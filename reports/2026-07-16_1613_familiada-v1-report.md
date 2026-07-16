# Familiada V1 — Build & Test Report

**Date:** 2026-07-16
**Scope:** Full V1 implementation per `PROJECT_SPEC.md`, built by the `developer` agent and independently verified by the `tester` agent against a real MariaDB + PHP HTTP server (not mocks).

---

## 1. Starting point

Before this session, the repository contained only documentation and scaffolding:

- `PROJECT_SPEC.md`, `docs/*.md`, `DEVELOPER_ONBOARDING.md` — fully consolidated, internally consistent spec (finale deferred to V2, lifecycle/restart rules, auth, and seed sounds finalized in the prior commit).
- `db/schema.sql` — real schema, matching the spec's data model.
- `src/lib/`, `src/game/`, `public/admin/`, `public/api/`, `public/board/` — placeholder `README.md` files only, no code. Some placeholder text was already stale (e.g. referencing a "pass turn" action the spec had explicitly removed).

**Nothing was implemented.** This session took it from spec-only to a working, tested V1.

---

## 2. What was built

Following the build order in `DEVELOPER_ONBOARDING.md` §7 (Grand Finale, item 9, intentionally excluded — deferred to V2 per spec §4.5):

### Foundation (`src/lib/`)
- `config.php` / `db.php` — PDO connection, `ATTR_EMULATE_PREPARES=false`.
- `response.php` — JSON response helpers with error masking (`PDOException`/unexpected errors → generic 500; our own `RuntimeException` validation messages → 400, never leaking raw SQL).
- `auth.php` — session-based auth, `password_verify` against `auth_password_hash`, server-enforced `session_lifetime`.
- `validate.php` — input validation helpers.

### Game engine (`src/game/`)
- **`GameRules.php`** — pure, DB-free logic: round multipliers (classic 1/1/1/2/3, free-rounds always ×1), strike/steal transitions, team-select lock threshold, classic-300 and free-rounds winner determination. Covered by `tests/GameRulesTest.php` (39 assertions, all passing).
- **`GameActions.php`** — DB-backed orchestrator: reveal, strike, set_team, finish_round, end_game (new — see §3), and the full lifecycle (startGame, setLive/demoteLiveAndPromote, restartGame) as one-transaction operations per spec §6.1.
- **`GameContent.php`** — library + game-editor CRUD. Enforces "frozen once started" at the API/DB layer (not just UI) — a `saveGame` call on a non-`draft` game is rejected server-side.
- **`SoundLibrary.php`** — per-pack/per-cue upload/list/delete, default-sound fallback.

### API (`public/api/`)
`state.php` (public GET poll target + server `now`), `action.php` (authenticated POST: `reveal`/`strike`/`finish_round`/`set_team`/`set_live`/`restart_game`/`end_game` — deliberately **no** `pass_turn`), `auth.php`, `games.php`, `library.php`, `sounds.php`.

### Views
- **Plansza** (`public/board/`) — LED-matrix board aesthetic, polling, reveal/strike animations, collapsed steal glyph.
- **Logowanie** (`public/admin/login.php`) — all 5 spec visual states (default/focused/submitting/error/session-expired), never reveals "closeness" of a wrong password.
- **Prezenter** (`public/admin/index.php`) — dense cockpit grid, manual sound override buttons, "Zakończ grę (wolne rundy)" early-end button for free-rounds mode.
- **Administrator** (`public/admin/administrator.php`) — games list, game editor (up to 8 answers/question, finale toggle rendered disabled "Wkrótce (V2)"), Dźwięki tab with real upload/play/delete.
- Shared design tokens (`assets/css/tokens.css`) — OKLCH palette, fonts, `prefers-reduced-motion` gating, `:focus-visible` rings, color+shape+label team markers (not color-only, for color-blind accessibility).

---

## 3. Bugs found by testing, and fixes

The tester ran **three full passes** against a live MariaDB + HTTP server, each time exercising real requests and inspecting real DB rows — not just reading code.

### Pass 1 — initial build
**119/120 checks passed.** Full round-flow (reveal/strike/steal success & fail/board-cleared win), classic-300 (exact and mid-round-crossing endings), free-rounds mode, lifecycle (single-live demotion, resume, restart-from-start), auth, Grand Finale lockout, and accessibility basics all verified correct on the first pass.

**Bug found — sounds completely broken in a real deployment (spec §8).**
Docroot is `public/` (per spec §9), but sound files lived at the project-root `assets/sounds/`, outside the web root → every cue 404'd. Compounding bug: URLs were emitted without a leading slash, so even fixing the folder location wouldn't have resolved paths correctly from `/board/` or `/admin/`.

**Also flagged (not bugs, design gaps):**
- `GameRules::freeRoundsWinner()` existed but was never called (dead code) — free-rounds games had no auto-finish/winner-reveal.
- No API action existed for a host to end a free-rounds game early, despite spec §4.4 allowing it ("or the host ends the game").

### Fix round 1
- Moved sound storage to `public/assets/sounds/` (git history preserved via `git mv`), added `sounds_url_base` config, rewrote `SoundLibrary.php` to always emit absolute URLs, updated schema seed paths and stale doc references.
- Wired `freeRoundsWinner()` into `GameActions`: last-set completion now auto-transitions to `finished` with a computed `winner` field (mode-gated, classic vs free-rounds). Added a new `end_game` action, legal only for free-rounds mode from `phase=round`, with a host-facing confirm-gated button in Prezenter. Extended unit tests to 39 assertions.

### Pass 2 — re-verification of fix round 1
**Sound fix: fully verified** — all 6 cues 200 via `curl`, upload/list/delete round-trip confirmed on disk and over HTTP, fallback works.
**Free-rounds auto-finish: verified** — clear-leader and tie cases both correct; `end_game` mode/phase gating correct.
**No regressions** in previously-verified behavior (22/22 spot-checks).

**New bug found — `end_game` discarded the in-flight round's pot.** Repro: mid-round, a team reveals a 5-point answer (not yet finished/banked); calling `end_game` ended the game but the 5 points never reached that team's score, and no `round_results` row was written — the points simply vanished, and the reported winner was wrong as a result.

### Fix round 2
`endGameByHost()` now reuses the same `bankPointsAndCloseRound()` helper that `finishRound()`'s board-cleared-win path already used, banking any non-zero `round_pot` before transitioning to `finished`. Added a new DB-backed integration test, `tests/EndGameByHostTest.php`, covering the exact bug scenario.

### Pass 3 — re-verification of fix round 2
**Confirmed fixed.** 21/21 new checks: the original repro now correctly banks the pot and reports the right winner; the zero-pot edge case (nothing revealed this round) ends cleanly with no phantom `round_results` row; both test suites pass (`GameRulesTest.php` 39/39, `EndGameByHostTest.php` pass, confirmed to be a real integration test, not a stub); the shared last-set auto-finish path is unaffected.

---

## 4. Final state

| Area | Status |
|---|---|
| DB schema (§6) | ✅ Matches spec |
| Auth (§7) | ✅ Verified: route guards, session persistence, generic error messages, `board/` public |
| Round flow / strike / steal (§4.2–§4.3) | ✅ Verified via live HTTP + DB |
| Classic 300 scoring (§4.4) | ✅ Verified exact-300 and mid-round-crossing endings |
| Free rounds scoring + auto-finish (§4.4) | ✅ Fixed and verified (was broken/missing at first pass) |
| `end_game` early-end action | ✅ Fixed and verified (pot-banking bug found and fixed) |
| Lifecycle: single-live, hold/resume, restart (§6.1) | ✅ Verified, including "frozen once started" enforced server-side |
| Sounds (§8) | ✅ Fixed and verified (was completely non-functional at first pass) |
| Grand Finale (§4.5) | ✅ Confirmed off everywhere — toggle disabled, `grand_finale` forced 0, `finale` phase unreachable |
| Accessibility floor | ✅ Spot-checked: color+shape+label team markers, reduced-motion CSS, keyboard focus styles |

---

## 5. Open items (not blocking, not yet actioned)

1. **Architect decision needed:** spec §5.4 describes the game editor authoring questions/answers directly on a game, while §6 describes games "freezing a chosen library set" on start. The developer reconciled this pragmatically (editor writes directly to `game_*` tables, mutable only while `draft`; added an optional `import_library_set` convenience) and flagged it in `GameContent.php`'s docblock rather than guessing further. Testing found no bug from this choice — "frozen once started" is correctly enforced server-side — but `PROJECT_SPEC.md` should be updated to confirm or correct the intended relationship between the library and game editor.
2. **Minor hardening, not yet done:**
   - No client-side file-size cap on sound uploads.
   - No `.htaccess` hardening beyond the existing outside-webroot layout.
3. No formal code review or commit has been made yet — all work above is uncommitted in the working tree.

---

## 6. How to run locally

```bash
mysql -u root -p familiada < db/schema.sql
cp config.example.php config.php   # fill DB creds + auth_password_hash + sounds_path/sounds_url_base
php -S localhost:8000 -t public
```
Open `http://localhost:8000/board/` (Plansza, public) and `http://localhost:8000/admin/` (Prezenter/Administrator, behind login).

Run tests:
```bash
php tests/GameRulesTest.php        # 39/39 pure-logic assertions
php tests/EndGameByHostTest.php    # DB-backed integration test (skips gracefully if no DB reachable)
```

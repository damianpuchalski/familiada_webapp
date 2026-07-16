# Developer Onboarding — Familiada

Everything you need to start building. Read [`PROJECT_SPEC.md`](PROJECT_SPEC.md) first — it is the single source of truth. This file tells you how to get running and what order to build in.

---

## 1. What you're building (30-second version)

A PHP + MySQL web game of **Familiada** (Polish Family Feud) for **cPanel shared hosting**. One host runs a live game from a cockpit; a room watches a big-screen board. Sync is **~1 s AJAX polling** — no framework, no build step, no WebSocket server. **V1 = four screens** (Polish UI): **Plansza** (board), **Prezenter** (cockpit), **Logowanie** (login), **Administrator** (games + sounds). The **Grand Finale is deferred to V2** — disabled everywhere in V1, schema/rules kept (see `PROJECT_SPEC.md` §4.5).

## 2. Ground rules (read before writing code)

1. **`PROJECT_SPEC.md` is the contract.** If code and it disagree, it wins. If two specs conflict or a decision is missing, **stop and ask the architect** — don't invent one. Record the resolution back into the spec.
2. **Never trust the client.** Every reveal / strike / turn / score / set-live change goes through a validated server action in `action.php` → `src/game/`. **Recompute scores server-side.** The board and cockpit only display and request.
3. **All live state lives in the DB** (`game_state` + content/history tables), never only in a browser. This is what makes *resume* work.
4. **Keep game logic out of the views.** The strike/steal state machine and scoring go in `src/game/` so the tester can unit-test them without a browser.
5. **Server clock is truth**, especially for the finale timer. `state.php` returns server `now`; clients compute the countdown from anchors (never decrement a server counter).
6. **Accessibility floor is non-negotiable:** blue/red distinguishable for color-blind viewers (color + shape/label/position), `prefers-reduced-motion` respected, visible keyboard focus in the cockpit.

## 3. Local setup

You need PHP 8.1+ and MySQL/MariaDB.

```bash
# 1. Create a database, then load the schema
mysql -u root -p familiada < db/schema.sql

# 2. Configure
cp config.example.php config.php
#   edit config.php: DB creds, poll_interval_ms (default 1000),
#   and the hashed host password for the login gate. Generate the hash with:
php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
#   paste the result into 'auth_password_hash' in config.php. See §6.

# 3. Serve the web root (public/ is the docroot)
php -S localhost:8000 -t public
```

Then open `http://localhost:8000/` (Plansza, the board root) and `http://localhost:8000/admin/` (Prezenter/Administrator, behind the login gate).

> On cPanel, the docroot points at `public/`; keep `src/`, `db/`, `config.php` **outside** the web root (or deny via `.htaccess`). See `PROJECT_SPEC.md` §9.

## 4. Repo map

```
familiada/
├─ PROJECT_SPEC.md          ← source of truth (read first)
├─ DEVELOPER_ONBOARDING.md  ← you are here
├─ docs/                    ← detailed appendices (architecture, rules, data model…)
├─ design/
│  └─ design_handoff_familiada/
│     ├─ Familiada.dc.html  ← design REFERENCE (recreate, don't lift verbatim)
│     └─ README.md          ← the design handoff + design tokens
├─ db/schema.sql            ← MySQL schema (InnoDB, utf8mb4)
├─ config.example.php       ← copy to config.php
├─ src/
│  ├─ lib/                  ← db connection, helpers, session/auth guard
│  └─ game/                 ← state machine + scoring (unit-testable)
├─ public/                  ← web root
│  ├─ index.php             ← Plansza board at the root (Grand Finale board = V2)
│  ├─ admin/                ← Prezenter, Administrator, editor, login (finale admin = V2)
│  ├─ api/                  ← state.php, action.php, auth, CRUD, sound upload
│  └─ assets/sounds/        ← default/ + klasyczny|retro|filmowy packs (starter WAVs shipped).
│                              MUST live under public/ (the web root) — anything outside it
│                              404s once the docroot points at public/ (Spec §9).
```

## 5. Using the design reference

`design/design_handoff_familiada/Familiada.dc.html` is a **single-file prototype** with mock data and no real backend. **Recreate its design and behavior in the real PHP stack — do not embed or transpile it.** The handoff README is high-fidelity: colors, typography, spacing, Polish copy, and interaction states are final-intent. Use its **Design Tokens** section verbatim.

Quick token reference (full list in the handoff README):

- **Fonts:** `Orbitron` (display/LED-adjacent numerics), `Space Grotesk` (body/UI), `Share Tech Mono` (LED readouts). Google Fonts.
- **Amber accent / LED glow:** `oklch(0.78 0.17 85)`. **Blue team:** `oklch(0.62 0.19 250)`. **Red team:** `oklch(0.60 0.22 18)`. **Success/green:** `oklch(0.68 0.17 145)`.
- **Cockpit backgrounds:** `oklch(0.13 0.02 260)` page / `0.17` header / `0.15–0.16` panels. **Board:** radial red/blue blend, dark edges.
- **Motion:** `ledIgnite` (reveal), `ledIgniteRed` (strike), `glowPulse` (total update), `stealBlink`, `cueFlash` — all gated behind `prefers-reduced-motion`.

Extract shared tokens (color/type/spacing) into **one stylesheet** and build both visual worlds from it.

## 6. First tasks (auth + scaffolding — mostly done)

Setup scaffolding is already in place; wire it up:

1. **Auth:** `config.example.php` now has `auth_password_hash` + `session_lifetime`. Build the PHP-session **verify/logout endpoints** and **route guards** that read them; replace the design's client-side password mock. Guard all `admin/` routes; leave the board (public root `index.php`) public. (Spec §7)
2. **Sound packs:** the three packs (Klasyczny / Retro / Filmowy) and the Klasyczny cue rows are **already seeded** in `db/schema.sql`, and starter WAVs ship under `public/assets/sounds/`. Build the upload/list/delete endpoints and the default-fallback player on top. (Spec §6, §8)
3. **Finale off:** ensure the editor's finale toggle is rendered **disabled** ("Wkrótce (V2)") and `games.grand_finale` stays `0`; never enter the `finale` phase. (Spec §4.5)

## 7. Build order

Follow this sequence; each step is a natural handoff to the tester.

1. **DB + content CRUD** — question sets / questions / answers (the library). Confirm `db/schema.sql` matches `PROJECT_SPEC.md` §6 first.
2. **Auth** — login gate, PHP session, route guards (see §6 above).
3. **Static board + cockpit** — Plansza and Prezenter from the design, with mock state (no real-time yet). Extract shared style tokens.
4. **Polling sync** — `state.php` (poll target, returns server `now`) + `action.php` (validated actions).
5. **State machine + scoring** — `src/game/`: round flow, strike, the 3-strike steal, round pot, finish round. Enforce every clause of `PROJECT_SPEC.md` §4.
6. **Game modes** — Classic (300, ×1/×1/×1/×2/×3, end at ≥300) and Free rounds (all ×1, highest wins).
7. **Sounds** — real per-pack/per-cue upload + storage; automatic transition-triggered cues + manual override grid; default-sound fallback (packs + starter files already scaffolded).
8. **Lifecycle & history** — statuses, the single-live-game pointer + **transactional demotion to `in_progress`** (hold), resume, **restart-from-start** (in-progress + finished), history/round results. Follow the state model in Spec §6.1 exactly.
9. **~~Grand Finale~~** — **V2, not in V1.** Keep the toggle disabled and the `finale` phase unused (Spec §4.5).

## 8. Watch-outs (the subtle rules that cause bugs)

- **No "pass turn" button.** Steal triggers **only** on the 3rd strike. (Spec §4.3)
- **Team selector locks at ≥3 revealed answers** in the current question. (Spec §4.3)
- **Strikes always count against `starting_team`**, even after control visually shifts. During a steal, one strike ends the round for `starting_team` — it does **not** start another steal.
- **Round pot is banked only on "ZAKOŃCZ RUNDĘ."** Revealing/stealing never auto-advances the score.
- **A cleared board is a normal win** (finish round with pot to the current owner), not only strikes.
- **Frozen content copy:** when a game *starts*, copy the chosen library sets into `game_*` tables; the game then reads only its frozen copy (read-only once `in_progress`/`finished`). Editing the library must never rewrite past games.
- **Hold ≠ draft.** Setting another game live demotes the previously-live game to **`in_progress`** (held, resumable), never `draft`. `draft` = creation-in-progress only. (Spec §6.1)
- **Restart keeps questions, wipes play state** — reset scores/reveals/round_results/game_state, keep the frozen `game_*` content; status → `in_progress`. Confirm-gate it. (Spec §6.1)
- **Exactly one game is live** at a time (`active_game` pointer + transactional demotion — do the pointer flip and the demotion in one transaction).
- **Sound-set is chosen in the editor**, displayed read-only in Prezenter. Missing pack file → fall back to `public/assets/sounds/default/<cue>.wav`. Cue URLs are always emitted as absolute (`/assets/sounds/...`) — a relative path breaks depending on whether the page is the board root (`/`) or `/admin/`.
- **Finale is V2/off:** don't build finale screens; keep `grand_finale = 0` and never enter the `finale` phase. (Timer design is recorded in Spec §4.5 for later.)

## 9. Working with the agents

Three Claude Code sub-agents are defined in `.claude/agents/`:

- **architect** (Opus) — owns `PROJECT_SPEC.md`, `docs/`, `db/schema.sql`. Makes/records design decisions, resolves conflicts. The V1 open decisions are now settled in Spec §10 (finale → V2, demote-to-`in_progress`, restart, auth, sounds); ask them if a *new* gap appears.
- **developer** (Sonnet) — you. Build from the spec; ask the architect rather than inventing.
- **tester** (Sonnet) — turns each clause of §4 (game rules) and §4.6 (invariants) into tests. Report failures by rule reference.

Typical loop: architect approves an approach → developer builds → tester verifies against the rules → back to architect if something doesn't fit. Keep the spec current — it's what makes the agents interchangeable across sessions.

## 10. Definition of done (per feature)

- Behavior matches `PROJECT_SPEC.md` (cite the section you implemented).
- Server-side validation for every state change; no trust in client input.
- Game logic lives in `src/game/` and has tests for the relevant §4.6 invariants.
- Accessibility floor met (color-blind-safe teams, reduced-motion, keyboard focus).
- Runs on plain PHP 8.1 with no build step; nothing secret committed (`config.php` is gitignored).

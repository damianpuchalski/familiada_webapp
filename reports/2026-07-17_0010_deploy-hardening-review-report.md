# Familiada — Deployment, Hardening & Code-Review Report

**Date:** 2026-07-17
**Scope:** Take the tested V1 (see `2026-07-16_1613_familiada-v1-report.md`) from committed-but-undeployed to **live in production**, apply the public/private security restructure, close the V1 open items, and run a formal pre-production code review. Also covers a related read-only audit of the neighbouring `owl-it` sites.

---

## 1. Starting point

V1 was built, tested (3 passes vs a live DB), and committed (`da72993`) but **not deployed**, and the working tree held an uncommitted restructure (board moved to docroot root, public/private split, deploy env). Nothing was live on the server.

---

## 2. Directory restructure — public/private split (committed `e48f3ea`, `dc77d7a`, `5c52650`)

The goal: private code (`config.php`, `src/`, `db/`) must never be fetchable by URL ("cannot be stolen").

- **Board moved** from `public/board/index.php` to `public/index.php` (docroot serves the board directly); asset/API paths de-relativized. Stale `/board/` references fixed across the docs.
- **Public/private deployment model** documented in `docs/DEPLOYMENT.md`: `public/` contents → site docroot; `config.php`+`src/`+`db/` → a sibling folder **outside every docroot**.
- **Path anchor (`public/paths.php`)** added to solve cross-subtree includes: it resolves the private dir into `FAMILIADA_PRIVATE_DIR` via (1) env var, (2) a gitignored `private_path.local.php` pointer, (3) parent-of-`public/` fallback for local dev. Fails with a clear HTTP 500 (not an opaque fatal) if the private dir isn't found. All six public entry points repointed at it.
- **Latent bug found & fixed:** the bare `config.php` gitignore pattern also ignored `src/lib/config.php` (the config *loader*, source not secret), so it was never committed/deployed and the app 500'd on the missing loader. Anchored the pattern to `/config.php`.
- **`deploy/sync.py`** added: a stdlib-only (ftplib) sync that uploads only git-tracked files to both FTP targets and imports the schema over SSH. Later hardened to **never sync `config.php`** (environment-specific; auto-push would clobber prod config).

---

## 3. Deployment to production (owl-it.pl/familiada)

Deployed to the live cPanel account (atthost24). Server layout confirmed via a throwaway PHP probe (deleted after): account home `/home/biurovictoria`, docroot `websites/owl-it/`, sub-sites nested beneath it.

- **Files:** 51 public files → `websites/owl-it/familiada/`; `src/`+`db/` → `familiada_private/`; auto-generated path anchor written to the public target.
- **Private target `familiada_private/`** created as a sibling of `websites/` — **verified NOT web-reachable** (all `owl-it.pl/familiada_private/*` return 404).
- **DB schema** imported into `13638_familiada` (0 → 14 tables) via a one-shot, token-guarded, immediately-deleted PHP importer (no local mysql client; interactive SSH unavailable from the tooling).
- **Server `config.php`** built by hand on the private target: live DB creds, hashed cockpit password, `debug=false`, absolute `sounds_path`, `sounds_url_base=/familiada/assets/sounds`. **Not** the local dev config (which points at 127.0.0.1/root).

**Verified live:** board 200; `api/state.php` DB-backed 200; login (correct pw) authenticates, wrong pw → generic 401; sound file served at the subfolder URL; private code 404.

---

## 4. robots.txt hardening

Added `Disallow: /familiada/` to every user-agent group in `owl-it.pl/robots.txt` (10 groups). This plus the unlinked URL and the login gate is the full "hidden behind an existing site" posture. (Crawler hint only — real protection remains auth + the private split.)

---

## 5. V1 open items closed (committed `04d0cf5`)

1. **Spec §5.4/§6 reconciliation** (via architect agent): `PROJECT_SPEC.md` §5.4/§6/§6.1/§10 now consistently describe **freeze-by-status** (editor authors `game_*` directly, mutable while `draft`, read-only once started; `importLibrarySet` is the optional library bridge) — matching the shipped code. Stale `schema.sql` comment fixed.
2. **Sound-upload size cap (8 MB):** `SoundLibrary::MAX_UPLOAD_BYTES` enforced server-side (source of truth), a distinct message for PHP-level oversize rejects, and a mirrored client-side pre-check in `admin.js`. Verified live: 9 MB → 400 "File too large (max 8 MB)"; 41 KB → 200.
3. **`public/.htaccess` hardening:** forces HTTPS (self-contained), disables directory listings, sets baseline security headers, denies direct HTTP access to includes / the private-path pointer / `.md`/`.sql`/`.example.php`. Verified live: site healthy; `_bootstrap.php`, `README.md`, `private_path.local.php`, and `assets/` listing all 403.

---

## 6. Related audit — owl-it sites (read-only, no changes)

While confirming the deploy target, audited the neighbouring owl-it mini-sites:
- **`config.php` credentials are NOT leaking** — PHP executes them (0-byte HTTP response).
- **Real leak found:** `owl-rental/schema.sql` is fetchable as plaintext (200) — DB structure disclosure (no creds). Flagged for the owl-it repo.
- **owl-rental SQLi audit:** clean — every query uses PDO prepared statements with bound params (`EMULATE_PREPARES=false`); the dynamic `ORDER BY`/`IN(...)`/`WHERE` spots are all whitelist- or placeholder-based. No injection vector found.
- A separate fix-it prompt was prepared for the owl-it repo (move `schema.sql`/config/includes out of docroot; note the reused password `!4Lw700921` across FTP/SSH/DB/SMTP).

---

## 7. Code review of live V1 (item 4) — 6 findings

Reviewed the state machine, scoring, auth, all API endpoints, content CRUD, and client JS. **Not yet fixed** — handed off to a dedicated chat.

| # | Sev | File | Issue |
|---|-----|------|-------|
| 1 | 🔴 **Critical** | `public/api/state.php` / `GameActions::getBoardState` | Public unauthenticated poll returns `text`+`points` for **unrevealed** answers → anyone can read hidden answers mid-game. Fix: redact hidden answers when caller not authenticated. |
| 2 | 🟠 | `src/game/GameActions.php` `reveal()` | Revealing ≥3 answers before choosing a starting team locks the selector and dead-ends the round (finish/end throw "no starting team"); only restart recovers. |
| 3 | 🟠 | `src/lib/auth.php` `auth_start_session()` | Session cookie set without `Secure` flag. |
| 4 | 🟠 | `src/lib/auth.php` `auth_attempt()` | No rate limit / lockout on cockpit login (unlimited password guesses). |
| 5 | 🟡 | `src/game/GameActions.php` `advanceAfterRoundEnd()` | classic_300 that exhausts rounds without hitting 300 reports "REMIS" even when scores differ (should be highest-score-wins). |
| 6 | 🟡 | `src/game/GameContent.php` `saveGame`/`importLibrarySet` | 8-answer cap keyed on array index, not inserted count. |

Findings 1–3 confirmed against code/live endpoint; 4–6 plausible. Review ran at medium effort (no second-pass verification).

---

## 8. Open items / next steps

1. **Fix the 6 code-review findings** (a dedicated fix-it prompt exists, ordered #1 critical → #6). #1 is game-breaking and should be fixed before any real game is played.
2. **owl-it repo:** remove the `schema.sql` leak, apply the private/public split, rotate the reused password.
3. **V2 — Grand Finale** (2 screens + wiring the `finale` phase the backend already supports; blocked on new design work).

---

## 9. Deployment method reference (for future sessions)

- Credentials/paths live in `deploy.local.env` (gitignored): FTP (`biurovictoria@biurovictoria.atthost24.pl:21`), SSH (port 6022), DB (`13638_familiada`), and `FAMILIADA_ADMIN_PASS` (live cockpit password).
- Public target FTP `websites/owl-it/familiada/`; private target FTP `familiada_private/` (abs `/home/biurovictoria/familiada_private`).
- **Deploy changed files by uploading them directly via curl FTP** (public → public target, `src/` → `familiada_private/src/...`). **Never** upload the local `config.php` — the server has its own production config. `deploy/sync.py` excludes `config.php` by design.
- The live cockpit password is hashed into the server `config.php`; the plaintext is in `deploy.local.env`. The reused `!4Lw700921` (FTP/SSH/DB/SMTP) is a rotation candidate.
- Verify live over HTTPS with curl after each change; run `php tests/GameRulesTest.php` after logic changes.

---

## 10. Commits this session (pushed to origin/master)

- `e48f3ea` refactor(deploy): move board to docroot root, split public/private, add sync script
- `dc77d7a` feat(deploy): resolve private-code path across subtrees via path anchor
- `5c52650` fix(deploy): track src/lib/config.php (anchor gitignore to root only)
- `04d0cf5` feat(v1): sound-upload size cap, public .htaccess hardening, ratify content model

The 6 code-review fixes are **not** in these commits — they are pending in the dedicated fix-it chat.

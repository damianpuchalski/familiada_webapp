# API endpoints (built)

- `state.php` — GET, the poll target. Returns the current board state as JSON for the
  live game (via `active_game`, or `?game_id=` explicitly), including the **server
  timestamp**. Public — no auth (Plansza is unauthenticated).
- `action.php` — POST, authenticated. Validated game actions: `reveal`, `strike`,
  `finish_round`, `set_team`, `set_live`, `restart_game`. **There is no `pass_turn`
  action** — steal is triggered only by the 3rd strike (Spec §4.3, §10 change 1).
  Every action is validated against the state machine in `src/game/GameActions.php`;
  scores are always recomputed server-side, never trusted from the client.
- `auth.php` — POST `{action:'login'|'logout'}` / GET `?action=check` — session gate (Spec §7).
- `games.php` — authenticated CRUD for the game editor + Gry list (create/edit/list/get/delete
  a draft, plus `import_library_set`). Lifecycle transitions (`set_live`, `restart_game`)
  live in `action.php`, not here.
- `library.php` — authenticated CRUD for the reusable content library
  (`lib_question_sets`/`lib_questions`/`lib_answers`).
- `sounds.php` — authenticated per-pack/per-cue sound file list/upload/delete
  (Administrator › Dźwięki). Uploaded files are served as static files under
  `/assets/sounds/`, which stays public so playback works without a session.

See `PROJECT_SPEC.md` §3.2, §4, §6, §7 and `docs/ARCHITECTURE.md` / `docs/GAME_RULES.md`.

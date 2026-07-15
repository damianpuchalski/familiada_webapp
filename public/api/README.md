# API endpoints (to build)

- `state.php` — GET, the poll target. Returns current board state as JSON for the live game (via `active_game`), including the **server timestamp** for the finale clock. Fast and small.
- `action.php` — POST, receives admin actions (reveal, strike, pass turn, finish round, set team, timer start/pause/resume/reset, set-live, etc.). Validates against the state machine in `src/game/` and updates `game_state`. Never trusts client-supplied scores.

See docs/ARCHITECTURE.md and docs/GAME_RULES.md.

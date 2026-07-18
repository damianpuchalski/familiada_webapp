# Architecture

This is the technical source of truth for the Familiada web game. If code and this document disagree, this document wins until it is deliberately updated (by the architect agent).

## Goal

A browser-based Familiada (Family Feud) game where one **host/admin** runs a live game and a room of contestants watches a shared **big-screen board**. Two teams (blue / red) compete. The host drives everything; the board reacts.

## Tech stack & why

- **PHP 8.1+ / MySQL / HTML / CSS / vanilla JS.**
- **Target host: standard cPanel shared hosting.** This is the constraint that shapes the real-time approach.
- No framework, no build step required for the back end — keep it deployable by copying files. (A light JS bundler is optional for the front end but not required.)

The cPanel offering can also deploy Django / Node / Rails apps, which would unlock a persistent WebSocket server. **We deliberately do not use that.** For a single-board game, it is unnecessary complexity. See "Real-time" below.

## Real-time sync (the one architectural decision that matters)

The requirement: when the admin clicks something, the contestant board updates "automatically."

**Chosen approach: AJAX polling.** The contestant board polls a small endpoint (`/api/state.php?game_id=X`) roughly every **500ms–1000ms** and re-renders from the returned JSON. The admin panel writes state on each action; the board picks it up on the next poll.

Why polling and not the alternatives:

- **Polling** — works on *any* cPanel host, trivial, bulletproof. For one board + one admin the traffic is negligible. The ~1s delay is hidden behind reveal/strike animations (the animation *is* the payoff). **This is the choice.**
- **Server-Sent Events (SSE)** — cleaner one-way push, but can collide with PHP `max_execution_time` on shared hosting. Acceptable future upgrade, not required.
- **WebSockets** — true bidirectional, but needs a long-running process the shared host may not allow. Overkill here. Only revisit if the game grows to many simultaneous independent boards.

**Consequence:** all live state lives in the database (in `game_state`), never only in a browser. This is what makes "resume an unfinished game" almost free — see Lifecycle.

## Components

```
┌─────────────────┐        writes        ┌──────────────┐
│  Admin Cockpit  │ ───────────────────► │              │
│  (browser)      │   POST /api/action   │   MySQL      │
└─────────────────┘                      │  game_state  │
                                         │   + content  │
┌─────────────────┐   GET /api/state     │   + history  │
│ Contestant Board│ ◄─────────────────── │              │
│  (browser, TV)  │   poll ~1s           └──────────────┘
└─────────────────┘
```

- **Admin Cockpit** (`public/admin/`) — dense control panel. Sees the full answer list with points, reveals answers, drives strikes/steal, selects team, finishes rounds, controls the finale timer and sounds.
- **Contestant Board** (`public/`, root `index.php`) — theatrical big screen. Shows answer slots (hidden → revealed), round total, strikes, current team, finale timer. Read-only, polls state.
- **API** (`public/api/`) — thin endpoints:
  - `state.php` — returns current board state as JSON (the poll target). Also returns the **server timestamp** (needed for the finale clock).
  - `action.php` — receives admin actions (reveal answer, strike, pass turn, finish round, set team, timer control, set-live, etc.), validates them against the state machine, updates `game_state`.
- **Content/admin CRUD** — game design, question sets, questions, answers, history/lifecycle.
- **Game logic** (`src/game/`) — the strike/steal state machine and scoring, kept out of the view layer so it can be unit-tested.

## State machine (round flow)

Lives in `game_state` and is enforced server-side in `src/game/`. The admin can only trigger legal transitions; buttons that don't apply are disabled/ignored. Full rules in [`GAME_RULES.md`](GAME_RULES.md).

Summary: a round belongs to a starting team. Wrong answers accumulate strikes. On the 3rd strike, control passes to the other team for a **single steal attempt**. If the steal answer is on the board → the stealing team takes the whole round pot; if not → the pot goes to the original team. The host confirms every transition; the machine just prevents illegal ones.

## Scoring

- **Round pot** = sum of revealed answers' points × round multiplier.
- On **finish round**, the pot is added to the owning team's total (`teams.score`), logged to `round_results`, and the game advances to the next set.
- **Classic (300) mode:** rounds 1–3 ×1, round 4 ×2, round 5 ×3; game ends when a team reaches **300**.
- **Free-rounds mode:** every round ×1, arbitrary number of rounds; highest total wins when sets run out.

## Grand finale

Optional phase for either mode. 5 questions asked to two contestants in turn.
- Contestant 1: **15s**. Contestant 2 (same questions): **20s**.
- Host types what the contestant said; the system shows the matching scored answer (host judges the match manually).
- Large countdown timer on the board.

**Timer is stored as anchors, not a decrementing counter** (avoids drift and works with polling):
`finale_timer_status` (idle/running/paused/expired), `finale_duration`, `finale_started_at`, `finale_elapsed_before`. Every client computes `remaining` from these + the server `now`. Host controls: **Start / Pause / Resume / Reset.** Browser animates smoothly via `requestAnimationFrame` but always snaps to the server-anchored truth on each poll. Use the **server clock** consistently (admin machine and TV machine may differ).

## Game lifecycle & the single "live" game

Each game has a `status`: `draft` / `live` / `in_progress` / `paused` / `finished` / `archived`.

- **Exactly one game is "live"** for the contestant board at a time. Enforced two ways: a single-row `active_game` pointer (the board polls whatever it points to) **and** a transaction that flips any previously-live game back to `in_progress` when a new one is set live.
- **Resume** works because all live state is in `game_state` — loading it re-renders the game exactly. No browser-only state.
- **History** = the games list filtered/annotated by status, with timestamps and final scores.

## Content integrity: library vs. frozen copy

**Decision:** questions/answers are authored in a reusable **library**, but when a game **starts**, it takes a **frozen copy** of the questions/answers it will use. Reasons:

- History (`round_results`) must match the questions actually asked; editing library content later must not rewrite the past.
- Once a game is `in_progress`/`finished`, its frozen content is **read-only**.

Cost: a copy step at game start. Worth it for trustworthy history. (See `DATA_MODEL.md` for how this is represented.)

## Sounds

HTML5 `<audio>` in the browser. A configurable **sound set** per game. Cues to support: correct answer (ding), wrong answer / strike (buzzer), round start, round end, game start, end-of-game. Triggered by *transitions* in the polled state (e.g. play buzzer once when strike count increases), not on every poll.

## Non-negotiable quality floor

- Board legible across a room (TV/projector target).
- Blue vs. red must be distinguishable for color-blind viewers (pair color with shape/label/position).
- Reduced-motion respected; keyboard focus visible in the cockpit.
- All state changes go through validated server actions — no trusting the client.

## Suggested build order

1. DB schema + content CRUD (question sets / questions / answers).
2. Static board + admin cockpit (no real-time).
3. Polling sync (`state.php` + `action.php`).
4. Strike/steal state machine + scoring.
5. Game modes (classic 300 / free rounds).
6. Grand finale module + pausable timer.
7. Sounds + polish.
8. Lifecycle: statuses, single-live pointer, resume, history.

# Data Model

Explains the tables and the reasoning. The actual DDL lives in [`../db/schema.sql`](../db/schema.sql) — keep the two in sync (architect owns changes).

## Overview

Three groups of tables:

1. **Content library** — reusable questions/answers the admin authors.
2. **Games + frozen content** — a game and the snapshot of content it actually uses.
3. **Live state + history** — the single live-state row per game, and per-round results.

## Content library (editable templates)

- **`lib_question_sets`** — a reusable set (one round's worth). `id`, `name`, `created_at`.
- **`lib_questions`** — `id`, `set_id`, `text`, `sort_order`.
- **`lib_answers`** — `id`, `question_id`, `text`, `points`, `sort_order`.

The library is freely editable at any time. Editing it does **not** affect games already started (see frozen copy below).

## Games + frozen content

- **`games`** — `id`, `name`, `mode` (`classic_300` | `free_rounds`), `grand_finale` (bool), `sound_set_id`, `status` (`draft`|`live`|`in_progress`|`paused`|`finished`|`archived`), `created_at`, `started_at`, `finished_at`.
- **`game_question_sets`** — frozen copy of the sets used by a game. `id`, `game_id`, `round_number`, `multiplier`, `name`.
- **`game_questions`** — `id`, `game_set_id`, `text`, `sort_order`.
- **`game_answers`** — `id`, `game_question_id`, `text`, `points`, `sort_order`, `revealed` (bool, set during play).

**Why frozen copies:** history must reflect the questions actually asked. Once a game starts, edits to the library must not rewrite its past. At game start, the chosen library sets are copied into `game_*` tables; from then on the game reads only its own frozen copy, and that copy becomes read-only once `in_progress`/`finished`.

## Teams

- **`teams`** — `id`, `game_id`, `color` (`blue`|`red`), `name` (optional), `score` (running total).

Two rows per game.

## Live state (the poll target)

- **`game_state`** — exactly one row per game. This is what the board polls and what makes resume work.
  - `game_id`
  - `phase` — `lobby` | `round` | `steal` | `round_end` | `finale` | `finished`
  - `current_game_set_id` — which round/set is live
  - `active_team` — which team currently owns answering (`blue`|`red`)
  - `starting_team` — the team that started this round (strikes are tracked for them)
  - `strikes` — 0–3
  - `steal_in_progress` — bool (the other team's one-shot attempt)
  - `round_pot` — running sum of revealed points (pre-multiplier or post, pick one and document; recommend store raw, apply multiplier on finish)
  - **Finale timer anchors:** `finale_timer_status` (`idle`|`running`|`paused`|`expired`), `finale_duration` (secs), `finale_started_at` (server ts, nullable), `finale_elapsed_before` (secs), `finale_player` (1|2), `finale_question_index`
  - `updated_at`

Revealed answers are tracked on `game_answers.revealed`, so the board can render the grid directly.

## Single "live" game

- **`active_game`** — single-row pointer table: `id` (always 1), `game_id` (nullable). The contestant board reads `active_game` to know which game to show. Setting a game live updates this pointer **and** flips any previously-live game to `in_progress`, in one transaction.

## History

- **`round_results`** — `id`, `game_id`, `game_set_id`, `round_number`, `team_color`, `points_awarded`, `multiplier_applied`, `created_at`. One row per completed round; drives history and final-score summaries.

## Finale records

- **`finale_answers`** — `id`, `game_id`, `finale_player` (1|2), `question_index`, `typed_answer`, `matched_answer_id` (nullable), `points_awarded`, `created_at`. Records what each contestant said and the host's manual match.

## Sounds

- **`sound_sets`** — `id`, `name`.
- **`sounds`** — `id`, `sound_set_id`, `cue` (`correct`|`strike`|`round_start`|`reveal`|`finale_timer`|`end_game`), `file_path`.

## Notes for implementers
- All timestamps use the **server** clock. `state.php` must return the server `now` so clients can compute the finale countdown consistently.
- Never trust client-supplied scores or reveal state; every change goes through validated actions in `src/game/`.
- Index `game_state.game_id`, `game_answers.game_question_id`, `round_results.game_id`.

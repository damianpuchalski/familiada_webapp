# Game Rules (exact)

These are the rules the code in `src/game/` must enforce. The admin can only trigger **legal** transitions; illegal actions are rejected/disabled. Written as precisely as possible so the tester agent can turn each clause into a test.

## Terms
- **Starting team** — the team that begins a round. Strikes count against them.
- **Round pot** — sum of the points of answers revealed so far, in this round.
- **Multiplier** — from the round (classic mode) or always 1 (free-rounds mode).
- **Steal** — the other team's single attempt after 3 strikes.

## Round flow (the state machine)

State fields: `phase`, `starting_team`, `active_team`, `strikes`, `steal_in_progress`, `round_pot`.

1. **Round start** (`phase=round`)
   - `starting_team` and `active_team` are set to the same team (host chooses which team owns the round via the team selector).
   - `strikes = 0`, `steal_in_progress = false`, `round_pot = 0`, all answers hidden.

2. **Reveal answer** (legal in `phase=round` or during `steal`)
   - Host clicks an unrevealed answer → it becomes `revealed`.
   - `round_pot += answer.points`.
   - Play `correct` sound.
   - Revealing does **not** by itself change turns.

3. **Strike** (legal in `phase=round`)
   - Host clicks Strike (the spoken answer isn't on the board).
   - `strikes += 1`. Play `strike` sound.
   - **strikes == 1:** nothing else happens.
   - **strikes == 2:** nothing mechanical; the other team may discuss (no state change beyond the counter).
   - **strikes == 3:** control passes → set `phase=steal`, `steal_in_progress=true`, `active_team = opposite(starting_team)`. Strikes stop accumulating.

4. **Steal attempt** (`phase=steal`, `steal_in_progress=true`)
   - The stealing team gives **exactly one** answer.
   - **Steal succeeds:** host reveals that answer (`round_pot += points`) → award the **entire round pot** to the **stealing team**. Go to `round_end`.
   - **Steal fails:** host clicks Strike → award the **entire round pot** to the **starting team**. Go to `round_end`.
   - Only one steal action is allowed; after it, no more reveals/strikes in this round.

5. **Round end** (`phase=round_end`)
   - Compute `awarded = round_pot * multiplier`.
   - Add `awarded` to the winning team's `teams.score`.
   - Write a `round_results` row (team, round_number, points_awarded, multiplier_applied).
   - Host clicks **Finish round / Next** → advance to the next set (`phase=round` with the next `current_game_set_id`), or to `finale`/`finished` per mode.

> Note: if all answers on a board are revealed before 3 strikes, the round can also end by host action ("finish round") with the pot going to the currently-owning team. Support this: the board being cleared is a normal win, not only strikes.

## Turn ownership subtlety
- Strikes are always tracked against `starting_team`, even after control visually shifts. Only the 3rd strike moves `active_team`. During a steal, a wrong answer (strike) does **not** increment toward another steal — it simply ends the round in favor of `starting_team`.

## Scoring modes

### Classic (300)
- Multipliers by round number: **1→×1, 2→×1, 3→×1, 4→×2, 5→×3.**
- After each round end, check both teams' totals. **If either team ≥ 300 → game ends** (`phase=finished`), that team wins.
- If a grand finale is enabled, it runs as its own phase (typically after the main rounds / when triggered by host).

### Free rounds
- Every round uses **×1**.
- Any number of rounds. When the sets run out (or host ends the game), the team with the **higher score** wins.

## Grand finale
- Enabled per game. Two contestants answer the **same 5 questions**.
- **Contestant 1: 15 seconds. Contestant 2: 20 seconds.**
- Host types each spoken answer (`finale_answers.typed_answer`) and manually matches it to a scored answer (`matched_answer_id`, `points_awarded`).
- **Timer:** stored as anchors (`finale_timer_status`, `finale_duration`, `finale_started_at`, `finale_elapsed_before`). Remaining time computed client-side:
  ```
  elapsed   = finale_elapsed_before + (status == running ? server_now - finale_started_at : 0)
  remaining = max(0, finale_duration - elapsed)
  ```
  - **Start:** status=running, started_at=now, elapsed_before=0.
  - **Pause:** status=paused, elapsed_before += (now - started_at), started_at=null.
  - **Resume:** status=running, started_at=now.
  - **Reset:** status=idle, elapsed_before=0, started_at=null, duration=(15 or 20).
  - **Expire:** when remaining hits 0, transition to `expired` once; play buzzer once (fire on transition, not per poll).

## Validation invariants (for the tester)
- `strikes` is always 0–3.
- `active_team` is always `blue` or `red`.
- A round awards its pot to **exactly one** team, exactly once.
- `round_pot` after finish equals the sum of that round's revealed answer points.
- `awarded` written to history == `round_pot * multiplier_applied`.
- Only **one** steal action per round; no reveals/strikes accepted after `round_end`.
- Exactly **one** game is `live` / pointed to by `active_game` at any time.
- Finale `remaining` never goes negative; pausing freezes it; resuming continues from the frozen value.

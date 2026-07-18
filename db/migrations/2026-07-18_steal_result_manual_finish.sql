-- Migration: stop auto-banking/auto-closing a round the instant a steal
-- resolves. A resolved steal (success or failure) now just records the
-- outcome in game_state.steal_result and waits — the presenter must click
-- "ZAKOŃCZ RUNDĘ" to actually bank the pot and enter phase=round_end (which
-- is what plays the round_end cue). This also fixes a missing strike sound:
-- a failed steal previously jumped straight to round_end with no audible
-- signal that the steal failed; the client now detects the 'failed'
-- transition and plays the strike cue.
--
-- Safe to re-run only if you first drop the column; there's no idempotent
-- ADD COLUMN in plain MySQL/MariaDB DDL.

ALTER TABLE game_state
  ADD COLUMN steal_result ENUM('none','success','failed') NOT NULL DEFAULT 'none' AFTER steal_in_progress;

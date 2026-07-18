-- Migration: server-authoritative sound cues.
--
-- Adds game_state.cue + game_state.cue_seq. Every audible action (begin_game,
-- reveal, strike, finish_round, advance_round, end_game, and the presenter's
-- manual cue grid) stamps the cue name into `cue` and bumps `cue_seq` by one,
-- atomically inside the action's own transaction. Both the public board and the
-- presenter poll state.php and play a cue exactly when they see cue_seq advance
-- past the last one they played — replacing the old client-side snapshot diffing
-- (sound.js's detectAndPlay) and syncing every viewer (incl. manual cues) to
-- within one poll. Clients treat a non-increase (game restart / game switch) as
-- a resync, not a replay, so cue_seq never needs to be reset.
--
-- Run this against an already-deployed DB (schema.sql's CREATE TABLE only
-- applies to a fresh install). No idempotent ADD COLUMN in plain MySQL/MariaDB
-- DDL — don't re-run twice against the same DB.

ALTER TABLE game_state
  ADD COLUMN cue     VARCHAR(32) NULL              AFTER round_pot,
  ADD COLUMN cue_seq INT UNSIGNED NOT NULL DEFAULT 0 AFTER cue;

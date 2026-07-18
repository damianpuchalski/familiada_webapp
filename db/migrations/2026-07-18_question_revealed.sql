-- Migration: gate question/answer visibility behind an explicit presenter reveal.
--
-- Adds game_state.question_revealed. While a round is active and this is 0, the
-- public board (state.php, unauthenticated) shows only the round number — the
-- question text and all answers are blanked in the API response — so a
-- contestant can't read the question before the presenter reads it aloud. The
-- authenticated cockpit always sees the real question text regardless, since
-- the presenter needs to read it before clicking reveal.
--
-- Default 1 so any game already live/mid-round keeps showing its question
-- uninterrupted after this deploys; only rows created afterwards (new games,
-- restarts, new rounds) start hidden at 0.
--
-- Run this against an already-deployed DB (schema.sql's CREATE TABLE only
-- applies to a fresh install). Safe to re-run (guarded by column-exists check
-- is not supported in plain MySQL DDL, so just don't re-run twice against the
-- same DB).

ALTER TABLE game_state
  ADD COLUMN question_revealed TINYINT(1) NOT NULL DEFAULT 1 AFTER current_game_set_id;

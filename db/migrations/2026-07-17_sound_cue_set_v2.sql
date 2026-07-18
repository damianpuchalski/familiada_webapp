-- Migration: restructure the sound cue set.
--
-- Drops 'reveal' and 'finale_timer' cues (no longer used — the finale timer
-- feature itself is unchanged, only its audio cue is removed) and adds
-- 'game_start' (fires when the game leaves the lobby) and 'round_end' (fires
-- when a round closes / points are banked). Final cue set:
-- correct, strike, round_start, round_end, game_start, end_game.
--
-- Run this against an already-deployed DB (schema.sql's INSERTs only apply to
-- a fresh install and won't touch existing rows). Safe to re-run.
--
-- Also requires: new on-disk files public/assets/sounds/{default,klasyczny,modern}/
-- game_start.wav and round_end.wav, and removal of reveal.wav / finale_timer.wav
-- from those same folders (already done in this repo; re-run deploy/sync.py
-- --files --apply on a server that still has the old files).

-- Step 1: widen the ENUM so both old and new members are valid while we migrate rows.
ALTER TABLE sounds MODIFY cue ENUM('correct','strike','round_start','reveal','finale_timer','round_end','game_start','end_game');

-- Step 2: drop rows for the retired cues.
DELETE FROM sounds WHERE cue IN ('reveal','finale_timer');

-- Step 3: shrink the ENUM to the final cue set.
ALTER TABLE sounds MODIFY cue ENUM('correct','strike','round_start','round_end','game_start','end_game');

-- Step 4: give the Klasyczny and Modern packs explicit files for the two new cues
-- (Retro has no files for any cue yet and keeps falling back to default/<cue>.wav).
INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'game_start', 'klasyczny/game_start.wav' FROM sound_sets WHERE name = 'Klasyczny'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'round_end', 'klasyczny/round_end.wav' FROM sound_sets WHERE name = 'Klasyczny'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'game_start', 'modern/game_start.wav' FROM sound_sets WHERE name = 'Modern'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'round_end', 'modern/round_end.wav' FROM sound_sets WHERE name = 'Modern'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

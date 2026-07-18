-- Migration: rename the "Filmowy" sound pack to "Modern" and give it the
-- default/ cue files as its own explicit files (previously it had none and
-- fell back to default/<cue>.wav for every cue).
--
-- Run this against an already-deployed DB (schema.sql's INSERTs only apply to
-- a fresh install and won't touch existing rows). Safe to re-run.
--
-- Also requires: the on-disk public/assets/sounds/filmowy/ folder renamed to
-- modern/ with the default/*.wav files copied in (already done in this repo;
-- if deploying to a server that still has the old folder, rename it by hand
-- or re-run deploy/sync.py --files --apply after this repo's rename).

UPDATE sound_sets SET name = 'Modern' WHERE name = 'Filmowy';

INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'correct', 'modern/correct.wav' FROM sound_sets WHERE name = 'Modern'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'strike', 'modern/strike.wav' FROM sound_sets WHERE name = 'Modern'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'round_start', 'modern/round_start.wav' FROM sound_sets WHERE name = 'Modern'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'reveal', 'modern/reveal.wav' FROM sound_sets WHERE name = 'Modern'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'finale_timer', 'modern/finale_timer.wav' FROM sound_sets WHERE name = 'Modern'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

INSERT INTO sounds (sound_set_id, cue, file_path)
SELECT id, 'end_game', 'modern/end_game.wav' FROM sound_sets WHERE name = 'Modern'
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

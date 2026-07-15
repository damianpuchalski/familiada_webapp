-- Familiada — MySQL schema
-- Matches docs/DATA_MODEL.md. Architect owns changes; keep the two in sync.
-- Engine InnoDB for FK + transactions (needed for the single-live-game transaction).

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Content library (reusable, editable templates)
-- ---------------------------------------------------------------------------
CREATE TABLE lib_question_sets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(255) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lib_questions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  set_id      INT UNSIGNED NOT NULL,
  text        VARCHAR(500) NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_lib_questions_set (set_id),
  CONSTRAINT fk_lib_questions_set FOREIGN KEY (set_id)
    REFERENCES lib_question_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lib_answers (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id INT UNSIGNED NOT NULL,
  text        VARCHAR(255) NOT NULL,
  points      INT NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_lib_answers_question (question_id),
  CONSTRAINT fk_lib_answers_question FOREIGN KEY (question_id)
    REFERENCES lib_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Sound sets
-- ---------------------------------------------------------------------------
CREATE TABLE sound_sets (
  id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name  VARCHAR(255) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sounds (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sound_set_id  INT UNSIGNED NOT NULL,
  cue           ENUM('correct','strike','round_start','reveal','finale_timer','end_game') NOT NULL,
  file_path     VARCHAR(500) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sound_set_cue (sound_set_id, cue),
  KEY idx_sounds_set (sound_set_id),
  CONSTRAINT fk_sounds_set FOREIGN KEY (sound_set_id)
    REFERENCES sound_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the three fixed packs (names must stay in sync with the editor picker and the
-- Administrator > Dzwieki sub-tabs, and with the folders in assets/sounds/).
INSERT INTO sound_sets (id, name) VALUES
  (1, 'Klasyczny'),
  (2, 'Retro'),
  (3, 'Filmowy');

-- Starter cue files for the Klasyczny pack (synthesized WAVs shipped in assets/sounds/).
-- Retro and Filmowy have no files yet: they fall back to assets/sounds/default/<cue>.wav.
INSERT INTO sounds (sound_set_id, cue, file_path) VALUES
  (1, 'correct',      'assets/sounds/klasyczny/correct.wav'),
  (1, 'strike',       'assets/sounds/klasyczny/strike.wav'),
  (1, 'round_start',  'assets/sounds/klasyczny/round_start.wav'),
  (1, 'reveal',       'assets/sounds/klasyczny/reveal.wav'),
  (1, 'finale_timer', 'assets/sounds/klasyczny/finale_timer.wav'),
  (1, 'end_game',     'assets/sounds/klasyczny/end_game.wav');

-- ---------------------------------------------------------------------------
-- Games + frozen content copy
-- ---------------------------------------------------------------------------
CREATE TABLE games (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(255) NOT NULL,
  mode          ENUM('classic_300','free_rounds') NOT NULL,
  grand_finale  TINYINT(1) NOT NULL DEFAULT 0,   -- V2 feature; keep column but force 0 / disabled in V1 UI
  sound_set_id  INT UNSIGNED NULL,
  status        ENUM('draft','live','in_progress','paused','finished','archived') NOT NULL DEFAULT 'draft',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at    DATETIME NULL,
  finished_at   DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_games_status (status),
  CONSTRAINT fk_games_sound_set FOREIGN KEY (sound_set_id)
    REFERENCES sound_sets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE game_question_sets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id       INT UNSIGNED NOT NULL,
  round_number  INT NOT NULL,
  multiplier    INT NOT NULL DEFAULT 1,
  name          VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_gqs_game (game_id),
  CONSTRAINT fk_gqs_game FOREIGN KEY (game_id)
    REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE game_questions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_set_id  INT UNSIGNED NOT NULL,
  text         VARCHAR(500) NOT NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_gq_set (game_set_id),
  CONSTRAINT fk_gq_set FOREIGN KEY (game_set_id)
    REFERENCES game_question_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE game_answers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_question_id  INT UNSIGNED NOT NULL,
  text              VARCHAR(255) NOT NULL,
  points            INT NOT NULL DEFAULT 0,
  sort_order        INT NOT NULL DEFAULT 0,
  revealed          TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_ga_question (game_question_id),
  CONSTRAINT fk_ga_question FOREIGN KEY (game_question_id)
    REFERENCES game_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Teams
-- ---------------------------------------------------------------------------
CREATE TABLE teams (
  id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id  INT UNSIGNED NOT NULL,
  color    ENUM('blue','red') NOT NULL,
  name     VARCHAR(255) NULL,
  score    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_team_game_color (game_id, color),
  CONSTRAINT fk_teams_game FOREIGN KEY (game_id)
    REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Live state (one row per game) — the poll target
-- ---------------------------------------------------------------------------
CREATE TABLE game_state (
  game_id               INT UNSIGNED NOT NULL,
  phase                 ENUM('lobby','round','steal','round_end','finale','finished') NOT NULL DEFAULT 'lobby',
  current_game_set_id   INT UNSIGNED NULL,
  active_team           ENUM('blue','red') NULL,
  starting_team         ENUM('blue','red') NULL,
  strikes               TINYINT NOT NULL DEFAULT 0,
  steal_in_progress     TINYINT(1) NOT NULL DEFAULT 0,
  round_pot             INT NOT NULL DEFAULT 0,   -- raw sum; multiplier applied at finish
  -- finale timer anchors (all server-clock based)
  finale_timer_status   ENUM('idle','running','paused','expired') NOT NULL DEFAULT 'idle',
  finale_duration       INT NOT NULL DEFAULT 15,
  finale_started_at     DATETIME NULL,
  finale_elapsed_before INT NOT NULL DEFAULT 0,
  finale_player         TINYINT NOT NULL DEFAULT 1,
  finale_question_index INT NOT NULL DEFAULT 0,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (game_id),
  CONSTRAINT fk_state_game FOREIGN KEY (game_id)
    REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Single "live" game pointer (always exactly one row, id=1)
-- ---------------------------------------------------------------------------
CREATE TABLE active_game (
  id       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  game_id  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_active_game FOREIGN KEY (game_id)
    REFERENCES games(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO active_game (id, game_id) VALUES (1, NULL);

-- ---------------------------------------------------------------------------
-- History
-- ---------------------------------------------------------------------------
CREATE TABLE round_results (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id            INT UNSIGNED NOT NULL,
  game_set_id        INT UNSIGNED NULL,
  round_number       INT NOT NULL,
  team_color         ENUM('blue','red') NOT NULL,
  points_awarded     INT NOT NULL,
  multiplier_applied INT NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rr_game (game_id),
  CONSTRAINT fk_rr_game FOREIGN KEY (game_id)
    REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Grand finale records
-- ---------------------------------------------------------------------------
CREATE TABLE finale_answers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id           INT UNSIGNED NOT NULL,
  finale_player     TINYINT NOT NULL,     -- 1 or 2
  question_index    INT NOT NULL,
  typed_answer      VARCHAR(255) NULL,
  matched_answer_id INT UNSIGNED NULL,    -- references a game_answers row, matched manually
  points_awarded    INT NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fa_game (game_id),
  CONSTRAINT fk_fa_game FOREIGN KEY (game_id)
    REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

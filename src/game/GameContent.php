<?php

declare(strict_types=1);

namespace Familiada\Game;

use PDO;
use RuntimeException;

/**
 * Content CRUD: the reusable library (lib_question_sets/questions/answers) and
 * the per-game editor content (game_question_sets/questions/answers), plus the
 * games list used by Administrator > Gry. Spec §5.4, §6.
 *
 * Design note (flagged ambiguity — see final report to the architect): the
 * design handoff's game editor authors question/answer blocks directly on the
 * game (no "pick a library set" control in that screen), while §6's data model
 * describes games as freezing a *chosen library set* on start. We reconcile by
 * treating the game editor as authoring game_question_sets/questions/answers
 * directly (mutable while draft, read-only once started — the "freeze" is
 * enforced by status, not by a copy step), and by adding an optional
 * "import from library" convenience (importLibrarySet) that copies a library
 * set's questions/answers into a draft game as new rounds. This keeps the
 * library CRUD meaningful without contradicting the editor UI spec.
 */
final class GameContent
{
    // ---------------------------------------------------------------
    // Content library
    // ---------------------------------------------------------------

    public static function listLibrarySets(PDO $pdo): array
    {
        $sets = $pdo->query('SELECT id, name, created_at FROM lib_question_sets ORDER BY created_at DESC')->fetchAll();
        foreach ($sets as &$set) {
            $stmt = $pdo->prepare('SELECT COUNT(*) c FROM lib_questions WHERE set_id = ?');
            $stmt->execute([$set['id']]);
            $set['question_count'] = (int) $stmt->fetch()['c'];
        }
        return $sets;
    }

    public static function getLibrarySet(PDO $pdo, int $setId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM lib_question_sets WHERE id = ?');
        $stmt->execute([$setId]);
        $set = $stmt->fetch();
        if (!$set) {
            return null;
        }

        $qStmt = $pdo->prepare('SELECT * FROM lib_questions WHERE set_id = ? ORDER BY sort_order ASC, id ASC');
        $qStmt->execute([$setId]);
        $questions = $qStmt->fetchAll();

        foreach ($questions as &$q) {
            $aStmt = $pdo->prepare('SELECT * FROM lib_answers WHERE question_id = ? ORDER BY sort_order ASC, id ASC');
            $aStmt->execute([$q['id']]);
            $q['answers'] = $aStmt->fetchAll();
        }

        $set['questions'] = $questions;
        return $set;
    }

    /** Full-replace save: id null creates, otherwise replaces all nested questions/answers. */
    public static function saveLibrarySet(PDO $pdo, ?int $setId, string $name, array $questions): int
    {
        $pdo->beginTransaction();
        try {
            if ($setId === null) {
                $pdo->prepare('INSERT INTO lib_question_sets (name) VALUES (?)')->execute([$name]);
                $setId = (int) $pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE lib_question_sets SET name = ? WHERE id = ?')->execute([$name, $setId]);
                $pdo->prepare('DELETE FROM lib_questions WHERE set_id = ?')->execute([$setId]);
            }

            foreach ($questions as $qi => $q) {
                $text = (string) ($q['text'] ?? '');
                if (trim($text) === '') {
                    continue;
                }
                $pdo->prepare('INSERT INTO lib_questions (set_id, text, sort_order) VALUES (?, ?, ?)')
                    ->execute([$setId, $text, $qi]);
                $questionId = (int) $pdo->lastInsertId();

                foreach (($q['answers'] ?? []) as $ai => $a) {
                    $aText = (string) ($a['text'] ?? '');
                    if (trim($aText) === '') {
                        continue;
                    }
                    $points = (int) ($a['points'] ?? 0);
                    $pdo->prepare('INSERT INTO lib_answers (question_id, text, points, sort_order) VALUES (?, ?, ?, ?)')
                        ->execute([$questionId, $aText, $points, $ai]);
                }
            }

            $pdo->commit();
            return $setId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function deleteLibrarySet(PDO $pdo, int $setId): void
    {
        $pdo->prepare('DELETE FROM lib_question_sets WHERE id = ?')->execute([$setId]);
    }

    // ---------------------------------------------------------------
    // Games (list + nested editor read/write)
    // ---------------------------------------------------------------

    public static function listGames(PDO $pdo): array
    {
        $sql = 'SELECT g.*, sset.name AS sound_set_name,
                       (SELECT game_id FROM active_game WHERE id = 1) AS live_game_id
                FROM games g
                LEFT JOIN sound_sets sset ON sset.id = g.sound_set_id
                WHERE g.status != "archived"
                ORDER BY g.created_at DESC';
        $games = $pdo->query($sql)->fetchAll();

        foreach ($games as &$g) {
            $stmt = $pdo->prepare('SELECT color, score FROM teams WHERE game_id = ?');
            $stmt->execute([$g['id']]);
            $scores = ['blue' => 0, 'red' => 0];
            foreach ($stmt->fetchAll() as $row) {
                $scores[$row['color']] = (int) $row['score'];
            }
            $g['scores'] = $scores;
            $g['is_live'] = $g['live_game_id'] !== null && (int) $g['live_game_id'] === (int) $g['id'];
            unset($g['live_game_id']);
        }
        return $games;
    }

    public static function getGame(PDO $pdo, int $gameId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM games WHERE id = ?');
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();
        if (!$game) {
            return null;
        }

        $sStmt = $pdo->prepare('SELECT * FROM game_question_sets WHERE game_id = ? ORDER BY round_number ASC');
        $sStmt->execute([$gameId]);
        $sets = $sStmt->fetchAll();

        foreach ($sets as &$set) {
            $qStmt = $pdo->prepare('SELECT * FROM game_questions WHERE game_set_id = ? ORDER BY sort_order ASC, id ASC');
            $qStmt->execute([$set['id']]);
            $questions = $qStmt->fetchAll();
            foreach ($questions as &$q) {
                $aStmt = $pdo->prepare('SELECT * FROM game_answers WHERE game_question_id = ? ORDER BY sort_order ASC, id ASC');
                $aStmt->execute([$q['id']]);
                $q['answers'] = $aStmt->fetchAll();
            }
            $set['questions'] = $questions;
        }
        $game['question_sets'] = $sets;

        $tStmt = $pdo->prepare('SELECT color, name, score FROM teams WHERE game_id = ?');
        $tStmt->execute([$gameId]);
        $game['teams'] = $tStmt->fetchAll();

        return $game;
    }

    /**
     * Create or update a draft game's full content (name, mode, sound_set_id,
     * and nested question_sets/questions/answers — full replace). Only allowed
     * while status = draft (enforced by caller).
     */
    public static function saveGame(PDO $pdo, ?int $gameId, array $payload): int
    {
        $name = (string) ($payload['name'] ?? 'Nowa gra');
        $mode = in_array($payload['mode'] ?? null, ['classic_300', 'free_rounds'], true) ? $payload['mode'] : 'classic_300';
        $soundSetId = isset($payload['sound_set_id']) ? (int) $payload['sound_set_id'] : null;
        $questionSets = $payload['question_sets'] ?? [];

        $pdo->beginTransaction();
        try {
            if ($gameId === null) {
                $pdo->prepare(
                    'INSERT INTO games (name, mode, grand_finale, sound_set_id, status) VALUES (?, ?, 0, ?, "draft")'
                )->execute([$name, $mode, $soundSetId]);
                $gameId = (int) $pdo->lastInsertId();
            } else {
                $game = $pdo->prepare('SELECT status FROM games WHERE id = ? FOR UPDATE');
                $game->execute([$gameId]);
                $row = $game->fetch();
                if (!$row || $row['status'] !== 'draft') {
                    throw new RuntimeException('Only draft games can be edited');
                }
                $pdo->prepare('UPDATE games SET name = ?, mode = ?, sound_set_id = ?, grand_finale = 0 WHERE id = ?')
                    ->execute([$name, $mode, $soundSetId, $gameId]);
                $pdo->prepare('DELETE FROM game_question_sets WHERE game_id = ?')->execute([$gameId]);
            }

            foreach ($questionSets as $si => $set) {
                $roundNumber = $si + 1;
                $multiplier = GameRules::multiplierForRound($mode, $roundNumber);
                $pdo->prepare('INSERT INTO game_question_sets (game_id, round_number, multiplier, name) VALUES (?, ?, ?, ?)')
                    ->execute([$gameId, $roundNumber, $multiplier, $set['name'] ?? null]);
                $gameSetId = (int) $pdo->lastInsertId();

                $questions = $set['questions'] ?? [];
                foreach ($questions as $qi => $q) {
                    $text = (string) ($q['text'] ?? '');
                    if (trim($text) === '') {
                        continue;
                    }
                    $pdo->prepare('INSERT INTO game_questions (game_set_id, text, sort_order) VALUES (?, ?, ?)')
                        ->execute([$gameSetId, $text, $qi]);
                    $questionId = (int) $pdo->lastInsertId();

                    foreach (($q['answers'] ?? []) as $ai => $a) {
                        $aText = (string) ($a['text'] ?? '');
                        if (trim($aText) === '') {
                            continue;
                        }
                        if ($ai >= 8) {
                            break; // Spec §5.4: up to 8 answers per question.
                        }
                        $points = (int) ($a['points'] ?? 0);
                        $pdo->prepare(
                            'INSERT INTO game_answers (game_question_id, text, points, sort_order) VALUES (?, ?, ?, ?)'
                        )->execute([$questionId, $aText, $points, $ai]);
                    }
                }
            }

            $pdo->commit();
            return $gameId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function deleteGame(PDO $pdo, int $gameId): void
    {
        $stmt = $pdo->prepare('SELECT status FROM games WHERE id = ?');
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'draft') {
            throw new RuntimeException('Only draft games can be deleted');
        }
        $pdo->prepare('DELETE FROM games WHERE id = ?')->execute([$gameId]);
    }

    /** Convenience: copy a library set's questions/answers into a draft game as new round(s). */
    public static function importLibrarySet(PDO $pdo, int $gameId, int $librarySetId): void
    {
        $pdo->beginTransaction();
        try {
            $game = $pdo->prepare('SELECT mode, status FROM games WHERE id = ? FOR UPDATE');
            $game->execute([$gameId]);
            $gameRow = $game->fetch();
            if (!$gameRow || $gameRow['status'] !== 'draft') {
                throw new RuntimeException('Only draft games can be edited');
            }

            $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(round_number), 0) m FROM game_question_sets WHERE game_id = ?');
            $maxStmt->execute([$gameId]);
            $nextRound = (int) $maxStmt->fetch()['m'] + 1;

            $libSet = self::getLibrarySet($pdo, $librarySetId);
            if (!$libSet) {
                throw new RuntimeException('Library set not found');
            }

            foreach ($libSet['questions'] as $q) {
                $multiplier = GameRules::multiplierForRound($gameRow['mode'], $nextRound);
                $pdo->prepare('INSERT INTO game_question_sets (game_id, round_number, multiplier, name) VALUES (?, ?, ?, ?)')
                    ->execute([$gameId, $nextRound, $multiplier, $libSet['name']]);
                $gameSetId = (int) $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO game_questions (game_set_id, text, sort_order) VALUES (?, ?, 0)')
                    ->execute([$gameSetId, $q['text']]);
                $questionId = (int) $pdo->lastInsertId();

                foreach ($q['answers'] as $ai => $a) {
                    if ($ai >= 8) {
                        break;
                    }
                    $pdo->prepare(
                        'INSERT INTO game_answers (game_question_id, text, points, sort_order) VALUES (?, ?, ?, ?)'
                    )->execute([$questionId, $a['text'], $a['points'], $ai]);
                }

                $nextRound++;
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

<?php

declare(strict_types=1);

/**
 * Integration test for GameActions::endGameByHost() — Spec §4.4 ("... or the
 * host ends the game"), and the bug the tester found: ending a free-rounds
 * game mid-round must bank the current round_pot to its owning team (same as
 * finishRound()'s board-cleared/early-finish path) before transitioning to
 * `finished` — not discard it.
 *
 * Requires a real MySQL/MariaDB connection (config.php, or FAMILIADA_TEST_DB_*
 * env vars overriding it) with db/schema.sql already imported. If no DB is
 * reachable, this SKIPS (exit 0) rather than failing the suite — the pure
 * game-rule math is covered DB-free in tests/GameRulesTest.php.
 *
 * Run with: php tests/EndGameByHostTest.php
 */

require_once __DIR__ . '/../src/lib/config.php';
require_once __DIR__ . '/../src/lib/db.php';
require_once __DIR__ . '/../src/game/GameRules.php';
require_once __DIR__ . '/../src/game/SoundLibrary.php';
require_once __DIR__ . '/../src/game/GameActions.php';
require_once __DIR__ . '/../src/game/GameContent.php';

use Familiada\Game\GameActions;
use Familiada\Game\GameContent;

function tdie(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

try {
    $pdo = familiada_db();
} catch (\Throwable $e) {
    echo "SKIPPED: no database reachable (" . $e->getMessage() . ")\n";
    exit(0);
}

$gameId = null;
try {
    // --- Arrange: a free_rounds draft game with two rounds of content, so
    // ending after round 1 is unambiguously "mid-sets", not "last set". ---
    $gameId = GameContent::saveGame($pdo, null, [
        'name' => '[test] endGameByHost pot banking',
        'mode' => 'free_rounds',
        'sound_set_id' => null,
        'question_sets' => [
            ['questions' => [['text' => 'Round 1 question', 'answers' => [
                ['text' => 'Answer A', 'points' => 5],
                ['text' => 'Answer B', 'points' => 3],
            ]]]],
            ['questions' => [['text' => 'Round 2 question', 'answers' => [
                ['text' => 'Answer C', 'points' => 7],
            ]]]],
        ],
    ]);

    GameActions::startGame($pdo, $gameId);
    // startGame lands in the lobby; the host's START GRY (beginGame) moves it to
    // phase=round, then POKAŻ PYTANIE (revealQuestion) opens the setTeam/reveal gate.
    GameActions::beginGame($pdo, $gameId);
    GameActions::revealQuestion($pdo, $gameId);
    GameActions::setTeam($pdo, $gameId, 'red');

    $state = GameActions::getBoardState($pdo, $gameId);
    $answerId = $state['answers'][0]['id']; // 5-point answer
    GameActions::reveal($pdo, $gameId, $answerId);

    $midState = GameActions::getBoardState($pdo, $gameId);
    if ($midState['round_pot'] !== 5) {
        tdie("expected round_pot=5 after reveal, got {$midState['round_pot']}");
    }
    if ($midState['phase'] !== 'round') {
        tdie("expected phase=round before end_game, got {$midState['phase']}");
    }

    // --- Act: host ends the game mid-round (round 1 of 2), with an unbanked pot. ---
    GameActions::endGameByHost($pdo, $gameId);

    // --- Assert: the pot's points made it to red's score, a round_results row
    // exists for round 1, phase/status are finished, and winner reflects it. ---
    $final = GameActions::getBoardState($pdo, $gameId);
    if ($final['phase'] !== 'finished') {
        tdie("expected phase=finished after end_game, got {$final['phase']}");
    }
    if ($final['teams']['red']['score'] !== 5) {
        tdie("expected red score=5 (banked pot), got {$final['teams']['red']['score']} — pot was discarded");
    }
    if ($final['teams']['blue']['score'] !== 0) {
        tdie("expected blue score=0, got {$final['teams']['blue']['score']}");
    }
    if ($final['winner'] !== 'red') {
        tdie("expected winner=red, got " . var_export($final['winner'], true));
    }

    $rrStmt = $pdo->prepare('SELECT * FROM round_results WHERE game_id = ? ORDER BY round_number');
    $rrStmt->execute([$gameId]);
    $rows = $rrStmt->fetchAll();
    if (count($rows) !== 1) {
        tdie('expected exactly 1 round_results row (round 1), got ' . count($rows));
    }
    if ($rows[0]['round_number'] !== 1 || (int) $rows[0]['points_awarded'] !== 5 || $rows[0]['team_color'] !== 'red') {
        tdie('round_results row does not match the banked round-1 pot: ' . json_encode($rows[0]));
    }
    if ((int) $rows[0]['multiplier_applied'] !== 1) {
        tdie('free_rounds multiplier_applied should always be 1, got ' . $rows[0]['multiplier_applied']);
    }

    $gameRow = $pdo->prepare('SELECT status FROM games WHERE id = ?');
    $gameRow->execute([$gameId]);
    if ($gameRow->fetch()['status'] !== 'finished') {
        tdie('expected games.status=finished');
    }

    echo "PASS: end_game mid-round banks the in-flight pot correctly (5 pts -> red, round_results written, winner=red).\n";
} finally {
    if ($gameId !== null) {
        $pdo->prepare('DELETE FROM games WHERE id = ?')->execute([$gameId]);
    }
}

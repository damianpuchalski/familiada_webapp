<?php

declare(strict_types=1);

/**
 * Plain-assertion unit tests for the pure game-rule math in src/game/GameRules.php.
 * No PHPUnit dependency (keeps the project build-step-free per PROJECT_SPEC.md).
 * Run with: php tests/GameRulesTest.php
 *
 * Covers PROJECT_SPEC.md §4.4 (scoring modes) and the parts of §4.6 (invariants)
 * that are pure functions of scores/strikes, independent of the DB.
 */

require_once __DIR__ . '/../src/game/GameRules.php';

use Familiada\Game\GameRules;

$failures = [];
$passed = 0;

function check(string $label, bool $condition, array &$failures, int &$passed): void
{
    if ($condition) {
        $passed++;
    } else {
        $failures[] = $label;
    }
}

// --- multiplierForRound: classic_300 §4.4 ---
check('classic round 1 -> x1', GameRules::multiplierForRound('classic_300', 1) === 1, $failures, $passed);
check('classic round 2 -> x1', GameRules::multiplierForRound('classic_300', 2) === 1, $failures, $passed);
check('classic round 3 -> x1', GameRules::multiplierForRound('classic_300', 3) === 1, $failures, $passed);
check('classic round 4 -> x2', GameRules::multiplierForRound('classic_300', 4) === 2, $failures, $passed);
check('classic round 5 -> x3', GameRules::multiplierForRound('classic_300', 5) === 3, $failures, $passed);
check('classic round 6 (beyond table) holds at x3', GameRules::multiplierForRound('classic_300', 6) === 3, $failures, $passed);

// --- free_rounds: always x1 ---
foreach ([1, 2, 5, 10] as $r) {
    check("free_rounds round {$r} -> x1", GameRules::multiplierForRound('free_rounds', $r) === 1, $failures, $passed);
}

// --- opposite() ---
check('opposite(blue) == red', GameRules::opposite('blue') === 'red', $failures, $passed);
check('opposite(red) == blue', GameRules::opposite('red') === 'blue', $failures, $passed);

// --- team select lock: Spec §4.3, locks at >=3 revealed ---
check('0 revealed -> not locked', GameRules::isTeamSelectLocked(0) === false, $failures, $passed);
check('2 revealed -> not locked', GameRules::isTeamSelectLocked(2) === false, $failures, $passed);
check('3 revealed -> locked', GameRules::isTeamSelectLocked(3) === true, $failures, $passed);
check('6 revealed -> locked', GameRules::isTeamSelectLocked(6) === true, $failures, $passed);

// --- computeAwarded: Spec §4.2 clause 5, §4.6 "awarded == round_pot * multiplier_applied" ---
check('awarded = pot * multiplier (0 pot)', GameRules::computeAwarded(0, 3) === 0, $failures, $passed);
check('awarded = pot * multiplier (100 * 2)', GameRules::computeAwarded(100, 2) === 200, $failures, $passed);
check('awarded = pot * multiplier (37 * 1)', GameRules::computeAwarded(37, 1) === 37, $failures, $passed);

// --- applyStrike: strikes 0-3 invariant, steal only enters on strike 3 (Spec §4.2 clause 3, §4.6) ---
$r1 = GameRules::applyStrike(0);
check('strike 1: strikes=1, no steal', $r1['strikes'] === 1 && $r1['enteredSteal'] === false, $failures, $passed);
$r2 = GameRules::applyStrike(1);
check('strike 2: strikes=2, no steal', $r2['strikes'] === 2 && $r2['enteredSteal'] === false, $failures, $passed);
$r3 = GameRules::applyStrike(2);
check('strike 3: strikes=3, enters steal', $r3['strikes'] === 3 && $r3['enteredSteal'] === true, $failures, $passed);

$threw = false;
try {
    GameRules::applyStrike(3);
} catch (\LogicException $e) {
    $threw = true;
}
check('cannot strike a 4th time (strikes stop accumulating past 3)', $threw, $failures, $passed);

// --- classicWinner: Spec §4.4, ends at >=300 ---
check('classicWinner: neither over -> null', GameRules::classicWinner(250, 280) === null, $failures, $passed);
check('classicWinner: blue over -> blue', GameRules::classicWinner(300, 200) === 'blue', $failures, $passed);
check('classicWinner: red over -> red', GameRules::classicWinner(150, 305) === 'red', $failures, $passed);
check('classicWinner: both over, higher wins', GameRules::classicWinner(310, 305) === 'blue', $failures, $passed);
check('classicWinner: exact tie at 300 -> null (host resolves)', GameRules::classicWinner(300, 300) === null, $failures, $passed);

// --- freeRoundsWinner: Spec §4.4, higher score wins when sets run out ---
check('freeRoundsWinner: blue higher', GameRules::freeRoundsWinner(120, 90) === 'blue', $failures, $passed);
check('freeRoundsWinner: red higher', GameRules::freeRoundsWinner(50, 60) === 'red', $failures, $passed);
check('freeRoundsWinner: tie -> null', GameRules::freeRoundsWinner(80, 80) === null, $failures, $passed);

// --- freeRoundsWinner as used by GameActions::advanceAfterRoundEnd() (auto-finish once
// the last game_question_set's round ends) and GameActions::endGameByHost() (host ends
// the game mid-sets via the 'end_game' action) — Spec §4.4, coordinator follow-up. Both
// code paths funnel through this same function; these scenarios pin its exact behavior. ---
check(
    'last-set finish, clear leader: blue wins (e.g. 180 vs 140 after round 5 of 5)',
    GameRules::freeRoundsWinner(180, 140) === 'blue',
    $failures,
    $passed
);
check(
    'last-set finish, tie: no forced winner (host resolves manually, Spec §4.4 note)',
    GameRules::freeRoundsWinner(150, 150) === null,
    $failures,
    $passed
);
check(
    'host ends game early mid-sets (e.g. after round 3 of 8), blue leading: blue wins',
    GameRules::freeRoundsWinner(90, 60) === 'blue',
    $failures,
    $passed
);
check(
    'host ends game early mid-sets, red leading: red wins',
    GameRules::freeRoundsWinner(40, 75) === 'red',
    $failures,
    $passed
);

// --- classic_300 never uses freeRoundsWinner's tie-null semantics; the two are mode-gated
// in GameActions::getBoardState()/advanceAfterRoundEnd() and must not be interchangeable. ---
check(
    'classicWinner and freeRoundsWinner diverge on an exact tie at the 300 threshold',
    GameRules::classicWinner(300, 300) === null && GameRules::freeRoundsWinner(300, 300) === null,
    $failures,
    $passed
);

// --- isValidTeam ---
check('blue is valid team', GameRules::isValidTeam('blue') === true, $failures, $passed);
check('red is valid team', GameRules::isValidTeam('red') === true, $failures, $passed);
check('green is not a valid team', GameRules::isValidTeam('green') === false, $failures, $passed);

echo "Passed: {$passed}\n";
if ($failures) {
    echo 'FAILED (' . count($failures) . "):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "All GameRules tests passed.\n";
exit(0);

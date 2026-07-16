<?php

declare(strict_types=1);

namespace Familiada\Game;

/**
 * Pure game-rule functions — no DB, no I/O. Enforces PROJECT_SPEC.md §4 exactly.
 * Kept side-effect free so it is trivially unit-testable (see tests/GameRulesTest.php).
 */
final class GameRules
{
    /** Classic-300 multiplier by round number (1-indexed). Spec §4.4. */
    private const CLASSIC_MULTIPLIERS = [1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 3];

    /** Classic-300 win threshold. Spec §4.4. */
    public const CLASSIC_TARGET = 300;

    /** Team selector locks once >= this many answers are revealed. Spec §4.3. */
    public const TEAM_SELECT_LOCK_THRESHOLD = 3;

    public static function isValidTeam(string $team): bool
    {
        return $team === 'blue' || $team === 'red';
    }

    public static function opposite(string $team): string
    {
        if (!self::isValidTeam($team)) {
            throw new \InvalidArgumentException("Invalid team: {$team}");
        }
        return $team === 'blue' ? 'red' : 'blue';
    }

    /**
     * Multiplier for a given 1-indexed round number under a game mode.
     * classic_300: 1/1/1/2/3 for rounds 1-5; rounds beyond 5 (shouldn't normally
     * be reached because the game ends at >=300) hold at the last defined value (3).
     * free_rounds: always x1.
     */
    public static function multiplierForRound(string $mode, int $roundNumber): int
    {
        if ($mode === 'free_rounds') {
            return 1;
        }
        if ($roundNumber < 1) {
            $roundNumber = 1;
        }
        if (isset(self::CLASSIC_MULTIPLIERS[$roundNumber])) {
            return self::CLASSIC_MULTIPLIERS[$roundNumber];
        }
        return self::CLASSIC_MULTIPLIERS[5];
    }

    /** Spec §4.3: selector locks once >=3 answers are revealed in the current question. */
    public static function isTeamSelectLocked(int $revealedCount): bool
    {
        return $revealedCount >= self::TEAM_SELECT_LOCK_THRESHOLD;
    }

    /** Spec §4.2 clause 5: awarded = round_pot * multiplier. */
    public static function computeAwarded(int $roundPot, int $multiplier): int
    {
        return $roundPot * $multiplier;
    }

    /**
     * Spec §4.2 clause 3: apply one strike against the active/starting team.
     * Returns the new strikes count and, on the 3rd strike, the steal transition.
     *
     * @return array{strikes:int, enteredSteal:bool}
     */
    public static function applyStrike(int $currentStrikes): array
    {
        if ($currentStrikes >= 3) {
            throw new \LogicException('Cannot strike again: already at 3 strikes.');
        }
        $strikes = $currentStrikes + 1;
        return [
            'strikes'      => $strikes,
            'enteredSteal' => $strikes === 3,
        ];
    }

    /**
     * Spec §4.4: classic_300 ends when either team's score >= 300. Returns the
     * winning color, or null if the game continues. If both somehow cross in the
     * same update, the higher score wins (tie -> null, extremely unlikely in practice).
     */
    public static function classicWinner(int $blueScore, int $redScore): ?string
    {
        $blueOver = $blueScore >= self::CLASSIC_TARGET;
        $redOver  = $redScore >= self::CLASSIC_TARGET;
        if (!$blueOver && !$redOver) {
            return null;
        }
        if ($blueOver && $redOver) {
            if ($blueScore === $redScore) {
                return null; // exceptional tie; let the host resolve manually
            }
            return $blueScore > $redScore ? 'blue' : 'red';
        }
        return $blueOver ? 'blue' : 'red';
    }

    /**
     * Spec §4.4: final winner for a classic_300 game that has ENDED. If a team crossed
     * 300 the instant-win rule decides it (classicWinner). Otherwise the game ran out of
     * sets without anyone reaching 300 — it ends by exhaustion and the higher score wins
     * (like free_rounds), rather than being misreported as a tie. An exact tie at/above
     * 300 stays unresolved (null) for the host, matching classicWinner's semantics.
     */
    public static function classicFinalWinner(int $blueScore, int $redScore): ?string
    {
        $winner = self::classicWinner($blueScore, $redScore);
        if ($winner !== null) {
            return $winner;
        }
        // Neither team crossed 300 -> ended by set exhaustion -> highest score wins.
        if ($blueScore < self::CLASSIC_TARGET && $redScore < self::CLASSIC_TARGET) {
            return self::freeRoundsWinner($blueScore, $redScore);
        }
        // Exact tie at/above the target — host resolves manually.
        return null;
    }

    /** Spec §4.4: free_rounds winner once the game is ended (sets exhausted or host ends it). */
    public static function freeRoundsWinner(int $blueScore, int $redScore): ?string
    {
        if ($blueScore === $redScore) {
            return null; // genuine tie — no forced winner in V1
        }
        return $blueScore > $redScore ? 'blue' : 'red';
    }
}

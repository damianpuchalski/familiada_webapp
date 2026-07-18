<?php

declare(strict_types=1);

namespace Familiada\Game;

use PDO;
use RuntimeException;

/**
 * DB-backed orchestration of the state machine defined in PROJECT_SPEC.md §4.
 * Pure scoring/transition math is delegated to GameRules; this class owns the
 * SQL and transactions. Every method assumes the caller has already checked
 * auth where relevant (lifecycle/admin actions) — reveal/strike/etc are meant
 * to be reachable by an authenticated host only, enforced in public/api/action.php.
 */
final class GameActions
{
    // ---------------------------------------------------------------
    // Read: full board/cockpit state
    // ---------------------------------------------------------------

    /**
     * @param bool $redactHidden When true, unrevealed answers have their text/points
     *   stripped (text: '', points: 0) so the unauthenticated public board (state.php)
     *   cannot read hidden answers/points mid-game. The authenticated cockpit passes
     *   false to get the full data it needs to run the round. The question text is
     *   always public (Spec §3.2). Default true = safe-by-default for any new caller.
     */
    public static function getBoardState(PDO $pdo, int $gameId, bool $redactHidden = true): array
    {
        $game = self::fetchGame($pdo, $gameId);
        if (!$game) {
            throw new RuntimeException('Game not found');
        }

        $state = self::fetchGameState($pdo, $gameId);
        $teams = self::fetchTeams($pdo, $gameId);

        $currentSet = null;
        $question = null;
        $answers = [];
        $revealedCount = 0;

        if ($state && $state['current_game_set_id']) {
            $currentSet = self::fetchQuestionSet($pdo, (int) $state['current_game_set_id']);
            if ($currentSet) {
                $question = self::fetchQuestionForSet($pdo, (int) $currentSet['id']);
                if ($question) {
                    $answers = self::fetchAnswers($pdo, (int) $question['id']);
                    foreach ($answers as $a) {
                        if ((int) $a['revealed'] === 1) {
                            $revealedCount++;
                        }
                    }
                }
            }
        }

        $mode = $game['mode'];
        $roundNumber = $currentSet['round_number'] ?? null;
        $multiplier = $currentSet['multiplier'] ?? ($roundNumber ? GameRules::multiplierForRound($mode, (int) $roundNumber) : 1);

        // Spec §4.4: winner is only meaningful once the game has ended. Computed here (not
        // stored — there's no games.winner column) so board/cockpit never have to re-derive
        // it client-side. classic_300 uses classicFinalWinner() so a game that runs out of
        // sets without anyone reaching 300 falls back to highest-score-wins instead of a
        // false "REMIS"; free_rounds' tie-aware freeRoundsWinner() is its source of truth.
        $winner = null;
        if (($state['phase'] ?? null) === 'finished') {
            $blueScore = (int) ($teams['blue']['score'] ?? 0);
            $redScore = (int) ($teams['red']['score'] ?? 0);
            $winner = $mode === 'classic_300'
                ? GameRules::classicFinalWinner($blueScore, $redScore)
                : GameRules::freeRoundsWinner($blueScore, $redScore);
        }

        // Whether finishing/advancing the current round will end the game rather
        // than load another round — mirrors advanceAfterRoundEnd()'s decision so the
        // cockpit can label the button "ZAKOŃCZ GRĘ" and play only the end_game cue
        // (not round_start) on the final round. Most meaningful at round_end, where
        // this round's points are already banked (classic_300's >=300 check is exact).
        $isLastRound = false;
        if ($currentSet && in_array($state['phase'] ?? null, ['round', 'steal', 'round_end'], true)) {
            $blueScore = (int) ($teams['blue']['score'] ?? 0);
            $redScore = (int) ($teams['red']['score'] ?? 0);
            if ($mode === 'classic_300' && GameRules::classicWinner($blueScore, $redScore) !== null) {
                $isLastRound = true;
            } else {
                $nextSet = self::fetchQuestionSetByRound($pdo, $gameId, (int) $currentSet['round_number'] + 1);
                $isLastRound = $nextSet === null;
            }
        }

        $questionHidden = $redactHidden
            && $state
            && in_array($state['phase'] ?? null, ['lobby', 'round'], true)
            && (int) $state['question_revealed'] === 0;

        $soundSetId = $game['sound_set_id'] !== null ? (int) $game['sound_set_id'] : null;
        $soundUrls = [];
        $soundSetName = null;
        if ($soundSetId !== null) {
            foreach (SoundLibrary::packStatus($pdo, $soundSetId) as $cueInfo) {
                // Absolute (leading-slash) URL — resolves the same from /board/ or /admin/,
                // unlike a relative "assets/sounds/..." path (Spec §8/§9).
                $soundUrls[$cueInfo['cue']] = $cueInfo['url'];
            }
            $nameStmt = $pdo->prepare('SELECT name FROM sound_sets WHERE id = ?');
            $nameStmt->execute([$soundSetId]);
            $soundSetName = ($nameStmt->fetch())['name'] ?? null;
        } else {
            foreach (SoundLibrary::CUES as $cue) {
                $soundUrls[$cue] = SoundLibrary::urlFor("default/{$cue}.wav");
            }
        }

        return [
            'game' => [
                'id'     => (int) $game['id'],
                'name'   => $game['name'],
                'mode'   => $game['mode'],
                'status' => $game['status'],
                'sound_set_id'   => $soundSetId,
                'sound_set_name' => $soundSetName,
            ],
            'sound_urls' => $soundUrls,
            'winner'             => $winner,
            'phase'              => $state['phase'] ?? 'lobby',
            'round_number'       => $roundNumber !== null ? (int) $roundNumber : null,
            'multiplier'         => (int) $multiplier,
            'question_revealed'  => $state ? (bool) $state['question_revealed'] : true,
            'starting_team'      => $state['starting_team'] ?? null,
            'active_team'        => $state['active_team'] ?? null,
            'strikes'            => $state ? (int) $state['strikes'] : 0,
            'steal_in_progress'  => $state ? (bool) $state['steal_in_progress'] : false,
            'steal_result'       => $state['steal_result'] ?? 'none',
            'round_pot'          => $state ? (int) $state['round_pot'] : 0,
            // Server-authoritative sound cue: `cue` is the last cue an action stamped, `cue_seq`
            // a monotonic counter. Board + presenter each play `cue` the moment they see cue_seq
            // advance past the last one they played (see board.js / cockpit.js) — no snapshot diffing.
            'cue'                => $state['cue'] ?? null,
            'cue_seq'            => $state ? (int) $state['cue_seq'] : 0,
            'is_last_round'      => $isLastRound,
            'team_select_locked' => GameRules::isTeamSelectLocked($revealedCount),
            // Before the presenter reveals the question (phase=round, question_revealed=0),
            // hide question text and all answers from unauthenticated callers so a contestant
            // can never read ahead of the presenter (Spec §3.2, §7 extended by the reveal gate).
            'question'           => $question
                ? ['id' => (int) $question['id'], 'text' => $questionHidden ? '' : $question['text']]
                : null,
            'answers'            => array_map(static function (array $a) use ($redactHidden, $questionHidden): array {
                $revealed = (int) $a['revealed'] === 1;
                $hide = $questionHidden || ($redactHidden && !$revealed);
                return [
                    'id'       => (int) $a['id'],
                    'text'     => $hide ? '' : $a['text'],
                    'points'   => $hide ? 0 : (int) $a['points'],
                    'revealed' => $revealed,
                    'sort_order' => (int) $a['sort_order'],
                ];
            }, $answers),
            'teams' => $teams,
            'now'   => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    // ---------------------------------------------------------------
    // Round-flow actions (Spec §4.2-4.4)
    // ---------------------------------------------------------------

    /**
     * Records the sound cue every viewer should play next and bumps the monotonic
     * cue_seq so board+presenter fire it exactly once (Spec §8). Call inside the
     * mutating action's own transaction — `cue_seq + 1` is then atomic. Public so
     * the manual cue grid (action.php's 'cue' action) can broadcast a cue to the
     * board without changing any game state.
     */
    public static function stampCue(PDO $pdo, int $gameId, string $cue): void
    {
        $pdo->prepare('UPDATE game_state SET cue = ?, cue_seq = cue_seq + 1 WHERE game_id = ?')
            ->execute([$cue, $gameId]);
    }

    public static function setTeam(PDO $pdo, int $gameId, string $team): void
    {
        if (!GameRules::isValidTeam($team)) {
            throw new RuntimeException('Invalid team');
        }
        $pdo->beginTransaction();
        try {
            $state = self::fetchGameState($pdo, $gameId, true);
            self::assertPhase($state, ['round']);
            self::assertQuestionRevealed($state);

            $revealedCount = self::currentRevealedCount($pdo, $state);
            if (GameRules::isTeamSelectLocked($revealedCount)) {
                throw new RuntimeException('Team selector is locked (>=3 answers revealed).');
            }

            $stmt = $pdo->prepare(
                'UPDATE game_state SET starting_team = ?, active_team = ? WHERE game_id = ?'
            );
            $stmt->execute([$team, $team, $gameId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function reveal(PDO $pdo, int $gameId, int $answerId): void
    {
        $pdo->beginTransaction();
        try {
            $state = self::fetchGameState($pdo, $gameId, true);
            self::assertPhase($state, ['round', 'steal', 'round_end']);

            // Require a starting team before revealing in a normal round: otherwise 3 reveals
            // lock setTeam() (Spec §4.3) and finishRound()/endGameByHost() can never bank the
            // pot ("No starting team set"), dead-ending the round. Steal phase is unaffected —
            // by then a team was already chosen and the steal target is set.
            if ($state['phase'] === 'round' && empty($state['starting_team'])) {
                throw new RuntimeException('Select a starting team before revealing answers.');
            }
            if ($state['phase'] !== 'round_end') {
                self::assertQuestionRevealed($state);
            }
            if ($state['phase'] === 'steal' && $state['steal_result'] !== 'none') {
                throw new RuntimeException('Steal already resolved for this round — click ZAKOŃCZ RUNDĘ');
            }

            $answer = self::fetchAnswerForUpdate($pdo, $answerId);
            if (!$answer) {
                throw new RuntimeException('Answer not found');
            }
            if ((int) $answer['revealed'] === 1) {
                throw new RuntimeException('Answer already revealed');
            }
            // Confirm the answer belongs to the currently active question.
            $question = self::fetchQuestionForSet($pdo, (int) $state['current_game_set_id']);
            if (!$question || (int) $answer['game_question_id'] !== (int) $question['id']) {
                throw new RuntimeException('Answer does not belong to the current question');
            }

            $pdo->prepare('UPDATE game_answers SET revealed = 1 WHERE id = ?')->execute([$answerId]);
            // 'correct' ding on every reveal — including round_end "reveal for show" (parity with
            // the old client-side detectAndPlay, which dinged whenever the revealed count rose).
            self::stampCue($pdo, $gameId, 'correct');

            if ($state['phase'] === 'round_end') {
                // Post-round "reveal for show" — the pot is already banked, this is purely
                // cosmetic so the audience sees every answer; no points change hands.
                $pdo->commit();
                return;
            }

            $newPot = (int) $state['round_pot'] + (int) $answer['points'];

            if ($state['phase'] === 'steal') {
                // Spec §4.2 clause 4: steal succeeds -> the pot is now earmarked for the
                // stealing team, but banking/round_end waits for the presenter's manual
                // "ZAKOŃCZ RUNDĘ" click (finishRound()) — no auto-transition here.
                $pdo->prepare(
                    'UPDATE game_state SET round_pot = ?, steal_result = "success" WHERE game_id = ?'
                )->execute([$newPot, $gameId]);
            } else {
                $pdo->prepare('UPDATE game_state SET round_pot = ? WHERE game_id = ?')->execute([$newPot, $gameId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function strike(PDO $pdo, int $gameId): void
    {
        $pdo->beginTransaction();
        try {
            $state = self::fetchGameState($pdo, $gameId, true);
            self::assertPhase($state, ['round', 'steal']);
            self::assertQuestionRevealed($state);

            if (empty($state['starting_team'])) {
                throw new RuntimeException('No starting team set for this round yet');
            }

            if ($state['phase'] === 'steal') {
                if ($state['steal_result'] !== 'none') {
                    throw new RuntimeException('Steal already resolved for this round — click ZAKOŃCZ RUNDĘ');
                }
                // Spec §4.2 clause 4: steal fails -> the pot is earmarked for the starting
                // team, but banking/round_end waits for the presenter's manual "ZAKOŃCZ
                // RUNDĘ" click (finishRound()) — this only records the outcome so the
                // client can play the strike cue (Spec §8 extended: a failed steal must
                // be audible, not silently jump straight to the round_end cue).
                $pdo->prepare('UPDATE game_state SET steal_result = "failed" WHERE game_id = ?')->execute([$gameId]);
            } else {
                $result = GameRules::applyStrike((int) $state['strikes']);
                if ($result['enteredSteal']) {
                    $stealingTeam = GameRules::opposite($state['starting_team']);
                    $pdo->prepare(
                        'UPDATE game_state SET strikes = ?, phase = "steal", steal_in_progress = 1, active_team = ? WHERE game_id = ?'
                    )->execute([$result['strikes'], $stealingTeam, $gameId]);
                } else {
                    $pdo->prepare('UPDATE game_state SET strikes = ? WHERE game_id = ?')
                        ->execute([$result['strikes'], $gameId]);
                }
            }

            // Every strike() path is audible: a normal strike, the 3rd strike that opens the steal,
            // and a failed steal (steal_result='failed') all play the strike cue.
            self::stampCue($pdo, $gameId, 'strike');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * ZAKOŃCZ RUNDĘ. The presenter's manual "the round is over, bank it" click —
     * this is what actually transitions to phase=round_end (and so is what plays
     * the round_end cue; see sound.js). Valid from:
     *   - phase=round (board-cleared / host ends round early) — banks the pot to
     *     the starting team.
     *   - phase=steal, but only once the steal attempt has resolved (steal_result
     *     != 'none' — set by reveal()/strike()): banks to whichever team the
     *     resolved outcome earmarked (the stealer on success, the starting team
     *     on failure). A steal still mid-attempt (steal_result='none') can't be
     *     finished yet — there's nothing to bank.
     * Does NOT advance to the next round: that only happens once the presenter
     * clicks again for advanceRound() (Spec §4.2 clause 5, §4.4).
     */
    public static function finishRound(PDO $pdo, int $gameId): void
    {
        $pdo->beginTransaction();
        try {
            $state = self::fetchGameState($pdo, $gameId, true);
            self::assertPhase($state, ['round', 'steal']);

            if ($state['phase'] === 'steal') {
                if ($state['steal_result'] === 'none') {
                    throw new RuntimeException('Steal attempt not resolved yet');
                }
                $winningTeam = $state['steal_result'] === 'success' ? $state['active_team'] : $state['starting_team'];
                self::bankPointsAndCloseRound($pdo, $gameId, $state, (int) $state['round_pot'], $winningTeam);
            } else {
                if (empty($state['starting_team'])) {
                    throw new RuntimeException('No starting team set for this round yet');
                }
                self::bankPointsAndCloseRound($pdo, $gameId, $state, (int) $state['round_pot'], $state['starting_team']);
            }

            self::stampCue($pdo, $gameId, 'round_end');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Presenter's "next round" step, called once the round_end sound cue has
     * finished playing (client-gated — see cockpit.js). Loads the next round's
     * question hidden (question_revealed=0) or ends the game if none remain.
     */
    public static function advanceRound(PDO $pdo, int $gameId): void
    {
        $pdo->beginTransaction();
        try {
            $state = self::fetchGameState($pdo, $gameId, true);
            self::assertPhase($state, ['round_end']);

            self::advanceAfterRoundEnd($pdo, $gameId, $state);
            // Cue depends on where advanceAfterRoundEnd() landed: end_game if this was the final
            // round (game over), round_start if another round loaded. Server decides — the client
            // no longer guesses phase==='finished' (which used to overlap end_game with round_start).
            $newState = self::fetchGameState($pdo, $gameId);
            self::stampCue($pdo, $gameId, ($newState['phase'] ?? null) === 'finished' ? 'end_game' : 'round_start');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Presenter's "START GAME" button, called once the game_start sound cue has
     * finished playing on the presenter's device. lobby -> round, question stays
     * hidden (question_revealed=0) until revealQuestion() is called.
     */
    public static function beginGame(PDO $pdo, int $gameId): void
    {
        $pdo->beginTransaction();
        try {
            $state = self::fetchGameState($pdo, $gameId, true);
            self::assertPhase($state, ['lobby']);

            $pdo->prepare('UPDATE game_state SET phase = "round" WHERE game_id = ?')->execute([$gameId]);
            self::stampCue($pdo, $gameId, 'game_start');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Presenter clicks "POKAŻ PYTANIE" once they've read the question aloud —
     * reveals the question text/answers to the public board.
     */
    public static function revealQuestion(PDO $pdo, int $gameId): void
    {
        $pdo->beginTransaction();
        try {
            $state = self::fetchGameState($pdo, $gameId, true);
            self::assertPhase($state, ['round']);
            if ((int) $state['question_revealed'] === 1) {
                throw new RuntimeException('Question already revealed');
            }

            $pdo->prepare('UPDATE game_state SET question_revealed = 1 WHERE game_id = ?')->execute([$gameId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Bank round_pot*multiplier to $winningTeam, write round_results, move phase to round_end. */
    private static function bankPointsAndCloseRound(PDO $pdo, int $gameId, array $state, int $roundPot, string $winningTeam): void
    {
        $game = self::fetchGame($pdo, $gameId, true);
        $currentSet = self::fetchQuestionSet($pdo, (int) $state['current_game_set_id']);
        $multiplier = $currentSet ? (int) $currentSet['multiplier'] : 1;
        $awarded = GameRules::computeAwarded($roundPot, $multiplier);

        $pdo->prepare('UPDATE teams SET score = score + ? WHERE game_id = ? AND color = ?')
            ->execute([$awarded, $gameId, $winningTeam]);

        $pdo->prepare(
            'INSERT INTO round_results (game_id, game_set_id, round_number, team_color, points_awarded, multiplier_applied)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $gameId,
            $currentSet['id'] ?? null,
            $currentSet['round_number'] ?? 0,
            $winningTeam,
            $awarded,
            $multiplier,
        ]);

        $pdo->prepare(
            'UPDATE game_state SET phase = "round_end", round_pot = ?, steal_in_progress = 0, steal_result = "none" WHERE game_id = ?'
        )->execute([$roundPot, $gameId]);
    }

    private static function advanceAfterRoundEnd(PDO $pdo, int $gameId, array $state): void
    {
        $game = self::fetchGame($pdo, $gameId, true);
        $teams = self::fetchTeams($pdo, $gameId);
        $blueScore = (int) ($teams['blue']['score'] ?? 0);
        $redScore = (int) ($teams['red']['score'] ?? 0);

        if ($game['mode'] === 'classic_300') {
            // Spec §4.4: classic_300 ends the instant either team crosses 300.
            $winner = GameRules::classicWinner($blueScore, $redScore);
            if ($winner !== null) {
                self::endGame($pdo, $gameId);
                return;
            }
        }

        $currentSet = self::fetchQuestionSet($pdo, (int) $state['current_game_set_id']);
        $nextRoundNumber = ($currentSet ? (int) $currentSet['round_number'] : 0) + 1;
        $nextSet = self::fetchQuestionSetByRound($pdo, $gameId, $nextRoundNumber);

        if (!$nextSet) {
            // Spec §4.4: free_rounds ends automatically once the sets run out — the
            // higher score wins (freeRoundsWinner()'s tie handling applies; getBoardState()
            // is what actually surfaces the 'winner' value to clients, computed the same way).
            self::endGame($pdo, $gameId);
            return;
        }

        $pdo->prepare(
            'UPDATE game_state SET phase = "round", current_game_set_id = ?, strikes = 0,
             steal_in_progress = 0, round_pot = 0, starting_team = NULL, active_team = NULL,
             question_revealed = 0
             WHERE game_id = ?'
        )->execute([$nextSet['id'], $gameId]);
    }

    /**
     * Host-initiated early end of a free_rounds game (Spec §4.4: "... or the host ends
     * the game"). Not available for classic_300 (that mode only ends automatically at
     * >=300 — Spec §4.4) and only outside round_end/steal, matching the `end_game`
     * action's guard in public/api/action.php.
     */
    public static function endGameByHost(PDO $pdo, int $gameId): void
    {
        $pdo->beginTransaction();
        try {
            $game = self::fetchGame($pdo, $gameId, true);
            if (!$game || $game['mode'] !== 'free_rounds') {
                throw new RuntimeException('Only a free-rounds game can be ended early by the host');
            }
            $state = self::fetchGameState($pdo, $gameId, true);
            self::assertPhase($state, ['round']);

            if ((int) $state['round_pot'] > 0) {
                if (empty($state['starting_team'])) {
                    throw new RuntimeException('No starting team set for this round yet');
                }
                // Bank whatever's already been revealed this round to its owning team before
                // ending the game — same "close out whatever's in-flight" step finishRound()
                // takes on a board-cleared/early finish (Spec §4.2 clause 5); free_rounds'
                // multiplier is always x1 so no multiplier math is needed here.
                self::bankPointsAndCloseRound($pdo, $gameId, $state, (int) $state['round_pot'], $state['starting_team']);
            }

            self::endGame($pdo, $gameId);
            self::stampCue($pdo, $gameId, 'end_game');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function endGame(PDO $pdo, int $gameId): void
    {
        $pdo->prepare('UPDATE game_state SET phase = "finished" WHERE game_id = ?')->execute([$gameId]);
        $pdo->prepare('UPDATE games SET status = "finished", finished_at = NOW() WHERE id = ?')->execute([$gameId]);
    }

    // ---------------------------------------------------------------
    // Lifecycle (Spec §6.1)
    // ---------------------------------------------------------------

    /** draft -> live: create teams, init game_state at round 1, point active_game, demote previous live. */
    public static function startGame(PDO $pdo, int $gameId): void
    {
        $pdo->beginTransaction();
        try {
            $game = self::fetchGame($pdo, $gameId, true);
            if (!$game || $game['status'] !== 'draft') {
                throw new RuntimeException('Only a draft game can be started');
            }

            $firstSet = self::fetchQuestionSetByRound($pdo, $gameId, 1);
            if (!$firstSet) {
                throw new RuntimeException('Game has no rounds/questions to play');
            }

            $pdo->prepare('INSERT INTO teams (game_id, color, score) VALUES (?, "blue", 0), (?, "red", 0)')
                ->execute([$gameId, $gameId]);

            $pdo->prepare(
                'INSERT INTO game_state (game_id, phase, current_game_set_id, question_revealed, strikes, steal_in_progress, round_pot)
                 VALUES (?, "lobby", ?, 0, 0, 0, 0)'
            )->execute([$gameId, $firstSet['id']]);

            self::demoteLiveAndPromote($pdo, $gameId);

            $pdo->prepare('UPDATE games SET status = "live", started_at = NOW() WHERE id = ?')->execute([$gameId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** in_progress -> live (resume): just repoint active_game + demote the old one. */
    public static function setLive(PDO $pdo, int $gameId): void
    {
        $pdo->beginTransaction();
        try {
            $game = self::fetchGame($pdo, $gameId, true);
            if (!$game) {
                throw new RuntimeException('Game not found');
            }
            if ($game['status'] === 'draft') {
                // Starting a draft for the first time needs full initialization.
                $pdo->rollBack();
                self::startGame($pdo, $gameId);
                return;
            }
            if (!in_array($game['status'], ['in_progress', 'paused'], true)) {
                throw new RuntimeException('Game cannot be set live from its current status');
            }

            self::demoteLiveAndPromote($pdo, $gameId);
            $pdo->prepare('UPDATE games SET status = "live" WHERE id = ?')->execute([$gameId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Demotes whichever game is currently live to in_progress (never draft), then points active_game at $gameId. */
    private static function demoteLiveAndPromote(PDO $pdo, int $gameId): void
    {
        $current = $pdo->query('SELECT game_id FROM active_game WHERE id = 1 FOR UPDATE')->fetch();
        $currentLiveId = $current['game_id'] ?? null;

        if ($currentLiveId !== null && (int) $currentLiveId !== $gameId) {
            $pdo->prepare('UPDATE games SET status = "in_progress" WHERE id = ? AND status = "live"')
                ->execute([$currentLiveId]);
        }

        $pdo->prepare('UPDATE active_game SET game_id = ? WHERE id = 1')->execute([$gameId]);
    }

    /** Restart-from-start: keep frozen content, wipe play state. Spec §6.1. */
    public static function restartGame(PDO $pdo, int $gameId): void
    {
        $pdo->beginTransaction();
        try {
            $game = self::fetchGame($pdo, $gameId, true);
            if (!$game || !in_array($game['status'], ['in_progress', 'finished', 'live', 'paused'], true)) {
                throw new RuntimeException('Game cannot be restarted from its current status');
            }

            $firstSet = self::fetchQuestionSetByRound($pdo, $gameId, 1);
            if (!$firstSet) {
                throw new RuntimeException('Game has no rounds/questions to play');
            }

            $pdo->prepare('UPDATE teams SET score = 0 WHERE game_id = ?')->execute([$gameId]);

            $pdo->prepare(
                'UPDATE game_answers ga
                 JOIN game_questions gq ON gq.id = ga.game_question_id
                 JOIN game_question_sets gs ON gs.id = gq.game_set_id
                 SET ga.revealed = 0
                 WHERE gs.game_id = ?'
            )->execute([$gameId]);

            $pdo->prepare('DELETE FROM round_results WHERE game_id = ?')->execute([$gameId]);
            $pdo->prepare('DELETE FROM finale_answers WHERE game_id = ?')->execute([$gameId]);

            $exists = $pdo->prepare('SELECT 1 FROM game_state WHERE game_id = ?');
            $exists->execute([$gameId]);
            if ($exists->fetch()) {
                $pdo->prepare(
                    'UPDATE game_state SET phase = "lobby", current_game_set_id = ?, question_revealed = 0,
                     active_team = NULL, starting_team = NULL, strikes = 0, steal_in_progress = 0,
                     steal_result = "none", round_pot = 0,
                     finale_timer_status = "idle", finale_duration = 15, finale_started_at = NULL,
                     finale_elapsed_before = 0, finale_player = 1, finale_question_index = 0
                     WHERE game_id = ?'
                )->execute([$firstSet['id'], $gameId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO game_state (game_id, phase, current_game_set_id, question_revealed, strikes, steal_in_progress, round_pot)
                     VALUES (?, "lobby", ?, 0, 0, 0, 0)'
                )->execute([$gameId, $firstSet['id']]);
            }

            $wasLive = ($game['status'] === 'live');
            $pdo->prepare('UPDATE games SET status = "in_progress", finished_at = NULL WHERE id = ?')->execute([$gameId]);
            if ($wasLive) {
                $pdo->prepare('UPDATE active_game SET game_id = ? WHERE id = 1')->execute([$gameId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------
    // Fetch helpers
    // ---------------------------------------------------------------

    private static function fetchGame(PDO $pdo, int $gameId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM games WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function fetchGameState(PDO $pdo, int $gameId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM game_state WHERE game_id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function assertPhase(?array $state, array $allowedPhases): void
    {
        if (!$state || !in_array($state['phase'], $allowedPhases, true)) {
            $phase = $state['phase'] ?? 'none';
            throw new RuntimeException("Action not allowed in phase '{$phase}'");
        }
    }

    /** Defense in depth: the cockpit UI already withholds team-select/reveal/strike controls
     *  until the presenter has revealed the question, but the server enforces it too. */
    private static function assertQuestionRevealed(?array $state): void
    {
        if (!$state || (int) $state['question_revealed'] === 0) {
            throw new RuntimeException('Question has not been revealed yet');
        }
    }

    private static function fetchTeams(PDO $pdo, int $gameId): array
    {
        $stmt = $pdo->prepare('SELECT color, name, score FROM teams WHERE game_id = ?');
        $stmt->execute([$gameId]);
        $out = ['blue' => ['score' => 0, 'name' => null], 'red' => ['score' => 0, 'name' => null]];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['color']] = ['score' => (int) $row['score'], 'name' => $row['name']];
        }
        return $out;
    }

    private static function fetchQuestionSet(PDO $pdo, int $gameSetId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM game_question_sets WHERE id = ?');
        $stmt->execute([$gameSetId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function fetchQuestionSetByRound(PDO $pdo, int $gameId, int $roundNumber): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM game_question_sets WHERE game_id = ? AND round_number = ?');
        $stmt->execute([$gameId, $roundNumber]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function fetchQuestionForSet(PDO $pdo, int $gameSetId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM game_questions WHERE game_set_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1');
        $stmt->execute([$gameSetId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function fetchAnswers(PDO $pdo, int $gameQuestionId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM game_answers WHERE game_question_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$gameQuestionId]);
        return $stmt->fetchAll();
    }

    private static function fetchAnswerForUpdate(PDO $pdo, int $answerId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM game_answers WHERE id = ? FOR UPDATE');
        $stmt->execute([$answerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function currentRevealedCount(PDO $pdo, array $state): int
    {
        if (!$state['current_game_set_id']) {
            return 0;
        }
        $question = self::fetchQuestionForSet($pdo, (int) $state['current_game_set_id']);
        if (!$question) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM game_answers WHERE game_question_id = ? AND revealed = 1');
        $stmt->execute([$question['id']]);
        return (int) $stmt->fetch()['c'];
    }
}

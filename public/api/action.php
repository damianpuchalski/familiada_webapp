<?php

declare(strict_types=1);

// Validated, authenticated game actions. Spec §3.2, §4. Every mutation goes
// through src/game/GameActions.php — never trust client-supplied scores/state.
// No "pass_turn" action exists (Spec §4.3 / §10 change 1).

require_once __DIR__ . '/_bootstrap.php';

use Familiada\Game\GameActions;

json_guard(function (): void {
    auth_require_api();

    $body = json_body();
    $action = (string) ($body['action'] ?? '');
    $gameId = v_int($body['game_id'] ?? null, 1);

    if ($gameId === null) {
        json_error('Missing or invalid game_id', 400);
    }

    $pdo = familiada_db();

    switch ($action) {
        case 'reveal':
            $answerId = v_int($body['answer_id'] ?? null, 1);
            if ($answerId === null) {
                json_error('Missing or invalid answer_id', 400);
            }
            GameActions::reveal($pdo, $gameId, $answerId);
            break;

        case 'strike':
            GameActions::strike($pdo, $gameId);
            break;

        case 'finish_round':
            GameActions::finishRound($pdo, $gameId);
            break;

        case 'end_game':
            // Spec §4.4: free_rounds only — "when the sets run out (or the host ends the
            // game), the higher score wins." Classic mode ends only automatically at >=300.
            GameActions::endGameByHost($pdo, $gameId);
            break;

        case 'set_team':
            $team = v_team($body['team'] ?? null);
            if ($team === null) {
                json_error('Missing or invalid team', 400);
            }
            GameActions::setTeam($pdo, $gameId, $team);
            break;

        case 'set_live':
            GameActions::setLive($pdo, $gameId);
            break;

        case 'restart_game':
            GameActions::restartGame($pdo, $gameId);
            break;

        default:
            json_error("Unknown action '{$action}'", 400);
    }

    json_ok(GameActions::getBoardState($pdo, $gameId));
});

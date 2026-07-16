<?php

declare(strict_types=1);

// Public poll endpoint (Plansza is unauthenticated — spec §3.2, §7). Prezenter
// polls the same endpoint while authenticated; auth is not required to READ
// state, only to mutate it (action.php).

require_once __DIR__ . '/_bootstrap.php';

use Familiada\Game\GameActions;

json_guard(function (): void {
    $pdo = familiada_db();

    $gameId = isset($_GET['game_id']) ? v_int($_GET['game_id'], 1) : null;

    if ($gameId === null) {
        $row = $pdo->query('SELECT game_id FROM active_game WHERE id = 1')->fetch();
        $gameId = $row['game_id'] ?? null;
        if ($gameId === null) {
            json_ok(['game' => null, 'now' => gmdate('Y-m-d\TH:i:s\Z')]);
        }
        $gameId = (int) $gameId;
    }

    $state = GameActions::getBoardState($pdo, $gameId);
    json_ok($state);
});

<?php

declare(strict_types=1);

// Games CRUD for Administrator > Gry + the game editor. Auth required (cockpit-only).
// Lifecycle mutations (set_live/restart) live in action.php; this endpoint only
// covers content authoring (create/edit/list/get/delete a draft) — Spec §5.4, §6.1.

require_once __DIR__ . '/_bootstrap.php';

use Familiada\Game\GameContent;

json_guard(function (): void {
    auth_require_api();
    $pdo = familiada_db();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
        if ($action === 'list') {
            json_ok(['games' => GameContent::listGames($pdo)]);
        }
        if ($action === 'get') {
            $id = v_int($_GET['id'] ?? null, 1);
            if ($id === null) {
                json_error('Missing or invalid id', 400);
            }
            $game = GameContent::getGame($pdo, $id);
            if (!$game) {
                json_error('Game not found', 404);
            }
            json_ok(['game' => $game]);
        }
        json_error('Unknown action', 400);
    }

    // POST
    $body = json_body();
    $action = (string) ($body['action'] ?? '');

    if ($action === 'save') {
        $id = isset($body['id']) ? v_int($body['id'], 1) : null;
        $name = v_string($body['name'] ?? '', 255);
        if ($name === null) {
            json_error('Nazwa gry jest wymagana.', 400);
        }
        $gameId = GameContent::saveGame($pdo, $id, $body);
        json_ok(['id' => $gameId, 'game' => GameContent::getGame($pdo, $gameId)]);
    }

    if ($action === 'delete') {
        $id = v_int($body['id'] ?? null, 1);
        if ($id === null) {
            json_error('Missing or invalid id', 400);
        }
        GameContent::deleteGame($pdo, $id);
        json_ok([]);
    }

    if ($action === 'import_library_set') {
        $id = v_int($body['id'] ?? null, 1);
        $setId = v_int($body['library_set_id'] ?? null, 1);
        if ($id === null || $setId === null) {
            json_error('Missing id or library_set_id', 400);
        }
        GameContent::importLibrarySet($pdo, $id, $setId);
        json_ok(['game' => GameContent::getGame($pdo, $id)]);
    }

    json_error("Unknown action '{$action}'", 400);
});

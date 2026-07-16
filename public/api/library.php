<?php

declare(strict_types=1);

// Content-library CRUD (lib_question_sets/questions/answers). Auth required.
// Spec §6, build-order step 1. Reusable, freely editable; never affects games
// already started (frozen game_* copies are independent — see GameContent).

require_once __DIR__ . '/_bootstrap.php';

use Familiada\Game\GameContent;

json_guard(function (): void {
    auth_require_api();
    $pdo = familiada_db();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
        if ($action === 'list') {
            json_ok(['sets' => GameContent::listLibrarySets($pdo)]);
        }
        if ($action === 'get') {
            $id = v_int($_GET['id'] ?? null, 1);
            if ($id === null) {
                json_error('Missing or invalid id', 400);
            }
            $set = GameContent::getLibrarySet($pdo, $id);
            if (!$set) {
                json_error('Set not found', 404);
            }
            json_ok(['set' => $set]);
        }
        json_error('Unknown action', 400);
    }

    $body = json_body();
    $action = (string) ($body['action'] ?? '');

    if ($action === 'save') {
        $id = isset($body['id']) ? v_int($body['id'], 1) : null;
        $name = v_string($body['name'] ?? '', 255);
        if ($name === null) {
            json_error('Nazwa zestawu jest wymagana.', 400);
        }
        $setId = GameContent::saveLibrarySet($pdo, $id, $name, $body['questions'] ?? []);
        json_ok(['id' => $setId, 'set' => GameContent::getLibrarySet($pdo, $setId)]);
    }

    if ($action === 'delete') {
        $id = v_int($body['id'] ?? null, 1);
        if ($id === null) {
            json_error('Missing or invalid id', 400);
        }
        GameContent::deleteLibrarySet($pdo, $id);
        json_ok([]);
    }

    json_error("Unknown action '{$action}'", 400);
});

<?php

declare(strict_types=1);

// Sound-pack management for Administrator > Dźwięki. Auth required to
// list/upload/delete. Actual audio files are served as plain static files
// under /assets/sounds/ (readable by board+cockpit without auth — needed for
// playback), see public/board and public/admin JS.

require_once __DIR__ . '/_bootstrap.php';

use Familiada\Game\SoundLibrary;

json_guard(function (): void {
    auth_require_api();
    $pdo = familiada_db();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'status';
        if ($action === 'packs') {
            json_ok(['packs' => SoundLibrary::listPacks($pdo)]);
        }
        if ($action === 'status') {
            $setId = v_int($_GET['sound_set_id'] ?? null, 1);
            if ($setId === null) {
                json_error('Missing or invalid sound_set_id', 400);
            }
            json_ok(['cues' => SoundLibrary::packStatus($pdo, $setId)]);
        }
        json_error('Unknown action', 400);
    }

    // POST — either multipart upload, or JSON {action: 'delete', ...}
    $action = $_POST['action'] ?? (json_body()['action'] ?? '');

    if ($action === 'upload') {
        $setId = v_int($_POST['sound_set_id'] ?? null, 1);
        $cue = v_enum($_POST['cue'] ?? null, SoundLibrary::CUES);
        if ($setId === null || $cue === null) {
            json_error('Missing or invalid sound_set_id/cue', 400);
        }
        $uploadErr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!isset($_FILES['file']) || $uploadErr !== UPLOAD_ERR_OK) {
            if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
                json_error('File too large for the server (exceeds PHP upload_max_filesize).', 400);
            }
            json_error('No file uploaded', 400);
        }
        $file = $_FILES['file'];
        if (!is_uploaded_file($file['tmp_name'])) {
            json_error('Invalid upload', 400);
        }
        $path = SoundLibrary::upload($pdo, $setId, $cue, $file['tmp_name'], $file['name']);
        json_ok(['file_path' => $path]);
    }

    $body = json_body();
    $action = (string) ($action ?: ($body['action'] ?? ''));

    if ($action === 'delete') {
        $setId = v_int($body['sound_set_id'] ?? null, 1);
        $cue = v_enum($body['cue'] ?? null, SoundLibrary::CUES);
        if ($setId === null || $cue === null) {
            json_error('Missing or invalid sound_set_id/cue', 400);
        }
        SoundLibrary::delete($pdo, $setId, $cue);
        json_ok([]);
    }

    json_error("Unknown action '{$action}'", 400);
});

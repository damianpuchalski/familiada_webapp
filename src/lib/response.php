<?php

declare(strict_types=1);

/** Emit a JSON success response and stop. */
function json_ok(array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Emit a JSON error response and stop. Never leak internals in $message. */
function json_error(string $message, int $status = 400, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Decode the raw JSON request body into an assoc array (empty array if none/invalid). */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Wraps a callable. RuntimeException is our "expected, user-facing validation
 * failure" signal (illegal move, locked selector, wrong status, etc.) — its
 * message is safe to show the host as-is and is returned as 400. Note:
 * PDOException also extends RuntimeException in PHP, so it must be caught
 * *first* and masked — a DB failure is never a safe-to-expose message.
 * Anything else is unexpected and is masked as a generic 500 unless debug is on.
 */
function json_guard(callable $fn): void
{
    try {
        $fn();
    } catch (PDOException $e) {
        $cfg = function_exists('familiada_config') ? familiada_config() : [];
        $extra = !empty($cfg['debug']) ? ['detail' => $e->getMessage()] : [];
        json_error('Database error', 500, $extra);
    } catch (RuntimeException $e) {
        json_error($e->getMessage(), 400);
    } catch (Throwable $e) {
        $cfg = function_exists('familiada_config') ? familiada_config() : [];
        $extra = !empty($cfg['debug']) ? ['detail' => $e->getMessage()] : [];
        json_error('Internal server error', 500, $extra);
    }
}

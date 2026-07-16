<?php
/**
 * Loads config.php (gitignored) and returns the config array.
 * Falls back to config.example.php only for local static-analysis convenience;
 * a real deployment MUST have its own config.php (see PROJECT_SPEC.md §9).
 */

declare(strict_types=1);

function familiada_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $root = dirname(__DIR__, 2);
    $path = $root . '/config.php';
    if (!is_file($path)) {
        $example = $root . '/config.example.php';
        if (is_file($example)) {
            // Local dev convenience only — never rely on this in production.
            $config = require $example;
            return $config;
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing config.php. Copy config.example.php to config.php and fill it in.']);
        exit;
    }

    $config = require $path;
    return $config;
}

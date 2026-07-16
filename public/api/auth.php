<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

json_guard(function (): void {
    $action = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['action'] ?? 'check') : (json_body()['action'] ?? '');

    if ($action === 'check') {
        json_ok(['authed' => auth_is_logged_in()]);
    }

    if ($action === 'login') {
        $body = json_body();
        $password = (string) ($body['password'] ?? '');
        if ($password === '') {
            json_error('Nieprawidłowe hasło. Spróbuj ponownie.', 401);
        }
        if (auth_is_throttled()) {
            // Generic message — don't disclose the lockout window to an attacker.
            json_error('Zbyt wiele prób logowania. Spróbuj ponownie za chwilę.', 429);
        }
        if (auth_attempt($password)) {
            json_ok(['authed' => true]);
        }
        json_error('Nieprawidłowe hasło. Spróbuj ponownie.', 401);
    }

    if ($action === 'logout') {
        auth_logout();
        json_ok(['authed' => false]);
    }

    json_error('Unknown action', 400);
});

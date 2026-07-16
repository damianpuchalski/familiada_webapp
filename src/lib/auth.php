<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Auth: one shared host password (bcrypt hash in config.php), PHP session.
 * Spec §7 / PROJECT_SPEC.md. Never reveal whether a wrong guess was "close".
 */

// Brute-force throttle for the single shared cockpit password: after
// AUTH_MAX_FAILURES consecutive wrong guesses, further attempts are rejected for
// AUTH_LOCKOUT_SECONDS (session-based — sufficient for one shared host, Spec §7).
const AUTH_MAX_FAILURES = 5;
const AUTH_LOCKOUT_SECONDS = 60;

/**
 * True while the current session is locked out after too many failed logins.
 * Once the window has elapsed the counters are cleared so the next attempt is
 * allowed again. Callers must not leak the remaining time to the client.
 */
function auth_is_throttled(): bool
{
    auth_start_session();
    $fails = (int) ($_SESSION['auth_fail_count'] ?? 0);
    if ($fails < AUTH_MAX_FAILURES) {
        return false;
    }
    $last = (int) ($_SESSION['auth_last_fail'] ?? 0);
    if ((time() - $last) >= AUTH_LOCKOUT_SECONDS) {
        // Lockout window elapsed — reset and let the user try again.
        unset($_SESSION['auth_fail_count'], $_SESSION['auth_last_fail']);
        return false;
    }
    return true;
}

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = familiada_config();
    $lifetime = (int) ($cfg['session_lifetime'] ?? 0);

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => true, // HTTPS-only deployment — never send the session cookie over plain HTTP.
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('familiada_session');
    session_start();

    // Enforce server-side session_lifetime independent of the cookie (cookie can be
    // kept alive by a client; this is the authoritative expiry check).
    if ($lifetime > 0) {
        $now = time();
        if (isset($_SESSION['auth_last_seen']) && ($now - $_SESSION['auth_last_seen']) > $lifetime) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['auth_last_seen'] = $now;
    }
}

function auth_is_logged_in(): bool
{
    auth_start_session();
    return !empty($_SESSION['authed']);
}

/** Verify the shared password; sets the session on success. Returns true/false. */
function auth_attempt(string $password): bool
{
    auth_start_session();
    // Refuse to even check the password while locked out (defense in depth; the
    // API endpoint also guards on auth_is_throttled()).
    if (auth_is_throttled()) {
        return false;
    }
    $cfg = familiada_config();
    $hash = (string) ($cfg['auth_password_hash'] ?? '');
    if ($hash === '' || str_contains($hash, 'REPLACE_ME')) {
        // Misconfigured server — fail closed, but don't leak why to the client.
        return false;
    }
    $valid = password_verify($password, $hash);
    if ($valid) {
        $_SESSION['authed'] = true;
        $_SESSION['auth_last_seen'] = time();
        unset($_SESSION['auth_fail_count'], $_SESSION['auth_last_fail']);
        session_regenerate_id(true);
    } else {
        $_SESSION['auth_fail_count'] = (int) ($_SESSION['auth_fail_count'] ?? 0) + 1;
        $_SESSION['auth_last_fail'] = time();
    }
    return $valid;
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Route guard for admin pages (not API endpoints). Redirects to Logowanie,
 * preserving which screen was requested via ?target=.
 */
function auth_require_web(string $target): void
{
    if (!auth_is_logged_in()) {
        $t = urlencode($target);
        header("Location: login.php?target={$t}");
        exit;
    }
}

/** Route guard for JSON API endpoints that require auth. */
function auth_require_api(): void
{
    if (!auth_is_logged_in()) {
        json_error('Nie zalogowano.', 401);
    }
}

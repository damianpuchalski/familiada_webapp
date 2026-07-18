<?php
// Copy this file to config.php and fill in your cPanel MySQL credentials.
// config.php is gitignored — never commit real credentials.

return [
    'db' => [
        'host'     => 'localhost',       // usually 'localhost' on cPanel
        'name'     => 'youruser_familiada',
        'user'     => 'youruser_dbuser',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],
    // Board polling interval in milliseconds (front-end reads this).
    'poll_interval_ms' => 1000,

    // --- Host authentication (Logowanie login gate) ---
    // ONE shared host password, no username. Protects Prezenter + Administrator;
    // the public board (Plansza) needs no login.
    // Store a HASH, never the plaintext. Generate one with:
    //   php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
    // then paste the result below. Verify at login with password_verify().
    'auth_password_hash' => '$2y$10$REPLACE_ME_WITH_A_REAL_HASH________________________________',

    // Session lifetime in seconds before the host must log in again (0 = until browser closes).
    'session_lifetime' => 0,

    // Where uploaded/seeded sound files live on disk (per-pack subfolders under here:
    // default/, klasyczny/, retro/, modern/). MUST be under public/ (the web root,
    // per §9) — board/Prezenter play these back as plain <audio src> URLs, so
    // anything outside public/ is unreachable over HTTP. See public/assets/sounds/README.md.
    'sounds_path' => __DIR__ . '/public/assets/sounds',

    // The browser-facing URL prefix that corresponds to 'sounds_path' above.
    // Emitted as an absolute (leading-slash) URL so it resolves the same from
    // /board/ and /admin/ regardless of nesting — never a relative "../" path.
    'sounds_url_base' => '/assets/sounds',

    // Set true only while developing locally.
    'debug' => false,
];

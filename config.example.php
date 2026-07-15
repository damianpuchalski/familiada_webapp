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

    // Base path/URL where uploaded sound files live (per-pack subfolders under here).
    'sounds_path' => __DIR__ . '/assets/sounds',

    // Set true only while developing locally.
    'debug' => false,
];

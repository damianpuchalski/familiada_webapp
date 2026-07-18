<?php
/**
 * Private-code path anchor.
 *
 * The private code — config.php, src/, db/ — lives OUTSIDE the web root on the
 * live server (a sibling of the site docroot) so it can't be fetched over HTTP.
 * That means the public entry points can't reach it with a fixed relative path
 * like `../../src`, because on the server public and private are in different
 * subtrees. Every public entry point requires THIS file first, then loads
 * private code via the FAMILIADA_PRIVATE_DIR constant.
 *
 * Resolution order (first hit wins):
 *   1. FAMILIADA_PRIVATE_DIR from the environment ($_SERVER or getenv) — set on
 *      the live server with e.g.
 *        SetEnv FAMILIADA_PRIVATE_DIR /home/<user>/familiada_private
 *      in public/.htaccess.
 *   2. A gitignored public/private_path.local.php that `return`s the absolute
 *      path as a string. deploy/sync.py writes this automatically when
 *      SERVER_PRIVATE_ABS_PATH is set in deploy.local.env.
 *   3. Fallback: the parent of public/ — correct for local dev, where src/ and
 *      config.php sit directly above public/.
 */

declare(strict_types=1);

if (!defined('FAMILIADA_PRIVATE_DIR')) {
    $dir = (string) ($_SERVER['FAMILIADA_PRIVATE_DIR'] ?? '');

    if ($dir === '') {
        $fromEnv = getenv('FAMILIADA_PRIVATE_DIR');
        $dir = $fromEnv !== false ? (string) $fromEnv : '';
    }

    if ($dir === '') {
        $localPointer = __DIR__ . '/private_path.local.php';
        if (is_file($localPointer)) {
            $dir = (string) (require $localPointer);
        }
    }

    if ($dir === '' || !is_dir($dir)) {
        $dir = dirname(__DIR__); // local dev: repo root, the parent of public/
    }

    define('FAMILIADA_PRIVATE_DIR', rtrim($dir, "/\\"));
}

if (!function_exists('familiada_asset')) {
    /**
     * Versioned asset URL: appends ?v=<file mtime> so browsers refetch changed
     * JS/CSS after a deploy instead of serving a stale cached copy (the same
     * cache-bust SoundLibrary::urlFor() does for cue files). $urlPrefix is the
     * caller's web-relative path to the assets dir — 'assets' for a page at the
     * web root, '../assets' for pages under /admin. The file is always resolved
     * on disk relative to THIS file (public/), so the version reflects the real
     * deployed file regardless of the caller's nesting.
     */
    function familiada_asset(string $urlPrefix, string $rel): string
    {
        $rel = ltrim($rel, '/');
        $url = rtrim($urlPrefix, '/') . '/' . $rel;
        $fsPath = __DIR__ . '/assets/' . $rel;
        if (is_file($fsPath)) {
            $url .= '?v=' . filemtime($fsPath);
        }
        return $url;
    }
}

// Fail loudly and clearly if the resolved directory isn't actually the private
// code root — otherwise a misconfigured server yields an opaque "failed to open
// stream" on the next require.
if (!is_file(FAMILIADA_PRIVATE_DIR . '/src/lib/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Familiada is misconfigured: private code directory not found.\n" .
        "Resolved FAMILIADA_PRIVATE_DIR to: " . FAMILIADA_PRIVATE_DIR . "\n" .
        "Expected to find src/lib/config.php under it. Set FAMILIADA_PRIVATE_DIR " .
        "(public/.htaccess) or public/private_path.local.php to the absolute path " .
        "of the private folder. See docs/DEPLOYMENT.md."
    );
}

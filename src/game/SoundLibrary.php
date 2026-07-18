<?php

declare(strict_types=1);

namespace Familiada\Game;

use PDO;
use RuntimeException;

require_once __DIR__ . '/../lib/config.php';

/**
 * Per-pack/per-cue sound file storage. Spec §5.4, §8, §9.
 *
 * Files MUST live under the web root (public/), not the project root — the
 * board and Prezenter fetch them as plain <audio> URLs, and per spec §9 the
 * docroot is public/. Configured via config.php's 'sounds_path' (disk dir,
 * default public/assets/sounds) and 'sounds_url_base' (the matching URL
 * prefix, default /assets/sounds). `sounds.file_path` stores a path relative
 * to 'sounds_path' (e.g. "klasyczny/correct.wav"); URLs are always emitted as
 * absolute (leading-slash) so they resolve the same from /board/ or /admin/ —
 * never a relative "../assets/..." path, which breaks depending on nesting.
 */
final class SoundLibrary
{
    public const CUES = ['correct', 'strike', 'round_start', 'round_end', 'game_start', 'end_game'];

    /**
     * Max accepted size for a single uploaded cue file, in bytes. Cues are short
     * sound effects, so 8 MB is generous. Server-side is the source of truth; the
     * client mirrors this number for a friendlier pre-upload check (admin.js).
     * Note: PHP's own upload_max_filesize/post_max_size can reject even sooner —
     * the endpoint reports that case separately.
     */
    public const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

    /** Absolute filesystem directory that per-pack sound folders live under. */
    public static function baseDir(): string
    {
        $cfg = familiada_config();
        $path = $cfg['sounds_path'] ?? (dirname(__DIR__, 2) . '/public/assets/sounds');
        return rtrim($path, '/');
    }

    /** Absolute (leading-slash) URL prefix matching baseDir(). */
    public static function urlBase(): string
    {
        $cfg = familiada_config();
        $base = $cfg['sounds_url_base'] ?? '/assets/sounds';
        return '/' . trim($base, '/');
    }

    /** Absolute, browser-usable URL for a path relative to baseDir(). */
    public static function urlFor(string $relativePath): string
    {
        return self::urlBase() . '/' . ltrim($relativePath, '/');
    }

    public static function folderForPackName(string $packName): string
    {
        // Packs are fixed ASCII names (Klasyczny/Retro/Modern); lowercase maps 1:1 to folder names.
        return strtolower($packName);
    }

    public static function listPacks(PDO $pdo): array
    {
        return $pdo->query('SELECT id, name FROM sound_sets ORDER BY id ASC')->fetchAll();
    }

    /** Returns cue => file_path (relative to baseDir()) for a pack's uploaded sounds. */
    public static function listPackFiles(PDO $pdo, int $soundSetId): array
    {
        $stmt = $pdo->prepare('SELECT cue, file_path FROM sounds WHERE sound_set_id = ?');
        $stmt->execute([$soundSetId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['cue']] = $row['file_path'];
        }
        return $out;
    }

    /**
     * Full status for the Dźwięki tab: every cue slot, whether a file exists,
     * and an absolute URL ready to hand to <audio> — either the uploaded file
     * or the default/<cue>.wav fallback.
     */
    public static function packStatus(PDO $pdo, int $soundSetId): array
    {
        $files = self::listPackFiles($pdo, $soundSetId);
        $out = [];
        foreach (self::CUES as $cue) {
            $path = $files[$cue] ?? null;
            $hasFile = $path !== null && is_file(self::baseDir() . '/' . $path);
            $fallbackPath = "default/{$cue}.wav";
            $out[] = [
                'cue'          => $cue,
                'has_file'     => $hasFile,
                'file_path'    => $hasFile ? $path : null,
                'url'          => self::urlFor($hasFile ? $path : $fallbackPath),
                'fallback_url' => self::urlFor($fallbackPath),
            ];
        }
        return $out;
    }

    /**
     * Store an uploaded file for $soundSetId/$cue. $tmpPath is the PHP upload tmp
     * file; caller (the endpoint) is responsible for is_uploaded_file() checks.
     * Returns the baseDir()-relative path (as stored in the DB).
     */
    public static function upload(PDO $pdo, int $soundSetId, string $cue, string $tmpPath, string $originalName): string
    {
        if (!in_array($cue, self::CUES, true)) {
            throw new RuntimeException('Unknown cue');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['wav', 'mp3', 'ogg'], true)) {
            throw new RuntimeException('Unsupported audio format (use wav, mp3 or ogg)');
        }

        $size = @filesize($tmpPath);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Empty or unreadable upload');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            $mb = (int) (self::MAX_UPLOAD_BYTES / (1024 * 1024));
            throw new RuntimeException("File too large (max {$mb} MB)");
        }

        $stmt = $pdo->prepare('SELECT name FROM sound_sets WHERE id = ?');
        $stmt->execute([$soundSetId]);
        $pack = $stmt->fetch();
        if (!$pack) {
            throw new RuntimeException('Unknown sound pack');
        }
        $folder = self::folderForPackName($pack['name']);

        $dir = self::baseDir() . '/' . $folder;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $relativePath = "{$folder}/{$cue}.{$ext}";
        $destPath = self::baseDir() . '/' . $relativePath;

        // Remove any previously stored file for this cue with a different extension.
        foreach (['wav', 'mp3', 'ogg'] as $oldExt) {
            $old = "{$dir}/{$cue}.{$oldExt}";
            if ($oldExt !== $ext && is_file($old)) {
                @unlink($old);
            }
        }

        if (!move_uploaded_file($tmpPath, $destPath) && !copy($tmpPath, $destPath)) {
            throw new RuntimeException('Failed to store uploaded file');
        }

        $pdo->prepare(
            'INSERT INTO sounds (sound_set_id, cue, file_path) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE file_path = VALUES(file_path)'
        )->execute([$soundSetId, $cue, $relativePath]);

        return $relativePath;
    }

    public static function delete(PDO $pdo, int $soundSetId, string $cue): void
    {
        $stmt = $pdo->prepare('SELECT file_path FROM sounds WHERE sound_set_id = ? AND cue = ?');
        $stmt->execute([$soundSetId, $cue]);
        $row = $stmt->fetch();
        if ($row) {
            $full = self::baseDir() . '/' . $row['file_path'];
            if (is_file($full)) {
                @unlink($full);
            }
        }
        $pdo->prepare('DELETE FROM sounds WHERE sound_set_id = ? AND cue = ?')->execute([$soundSetId, $cue]);
    }
}

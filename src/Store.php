<?php
declare(strict_types=1);

/**
 * Lettura/scrittura atomica di file JSON in data/ e log applicativo.
 */
final class Store
{
    private static function path(string $name): string
    {
        // Impedisce path traversal: solo nome file semplice.
        $name = basename($name);
        return DATA_DIR . '/' . $name;
    }

    public static function ensureDataDir(): void
    {
        if (!is_dir(DATA_DIR)) {
            @mkdir(DATA_DIR, 0775, true);
        }
    }

    /** Legge un JSON, restituendo $default se manca o e' corrotto. */
    public static function readJson(string $name, $default = null)
    {
        $file = self::path($name);
        if (!is_file($file)) {
            return $default;
        }
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    }

    /** Scrive un JSON in modo atomico (tmp + rename) con lock. */
    public static function writeJson(string $name, $data): bool
    {
        self::ensureDataDir();
        $file = self::path($name);
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            return false;
        }
        $tmp = $file . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        return rename($tmp, $file);
    }

    /** Aggiunge una riga al log (con timestamp nel fuso configurato). */
    public static function log(string $line): void
    {
        self::ensureDataDir();
        $ts = now_app()->format('Y-m-d H:i:s');
        $entry = '[' . $ts . '] ' . $line . PHP_EOL;
        @file_put_contents(self::path('controller.log'), $entry, FILE_APPEND | LOCK_EX);
    }

    /** Restituisce le ultime $n righe del log (piu' recenti in fondo). */
    public static function tailLog(int $n = 50): array
    {
        $file = self::path('controller.log');
        if (!is_file($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        return array_slice($lines, -$n);
    }
}

<?php

class FileStore {
    /**
     * Read a JSON file with a shared lock. Returns decoded array or null.
     */
    public static function read(string $path): ?array {
        if (!file_exists($path)) return null;
        $fp = fopen($path, 'r');
        if (!$fp) return null;
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($content, true) ?? null;
    }

    /**
     * Atomic read-modify-write with an exclusive lock.
     * $fn receives the current data (or $default if file is new) and must return the new data.
     */
    public static function update(string $path, callable $fn, array $default = []): array {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $fp = fopen($path, 'c+');
        if (!$fp) throw new RuntimeException("Cannot open: $path");

        flock($fp, LOCK_EX);

        $content = stream_get_contents($fp);
        $data = ($content !== '' && $content !== false)
            ? (json_decode($content, true) ?? $default)
            : $default;

        $data = $fn($data);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));

        flock($fp, LOCK_UN);
        fclose($fp);

        return $data;
    }
}

<?php

declare(strict_types=1);

final class JsonStore
{
    public static function read(string $path, mixed $default = null): mixed
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $default;
        }
    }

    public static function write(string $path, mixed $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Kann Verzeichnis nicht anlegen: ' . $dir);
        }
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Kann nicht schreiben: ' . $path);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Kann nicht umbenennen: ' . $path);
        }
    }
}

<?php

declare(strict_types=1);

final class App
{
    public static string $root;
    public static array $config;

    public static function init(): void
    {
        $envRoot = getenv('RSS_MONITOR_ROOT');
        self::$root = (is_string($envRoot) && $envRoot !== '')
            ? rtrim($envRoot, '/')
            : dirname(__DIR__);
        $config = require self::$root . '/config.php';
        $local = self::$root . '/config.local.php';
        if (is_file($local)) {
            $config = array_replace($config, require $local);
        }
        self::$config = $config;
        date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Berlin'));
    }

    public static function cfg(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    public static function path(string $relative): string
    {
        return self::$root . '/' . ltrim($relative, '/');
    }

    public static function ensureDirs(): void
    {
        foreach (['data/state', 'data/items', 'data/runs', 'feeds'] as $dir) {
            $path = self::path($dir);
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new RuntimeException('Kann Verzeichnis nicht anlegen: ' . $path);
            }
        }
    }

    public static function baseUrl(): string
    {
        $configured = rtrim((string) self::cfg('base_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        if (PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
            $scheme = $https ? 'https' : 'http';
            $script = dirname($_SERVER['SCRIPT_NAME'] ?? '');
            $script = str_replace('\\', '/', $script);
            if ($script === '/' || $script === '.') {
                $script = '';
            }
            return $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim($script, '/');
        }
        return '';
    }
}

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

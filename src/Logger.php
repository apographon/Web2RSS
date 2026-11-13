<?php

declare(strict_types=1);

final class Logger
{
    public static ?RunReport $report = null;

    public static function line(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        $path = App::path('data/crawl.log');
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $line);
        }
        self::$report?->log($message);
        self::trim($path);
    }

    private static function trim(string $path): void
    {
        if (!is_file($path) || filesize($path) < 250_000) {
            return;
        }
        $data = file_get_contents($path);
        if ($data === false) {
            return;
        }
        file_put_contents($path, substr($data, -120_000), LOCK_EX);
    }
}

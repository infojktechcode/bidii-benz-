<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application file logger. Never echoes to output; never logs secrets.
 */
final class Log
{
    private static string $dir = '';

    public static function init(string $dir): void
    {
        self::$dir = rtrim($dir, DIRECTORY_SEPARATOR);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$dir === '' || !is_dir(self::$dir)) {
            return;
        }
        $safe = [];
        foreach ($context as $key => $value) {
            $k = (string) $key;
            if (preg_match('/pass|token|secret|key|authorization|cookie/i', $k)) {
                $safe[$k] = '[REDACTED]';
                continue;
            }
            $safe[$k] = is_scalar($value) || $value === null ? $value : gettype($value);
        }
        $line = sprintf(
            "[%s] %s %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $safe === [] ? '' : json_encode($safe, JSON_UNESCAPED_SLASHES)
        );
        @file_put_contents(self::$dir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}

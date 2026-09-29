<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env parser (KEY=VALUE lines, # comments). No external dependency.
 */
final class Env
{
    /**
     * Parse an env file body into an associative array.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $vars = [];
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $value = trim(substr($line, $eq + 1));
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            $vars[$key] = $value;
        }
        return $vars;
    }

    /**
     * Load a file if present; existing real environment variables win.
     *
     * @return array<string, string>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $parsed = self::parse((string) file_get_contents($path));
        $vars = [];
        foreach ($parsed as $key => $value) {
            $current = getenv($key);
            $vars[$key] = $current !== false && $current !== '' ? $current : $value;
        }
        return $vars;
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

/**
 * View rendering with mandatory output escaping.
 */
final class View
{
    /** Base path prefix (e.g. "/bidii-benz/public" in dev, "" at production docroot). */
    private static string $basePath = '';

    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/');
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    /**
     * Build an application-relative URL: url('/login') => '/bidii-benz/public/login'.
     */
    public static function url(string $path): string
    {
        if ($path === '') {
            return self::$basePath . '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return self::$basePath . $path;
    }

    /**
     * Escape for HTML context (the ONLY way output leaves the server).
     */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render a template file with extracted variables.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], ?int $status = null): void
    {
        if ($status !== null) {
            http_response_code($status);
        }
        extract($data, EXTR_SKIP);
        $e = static fn (mixed $v): string => self::e($v);
        $csrfField = static fn (string $form): string => Csrf::field($form);
        require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $template . '.php';
    }

    /**
     * Render a layout wrapping content.
     *
     * @param array<string, mixed> $data
     */
    public static function page(string $template, array $data = [], ?int $status = null): void
    {
        self::render($template, $data, $status);
    }
}

<?php

declare(strict_types=1);

/**
 * Global helper: redirect with exit (PRG pattern).
 * $path is application-relative; the base path is prefixed automatically.
 */
function redirect(string $path, int $status = 302): never
{
    header('Location: ' . \App\Core\View::url($path), true, $status);
    exit;
}

/**
 * Global helper: build an application-relative URL.
 */
function url(string $path): string
{
    return \App\Core\View::url($path);
}

/**
 * Global helper: escape for HTML output.
 */
function e(mixed $value): string
{
    return \App\Core\View::e($value);
}

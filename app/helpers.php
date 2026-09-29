<?php

declare(strict_types=1);

/**
 * Global helper: redirect with exit (PRG pattern).
 */
function redirect(string $path, int $status = 302): never
{
    $base = '';
    header('Location: ' . $base . $path, true, $status);
    exit;
}

/**
 * Global helper: escape for HTML output.
 */
function e(mixed $value): string
{
    return \App\Core\View::e($value);
}

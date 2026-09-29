<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Per-session CSRF token with per-form salts. Timing-safe comparison.
 */
final class Csrf
{
    private const TOKEN_KEY = '__csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::TOKEN_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::TOKEN_KEY, $token);
        }
        return $token;
    }

    /**
     * Hidden input for a form: Csrf::field('booking_create')
     */
    public static function field(string $form): string
    {
        $value = self::value($form);
        return sprintf(
            '<input type="hidden" name="_csrf" value="%s">',
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * Per-form derived token (binds token to a specific form name).
     */
    public static function value(string $form): string
    {
        return hash_hmac('sha256', $form . '|' . self::token(), self::token());
    }

    /**
     * Validate a submitted token for the given form. Returns false on any mismatch.
     */
    public static function validate(string $form, mixed $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }
        return hash_equals(self::value($form), $submitted);
    }

    /**
     * Rotate token after privilege changes (login/logout).
     */
    public static function rotate(): void
    {
        Session::remove(self::TOKEN_KEY);
        self::token();
    }
}

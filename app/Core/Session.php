<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session wrapper with hardened cookie attributes, idle/absolute timeouts,
 * CSRF rotation hook and flash messages.
 *
 * Reads/writes the $_SESSION superglobal so it stays unit-testable in CLI
 * without starting a real PHP session.
 */
final class Session
{
    private const RELATIVE_KEY = '__active_since';
    private const TOUCH_KEY = '__last_touch';

    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        // Explicitly pin the no-store cache policy for every response this
        // session touches, instead of relying on the php.ini default
        // (session.cache_limiter), which a host could silently override.
        session_cache_limiter('nocache');

        session_name((string) $config->get('session.name', 'bidii_sess'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        self::enforceTimeouts($config);
        if (!isset($_SESSION[self::RELATIVE_KEY])) {
            $_SESSION[self::RELATIVE_KEY] = time();
        }
        $_SESSION[self::TOUCH_KEY] = time();
    }

    private static function enforceTimeouts(Config $config): void
    {
        $idle = (int) $config->get('session.idle_timeout', 1800);
        $absolute = (int) $config->get('session.absolute_timeout', 28800);

        $now = time();
        $lastTouch = isset($_SESSION[self::TOUCH_KEY]) ? (int) $_SESSION[self::TOUCH_KEY] : null;
        $activeSince = isset($_SESSION[self::RELATIVE_KEY]) ? (int) $_SESSION[self::RELATIVE_KEY] : null;

        $expired = false;
        if ($lastTouch !== null && ($now - $lastTouch) > $idle) {
            $expired = true;
        }
        if ($activeSince !== null && ($now - $activeSince) > $absolute) {
            $expired = true;
        }

        if ($expired) {
            self::destroy();
            session_start();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Read a value once and remove it from the session.
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    /**
     * Rotate the session ID (call on login and privilege change).
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::RELATIVE_KEY] = time();
        $_SESSION[self::TOUCH_KEY] = time();
    }

    public static function flash(string $type, ?string $message = null): ?string
    {
        if ($message === null) {
            $value = $_SESSION['__flash'][$type] ?? null;
            unset($_SESSION['__flash'][$type]);
            return $value;
        }
        $_SESSION['__flash'][$type] = $message;
        return null;
    }

    /**
     * Fully destroy the session (server-side data + cookie).
     */
    public static function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
            session_destroy();
        }
    }
}

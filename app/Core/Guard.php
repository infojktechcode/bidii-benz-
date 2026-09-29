<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Server-side authorization guard. EVERY route must call one of these.
 * Frontend hiding of links is UX only and never a security control.
 */
final class Guard
{
    public const ROLE_CLIENT = 'client';
    public const ROLE_STAFF = 'staff';
    public const ROLE_OWNER = 'owner';

    /** @var list<string> */
    public const ALL_ROLES = [self::ROLE_CLIENT, self::ROLE_STAFF, self::ROLE_OWNER];

    /**
     * Current session user id, or null when unauthenticated.
     */
    public static function userId(): ?int
    {
        $id = Session::get('user_id');
        return is_int($id) ? $id : null;
    }

    public static function role(): ?string
    {
        $role = Session::get('role');
        return is_string($role) && in_array($role, self::ALL_ROLES, true) ? $role : null;
    }

    public static function check(): bool
    {
        return self::userId() !== null && self::role() !== null;
    }

    public static function hasRole(string ...$roles): bool
    {
        $current = self::role();
        return $current !== null && in_array($current, $roles, true);
    }

    /**
     * Owner outranks staff: owner satisfies staff checks.
     */
    public static function atLeast(string $role): bool
    {
        $current = self::role();
        if ($current === null) {
            return false;
        }
        $rank = [self::ROLE_CLIENT => 1, self::ROLE_STAFF => 2, self::ROLE_OWNER => 3];
        return ($rank[$current] ?? 0) >= ($rank[$role] ?? 99);
    }

    /**
     * Abort with 401 (redirect to login) when not authenticated.
     */
    public static function requireLogin(string $loginPath = '/login'): void
    {
        if (!self::check()) {
            Session::flash('error', 'Please sign in to continue.');
            redirect($loginPath);
        }
    }

    /**
     * Abort with 403 when the session role is not in the allowed set.
     */
    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!self::hasRole(...$roles)) {
            http_response_code(403);
            require __DIR__ . '/../views/errors/403.php';
            exit;
        }
    }

    /**
     * Abort with 403 unless the current role ranks at least $role.
     */
    public static function requireAtLeast(string $role): void
    {
        self::requireLogin();
        if (!self::atLeast($role)) {
            http_response_code(403);
            require __DIR__ . '/../views/errors/403.php';
            exit;
        }
    }
}

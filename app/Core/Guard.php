<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

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
            // Remember where the user was heading (application-relative path).
            $intended = self::intendedPath($_SERVER['REQUEST_URI'] ?? '/');
            if ($intended !== null) {
                Session::set('intended', $intended);
            }
            Session::flash('error', 'Please sign in to continue.');
            redirect($loginPath);
        }
    }

    /**
     * Normalize a request URI into a safe application-relative path for the
     * "intended URL" post-login redirect.
     *
     * Returns null when the path must NOT be remembered — in particular any
     * protocol-relative path ("//host"), which would become an open redirect
     * when the base path is empty (production docroot).
     */
    public static function intendedPath(string $uri): ?string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        // Strip the base path only on an exact boundary so that
        // "/bidii-benz/publicity" is never mistaken for "/bidii-benz/public/...".
        $base = View::basePath();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }
        return $path;
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

    /**
     * Re-check the signed-in account against the database once per request.
     *
     * The session only stores what was true at login time: a suspended,
     * deleted or role-changed account would otherwise keep its privileges
     * until the cookie expired. Returns true when the session was invalidated
     * (the visitor has been signed out).
     */
    public static function revalidateSession(PDO $pdo): bool
    {
        $userId = self::userId();
        if ($userId === null) {
            return false;
        }

        $stmt = $pdo->prepare('SELECT role, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $sessionRole = self::role();

        $stillValid = $user !== false
            && $user['status'] === 'active'
            && $sessionRole !== null
            && $user['role'] === $sessionRole;

        if ($stillValid) {
            return false;
        }

        foreach (['user_id', 'role', 'user_name'] as $key) {
            Session::remove($key);
        }
        Session::flash('error', 'Your session is no longer valid. Please sign in again.');
        return true;
    }
}

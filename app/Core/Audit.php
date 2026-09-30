<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Audit trail writer. Every privileged/state-changing action records who did
 * what, to which entity, from where.
 *
 * The payload is deliberately narrow: user_id, role, action, entity, entity_id,
 * ip_address and a free-text detail. Passwords, tokens, API credentials and
 * payment secrets have no column to land in, and callers must never place them
 * in $detail — the detail is capped before it is written.
 *
 * An audit failure never breaks the business transaction: it is logged and the
 * request continues, exactly like the previous per-controller implementations.
 */
final class Audit
{
    private const MAX_ACTION = 60;
    private const MAX_ROLE = 16;
    private const MAX_ENTITY = 40;
    private const MAX_DETAIL = 500;
    private const MAX_IP = 45;

    private static ?Config $config = null;

    /**
     * @param string $action   e.g. 'booking.create', 'payment.refund'
     * @param string|null $entity e.g. 'bookings', 'payments', 'cars'
     */
    public static function log(
        ?int $userId,
        ?string $role,
        string $action,
        ?string $entity = null,
        ?int $entityId = null,
        ?string $detail = null,
        ?string $ip = null
    ): bool {
        try {
            $pdo = self::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, role, action, entity, entity_id, ip_address, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                self::clip($role, self::MAX_ROLE),
                self::clip($action, self::MAX_ACTION),
                self::clip($entity, self::MAX_ENTITY),
                $entityId,
                self::clip($ip ?? self::clientIp(), self::MAX_IP),
                self::clip($detail, self::MAX_DETAIL),
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Audit write failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Audit on behalf of the current session (user id, role, IP). Returns false
     * when nobody is signed in: an anonymous actor must never be recorded as a
     * real user.
     *
     * @see self::log()
     */
    public static function asCurrentActor(
        string $action,
        ?string $entity = null,
        ?int $entityId = null,
        ?string $detail = null
    ): bool {
        $userId = Guard::userId();
        if ($userId === null) {
            return false;
        }
        return self::log($userId, Guard::role(), $action, $entity, $entityId, $detail);
    }

    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }

    private static function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    private static function pdo(): PDO
    {
        self::$config ??= Config::load(dirname(__DIR__, 2));
        return Database::connect(self::$config);
    }
}

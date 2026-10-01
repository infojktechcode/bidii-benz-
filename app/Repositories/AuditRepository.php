<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Audit trail queries for the staff/owner viewer. Prepared statements only.
 */
final class AuditRepository
{
    public const PER_PAGE = 50;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Newest-first page of audit rows with the actor joined in. Staff/owner
     * accounts have no profile row (users carries only email/phone), so the
     * name comes from clients when there is one; deleted actors still render
     * from the audit row's own role column.
     *
     * @return list<array<string, mixed>>
     */
    public function page(int $page, ?string $action = null, ?int $userId = null): array
    {
        [$where, $params] = $this->filter($action, $userId);
        $offset = (max(1, $page) - 1) * self::PER_PAGE;
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.user_id, a.role, a.action, a.entity, a.entity_id,
                    a.ip_address, a.detail, a.created_at,
                    u.email, c.full_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN clients c ON c.user_id = a.user_id
             {$where}
             ORDER BY a.id DESC
             LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function count(?string $action = null, ?int $userId = null): int
    {
        [$where, $params] = $this->filter($action, $userId);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs a {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    public function distinctActions(): array
    {
        $rows = $this->pdo
            ->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /** @return list<array{id:int, label:string}> */
    public function distinctActors(): array
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT a.user_id AS id, u.email, c.full_name
             FROM audit_logs a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN clients c ON c.user_id = u.id
             ORDER BY u.email'
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['full_name'] ?? '');
            $email = (string) $row['email'];
            $out[] = [
                'id' => (int) $row['id'],
                'label' => $name !== '' ? trim($name . ' (' . $email . ')') : $email,
            ];
        }
        return $out;
    }

    /**
     * Rows created strictly before $createdBefore (Y-m-d H:i:s) — the
     * retention window reported by a --dry-run before anything is deleted.
     */
    public function countBefore(string $createdBefore): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE created_at < ?');
        $stmt->execute([$createdBefore]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete rows created strictly before $createdBefore (Y-m-d H:i:s);
     * returns the number removed. Enforces the owner-approved retention
     * window (AUDIT_RETENTION_MONTHS) via scripts/prune.php.
     */
    public function pruneBefore(string $createdBefore): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM audit_logs WHERE created_at < ?');
        $stmt->execute([$createdBefore]);
        return $stmt->rowCount();
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function filter(?string $action, ?int $userId): array
    {
        $clauses = [];
        $params = [];
        if ($action !== null) {
            $clauses[] = 'a.action = ?';
            $params[] = $action;
        }
        if ($userId !== null) {
            $clauses[] = 'a.user_id = ?';
            $params[] = $userId;
        }
        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}

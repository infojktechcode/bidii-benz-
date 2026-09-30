<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * All user/client/attempt queries. Prepared statements only.
 */
final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE phone = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Login accepts either an email address or a phone number.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdentifier(string $identifier): ?array
    {
        return str_contains($identifier, '@')
            ? $this->findByEmail($identifier)
            : $this->findByPhone($identifier);
    }

    public function idNumberExists(string $idNumber): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM clients WHERE id_number = ? LIMIT 1');
        $stmt->execute([$idNumber]);
        return $stmt->fetchColumn() !== false;
    }

    public function countClients(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    }

    /**
     * Create a client account (users + clients) inside one transaction.
     *
     * @param array{email:string, phone:string, password_hash:string,
     *              full_name:string, id_number:string} $data
     * @throws \PDOException on unique-constraint violation (caller maps it)
     */
    public function createClient(array $data): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (role, email, phone, password_hash, status)
                 VALUES ("client", ?, ?, ?, "active")'
            );
            $stmt->execute([$data['email'], $data['phone'], $data['password_hash']]);
            $userId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO clients (user_id, full_name, id_number, city)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $data['full_name'],
                $data['id_number'],
                $data['city'] ?? null,
            ]);

            $this->pdo->commit();
            return $userId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function touchLastLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $stmt->execute([$userId]);
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $userId]);
    }

    // --- rate limiting -------------------------------------------------------

    public function recordAttempt(string $identifier, string $ip, bool $successful): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, successful)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$identifier, $ip, $successful ? 1 : 0]);
    }

    /**
     * @return list<int> unix timestamps of recent FAILED attempts for this identifier
     */
    public function recentFailures(string $identifier, int $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT UNIX_TIMESTAMP(attempted_at) FROM login_attempts
             WHERE identifier = ? AND successful = 0 AND attempted_at >= FROM_UNIXTIME(?)
             ORDER BY attempted_at ASC'
        );
        $stmt->execute([$identifier, $since]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<int> unix timestamps of recent FAILED attempts from this IP
     */
    public function recentFailuresByIp(string $ip, int $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT UNIX_TIMESTAMP(attempted_at) FROM login_attempts
             WHERE ip_address = ? AND successful = 0 AND attempted_at >= FROM_UNIXTIME(?)
             ORDER BY attempted_at ASC'
        );
        $stmt->execute([$ip, $since]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function clearFailures(string $identifier): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE identifier = ? AND successful = 0'
        );
        $stmt->execute([$identifier]);
    }
}

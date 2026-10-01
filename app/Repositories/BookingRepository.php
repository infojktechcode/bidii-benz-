<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Booking queries. Prepared statements only.
 */
final class BookingRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, c.registration_plate, c.model, c.make, c.daily_price,
                    cl.full_name, cl.id_number, u.email, u.phone
             FROM bookings b
             JOIN cars c ON c.id = b.car_id
             JOIN clients cl ON cl.id = b.client_id
             JOIN users u ON u.id = cl.user_id
             WHERE b.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByRef(string $ref): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, c.registration_plate, c.model, c.make, c.daily_price,
                    cl.full_name, cl.id_number, u.email, u.phone
             FROM bookings b
             JOIN cars c ON c.id = b.car_id
             JOIN clients cl ON cl.id = b.client_id
             JOIN users u ON u.id = cl.user_id
             WHERE b.booking_ref = ? LIMIT 1'
        );
        $stmt->execute([$ref]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Bridge between the two identities: a session knows `users.id`, while a
     * booking is keyed by `clients.id`. Client-facing authorization compares
     * against `bookings.client_id`, so it resolves through here — a booking
     * can never be matched against the wrong table's id.
     */
    public function clientIdForUser(int $userId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM clients WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return list<array<string, mixed>> */
    public function findByClient(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, c.registration_plate, c.model, c.make, c.image_path
             FROM bookings b
             JOIN cars c ON c.id = b.car_id
             WHERE b.client_id = ?
             ORDER BY b.created_at DESC'
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function findAll(?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT b.*, c.registration_plate, c.model, c.make,
                       cl.full_name, u.email, u.phone
                FROM bookings b
                JOIN cars c ON c.id = b.car_id
                JOIN clients cl ON cl.id = b.client_id
                JOIN users u ON u.id = cl.user_id';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE b.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY b.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countAll(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM bookings';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> status => count (only statuses present) */
    public function countByStatus(): array
    {
        $rows = $this->pdo->query(
            'SELECT status, COUNT(*) AS c FROM bookings GROUP BY status'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Money still owed across every non-cancelled booking.
     *
     * Both figures are aggregated independently: MariaDB 10.4 hoists a scalar
     * subquery that references the outer aggregate's rows, so the previous
     * `SUM(total) - (correlated subquery)` shape subtracted only ONE booking's
     * confirmed payments. Confirmed payments on cancelled bookings are
     * excluded, mirroring the report-level balance semantics.
     */
    public function outstandingBalance(): float
    {
        $sql = 'SELECT COALESCE((
                    SELECT SUM(total_amount) FROM bookings WHERE status <> "cancelled"
                ), 0)
                - COALESCE((
                    SELECT SUM(p.amount)
                    FROM payments p
                    JOIN bookings b ON b.id = p.booking_id
                    WHERE p.status = "confirmed" AND b.status <> "cancelled"
                ), 0)';
        return round((float) $this->pdo->query($sql)->fetchColumn(), 2);
    }

    /**
     * Create booking header + booking_days rows in ONE transaction.
     * Returns booking_id on success, throws on conflict (duplicate day).
     *
     * @param array{
     *   client_id: int,
     *   car_id: int,
     *   pickup_date: string,
     *   return_date: string,
     *   daily_rate: float,
     *   total_amount: float,
     *   pickup_location?: string,
     *   notes?: string,
     *   created_by?: int
     * } $data
     */
    public function createWithDays(array $data): int
    {
        $this->pdo->beginTransaction();
        try {
            $ref = 'BB-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

            $stmt = $this->pdo->prepare(
                'INSERT INTO bookings (booking_ref, client_id, car_id, pickup_date, return_date,
                                       daily_rate, total_amount, status, pickup_location, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "pending_payment", ?, ?, ?)'
            );
            $stmt->execute([
                $ref,
                $data['client_id'],
                $data['car_id'],
                $data['pickup_date'],
                $data['return_date'],
                $data['daily_rate'],
                $data['total_amount'],
                $data['pickup_location'] ?? null,
                $data['notes'] ?? null,
                $data['created_by'] ?? null,
            ]);
            $bookingId = (int) $this->pdo->lastInsertId();

            // Occupy each day inclusive (pickup through return)
            $ins = $this->pdo->prepare('INSERT INTO booking_days (car_id, day, booking_id) VALUES (?, ?, ?)');
            $day = new \DateTimeImmutable($data['pickup_date']);
            $end = new \DateTimeImmutable($data['return_date']);
            while ($day <= $end) {
                $ins->execute([$data['car_id'], $day->format('Y-m-d'), $bookingId]);
                $day = $day->modify('+1 day');
            }

            $this->pdo->commit();
            return $bookingId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Status transition. Cancelling also frees the occupied days, so the header
     * update and the day deletion are applied inside ONE transaction: a failure
     * to free the days must never leave a `cancelled` booking still blocking the
     * vehicle (and must never leave a completed/confirmed status applied while
     * its days are half-deleted).
     *
     * Completing writes the status AND the recorded return time in the same
     * statement, so a booking can never end up `completed` without a return
     * time, or with a return time while still `active`.
     *
     * If a caller already has a transaction open, its own boundary is used so
     * the statements still commit or roll back together with that work.
     *
     * @param string|null $actualReturnAt required-format return time for
     *        status 'completed' (validated by BookingService); defaults to now.
     */
    public function updateStatus(
        int $bookingId,
        string $status,
        ?int $actorId = null,
        ?string $cancelledAt = null,
        ?string $actualReturnAt = null
    ): void {
        $fields = ['status = ?'];
        $params = [$status];
        if ($status === 'cancelled') {
            $fields[] = 'cancelled_at = ?';
            $params[] = $cancelledAt ?? date('Y-m-d H:i:s');
        }
        if ($status === 'completed') {
            $fields[] = 'actual_return_at = ?';
            $params[] = $actualReturnAt ?? date('Y-m-d H:i:s');
        }
        $params[] = $bookingId;

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare('UPDATE bookings SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($params);

            // If cancelled, free the booking_days
            if ($status === 'cancelled') {
                $this->pdo->prepare('DELETE FROM booking_days WHERE booking_id = ?')->execute([$bookingId]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Operational reporting aggregates (staff/owner only).
 *
 * Every aggregate runs as a prepared statement; the date range is bound, never
 * interpolated. The only strings concatenated into SQL are the fixed column
 * names supplied by this class itself.
 *
 * Period semantics:
 *  - bookings/outstanding/by-vehicle/by-client are keyed on bookings.created_at
 *    (when the booking was placed),
 *  - payments are keyed on payments.created_at (when the payment was recorded).
 * Both are stated on the reports screen so the totals are never ambiguous.
 */
final class ReportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param string|null $from inclusive Y-m-d, null = unbounded
     * @param string|null $to inclusive Y-m-d, null = unbounded
     * @return array{
     *   from: ?string,
     *   to: ?string,
     *   bookings: array{count: int, revenue: float, clients: int, vehicles: int},
     *   bookings_by_status: array<string, int>,
     *   payments: array{count: int, total: float},
     *   payments_by_status: array<string, float>,
     *   payments_count_by_status: array<string, int>,
     *   outstanding: float,
     *   outstanding_all: float,
     *   by_vehicle: list<array<string, mixed>>,
     *   by_client: list<array<string, mixed>>
     * }
     */
    public function summary(?string $from, ?string $to): array
    {
        $rangeParams = [];

        // --- Bookings placed in the period ---------------------------------
        $sql = 'SELECT COUNT(*) AS c,
                       COALESCE(SUM(CASE WHEN b.status <> "cancelled" THEN b.total_amount ELSE 0 END), 0) AS revenue,
                       COUNT(DISTINCT b.client_id) AS clients,
                       COUNT(DISTINCT b.car_id) AS vehicles
                FROM bookings b
                WHERE 1 = 1' . $this->range('b.created_at', $from, $to, $rangeParams);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($rangeParams);
        $bookings = $stmt->fetch() ?: ['c' => 0, 'revenue' => 0, 'clients' => 0, 'vehicles' => 0];

        $rangeParams = [];
        $sql = 'SELECT b.status AS status, COUNT(*) AS c
                FROM bookings b
                WHERE 1 = 1' . $this->range('b.created_at', $from, $to, $rangeParams) . '
                GROUP BY b.status';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($rangeParams);
        $bookingsByStatus = [];
        foreach ($stmt->fetchAll() as $row) {
            $bookingsByStatus[(string) $row['status']] = (int) $row['c'];
        }

        // --- Payments recorded in the period --------------------------------
        $rangeParams = [];
        $sql = 'SELECT COUNT(*) AS c,
                       COALESCE(SUM(p.amount), 0) AS total,
                       COALESCE(SUM(CASE WHEN p.status = "confirmed" THEN p.amount ELSE 0 END), 0) AS confirmed,
                       COALESCE(SUM(CASE WHEN p.status = "pending" THEN p.amount ELSE 0 END), 0) AS pending,
                       COALESCE(SUM(CASE WHEN p.status = "failed" THEN p.amount ELSE 0 END), 0) AS failed,
                       COALESCE(SUM(CASE WHEN p.status = "refunded" THEN p.amount ELSE 0 END), 0) AS refunded
                FROM payments p
                WHERE 1 = 1' . $this->range('p.created_at', $from, $to, $rangeParams);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($rangeParams);
        $payments = $stmt->fetch() ?: ['c' => 0, 'total' => 0, 'confirmed' => 0, 'pending' => 0, 'failed' => 0, 'refunded' => 0];

        $rangeParams = [];
        $sql = 'SELECT p.status AS status, COUNT(*) AS c, COALESCE(SUM(p.amount), 0) AS total
                FROM payments p
                WHERE 1 = 1' . $this->range('p.created_at', $from, $to, $rangeParams) . '
                GROUP BY p.status';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($rangeParams);
        $paymentsByStatus = [];
        $paymentsCountByStatus = [];
        foreach ($stmt->fetchAll() as $row) {
            $paymentsByStatus[(string) $row['status']] = (float) $row['total'];
            $paymentsCountByStatus[(string) $row['status']] = (int) $row['c'];
        }

        // --- Outstanding on those bookings ----------------------------------
        $rangeParams = [];
        $sql = 'SELECT COALESCE(SUM(b.total_amount - COALESCE(pay.paid, 0)), 0) AS outstanding
                FROM bookings b
                LEFT JOIN (
                    SELECT booking_id, SUM(amount) AS paid
                    FROM payments
                    WHERE status = "confirmed"
                    GROUP BY booking_id
                ) pay ON pay.booking_id = b.id
                WHERE b.status <> "cancelled"' . $this->range('b.created_at', $from, $to, $rangeParams);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($rangeParams);
        $outstanding = (float) ($stmt->fetchColumn() ?: 0);

        // --- Totals per vehicle ---------------------------------------------
        $rangeParams = [];
        $sql = 'SELECT c.id AS id, c.make AS make, c.model AS model, c.registration_plate AS plate,
                       COUNT(b.id) AS bookings,
                       COALESCE(SUM(CASE WHEN b.status <> "cancelled" THEN b.total_amount ELSE 0 END), 0) AS total,
                       COALESCE(SUM(CASE WHEN b.status <> "cancelled"
                                          THEN b.total_amount - COALESCE(pay.paid, 0) ELSE 0 END), 0) AS outstanding
                FROM bookings b
                JOIN cars c ON c.id = b.car_id
                LEFT JOIN (
                    SELECT booking_id, SUM(amount) AS paid
                    FROM payments
                    WHERE status = "confirmed"
                    GROUP BY booking_id
                ) pay ON pay.booking_id = b.id
                WHERE 1 = 1' . $this->range('b.created_at', $from, $to, $rangeParams) . '
                GROUP BY c.id, c.make, c.model, c.registration_plate
                ORDER BY total DESC, c.id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($rangeParams);
        $byVehicle = $stmt->fetchAll();

        // --- Totals per client ----------------------------------------------
        $rangeParams = [];
        $sql = 'SELECT cl.id AS id, cl.full_name AS full_name,
                       COUNT(b.id) AS bookings,
                       COALESCE(SUM(CASE WHEN b.status <> "cancelled" THEN b.total_amount ELSE 0 END), 0) AS total,
                       COALESCE(SUM(CASE WHEN b.status <> "cancelled"
                                          THEN b.total_amount - COALESCE(pay.paid, 0) ELSE 0 END), 0) AS outstanding
                FROM bookings b
                JOIN clients cl ON cl.id = b.client_id
                LEFT JOIN (
                    SELECT booking_id, SUM(amount) AS paid
                    FROM payments
                    WHERE status = "confirmed"
                    GROUP BY booking_id
                ) pay ON pay.booking_id = b.id
                WHERE 1 = 1' . $this->range('b.created_at', $from, $to, $rangeParams) . '
                GROUP BY cl.id, cl.full_name
                ORDER BY total DESC, cl.id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($rangeParams);
        $byClient = $stmt->fetchAll();

        return [
            'from' => $from,
            'to' => $to,
            'bookings' => [
                'count' => (int) ($bookings['c'] ?? 0),
                'revenue' => (float) ($bookings['revenue'] ?? 0),
                'clients' => (int) ($bookings['clients'] ?? 0),
                'vehicles' => (int) ($bookings['vehicles'] ?? 0),
            ],
            'bookings_by_status' => $bookingsByStatus,
            'payments' => [
                'count' => (int) ($payments['c'] ?? 0),
                'total' => (float) ($payments['total'] ?? 0),
            ],
            'payments_by_status' => $paymentsByStatus,
            'payments_count_by_status' => $paymentsCountByStatus,
            'outstanding' => $outstanding,
            'outstanding_all' => (new BookingRepository($this->pdo))->outstandingBalance(),
            'by_vehicle' => $byVehicle,
            'by_client' => $byClient,
        ];
    }

    /**
     * Append an inclusive date-range predicate for a datetime column.
     *
     * @param list<mixed> $params bound by reference; new values are appended
     */
    private function range(string $column, ?string $from, ?string $to, array &$params): string
    {
        $sql = '';
        if ($from !== null) {
            $sql .= ' AND ' . $column . ' >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $sql .= ' AND ' . $column . ' < ?';
            $next = date('Y-m-d', strtotime($to . ' +1 day'));
            $params[] = $next . ' 00:00:00';
        }
        return $sql;
    }
}

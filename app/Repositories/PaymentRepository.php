<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Payment queries. Prepared statements only.
 */
final class PaymentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, b.booking_ref, b.client_id, b.car_id, b.total_amount,
                    c.registration_plate, c.model, cl.full_name, u.email, u.phone
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN cars c ON c.id = b.car_id
             JOIN clients cl ON cl.id = b.client_id
             JOIN users u ON u.id = cl.user_id
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByBooking(int $bookingId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payments WHERE booking_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Every payment recorded against one booking, newest first.
     * Used by both the staff booking detail and the client's own payment status.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payments WHERE booking_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$bookingId]);
        return $stmt->fetchAll();
    }

    /** True when the booking already has a payment awaiting confirmation. */
    public function hasPendingForBooking(int $bookingId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM payments WHERE booking_id = ? AND status = "pending" LIMIT 1'
        );
        $stmt->execute([$bookingId]);
        return $stmt->fetch() !== false;
    }

    /** @return array<string, mixed>|null */
    public function findByCheckoutRequestId(string $checkoutRequestId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payments WHERE checkout_request_id = ? LIMIT 1'
        );
        $stmt->execute([$checkoutRequestId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByMpesaReceipt(string $receipt): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payments WHERE mpesa_receipt = ? LIMIT 1'
        );
        $stmt->execute([$receipt]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function findByClient(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, b.booking_ref, b.pickup_date, b.return_date,
                    c.registration_plate, c.model
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN cars c ON c.id = b.car_id
             WHERE p.client_id = ?
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function findAll(?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT p.*, b.booking_ref, cl.full_name, u.email, u.phone
                FROM payments p
                JOIN bookings b ON b.id = p.booking_id
                JOIN clients cl ON cl.id = p.client_id
                JOIN users u ON u.id = cl.user_id';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE p.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY p.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countAll(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM payments';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Create a pending payment record.
     * @param array{booking_id:int, client_id:int, amount:float, method:string, mpesa_phone?:string, checkout_request_id?:string, merchant_request_id?:string} $data
     */
    public function createPending(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments (booking_id, client_id, amount, currency, method, status,
                                   mpesa_phone, checkout_request_id, merchant_request_id)
             VALUES (?, ?, ?, "KES", ?, "pending", ?, ?, ?)'
        );
        $stmt->execute([
            $data['booking_id'],
            $data['client_id'],
            $data['amount'],
            $data['method'],
            $data['mpesa_phone'] ?? null,
            $data['checkout_request_id'] ?? null,
            $data['merchant_request_id'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function confirm(int $paymentId, string $mpesaReceipt, string $paidAt, ?int $confirmedBy = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE payments SET status = "confirmed", mpesa_receipt = ?, paid_at = ?, confirmed_by = ?
             WHERE id = ?'
        );
        $stmt->execute([$mpesaReceipt, $paidAt, $confirmedBy, $paymentId]);
    }

    public function markFailed(int $paymentId, string $detail = ''): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE payments SET status = "failed", notes = ? WHERE id = ?'
        );
        $stmt->execute([$detail, $paymentId]);
    }

    /** $refundedBy is the real users.id of the person who approved the refund, or null for a system action. */
    public function refund(int $paymentId, ?int $refundedBy = null, string $notes = ''): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE payments SET status = "refunded", confirmed_by = ?, notes = CONCAT(IFNULL(notes, ""), ?, " ") WHERE id = ?'
        );
        $stmt->execute([$refundedBy, $notes, $paymentId]);
    }

    /** @return float total confirmed payments for a booking */
    public function totalConfirmedForBooking(int $bookingId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = ? AND status = "confirmed"'
        );
        $stmt->execute([$bookingId]);
        return (float) $stmt->fetchColumn();
    }
}
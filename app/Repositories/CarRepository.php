<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Car queries. Prepared statements only.
 */
final class CarRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function findActive(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cars WHERE status = "active" AND deleted_at IS NULL ORDER BY daily_price ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cars WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByPlate(string $plate): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cars WHERE registration_plate = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$plate]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Check if a car is available for the entire date range (inclusive).
     * Uses booking_days PK (car_id, day) for exact overlap detection.
     */
    public function isAvailable(int $carId, string $pickupDate, string $returnDate): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM booking_days
             WHERE car_id = ? AND day BETWEEN ? AND ?
             LIMIT 1'
        );
        $stmt->execute([$carId, $pickupDate, $returnDate]);
        return $stmt->fetch() === false;
    }

    /**
     * Get occupied days for a car in a date range (for calendar display).
     * @return list<string> Y-m-d dates
     */
    public function getOccupiedDays(int $carId, string $start, string $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT day FROM booking_days
             WHERE car_id = ? AND day BETWEEN ? AND ?
             ORDER BY day'
        );
        $stmt->execute([$carId, $start, $end]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * For every car held on $day, the latest return date among the bookings
     * that hold it. Same source of truth as isAvailable()/getOccupiedDays(), so
     * the listing badge can never contradict the booking form: if booking_days
     * has today, the car is booked today.
     *
     * @return array<int, string> car_id => Y-m-d return date (max)
     */
    public function occupiedUntil(string $day): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bd.car_id, MAX(b.return_date) AS until_date
             FROM booking_days bd
             JOIN bookings b ON b.id = bd.booking_id
             WHERE bd.day = ?
             GROUP BY bd.car_id'
        );
        $stmt->execute([$day]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['car_id']] = (string) $row['until_date'];
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cars (make, model, year, body_type, seats, transmission, fuel_type,
                              registration_plate, daily_price, currency, status, image_path, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['make'] ?? 'Mercedes-Benz',
            $data['model'],
            $data['year'] ?? null,
            $data['body_type'] ?? null,
            $data['seats'] ?? 5,
            $data['transmission'] ?? 'automatic',
            $data['fuel_type'] ?? 'petrol',
            $data['registration_plate'],
            $data['daily_price'],
            $data['currency'] ?? 'KES',
            $data['status'] ?? 'active',
            $data['image_path'] ?? null,
            $data['description'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $fields = [];
        $values = [];
        foreach (['make', 'model', 'year', 'body_type', 'seats', 'transmission', 'fuel_type',
                  'registration_plate', 'daily_price', 'currency', 'status', 'image_path', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        if ($fields === []) {
            return;
        }
        $values[] = $id;
        $stmt = $this->pdo->prepare('UPDATE cars SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE cars SET deleted_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    public function findAll(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cars WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM cars WHERE deleted_at IS NULL')->fetchColumn();
    }
}
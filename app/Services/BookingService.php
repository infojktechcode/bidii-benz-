<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BookingRepository;
use App\Repositories\CarRepository;
use App\Repositories\PaymentRepository;
use App\Core\Log;
use PDOException;

/**
 * Booking business logic: availability, creation, status transitions.
 * All DB writes use transactions via repositories.
 */
final class BookingService
{
    public function __construct(
        private BookingRepository $bookings,
        private CarRepository $cars,
        private PaymentRepository $payments
    ) {
    }

    /**
     * Check if a car is available for the full date range.
     */
    public function checkAvailability(int $carId, string $pickupDate, string $returnDate): bool
    {
        return $this->cars->isAvailable($carId, $pickupDate, $returnDate);
    }

    /**
     * Get occupied days for calendar display.
     * @return list<string>
     */
    public function getOccupiedDays(int $carId, string $start, string $end): array
    {
        return $this->cars->getOccupiedDays($carId, $start, $end);
    }

    /**
     * Create a booking for a client.
     *
     * @param array{
     *   client_id: int,
     *   car_id: int,
     *   pickup_date: string,
     *   return_date: string,
     *   pickup_location?: string,
     *   notes?: string,
     *   created_by?: int
     * } $data
     * @return array{ok: bool, booking_id?: int, booking_ref?: string, error?: string}
     */
    public function createBooking(array $data): array
    {
        // Validate dates
        $pickup = $data['pickup_date'];
        $return = $data['return_date'];
        if ($pickup >= $return) {
            return ['ok' => false, 'error' => 'Return date must be after pickup date.'];
        }
        if ($pickup < date('Y-m-d')) {
            return ['ok' => false, 'error' => 'Pickup date cannot be in the past.'];
        }

        // Verify car exists and is active
        $car = $this->cars->findById($data['car_id']);
        if ($car === null) {
            return ['ok' => false, 'error' => 'Vehicle not found.'];
        }
        if ($car['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This vehicle is not available for booking.'];
        }

        // Check availability (will be re-checked inside transaction via PK constraint)
        if (!$this->cars->isAvailable($data['car_id'], $pickup, $return)) {
            return ['ok' => false, 'error' => 'Vehicle is not available for the selected dates.'];
        }

        // Calculate total
        $days = (new \DateTimeImmutable($pickup))->diff(new \DateTimeImmutable($return))->days + 1;
        $dailyRate = (float) $car['daily_price'];
        $total = round($dailyRate * $days, 2);

        try {
            $bookingId = $this->bookings->createWithDays([
                'client_id' => $data['client_id'],
                'car_id' => $data['car_id'],
                'pickup_date' => $pickup,
                'return_date' => $return,
                'daily_rate' => $dailyRate,
                'total_amount' => $total,
                'pickup_location' => $data['pickup_location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'PRIMARY') || str_contains($e->getMessage(), 'Duplicate entry')) {
                return ['ok' => false, 'error' => 'Vehicle was just booked for those dates. Please refresh and try again.'];
            }
            Log::error('Booking creation failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Booking failed. Please try again.'];
        }

        $booking = $this->bookings->findById($bookingId);
        return [
            'ok' => true,
            'booking_id' => $bookingId,
            'booking_ref' => $booking['booking_ref'] ?? null,
        ];
    }

    /**
     * Confirm a pending payment booking (staff/owner).
     */
    public function confirmBooking(int $bookingId, int $actorId): array
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            return ['ok' => false, 'error' => 'Booking not found.'];
        }
        if ($booking['status'] !== 'pending_payment') {
            return ['ok' => false, 'error' => 'Only pending payment bookings can be confirmed.'];
        }
        $this->bookings->updateStatus($bookingId, 'confirmed', $actorId);
        return ['ok' => true];
    }

    /**
     * Start an approved hire (staff/owner): confirmed -> active.
     */
    public function startBooking(int $bookingId, int $actorId): array
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            return ['ok' => false, 'error' => 'Booking not found.'];
        }
        if ($booking['status'] !== 'confirmed') {
            return ['ok' => false, 'error' => 'Only confirmed bookings can be started.'];
        }
        $this->bookings->updateStatus($bookingId, 'active', $actorId);
        return ['ok' => true];
    }

    /**
     * Cancel a booking (client before confirmed, staff/owner anytime except completed).
     */
    public function cancelBooking(int $bookingId, int $actorId, string $actorRole): array
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            return ['ok' => false, 'error' => 'Booking not found.'];
        }

        $allowedStatuses = ['pending_payment', 'confirmed'];
        if ($actorRole === 'owner' || $actorRole === 'staff') {
            $allowedStatuses[] = 'active';
        }
        if (!in_array($booking['status'], $allowedStatuses, true)) {
            return ['ok' => false, 'error' => 'This booking cannot be cancelled.'];
        }

        // Client can only cancel their own. The caller passes the signed-in
        // user id; bookings are keyed by the client row, so resolve it first —
        // comparing a users.id against clients.id would deny every client.
        if ($actorRole === 'client') {
            $clientId = $this->bookings->clientIdForUser($actorId);
            if ($clientId === null || (int) $booking['client_id'] !== $clientId) {
                return ['ok' => false, 'error' => 'Not authorized.'];
            }
        }

        $this->bookings->updateStatus($bookingId, 'cancelled', $actorId);
        return ['ok' => true];
    }

    /**
     * Mark booking as completed (staff/owner) - records the vehicle return.
     *
     * The actual return time is staff-supplied when the office processes the
     * hand-back later than the hand-back itself; it defaults to now. One status
     * write carries both `status = completed` and `actual_return_at`, so the
     * booking can never be half-completed.
     *
     * @param string|null $actualReturnAt 'Y-m-d H:i:s' or 'Y-m-d\TH:i'
     * @return array{ok: bool, error?: string}
     */
    public function completeBooking(int $bookingId, int $actorId, ?string $actualReturnAt = null): array
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            return ['ok' => false, 'error' => 'Booking not found.'];
        }
        if ($booking['status'] !== 'active') {
            return ['ok' => false, 'error' => 'Only active bookings can be completed.'];
        }

        $returnAt = date('Y-m-d H:i:s');
        if ($actualReturnAt !== null && $actualReturnAt !== '') {
            $parsed = $this->parseReturnTime($actualReturnAt);
            if ($parsed === null) {
                return ['ok' => false, 'error' => 'Enter a valid return date and time.'];
            }
            if (substr($parsed, 0, 10) < (string) $booking['pickup_date']) {
                return ['ok' => false, 'error' => 'The return cannot be before the pickup date.'];
            }
            if ($parsed > date('Y-m-d H:i:s', time() + 300)) {
                return ['ok' => false, 'error' => 'The return time cannot be in the future.'];
            }
            $returnAt = $parsed;
        }

        $this->bookings->updateStatus($bookingId, 'completed', $actorId, null, $returnAt);
        return ['ok' => true];
    }

    /**
     * Normalise a submitted return time to 'Y-m-d H:i:s'.
     * The round-trip comparison rejects calendar-overflow values such as
     * 2026-02-31, which createFromFormat() would otherwise silently roll over.
     * Returns null when the value is not a real datetime.
     */
    private function parseReturnTime(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($dt === false || $dt->format($format) !== $value) {
                continue;
            }
            return $dt->format('Y-m-d H:i:s');
        }
        return null;
    }

    /**
     * Get client balance for a booking (total - confirmed payments).
     */
    public function getBookingBalance(int $bookingId): float
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            return 0.0;
        }
        if ($booking['status'] === 'cancelled') {
            return 0.0;
        }
        $paid = $this->payments->totalConfirmedForBooking($bookingId);
        return round((float) $booking['total_amount'] - $paid, 2);
    }
}
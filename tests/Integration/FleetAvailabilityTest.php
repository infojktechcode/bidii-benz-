<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\BookingRepository;
use App\Repositories\CarRepository;
use App\Repositories\PaymentRepository;
use App\Services\BookingService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\CreatesRentalFixture;

/**
 * Fleet availability badge data (Phase 8, GAP-A1): the listing says "Booked
 * until …" exactly when booking_days holds today — the same source the booking
 * form uses to refuse a date, so the badge can never contradict the form.
 *
 * Skips when MySQL is unavailable.
 */
final class FleetAvailabilityTest extends TestCase
{
    use CreatesRentalFixture;

    private static ?PDO $pdo = null;

    private CarRepository $cars;
    private BookingService $bookingService;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = Database::connect(Config::load(dirname(__DIR__, 2)));
        } catch (\Throwable) {
            self::$pdo = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('MySQL not available.');
        }
        $this->createRentalFixture(self::$pdo);
        $this->cars = new CarRepository(self::$pdo);
        $this->bookingService = new BookingService(
            new BookingRepository(self::$pdo),
            $this->cars,
            new PaymentRepository(self::$pdo)
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->destroyRentalFixture(self::$pdo);
        }
    }

    public function testAFreshCarShowsAsFreeAndABookingReportsItsReturnDate(): void
    {
        $pickup = date('Y-m-d', strtotime('+40 days'));
        $return = date('Y-m-d', strtotime('+43 days'));

        self::assertArrayNotHasKey(
            $this->fixtureCarId(),
            $this->cars->occupiedUntil($pickup),
            'a car with no bookings must not appear in the availability map'
        );

        $result = $this->bookingService->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => $pickup,
            'return_date' => $return,
        ]);
        self::assertTrue($result['ok'], $result['error'] ?? '');

        $occupied = $this->cars->occupiedUntil($pickup);
        self::assertSame(
            $return,
            $occupied[$this->fixtureCarId()] ?? null,
            'the badge must report the latest return date of the covering booking'
        );

        // Days outside the booking are untouched.
        $outside = $this->cars->occupiedUntil(date('Y-m-d', strtotime('+50 days')));
        self::assertArrayNotHasKey($this->fixtureCarId(), $outside);

        // A day inside the window agrees with isAvailable(): not available.
        self::assertFalse($this->cars->isAvailable($this->fixtureCarId(), $pickup, $pickup));
    }

    public function testCancellingFreesTheBadge(): void
    {
        $pickup = date('Y-m-d', strtotime('+44 days'));
        $return = date('Y-m-d', strtotime('+45 days'));

        $result = $this->bookingService->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => $pickup,
            'return_date' => $return,
        ]);
        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertArrayHasKey($this->fixtureCarId(), $this->cars->occupiedUntil($pickup));

        $cancel = $this->bookingService->cancelBooking(
            (int) $result['booking_id'],
            $this->fixtureUserId(),
            'client'
        );
        self::assertTrue($cancel['ok'], $cancel['error'] ?? '');
        self::assertArrayNotHasKey(
            $this->fixtureCarId(),
            $this->cars->occupiedUntil($pickup),
            'a cancelled booking must release the availability badge immediately'
        );
    }
}

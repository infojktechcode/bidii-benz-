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
 * Phase 11A (DASH-06) — dashboard KPI sources:
 *
 *   outstanding balance (non-cancelled totals minus confirmed payments),
 *   booking/payment counters, and the "available today" derivation used by
 *   the admin dashboard (active vehicles minus vehicles occupied today).
 *
 * These are the numbers the client and admin dashboards display; they were
 * previously only covered indirectly by the report and return suites.
 *
 * Skips when MySQL is unavailable.
 */
final class DashboardTest extends TestCase
{
    use CreatesRentalFixture;

    private static ?PDO $pdo = null;
    private static string $root = '';

    private BookingRepository $bookings;
    private CarRepository $cars;
    private PaymentRepository $payments;
    private BookingService $service;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        try {
            self::$pdo = Database::connect(Config::load(self::$root));
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
        $this->bookings = new BookingRepository(self::$pdo);
        $this->cars = new CarRepository(self::$pdo);
        $this->payments = new PaymentRepository(self::$pdo);
        $this->service = new BookingService($this->bookings, $this->cars, $this->payments);
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->destroyRentalFixture(self::$pdo);
        }
    }

    /** @return array{int, int} available today, active fleet size */
    private function deriveAvailability(): array
    {
        $occupied = $this->cars->occupiedUntil(date('Y-m-d'));
        $fleet = $this->cars->findActive();
        $available = 0;
        foreach ($fleet as $car) {
            if (!isset($occupied[(int) $car['id']])) {
                $available++;
            }
        }
        return [$available, count($fleet)];
    }

    public function testOutstandingBalanceSubtractsConfirmedPaymentsAndSkipsCancelled(): void
    {
        $before = $this->bookings->outstandingBalance();

        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => date('Y-m-d', strtotime('+10 days')),
            'return_date' => date('Y-m-d', strtotime('+11 days')),
        ]);
        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $bookingId = (int) $result['booking_id'];

        $st = self::$pdo->prepare('SELECT total_amount FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        $total = (float) $st->fetchColumn();

        self::assertEqualsWithDelta($before + $total, $this->bookings->outstandingBalance(), 0.01,
            'a new non-cancelled booking adds its total to the outstanding balance');

        $paymentId = $this->payments->createPending([
            'booking_id' => $bookingId,
            'client_id' => $this->fixtureClientId(),
            'amount' => 1000.00,
            'method' => 'mpesa',
        ]);
        self::assertEqualsWithDelta($before + $total, $this->bookings->outstandingBalance(), 0.01,
            'a pending payment does not reduce the outstanding balance');

        $this->payments->confirm($paymentId, 'DSH' . random_int(100000, 999999), date('Y-m-d H:i:s'));
        self::assertEqualsWithDelta($before + $total - 1000.00, $this->bookings->outstandingBalance(), 0.01,
            'a confirmed payment subtracts from the outstanding balance');

        $this->bookings->updateStatus($bookingId, 'cancelled');
        self::assertEqualsWithDelta($before, $this->bookings->outstandingBalance(), 0.01,
            'a cancelled booking drops out of the outstanding balance entirely');
    }

    public function testKpiCountersTrackBookingAndPaymentState(): void
    {
        $active0 = $this->bookings->countAll('active');
        $pendingBookings0 = $this->bookings->countAll('pending_payment');
        $pendingPayments0 = $this->payments->countAll('pending');
        $total0 = $this->bookings->countAll();

        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => date('Y-m-d', strtotime('+20 days')),
            'return_date' => date('Y-m-d', strtotime('+22 days')),
        ]);
        self::assertTrue($result['ok']);
        $bookingId = (int) $result['booking_id'];

        self::assertSame($pendingBookings0 + 1, $this->bookings->countAll('pending_payment'),
            'a fresh booking shows up in the pending-bookings KPI');
        self::assertSame($total0 + 1, $this->bookings->countAll(),
            'a fresh booking shows up in the total-bookings KPI');
        self::assertSame($active0, $this->bookings->countAll('active'),
            'a fresh booking is not yet an active rental');

        $this->bookings->updateStatus($bookingId, 'active');
        self::assertSame($active0 + 1, $this->bookings->countAll('active'),
            'starting the hire moves it into the active-rentals KPI');
        self::assertSame($pendingBookings0, $this->bookings->countAll('pending_payment'),
            'and out of the pending-bookings KPI');

        $this->payments->createPending([
            'booking_id' => $bookingId,
            'client_id' => $this->fixtureClientId(),
            'amount' => 500.00,
            'method' => 'mpesa',
        ]);
        self::assertSame($pendingPayments0 + 1, $this->payments->countAll('pending'),
            'a new payment shows up in the pending-payments KPI');
    }

    public function testAvailableTodayDerivationReleasesWhenTheBookingIsCancelled(): void
    {
        [$availableBefore, $fleetSize] = $this->deriveAvailability();
        self::assertGreaterThanOrEqual(1, $fleetSize);
        self::assertContains($this->fixtureCarId(), array_map(
            static fn (array $car): int => (int) $car['id'],
            $this->cars->findActive()
        ), 'the fixture vehicle is part of the active fleet');

        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => date('Y-m-d'),
            'return_date' => date('Y-m-d', strtotime('+2 days')),
        ]);
        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        [$availableBusy] = $this->deriveAvailability();
        self::assertSame($availableBefore - 1, $availableBusy,
            'a vehicle booked today leaves the available-today count');

        $this->bookings->updateStatus((int) $result['booking_id'], 'cancelled');
        [$availableAfter] = $this->deriveAvailability();
        self::assertSame($availableBefore, $availableAfter,
            'cancelling returns the vehicle to the available-today count');
    }

    public function testAvailabilityDerivationIgnoresInactiveAndDeletedVehicles(): void
    {
        // The derivation only counts findActive() rows (status active, not
        // soft-deleted): flipping the fixture vehicle to maintenance must
        // shrink the fleet size by exactly one.
        [, $fleetBefore] = $this->deriveAvailability();
        self::$pdo->prepare('UPDATE cars SET status = "maintenance" WHERE id = ?')
            ->execute([$this->fixtureCarId()]);
        [$availableAfter, $fleetAfter] = $this->deriveAvailability();
        self::assertSame($fleetBefore - 1, $fleetAfter);
        self::assertLessThanOrEqual($fleetAfter, $availableAfter);

        self::$pdo->prepare('UPDATE cars SET status = "active" WHERE id = ?')
            ->execute([$this->fixtureCarId()]);
    }
}

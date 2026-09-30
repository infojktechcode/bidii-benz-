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
 * Booking engine against the real database: occupancy, double-booking
 * prevention, cancellation and balance arithmetic.
 * Skips when MySQL is unavailable.
 */
final class BookingFlowTest extends TestCase
{
    use CreatesRentalFixture;

    private static ?PDO $pdo = null;

    private BookingService $service;
    private BookingRepository $bookings;
    private CarRepository $cars;

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
        $this->bookings = new BookingRepository(self::$pdo);
        $this->service = new BookingService(
            $this->bookings,
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

    /** @return array{pickup_date:string, return_date:string} */
    private function futureRange(int $plusStart, int $plusEnd): array
    {
        return [
            'pickup_date' => date('Y-m-d', strtotime("+{$plusStart} days")),
            'return_date' => date('Y-m-d', strtotime("+{$plusEnd} days")),
        ];
    }

    public function testBookingOccupiesEveryDayAndComputesTheTotal(): void
    {
        $range = $this->futureRange(40, 43); // 4 days inclusive

        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$range,
        ]);

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertArrayHasKey('booking_ref', $result);

        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM booking_days WHERE car_id = ? AND booking_id = ?'
        );
        $stmt->execute([$this->fixtureCarId(), $result['booking_id']]);
        self::assertSame(4, (int) $stmt->fetchColumn(), 'one row per occupied day');

        $booking = $this->bookings->findById((int) $result['booking_id']);
        self::assertNotNull($booking);
        self::assertSame('pending_payment', $booking['status']);
        self::assertSame(20000.0, (float) $booking['total_amount'], '4 days x 5000');
        self::assertSame(5000.0, (float) $booking['daily_rate'], 'rate is snapshotted');
        self::assertMatchesRegularExpression('/^BB-\d{8}-[A-Z0-9]{4}$/', (string) $booking['booking_ref']);
    }

    public function testExactSameRangeIsRejectedAsDoubleBooking(): void
    {
        $range = $this->futureRange(50, 52);

        self::assertTrue($this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$range,
        ])['ok']);

        $second = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$range,
        ]);

        self::assertFalse($second['ok'], 'the same car must not be bookable twice');
        self::assertStringContainsString('not available', $second['error'] ?? '');

        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM bookings WHERE car_id = ?');
        $stmt->execute([$this->fixtureCarId()]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'no second booking row may be written');
    }

    public function testPartiallyOverlappingRangeIsRejected(): void
    {
        $range = $this->futureRange(60, 63);

        self::assertTrue($this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$range,
        ])['ok']);

        // Starts two days before the first booking ends -> overlap on day 62.
        $overlap = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => date('Y-m-d', strtotime('+62 days')),
            'return_date' => date('Y-m-d', strtotime('+65 days')),
        ]);

        self::assertFalse($overlap['ok'], 'overlapping ranges must be refused');
    }

    public function testNonOverlappingRangeOnTheSameCarIsAccepted(): void
    {
        $range = $this->futureRange(70, 71);

        self::assertTrue($this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$range,
        ])['ok']);

        $later = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => date('Y-m-d', strtotime('+73 days')),
            'return_date' => date('Y-m-d', strtotime('+74 days')),
        ]);

        self::assertTrue($later['ok'], $later['error'] ?? '');
    }

    public function testAvailabilityMirrorsTheOccupancyTable(): void
    {
        $range = $this->futureRange(80, 81);

        self::assertTrue($this->cars->isAvailable(
            $this->fixtureCarId(),
            $range['pickup_date'],
            $range['return_date']
        ), 'free dates report available');

        self::assertTrue($this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$range,
        ])['ok']);

        self::assertFalse($this->cars->isAvailable(
            $this->fixtureCarId(),
            $range['pickup_date'],
            $range['return_date']
        ), 'booked dates report unavailable');
    }

    public function testCancellingFreesTheOccupiedDays(): void
    {
        $range = $this->futureRange(90, 91);

        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$range,
        ]);
        self::assertTrue($result['ok']);
        $bookingId = (int) $result['booking_id'];

        $cancel = $this->service->cancelBooking(
            $bookingId,
            $this->fixtureUserId(),
            'owner'
        );
        self::assertTrue($cancel['ok'], $cancel['error'] ?? '');

        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM booking_days WHERE booking_id = ?');
        $stmt->execute([$bookingId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'cancelled bookings release their days');

        $booking = $this->bookings->findById($bookingId);
        self::assertSame('cancelled', $booking['status']);
        self::assertNotNull($booking['cancelled_at']);

        self::assertTrue(
            $this->cars->isAvailable($this->fixtureCarId(), $range['pickup_date'], $range['return_date']),
            'the vehicle becomes bookable again'
        );
    }

    public function testPastPickupDateIsRejected(): void
    {
        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => date('Y-m-d', strtotime('-3 days')),
            'return_date' => date('Y-m-d', strtotime('+1 day')),
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('past', $result['error'] ?? '');
    }

    public function testReturnDateMustBeAfterPickupDate(): void
    {
        $day = date('Y-m-d', strtotime('+120 days'));
        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => $day,
            'return_date' => $day,
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('after', $result['error'] ?? '');
    }

    public function testInactiveVehicleCannotBeBooked(): void
    {
        self::$pdo->prepare('UPDATE cars SET status = "maintenance" WHERE id = ?')
            ->execute([$this->fixtureCarId()]);

        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$this->futureRange(130, 131),
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('not available', $result['error'] ?? '');
    }

    public function testBalanceIsTotalMinusConfirmedPayments(): void
    {
        $range = $this->futureRange(140, 141);
        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$range,
        ]);
        self::assertTrue($result['ok']);
        $bookingId = (int) $result['booking_id'];

        self::assertSame(
            10000.0,
            $this->service->getBookingBalance($bookingId),
            'nothing paid yet, the full total is outstanding'
        );

        // A pending payment must not reduce the balance.
        self::$pdo->prepare(
            'INSERT INTO payments (booking_id, client_id, amount, method, status)
             VALUES (?, ?, 10000, "mpesa", "pending")'
        )->execute([$bookingId, $this->fixtureClientId()]);
        self::assertSame(10000.0, $this->service->getBookingBalance($bookingId));

        // Confirming it does.
        self::$pdo->prepare('UPDATE payments SET status = "confirmed", mpesa_receipt = ?, paid_at = NOW() WHERE booking_id = ?')
            ->execute(['TESTBAL' . $bookingId, $bookingId]);
        self::assertSame(0.0, $this->service->getBookingBalance($bookingId));

        // Cancelled bookings never show a balance.
        self::assertTrue($this->service->cancelBooking($bookingId, $this->fixtureUserId(), 'owner')['ok']);
        self::assertSame(0.0, $this->service->getBookingBalance($bookingId));
    }

    public function testBookingLifecycleTransitionsAreEnforced(): void
    {
        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            ...$this->futureRange(150, 151),
        ]);
        self::assertTrue($result['ok']);
        $bookingId = (int) $result['booking_id'];
        $actor = $this->fixtureUserId();

        // Cannot complete something that never started.
        $tooEarly = $this->service->completeBooking($bookingId, $actor);
        self::assertFalse($tooEarly['ok']);

        self::assertTrue($this->service->confirmBooking($bookingId, $actor)['ok']);
        // Confirming twice must fail: the state machine is not idempotent-by-force.
        self::assertFalse($this->service->confirmBooking($bookingId, $actor)['ok']);

        self::assertTrue($this->service->startBooking($bookingId, $actor)['ok']);
        self::assertFalse($this->service->startBooking($bookingId, $actor)['ok'], 'already active');

        self::assertTrue($this->service->completeBooking($bookingId, $actor)['ok']);
        $booking = $this->bookings->findById($bookingId);
        self::assertSame('completed', $booking['status']);
        self::assertNotNull($booking['actual_return_at']);
    }
}

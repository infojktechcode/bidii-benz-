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
 * Phase 6 — operational return and balance workflow (§5):
 *
 *   identify -> start -> record the return -> compute rental/payments/balance
 *   -> complete -> the vehicle is bookable again -> audit trail preserved.
 *
 * Also locks down the completion state machine: only an active booking may be
 * completed, the recorded return time is validated, and a failed completion
 * must never leave a half-written row.
 *
 * Skips when MySQL is unavailable.
 */
final class ReturnWorkflowTest extends TestCase
{
    use CreatesRentalFixture;

    private const LOCK_WAIT_TIMEOUT = 1205;

    private static ?PDO $pdo = null;
    private static string $root = '';

    private BookingService $service;
    private BookingRepository $bookings;
    private CarRepository $cars;
    private ?PDO $locker = null;
    private int $savedLockTimeout = 50;

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
        $this->cars = new CarRepository(self::$pdo);
        $this->bookings = new BookingRepository(self::$pdo);
        $this->service = new BookingService(
            $this->bookings,
            $this->cars,
            new PaymentRepository(self::$pdo)
        );
        $this->savedLockTimeout = (int) self::$pdo
            ->query('SELECT @@SESSION.innodb_lock_wait_timeout')
            ->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->releaseLocker();
        if (self::$pdo === null) {
            return;
        }
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        self::$pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $this->savedLockTimeout);
        $this->destroyRentalFixture(self::$pdo);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @return array{id: int, pickup: string, ret: string, total: float}
     */
    private function createBooking(int $startInDays, int $lengthDays = 1): array
    {
        $pickup = date('Y-m-d', strtotime('+' . $startInDays . ' days'));
        $ret = date('Y-m-d', strtotime('+' . ($startInDays + $lengthDays) . ' days'));

        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => $pickup,
            'return_date' => $ret,
        ]);
        self::assertTrue($result['ok'], $result['error'] ?? '');

        $booking = $this->bookings->findById((int) $result['booking_id']);
        self::assertNotNull($booking);

        return [
            'id' => (int) $result['booking_id'],
            'pickup' => $pickup,
            'ret' => $ret,
            'total' => (float) $booking['total_amount'],
        ];
    }

    /**
     * @return array{id: int, pickup: string, ret: string, total: float}
     */
    private function createActiveBooking(int $startInDays): array
    {
        $booking = $this->createBooking($startInDays);
        self::assertTrue($this->service->confirmBooking($booking['id'], $this->fixtureUserId())['ok']);
        self::assertTrue($this->service->startBooking($booking['id'], $this->fixtureUserId())['ok']);
        return $booking;
    }

    private function statusOf(int $bookingId): string
    {
        $booking = $this->bookings->findById($bookingId);
        self::assertNotNull($booking);
        return (string) $booking['status'];
    }

    /**
     * Move the rental dates into the past. A recorded return time must fall
     * between the pickup date and now, while createBooking() insists on a
     * future pickup — so realistic return times need the row re-dated first.
     */
    private function backdate(int $bookingId, string $pickup, string $return): void
    {
        self::$pdo->prepare('UPDATE bookings SET pickup_date = ?, return_date = ? WHERE id = ?')
            ->execute([$pickup, $return, $bookingId]);
    }

    private function actualReturnOf(int $bookingId): ?string
    {
        $booking = $this->bookings->findById($bookingId);
        self::assertNotNull($booking);
        return $booking['actual_return_at'] === null ? null : (string) $booking['actual_return_at'];
    }

    /** A separate connection, used only to hold a row lock. */
    private function newConnection(): PDO
    {
        $db = Config::load(self::$root)->get('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['name'],
            $db['charset']
        );

        return new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    private function releaseLocker(): void
    {
        if ($this->locker === null) {
            return;
        }
        try {
            if ($this->locker->inTransaction()) {
                $this->locker->rollBack();
            }
        } catch (\Throwable) {
            // The connection is discarded either way.
        }
        $this->locker = null;
    }

    // --- 1-4: record the return --------------------------------------------

    public function testCompletionStoresTheStaffSuppliedReturnTime(): void
    {
        $booking = $this->createActiveBooking(200);
        $this->backdate(
            $booking['id'],
            date('Y-m-d', strtotime('-3 days')),
            date('Y-m-d', strtotime('-2 days'))
        );
        $returnedAt = date('Y-m-d H:i:s', strtotime('-2 days 17:30:00'));

        $result = $this->service->completeBooking($booking['id'], $this->fixtureUserId(), $returnedAt);

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame('completed', $this->statusOf($booking['id']));
        self::assertSame($returnedAt, $this->actualReturnOf($booking['id']));
    }

    public function testCompletionDefaultsToTheCurrentTime(): void
    {
        $booking = $this->createActiveBooking(205);
        $before = date('Y-m-d H:i:s');

        self::assertTrue(
            $this->service->completeBooking($booking['id'], $this->fixtureUserId())['ok']
        );

        $after = date('Y-m-d H:i:s');
        $recorded = (string) $this->actualReturnOf($booking['id']);

        self::assertGreaterThanOrEqual($before, $recorded, 'return time must not be in the past of the action');
        self::assertGreaterThanOrEqual($recorded, $after, 'return time must not be in the future');
    }

    public function testDatetimeLocalFormatIsAccepted(): void
    {
        $booking = $this->createActiveBooking(210);
        $this->backdate(
            $booking['id'],
            date('Y-m-d', strtotime('-3 days')),
            date('Y-m-d', strtotime('-2 days'))
        );
        $submitted = date('Y-m-d\TH:i', strtotime('-2 days 09:15:00'));

        $result = $this->service->completeBooking($booking['id'], $this->fixtureUserId(), $submitted);

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame(
            date('Y-m-d H:i:s', strtotime($submitted)),
            $this->actualReturnOf($booking['id']),
            'a datetime-local value is normalised to Y-m-d H:i:s'
        );
    }

    public function testFutureReturnTimeIsRejectedAndTheBookingStaysActive(): void
    {
        $booking = $this->createActiveBooking(215);
        // The rental must already be under way, otherwise the pickup-date rule
        // would fire first and hide the future-time rule under test.
        $this->backdate(
            $booking['id'],
            date('Y-m-d', strtotime('-3 days')),
            date('Y-m-d', strtotime('-2 days'))
        );

        $result = $this->service->completeBooking(
            $booking['id'],
            $this->fixtureUserId(),
            date('Y-m-d H:i:s', strtotime('+2 days'))
        );

        self::assertFalse($result['ok']);
        self::assertStringContainsString('future', (string) $result['error']);
        self::assertSame('active', $this->statusOf($booking['id']), 'nothing may change on a rejected return');
        self::assertNull($this->actualReturnOf($booking['id']), 'no return time may be written either');
    }

    public function testReturnBeforeThePickupDateIsRejected(): void
    {
        $booking = $this->createActiveBooking(220);
        $this->backdate(
            $booking['id'],
            date('Y-m-d', strtotime('-3 days')),
            date('Y-m-d', strtotime('-2 days'))
        );
        $tooEarly = date('Y-m-d H:i:s', strtotime('-4 days 12:00:00'));

        $result = $this->service->completeBooking($booking['id'], $this->fixtureUserId(), $tooEarly);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('pickup', (string) $result['error']);
        self::assertSame('active', $this->statusOf($booking['id']));
        self::assertNull($this->actualReturnOf($booking['id']));
    }

    public function testCalendarOverflowReturnTimeIsRejected(): void
    {
        $booking = $this->createActiveBooking(225);

        $result = $this->service->completeBooking($booking['id'], $this->fixtureUserId(), '2026-02-31 10:00:00');

        self::assertFalse($result['ok']);
        self::assertSame('active', $this->statusOf($booking['id']));
        self::assertNull($this->actualReturnOf($booking['id']));
    }

    public function testNonDateReturnTimeIsRejected(): void
    {
        $booking = $this->createActiveBooking(230);

        $result = $this->service->completeBooking($booking['id'], $this->fixtureUserId(), 'when they came back');

        self::assertFalse($result['ok']);
        self::assertSame('active', $this->statusOf($booking['id']));
        self::assertNull($this->actualReturnOf($booking['id']));
    }

    // --- 8: completion state machine ---------------------------------------

    public function testOnlyAnActiveBookingCanBeCompleted(): void
    {
        $actor = $this->fixtureUserId();

        $pending = $this->createBooking(240);
        $confirmed = $this->createBooking(245);
        self::assertTrue($this->service->confirmBooking($confirmed['id'], $actor)['ok']);

        $cancelled = $this->createBooking(250);
        self::assertTrue($this->service->cancelBooking($cancelled['id'], $actor, 'owner')['ok']);

        $completed = $this->createActiveBooking(255);
        self::assertTrue($this->service->completeBooking($completed['id'], $actor)['ok']);

        foreach ([$pending, $confirmed, $cancelled, $completed] as $booking) {
            $result = $this->service->completeBooking($booking['id'], $actor);
            self::assertFalse($result['ok'], 'status ' . $this->statusOf($booking['id']) . ' must not complete');
            self::assertStringContainsString('active', (string) $result['error']);
        }

        self::assertSame('pending_payment', $this->statusOf($pending['id']));
        self::assertSame('confirmed', $this->statusOf($confirmed['id']));
        self::assertSame('cancelled', $this->statusOf($cancelled['id']));
        self::assertSame('completed', $this->statusOf($completed['id']));
    }

    public function testCompletedBookingsRejectFurtherTransitions(): void
    {
        $actor = $this->fixtureUserId();
        $booking = $this->createActiveBooking(260);
        self::assertTrue($this->service->completeBooking($booking['id'], $actor)['ok']);

        self::assertFalse($this->service->startBooking($booking['id'], $actor)['ok'], 'start after complete');
        self::assertFalse($this->service->confirmBooking($booking['id'], $actor)['ok'], 'confirm after complete');
        self::assertFalse($this->service->cancelBooking($booking['id'], $actor, 'owner')['ok'], 'cancel after complete');
        self::assertFalse(
            $this->service->completeBooking($booking['id'], $actor)['ok'],
            'complete twice must not rewrite the recorded return time'
        );

        self::assertSame('completed', $this->statusOf($booking['id']));
    }

    public function testCompletionNeverWritesAPartialRow(): void
    {
        $booking = $this->createActiveBooking(265);

        $this->locker = $this->newConnection();
        $this->locker->exec('SET SESSION innodb_lock_wait_timeout = 60');
        $this->locker->beginTransaction();
        $lock = $this->locker->prepare('SELECT id FROM bookings WHERE id = ? FOR UPDATE');
        $lock->execute([$booking['id']]);
        self::assertGreaterThan(0, $lock->rowCount(), 'the lock must actually be held');

        self::$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $thrown = null;
        try {
            $this->service->completeBooking($booking['id'], $this->fixtureUserId());
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            self::$pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $this->savedLockTimeout);
            $this->releaseLocker();
        }

        self::assertNotNull($thrown, 'the write must fail while another transaction holds the row');
        self::assertSame('active', $this->statusOf($booking['id']), 'a failed completion must roll back completely');
        self::assertNull($this->actualReturnOf($booking['id']));
    }

    // --- 5-7: money ---------------------------------------------------------

    public function testCompletionKeepsTheRentalAmountAndTheOutstandingBalance(): void
    {
        $booking = $this->createActiveBooking(270);
        self::assertSame(10000.0, $booking['total'], '2 days x 5000');

        self::$pdo->prepare(
            'INSERT INTO payments (booking_id, client_id, amount, method, status, mpesa_receipt, paid_at)
             VALUES (?, ?, 4000, "mpesa", "confirmed", ?, NOW())'
        )->execute([$booking['id'], $this->fixtureClientId(), 'RETK' . bin2hex(random_bytes(4))]);

        $service = new BookingService($this->bookings, $this->cars, new PaymentRepository(self::$pdo));
        self::assertSame(6000.0, $service->getBookingBalance($booking['id']), 'total - confirmed payments');

        self::assertTrue($service->completeBooking($booking['id'], $this->fixtureUserId())['ok']);

        $row = $this->bookings->findById($booking['id']);
        self::assertNotNull($row);
        self::assertSame(10000.0, (float) $row['total_amount'], 'completion must never rewrite the rental amount');
        self::assertSame('completed', $row['status']);
        self::assertSame(6000.0, $service->getBookingBalance($booking['id']), 'the balance survives completion');
    }

    // --- 9: the vehicle goes back into circulation --------------------------

    public function testTheVehicleIsBookableAgainAfterTheReturn(): void
    {
        $booking = $this->createActiveBooking(300);
        self::assertTrue($this->service->completeBooking($booking['id'], $this->fixtureUserId())['ok']);

        $nextStart = date('Y-m-d', strtotime($booking['ret'] . ' +1 day'));
        $nextEnd = date('Y-m-d', strtotime($booking['ret'] . ' +3 day'));

        self::assertTrue(
            $this->cars->isAvailable($this->fixtureCarId(), $nextStart, $nextEnd),
            'the days after the return must be free'
        );

        $next = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => $nextStart,
            'return_date' => $nextEnd,
        ]);

        self::assertTrue($next['ok'], $next['error'] ?? '');
    }
}

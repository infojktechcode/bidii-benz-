<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\BookingRepository;
use App\Repositories\CarRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\CreatesRentalFixture;

/**
 * Proof of the concurrency contract documented in docs/database-design.md:
 *
 *   "the booking service MUST insert the bookings header and all booking_days
 *    rows inside ONE transaction and roll back on any failure."
 *
 * plus the paired cancellation guarantee: booking_days rows are deleted when a
 * booking is cancelled, so the status change and the deletion must not be able
 * to disagree.
 *
 * Uses a throw-away vehicle/client (see Tests\Support\CreatesRentalFixture).
 * Skips when MySQL is unavailable.
 */
final class BookingConcurrencyTest extends TestCase
{
    use CreatesRentalFixture;

    private const DUPLICATE_ENTRY = 1062;
    private const LOCK_WAIT_TIMEOUT = 1205;

    private static ?PDO $pdo = null;
    private static string $root = '';

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
        $this->bookings = new BookingRepository(self::$pdo);
        $this->cars = new CarRepository(self::$pdo);
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

    /** Book the fixture vehicle from $pickup through $return inclusive. */
    private function occupy(string $pickup, string $return): int
    {
        $days = (new \DateTimeImmutable($pickup))->diff(new \DateTimeImmutable($return))->days + 1;

        return $this->bookings->createWithDays([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => $pickup,
            'return_date' => $return,
            'daily_rate' => 5000.0,
            'total_amount' => 5000.0 * $days,
        ]);
    }

    private function bookingCount(): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM bookings WHERE car_id = ?');
        $stmt->execute([$this->fixtureCarId()]);
        return (int) $stmt->fetchColumn();
    }

    private function dayCount(): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM booking_days WHERE car_id = ?');
        $stmt->execute([$this->fixtureCarId()]);
        return (int) $stmt->fetchColumn();
    }

    private function dayCountFor(int $bookingId): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM booking_days WHERE booking_id = ?');
        $stmt->execute([$bookingId]);
        return (int) $stmt->fetchColumn();
    }

    private function catchFailure(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        self::fail('Expected a database failure but the operation succeeded.');
    }

    private function mysqlError(\Throwable $e): int
    {
        if ($e instanceof \PDOException && isset($e->errorInfo[1])) {
            return (int) $e->errorInfo[1];
        }
        return 0;
    }

    /** A separate connection, used only to hold row locks. */
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

    private function holdLocksOnDays(int $bookingId): PDO
    {
        $this->locker = $this->newConnection();
        $this->locker->exec('SET SESSION innodb_lock_wait_timeout = 60');
        $this->locker->beginTransaction();
        $stmt = $this->locker->prepare(
            'SELECT day FROM booking_days WHERE booking_id = ? FOR UPDATE'
        );
        $stmt->execute([$bookingId]);
        self::assertGreaterThan(
            0,
            $stmt->rowCount(),
            'the test cannot prove atomicity unless it actually holds the day rows'
        );

        return $this->locker;
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

    private function lockWaitTimeout(): int
    {
        $stmt = self::$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout');
        return (int) $stmt->fetchColumn();
    }

    // --- G1: header + days commit or roll back together ---------------------

    public function testSuccessfulCreationPersistsTheHeaderAndEveryDay(): void
    {
        $pickup = date('Y-m-d', strtotime('+120 days'));
        $return = date('Y-m-d', strtotime('+122 days'));

        $before = $this->bookingCount();
        $id = $this->occupy($pickup, $return);

        self::assertSame($before + 1, $this->bookingCount());
        self::assertSame(3, $this->dayCountFor($id), 'all three days are written');

        $booking = $this->bookings->findById($id);
        self::assertNotNull($booking);
        self::assertSame('pending_payment', $booking['status']);
        self::assertSame($pickup, $booking['pickup_date']);
        self::assertSame($return, $booking['return_date']);
        self::assertSame(15000.0, (float) $booking['total_amount']);
    }

    public function testConflictOnTheFirstOccupiedDayLeavesNoHeaderBehind(): void
    {
        $day = date('Y-m-d', strtotime('+130 days'));
        $return = date('Y-m-d', strtotime('+131 days'));
        $this->occupy($day, $return);

        $bookingsBefore = $this->bookingCount();
        $daysBefore = $this->dayCount();
        $later = date('Y-m-d', strtotime('+132 days'));

        // The competitor already owns the very first day of this range, so the
        // failure happens on the first booking_days insert — right after the
        // header was written.
        $failure = $this->catchFailure(fn () => $this->occupy($day, $later));

        self::assertSame(
            self::DUPLICATE_ENTRY,
            $this->mysqlError($failure),
            'the database primary key must be the thing that rejects the second writer'
        );
        self::assertSame(
            $bookingsBefore,
            $this->bookingCount(),
            'the bookings header must be rolled back, otherwise an orphaned booking survives'
        );
        self::assertSame($daysBefore, $this->dayCount());

        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM bookings WHERE car_id = ? AND return_date = ?'
        );
        $stmt->execute([$this->fixtureCarId(), $later]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'no header from the failed attempt');
    }

    public function testConflictOnALaterDayRollsBackTheHeaderAndTheEarlierDays(): void
    {
        // The competing booking occupies the third and fourth days of the range
        // we are about to claim, so the header and the first two days are
        // written BEFORE the failure. All three must disappear.
        $d0 = date('Y-m-d', strtotime('+140 days'));
        $d2 = date('Y-m-d', strtotime('+142 days'));
        $d3 = date('Y-m-d', strtotime('+143 days'));

        $this->occupy($d2, $d3);

        $bookingsBefore = $this->bookingCount();
        $daysBefore = $this->dayCount();
        self::assertSame(2, $daysBefore);

        $failure = $this->catchFailure(fn () => $this->occupy($d0, $d3));

        self::assertSame(self::DUPLICATE_ENTRY, $this->mysqlError($failure));
        self::assertSame(
            $bookingsBefore,
            $this->bookingCount(),
            'a mid-transaction failure must not leave the header row committed'
        );
        self::assertSame(
            $daysBefore,
            $this->dayCount(),
            'the days written before the conflict must be rolled back too'
        );
    }

    public function testSecondWriterLosesAtThePrimaryKeyEvenAfterPassingThePrecheck(): void
    {
        $pickup = date('Y-m-d', strtotime('+150 days'));
        $return = date('Y-m-d', strtotime('+151 days'));

        // Writer 1 and writer 2 both ask the same question and both get "free".
        self::assertTrue($this->cars->isAvailable($this->fixtureCarId(), $pickup, $return));

        $first = $this->occupy($pickup, $return);

        // Writer 2 does not re-check — that is exactly what the race looks like.
        $bookingsBefore = $this->bookingCount();
        $daysBefore = $this->dayCount();
        $failure = $this->catchFailure(fn () => $this->occupy($pickup, $return));

        self::assertSame(self::DUPLICATE_ENTRY, $this->mysqlError($failure));
        self::assertSame($bookingsBefore, $this->bookingCount(), 'exactly one writer survives');
        self::assertSame($daysBefore, $this->dayCount());

        // The surviving booking is untouched and still owns both days.
        $survivor = $this->bookings->findById($first);
        self::assertNotNull($survivor);
        self::assertSame('pending_payment', $survivor['status']);
        self::assertSame(2, $this->dayCountFor($first));

        self::assertFalse(
            $this->cars->isAvailable($this->fixtureCarId(), $pickup, $return),
            'the loser must not leave the vehicle looking free or double-booked'
        );
    }

    // --- G2: cancellation is atomic -----------------------------------------

    public function testCancellationRollsBackTheStatusChangeWhenFreeingTheDaysFails(): void
    {
        $pickup = date('Y-m-d', strtotime('+160 days'));
        $return = date('Y-m-d', strtotime('+161 days'));
        $id = $this->occupy($pickup, $return);
        self::assertSame(2, $this->dayCountFor($id));

        // A second connection holds the day rows, so the DELETE cannot finish.
        $this->holdLocksOnDays($id);

        $this->savedLockTimeout = $this->lockWaitTimeout();
        self::$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $failure = $this->catchFailure(
            fn () => $this->bookings->updateStatus($id, 'cancelled', $this->fixtureUserId())
        );

        self::$pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $this->savedLockTimeout);
        $this->releaseLocker();

        self::assertSame(
            self::LOCK_WAIT_TIMEOUT,
            $this->mysqlError($failure),
            'the held lock must be what makes freeing the days fail'
        );

        $booking = $this->bookings->findById($id);
        self::assertNotNull($booking);
        self::assertSame(
            'pending_payment',
            $booking['status'],
            'the status change must be rolled back with the failed deletion'
        );
        self::assertNull($booking['cancelled_at'], 'no cancellation timestamp may survive');
        self::assertSame(
            2,
            $this->dayCountFor($id),
            'the days stay occupied, consistent with a booking that is not cancelled'
        );
    }

    public function testCancellationStillFreesEveryDayWhenNothingFails(): void
    {
        $pickup = date('Y-m-d', strtotime('+170 days'));
        $return = date('Y-m-d', strtotime('+171 days'));
        $id = $this->occupy($pickup, $return);
        self::assertSame(2, $this->dayCountFor($id));

        $this->bookings->updateStatus($id, 'cancelled', $this->fixtureUserId());

        $booking = $this->bookings->findById($id);
        self::assertSame('cancelled', $booking['status']);
        self::assertNotNull($booking['cancelled_at']);
        self::assertSame(0, $this->dayCountFor($id), 'a cancelled booking frees the vehicle');

        self::assertTrue(
            $this->cars->isAvailable($this->fixtureCarId(), $pickup, $return),
            'the freed days are bookable again'
        );
    }

    public function testUpdateStatusJoinsATransactionTheCallerAlreadyOpened(): void
    {
        $pickup = date('Y-m-d', strtotime('+180 days'));
        $id = $this->occupy($pickup, date('Y-m-d', strtotime('+181 days')));

        // If updateStatus() blindly called beginTransaction() this would throw
        // "There is already an active transaction".
        self::$pdo->beginTransaction();
        try {
            $this->bookings->updateStatus($id, 'confirmed', $this->fixtureUserId());
            self::$pdo->commit();
        } catch (\Throwable $e) {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            throw $e;
        }

        $booking = $this->bookings->findById($id);
        self::assertSame('confirmed', $booking['status']);
        self::assertSame(2, $this->dayCountFor($id), 'a status change must not disturb the days');
    }
}

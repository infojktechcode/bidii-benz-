<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\BookingRepository;
use App\Repositories\CarRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ReportRepository;
use App\Services\BookingService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\CreatesRentalFixture;

/**
 * Phase 6 reporting (§9): booking/payment/client/vehicle totals, outstanding
 * balances, empty ranges, single-day ranges, month ranges and out-of-range
 * results must all be correct.
 *
 * Fixture rows are back-dated into 2026-03/2026-04 — months the seed data does
 * not touch — so the aggregates for those ranges are exact.
 *
 * Skips when MySQL is unavailable.
 */
final class ReportSummaryTest extends TestCase
{
    use CreatesRentalFixture;

    private static ?PDO $pdo = null;
    private static string $root = '';

    private BookingService $service;
    private BookingRepository $bookings;
    private ReportRepository $reports;
    private int $bookingA = 0;
    private int $bookingC = 0;

    /** Fixture bookings live in March/April 2026. */
    private const MARCH = ['2026-03-01', '2026-03-31'];
    private const MARCH_DAY = ['2026-03-10', '2026-03-10'];
    private const APRIL = ['2026-04-01', '2026-04-30'];
    private const EMPTY = ['2027-06-01', '2027-06-30'];

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
        $this->reports = new ReportRepository(self::$pdo);
        $this->service = new BookingService(
            $this->bookings,
            new CarRepository(self::$pdo),
            new PaymentRepository(self::$pdo)
        );

        // A: placed 10 March, still awaiting payment (2 days x 5000).
        $this->bookingA = $this->createAndBackdate(30, '2026-03-10 09:00:00');
        // B: placed 5 April, later cancelled (excluded from revenue).
        $this->createAndBackdate(35, '2026-04-05 11:00:00', cancelled: true);
        // C: placed 12 March, cancelled (same month as A).
        $this->bookingC = $this->createAndBackdate(40, '2026-03-12 14:30:00', cancelled: true);

        // 4000 received against A on 11 March.
        self::$pdo->prepare(
            'INSERT INTO payments (booking_id, client_id, amount, method, status, mpesa_receipt, paid_at, created_at)
             VALUES (?, ?, 4000, "mpesa", "confirmed", ?, "2026-03-11 10:00:00", "2026-03-11 10:00:00")'
        )->execute([$this->bookingA, $this->fixtureClientId(), 'RPT' . bin2hex(random_bytes(4))]);
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->destroyRentalFixture(self::$pdo);
        }
    }

    // --- helpers ------------------------------------------------------------

    private function createAndBackdate(int $startInDays, string $createdAt, bool $cancelled = false): int
    {
        $pickup = date('Y-m-d', strtotime('+' . $startInDays . ' days'));
        $return = date('Y-m-d', strtotime('+' . ($startInDays + 1) . ' days'));

        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => $pickup,
            'return_date' => $return,
        ]);
        self::assertTrue($result['ok'], $result['error'] ?? '');
        $id = (int) $result['booking_id'];

        self::$pdo->prepare('UPDATE bookings SET created_at = ? WHERE id = ?')
            ->execute([$createdAt, $id]);

        if ($cancelled) {
            self::assertTrue(
                $this->service->cancelBooking($id, $this->fixtureUserId(), 'owner')['ok']
            );
        }

        return $id;
    }

    private function summary(?string $from, ?string $to): array
    {
        return $this->reports->summary($from, $to);
    }

    // --- empty / all-time ---------------------------------------------------

    public function testEmptyRangeReportsAllTime(): void
    {
        $summary = $this->summary(null, null);

        self::assertGreaterThanOrEqual(3, $summary['bookings']['count'], 'at least the three fixture bookings');
        self::assertNull($summary['from']);
        self::assertNull($summary['to']);
        self::assertGreaterThanOrEqual(0, $summary['outstanding_all']);
        self::assertGreaterThanOrEqual(
            0.0,
            $summary['outstanding_all'] + 0.01 - $summary['outstanding'],
            'the all-time outstanding balance covers any single period'
        );
    }

    // --- single-day and month ranges ---------------------------------------

    public function testSingleDayRangeSelectsOnlyThatDay(): void
    {
        $summary = $this->summary(self::MARCH_DAY[0], self::MARCH_DAY[1]);

        self::assertSame(1, $summary['bookings']['count'], 'only booking A was placed on 10 March');
        self::assertCount(1, $summary['bookings_by_status']);
        self::assertSame(1, $summary['bookings_by_status']['pending_payment'] ?? 0);
        self::assertSame(10000.0, $summary['bookings']['revenue']);
        self::assertSame(1, $summary['bookings']['clients']);
        self::assertSame(1, $summary['bookings']['vehicles']);
        self::assertSame(0, $summary['payments']['count'], 'the payment was recorded on 11 March, not the 10th');
        self::assertSame(6000.0, $summary['outstanding'], '10000 owed - nothing confirmed on that day');

        $nextDay = $this->summary('2026-03-11', '2026-03-11');
        self::assertSame(0, $nextDay['bookings']['count'], 'no booking was placed on 11 March');
        self::assertSame(1, $nextDay['payments']['count'], 'the payment belongs to 11 March');
        self::assertSame(4000.0, $nextDay['payments']['total']);
    }

    public function testMonthRangeAggregatesEveryBookingInThatMonth(): void
    {
        $summary = $this->summary(self::MARCH[0], self::MARCH[1]);

        self::assertSame(2, $summary['bookings']['count'], 'A and C were both placed in March');
        self::assertCount(2, $summary['bookings_by_status']);
        self::assertSame(1, $summary['bookings_by_status']['pending_payment'] ?? 0);
        self::assertSame(1, $summary['bookings_by_status']['cancelled'] ?? 0);
        self::assertSame(10000.0, $summary['bookings']['revenue'], 'cancelled bookings contribute no revenue');
        self::assertSame(1, $summary['bookings']['clients']);
        self::assertSame(1, $summary['bookings']['vehicles']);
        self::assertSame(1, $summary['payments']['count']);
        self::assertSame(4000.0, $summary['payments']['total']);
        self::assertSame(4000.0, $summary['payments_by_status']['confirmed']);
        self::assertSame(1, $summary['payments_count_by_status']['confirmed']);
        self::assertSame(6000.0, $summary['outstanding'], 'C is cancelled, A still owes 6000');
    }

    public function testAprilRangeContainsOnlyTheCancelledBooking(): void
    {
        $summary = $this->summary(self::APRIL[0], self::APRIL[1]);

        self::assertSame(1, $summary['bookings']['count']);
        self::assertSame(1, $summary['bookings_by_status']['cancelled'] ?? 0);
        self::assertSame(0.0, $summary['bookings']['revenue']);
        self::assertSame(0, $summary['payments']['count']);
        self::assertSame(0.0, $summary['outstanding'], 'cancelled bookings are excluded from balances');
        self::assertCount(1, $summary['by_client'], 'a cancelled booking still lists its client');
        self::assertSame(0.0, (float) $summary['by_client'][0]['total']);
        self::assertSame(0.0, (float) $summary['by_client'][0]['outstanding']);
    }

    public function testOutOfRangeProducesZeroTotals(): void
    {
        $summary = $this->summary(self::EMPTY[0], self::EMPTY[1]);

        self::assertSame(0, $summary['bookings']['count']);
        self::assertSame(0.0, $summary['bookings']['revenue']);
        self::assertSame(0, $summary['bookings']['clients']);
        self::assertSame(0, $summary['bookings']['vehicles']);
        self::assertSame(0, $summary['payments']['count']);
        self::assertSame(0.0, $summary['payments']['total']);
        self::assertSame(0.0, $summary['outstanding']);
        self::assertSame([], $summary['bookings_by_status']);
        self::assertSame([], $summary['by_vehicle']);
        self::assertSame([], $summary['by_client']);
    }

    // --- breakdowns ---------------------------------------------------------

    public function testVehicleAndClientBreakdownsMatchTheRange(): void
    {
        $summary = $this->summary(self::MARCH[0], self::MARCH[1]);

        self::assertCount(1, $summary['by_vehicle'], 'only the fixture vehicle was hired in March');
        $vehicle = $summary['by_vehicle'][0];
        self::assertSame($this->fixtureCarId(), (int) $vehicle['id']);
        self::assertSame('Fixture C-Class', (string) $vehicle['model']);
        self::assertSame(2, (int) $vehicle['bookings']);
        self::assertSame(10000.0, (float) $vehicle['total']);
        self::assertSame(6000.0, (float) $vehicle['outstanding']);

        self::assertCount(1, $summary['by_client']);
        $client = $summary['by_client'][0];
        self::assertSame('Fixture Client', (string) $client['full_name']);
        self::assertSame(2, (int) $client['bookings']);
        self::assertSame(10000.0, (float) $client['total']);
        self::assertSame(6000.0, (float) $client['outstanding']);
    }

    public function testReportTotalsNeverExposeAnotherClientsBookings(): void
    {
        $summary = $this->summary(self::MARCH[0], self::MARCH[1]);

        foreach ($summary['by_client'] as $row) {
            self::assertSame($this->fixtureClientId(), (int) $row['id'], 'the March range only holds fixture rows');
        }
        self::assertNotContains(
            'national_id',
            array_keys($summary['by_client'][0] ?? []),
            'a report must not carry identifier PII it does not need'
        );
    }
}

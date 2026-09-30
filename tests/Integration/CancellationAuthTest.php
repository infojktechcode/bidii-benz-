<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\BookingRepository;
use App\Repositories\CarRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\UserRepository;
use App\Services\BookingService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\CreatesRentalFixture;

/**
 * Phase 6 — client cancellation rules and object-level authorization (§8, §12).
 *
 * BookingService::cancelBooking() is the single place that decides whether an
 * actor may cancel a booking, so both the client route and the staff route
 * depend on these rules:
 *
 *   client : own booking only, and only while pending_payment/confirmed
 *   staff  : any booking that is pending/confirmed/active
 *   owner  : same as staff (owner outranks staff)
 *
 * Skips when MySQL is unavailable.
 */
final class CancellationAuthTest extends TestCase
{
    use CreatesRentalFixture;

    private static ?PDO $pdo = null;
    private static string $root = '';

    private BookingService $service;
    private BookingRepository $bookings;
    private CarRepository $cars;
    private int $otherUserId = 0;

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
    }

    protected function tearDown(): void
    {
        $this->removeOtherClient();
        if (self::$pdo !== null) {
            $this->destroyRentalFixture(self::$pdo);
        }
    }

    // --- helpers ------------------------------------------------------------

    private function createBooking(int $startInDays): int
    {
        $result = $this->service->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => date('Y-m-d', strtotime('+' . $startInDays . ' days')),
            'return_date' => date('Y-m-d', strtotime('+' . ($startInDays + 1) . ' days')),
        ]);
        self::assertTrue($result['ok'], $result['error'] ?? '');
        return (int) $result['booking_id'];
    }

    private function statusOf(int $bookingId): string
    {
        $booking = $this->bookings->findById($bookingId);
        self::assertNotNull($booking);
        return (string) $booking['status'];
    }

    private function heldDays(int $bookingId): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM booking_days WHERE booking_id = ?');
        $stmt->execute([$bookingId]);
        return (int) $stmt->fetchColumn();
    }

    /** A real second client — object-level authorization must be proven with one. */
    private function otherClientId(): int
    {
        if ($this->otherUserId !== 0) {
            $stmt = self::$pdo->prepare('SELECT id FROM clients WHERE user_id = ?');
            $stmt->execute([$this->otherUserId]);
            return (int) $stmt->fetchColumn();
        }

        $this->otherUserId = (new UserRepository(self::$pdo))->createClient([
            'email' => 'other-' . bin2hex(random_bytes(5)) . '@example.test',
            'phone' => '07' . random_int(10000000, 99999999),
            'password_hash' => password_hash('Other1234', PASSWORD_DEFAULT),
            'full_name' => 'Other Client',
            'id_number' => (string) random_int(10000000, 99999999),
            'city' => 'Nairobi',
        ]);

        $stmt = self::$pdo->prepare('SELECT id FROM clients WHERE user_id = ?');
        $stmt->execute([$this->otherUserId]);
        return (int) $stmt->fetchColumn();
    }

    /** The id a signed-in intruder's session carries (users.id, not clients.id). */
    private function intruderUserId(): int
    {
        $this->otherClientId();
        return $this->otherUserId;
    }

    private function removeOtherClient(): void
    {
        if ($this->otherUserId === 0 || self::$pdo === null) {
            return;
        }
        self::$pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$this->otherUserId]);
        self::$pdo->prepare('DELETE FROM clients WHERE user_id = ?')->execute([$this->otherUserId]);
        self::$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->otherUserId]);
        $this->otherUserId = 0;
    }

    // --- client rules -------------------------------------------------------

    public function testClientCancelsTheirOwnPendingBookingAndTheDaysAreFreed(): void
    {
        $bookingId = $this->createBooking(60);
        self::assertSame(2, $this->heldDays($bookingId));

        $result = $this->service->cancelBooking(
            $bookingId,
            $this->fixtureUserId(),
            'client'
        );

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame('cancelled', $this->statusOf($bookingId));
        self::assertSame(0, $this->heldDays($bookingId), 'cancellation releases the vehicle');

        $booking = $this->bookings->findById($bookingId);
        self::assertNotNull($booking);
        self::assertNotNull($booking['cancelled_at'], 'the cancellation is timestamped for the audit trail');

        self::assertTrue(
            $this->cars->isAvailable(
                $this->fixtureCarId(),
                $booking['pickup_date'],
                $booking['return_date']
            ),
            'the vehicle can be booked again immediately'
        );
    }

    public function testClientCancelsTheirOwnConfirmedBooking(): void
    {
        $bookingId = $this->createBooking(65);
        self::assertTrue($this->service->confirmBooking($bookingId, $this->fixtureUserId())['ok']);

        $result = $this->service->cancelBooking($bookingId, $this->fixtureUserId(), 'client');

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame('cancelled', $this->statusOf($bookingId));
        self::assertSame(0, $this->heldDays($bookingId));
    }

    public function testClientCannotCancelAnotherClientsBooking(): void
    {
        $bookingId = $this->createBooking(70);
        $intruder = $this->intruderUserId();

        $result = $this->service->cancelBooking($bookingId, $intruder, 'client');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('authorized', (string) $result['error']);
        self::assertSame('pending_payment', $this->statusOf($bookingId), 'the victim booking must be untouched');
        self::assertSame(2, $this->heldDays($bookingId), 'the dates must stay occupied');
    }

    public function testClientCannotCancelAnActiveBooking(): void
    {
        $bookingId = $this->createBooking(75);
        self::assertTrue($this->service->confirmBooking($bookingId, $this->fixtureUserId())['ok']);
        self::assertTrue($this->service->startBooking($bookingId, $this->fixtureUserId())['ok']);

        $result = $this->service->cancelBooking($bookingId, $this->fixtureUserId(), 'client');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('cannot be cancelled', (string) $result['error']);
        self::assertSame('active', $this->statusOf($bookingId));
    }

    public function testClientCannotCancelCompletedOrCancelledBookings(): void
    {
        $completed = $this->createBooking(80);
        self::assertTrue($this->service->confirmBooking($completed, $this->fixtureUserId())['ok']);
        self::assertTrue($this->service->startBooking($completed, $this->fixtureUserId())['ok']);
        self::assertTrue($this->service->completeBooking($completed, $this->fixtureUserId())['ok']);

        $cancelled = $this->createBooking(85);
        self::assertTrue($this->service->cancelBooking($cancelled, $this->fixtureUserId(), 'client')['ok']);

        foreach ([$completed, $cancelled] as $id) {
            $result = $this->service->cancelBooking($id, $this->fixtureUserId(), 'client');
            self::assertFalse($result['ok'], 'booking ' . $id . ' must not be cancellable twice or after completion');
        }

        self::assertSame('completed', $this->statusOf($completed));
        self::assertSame('cancelled', $this->statusOf($cancelled));
    }

    public function testClientCannotCancelAnUnknownBooking(): void
    {
        $result = $this->service->cancelBooking(99999999, $this->fixtureUserId(), 'client');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('not found', (string) $result['error']);
    }

    // --- staff/owner rules --------------------------------------------------

    public function testStaffAndOwnerMayCancelAnActiveBooking(): void
    {
        $bookingId = $this->createBooking(90);
        self::assertTrue($this->service->confirmBooking($bookingId, $this->fixtureUserId())['ok']);
        self::assertTrue($this->service->startBooking($bookingId, $this->fixtureUserId())['ok']);

        self::assertTrue(
            $this->service->cancelBooking($bookingId, $this->fixtureUserId(), 'staff')['ok']
        );
        self::assertSame('cancelled', $this->statusOf($bookingId));
        self::assertSame(0, $this->heldDays($bookingId));
    }

    public function testStaffCannotCancelACompletedBooking(): void
    {
        $bookingId = $this->createBooking(95);
        self::assertTrue($this->service->confirmBooking($bookingId, $this->fixtureUserId())['ok']);
        self::assertTrue($this->service->startBooking($bookingId, $this->fixtureUserId())['ok']);
        self::assertTrue($this->service->completeBooking($bookingId, $this->fixtureUserId())['ok']);

        self::assertFalse(
            $this->service->cancelBooking($bookingId, $this->fixtureUserId(), 'staff')['ok']
        );
        self::assertSame('completed', $this->statusOf($bookingId));
    }

    public function testALieAboutTheRoleDoesNotWidenClientRights(): void
    {
        $bookingId = $this->createBooking(100);

        // The service trusts the caller's role string only to *narrow* rights;
        // a client acting as 'client' on somebody else's booking is rejected,
        // and the staff branch is only reachable from a staff/owner session.
        $asClient = $this->service->cancelBooking($bookingId, $this->intruderUserId(), 'client');
        self::assertFalse($asClient['ok']);
        self::assertSame('pending_payment', $this->statusOf($bookingId));
    }
}

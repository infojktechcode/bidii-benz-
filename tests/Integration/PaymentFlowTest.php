<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\BookingRepository;
use App\Repositories\CarRepository;
use App\Repositories\PaymentRepository;
use App\Services\BookingService;
use App\Services\PaymentService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\CreatesRentalFixture;

/**
 * Payment engine against the real database. Locks down the two defects that
 * blocked Phase 3: `confirmed_by` sent to a non-existent row 0 (ERROR 1452),
 * and `checkout_request_id` never being persisted (which made the M-Pesa
 * callback unable to find the payment it belonged to).
 *
 * Skips when MySQL is unavailable.
 */
final class PaymentFlowTest extends TestCase
{
    use CreatesRentalFixture;

    private static ?PDO $pdo = null;
    private static string $root = '';

    private BookingService $bookingsService;
    private BookingRepository $bookings;
    private CarRepository $cars;
    private PaymentRepository $payments;
    private PaymentService $paymentsService;
    private int $bookingId = 0;

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
        $this->payments = new PaymentRepository(self::$pdo);
        $this->bookingsService = new BookingService($this->bookings, $this->cars, $this->payments);
        $this->paymentsService = new PaymentService(
            $this->payments,
            $this->bookings,
            Config::load(self::$root)
        );

        $result = $this->bookingsService->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => date('Y-m-d', strtotime('+30 days')),
            'return_date' => date('Y-m-d', strtotime('+31 days')),
        ]);
        self::assertTrue($result['ok'], $result['error'] ?? '');
        $this->bookingId = (int) $result['booking_id'];
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->destroyRentalFixture(self::$pdo);
        }
    }

    private function serviceWith(array $overrides): PaymentService
    {
        return new PaymentService(
            $this->payments,
            $this->bookings,
            Config::load(self::$root, $overrides)
        );
    }

    public function testMockMpesaPaymentConfirmsImmediatelyAndPersistsTheCheckoutId(): void
    {
        $result = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'mpesa',
        ]);

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertArrayHasKey('checkout_request_id', $result);
        self::assertNotEmpty($result['checkout_request_id']);

        $payment = $this->payments->findById((int) $result['payment_id']);
        self::assertNotNull($payment);
        self::assertSame('confirmed', $payment['status']);
        self::assertSame(
            $result['checkout_request_id'],
            $payment['checkout_request_id'],
            'the callback correlation id must be stored, otherwise the callback can never match a payment'
        );
        self::assertNull($payment['confirmed_by'], 'mock confirmation is a system action, not a human one');
        self::assertNotEmpty($payment['mpesa_receipt']);
        self::assertNotNull($payment['paid_at']);

        $booking = $this->bookings->findById($this->bookingId);
        self::assertSame('confirmed', $booking['status'], 'settling the balance confirms the booking');
        self::assertSame(0.0, $this->paymentsService->getBookingBalance($this->bookingId));
    }

    public function testCashPaymentIsRecordedButNotAutoConfirmed(): void
    {
        $result = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertTrue($result['awaiting_confirmation'] ?? false);

        $payment = $this->payments->findById((int) $result['payment_id']);
        self::assertSame('pending', $payment['status']);
        self::assertNull($payment['confirmed_by']);

        $booking = $this->bookings->findById($this->bookingId);
        self::assertSame(
            'pending_payment',
            $booking['status'],
            'cash never moves money here — staff must confirm it manually'
        );
        self::assertSame(10000.0, $this->paymentsService->getBookingBalance($this->bookingId));
    }

    public function testManualConfirmationIsAttributedToARealStaffUser(): void
    {
        $initiated = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        self::assertTrue($initiated['ok']);

        $receipt = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        $confirm = $this->paymentsService->manualConfirm(
            (int) $initiated['payment_id'],
            $receipt,
            $this->fixtureUserId()
        );
        self::assertTrue($confirm['ok'], $confirm['error'] ?? '');

        $payment = $this->payments->findById((int) $initiated['payment_id']);
        self::assertSame('confirmed', $payment['status']);
        self::assertSame($this->fixtureUserId(), (int) $payment['confirmed_by']);
        self::assertSame($receipt, $payment['mpesa_receipt']);

        $booking = $this->bookings->findById($this->bookingId);
        self::assertSame('confirmed', $booking['status']);

        // The original error: confirmed_by = 0, which has no users row.
        $fkey = self::$pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS ' .
            'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = "payments" ' .
            'AND CONSTRAINT_NAME = "fk_payments_confirmer"'
        )->fetchColumn();
        self::assertNotFalse($fkey);
    }

    public function testReceiptsAreUniqueAcrossConfirmations(): void
    {
        $first = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        $receipt = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        self::assertTrue(
            $this->paymentsService->manualConfirm((int) $first['payment_id'], $receipt, $this->fixtureUserId())['ok']
        );

        $secondBooking = $this->bookingsService->createBooking([
            'client_id' => $this->fixtureClientId(),
            'car_id' => $this->fixtureCarId(),
            'pickup_date' => date('Y-m-d', strtotime('+45 days')),
            'return_date' => date('Y-m-d', strtotime('+46 days')),
        ]);
        self::assertTrue($secondBooking['ok'], $secondBooking['error'] ?? '');

        $second = $this->paymentsService->initiatePayment([
            'booking_id' => (int) $secondBooking['booking_id'],
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        self::assertTrue($second['ok'], $second['error'] ?? '');

        $duplicate = $this->paymentsService->manualConfirm(
            (int) $second['payment_id'],
            $receipt,
            $this->fixtureUserId()
        );
        self::assertFalse($duplicate['ok']);
        self::assertSame('Receipt already used.', $duplicate['error']);

        $fresh = $receipt . 'B';
        self::assertTrue(
            $this->paymentsService->manualConfirm((int) $second['payment_id'], $fresh, $this->fixtureUserId())['ok'],
            'an unused receipt still confirms normally'
        );
    }

    public function testOnlyPendingPaymentsCanBeManuallyConfirmed(): void
    {
        $initiated = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        $paymentId = (int) $initiated['payment_id'];
        self::assertTrue(
            $this->paymentsService->manualConfirm($paymentId, 'ONESHOT' . $paymentId, $this->fixtureUserId())['ok']
        );

        $again = $this->paymentsService->manualConfirm($paymentId, 'TWICE' . $paymentId, $this->fixtureUserId());
        self::assertFalse($again['ok']);
        self::assertSame('Payment is not pending.', $again['error']);
    }

    public function testSandboxModeRefusesInsteadOfFakingACollection(): void
    {
        $service = $this->serviceWith(['mpesa' => ['env' => 'sandbox']]);
        self::assertSame('sandbox', $service->environment());

        $result = $service->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'mpesa',
        ]);

        self::assertFalse($result['ok'], 'sandbox must not silently imitate a successful collection');
        self::assertStringContainsString('not enabled yet', $result['error'] ?? '');

        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM payments WHERE booking_id = ?');
        $stmt->execute([$this->bookingId]);
        self::assertSame(
            0,
            (int) $stmt->fetchColumn(),
            'a refused payment must not leave an orphaned pending record behind'
        );

        $booking = $this->bookings->findById($this->bookingId);
        self::assertSame('pending_payment', $booking['status']);
    }

    public function testInvalidEnvironmentIsRejected(): void
    {
        $service = $this->serviceWith(['mpesa' => ['env' => 'staging']]);
        $result = $service->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'mpesa',
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Invalid M-Pesa environment', $result['error'] ?? '');
    }

    public function testCallbackIsRejectedInMockMode(): void
    {
        $result = $this->paymentsService->handleCallback([
            'Body' => ['stkCallback' => ['CheckoutRequestID' => 'ws_CO_x', 'ResultCode' => 0]],
        ]);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('mock mode', $result['error'] ?? '');
    }

    public function testCallbackRequiresTheSharedSecret(): void
    {
        $service = $this->serviceWith(['mpesa' => ['env' => 'sandbox', 'callback_secret' => 's3cr3t-token']]);
        $payload = ['Body' => ['stkCallback' => ['CheckoutRequestID' => 'ws_CO_x', 'ResultCode' => 0]]];

        $missing = $service->handleCallback($payload, '');
        self::assertFalse($missing['ok']);
        self::assertSame('Unauthorized', $missing['error']);

        $wrong = $service->handleCallback($payload, 'not-the-token');
        self::assertFalse($wrong['ok']);

        self::assertTrue($service->validateCallbackToken('s3cr3t-token'));
        self::assertFalse($service->validateCallbackToken('s3cr3t-token '));
    }

    public function testCallbackWithoutSharedSecretConfiguredNeverAuthenticates(): void
    {
        $service = $this->serviceWith(['mpesa' => ['env' => 'sandbox', 'callback_secret' => '']]);
        self::assertFalse($service->validateCallbackToken(''));
        self::assertFalse($service->validateCallbackToken('anything'));
    }

    public function testCallbackConfirmsThePaymentItCorrelatesTo(): void
    {
        $initiated = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        $paymentId = (int) $initiated['payment_id'];
        $checkoutId = $initiated['checkout_request_id'];

        // Re-create it as an in-flight M-Pesa collection.
        self::$pdo->prepare('UPDATE payments SET status = "pending", method = "mpesa", mpesa_receipt = NULL WHERE id = ?')
            ->execute([$paymentId]);

        $service = $this->serviceWith(['mpesa' => ['env' => 'sandbox', 'callback_secret' => 'tok123']]);
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'ok',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'QE' . $paymentId . 'TEST'],
                            ['Name' => 'Amount', 'Value' => 10000],
                        ],
                    ],
                ],
            ],
        ];

        $result = $service->handleCallback($payload, 'tok123');
        self::assertTrue($result['ok'], $result['error'] ?? '');

        $payment = $this->payments->findById($paymentId);
        self::assertSame('confirmed', $payment['status']);
        self::assertNull($payment['confirmed_by'], 'a machine callback is not a human confirmation');
        self::assertSame('QE' . $paymentId . 'TEST', $payment['mpesa_receipt']);

        $booking = $this->bookings->findById($this->bookingId);
        self::assertSame('confirmed', $booking['status']);
    }

    public function testFailedCallbackMarksThePaymentFailedAndAcknowledges(): void
    {
        $initiated = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        $paymentId = (int) $initiated['payment_id'];
        self::$pdo->prepare('UPDATE payments SET status = "pending", method = "mpesa" WHERE id = ?')
            ->execute([$paymentId]);

        $service = $this->serviceWith(['mpesa' => ['env' => 'sandbox', 'callback_secret' => 'tok123']]);
        $result = $service->handleCallback([
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $initiated['checkout_request_id'],
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ], 'tok123');

        self::assertTrue($result['ok'], 'failures are acknowledged to stop Safaricom retries');

        $payment = $this->payments->findById($paymentId);
        self::assertSame('failed', $payment['status']);
        self::assertNull($payment['confirmed_by']);

        $booking = $this->bookings->findById($this->bookingId);
        self::assertSame('pending_payment', $booking['status']);
    }

    public function testCallbackForUnknownCheckoutIsRejected(): void
    {
        $service = $this->serviceWith(['mpesa' => ['env' => 'sandbox', 'callback_secret' => 'tok123']]);
        $result = $service->handleCallback([
            'Body' => ['stkCallback' => ['CheckoutRequestID' => 'ws_CO_never_existed', 'ResultCode' => 0]],
        ], 'tok123');

        self::assertFalse($result['ok']);
        self::assertSame('Unknown transaction', $result['error']);
    }

    public function testPartPaymentKeepsTheBalanceOpenAndTheNextCollectionIsTheRemainder(): void
    {
        $this->payments->createPending([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'amount' => 4000.0,
            'method' => 'mpesa',
            'mpesa_phone' => '+254712345678',
            'checkout_request_id' => 'ws_CO_partial',
        ]);
        $partial = $this->payments->findByCheckoutRequestId('ws_CO_partial');
        $this->payments->confirm(
            (int) $partial['id'],
            'PART' . $this->bookingId,
            date('Y-m-d H:i:s'),
            null
        );

        self::assertSame(
            6000.0,
            $this->paymentsService->getBookingBalance($this->bookingId),
            'the outstanding balance is total minus confirmed payments'
        );

        $booking = $this->bookings->findById($this->bookingId);
        self::assertSame(
            'pending_payment',
            $booking['status'],
            'a part payment must not confirm the booking'
        );

        $next = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'mpesa',
        ]);
        self::assertTrue($next['ok'], $next['error'] ?? '');

        $second = $this->payments->findById((int) $next['payment_id']);
        self::assertSame(
            6000.0,
            (float) $second['amount'],
            'the second collection must request exactly what is still owed'
        );

        $booking = $this->bookings->findById($this->bookingId);
        self::assertSame('confirmed', $booking['status'], 'settling the balance confirms it');
        self::assertSame(0.0, $this->paymentsService->getBookingBalance($this->bookingId));
    }

    public function testDuplicatePendingPaymentIsBlocked(): void
    {
        $first = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        self::assertTrue($first['ok']);

        $second = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        self::assertFalse($second['ok']);
        self::assertStringContainsString('already pending', $second['error'] ?? '');
    }

    public function testPaymentCannotBeInitiatedForSomebodyElsesBooking(): void
    {
        $result = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId() + 100000,
            'phone' => '0712345678',
            'method' => 'mpesa',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('Not authorized.', $result['error']);
    }

    public function testCancelledBookingIsNotPayable(): void
    {
        self::assertTrue(
            $this->bookingsService->cancelBooking($this->bookingId, $this->fixtureUserId(), 'owner')['ok']
        );

        $result = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'mpesa',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('Booking is not in a payable state.', $result['error']);
        self::assertSame(0.0, $this->paymentsService->getBookingBalance($this->bookingId));
    }

    public function testRefundRequiresAConfirmedPayment(): void
    {
        $initiated = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        $paymentId = (int) $initiated['payment_id'];

        $tooEarly = $this->paymentsService->refund($paymentId, $this->fixtureUserId(), 'too early');
        self::assertFalse($tooEarly['ok']);
        self::assertStringContainsString('confirmed', $tooEarly['error'] ?? '');

        self::assertTrue(
            $this->paymentsService->manualConfirm($paymentId, 'REFTEST' . $paymentId, $this->fixtureUserId())['ok']
        );

        $refunded = $this->payments->findById($paymentId);
        self::assertSame('confirmed', $refunded['status']);

        $ok = $this->paymentsService->refund($paymentId, $this->fixtureUserId(), 'customer request');
        self::assertTrue($ok['ok'], $ok['error'] ?? '');

        $payment = $this->payments->findById($paymentId);
        self::assertSame('refunded', $payment['status']);
        self::assertStringContainsString('customer request', (string) $payment['notes']);

        self::assertSame(
            10000.0,
            $this->paymentsService->getBookingBalance($this->bookingId),
            'a refund puts the money back on the books as outstanding'
        );
    }

    public function testSystemRefundWithoutAnActorIsAcceptable(): void
    {
        $initiated = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);
        $paymentId = (int) $initiated['payment_id'];
        self::assertTrue(
            $this->paymentsService->manualConfirm($paymentId, 'SYSREF' . $paymentId, null)['ok']
        );

        $payment = $this->payments->findById($paymentId);
        self::assertNull($payment['confirmed_by']);

        $result = $this->paymentsService->refund($paymentId, null, 'system');
        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame('refunded', $this->payments->findById($paymentId)['status']);
    }

    public function testUnknownPaymentOperationsFailGracefully(): void
    {
        self::assertFalse($this->paymentsService->manualConfirm(999999, 'X', $this->fixtureUserId())['ok']);
        self::assertFalse($this->paymentsService->refund(999999, $this->fixtureUserId())['ok']);
        self::assertFalse(
            $this->paymentsService->initiatePayment([
                'booking_id' => 999999,
                'client_id' => $this->fixtureClientId(),
                'phone' => '0712345678',
                'method' => 'mpesa',
            ])['ok']
        );
    }

    // --- Phase 6: which booking states may be paid ---------------------------

    public function testPayableStatesAreExactlyTheApprovedFour(): void
    {
        self::assertSame(
            ['pending_payment', 'confirmed', 'active', 'completed'],
            PaymentService::PAYABLE_STATUSES,
            'cancelled and no_show must never be payable (Phase 6 approved scope)'
        );
    }

    public function testAConfirmedBookingWithoutAStillCollectsTheBalance(): void
    {
        self::assertTrue($this->bookingsService->confirmBooking($this->bookingId, $this->fixtureUserId())['ok']);
        self::assertSame('confirmed', $this->bookings->findById($this->bookingId)['status']);
        self::assertSame(10000.0, $this->paymentsService->getBookingBalance($this->bookingId));

        $result = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'mpesa',
        ]);

        self::assertTrue($result['ok'], 'an approved but unpaid booking must stay payable');
        self::assertSame(
            10000.0,
            (float) $this->payments->findById((int) $result['payment_id'])['amount'],
            'the collection is for the whole outstanding balance'
        );
    }

    public function testAnActiveHireRemainsPayableWhileItIsUnderWay(): void
    {
        self::assertTrue($this->bookingsService->confirmBooking($this->bookingId, $this->fixtureUserId())['ok']);
        self::assertTrue($this->bookingsService->startBooking($this->bookingId, $this->fixtureUserId())['ok']);

        $result = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'cash',
        ]);

        self::assertTrue($result['ok'], 'a client paying during the hire must not be refused');
        self::assertSame('active', $this->bookings->findById($this->bookingId)['status'], 'paying never moves the rental on');
    }

    public function testAReturnedBookingCanBeSettledAfterTheHandBack(): void
    {
        self::assertTrue($this->bookingsService->confirmBooking($this->bookingId, $this->fixtureUserId())['ok']);
        self::assertTrue($this->bookingsService->startBooking($this->bookingId, $this->fixtureUserId())['ok']);
        self::assertTrue($this->bookingsService->completeBooking($this->bookingId, $this->fixtureUserId())['ok']);

        $result = $this->paymentsService->initiatePayment([
            'booking_id' => $this->bookingId,
            'client_id' => $this->fixtureClientId(),
            'phone' => '0712345678',
            'method' => 'mpesa',
        ]);

        self::assertTrue($result['ok'], 'a balance still owed after the return must be collectable');
        $payment = $this->payments->findById((int) $result['payment_id']);
        self::assertSame(10000.0, (float) $payment['amount'], 'the whole remaining balance is collected');
        self::assertSame(0.0, $this->paymentsService->getBookingBalance($this->bookingId));
    }
}

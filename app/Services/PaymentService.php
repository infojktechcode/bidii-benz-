<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PaymentRepository;
use App\Repositories\BookingRepository;
use App\Core\Config;
use App\Core\Log;
use Exception;

/**
 * Payment business logic: M-Pesa STK push (mock/sandbox), callback handling, confirmations.
 *
 * Environment contract (Config 'mpesa.*', loaded from .env MPESA_* keys):
 *   mock     — no external call; the payment is confirmed immediately.
 *   sandbox  — REFUSES until real Daraja sandbox credentials are supplied.
 *   live     — REFUSES until real Daraja credentials are supplied.
 * sandbox/live must never silently imitate a successful collection: doing so
 * would mark bookings confirmed while no money moved.
 */
final class PaymentService
{
    private const MOCK_RECEIPT_PREFIX = 'MOCK';

    public function __construct(
        private PaymentRepository $payments,
        private BookingRepository $bookings,
        private Config $config
    ) {
    }

    /** mock = auto-confirm locally; sandbox/live = real collection required. */
    public function environment(): string
    {
        return (string) $this->config->get('mpesa.env', 'mock');
    }

    /**
     * A callback is only expected outside mock mode, and only when a shared
     * secret has been configured. Comparison is timing-safe.
     */
    public function validateCallbackToken(string $provided): bool
    {
        $expected = (string) $this->config->get('mpesa.callback_secret', '');
        if ($expected === '') {
            return false;
        }
        return hash_equals($expected, $provided);
    }

    public function credentialsConfigured(): bool
    {
        foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey'] as $key) {
            if ((string) $this->config->get('mpesa.' . $key, '') === '') {
                return false;
            }
        }
        return true;
    }


    /**
     * Initiate payment for a booking.
     * Returns checkout info for the frontend to proceed (or auto-confirm in mock mode).
     *
     * @param array{booking_id:int, client_id:int, phone:string, method:string} $data
     * @return array{ok:bool, payment_id?:int, checkout_request_id?:string, merchant_request_id?:string,
     *               response_code?:string, response_description?:string, error?:string}
     */
    public function initiatePayment(array $data): array
    {
        $booking = $this->bookings->findById($data['booking_id']);
        if ($booking === null) {
            return ['ok' => false, 'error' => 'Booking not found.'];
        }
        if ($booking['client_id'] !== $data['client_id']) {
            return ['ok' => false, 'error' => 'Not authorized.'];
        }
        if ($booking['status'] !== 'pending_payment' && $booking['status'] !== 'confirmed') {
            return ['ok' => false, 'error' => 'Booking is not in a payable state.'];
        }

        // Block only while a collection is actually in flight. Confirmed
        // payments do not block: the balance may still be settled in parts.
        if ($this->payments->hasPendingForBooking($data['booking_id'])) {
            return ['ok' => false, 'error' => 'A payment is already pending for this booking.'];
        }

        $amount = $this->calculatePayableAmount($data['booking_id']);
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'No amount due.'];
        }

        $env = $this->environment();
        if (!in_array($env, ['mock', 'sandbox', 'live'], true)) {
            return ['ok' => false, 'error' => 'Invalid M-Pesa environment configuration.'];
        }
        // Decide BEFORE inserting a row so a refused payment never leaves an
        // orphaned "pending" record behind.
        if ($env !== 'mock') {
            return [
                'ok' => false,
                'error' => sprintf(
                    'M-Pesa %s mode is not enabled yet: real STK push is not integrated%s.',
                    $env,
                    $this->credentialsConfigured() ? '' : ' and MPESA_* credentials are missing'
                ),
            ];
        }

        $phone = $this->normalizePhone($data['phone']);
        $checkoutRequestId = 'ws_CO_' . date('YmdHis') . bin2hex(random_bytes(4));

        $paymentId = $this->payments->createPending([
            'booking_id' => $data['booking_id'],
            'client_id' => $data['client_id'],
            'amount' => $amount,
            'method' => $data['method'],
            'mpesa_phone' => $phone,
            'checkout_request_id' => $checkoutRequestId,
        ]);

        // Cash and bank transfers move no money here: record them and let
        // staff confirm through the admin panel.
        if ($data['method'] !== 'mpesa') {
            return [
                'ok' => true,
                'payment_id' => $paymentId,
                'checkout_request_id' => $checkoutRequestId,
                'response_code' => '0',
                'response_description' => 'Recorded — awaiting office confirmation',
                'awaiting_confirmation' => true,
            ];
        }

        return $this->mockStkPush($paymentId, (int) $data['booking_id'], $checkoutRequestId);
    }

    /**
     * Handle M-Pesa callback (STK push result).
     * Rejects callbacks that are not allowed by the configured environment or
     * that do not present the shared secret.
     *
     * @param array<string, mixed> $callback
     */
    public function handleCallback(array $callback, string $token = ''): array
    {
        if ($this->environment() === 'mock') {
            Log::warning('M-Pesa callback rejected: callbacks are disabled in mock mode');
            return ['ok' => false, 'error' => 'Callbacks disabled in mock mode'];
        }
        if (!$this->validateCallbackToken($token)) {
            Log::warning('M-Pesa callback rejected: missing or invalid shared secret');
            return ['ok' => false, 'error' => 'Unauthorized'];
        }

        $stkCallback = $callback['Body']['stkCallback'] ?? null;
        if ($stkCallback === null) {
            Log::warning('Invalid M-Pesa callback structure');
            return ['ok' => false, 'error' => 'Invalid callback'];
        }

        $checkoutRequestId = $stkCallback['CheckoutRequestID'] ?? '';
        $resultCode = (int) ($stkCallback['ResultCode'] ?? -1);
        $resultDesc = (string) ($stkCallback['ResultDesc'] ?? 'Unknown error');

        $payment = $this->payments->findByCheckoutRequestId($checkoutRequestId);
        if ($payment === null) {
            Log::warning('M-Pesa callback for unknown checkout_request_id', ['id' => $checkoutRequestId]);
            return ['ok' => false, 'error' => 'Unknown transaction'];
        }

        if ($resultCode !== 0) {
            $this->payments->markFailed((int) $payment['id'], $resultDesc);
            Log::info('M-Pesa payment failed', ['checkout' => $checkoutRequestId, 'desc' => $resultDesc]);
            return ['ok' => true]; // Acknowledge to stop retries
        }

        // Success - extract receipt and confirm
        $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
        $mpesaReceipt = '';
        foreach ($callbackMetadata as $item) {
            if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
                $mpesaReceipt = (string) ($item['Value'] ?? '');
                break;
            }
        }

        if ($mpesaReceipt === '') {
            $this->payments->markFailed((int) $payment['id'], 'Missing receipt in callback');
            return ['ok' => false, 'error' => 'Missing receipt'];
        }

        // Idempotency: check if receipt already used
        if ($this->payments->findByMpesaReceipt($mpesaReceipt) !== null) {
            Log::warning('Duplicate M-Pesa receipt in callback', ['receipt' => $mpesaReceipt]);
            return ['ok' => true];
        }

        $paidAt = date('Y-m-d H:i:s');
        // confirmed_by stays NULL: no human authorised this, and users(id) has
        // no row 0, so the FK would reject a 0 sentinel.
        $this->payments->confirm((int) $payment['id'], $mpesaReceipt, $paidAt, null);

        // If booking was pending_payment and now fully paid, confirm it
        $booking = $this->bookings->findById((int) $payment['booking_id']);
        if ($booking !== null && $booking['status'] === 'pending_payment') {
            $balance = $this->getBookingBalance((int) $payment['booking_id']);
            if ($balance <= 0.01) { // Allow small rounding
                $this->bookings->updateStatus((int) $payment['booking_id'], 'confirmed', null);
            }
        }

        Log::info('M-Pesa payment confirmed', ['receipt' => $mpesaReceipt, 'checkout' => $checkoutRequestId]);
        return ['ok' => true];
    }

    /**
     * Staff/owner manual payment confirmation. $confirmedBy is a real users.id
     * (or null for a system action) — never a sentinel like 0, which the
     * payments.confirmed_by foreign key would reject.
     */
    public function manualConfirm(int $paymentId, string $receipt, ?int $confirmedBy): array
    {
        $payment = $this->payments->findById($paymentId);
        if ($payment === null) {
            return ['ok' => false, 'error' => 'Payment not found.'];
        }
        if ($payment['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'Payment is not pending.'];
        }
        if ($this->payments->findByMpesaReceipt($receipt) !== null) {
            return ['ok' => false, 'error' => 'Receipt already used.'];
        }

        $paidAt = date('Y-m-d H:i:s');
        $this->payments->confirm($paymentId, $receipt, $paidAt, $confirmedBy);

        $booking = $this->bookings->findById((int) $payment['booking_id']);
        if ($booking !== null && $booking['status'] === 'pending_payment') {
            $balance = $this->getBookingBalance((int) $payment['booking_id']);
            if ($balance <= 0.01) {
                $this->bookings->updateStatus((int) $payment['booking_id'], 'confirmed', $confirmedBy);
            }
        }

        return ['ok' => true];
    }

    public function refund(int $paymentId, ?int $refundedBy, string $notes = ''): array
    {
        $payment = $this->payments->findById($paymentId);
        if ($payment === null) {
            return ['ok' => false, 'error' => 'Payment not found.'];
        }
        if ($payment['status'] !== 'confirmed') {
            return ['ok' => false, 'error' => 'Only confirmed payments can be refunded.'];
        }
        $this->payments->refund($paymentId, $refundedBy, $notes);
        return ['ok' => true];
    }

    private function calculatePayableAmount(int $bookingId): float
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            return 0.0;
        }
        $paid = $this->payments->totalConfirmedForBooking($bookingId);
        return round((float) $booking['total_amount'] - $paid, 2);
    }

    public function getBookingBalance(int $bookingId): float
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null || $booking['status'] === 'cancelled') {
            return 0.0;
        }
        $paid = $this->payments->totalConfirmedForBooking($bookingId);
        return round((float) $booking['total_amount'] - $paid, 2);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-\.]/', '', $phone);
        if (str_starts_with($phone, '0')) {
            return '+254' . substr($phone, 1);
        }
        if (str_starts_with($phone, '+254')) {
            return $phone;
        }
        if (str_starts_with($phone, '254')) {
            return '+' . $phone;
        }
        return $phone; // Let M-Pesa validate
    }

    /**
     * Local mock collection: confirms the payment immediately and promotes the
     * booking once the balance is settled. Never contacts Safaricom.
     *
     * @return array{ok:bool, payment_id:int, checkout_request_id:string,
     *               response_code:string, response_description:string}
     */
    private function mockStkPush(int $paymentId, int $bookingId, string $checkoutRequestId): array
    {
        $receipt = self::MOCK_RECEIPT_PREFIX . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
        $paidAt = date('Y-m-d H:i:s');

        $this->payments->confirm($paymentId, $receipt, $paidAt, null);

        $booking = $this->bookings->findById($bookingId);
        if ($booking !== null && $booking['status'] === 'pending_payment') {
            $balance = $this->getBookingBalance($bookingId);
            if ($balance <= 0.01) {
                $this->bookings->updateStatus($bookingId, 'confirmed', null);
            }
        }

        return [
            'ok' => true,
            'payment_id' => $paymentId,
            'checkout_request_id' => $checkoutRequestId,
            'response_code' => '0',
            'response_description' => 'Mock payment successful',
        ];
    }
}
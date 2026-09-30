<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Guard;
use App\Core\Input;
use App\Core\Log;
use App\Core\Session;
use App\Core\View;
use App\Repositories\BookingRepository;
use App\Repositories\PaymentRepository;
use App\Services\PaymentService;
use App\Services\RateLimiter;
use App\Core\Database;

/**
 * Payment flow: show payment page, initiate M-Pesa, handle callback.
 */
final class PaymentController
{
    private PaymentService $paymentService;
    private BookingRepository $bookings;
    private PaymentRepository $payments;
    private Config $config;

    public function __construct()
    {
        $this->config = Config::load(dirname(__DIR__, 2));
        $pdo = Database::connect($this->config);
        $this->bookings = new BookingRepository($pdo);
        $this->payments = new PaymentRepository($pdo);
        $this->paymentService = new PaymentService($this->payments, $this->bookings, $this->config);
    }

    public function show(array $params = []): void
    {
        $bookingId = (int) ($params['bookingId'] ?? 0);
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            http_response_code(404);
            View::render('errors/404', [], 404);
            return;
        }

        $clientId = $this->getClientId();
        $role = Guard::role();
        if ($role === 'client' && $booking['client_id'] !== $clientId) {
            http_response_code(403);
            View::render('errors/403', [], 403);
            return;
        }

        if (!in_array((string) $booking['status'], PaymentService::PAYABLE_STATUSES, true)) {
            Session::flash('error', 'This booking is not in a payable state.');
            redirect('/bookings');
        }

        $balance = $this->paymentService->getBookingBalance($bookingId);
        if ($balance <= 0.01) {
            Session::flash('success', 'Booking fully paid.');
            redirect('/booking/' . $booking['booking_ref'] . '/confirm');
        }

        if ($this->payments->hasPendingForBooking($bookingId)) {
            Session::flash('error', 'A payment is already pending for this booking.');
            redirect('/booking/' . $booking['booking_ref'] . '/confirm');
        }

        View::render('payment/show', [
            'title' => 'Pay for ' . $booking['booking_ref'] . ' — Bidii Benz Rentals',
            'booking' => $booking,
            'balance' => $balance,
            'errors' => Session::pull('pay_errors', []),
            'old' => Session::pull('pay_old', []),
        ]);
    }

    public function initiate(array $params = []): void
    {
        if (!Csrf::validate('payment_initiate', $_POST['_csrf'] ?? null)) {
            $this->forbidCsrf();
        }

        // Throttle initiations per session before anything else runs: on live
        // M-Pesa every STK push costs money, and a hammered form should stop at
        // 429 rather than queueing pushes. Counted per session, not per row, so
        // no booking data is written by a blocked attempt.
        $now = time();
        $raw = Session::get('pay_init_times', []);
        $times = is_array($raw)
            ? array_values(array_filter(array_map('intval', $raw), static fn (int $t): bool => $t > 0))
            : [];
        if (RateLimiter::overLimit($times, RateLimiter::MAX_INITIATES_PER_WINDOW, $now)) {
            http_response_code(429);
            View::render('errors/429', [], 429);
            return;
        }
        $times[] = $now;
        Session::set('pay_init_times', RateLimiter::inWindow($times, $now));

        $bookingId = (int) ($params['bookingId'] ?? 0);
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            Session::flash('error', 'Booking not found.');
            redirect('/bookings');
        }

        $clientId = $this->getClientId();
        $role = Guard::role();
        if ($role === 'client' && $booking['client_id'] !== $clientId) {
            http_response_code(403);
            View::render('errors/403', [], 403);
            return;
        }

        $input = Input::fromRequest();
        $phone = $input->phone('phone');
        $method = $input->string('method', 20);

        $errors = [];
        if ($phone === '') {
            $errors['phone'] = 'Valid Kenyan mobile number required for M-Pesa.';
        }
        if ($method === '') {
            $errors['method'] = 'Payment method required.';
        }

        if ($errors !== []) {
            Session::set('pay_errors', $errors);
            Session::set('pay_old', ['phone' => $phone, 'method' => $method]);
            redirect('/payment/' . $bookingId);
        }

        $result = $this->paymentService->initiatePayment([
            'booking_id' => $bookingId,
            'client_id' => (int) $booking['client_id'],
            'phone' => $phone,
            'method' => $method,
        ]);

        if (!$result['ok']) {
            Session::flash('error', $result['error']);
            redirect('/payment/' . $bookingId);
        }

        // The service refuses any non-mock M-Pesa environment before writing a
        // row, so reaching here means either the mock STK push confirmed the
        // payment or a cash/bank record is awaiting office confirmation.
        $payment = $this->payments->findById((int) $result['payment_id']);
        Audit::asCurrentActor(
            'payment.initiate',
            'payments',
            (int) $result['payment_id'],
            sprintf(
                'booking=%s amount=%s method=%s',
                $booking['booking_ref'],
                $payment !== null ? number_format((float) $payment['amount'], 2) : 'n/a',
                $method
            )
        );
        if (!empty($result['awaiting_confirmation'])) {
            Session::flash('success', 'Payment recorded. The office will confirm it shortly.');
        } else {
            Session::flash('success', 'Payment successful (mock mode).');
        }
        redirect('/booking/' . $booking['booking_ref'] . '/confirm');
    }

    /**
     * M-Pesa result callback. No session/CSRF (it is a machine caller):
     * authenticated instead by the configured shared secret, and refused
     * outright while MPESA_ENV=mock.
     */
    public function callback(array $params = []): void
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!is_array($data)) {
            Log::warning('Invalid M-Pesa callback JSON');
            http_response_code(400);
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload']);
            exit;
        }

        $token = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';
        $result = $this->paymentService->handleCallback($data, is_string($token) ? $token : '');
        if ($result['ok']) {
            http_response_code(200);
            echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        } else {
            http_response_code(401);
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => $result['error'] ?? 'Rejected']);
        }
        exit;
    }

    private function getClientId(): ?int
    {
        $userId = Guard::userId();
        if ($userId === null) {
            return null;
        }
        $pdo = Database::connect($this->config);
        $stmt = $pdo->prepare('SELECT id FROM clients WHERE user_id = ?');
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function forbidCsrf(): never
    {
        http_response_code(403);
        View::render('errors/403', [], 403);
        exit;
    }
}
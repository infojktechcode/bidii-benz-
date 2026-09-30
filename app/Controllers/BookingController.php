<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Guard;
use App\Core\Input;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CarRepository;
use App\Repositories\BookingRepository;
use App\Repositories\PaymentRepository;
use App\Services\BookingService;
use App\Core\Database;

/**
 * Client booking flow: create, confirm, history, detail.
 */
final class BookingController
{
    private BookingService $bookingService;
    private CarRepository $cars;
    private BookingRepository $bookings;
    private PaymentRepository $payments;
    private Config $config;

    public function __construct()
    {
        $this->config = Config::load(dirname(__DIR__, 2));
        $pdo = Database::connect($this->config);
        $this->cars = new CarRepository($pdo);
        $this->bookings = new BookingRepository($pdo);
        $this->payments = new PaymentRepository($pdo);
        $this->bookingService = new BookingService($this->bookings, $this->cars, $this->payments);
    }

    // --- Create booking ------------------------------------------------------

    public function showCreate(array $params = []): void
    {
        $carId = (int) ($params['id'] ?? 0);
        $car = $this->cars->findById($carId);
        if ($car === null || $car['status'] !== 'active') {
            http_response_code(404);
            View::render('errors/404', [], 404);
            return;
        }

        $start = date('Y-m-d');
        $end = date('Y-m-d', strtotime('+90 days'));

        View::render('booking/create', [
            'title' => 'Book ' . $car['make'] . ' ' . $car['model'] . ' — Bidii Benz Rentals',
            'car' => $car,
            'min_date' => $start,
            'max_date' => $end,
            'errors' => Session::pull('book_errors', []),
            'old' => Session::pull('book_old', []),
        ]);
    }

    public function create(array $params = []): void
    {
        if (!Csrf::validate('booking_create', $_POST['_csrf'] ?? null)) {
            $this->forbidCsrf();
        }

        $carId = (int) ($params['id'] ?? 0);
        $car = $this->cars->findById($carId);
        if ($car === null || $car['status'] !== 'active') {
            Session::flash('error', 'Vehicle not available.');
            redirect('/cars');
        }

        $clientId = $this->getClientId();
        if ($clientId === null) {
            Session::flash('error', 'Client profile not found.');
            redirect('/cars/' . $carId . '/book');
        }

        $input = Input::fromRequest();
        $pickup = $input->date('pickup_date');
        $return = $input->date('return_date');
        $pickupLocation = $input->string('pickup_location', 120);
        $notes = $input->string('notes', 500);

        $errors = [];
        if ($pickup === null) {
            $errors['pickup_date'] = 'Pickup date is required.';
        }
        if ($return === null) {
            $errors['return_date'] = 'Return date is required.';
        }
        if ($pickup !== null && $return !== null && $pickup >= $return) {
            $errors['return_date'] = 'Return date must be after pickup date.';
        }
        if ($pickup !== null && $pickup < date('Y-m-d')) {
            $errors['pickup_date'] = 'Pickup date cannot be in the past.';
        }

        if ($errors !== []) {
            Session::set('book_errors', $errors);
            Session::set('book_old', [
                'pickup_date' => $pickup ?? '',
                'return_date' => $return ?? '',
                'pickup_location' => $pickupLocation,
                'notes' => $notes,
            ]);
            redirect('/cars/' . $carId . '/book');
        }

        $result = $this->bookingService->createBooking([
            'client_id' => $clientId,
            'car_id' => $carId,
            'pickup_date' => $pickup,
            'return_date' => $return,
            'pickup_location' => $pickupLocation ?: null,
            'notes' => $notes ?: null,
        ]);

        if (!$result['ok']) {
            Session::flash('error', $result['error']);
            redirect('/cars/' . $carId . '/book');
        }

        Audit::asCurrentActor(
            'booking.create',
            'bookings',
            (int) $result['booking_id'],
            (string) ($result['booking_ref'] ?? '')
        );

        redirect('/booking/' . $result['booking_ref'] . '/confirm');
    }

    /**
     * Cancel an eligible own booking (client). Ownership and the allowed source
     * statuses are enforced server-side in BookingService — the button in the
     * UI is only a convenience.
     */
    public function cancel(array $params = []): void
    {
        if (!Csrf::validate('booking_cancel', $_POST['_csrf'] ?? null)) {
            $this->forbidCsrf();
        }

        $id = (int) ($params['id'] ?? 0);
        $booking = $this->bookings->findById($id);
        if ($booking === null) {
            Session::flash('error', 'Booking not found.');
            redirect('/bookings');
        }

        $clientId = $this->getClientId();
        if ($clientId === null || (int) $booking['client_id'] !== $clientId) {
            http_response_code(403);
            View::render('errors/403', [], 403);
            return;
        }

        $result = $this->bookingService->cancelBooking($id, (int) Guard::userId(), 'client');
        if (!$result['ok']) {
            Session::flash('error', $result['error'] ?? 'This booking cannot be cancelled.');
            redirect('/bookings/' . $id);
        }

        Audit::asCurrentActor('booking.cancel', 'bookings', $id, (string) $booking['booking_ref']);
        Session::flash('success', 'Booking cancelled. The dates are available again.');
        redirect('/bookings');
    }

    // --- Confirmation --------------------------------------------------------

    public function confirm(array $params = []): void
    {
        $ref = (string) ($params['ref'] ?? '');
        $booking = $this->bookings->findByRef($ref);
        if ($booking === null) {
            http_response_code(404);
            View::render('errors/404', [], 404);
            return;
        }

        // Authorization: only the booking client (or staff/owner) can view
        $clientId = $this->getClientId();
        $role = Guard::role();
        if ($role === 'client' && $booking['client_id'] !== $clientId) {
            http_response_code(403);
            View::render('errors/403', [], 403);
            return;
        }

        $balance = $this->bookingService->getBookingBalance((int) $booking['id']);

        View::render('booking/confirm', [
            'title' => 'Booking ' . $booking['booking_ref'] . ' — Bidii Benz Rentals',
            'booking' => $booking,
            'balance' => $balance,
        ]);
    }

    // --- History -------------------------------------------------------------

    public function history(array $params = []): void
    {
        $clientId = $this->getClientId();
        if ($clientId === null) {
            Session::flash('error', 'Client profile not found.');
            redirect('/');
        }

        $bookings = $this->bookings->findByClient($clientId);
        $withBalances = [];
        foreach ($bookings as $b) {
            $balance = $this->bookingService->getBookingBalance((int) $b['id']);
            $withBalances[] = array_merge($b, ['balance' => $balance]);
        }

        View::render('booking/history', [
            'title' => 'My Bookings — Bidii Benz Rentals',
            'bookings' => $withBalances,
        ]);
    }

    public function detail(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $booking = $this->bookings->findById($id);
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

        $balance = $this->bookingService->getBookingBalance($id);

        View::render('booking/detail', [
            'title' => 'Booking ' . $booking['booking_ref'] . ' — Bidii Benz Rentals',
            'booking' => $booking,
            'balance' => $balance,
            'payments' => $this->payments->findAllForBooking($id),
        ]);
    }

    // --- Helpers -------------------------------------------------------------

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
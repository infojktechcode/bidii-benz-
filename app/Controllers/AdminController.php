<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Guard;
use App\Core\Input;
use App\Core\Log;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AuditRepository;
use App\Repositories\BookingRepository;
use App\Repositories\CarRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ReportRepository;
use App\Repositories\UserRepository;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\PhotoStorage;
use App\Services\ReportRange;
use PDO;

/**
 * Staff/owner back office.
 *
 * Every route that reaches this controller is guarded in app/routes.php:
 * 'staff' for the back office generally (owner satisfies the staff check) and
 * 'owner' for the money-moving refund action. Every POST is CSRF-validated.
 * Frontend visibility of these links is never the access control.
 */
final class AdminController
{
    private Config $config;
    private PDO $pdo;
    private CarRepository $cars;
    private BookingRepository $bookings;
    private PaymentRepository $payments;
    private BookingService $bookingService;
    private PaymentService $paymentService;
    private PhotoStorage $photos;
    private UserRepository $users;
    private AuditRepository $auditLogs;

    public function __construct()
    {
        $this->config = Config::load(dirname(__DIR__, 2));
        $this->pdo = Database::connect($this->config);
        $this->cars = new CarRepository($this->pdo);
        $this->bookings = new BookingRepository($this->pdo);
        $this->payments = new PaymentRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);
        $this->auditLogs = new AuditRepository($this->pdo);
        $this->bookingService = new BookingService($this->bookings, $this->cars, $this->payments);
        $this->paymentService = new PaymentService($this->payments, $this->bookings, $this->config);
        $this->photos = new PhotoStorage(
            (string) $this->config->get('uploads.dir') . DIRECTORY_SEPARATOR . 'cars',
            (int) $this->config->get('uploads.max_bytes', 2097152)
        );
    }

    // --- Dashboard -----------------------------------------------------------

    public function dashboard(array $params = []): void
    {
        $byStatus = $this->bookings->countByStatus();
        View::render('admin/dashboard', [
            'title' => 'Dashboard — Bidii Benz Rentals',
            'stats' => [
                'cars' => $this->cars->countAll(),
                'clients' => $this->users->countClients(),
                'bookings' => $this->bookings->countAll(),
                'pending_payments' => $this->payments->countAll('pending'),
                'outstanding' => $this->bookings->outstandingBalance(),
            ],
            'by_status' => $byStatus,
            'recent' => $this->bookings->findAll(null, 10),
        ]);
    }

    // --- Vehicles ------------------------------------------------------------

    public function vehicles(array $params = []): void
    {
        View::render('admin/vehicles', [
            'title' => 'Vehicles — Bidii Benz Rentals',
            'cars' => $this->cars->findAll(),
        ]);
    }

    public function showCreateVehicle(array $params = []): void
    {
        View::render('admin/vehicle_form', [
            'title' => 'Add vehicle — Bidii Benz Rentals',
            'car' => null,
            'errors' => Session::pull('veh_errors', []),
            'old' => Session::pull('veh_old', []),
        ]);
    }

    public function createVehicle(array $params = []): void
    {
        if (!$this->csrfOk('admin_vehicle_create')) {
            return;
        }

        [$data, $errors, $old] = $this->readVehicle();
        if ($errors !== []) {
            Session::set('veh_errors', $errors);
            Session::set('veh_old', $old);
            redirect('/admin/vehicles/create');
        }

        $filename = $this->storePhoto($errors, $old);
        if ($errors !== []) {
            Session::set('veh_errors', $errors);
            Session::set('veh_old', $old);
            redirect('/admin/vehicles/create');
        }
        $data['image_path'] = $filename;

        try {
            $id = $this->cars->create($data);
        } catch (\PDOException $e) {
            $this->photos->delete($filename);
            Log::error('Vehicle creation failed: ' . $e->getMessage());
            Session::flash('error', str_contains($e->getMessage(), 'uq_cars_plate')
                ? 'That registration plate already exists.'
                : 'Could not save the vehicle.');
            redirect('/admin/vehicles/create');
        }

        $this->audit('vehicle.create', 'cars', $id, $data['registration_plate']);
        Session::flash('success', 'Vehicle added.');
        redirect('/admin/vehicles');
    }

    public function showEditVehicle(array $params = []): void
    {
        $car = $this->cars->findById((int) ($params['id'] ?? 0));
        if ($car === null) {
            http_response_code(404);
            View::render('errors/404', [], 404);
            return;
        }

        View::render('admin/vehicle_form', [
            'title' => 'Edit vehicle — Bidii Benz Rentals',
            'car' => $car,
            'errors' => Session::pull('veh_errors', []),
            'old' => Session::pull('veh_old', []),
        ]);
    }

    public function updateVehicle(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $car = $this->cars->findById($id);
        if ($car === null) {
            Session::flash('error', 'Vehicle not found.');
            redirect('/admin/vehicles');
        }
        if (!$this->csrfOk('admin_vehicle_update')) {
            return;
        }

        [$data, $errors, $old] = $this->readVehicle();
        if ($errors !== []) {
            Session::set('veh_errors', $errors);
            Session::set('veh_old', $old);
            redirect('/admin/vehicles/' . $id . '/edit');
        }

        $oldPhoto = is_string($car['image_path']) ? $car['image_path'] : null;
        $newPhoto = null;
        if ($this->hasPhotoUpload()) {
            $newPhoto = $this->storePhoto($errors, $old);
            if ($errors !== []) {
                Session::set('veh_errors', $errors);
                Session::set('veh_old', $old);
                redirect('/admin/vehicles/' . $id . '/edit');
            }
            $data['image_path'] = $newPhoto;
        }

        if (!empty($old['remove_photo']) && $newPhoto === null) {
            $data['image_path'] = null;
        }

        try {
            $this->cars->update($id, $data);
        } catch (\PDOException $e) {
            $this->photos->delete($newPhoto);
            Log::error('Vehicle update failed: ' . $e->getMessage());
            Session::flash('error', str_contains($e->getMessage(), 'uq_cars_plate')
                ? 'That registration plate already exists.'
                : 'Could not save the vehicle.');
            redirect('/admin/vehicles/' . $id . '/edit');
        }

        if (array_key_exists('image_path', $data)
            && $oldPhoto !== null
            && $data['image_path'] !== $oldPhoto) {
            $this->photos->delete($oldPhoto);
        }

        $this->audit('vehicle.update', 'cars', $id, $data['registration_plate'] ?? '');
        Session::flash('success', 'Vehicle updated.');
        redirect('/admin/vehicles');
    }

    public function deleteVehicle(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $car = $this->cars->findById($id);
        if ($car === null) {
            Session::flash('error', 'Vehicle not found.');
            redirect('/admin/vehicles');
        }
        if (!$this->csrfOk('admin_vehicle_delete')) {
            return;
        }

        $this->cars->softDelete($id);
        $this->audit('vehicle.delete', 'cars', $id, $car['registration_plate']);

        Session::flash('success', 'Vehicle removed from the fleet.');
        redirect('/admin/vehicles');
    }

    // --- Bookings ------------------------------------------------------------

    public function bookings(array $params = []): void
    {
        $status = null;
        if (!empty($_GET['status']) && is_string($_GET['status'])) {
            $candidate = substr($_GET['status'], 0, 30);
            if (in_array($candidate, $this->bookingStatuses(), true)) {
                $status = $candidate;
            }
        }

        View::render('admin/bookings', [
            'title' => 'Bookings — Bidii Benz Rentals',
            'bookings' => $this->bookings->findAll($status, 200),
            'filter' => $status,
            'statuses' => $this->bookingStatuses(),
        ]);
    }

    public function bookingDetail(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $booking = $this->bookings->findById($id);
        if ($booking === null) {
            http_response_code(404);
            View::render('errors/404', [], 404);
            return;
        }

        View::render('admin/booking_detail', [
            'title' => 'Booking ' . $booking['booking_ref'] . ' — Bidii Benz Rentals',
            'booking' => $booking,
            'balance' => $this->bookingService->getBookingBalance($id),
            'payments' => $this->paymentsForBooking($id),
        ]);
    }

    public function confirmBooking(array $params = []): void
    {
        $this->bookingAction($params, 'admin_booking_confirm', function (array $booking, int $actor): array {
            return $this->bookingService->confirmBooking((int) $booking['id'], $actor);
        }, 'Booking confirmed.');
    }

    public function startBooking(array $params = []): void
    {
        $this->bookingAction($params, 'admin_booking_start', function (array $booking, int $actor): array {
            return $this->bookingService->startBooking((int) $booking['id'], $actor);
        }, 'Hire started.');
    }

    public function cancelBooking(array $params = []): void
    {
        $this->bookingAction($params, 'admin_booking_cancel', function (array $booking, int $actor): array {
            return $this->bookingService->cancelBooking(
                (int) $booking['id'],
                $actor,
                (string) Guard::role()
            );
        }, 'Booking cancelled.');
    }

    public function completeBooking(array $params = []): void
    {
        $this->bookingAction($params, 'admin_booking_complete', function (array $booking, int $actor): array {
            // Staff may record when the vehicle was actually handed back
            // (default: now). The service validates the value.
            $input = Input::fromRequest();
            $raw = $input->string('actual_return_at', 19);
            return $this->bookingService->completeBooking(
                (int) $booking['id'],
                $actor,
                $raw === '' ? null : $raw
            );
        }, 'Vehicle return recorded — booking completed.');
    }

    // --- Reporting -----------------------------------------------------------

    /**
     * Date-range operational report (staff/owner, §8 authorization matrix).
     * An empty range reports all time; malformed or reversed ranges are
     * rejected and re-rendered with the submitted values intact.
     */
    public function reports(array $params = []): void
    {
        $input = Input::fromRequest();
        // Read longer than the format so an over-long value is rejected by
        // ReportRange instead of being silently truncated into a valid date.
        $rawFrom = $input->string('from', 30);
        $rawTo = $input->string('to', 30);

        $range = ReportRange::parse($rawFrom, $rawTo);
        $errors = $range['errors'];

        View::render('admin/reports', [
            'title' => 'Reports — Bidii Benz Rentals',
            'errors' => $errors,
            'from' => $range['from'],
            'to' => $range['to'],
            'raw_from' => $rawFrom,
            'raw_to' => $rawTo,
            'summary' => $errors === []
                ? (new ReportRepository($this->pdo))->summary($range['from'], $range['to'])
                : null,
        ]);
    }

    // --- Clients -------------------------------------------------------------

    public function clients(array $params = []): void
    {
        View::render('admin/clients', [
            'title' => 'Clients — Bidii Benz Rentals',
            'clients' => $this->users->findAllClients(),
        ]);
    }

    public function clientDetail(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $client = $this->users->findClient($id);
        if ($client === null) {
            http_response_code(404);
            View::render('errors/404', [], 404);
            return;
        }

        View::render('admin/client_detail', [
            'title' => $client['full_name'] . ' — Bidii Benz Rentals',
            'client' => $client,
            'bookings' => $this->bookings->findByClient($id),
            'payments' => $this->payments->findByClient($id),
        ]);
    }

    // --- Payments ------------------------------------------------------------

    public function payments(array $params = []): void
    {
        $status = null;
        if (!empty($_GET['status']) && is_string($_GET['status'])) {
            $candidate = substr($_GET['status'], 0, 30);
            if (in_array($candidate, ['pending', 'confirmed', 'failed', 'refunded'], true)) {
                $status = $candidate;
            }
        }

        View::render('admin/payments', [
            'title' => 'Payments — Bidii Benz Rentals',
            'payments' => $this->payments->findAll($status, 200),
            'filter' => $status,
            'outstanding' => $this->bookings->outstandingBalance(),
        ]);
    }

    public function confirmPayment(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if (!$this->csrfOk('admin_payment_confirm')) {
            return;
        }

        $receipt = Input::fromRequest()->string('receipt', 20);
        $actor = $this->actorId();
        $result = $receipt === ''
            ? ['ok' => false, 'error' => 'Enter the M-Pesa receipt or reference.']
            : $this->paymentService->manualConfirm($id, $receipt, $actor);

        if (!$result['ok']) {
            Session::flash('error', $result['error'] ?? 'Could not confirm the payment.');
        } else {
            $this->audit('payment.confirm', 'payments', $id, 'receipt=' . $receipt);
            Session::flash('success', 'Payment confirmed.');
        }
        redirect('/admin/payments');
    }

    public function refundPayment(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if (!$this->csrfOk('admin_payment_refund')) {
            return;
        }

        $notes = Input::fromRequest()->string('notes', 255);
        $result = $this->paymentService->refund($id, $this->actorId(), $notes);

        if (!$result['ok']) {
            Session::flash('error', $result['error'] ?? 'Could not refund the payment.');
        } else {
            $this->audit('payment.refund', 'payments', $id, $notes);
            Session::flash('success', 'Payment refunded.');
        }
        redirect('/admin/payments');
    }

    // --- Audit trail ----------------------------------------------------------

    /**
     * Read-only audit viewer: newest first, filterable by action and actor,
     * 50 rows per page. The route guard is 'staff' like the rest of the office.
     */
    public function auditLog(array $params = []): void
    {
        $action = null;
        if (!empty($_GET['action']) && is_string($_GET['action'])) {
            $candidate = substr($_GET['action'], 0, 60);
            if (preg_match('/^[A-Za-z0-9._-]+$/', $candidate) === 1) {
                $action = $candidate;
            }
        }

        $userId = null;
        if (!empty($_GET['user']) && is_string($_GET['user']) && ctype_digit($_GET['user'])) {
            $candidate = (int) $_GET['user'];
            if ($candidate > 0) {
                $userId = $candidate;
            }
        }

        $page = 1;
        if (!empty($_GET['page']) && is_string($_GET['page']) && ctype_digit($_GET['page'])) {
            $page = max(1, (int) $_GET['page']);
        }

        $total = $this->auditLogs->count($action, $userId);
        $pages = max(1, (int) ceil($total / AuditRepository::PER_PAGE));
        $page = min($page, $pages);

        View::render('admin/audit', [
            'title' => 'Audit trail — Bidii Benz Rentals',
            'entries' => $this->auditLogs->page($page, $action, $userId),
            'actions' => $this->auditLogs->distinctActions(),
            'actors' => $this->auditLogs->distinctActors(),
            'filterAction' => $action,
            'filterUser' => $userId,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Shared handler for the three booking status transitions.
     *
     * @param callable(array<string, mixed>, int):array{ok:bool, error?:string} $action
     */
    private function bookingAction(array $params, string $form, callable $action, string $success): void
    {
        $id = (int) ($params['id'] ?? 0);
        if (!$this->csrfOk($form)) {
            return;
        }

        $booking = $this->bookings->findById($id);
        if ($booking === null) {
            Session::flash('error', 'Booking not found.');
            redirect('/admin/bookings');
        }

        $actor = $this->actorId();
        $result = $action($booking, $actor);

        if (!$result['ok']) {
            Session::flash('error', $result['error'] ?? 'The booking could not be updated.');
            redirect('/admin/bookings/' . $id);
        }

        $this->audit('booking.' . substr($form, strrpos($form, '_') + 1), 'bookings', $id, $booking['booking_ref']);
        Session::flash('success', $success);
        redirect('/admin/bookings/' . $id);
    }

    /** @return array<int, array<string, mixed>> */
    private function paymentsForBooking(int $bookingId): array
    {
        return $this->payments->findAllForBooking($bookingId);
    }

    /** @return list<string> */
    private function bookingStatuses(): array
    {
        return [
            'pending_payment', 'confirmed', 'active',
            'completed', 'cancelled', 'no_show',
        ];
    }

    /**
     * The acting staff/owner id. Guards have already required a session, so a
     * null here only means the session vanished mid-request.
     */
    private function actorId(): int
    {
        $id = Guard::userId();
        if ($id === null) {
            redirect('/login');
        }
        return $id;
    }

    private function hasPhotoUpload(): bool
    {
        return isset($_FILES['photo'])
            && is_array($_FILES['photo'])
            && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function storePhoto(array &$errors, array &$old): ?string
    {
        if (!$this->hasPhotoUpload()) {
            return null;
        }
        $stored = $this->photos->store($_FILES['photo']);
        if (!$stored['ok']) {
            $errors['photo'] = $stored['error'] ?? 'The photo could not be uploaded.';
            return null;
        }
        return $stored['filename'];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    private function readVehicle(): array
    {
        $input = Input::fromRequest();

        $model = $input->string('model', 60);
        $plate = strtoupper($input->string('registration_plate', 20));
        $seats = $input->int('seats');
        $priceRaw = $input->string('daily_price', 12);
        $year = $input->int('year');
        $transmission = $input->string('transmission', 10);
        $fuel = $input->string('fuel_type', 10);
        $status = $input->string('status', 20);
        $bodyType = $input->string('body_type', 40);
        $description = $input->string('description', 2000);

        $errors = [];
        if ($model === '') {
            $errors['model'] = 'Model is required.';
        }
        if ($plate === '' || strlen($plate) > 20) {
            $errors['registration_plate'] = 'Registration plate is required.';
        }
        if ($seats === null || $seats < 1 || $seats > 20) {
            $errors['seats'] = 'Seats must be between 1 and 20.';
        }
        if (!is_numeric($priceRaw) || (float) $priceRaw <= 0) {
            $errors['daily_price'] = 'Daily price must be a positive number.';
        }
        if ($year !== null && ($year < 1980 || $year > (int) date('Y') + 1)) {
            $errors['year'] = 'Enter a valid year.';
        }
        if (!in_array($transmission, ['automatic', 'manual'], true)) {
            $errors['transmission'] = 'Choose a transmission type.';
        }
        if (!in_array($fuel, ['petrol', 'diesel', 'hybrid'], true)) {
            $errors['fuel_type'] = 'Choose a fuel type.';
        }
        if (!in_array($status, ['active', 'maintenance', 'retired'], true)) {
            $errors['status'] = 'Choose a valid status.';
        }

        $old = [
            'model' => $model,
            'registration_plate' => $plate,
            'seats' => $seats === null ? '' : (string) $seats,
            'daily_price' => $priceRaw,
            'year' => $year === null ? '' : (string) $year,
            'transmission' => $transmission,
            'fuel_type' => $fuel,
            'status' => $status,
            'body_type' => $bodyType,
            'description' => $description,
            'remove_photo' => isset($_POST['remove_photo']) ? '1' : '',
        ];

        $data = [
            'make' => 'Mercedes-Benz',
            'model' => $model,
            'year' => $year,
            'body_type' => $bodyType === '' ? null : $bodyType,
            'seats' => $seats ?? 5,
            'transmission' => $transmission,
            'fuel_type' => $fuel,
            'registration_plate' => $plate,
            'daily_price' => is_numeric($priceRaw) ? round((float) $priceRaw, 2) : 0.0,
            'status' => $status,
            'description' => $description === '' ? null : $description,
        ];

        return [$data, $errors, $old];
    }

    private function csrfOk(string $form): bool
    {
        if (Csrf::validate($form, $_POST['_csrf'] ?? null)) {
            return true;
        }
        http_response_code(403);
        View::render('errors/403', [], 403);
        exit;
    }

    private function audit(string $action, string $entity, int $entityId, ?string $detail): void
    {
        Audit::log(Guard::userId(), Guard::role(), $action, $entity, $entityId, $detail);
    }
}

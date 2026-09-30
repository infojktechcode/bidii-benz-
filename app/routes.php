<?php

declare(strict_types=1);

use App\Core\Router;

/** @var Router $router */

// Public
$router->get('/', [\App\Controllers\HomeController::class, 'index']);
$router->get('/privacy', [\App\Controllers\AuthController::class, 'privacy']);

// Cars (public browsing)
$router->get('/cars', [\App\Controllers\CarController::class, 'index']);
$router->get('/cars/{id}', [\App\Controllers\CarController::class, 'detail']);
$router->get('/media/cars/{id}', [\App\Controllers\CarController::class, 'media']);

// Authentication
$router->get('/login', [\App\Controllers\AuthController::class, 'showLogin']);
$router->post('/login', [\App\Controllers\AuthController::class, 'login']);
$router->get('/register', [\App\Controllers\AuthController::class, 'showRegister']);
$router->post('/register', [\App\Controllers\AuthController::class, 'register']);
$router->post('/logout', [\App\Controllers\AuthController::class, 'logout'], 'login');

// Client booking (requires login)
$router->get('/cars/{id}/book', [\App\Controllers\BookingController::class, 'showCreate'], 'client');
$router->post('/cars/{id}/book', [\App\Controllers\BookingController::class, 'create'], 'client');
$router->get('/booking/{ref}/confirm', [\App\Controllers\BookingController::class, 'confirm'], 'client');
$router->get('/bookings', [\App\Controllers\BookingController::class, 'history'], 'client');
$router->get('/bookings/{id}', [\App\Controllers\BookingController::class, 'detail'], 'client');
$router->post('/bookings/{id}/cancel', [\App\Controllers\BookingController::class, 'cancel'], 'client');

// Payment (client)
$router->get('/payment/{bookingId}', [\App\Controllers\PaymentController::class, 'show'], 'client');
$router->post('/payment/{bookingId}/initiate', [\App\Controllers\PaymentController::class, 'initiate'], 'client');
// M-Pesa callback: no session/CSRF because it is a machine caller.
// Authentication is the X-Callback-Token shared secret enforced in
// PaymentService::handleCallback(); rejected outright while MPESA_ENV=mock.
$router->post('/payment/callback', [\App\Controllers\PaymentController::class, 'callback']);

// Account self-service: profile edits are client-only; the password change
// is available to every authenticated role (current password + throttle).
$router->get('/account', [\App\Controllers\AccountController::class, 'show'], 'login');
$router->post('/account/profile', [\App\Controllers\AccountController::class, 'updateProfile'], 'client');
$router->post('/account/password', [\App\Controllers\AccountController::class, 'changePassword'], 'login');

// Admin (staff/owner)
$router->get('/admin', [\App\Controllers\AdminController::class, 'dashboard'], 'staff');
$router->get('/admin/vehicles', [\App\Controllers\AdminController::class, 'vehicles'], 'staff');
$router->get('/admin/vehicles/create', [\App\Controllers\AdminController::class, 'showCreateVehicle'], 'staff');
$router->post('/admin/vehicles', [\App\Controllers\AdminController::class, 'createVehicle'], 'staff');
$router->get('/admin/vehicles/{id}/edit', [\App\Controllers\AdminController::class, 'showEditVehicle'], 'staff');
$router->post('/admin/vehicles/{id}', [\App\Controllers\AdminController::class, 'updateVehicle'], 'staff');
$router->post('/admin/vehicles/{id}/delete', [\App\Controllers\AdminController::class, 'deleteVehicle'], 'staff');
$router->get('/admin/bookings', [\App\Controllers\AdminController::class, 'bookings'], 'staff');
$router->get('/admin/bookings/{id}', [\App\Controllers\AdminController::class, 'bookingDetail'], 'staff');
$router->post('/admin/bookings/{id}/confirm', [\App\Controllers\AdminController::class, 'confirmBooking'], 'staff');
$router->post('/admin/bookings/{id}/start', [\App\Controllers\AdminController::class, 'startBooking'], 'staff');
$router->post('/admin/bookings/{id}/cancel', [\App\Controllers\AdminController::class, 'cancelBooking'], 'staff');
$router->post('/admin/bookings/{id}/complete', [\App\Controllers\AdminController::class, 'completeBooking'], 'staff');
$router->get('/admin/clients', [\App\Controllers\AdminController::class, 'clients'], 'staff');
$router->get('/admin/clients/{id}', [\App\Controllers\AdminController::class, 'clientDetail'], 'staff');
$router->get('/admin/payments', [\App\Controllers\AdminController::class, 'payments'], 'staff');
$router->post('/admin/payments/{id}/confirm', [\App\Controllers\AdminController::class, 'confirmPayment'], 'staff');
$router->post('/admin/payments/{id}/refund', [\App\Controllers\AdminController::class, 'refundPayment'], 'owner');
$router->get('/admin/reports', [\App\Controllers\AdminController::class, 'reports'], 'staff');
// Read-only audit viewer. Refunds are money-moving: owner-only (see above).
$router->get('/admin/audit', [\App\Controllers\AdminController::class, 'auditLog'], 'staff');

$router->setNotFound(static function (): void {
    http_response_code(404);
    \App\Core\View::render('errors/404', [], 404);
});
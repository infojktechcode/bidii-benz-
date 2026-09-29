<?php

declare(strict_types=1);

use App\Core\Router;

/** @var Router $router */

// Public
$router->get('/', [\App\Controllers\HomeController::class, 'index']);
$router->get('/privacy', [\App\Controllers\AuthController::class, 'privacy']);

// Authentication
$router->get('/login', [\App\Controllers\AuthController::class, 'showLogin']);
$router->post('/login', [\App\Controllers\AuthController::class, 'login']);
$router->get('/register', [\App\Controllers\AuthController::class, 'showRegister']);
$router->post('/register', [\App\Controllers\AuthController::class, 'register']);
$router->post('/logout', [\App\Controllers\AuthController::class, 'logout'], 'login');

$router->setNotFound(static function (): void {
    http_response_code(404);
    \App\Core\View::render('errors/404', [], 404);
});

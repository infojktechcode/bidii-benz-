<?php

declare(strict_types=1);

/**
 * Application routes.
 * Format: $router->get|post(pattern, [Controller::class, method], guard)
 * Guards: null | 'login' | 'client' | 'staff' | 'owner' | 'any_auth'
 */

use App\Core\Router;

/** @var Router $router */

$router->get('/', [\App\Controllers\HomeController::class, 'index']);

$router->setNotFound(static function (): void {
    http_response_code(404);
    \App\Core\View::render('errors/404', [], 404);
});

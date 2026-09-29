<?php

declare(strict_types=1);

/**
 * Front controller. All HTTP traffic enters here.
 * Docroot for production MUST be the public/ directory.
 */

require dirname(__DIR__) . '/app/autoload.php';

use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\Log;
use App\Core\Router;

$projectRoot = dirname(__DIR__);

[$config, $pdo] = Bootstrap::boot($projectRoot, false);

// Security headers on every response
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header_remove('X-Powered-By');
if ($config->isProduction()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'");
}

// HTTPS enforcement (production only; local XAMPP has no TLS)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($config->isProduction() && !$isHttps) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    header('Location: https://' . $host . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

// Error handling per environment
if ($config->get('app_debug') && !$config->isProduction()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

set_exception_handler(static function (Throwable $e): void {
    Log::error('Unhandled exception: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(500);
    $env = getenv('APP_ENV') ?: 'development';
    if ($env === 'development') {
        echo '<h1>Application error</h1><pre>' . htmlspecialchars(
            $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine(),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) . '</pre>';
    } else {
        echo '<h1>Something went wrong</h1><p>Please try again later.</p>';
    }
    exit;
});

// Method spoofing support for HTML forms (DELETE/PUT not used, but POST-only enforced)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = parse_url($config->get('app_url', ''), PHP_URL_PATH) ?: '';
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath)) ?: '/';
}

$router = new Router();
require dirname(__DIR__) . '/app/routes.php';

$router->dispatch($method, $path);

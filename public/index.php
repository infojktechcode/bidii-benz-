<?php

declare(strict_types=1);

/**
 * Front controller. All HTTP traffic enters here.
 * Docroot for production MUST be the public/ directory.
 */

require dirname(__DIR__) . '/app/autoload.php';

use App\Core\Bootstrap;
use App\Core\Config;
use App\Core\Database;
use App\Core\Guard;
use App\Core\Log;
use App\Core\Router;

$projectRoot = dirname(__DIR__);

[$config, $pdo] = Bootstrap::boot($projectRoot, false);

// The session caches role/status from login time. Re-check the account on
// every request so a suspended, deleted or demoted user loses access on the
// next request instead of when the cookie expires.
if (Guard::userId() !== null) {
    Guard::revalidateSession(Database::connect($config));
}

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
    // SEC-04: never echo the request Host header back into a redirect — a
    // forged Host would otherwise make this an open-redirect / cache-poisoning
    // vector. The canonical authority comes from the configured APP_URL; if
    // that is unusable the redirect is skipped entirely (fail closed on the
    // header, rather than trusting the attacker-supplied one).
    $appUrl = (string) $config->get('app_url', '');
    $host = parse_url($appUrl, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        $port = parse_url($appUrl, PHP_URL_PORT);
        $authority = $host . (is_int($port) && $port !== 443 ? ':' . $port : '');
        header('Location: https://' . $authority . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
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

set_exception_handler(static function (Throwable $e) use ($config): void {
    Log::error('Unhandled exception: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(500);
    // Same policy as display_errors above: the configured app_env decides,
    // never the raw process environment (which may be unset or overridden).
    // Failing closed keeps stack traces off the page whenever config is odd.
    $showDetails = (bool) $config->get('app_debug') && !$config->isProduction();
    if ($showDetails) {
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
\App\Core\View::setBasePath($basePath);

$router = new Router();
require dirname(__DIR__) . '/app/routes.php';

$router->dispatch($method, $path);

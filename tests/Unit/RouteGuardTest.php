<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * Route registry contract:
 *  - every route resolves to a real controller method, and
 *  - every route is guarded server-side unless it is explicitly public.
 *
 * Frontend hiding of a link is never access control, so an unguarded route
 * (other than the documented public ones) is a security defect.
 */
final class RouteGuardTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * Routes that must remain reachable without a session, with the reason.
     *
     * @var array<string, string>
     */
    private const PUBLIC_ROUTES = [
        'GET /' => 'landing page',
        'GET /privacy' => 'statutory notice',
        'GET /cars' => 'public fleet browsing',
        'GET /cars/{id}' => 'public vehicle detail',
        'GET /media/cars/{id}' => 'public vehicle photo',
        'GET /login' => 'sign-in form',
        'POST /login' => 'sign-in submit (CSRF protected)',
        'GET /register' => 'registration form',
        'POST /register' => 'registration submit (CSRF protected)',
        'POST /payment/callback' => 'M-Pesa machine callback (shared-secret protected)',
    ];

    /** @var list<array{method:string, pattern:string, handler:mixed, guard:?string}>|null */
    private static ?array $routes = null;

    /** @return list<array{method:string, pattern:string, handler:mixed, guard:?string}> */
    private static function routes(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }

        $router = new Router();
        require self::ROOT . '/app/routes.php';

        $property = new \ReflectionProperty(Router::class, 'routes');
        $property->setAccessible(true);
        self::$routes = $property->getValue($router);

        return self::$routes;
    }

    public function testRoutesWereRegistered(): void
    {
        $routes = self::routes();
        self::assertGreaterThan(20, count($routes), 'expected the full route table');
    }

    public function testEveryRouteResolvesToAnExistingControllerMethod(): void
    {
        foreach (self::routes() as $route) {
            $handler = $route['handler'];
            if (!is_array($handler)) {
                continue;
            }
            [$class, $action] = $handler;
            $label = $route['method'] . ' ' . $route['pattern'];

            self::assertTrue(class_exists($class), "{$label}: class {$class} does not exist");
            self::assertTrue(
                method_exists($class, $action),
                "{$label}: {$class}::{$action}() does not exist"
            );
        }
    }

    public function testEveryNonPublicRouteIsGuarded(): void
    {
        $offenders = [];
        foreach (self::routes() as $route) {
            $label = $route['method'] . ' ' . $route['pattern'];
            if ($route['guard'] !== null) {
                continue;
            }
            if (isset(self::PUBLIC_ROUTES[$label])) {
                continue;
            }
            $offenders[] = $label;
        }

        self::assertSame(
            [],
            $offenders,
            'these routes have no server-side guard: ' . implode(', ', $offenders)
        );
    }

    public function testEveryGuardValueIsValid(): void
    {
        $allowed = ['login', 'client', 'staff', 'owner', 'any_auth'];
        foreach (self::routes() as $route) {
            if ($route['guard'] === null) {
                continue;
            }
            self::assertContains(
                $route['guard'],
                $allowed,
                ($route['method'] . ' ' . $route['pattern']) . ' uses unknown guard ' . $route['guard']
            );
        }
    }

    public function testAdminRoutesAreStaffGuarded(): void
    {
        $admin = array_filter(
            self::routes(),
            static fn (array $r): bool => str_starts_with($r['pattern'], '/admin')
        );
        self::assertNotEmpty($admin, 'admin routes must exist');
        foreach ($admin as $route) {
            // 'staff' is the office baseline (owner satisfies it). A route may
            // demand 'owner' outright for money-moving actions (refunds); it
            // must never fall back to a weaker guard or none.
            self::assertContains(
                $route['guard'],
                ['staff', 'owner'],
                $route['method'] . ' ' . $route['pattern'] . ' must require the staff guard (owner allowed for owner-only actions)'
            );
        }
    }

    public function testPublicAllowListMatchesActualUnguardedRoutes(): void
    {
        $unguarded = [];
        foreach (self::routes() as $route) {
            if ($route['guard'] === null) {
                $unguarded[] = $route['method'] . ' ' . $route['pattern'];
            }
        }
        sort($unguarded);
        $expected = array_keys(self::PUBLIC_ROUTES);
        sort($expected);

        self::assertSame(
            $expected,
            $unguarded,
            'the documented public route list must exactly match reality'
        );
    }
}

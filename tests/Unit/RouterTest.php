<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesToCallableWithParams(): void
    {
        $router = new Router();
        $seen = null;
        $router->get('/cars/{id}', function (array $params) use (&$seen): void {
            $seen = $params;
        });
        $router->dispatch('GET', '/cars/42');
        self::assertSame(['id' => '42'], $seen);
    }

    public function testTrailingSlashIsNormalised(): void
    {
        $router = new Router();
        $hit = false;
        $router->get('/cars', function () use (&$hit): void {
            $hit = true;
        });
        $router->dispatch('GET', '/cars/');
        self::assertTrue($hit);
    }

    public function testWrongMethodDoesNotMatch(): void
    {
        $router = new Router();
        $hit = false;
        $router->get('/book', function () use (&$hit): void {
            $hit = true;
        });
        $router->dispatch('POST', '/book');
        self::assertFalse($hit, 'GET route must not answer POST');
    }

    public function testUnknownPathTriggersNotFoundHandler(): void
    {
        $router = new Router();
        $notFound = false;
        $router->setNotFound(function () use (&$notFound): void {
            $notFound = true;
        });
        $router->dispatch('GET', '/no/such/page');
        self::assertTrue($notFound);
        self::assertSame(404, http_response_code());
        http_response_code(200);
    }

    public function testControllerArrayHandlerIsInvoked(): void
    {
        $router = new Router();
        $router->get('/home', [\Tests\Support\FakeController::class, 'run']);
        $router->dispatch('GET', '/home');
        self::assertSame('ran', \Tests\Support\FakeController::$last);
        \Tests\Support\FakeController::$last = null;
    }

    public function testPatternDoesNotMatchDifferentSegments(): void
    {
        $router = new Router();
        $hit = false;
        $router->get('/cars/{id}', function () use (&$hit): void {
            $hit = true;
        });
        $router->dispatch('GET', '/cars/42/extra');
        self::assertFalse($hit);
    }
}

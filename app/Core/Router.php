<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal method+path router with {param} support.
 * Route handlers are [ControllerClass, method] callables.
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,handler:mixed,guard:?string}> */
    private array $routes = [];

    /** @var callable|null */
    private $notFound = null;

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param string|null $guard 'login' | 'staff' | 'owner' | 'client' | null
     */
    public function add(string $method, string $pattern, callable|array $handler, ?string $guard = null): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'guard' => $guard,
        ];
    }

    public function get(string $pattern, callable|array $handler, ?string $guard = null): void
    {
        $this->add('GET', $pattern, $handler, $guard);
    }

    public function post(string $pattern, callable|array $handler, ?string $guard = null): void
    {
        $this->add('POST', $pattern, $handler, $guard);
    }

    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $path = rtrim($path, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['pattern']);
            $regex = '#^' . $regex . '$#';
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            $this->applyGuard($route['guard']);

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                $controller = new $class();
                $controller->$action($params);
            } else {
                $handler($params);
            }
            return;
        }

        http_response_code(404);
        if ($this->notFound !== null) {
            ($this->notFound)();
        }
    }

    private function applyGuard(?string $guard): void
    {
        switch ($guard) {
            case 'login':
                Guard::requireLogin();
                break;
            case 'client':
                Guard::requireRole(Guard::ROLE_CLIENT);
                break;
            case 'staff':
                Guard::requireAtLeast(Guard::ROLE_STAFF);
                break;
            case 'owner':
                Guard::requireRole(Guard::ROLE_OWNER);
                break;
            case 'any_auth':
                Guard::requireLogin();
                break;
        }
    }
}

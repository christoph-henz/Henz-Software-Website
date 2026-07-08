<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Exceptions\MethodNotAllowedHttpException;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Http\Request;

final class Router
{
    /** @var array<int, Route> */
    private array $routes = [];

    /** @var array<int, array{prefix: string, middleware: array<int, string>}> */
    private array $groupStack = [];

    private ?Route $fallback = null;

    public function get(string $path, array|callable $handler): RouteRegistrar
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, array|callable $handler): RouteRegistrar
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, array|callable $handler): RouteRegistrar
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, array|callable $handler): RouteRegistrar
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, array|callable $handler): RouteRegistrar
    {
        return $this->add('DELETE', $path, $handler);
    }

    /** @param callable(self): void $callback */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $groupPrefix = $this->normalizePath($prefix);
        $parent = end($this->groupStack);
        $parentPrefix = $parent['prefix'] ?? '';
        $parentMiddleware = $parent['middleware'] ?? [];

        $this->groupStack[] = [
            'prefix' => rtrim($parentPrefix . $groupPrefix, '/'),
            'middleware' => array_merge($parentMiddleware, $middleware),
        ];

        $callback($this);
        array_pop($this->groupStack);
    }

    public function fallback(array|callable $handler): void
    {
        $this->fallback = new Route('FALLBACK', '/{any}', $handler);
    }

    public function dispatch(Request $request): MatchedRoute
    {
        $path = $this->normalizePath($request->path());
        $method = $request->method();

        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $this->matchPath($route->path, $path);
            if ($params === null) {
                continue;
            }

            if ($route->method !== $method) {
                $allowedMethods[] = $route->method;
                continue;
            }

            return new MatchedRoute($route, $params);
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedHttpException(array_values(array_unique($allowedMethods)));
        }

        if ($this->fallback !== null) {
            return new MatchedRoute($this->fallback, []);
        }

        throw new NotFoundHttpException();
    }

    /** @return array<int, Route> */
    public function all(): array
    {
        return $this->routes;
    }

    public function pathFor(string $name, array $params = []): ?string
    {
        foreach ($this->routes as $route) {
            if ($route->name !== $name) {
                continue;
            }

            $path = $route->path;
            foreach ($params as $key => $value) {
                $path = str_replace('{' . $key . '}', (string) $value, $path);
            }

            return $path;
        }

        return null;
    }

    private function add(string $method, string $path, array|callable $handler): RouteRegistrar
    {
        $group = end($this->groupStack);
        $prefix = $group['prefix'] ?? '';
        $groupMiddleware = $group['middleware'] ?? [];

        $route = new Route(
            method: $method,
            path: $this->normalizePath($prefix . '/' . ltrim($path, '/')),
            handler: $handler,
            middleware: $groupMiddleware,
        );

        $this->routes[] = $route;

        return new RouteRegistrar($this, $route, count($this->routes) - 1);
    }

    /** @param array<int, string> $middleware */
    public function mutateRoute(int $index, array $middleware, ?string $name): void
    {
        $existing = $this->routes[$index];
        $this->routes[$index] = new Route(
            method: $existing->method,
            path: $existing->path,
            handler: $existing->handler,
            middleware: array_values(array_unique(array_merge($existing->middleware, $middleware))),
            name: $name ?? $existing->name,
        );
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @return array<string, string>|null */
    private function matchPath(string $routePath, string $requestPath): ?array
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_-]*)\}#', '(?P<$1>[^/]+)', $routePath);
        if ($pattern === null) {
            return null;
        }

        if (!preg_match('#^' . $pattern . '$#', $requestPath, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}

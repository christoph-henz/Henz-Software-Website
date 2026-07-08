<?php

declare(strict_types=1);

namespace App\Core\Routing;

final class RouterFacade
{
    private static ?Router $router = null;

    public static function setRouter(Router $router): void
    {
        self::$router = $router;
    }

    public static function get(string $path, array|callable $handler): RouteRegistrar
    {
        return self::router()->get($path, $handler);
    }

    public static function post(string $path, array|callable $handler): RouteRegistrar
    {
        return self::router()->post($path, $handler);
    }

    public static function put(string $path, array|callable $handler): RouteRegistrar
    {
        return self::router()->put($path, $handler);
    }

    public static function patch(string $path, array|callable $handler): RouteRegistrar
    {
        return self::router()->patch($path, $handler);
    }

    public static function delete(string $path, array|callable $handler): RouteRegistrar
    {
        return self::router()->delete($path, $handler);
    }

    /** @param callable(Router): void $callback */
    public static function group(string $prefix, callable $callback, array $middleware = []): void
    {
        self::router()->group($prefix, $callback, $middleware);
    }

    public static function fallback(array|callable $handler): void
    {
        self::router()->fallback($handler);
    }

    private static function router(): Router
    {
        if (self::$router === null) {
            throw new \RuntimeException('Router facade is not initialized');
        }

        return self::$router;
    }
}

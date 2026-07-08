<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Kernel
{
    /** @var array<int, class-string> */
    private array $globalMiddleware = [];

    /** @var array<string, class-string> */
    private array $namedMiddleware = [];

    /** @param array<int, class-string> $middleware */
    public function setGlobal(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    public function alias(string $key, string $middlewareClass): void
    {
        $this->namedMiddleware[$key] = $middlewareClass;
    }

    /** @return array<int, class-string> */
    public function globalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /** @return array<string, class-string> */
    public function aliases(): array
    {
        return $this->namedMiddleware;
    }

    /** @param array<int, string> $routeMiddleware */
    public function resolveRouteMiddleware(array $routeMiddleware): array
    {
        return array_map(function (string $middleware): string {
            return $this->namedMiddleware[$middleware] ?? $middleware;
        }, $routeMiddleware);
    }
}

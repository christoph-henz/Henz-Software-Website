<?php

declare(strict_types=1);

namespace App\Core\Routing;

final class Route
{
    /**
     * @param array{0: class-string, 1: string}|\Closure $handler
     * @param array<int, string> $middleware
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly array $middleware = [],
        public readonly ?string $name = null,
    ) {
    }
}

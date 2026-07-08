<?php

declare(strict_types=1);

namespace App\Core\Routing;

final class RouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly Route $route,
        private readonly int $index,
    ) {
    }

    /** @param string|array<int, string> $middleware */
    public function middleware(string|array $middleware): self
    {
        $list = is_array($middleware) ? $middleware : [$middleware];
        $this->router->mutateRoute($this->index, $list, null);
        return $this;
    }

    public function name(string $name): self
    {
        $this->router->mutateRoute($this->index, [], $name);
        return $this;
    }

    public function route(): Route
    {
        return $this->route;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Http\Response;
use RuntimeException;

final class MiddlewarePipeline
{
    /** @param array<int, string|MiddlewareInterface> $middleware */
    public function __construct(private readonly Container $container, private readonly array $middleware)
    {
    }

    /** @param callable(Request): Response $destination */
    public function process(Request $request, callable $destination): Response
    {
        $next = array_reduce(
            array_reverse($this->middleware),
            function (callable $next, string|MiddlewareInterface $middleware): callable {
                return function (Request $request) use ($next, $middleware): Response {
                    $instance = is_string($middleware) ? $this->container->get($middleware) : $middleware;

                    if (!$instance instanceof MiddlewareInterface) {
                        throw new RuntimeException('Middleware must implement MiddlewareInterface');
                    }

                    return $instance->handle($request, $next);
                };
            },
            $destination
        );

        return $next($request);
    }
}

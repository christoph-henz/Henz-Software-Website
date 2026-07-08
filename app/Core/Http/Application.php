<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Container\Container;
use App\Core\Middleware\MiddlewarePipeline;
use App\Core\Routing\MatchedRoute;
use App\Core\Routing\Router;
use RuntimeException;

final class Application
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Kernel $kernel,
    ) {
    }

    public function handle(Request $request): Response
    {
        $requestId = $this->resolveRequestId($request);
        $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
        $request = $request->withAttribute('request_id', $requestId);

        // Handle CORS preflight OPTIONS requests immediately
        if ($request->method() === 'OPTIONS') {
            $origins = config('cors.allowed_origins', ['*']);
            $methods = config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
            $headers = config('cors.allowed_headers', ['Content-Type', 'Authorization']);
            $exposedHeaders = config('cors.exposed_headers', ['X-Request-Id']);

            $origin = (string) $request->header('Origin', '*');
            $allowOrigin = in_array('*', $origins, true) || in_array($origin, $origins, true) ? $origin : 'null';

            return Response::noContent()
                ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
                ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
                ->withHeader('Access-Control-Allow-Headers', implode(', ', $headers))
                ->withHeader('Access-Control-Expose-Headers', implode(', ', $exposedHeaders))
                ->withHeader('Access-Control-Max-Age', '3600')
                ->withHeader('X-Request-Id', $requestId);
        }

        $matched = $this->router->dispatch($request);

        $routeMiddleware = $this->kernel->resolveRouteMiddleware($matched->route->middleware);
        $middlewareStack = [...$this->kernel->globalMiddleware(), ...$routeMiddleware];

        $pipeline = new MiddlewarePipeline($this->container, $middlewareStack);

        $response = $pipeline->process(
            $this->injectRouteParams($request, $matched),
            fn (Request $request): Response => $this->runHandler($request, $matched)
        );

        return $response->withHeader('X-Request-Id', $requestId);
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function runHandler(Request $request, MatchedRoute $matched): Response
    {
        $handler = $matched->route->handler;

        if (is_callable($handler)) {
            $result = $handler($request);
            if (!$result instanceof Response) {
                throw new RuntimeException('Route closure must return Response');
            }
            return $result;
        }

        [$class, $method] = $handler;
        $controller = $this->container->get($class);
        $result = $controller->{$method}($request);

        if (!$result instanceof Response) {
            throw new RuntimeException(sprintf('Controller %s::%s must return Response', $class, $method));
        }

        return $result;
    }

    private function injectRouteParams(Request $request, MatchedRoute $matched): Request
    {
        $withParams = $request;
        foreach ($matched->params as $key => $value) {
            $withParams = $withParams->withAttribute($key, $value);
        }

        return $withParams;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = trim((string) $request->header('x-request-id', ''));
        if ($incoming !== '') {
            $sanitized = preg_replace('/[^A-Za-z0-9\-_.]/', '', $incoming) ?? '';
            if ($sanitized !== '') {
                return substr($sanitized, 0, 100);
            }
        }

        try {
            $hex = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $hex = bin2hex((string) microtime(true) . (string) mt_rand());
            $hex = substr($hex, 0, 32);
        }

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

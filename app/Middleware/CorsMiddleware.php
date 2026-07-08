<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $origins = config('cors.allowed_origins', ['*']);
        $methods = config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $headers = config('cors.allowed_headers', ['Content-Type', 'Authorization']);
        $exposedHeaders = config('cors.exposed_headers', ['X-Request-Id']);

        $origin = (string) $request->header('Origin', '*');
        $allowOrigin = in_array('*', $origins, true) || in_array($origin, $origins, true) ? $origin : 'null';

        // Handle CORS preflight requests (OPTIONS) immediately
        if ($request->method() === 'OPTIONS') {
            return Response::noContent()
                ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
                ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
                ->withHeader('Access-Control-Allow-Headers', implode(', ', $headers))
                ->withHeader('Access-Control-Expose-Headers', implode(', ', $exposedHeaders))
                ->withHeader('Access-Control-Max-Age', '3600');
        }

        return $next($request)
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $headers))
            ->withHeader('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
    }
}

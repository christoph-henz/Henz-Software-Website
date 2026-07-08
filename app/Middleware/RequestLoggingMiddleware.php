<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Exceptions\HttpException;
use App\Core\Logging\Logger;
use App\Core\Middleware\MiddlewareInterface;
use Throwable;

final class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $start = microtime(true);
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = $exception instanceof HttpException ? $exception->statusCode() : 500;

            $this->logger->warning('request', [
                'request_id' => (string) $request->attribute('request_id', ''),
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $status,
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $this->logger->info('request', [
            'request_id' => (string) $request->attribute('request_id', ''),
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->statusCode(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

        return $response;
    }
}

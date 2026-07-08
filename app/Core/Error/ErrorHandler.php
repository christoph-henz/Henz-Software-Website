<?php

declare(strict_types=1);

namespace App\Core\Error;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationHttpException;
use App\Core\Http\Response;
use App\Core\Logging\Logger;
use Throwable;

final class ErrorHandler
{
    public function __construct(
        private readonly bool $debug,
        private readonly Logger $logger,
    ) {
    }

    public function register(): void
    {
        set_exception_handler(function (Throwable $exception): void {
            $requestId = $this->currentRequestId();

            $this->logger->error($exception->getMessage(), [
                'request_id' => $requestId,
                'exception' => $exception::class,
                'trace' => $this->debug ? $exception->getTraceAsString() : null,
            ]);

            $response = $this->renderException($exception);
            $response->send();
        });
    }

    public function renderException(Throwable $exception): Response
    {
        $requestId = $this->currentRequestId();

        if ($exception instanceof HttpException) {
            $payload = [
                'success' => false,
                'error' => true,
                'message' => $exception->getMessage(),
                'errors' => [],
            ];

            if ($exception instanceof ValidationHttpException) {
                $payload['errors'] = $exception->errors();
            }

            $payload['request_id'] = $requestId;

            return $this->withApiCorsHeaders(
                Response::json($payload, $exception->statusCode(), $exception->headers())
                    ->withHeader('X-Request-Id', $requestId)
            );
        }

        if ($this->shouldRenderHtmlError()) {
            return $this->renderHtmlErrorResponse($exception);
        }

        $payload = [
            'success' => false,
            'error' => true,
            'message' => $this->debug ? $exception->getMessage() : 'Internal server error',
            'errors' => [],
            'request_id' => $requestId,
        ];

        if ($this->debug) {
            $payload['trace'] = explode(PHP_EOL, $exception->getTraceAsString());
        }

        return $this->withApiCorsHeaders(
            Response::json($payload, 500)
                ->withHeader('X-Request-Id', $requestId)
        );
    }

    private function shouldRenderHtmlError(): bool
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        if ($path === '') {
            return true;
        }

        if ($host !== '' && str_starts_with($host, 'api.')) {
            return false;
        }

        if (str_starts_with($path, '/api')) {
            return false;
        }

        if (str_contains($accept, 'application/json')) {
            return false;
        }

        if (str_ends_with($path, '.json')) {
            return false;
        }

        return true;
    }

    private function renderHtmlErrorResponse(Throwable $exception): Response
    {
        $requestId = $this->currentRequestId();

        if (!function_exists('base_path')) {
            return Response::json([
                'error' => true,
                'message' => $this->debug ? $exception->getMessage() : 'Internal server error',
            ], 500)->withHeader('X-Request-Id', $requestId);
        }

        $template = base_path('public/ui/_templates/error-page.php');
        if (!is_file($template)) {
            return Response::json([
                'error' => true,
                'message' => $this->debug ? $exception->getMessage() : 'Internal server error',
            ], 500)->withHeader('X-Request-Id', $requestId);
        }

        $code = 500;
        $title = 'Interner Fehler';
        $message = $this->debug
            ? $exception->getMessage()
            : 'Die Anfrage konnte nicht verarbeitet werden. Bitte versuche es später erneut.';
        $supportRequestId = $requestId;
        $hints = [
            'Die Fehlersituation wurde protokolliert.',
            'Prüfe bei Entwicklungsfehlern die Logs in storage/logs.',
        ];

        ob_start();
        require $template;
        $html = (string) ob_get_clean();

        return new Response($html, 500, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Request-Id' => $requestId,
        ]);
    }

    private function currentRequestId(): string
    {
        $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        return $requestId !== '' ? $requestId : 'n/a';
    }

    private function withApiCorsHeaders(Response $response): Response
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && !str_starts_with($host, 'api.')) {
            return $response;
        }

        $origins = config('cors.allowed_origins', ['*']);
        $methods = config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $headers = config('cors.allowed_headers', ['Content-Type', 'Authorization']);
        $exposedHeaders = config('cors.exposed_headers', ['X-Request-Id']);

        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '*');
        $allowOrigin = in_array('*', $origins, true) || in_array($origin, $origins, true) ? $origin : 'null';

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $headers))
            ->withHeader('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
    }
}

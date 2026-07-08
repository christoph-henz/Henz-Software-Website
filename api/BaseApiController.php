<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Exceptions\ValidationHttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Logging\Logger;

abstract class BaseApiController
{
    protected function ok(array $data, int $status = 200): Response
    {
        $this->logApiResponse($status, 'ok');

        return Response::json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    protected function fail(string $message, int $status = 400, array $errors = []): Response
    {
        $this->logApiResponse($status, 'fail', [
            'message' => $message,
            'error_keys' => array_keys($errors),
        ]);

        $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));

        return Response::json([
            'success' => false,
            'error' => true,
            'message' => $message,
            'errors' => $errors,
            'request_id' => $requestId !== '' ? $requestId : 'n/a',
        ], $status);
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules): array
    {
        $data = $request->all();
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleset = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($ruleset as $rule) {
                if ($rule === 'required') {
                    $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);
                    if ($isEmpty) {
                        $errors[$field][] = 'required';
                    }
                }

                if ($rule === 'email' && $value !== null && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = 'email';
                }
            }
        }

        if ($errors !== []) {
            $this->logApiResponse(422, 'validation_failed', [
                'message' => 'Validation failed',
                'error_keys' => array_keys($errors),
            ]);

            throw new ValidationHttpException($errors);
        }

        return $data;
    }

    private function logApiResponse(int $status, string $type, array $context = []): void
    {
        $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        $baseContext = [
            'request_id' => $requestId !== '' ? $requestId : 'n/a',
            'status' => $status,
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            'path' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
            'type' => $type,
        ];

        $logger = app(Logger::class);
        if (!$logger instanceof Logger) {
            return;
        }

        $merged = array_merge($baseContext, $context);
        if ($status >= 500) {
            $logger->error('api.response', $merged);
            return;
        }

        if ($status >= 400) {
            $logger->warning('api.response', $merged);
            return;
        }

        $logger->info('api.response', $merged);
    }
}

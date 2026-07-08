<?php

declare(strict_types=1);

use App\Core\Http\Request;

// API needs db()/app()/config() helpers from app/Support.
require_once dirname(__DIR__) . '/app/Core/Support/helpers.php';

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

[$container, $app, $errorHandler] = require dirname(__DIR__) . '/bootstrap/api.php';

$request = Request::capture();

try {
    $response = $app->handle($request);
} catch (Throwable $exception) {
    $response = $errorHandler->renderException($exception);
}

$response->send();

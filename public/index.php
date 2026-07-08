<?php

declare(strict_types=1);

use App\Core\Http\Request;

require_once dirname(__DIR__) . '/app/Core/Support/helpers.php';

[$container, $app, $errorHandler] = require dirname(__DIR__) . '/bootstrap/app.php';

$request = Request::capture();

try {
    $response = $app->handle($request);
} catch (Throwable $exception) {
    $response = $errorHandler->renderException($exception);
}

$response->send();

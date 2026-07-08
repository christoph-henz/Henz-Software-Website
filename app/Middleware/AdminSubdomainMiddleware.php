<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Support\OperationHost;

final class AdminSubdomainMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!OperationHost::isOperationHost($request)) {
            return Response::redirect(OperationHost::buildOperationUrl($request, OperationHost::currentPathWithQuery($request)), 302);
        }

        return $next($request);
    }
}

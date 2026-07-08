<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $token = (string) $request->header('Authorization', '');

        if ($token === '') {
            throw new HttpException('Unauthorized', 401);
        }

        return $next($request);
    }
}

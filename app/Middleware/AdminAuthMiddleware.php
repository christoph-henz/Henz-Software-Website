<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (!is_array($adminUser) || $adminUser === []) {

            $loginPath = (string) config('admin.login_path', '/login');

            $path = $request->path();

            if ($path !== '/login') {
                return Response::redirect(
                    $loginPath . '?redirect=' . rawurlencode($path)
                );
            }

            return $next($request);
        }

        return $next($request);
    }
}
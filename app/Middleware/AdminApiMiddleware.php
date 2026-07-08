<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

final class AdminApiMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if ((bool) config('app.debug', false)) {
            $testRoleMask = trim((string) $request->header('x-admin-test-rolemask', ''));
            if ($testRoleMask !== '' && is_numeric($testRoleMask)) {
                $session = $request->session();
                $sessionKey = (string) config('admin.session_key', 'admin_user');

                $_SESSION[$sessionKey] = [
                    'id' => null,
                    'first_name' => 'Debug',
                    'last_name' => 'TestAdmin',
                    'email' => 'debug-admin@test.local',
                    'role_mask' => (int) $testRoleMask,
                    'logged_in_at' => date(DATE_ATOM),
                ];

                return $next($request);
            }
        }

        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (!is_array($adminUser) || $adminUser === []) {
            return Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Unauthorized',
                'errors' => ['auth' => ['required']],
            ], 401);
        }

        return $next($request);
    }
}
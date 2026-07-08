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
            $path = rtrim((string) $request->path(), '/');
            if ($path === '') {
                $path = '/';
            }

            if ($path !== '/admin') {
                $loginPath = (string) config('admin.login_path', '/login');
                $redirectTarget = rawurlencode((string) $request->path());

                return Response::redirect($loginPath . '?redirect=' . $redirectTarget);
            }

            $code = 404;
            $httpStatus = 404;
            $title = 'Seite nicht gefunden';
            $message = 'Die angeforderte Seite existiert nicht oder wurde verschoben.';
            $hints = [
                'Pruefe die URL auf Schreibfehler.',
                'Nutze die Navigation für gueltige Bereiche.',
                'Starte bei Bedarf auf der Startseite neu.',
            ];

            ob_start();
            require base_path('public/ui/_templates/error-page.php');
            $html = (string) ob_get_clean();

            return new Response($html, 404, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return $next($request);
    }
}
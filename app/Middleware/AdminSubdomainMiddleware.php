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
            $code = 404;
            $httpStatus = 404;
            $title = 'Seite nicht gefunden';
            $message = 'Diese Route ist nur auf der Operations-Subdomain verfuegbar.';
            $hints = [
                'Rufe diesen Bereich über die Operations-Subdomain auf.',
                'Auf der Hauptdomain liefern Admin-Routen absichtlich einen 404-Status.',
            ];

            ob_start();
            require base_path('public/ui/_templates/error-page.php');
            $html = (string) ob_get_clean();

            return new Response($html, 404, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return $next($request);
    }
}

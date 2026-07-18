<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $path = $request->path();
        $isInvoicePdf = (bool) preg_match('#^/(clients/data|v1/admin/clients)/\d+/invoices/\d+/pdf$#', $path);
        $frameOptions = $isInvoicePdf ? 'SAMEORIGIN' : 'DENY';

        return $next($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', $frameOptions)
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-XSS-Protection', '1; mode=block');
    }
}

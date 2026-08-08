<?php

declare(strict_types=1);

use App\Controllers\Api\V1\ConsentController;
use App\Controllers\Api\V1\AvailabilityController;
use App\Controllers\Api\V1\ContactRequestController;
use App\Controllers\Api\V1\Admin\RequestAdminController;
use App\Controllers\Api\V1\Admin\BookingAdminController;
use App\Controllers\Api\V1\Admin\BookingStatusController;
use App\Controllers\Api\V1\Admin\ClientAdminController;
use App\Controllers\Api\V1\Admin\ServiceAdminController;
use App\Controllers\Api\V1\Admin\AvailabilityAdminController;
use App\Controllers\Api\V1\RequestController;
use App\Controllers\Api\V1\UserController;
use App\Controllers\Api\V1\Admin\MediaController;
use App\Controllers\Api\V1\Admin\GalleryController;
use App\Controllers\Api\V1\Admin\PageMediaAssignmentController;
use App\Controllers\Api\V1\Internal\PaymentReminderCronController;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\RouterFacade as Router;
use App\Core\Routing\Router as RouterInstance;
use App\Core\Support\PermissionBits;

if (!function_exists('apiSwaggerNotFound')) {
    function apiSwaggerNotFound(string $reason = 'not_found'): Response
    {
        $response = Response::json([
            'success' => false,
            'error' => true,
            'message' => 'Not found',
            'errors' => [],
        ], 404);

        if ((bool) config('app.debug', false)) {
            $response = $response->withHeader('X-Swagger-Deny-Reason', $reason);
        }

        return $response;
    }
}

if (!function_exists('apiSwaggerAccessReason')) {
    function apiSwaggerAccessReason(Request $request): string
    {
        $sessionCookieName = session_name();
        $sessionCookieValue = trim((string) $request->cookie($sessionCookieName, ''));
        if ($sessionCookieValue === '') {
            return 'session_cookie_missing';
        }

        $sessionKey = (string) config('operations.session_key', 'operations_user');
        $operationsUser = $request->session()[$sessionKey] ?? [];
        if (!is_array($operationsUser) || $operationsUser === []) {
            return 'session_user_missing';
        }

        $roleMask = (int) ($operationsUser['role_mask'] ?? 0);
        $bit = PermissionBits::resolve('manage_admin_settings', 2048);
        if (($roleMask & $bit) === 0) {
            return 'permission_missing';
        }

        return 'ok';
    }
}

Router::group('/swagger', function (RouterInstance $router): void {
    Router::get('/', function (Request $request): Response {
        $accessReason = apiSwaggerAccessReason($request);
        if ($accessReason !== 'ok') {
            return apiSwaggerNotFound($accessReason);
        }

        $publicUrl = '/swagger/openapi-public.yaml';
        $adminUrl = '/swagger/openapi-admin.yaml';
        $html = <<<HTML
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Swagger UI - Henz Software API</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        html, body { margin: 0; padding: 0; height: 100%; }
        body { font-family: "Segoe UI", Tahoma, sans-serif; background: #f3f6f9; }
        .header { padding: 10px 16px; background: #10243e; color: #fff; display: flex; align-items: center; gap: 10px; }
        .header h1 { margin: 0; font-size: 16px; font-weight: 600; }
        .header select { border: 1px solid #4b6686; border-radius: 6px; padding: 6px 8px; background: #163457; color: #fff; }
        #swagger-ui { height: calc(100% - 48px); overflow: auto; }
    </style>
</head>
<body>
    <div class="header">
        <h1>API Dokumentation</h1>
        <label for="spec-select">Spezifikation:</label>
        <select id="spec-select">
            <option value="{$publicUrl}">Public (openapi-public.yaml)</option>
            <option value="{$adminUrl}">Admin/Internal (openapi-admin.yaml)</option>
        </select>
    </div>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        (function () {
            var select = document.getElementById('spec-select');
            var ui = SwaggerUIBundle({
                url: select.value,
                dom_id: '#swagger-ui',
                deepLinking: true,
                displayRequestDuration: true,
                persistAuthorization: true,
            });

            select.addEventListener('change', function () {
                ui.specActions.updateUrl(select.value);
                ui.specActions.download(select.value);
            });
        })();
    </script>
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    })->name('api.swagger.ui');

    Router::get('/openapi-public.yaml', function (Request $request): Response {
        $accessReason = apiSwaggerAccessReason($request);
        if ($accessReason !== 'ok') {
            return apiSwaggerNotFound($accessReason);
        }

        $file = base_path('docs/api/openapi-public.yaml');
        if (!is_file($file)) {
            return apiSwaggerNotFound();
        }

        return new Response(
            (string) file_get_contents($file),
            200,
            [
                'Content-Type' => 'application/yaml; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]
        );
    })->name('api.swagger.public_spec');

    Router::get('/openapi-admin.yaml', function (Request $request): Response {
        $accessReason = apiSwaggerAccessReason($request);
        if ($accessReason !== 'ok') {
            return apiSwaggerNotFound($accessReason);
        }

        $file = base_path('docs/api/openapi-admin.yaml');
        if (!is_file($file)) {
            return apiSwaggerNotFound();
        }

        return new Response(
            (string) file_get_contents($file),
            200,
            [
                'Content-Type' => 'application/yaml; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]
        );
    })->name('api.swagger.admin_spec');
});

Router::group('/v1', function (RouterInstance $router): void {
    Router::get('/availability/slots', [AvailabilityController::class, 'slots'])->name('api.v1.availability.slots');
    Router::get('/availability/days', [AvailabilityController::class, 'days'])->name('api.v1.availability.days');
    Router::post('/contact-request', [ContactRequestController::class, 'store'])->name('api.v1.contact_request.store');
    Router::post('/request', [RequestController::class, 'store'])->name('api.v1.request.store');
    Router::post('/consents', [ConsentController::class, 'store'])->name('api.v1.consents.store');
    Router::group('/admin/requests', function (RouterInstance $router): void {
        Router::get('/', [RequestAdminController::class, 'index'])->name('api.v1.admin.requests.index');
        Router::get('/{id}', [RequestAdminController::class, 'show'])->name('api.v1.admin.requests.show');
        Router::patch('/{id}', [RequestAdminController::class, 'update'])->name('api.v1.admin.requests.update');
    }, ['admin_session']);

    /*
    Router::group('/admin/bookings', function (RouterInstance $router): void {
        Router::get('/', [BookingAdminController::class, 'index'])->name('api.v1.admin.bookings.index');
        Router::get('/summary', [BookingAdminController::class, 'summary'])->name('api.v1.admin.bookings.summary');
        Router::post('/', [BookingAdminController::class, 'store'])->name('api.v1.admin.bookings.store');
        Router::patch('/{id}/reschedule', [BookingAdminController::class, 'reschedule'])->name('api.v1.admin.bookings.reschedule');
        Router::patch('/{id}/cancel', [BookingAdminController::class, 'cancel'])->name('api.v1.admin.bookings.cancel');
        Router::patch('/{id}/status', [BookingStatusController::class, 'updateStatus'])->name('api.v1.admin.bookings.update_status');
        Router::post('/{id}/invoice', [BookingAdminController::class, 'createInvoice'])->name('api.v1.admin.bookings.create_invoice');
        Router::get('/{id}/status-audit', [BookingStatusController::class, 'auditLog'])->name('api.v1.admin.bookings.audit_log');
    }, ['admin_session']);
    */

    Router::group('/admin/clients', function (RouterInstance $router): void {
        Router::get('/', [ClientAdminController::class, 'index'])->name('api.v1.admin.clients.index');
        Router::post('/', [ClientAdminController::class, 'store'])->name('api.v1.admin.clients.store');
        Router::get('/validate-email', [ClientAdminController::class, 'validateEmail'])->name('api.v1.admin.clients.validate_email');
        Router::get('/{id}', [ClientAdminController::class, 'show'])->name('api.v1.admin.clients.show');
        Router::patch('/{id}', [ClientAdminController::class, 'update'])->name('api.v1.admin.clients.update');
        Router::get('/{id}/history', [ClientAdminController::class, 'history'])->name('api.v1.admin.clients.history');
        Router::get('/{id}/appointments', [ClientAdminController::class, 'appointments'])->name('api.v1.admin.clients.appointments');
        Router::get('/{id}/consents', [ClientAdminController::class, 'consents'])->name('api.v1.admin.clients.consents');
        Router::get('/{id}/tickets', [ClientAdminController::class, 'tickets'])->name('api.v1.admin.clients.tickets');
        Router::get('/{id}/packages', [ClientAdminController::class, 'packages'])->name('api.v1.admin.clients.packages');
        Router::get('/{id}/invoices', [ClientAdminController::class, 'invoices'])->name('api.v1.admin.clients.invoices');
        Router::post('/{id}/invoices', [ClientAdminController::class, 'createInvoice'])->name('api.v1.admin.clients.invoices.create');
        Router::get('/{id}/invoices/{invoice_id}/pdf', [ClientAdminController::class, 'invoicePdf'])->name('api.v1.admin.clients.invoices.pdf');
    }, ['admin_session']);

    Router::group('/admin/tickets', function (RouterInstance $router): void {
        Router::get('/', [ClientAdminController::class, 'ticketsIndex'])->name('api.v1.admin.tickets.index');
        Router::get('/{ticket_id}', [ClientAdminController::class, 'ticketDetail'])->name('api.v1.admin.tickets.show');
        Router::patch('/{ticket_id}', [ClientAdminController::class, 'updateTicket'])->name('api.v1.admin.tickets.update');
        Router::post('/{ticket_id}/protocols', [ClientAdminController::class, 'createTicketProtocol'])->name('api.v1.admin.tickets.protocols.create');
    }, ['admin_session']);

    Router::group('/admin/services', function (RouterInstance $router): void {
        Router::get('/', [ServiceAdminController::class, 'services'])->name('api.v1.admin.services.index');
        Router::post('/', [ServiceAdminController::class, 'storeService'])->name('api.v1.admin.services.store');
        Router::get('/{id}', [ServiceAdminController::class, 'showService'])->name('api.v1.admin.services.show');
        Router::patch('/{id}', [ServiceAdminController::class, 'updateService'])->name('api.v1.admin.services.update');
    }, ['admin_session']);

    Router::group('/admin/availability', function (RouterInstance $router): void {
        Router::get('/', [AvailabilityAdminController::class, 'index'])->name('api.v1.admin.availability.index');
        Router::patch('/rules', [AvailabilityAdminController::class, 'updateRules'])->name('api.v1.admin.availability.rules.update');
        Router::put('/recurring', [AvailabilityAdminController::class, 'replaceRecurring'])->name('api.v1.admin.availability.recurring.replace');
        Router::post('/blocked', [AvailabilityAdminController::class, 'createBlockedTime'])->name('api.v1.admin.availability.blocked.store');
        Router::delete('/blocked/{id}', [AvailabilityAdminController::class, 'deleteBlockedTime'])->name('api.v1.admin.availability.blocked.delete');
    }, ['admin_session']);

    Router::group('/admin/referenced-projects', function (RouterInstance $router): void {
        Router::get('/', [ServiceAdminController::class, 'referencedProjects'])->name('api.v1.admin.referenced_projects.index');
        Router::post('/', [ServiceAdminController::class, 'storeReferencedProject'])->name('api.v1.admin.referenced_projects.store');
        Router::get('/{id}', [ServiceAdminController::class, 'showReferencedProject'])->name('api.v1.admin.referenced_projects.show');
        Router::patch('/{id}', [ServiceAdminController::class, 'updateReferencedProject'])->name('api.v1.admin.referenced_projects.update');
    }, ['admin_session']);

    Router::group('/admin/media', function (RouterInstance $router): void {
        Router::get('/', [MediaController::class, 'index'])->name('api.v1.admin.media.index');
        Router::post('/', [MediaController::class, 'store'])->name('api.v1.admin.media.store');
        Router::post('/chunk/init', [MediaController::class, 'chunkInit'])->name('api.v1.admin.media.chunk.init');
        Router::post('/chunk/{upload_id}', [MediaController::class, 'chunkAppend'])->name('api.v1.admin.media.chunk.append');
        Router::post('/chunk/{upload_id}/finish', [MediaController::class, 'chunkFinish'])->name('api.v1.admin.media.chunk.finish');
        Router::get('/{id}', [MediaController::class, 'show'])->name('api.v1.admin.media.show');
        Router::patch('/{id}', [MediaController::class, 'update'])->name('api.v1.admin.media.update');
        Router::delete('/{id}', [MediaController::class, 'destroy'])->name('api.v1.admin.media.destroy');
    }, ['admin_session']);

    Router::group('/admin/galleries', function (RouterInstance $router): void {
        Router::get('/', [GalleryController::class, 'index'])->name('api.v1.admin.galleries.index');
        Router::post('/', [GalleryController::class, 'store'])->name('api.v1.admin.galleries.store');
        Router::get('/{id}', [GalleryController::class, 'show'])->name('api.v1.admin.galleries.show');
        Router::patch('/{id}', [GalleryController::class, 'update'])->name('api.v1.admin.galleries.update');
        Router::delete('/{id}', [GalleryController::class, 'destroy'])->name('api.v1.admin.galleries.destroy');
        Router::post('/{id}/items', [GalleryController::class, 'addItem'])->name('api.v1.admin.galleries.add_item');
        Router::patch('/{id}/items/{item_id}', [GalleryController::class, 'updateItem'])->name('api.v1.admin.galleries.update_item');
        Router::delete('/{id}/items/{item_id}', [GalleryController::class, 'removeItem'])->name('api.v1.admin.galleries.remove_item');
    }, ['admin_session']);

    Router::group('/admin/pages/{page_key}/media', function (RouterInstance $router): void {
        Router::get('/', [PageMediaAssignmentController::class, 'indexByPage'])->name('api.v1.admin.pages.media.index');
        Router::post('/', [PageMediaAssignmentController::class, 'store'])->name('api.v1.admin.pages.media.store');
        Router::get('/{id}', [PageMediaAssignmentController::class, 'show'])->name('api.v1.admin.pages.media.show');
        Router::patch('/{id}', [PageMediaAssignmentController::class, 'update'])->name('api.v1.admin.pages.media.update');
        Router::delete('/{id}', [PageMediaAssignmentController::class, 'destroy'])->name('api.v1.admin.pages.media.destroy');
    }, ['admin_session']);

    Router::group('/users', function (RouterInstance $router): void {
        Router::get('/', [UserController::class, 'index'])->name('api.v1.users.index');
        Router::get('/{id}', [UserController::class, 'show'])->name('api.v1.users.show');
        Router::post('/', [UserController::class, 'store'])->name('api.v1.users.store');
    }, ['auth']);

    Router::post('/internal/payment-reminders', [PaymentReminderCronController::class, 'run'])
        ->name('api.v1.internal.payment_reminders.run');
});

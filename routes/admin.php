<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\RouterFacade as Router;
use App\Controllers\Operations\AuthController as AdminAuthController;
use App\Controllers\Operations\RequestsPageController;
use App\Controllers\Operations\BookingsPageController;
use App\Controllers\Operations\ImagesPageController;
use App\Controllers\Operations\SettingsPageController;
use App\Controllers\Operations\UsersPageController;
use App\Controllers\Operations\ClientsPageController;
use App\Controllers\Operations\ServicesPageController;
use App\Controllers\Operations\AvailabilityPageController;
use App\Controllers\Operations\EmailTemplatesPageController;
use App\Controllers\InviteController;
use App\Controllers\Api\V1\AvailabilityController;
use App\Controllers\Api\V1\Admin\BookingAdminController;
use App\Controllers\Api\V1\Admin\BookingStatusController;
use App\Controllers\Api\V1\Admin\UserAdminController;
use App\Controllers\Api\V1\Admin\ClientAdminController;
use App\Controllers\Api\V1\Admin\ServiceAdminController;
use App\Controllers\Api\V1\Admin\AvailabilityAdminController;
use App\Controllers\Api\V1\Admin\RequestAdminController;
use App\Controllers\Api\V1\Admin\MediaController;
use App\Controllers\Api\V1\Admin\GalleryController;
use App\Controllers\Api\V1\Admin\PageMediaAssignmentController;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\AdminSubdomainMiddleware;
use App\Core\Support\OperationHost;

if (!function_exists('operations_not_found_response')) {
    function operations_not_found_response(): Response
    {
        $code = 404;
        $httpStatus = 404;
        $title = 'Seite nicht gefunden';
        $message = 'Diese Route ist nur auf der Operations-Subdomain verfuegbar.';
        $hints = [
            'Rufe den Bereich ueber die Operations-Subdomain auf.',
            'Auf der Hauptdomain sind diese Routen absichtlich nicht verfuegbar.',
        ];

        ob_start();
        require base_path('public/ui/_templates/error-page.php');
        $html = (string) ob_get_clean();

        return new Response($html, 404, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

Router::get('/', function (Request $request): Response {
    if (!OperationHost::isOperationHost($request)) {
        return Response::redirect(OperationHost::buildOperationUrl($request, OperationHost::currentPathWithQuery($request)), 302);
    }

    $session = $request->session();
    $sessionKey = (string) config('admin.session_key', 'admin_user');
    $adminUser = $session[$sessionKey] ?? null;

    return Response::redirect(
        (is_array($adminUser) && $adminUser !== [])
            ? (string) config('admin.dashboard_path', '/dashboard')
            : (string) config('admin.login_path', '/login'),
        302
    );
});
Router::get('/login', function (Request $request): Response {
    if (!OperationHost::isOperationHost($request)) {
        return operations_not_found_response();
    }

    $token = trim((string) $request->query('token', ''));
    if ($token !== '') {
        return app(InviteController::class)->showForm($request);
    }

    return app(AdminAuthController::class)->loginForm($request);
})->name('admin.login');

Router::post('/login', function (Request $request): Response {
    if (!OperationHost::isOperationHost($request)) {
        return operations_not_found_response();
    }

    return app(AdminAuthController::class)->login($request);
})->name('admin.login.submit');

Router::post('/login/accept', function (Request $request): Response {
    if (!OperationHost::isOperationHost($request)) {
        return operations_not_found_response();
    }

    return app(InviteController::class)->submit($request);
})->name('invite.accept');

Router::group('', function (): void {
    Router::get('/dashboard', [AdminAuthController::class, 'dashboard'])->name('admin.dashboard');
    Router::get('/calender', fn (): Response => Response::redirect('/dashboard', 302))->name('admin.dashboard.legacy');
    Router::get('/dashboard/bookings', [BookingAdminController::class, 'index'])->name('admin.dashboard.bookings.index');
    Router::get('/bookings/data/meta', [BookingAdminController::class, 'meta'])->name('admin.bookings.data.meta');
    Router::get('/bookings/data/blocked', [BookingAdminController::class, 'listBlocked'])->name('admin.bookings.data.blocked.index');
    Router::delete('/bookings/data/blocked/{id}', [BookingAdminController::class, 'deleteBlocked'])->name('admin.bookings.data.blocked.delete');
    Router::get('/bookings/data', [BookingAdminController::class, 'index'])->name('admin.bookings.data.index');
    Router::get('/bookings/data/{id}', [BookingAdminController::class, 'show'])->name('admin.bookings.data.show');
    Router::post('/bookings/data', [BookingAdminController::class, 'store'])->name('admin.bookings.data.store');
    Router::post('/bookings/data/block', [BookingAdminController::class, 'block'])->name('admin.bookings.data.block');
    Router::patch('/bookings/data/{id}', [BookingStatusController::class, 'updateStatus'])->name('admin.bookings.data.update_status');
    Router::post('/bookings/data/{id}/invoice', [BookingAdminController::class, 'createInvoice'])->name('admin.bookings.data.create_invoice');
    Router::patch('/bookings/data/{id}/reschedule', [BookingAdminController::class, 'reschedule'])->name('admin.bookings.data.reschedule');
    Router::patch('/bookings/data/{id}/cancel', [BookingAdminController::class, 'cancel'])->name('admin.bookings.data.cancel');
    Router::get('/bookings/slots', [AvailabilityController::class, 'slots'])->name('admin.bookings.slots');
    Router::get('/bookings', [BookingsPageController::class, 'index'])->name('admin.bookings.index');
    Router::get('/bookings/{id}', [BookingsPageController::class, 'show'])->name('admin.bookings.show');
    Router::get('/services/data', [ServiceAdminController::class, 'services'])->name('admin.services.data.index');
    Router::post('/services/data', [ServiceAdminController::class, 'storeService'])->name('admin.services.data.store');
    Router::get('/services/data/{id}', [ServiceAdminController::class, 'showService'])->name('admin.services.data.show');
    Router::patch('/services/data/{id}', [ServiceAdminController::class, 'updateService'])->name('admin.services.data.update');
    Router::get('/availability/data', [AvailabilityAdminController::class, 'index'])->name('admin.availability.data.index');
    Router::patch('/availability/data/rules', [AvailabilityAdminController::class, 'updateRules'])->name('admin.availability.data.rules.update');
    Router::put('/availability/data/recurring', [AvailabilityAdminController::class, 'replaceRecurring'])->name('admin.availability.data.recurring.replace');
    Router::post('/availability/data/blocked', [AvailabilityAdminController::class, 'createBlockedTime'])->name('admin.availability.data.blocked.store');
    Router::delete('/availability/data/blocked/{id}', [AvailabilityAdminController::class, 'deleteBlockedTime'])->name('admin.availability.data.blocked.delete');
    Router::get('/packages/data', [ServiceAdminController::class, 'packages'])->name('admin.packages.data.index');
    Router::post('/packages/data', [ServiceAdminController::class, 'storePackage'])->name('admin.packages.data.store');
    Router::get('/packages/data/{id}', [ServiceAdminController::class, 'showPackage'])->name('admin.packages.data.show');
    Router::patch('/packages/data/{id}', [ServiceAdminController::class, 'updatePackage'])->name('admin.packages.data.update');
    Router::get('/services', [ServicesPageController::class, 'index'])->name('admin.services.index');
    Router::get('/services/{id}', [ServicesPageController::class, 'show'])->name('admin.services.show');
    Router::get('/availability', [AvailabilityPageController::class, 'index'])->name('admin.availability.index');
    Router::get('/requests/data', [RequestAdminController::class, 'index'])->name('admin.requests.data.index');
    Router::get('/requests/data/{id}', [RequestAdminController::class, 'show'])->name('admin.requests.data.show');
    Router::patch('/requests/data/{id}', [RequestAdminController::class, 'update'])->name('admin.requests.data.update');
    Router::get('/requests/slots', [AvailabilityController::class, 'slots'])->name('admin.requests.slots');
    Router::get('/requests', [RequestsPageController::class, 'index'])->name('admin.requests.index');
    Router::get('/requests/{id}', [RequestsPageController::class, 'show'])->name('admin.requests.show');
    Router::get('/images/data', [MediaController::class, 'index'])->name('admin.images.data.index');
    Router::post('/images/data', [MediaController::class, 'store'])->name('admin.images.data.store');
    Router::post('/images/data/chunk/init', [MediaController::class, 'chunkInit'])->name('admin.images.data.chunk.init');
    Router::post('/images/data/chunk/{upload_id}', [MediaController::class, 'chunkAppend'])->name('admin.images.data.chunk.append');
    Router::post('/images/data/chunk/{upload_id}/finish', [MediaController::class, 'chunkFinish'])->name('admin.images.data.chunk.finish');
    Router::get('/images/data/{id}', [MediaController::class, 'show'])->name('admin.images.data.show');
    Router::patch('/images/data/{id}', [MediaController::class, 'update'])->name('admin.images.data.update');
    Router::delete('/images/data/{id}', [MediaController::class, 'destroy'])->name('admin.images.data.destroy');
    Router::get('/images/galleries/data', [GalleryController::class, 'index'])->name('admin.images.galleries.data.index');
    Router::get('/images/pages/{page_key}/assignments', [PageMediaAssignmentController::class, 'indexByPage'])->name('admin.images.assignments.index');
    Router::post('/images/pages/{page_key}/assignments', [PageMediaAssignmentController::class, 'store'])->name('admin.images.assignments.store');
    Router::get('/images', [ImagesPageController::class, 'index'])->name('admin.images.index');
    Router::get('/settings', [SettingsPageController::class, 'index'])->name('admin.settings.index');
    Router::post('/settings', [SettingsPageController::class, 'save'])->name('admin.settings.save');
    Router::get('/email-templates', [EmailTemplatesPageController::class, 'index'])->name('admin.email_templates.index');
    Router::post('/email-templates/{id}', [EmailTemplatesPageController::class, 'update'])->name('admin.email_templates.update');
    Router::post('/email-templates/{id}/test', [EmailTemplatesPageController::class, 'sendTest'])->name('admin.email_templates.test');
    Router::post('/email-templates/{id}/preview', [EmailTemplatesPageController::class, 'preview'])->name('admin.email_templates.preview');
    Router::get('/users/data', [UserAdminController::class, 'index'])->name('admin.users.data.index');
    Router::post('/users/data', [UserAdminController::class, 'store'])->name('admin.users.data.store');
    Router::patch('/users/data/{id}', [UserAdminController::class, 'update'])->name('admin.users.data.update');
    Router::delete('/users/data/{id}', [UserAdminController::class, 'destroy'])->name('admin.users.data.destroy');
    Router::post('/users/data/{id}/invite', [UserAdminController::class, 'invite'])->name('admin.users.data.invite');
    Router::get('/clients/data', [ClientAdminController::class, 'index'])->name('admin.clients.data.index');
    Router::post('/clients/data', [ClientAdminController::class, 'store'])->name('admin.clients.data.store');
    Router::get('/clients/data/validate-email', [ClientAdminController::class, 'validateEmail'])->name('admin.clients.data.validate_email');
    Router::get('/clients/data/{id}', [ClientAdminController::class, 'show'])->name('admin.clients.data.show');
    Router::patch('/clients/data/{id}', [ClientAdminController::class, 'update'])->name('admin.clients.data.update');
    Router::get('/clients/data/{id}/history', [ClientAdminController::class, 'history'])->name('admin.clients.data.history');
    Router::get('/clients/data/{id}/consents', [ClientAdminController::class, 'consents'])->name('admin.clients.data.consents');
    Router::get('/clients/data/{id}/packages', [ClientAdminController::class, 'packages'])->name('admin.clients.data.packages');
    Router::get('/clients/data/{id}/invoices', [ClientAdminController::class, 'invoices'])->name('admin.clients.data.invoices');
    Router::get('/clients/data/{id}/invoices/{invoice_id}/pdf', [ClientAdminController::class, 'invoicePdf'])->name('admin.clients.data.invoices.pdf');
    Router::get('/clients', [ClientsPageController::class, 'index'])->name('admin.clients.index');
    Router::get('/clients/{id}', [ClientsPageController::class, 'show'])->name('admin.clients.show');
    Router::get('/users', [UsersPageController::class, 'index'])->name('admin.users.index');
    Router::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');
}, [AdminSubdomainMiddleware::class, AdminAuthMiddleware::class]);

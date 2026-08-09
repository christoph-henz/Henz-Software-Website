<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\RouterFacade as Router;
use App\Controllers\Operations\AuthController as AdminAuthController;
use App\Controllers\Operations\RequestsPageController;
use App\Controllers\Operations\CalendarPageController;
use App\Controllers\Operations\AppointmentsPageController;
use App\Controllers\Operations\ImagesPageController;
use App\Controllers\Operations\SettingsPageController;
use App\Controllers\Operations\UsersPageController;
use App\Controllers\Operations\ProjectsPageController;
use App\Controllers\Operations\ClientsPageController;
use App\Controllers\Operations\ServicesPageController;
use App\Controllers\Operations\AvailabilityPageController;
use App\Controllers\Operations\EmailTemplatesPageController;
use App\Controllers\Operations\FormTemplatesPageController;
use App\Controllers\InviteController;
use App\Controllers\Api\V1\AvailabilityController;
use App\Controllers\Api\V1\Admin\AppointmentAdminController;
use App\Controllers\Api\V1\Admin\UserAdminController;
use App\Controllers\Api\V1\Admin\ProjectAdminController;
use App\Controllers\Api\V1\Admin\ClientAdminController;
use App\Controllers\Api\V1\Admin\ServiceAdminController;
use App\Controllers\Api\V1\Admin\FormTemplateAdminController;
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
    Router::get('/calendar', [CalendarPageController::class, 'index'])->name('admin.calendar.index');
    Router::get('/dashboard/appointments', [AppointmentAdminController::class, 'index'])->name('admin.dashboard.appointments.index');
    Router::get('/appointments/data/meta', [AppointmentAdminController::class, 'meta'])->name('admin.appointments.data.meta');
    Router::get('/appointments/data/blocked', [AppointmentAdminController::class, 'listBlocked'])->name('admin.appointments.data.blocked.index');
    Router::delete('/appointments/data/blocked/{id}', [AppointmentAdminController::class, 'deleteBlocked'])->name('admin.appointments.data.blocked.delete');
    Router::get('/appointments/data', [AppointmentAdminController::class, 'index'])->name('admin.appointments.data.index');
    Router::get('/appointments/data/{id}', [AppointmentAdminController::class, 'show'])->name('admin.appointments.data.show');
    Router::post('/appointments/data', [AppointmentAdminController::class, 'store'])->name('admin.appointments.data.store');
    Router::post('/appointments/data/block', [AppointmentAdminController::class, 'block'])->name('admin.appointments.data.block');
    Router::patch('/appointments/data/{id}', [AppointmentAdminController::class, 'update'])->name('admin.appointments.data.update');
    Router::delete('/appointments/data/{id}', [AppointmentAdminController::class, 'destroy'])->name('admin.appointments.data.delete');
    Router::post('/appointments/data/{id}/invoice', [AppointmentAdminController::class, 'createInvoice'])->name('admin.appointments.data.create_invoice');
    Router::patch('/appointments/data/{id}/reschedule', [AppointmentAdminController::class, 'reschedule'])->name('admin.appointments.data.reschedule');
    Router::patch('/appointments/data/{id}/cancel', [AppointmentAdminController::class, 'cancel'])->name('admin.appointments.data.cancel');
    Router::get('/appointments/slots', [AvailabilityController::class, 'slots'])->name('admin.appointments.slots');
    Router::get('/appointments', [AppointmentsPageController::class, 'index'])->name('admin.appointments.index');
    Router::get('/appointments/{id}', [AppointmentsPageController::class, 'show'])->name('admin.appointments.show');
    Router::get('/services/data', [ServiceAdminController::class, 'services'])->name('admin.services.data.index');
    Router::post('/services/data', [ServiceAdminController::class, 'storeService'])->name('admin.services.data.store');
    Router::get('/services/data/{id}', [ServiceAdminController::class, 'showService'])->name('admin.services.data.show');
    Router::patch('/services/data/{id}', [ServiceAdminController::class, 'updateService'])->name('admin.services.data.update');
    Router::get('/availability/data', [AvailabilityAdminController::class, 'index'])->name('admin.availability.data.index');
    Router::patch('/availability/data/rules', [AvailabilityAdminController::class, 'updateRules'])->name('admin.availability.data.rules.update');
    Router::put('/availability/data/recurring', [AvailabilityAdminController::class, 'replaceRecurring'])->name('admin.availability.data.recurring.replace');
    Router::post('/availability/data/blocked', [AvailabilityAdminController::class, 'createBlockedTime'])->name('admin.availability.data.blocked.store');
    Router::delete('/availability/data/blocked/{id}', [AvailabilityAdminController::class, 'deleteBlockedTime'])->name('admin.availability.data.blocked.delete');
    Router::get('/referenced-projects/data', [ServiceAdminController::class, 'referencedProjects'])->name('admin.referenced_projects.data.index');
    Router::post('/referenced-projects/data', [ServiceAdminController::class, 'storeReferencedProject'])->name('admin.referenced_projects.data.store');
    Router::get('/referenced-projects/data/{id}', [ServiceAdminController::class, 'showReferencedProject'])->name('admin.referenced_projects.data.show');
    Router::patch('/referenced-projects/data/{id}', [ServiceAdminController::class, 'updateReferencedProject'])->name('admin.referenced_projects.data.update');
    Router::get('/services', [ServicesPageController::class, 'index'])->name('admin.services.index');
    Router::get('/services/{id}', [ServicesPageController::class, 'show'])->name('admin.services.show');
    Router::get('/availability', [AvailabilityPageController::class, 'index'])->name('admin.availability.index');
    Router::get('/requests/data', [RequestAdminController::class, 'index'])->name('admin.requests.data.index');
    Router::get('/requests/data/{id}', [RequestAdminController::class, 'show'])->name('admin.requests.data.show');
    Router::patch('/requests/data/{id}', [RequestAdminController::class, 'update'])->name('admin.requests.data.update');
    Router::get('/requests/slots', [AvailabilityController::class, 'slots'])->name('admin.requests.slots');
    Router::get('/requests', [RequestsPageController::class, 'index'])->name('admin.requests.index');
    Router::get('/requests/{id}', [RequestsPageController::class, 'show'])->name('admin.requests.show');
    Router::get('/form-templates', [FormTemplatesPageController::class, 'index'])->name('admin.session_templates.index');
    Router::get('/form-templates/data', [FormTemplateAdminController::class, 'index'])->name('admin.session_templates.data.index');
    Router::get('/form-templates/data/{id}', [FormTemplateAdminController::class, 'show'])->name('admin.session_templates.data.show');
    Router::post('/form-templates/data', [FormTemplateAdminController::class, 'store'])->name('admin.session_templates.data.store');
    Router::patch('/form-templates/data/{id}', [FormTemplateAdminController::class, 'update'])->name('admin.session_templates.data.update');
    Router::delete('/form-templates/data/{id}', [FormTemplateAdminController::class, 'destroy'])->name('admin.session_templates.data.delete');
    Router::get('/form-templates/{id}/editor', [FormTemplatesPageController::class, 'showEditor'])->name('admin.session_templates.editor');
    Router::get('/form-templates/{id}/editor/{version_no}', [FormTemplatesPageController::class, 'showEditorVersion'])->name('admin.session_templates.editor_version');
    Router::get('/form-templates/data/{id}/versions', [FormTemplateAdminController::class, 'listVersions'])->name('admin.session_templates.data.versions.index');
    Router::get('/form-templates/data/{id}/versions/{version_id}', [FormTemplateAdminController::class, 'showVersion'])->name('admin.session_templates.data.versions.show');
    Router::get('/form-templates/data/{id}/versions/{version_id}/pdf', [FormTemplateAdminController::class, 'exportVersionPdf'])->name('admin.session_templates.data.versions.pdf');
    Router::post('/form-templates/data/{id}/versions', [FormTemplateAdminController::class, 'publishVersion'])->name('admin.session_templates.data.versions.store');
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
    Router::get('/users', [UsersPageController::class, 'index'])->name('admin.users.index');
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
    Router::get('/clients/data/{id}/appointments', [ClientAdminController::class, 'appointments'])->name('admin.clients.data.appointments');
    Router::get('/clients/data/{id}/consents', [ClientAdminController::class, 'consents'])->name('admin.clients.data.consents');
    Router::get('/clients/data/{id}/tickets', [ClientAdminController::class, 'tickets'])->name('admin.clients.data.tickets');
    Router::get('/tickets/data', [ClientAdminController::class, 'ticketsIndex'])->name('admin.tickets.data.index');
    Router::get('/tickets/data/{ticket_id}', [ClientAdminController::class, 'ticketDetail'])->name('admin.tickets.data.show');
    Router::patch('/tickets/data/{ticket_id}', [ClientAdminController::class, 'updateTicket'])->name('admin.tickets.data.update');
    Router::post('/tickets/data/{ticket_id}/protocols', [ClientAdminController::class, 'createTicketProtocol'])->name('admin.tickets.data.protocols.create');
    Router::get('/clients/data/{id}/invoices', [ClientAdminController::class, 'invoices'])->name('admin.clients.data.invoices');
    Router::post('/clients/data/{id}/invoices', [ClientAdminController::class, 'createInvoice'])->name('admin.clients.data.invoices.create');
    Router::get('/clients/data/{id}/invoices/{invoice_id}/pdf', [ClientAdminController::class, 'invoicePdf'])->name('admin.clients.data.invoices.pdf');
    Router::get('/clients/data/{id}/contracts', [ClientAdminController::class, 'contracts'])->name('admin.clients.data.contracts');
    Router::post('/clients/data/{id}/contracts', [ClientAdminController::class, 'createContract'])->name('admin.clients.data.contracts.create');
    Router::post('/clients/data/{id}/contracts/upload', [ClientAdminController::class, 'uploadContract'])->name('admin.clients.data.contracts.upload');
    Router::patch('/clients/data/{id}/contracts/{contract_id}', [ClientAdminController::class, 'updateContract'])->name('admin.clients.data.contracts.update');
    Router::get('/clients/data/{id}/contracts/{contract_id}/download', [ClientAdminController::class, 'downloadContract'])->name('admin.clients.data.contracts.download');
    Router::get('/clients', [ClientsPageController::class, 'index'])->name('admin.clients.index');
    Router::get('/clients/{id}', [ClientsPageController::class, 'show'])->name('admin.clients.show');
    Router::get('/tickets', [ClientsPageController::class, 'tickets'])->name('admin.tickets.index');
    
    Router::get('/projects', [ProjectsPageController::class, 'index'])->name('admin.projects.index');
    Router::get('/projects/data', [ProjectAdminController::class, 'index'])->name('admin.projects.data.index');
    Router::get('/projects/data/users', [ProjectAdminController::class, 'users'])->name('admin.projects.data.users');
    Router::get('/projects/data/clients', [ProjectAdminController::class, 'clients'])->name('admin.projects.data.clients');
    Router::get('/projects/data/{id}', [ProjectAdminController::class, 'show'])->name('admin.projects.data.show');
    Router::get('/projects/data/{id}/phases', [ProjectAdminController::class, 'phases'])->name('admin.projects.data.phases.index');
    Router::post('/projects/data/{id}/phases', [ProjectAdminController::class, 'storePhase'])->name('admin.projects.data.phases.store');
    Router::patch('/projects/data/{id}/phases/{phase_id}', [ProjectAdminController::class, 'updatePhase'])->name('admin.projects.data.phases.update');
    Router::delete('/projects/data/{id}/phases/{phase_id}', [ProjectAdminController::class, 'destroyPhase'])->name('admin.projects.data.phases.destroy');
    Router::get('/projects/{id}/phase/{phase_id}/test-data', [ProjectAdminController::class, 'phaseTestData'])->name('admin.projects.phase.test_data');
    Router::post('/projects/{id}/phase/{phase_id}/tests', [ProjectAdminController::class, 'createPhaseTests'])->name('admin.projects.phase.tests.create');
    Router::post('/projects/{id}/phase/{phase_id}/test-data', [ProjectAdminController::class, 'savePhaseTestData'])->name('admin.projects.phase.test_data.save');
    Router::post('/projects/{id}/phase/{phase_id}/test-data/attachments', [ProjectAdminController::class, 'uploadPhaseTestAttachment'])->name('admin.projects.phase.test_data.attachments.upload');
    Router::get('/projects/{id}/phase/{phase_id}/test-data/attachments/{attachment_id}/download', [ProjectAdminController::class, 'downloadPhaseTestAttachment'])->name('admin.projects.phase.test_data.attachments.download');
    Router::get('/projects/data/{id}/members', [ProjectAdminController::class, 'members'])->name('admin.projects.data.members.index');
    Router::post('/projects/data/{id}/members', [ProjectAdminController::class, 'storeMember'])->name('admin.projects.data.members.store');
    Router::delete('/projects/data/{id}/members/{member_id}', [ProjectAdminController::class, 'destroyMember'])->name('admin.projects.data.members.destroy');
    Router::post('/projects/data', [ProjectAdminController::class, 'store'])->name('admin.projects.data.store');
    Router::patch('/projects/data/{id}', [ProjectAdminController::class, 'update'])->name('admin.projects.data.update');
    Router::delete('/projects/data/{id}', [ProjectAdminController::class, 'destroy'])->name('admin.projects.data.destroy');
    Router::get('/projects/{id}', [ProjectsPageController::class, 'show'])->name('admin.projects.show');
    
    Router::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');
}, [AdminSubdomainMiddleware::class, AdminAuthMiddleware::class]);

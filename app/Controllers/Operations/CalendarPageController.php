<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;
final class CalendarPageController
{
    private const VIEW_APPOINTMENTS_BIT = 1;
    private const MANAGE_APPOINTMENTS_BIT = 2;

    public function index(Request $request): Response
    {
        return $this->renderPage($request);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::redirect('/calendar', 302);
        }

        return $this->renderPage($request, $id);
    }

    private function renderPage(Request $request, ?int $initialAppointmentId = null): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $viewBit = PermissionBits::resolve('view_appointments', self::VIEW_APPOINTMENTS_BIT);
        $manageBit = PermissionBits::resolve('manage_appointments', self::MANAGE_APPOINTMENTS_BIT);

        $canView = (($roleMask & $viewBit) !== 0) || (($roleMask & $manageBit) !== 0);
        $canManage = ($roleMask & $manageBit) !== 0;

        if (!$canView) {
            return $this->renderError(403, 'Zugriff verweigert', 'Ihre Rolle berechtigt nicht zur Terminverwaltung.');
        }

        $config = require base_path('public/ui/_config/operations/admin-calendar.php');
        $queryAppointmentId = (int) $request->query('appointment_id', 0);
        $config['can_view_appointments'] = $canView;
        $config['can_manage_appointments'] = $canManage;
        $config['initial_appointment_id'] = $initialAppointmentId ?? ($queryAppointmentId > 0 ? $queryAppointmentId : null);

        return $this->render('admin-calendar-page.php', [
            'pageTitle' => 'Kalender - Henz Software',
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'calendarConfig' => $config,
        ]);
    }

    private function renderError(int $code, string $title, string $message): Response
    {
        $hints = [];
        ob_start();
        require base_path('public/ui/_templates/error-page.php');
        $html = (string) ob_get_clean();

        return new Response($html, $code, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data = [], int $status = 200): Response
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require base_path('public/ui/_templates/operations/' . $template);
        $html = (string) ob_get_clean();

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

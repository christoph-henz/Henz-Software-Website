<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class AppointmentsPageController
{
    private const VIEW_APPOINTMENTS_BIT = 1;
    private const MANAGE_APPOINTMENTS_BIT = 2;
    private const STORNO_APPOINTMENTS_BIT = 4;

    public function index(Request $request): Response
    {
        return $this->renderPage($request, null);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        return $this->renderPage($request, $id > 0 ? $id : null);
    }

    private function renderPage(Request $request, ?int $bookingId): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $viewBit = PermissionBits::resolve('view_appointments', self::VIEW_APPOINTMENTS_BIT);
        $manageBit = PermissionBits::resolve('manage_appointments', self::MANAGE_APPOINTMENTS_BIT);
        $stornoBit = PermissionBits::resolve('storno_appointment', self::STORNO_APPOINTMENTS_BIT);

        $canView = (($roleMask & $viewBit) !== 0) || (($roleMask & $manageBit) !== 0);
        $canManage = (($roleMask & $manageBit) !== 0);
        $canStorno = (($roleMask & $stornoBit) !== 0);

        if (!$canView) {
            return Response::json([
                'success' => false,
                'message' => 'Forbidden',
                'errors' => [
                    'permission' => ['insufficient_role'],
                ],
            ], 403);
        }

        $config = require base_path('public/ui/_config/operations/admin-appointments.php');
        $config['can_view_appointments'] = $canView;
        $config['can_manage_appointments'] = $canManage;
        $config['can_storno_appointments'] = $canStorno;
        $config['initial_appointment_id'] = $bookingId;

        return $this->render('admin-appointments-page.php', [
            'pageTitle' => 'Termine – Henz Software',
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'appointmentsConfig' => $config,
        ]);
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
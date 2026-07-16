<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class BookingsPageController
{
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
        $viewBit = PermissionBits::resolve('view_bookings', 1);
        $manageBit = PermissionBits::resolve('manage_bookings', 2);
        $revertBit = PermissionBits::resolve('revert_booking_status', 4);

        $canView = (($roleMask & $viewBit) !== 0) || (($roleMask & $manageBit) !== 0);
        $canManage = (($roleMask & $manageBit) !== 0);

        if (!$canView) {
            return Response::json([
                'success' => false,
                'message' => 'Forbidden',
                'errors' => [
                    'permission' => ['insufficient_role'],
                ],
            ], 403);
        }

        $config = require base_path('public/ui/_config/admin-bookings.php');
        $config['can_view_bookings'] = $canView;
        $config['can_manage_bookings'] = $canManage;
        $config['can_revert_bookings'] = (($roleMask & $revertBit) !== 0);
        $config['initial_booking_id'] = $bookingId;

        return $this->render('admin-bookings-page.php', [
            'pageTitle' => 'Buchungen – Henz Software',
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'bookingsConfig' => $config,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data = [], int $status = 200): Response
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require base_path('public/ui/_templates/' . $template);
        $html = (string) ob_get_clean();

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
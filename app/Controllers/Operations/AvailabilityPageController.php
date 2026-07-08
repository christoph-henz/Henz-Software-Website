<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class AvailabilityPageController
{
    private const VIEW_BOOKINGS_BIT = 1;
    private const MANAGE_BOOKINGS_BIT = 2;

    public function index(Request $request): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $viewBit = PermissionBits::resolve('view_bookings', self::VIEW_BOOKINGS_BIT);
        $manageBit = PermissionBits::resolve('manage_bookings', self::MANAGE_BOOKINGS_BIT);

        $canView = (($roleMask & ($viewBit | $manageBit)) !== 0);
        $canManage = (($roleMask & $manageBit) !== 0);

        if (!$canView) {
            return $this->renderError(403, 'Zugriff verweigert', 'Ihre Rolle berechtigt nicht zur Verfügbarkeitsverwaltung.');
        }

        $config = require base_path('public/ui/_config/admin-availability.php');
        $config['can_view_availability'] = $canView;
        $config['can_manage_availability'] = $canManage;

        return $this->render('admin-availability-page.php', [
            'pageTitle' => 'Verfügbarkeit - Getragen Begleiten',
            'adminUser' => $adminUser,
            'logoutAction' => '/admin/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'availabilityConfig' => $config,
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
        require base_path('public/ui/_templates/' . $template);
        $html = (string) ob_get_clean();

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

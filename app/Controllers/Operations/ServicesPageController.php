<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class ServicesPageController
{
    private const MANAGE_SERVICES_BIT = 8192;

    public function index(Request $request): Response
    {
        return $this->renderPage($request, null);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        return $this->renderPage($request, $id > 0 ? $id : null);
    }

    private function renderPage(Request $request, ?int $pathServiceId): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $manageBit = PermissionBits::resolve('manage_services', self::MANAGE_SERVICES_BIT);
        $canManage = (($roleMask & $manageBit) !== 0);

        if (!$canManage) {
            return $this->renderError(403, 'Zugriff verweigert', 'Ihre Rolle berechtigt nicht zur Serviceverwaltung.');
        }

        $config = require base_path('public/ui/_config/admin-services.php');
        $config['can_manage_services'] = $canManage;
        $config['initial_service_id'] = $pathServiceId;

        return $this->render('admin-services-page.php', [
            'pageTitle' => 'Leistungen - Getragen Begleiten',
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'servicesConfig' => $config,
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

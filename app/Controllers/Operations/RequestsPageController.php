<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class RequestsPageController
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

    private function renderPage(Request $request, ?int $requestId): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $viewBit = PermissionBits::resolve('view_clients', 8);
        $manageBit = PermissionBits::resolve('manage_clients', 16);

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

        $config = require base_path('public/ui/_config/admin-requests.php');
        $config['can_view_requests'] = $canView;
        $config['can_manage_requests'] = $canManage;
        $config['initial_request_id'] = $requestId;
        $config['services'] = $this->serviceMapping();

        return $this->render('admin-requests-page.php', [
            'pageTitle' => 'Anfragen – Getragen Begleiten',
            'adminUser' => $adminUser,
            'logoutAction' => '/admin/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'requestsConfig' => $config,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function serviceMapping(): array
    {
        $rows = db('services')
            ->select(['slug', 'name'])
            ->orderBy('display_order', 'asc')
            ->get();

        $mapping = [];
        foreach ($rows as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $mapping[$slug] = trim((string) ($row['name'] ?? $slug));
        }

        return $mapping;
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

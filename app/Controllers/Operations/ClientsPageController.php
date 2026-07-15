<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;
final class ClientsPageController
{
    private const VIEW_CLIENTS_BIT = 8;
    private const MANAGE_CLIENTS_BIT = 16;
    private const USE_FORM_TEMPLATES_FOR_CLIENTS_BIT = 32768;

    public function index(Request $request): Response
    {
        return $this->renderPage($request, null, 'list');
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::redirect('/clients', 302);
        }

        return $this->renderPage($request, $id, 'record');
    }

    private function renderPage(Request $request, ?int $pathClientId, string $viewMode): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $viewBit = PermissionBits::resolve('view_clients', self::VIEW_CLIENTS_BIT);
        $manageBit = PermissionBits::resolve('manage_clients', self::MANAGE_CLIENTS_BIT);
        $useFormsBit = PermissionBits::resolve('use_form_templates_for_clients', self::USE_FORM_TEMPLATES_FOR_CLIENTS_BIT);

        $canView = (($roleMask & $viewBit) !== 0) || (($roleMask & $manageBit) !== 0);
        $canManage = ($roleMask & $manageBit) !== 0;
        $canUseFormTemplates = ($roleMask & $useFormsBit) !== 0;

        if (!$canView) {
            return $this->renderError(403, 'Zugriff verweigert', 'Ihre Rolle berechtigt nicht zur Clientverwaltung.');
        }

        $config = require base_path('public/ui/_config/operations/admin-clients.php');
        $queryClientId = (int) $request->query('client_id', 0);
        $initialClientId = $pathClientId ?? ($queryClientId > 0 ? $queryClientId : null);

        $packagesRaw = strtolower(trim((string) $request->query('packages', '0')));
        $openPackages = in_array($packagesRaw, ['1', 'true', 'yes'], true);
        $invoicesRaw = strtolower(trim((string) $request->query('invoices', '0')));
        $openInvoices = in_array($invoicesRaw, ['1', 'true', 'yes'], true);

        $config['can_view_clients'] = $canView;
        $config['can_manage_clients'] = $canManage;
        $config['can_use_form_templates_for_clients'] = $canUseFormTemplates;
        $config['initial_client_id'] = $initialClientId;
        $config['initial_packages_open'] = $openPackages;
        $config['initial_invoices_open'] = $openInvoices;
        $config['view_mode'] = $viewMode;

        return $this->render('admin-clients-page.php', [
            'pageTitle' => 'Clientverwaltung - Getragen Begleiten',
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'clientsConfig' => $config,
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

<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class FormTemplatesPageController
{
    private const MANAGE_FORM_TEMPLATES_BIT = 33554432;

    public function index(Request $request): Response
    {
        return $this->renderPage($request, null, false, 'overview', null);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        return $this->renderPage($request, $id > 0 ? $id : null, false, 'overview', null);
    }

    public function showSettings(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        return $this->renderPage($request, $id > 0 ? $id : null, false, 'metadata', null);
    }

    public function showVersions(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        return $this->renderPage($request, $id > 0 ? $id : null, false, 'versions', null);
    }

    public function showEditor(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        return $this->renderPage($request, $id > 0 ? $id : null, true, 'versions', null);
    }

    public function showEditorVersion(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        $versionNo = trim((string) $request->attribute('version_no', ''));
        return $this->renderPage($request, $id > 0 ? $id : null, true, 'versions', $versionNo !== '' ? $versionNo : null);
    }

    private function renderPage(Request $request, ?int $pathTemplateId, bool $openEditor, string $initialTab, ?string $initialEditorVersionNo): Response
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $manageBit = PermissionBits::resolve('manage_form_templates', self::MANAGE_FORM_TEMPLATES_BIT);
        $canManage = (($roleMask & $manageBit) !== 0);

        if (!$canManage) {
            return $this->renderError(403, 'Zugriff verweigert', 'Ihre Rolle berechtigt nicht zur Formularverwaltung.');
        }

        $config = require base_path('public/ui/_config/operations/admin-form-templates.php');
        $config['can_manage_templates'] = $canManage;
        $config['initial_template_id'] = $pathTemplateId;
        $config['open_editor_on_load'] = $openEditor;
        $config['initial_tab'] = $initialTab;
        $config['initial_editor_version_no'] = $initialEditorVersionNo;

        return $this->render('admin-form-templates-page.php', [
            'pageTitle' => 'Formularverwaltung - Henz Software',
            'adminUser' => $adminUser,
            'logoutAction' => '/admin/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'templatesConfig' => $config,
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

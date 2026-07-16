<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class ProjectsPageController
{
    private const VIEW_PROJECTS_BIT = 256;
    private const MANAGE_PROJECTS_BIT = 512;

    public function index(Request $request): Response
    {
        $session    = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser  = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask  = (int) ($adminUser['role_mask'] ?? 0);
        $viewBit = PermissionBits::resolve('view_projects', self::VIEW_PROJECTS_BIT);
        $canView = ($roleMask & $viewBit) !== 0;

        if (!$canView) {
            return $this->renderError(403, 'Zugriff verweigert', 'Ihre Rolle berechtigt nicht zur Projektverwaltung.');
        }

        $manageProjectsBit = PermissionBits::resolve('manage_projects', self::MANAGE_PROJECTS_BIT);

        $config                               = require base_path('public/ui/_config/operations/admin-projects.php');
        $config['can_view']                 = $canView;
        $config['current_user_id']            = (int) ($adminUser['id'] ?? 0);
        $config['current_role_mask']          = $roleMask;
        $config['can_manage_projects']        = ($roleMask & $manageProjectsBit) !== 0;

        return $this->render('admin-project-page.php', [
            'pageTitle'    => 'Projektverwaltung – Henz Software',
            'adminUser'    => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken'    => app(CsrfTokenManager::class)->token(),
            'projectsConfig'  => $config,
        ]);
    }

    public function show(Request $request): Response
    {
        $session    = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser  = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $viewBit = PermissionBits::resolve('view_projects', self::VIEW_PROJECTS_BIT);
        $canView = ($roleMask & $viewBit) !== 0;

        if (!$canView) {
            return $this->renderError(403, 'Zugriff verweigert', 'Ihre Rolle berechtigt nicht zur Projektverwaltung.');
        }

        $projectId = (int) $request->attribute('id', 0);
        if ($projectId <= 0) {
            return $this->renderError(404, 'Projekt nicht gefunden', 'Die angeforderte Projekt-ID ist ungültig.');
        }

        $pdo = app(Database::class)->connection();
        $projectStmt = $pdo->prepare(
            'SELECT p.id, p.name
             FROM projects p
             WHERE p.id = :id AND p.deleted_at IS NULL
             LIMIT 1'
        );
        $projectStmt->execute([':id' => $projectId]);
        $project = $projectStmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($project)) {
            return $this->renderError(404, 'Projekt nicht gefunden', 'Das Projekt existiert nicht oder wurde gelöscht.');
        }

        $manageProjectsBit = PermissionBits::resolve('manage_projects', self::MANAGE_PROJECTS_BIT);

        $config = [
            'project_id' => $projectId,
            'back_url' => '/projects',
            'project_data_url' => '/projects/data/' . $projectId,
            'project_phases_url' => '/projects/data/' . $projectId . '/phases',
            'project_members_url' => '/projects/data/' . $projectId . '/members',
            'project_users_url' => '/projects/data/users',
            'phase_test_data_url_base' => '/projects/' . $projectId . '/phase',
            'phase_tests_url_base' => '/projects/' . $projectId . '/phase',
            'can_manage_projects' => ($roleMask & $manageProjectsBit) !== 0,
        ];

        return $this->render('admin-project-detail-page.php', [
            'pageTitle' => 'Projektdetails – ' . (string) ($project['name'] ?? ('#' . $projectId)),
            'adminUser' => $adminUser,
            'logoutAction' => '/logout',
            'csrfToken' => app(CsrfTokenManager::class)->token(),
            'projectDetailConfig' => $config,
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

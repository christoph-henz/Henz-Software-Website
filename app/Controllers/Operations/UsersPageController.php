<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class UsersPageController
{
    private const MANAGE_USERS_BIT = 256;
    private const MANAGE_SETTINGS_BIT = 1024;
    private const MANAGE_ADMIN_SETTINGS_BIT = 2048;

    public function index(Request $request): Response
    {
        $session    = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser  = is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];

        $roleMask  = (int) ($adminUser['role_mask'] ?? 0);
        $manageBit = PermissionBits::resolve('manage_users', self::MANAGE_USERS_BIT);
        $canManage = ($roleMask & $manageBit) !== 0;

        if (!$canManage) {
            return $this->renderError(403, 'Zugriff verweigert', 'Ihre Rolle berechtigt nicht zur Benutzerverwaltung.');
        }

        $manageSettingsBit = PermissionBits::resolve('manage_settings', self::MANAGE_SETTINGS_BIT);
        $manageAdminBit    = PermissionBits::resolve('manage_admin_settings', self::MANAGE_ADMIN_SETTINGS_BIT);

        $config                               = require base_path('public/ui/_config/admin-users.php');
        $config['can_manage']                 = $canManage;
        $config['current_user_id']            = (int) ($adminUser['id'] ?? 0);
        $config['current_role_mask']          = $roleMask;
        $config['can_manage_settings']        = ($roleMask & $manageSettingsBit) !== 0;
        $config['can_manage_admin_settings']  = ($roleMask & $manageAdminBit) !== 0;
        $config['permission_catalog']         = $this->loadPermissionCatalog();

        return $this->render('admin-users-page.php', [
            'pageTitle'    => 'Benutzerverwaltung – Getragen Begleiten',
            'adminUser'    => $adminUser,
            'logoutAction' => '/admin/logout',
            'csrfToken'    => app(CsrfTokenManager::class)->token(),
            'usersConfig'  => $config,
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

    /** @return array<int, array<string, int|string>> */
    private function loadPermissionCatalog(): array
    {
        $pdo = app(Database::class)->connection();

        $stmt = $pdo->prepare(
            'SELECT slug, name, bit_value, description
             FROM permissions
             WHERE bit_value > 0
             ORDER BY bit_value ASC'
        );
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $row): array => [
                'slug' => (string) ($row['slug'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'bit_value' => (int) ($row['bit_value'] ?? 0),
                'description' => (string) ($row['description'] ?? ''),
            ],
            $rows
        ));
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

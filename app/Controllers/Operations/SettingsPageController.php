<?php

declare(strict_types=1);

namespace App\Controllers\Operations;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;
$view_settings = PermissionBits::resolve("view_settings");
final class SettingsPageController
{
    /**
     * manage_settings (1024) | manage_admin_settings (2048)
     * Either bit grants access to the settings page.
     */
    private const ACCESS_BITS = 16777216;

    /**
     * manage_admin_settings (2048)
     * Used for optional UI flags in templates.
     */
    private const ADMIN_ONLY_BIT = 2048;

    public function index(Request $request): Response
    {
        $adminUser = $this->adminUser($request);
        $roleMask  = (int) ($adminUser['role_mask'] ?? 0);

        if (($roleMask & PermissionBits::resolve("view_settings")) === 0) {
            return $this->renderError(
                403,
                'Zugriff verweigert',
                'Du hast keine Berechtigung, die Einstellungen aufzurufen.',
            );
        }

        $canManageAdmin = ($roleMask & self::ADMIN_ONLY_BIT) !== 0;
        $settingsConfig  = require base_path('public/ui/_config/operations/admin-settings.php');
        $hiddenKeys = $this->resolveHiddenKeys($settingsConfig);
        $settingsByGroup = $this->loadSettings($roleMask, $hiddenKeys);

        return $this->render('admin-settings-page.php', [
            'pageTitle'       => 'Einstellungen – Henz Software',
            'adminUser'       => $adminUser,
            'logoutAction'    => '/logout',
            'csrfToken'       => app(CsrfTokenManager::class)->token(),
            'settingsByGroup' => $settingsByGroup,
            'settingsConfig'  => $settingsConfig,
            'canManageAdmin'  => $canManageAdmin,
        ]);
    }

    public function save(Request $request): Response
    {
        $adminUser = $this->adminUser($request);
        $roleMask  = (int) ($adminUser['role_mask'] ?? 0);

        if (($roleMask & PermissionBits::resolve("manage_settings")) === 0) {
            return $this->renderError(
                403,
                'Zugriff verweigert',
                'Du hast keine Berechtigung, Einstellungen zu ändern.',
            );
        }

        $canManageAdmin = ($roleMask & self::ADMIN_ONLY_BIT) !== 0;
        $settingsConfig  = require base_path('public/ui/_config/operations/admin-settings.php');
        $hiddenKeys = $this->resolveHiddenKeys($settingsConfig);

        // CSRF validation
        $submittedToken = trim((string) $request->input('_csrf', ''));

        if (!app(CsrfTokenManager::class)->isValid($submittedToken)) {
            return $this->renderError(
                403,
                'Ungültige Anfrage',
                'Das Sicherheitstoken ist ungültig. Bitte lade die Seite neu und versuche es erneut.',
            );
        }

        // Load settings the user is allowed to see/edit
        $settingsByGroup = $this->loadSettings($roleMask, $hiddenKeys);
        $flat = [];
        foreach ($settingsByGroup as $rows) {
            foreach ($rows as $row) {
                $flat[(string) $row['key']] = $row;
            }
        }

        $errors = [];

        foreach ($flat as $key => $setting) {
            $minPermissionSum = (int) ($setting['min_permission_sum'] ?? 0);

            // Guard: editing requires full bitmask containment of min_permission_sum.
            if (!$this->hasRequiredPermissionSum($roleMask, $minPermissionSum)) {
                continue;
            }

            $rawValue = $request->input($key);
            $type     = (string) ($setting['type'] ?? 'string');

            switch ($type) {
                case 'boolean':
                    // Unchecked checkboxes are absent from POST – treat as false
                    $coerced = ($rawValue !== null && $rawValue !== '0' && $rawValue !== 'false')
                        ? '1'
                        : '0';
                    break;

                case 'integer':
                    if ($rawValue === null || (string) $rawValue === '') {
                        $errors[$key] = 'Dieses Feld ist erforderlich.';
                        continue 2;
                    }
                    if (!is_numeric($rawValue)) {
                        $errors[$key] = 'Nur Ganzzahlen erlaubt.';
                        continue 2;
                    }
                    $coerced = (string) (int) $rawValue;
                    break;

                case 'json':
                    $jsonStr = (string) ($rawValue ?? '');
                    if ($jsonStr !== '') {
                        json_decode($jsonStr);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $errors[$key] = 'Ungültiges JSON-Format.';
                            continue 2;
                        }
                    }
                    $coerced = $jsonStr;
                    break;

                default:
                    // string / email / url / text
                    $coerced = trim((string) ($rawValue ?? ''));
                    break;
            }

            db('settings')->where('`key`', $key)->update(['value' => $coerced]);
        }

        if ($errors !== []) {
            admin_flash('error', 'Einige Felder konnten nicht gespeichert werden. Bitte prüfe deine Eingaben.');
        } else {
            admin_flash('success', 'Einstellungen wurden erfolgreich gespeichert.');
        }

        return Response::redirect('/settings');
    }

    /**
     * Load all settings rows from the database, grouped by the `group` column.
     * Rows are filtered by min_permission_sum bitmask containment.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function loadSettings(int $roleMask, array $hiddenKeys = []): array
    {
        $rows    = db('settings')->select(['*'])->get();
        $grouped = [];

        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '' && in_array($key, $hiddenKeys, true)) {
                continue;
            }

            $minPermissionSum = (int) ($row['min_permission_sum'] ?? 0);
            if (!$this->hasRequiredPermissionSum($roleMask, $minPermissionSum)) {
                continue;
            }
            $group             = (string) ($row['group'] ?? 'general');
            $grouped[$group][] = $row;
        }

        return $grouped;
    }

    /** @param array<string, mixed> $settingsConfig
     *  @return array<int, string>
     */
    private function resolveHiddenKeys(array $settingsConfig): array
    {
        $raw = $settingsConfig['hidden_keys'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $keys = [];
        foreach ($raw as $entry) {
            $key = trim((string) $entry);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function hasRequiredPermissionSum(int $roleMask, int $minPermissionSum): bool
    {
        if ($minPermissionSum <= 0) {
            return true;
        }

        return ($roleMask & $minPermissionSum) === $minPermissionSum;
    }

    /** @return array<string, mixed> */
    private function adminUser(Request $request): array
    {
        $session    = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');

        return is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];
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

<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfTokenManager;
use App\Core\Support\PermissionBits;

final class SettingsPageController
{
    /**
     * manage_settings (1024) | manage_admin_settings (2048)
     * Either bit grants access to the settings page.
     */
    private const ACCESS_BITS = 3072;

    /**
     * manage_admin_settings (2048)
     * Required to view or edit settings flagged as admin-only.
     */
    private const ADMIN_ONLY_BIT = 2048;

    public function index(Request $request): Response
    {
        $adminUser = $this->adminUser($request);
        $roleMask  = (int) ($adminUser['role_mask'] ?? 0);

        if (($roleMask & self::ACCESS_BITS) === 0) {
            return $this->renderError(
                403,
                'Zugriff verweigert',
                'Du hast keine Berechtigung, die Einstellungen aufzurufen.',
            );
        }

        $canManageAdmin = ($roleMask & self::ADMIN_ONLY_BIT) !== 0;
        $settingsConfig  = require base_path('public/ui/_config/admin-settings.php');
        $hiddenKeys = $this->resolveHiddenKeys($settingsConfig);
        $settingsByGroup = $this->loadSettings($canManageAdmin, $hiddenKeys);

        return $this->render('admin-settings-page.php', [
            'pageTitle'       => 'Einstellungen – Getragen Begleiten',
            'adminUser'       => $adminUser,
            'logoutAction'    => '/admin/logout',
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

        if (($roleMask & self::ACCESS_BITS) === 0) {
            return $this->renderError(
                403,
                'Zugriff verweigert',
                'Du hast keine Berechtigung, Einstellungen zu ändern.',
            );
        }

        $canManageAdmin = ($roleMask & self::ADMIN_ONLY_BIT) !== 0;
        $settingsConfig  = require base_path('public/ui/_config/admin-settings.php');
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
        $settingsByGroup = $this->loadSettings($canManageAdmin, $hiddenKeys);
        $flat = [];
        foreach ($settingsByGroup as $rows) {
            foreach ($rows as $row) {
                $flat[(string) $row['key']] = $row;
            }
        }

        $errors = [];

        foreach ($flat as $key => $setting) {
            // Guard: admin-only fields require the admin bit even in POST
            if ((bool) $setting['is_admin_only'] && !$canManageAdmin) {
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

        return Response::redirect('/admin/settings');
    }

    /**
     * Load all settings rows from the database, grouped by the `group` column.
     * Admin-only rows are excluded when $canManageAdmin is false.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function loadSettings(bool $canManageAdmin, array $hiddenKeys = []): array
    {
        $rows    = db('settings')->select(['*'])->get();
        $grouped = [];

        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '' && in_array($key, $hiddenKeys, true)) {
                continue;
            }

            if ((bool) $row['is_admin_only'] && !$canManageAdmin) {
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
        require base_path('public/ui/_templates/' . $template);
        $html = (string) ob_get_clean();

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

<?php

declare(strict_types=1);

$pageTitle   = (string) ($pageTitle ?? 'Benutzerverwaltung – Henz Software');
$adminUser   = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken   = (string) ($csrfToken ?? '');
$usersConfig = is_array($usersConfig ?? null) ? $usersConfig : [];

$extraHead    = '';
$extraScripts = '<script>window.__ADMIN_USERS_CONFIG = ' . json_encode(
    $usersConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-users.js" defer></script>';

ob_start();
?>
<div class="admin-page-header admin-users-header">
    <div>
        <h1 class="admin-page-title">Mitarbeiterverwaltung</h1>
        <p class="admin-page-subtitle">Mitarbeiter anlegen, Rollen vergeben und Einladungen versenden.</p>
    </div>
    <button type="button" class="admin-users-create-btn" id="openCreateUser">
        Mitarbeiter anlegen
    </button>
</div>

<?php require base_path('public/ui/_partials/operations/users/table.php'); ?>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

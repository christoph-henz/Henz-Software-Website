<?php

declare(strict_types=1);

$pageTitle       = (string) ($pageTitle ?? 'Einstellungen – ' . env('APP_NAME', 'Henz Software'));
$adminUser       = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction    = (string) ($logoutAction ?? '/logout');
$csrfToken       = (string) ($csrfToken ?? '');
$settingsByGroup = is_array($settingsByGroup ?? null) ? $settingsByGroup : [];
$settingsConfig  = is_array($settingsConfig ?? null) ? $settingsConfig : [];
$canManageAdmin  = (bool) ($canManageAdmin ?? false);

$extraHead    = '<link rel="icon" type="image/svg+xml" href="/ui/_assets/images/favicon.svg" />';
$extraScripts = '';

ob_start();
?>
<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Einstellungen</h1>
        <p class="admin-page-subtitle">Systemweite Konfiguration der Plattform.</p>
    </div>
</div>

<?php require base_path('public/ui/_partials/operations/settings/form-groups.php'); ?>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

<?php

declare(strict_types=1);

$pageTitle   = (string) ($pageTitle ?? 'Projektverwaltung');
$adminUser   = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/admin/logout');
$csrfToken   = (string) ($csrfToken ?? '');
$projectsConfig = is_array($projectsConfig ?? null) ? $projectsConfig : [];

$extraHead    = '';
$extraScripts = '<script>window.__ADMIN_PROJECTS_CONFIG = ' . json_encode(
    $projectsConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-projects.js" defer></script>';

ob_start();
?>
<div class="admin-page-header admin-project-header">
    <div>
        <h1 class="admin-page-title">Projektverwaltung</h1>
        <p class="admin-page-subtitle">Projekte anlegen, verwalten, vergeben und abrechnen.</p>
    </div>
    <button type="button" class="admin-projects-create-btn" id="openCreateProject">
        Projekt anlegen
    </button>
</div>

<?php require base_path('public/ui/_partials/operations/projects/table.php'); ?>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

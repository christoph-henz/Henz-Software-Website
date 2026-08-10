<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'Adminpanel – Henz Software');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');

$_displayName = trim(
    ((string) ($adminUser['first_name'] ?? '')) . ' ' . ((string) ($adminUser['last_name'] ?? ''))
);
$_displayName = $_displayName !== '' ? $_displayName : ((string) ($adminUser['email'] ?? 'Administrator'));
$_dashboardConfig = is_array($dashboardConfig ?? null)
    ? $dashboardConfig
    : (require base_path('public/ui/_config/operations/admin-dashboard.php'));

$firstName = trim((string) ($adminUser['first_name'] ?? ''));
$welcomeName = $firstName !== '' ? $firstName : $_displayName;

$kpiCards = is_array($kpiCards ?? null) ? $kpiCards : [];
$revenueSeries = is_array($revenueSeries ?? null) ? $revenueSeries : [];
$revenueSummary = is_array($revenueSummary ?? null) ? $revenueSummary : [];
$activityItems = is_array($activityItems ?? null) ? $activityItems : [];
$projectRows = is_array($projectRows ?? null) ? $projectRows : [];
$canViewProjects = (bool) ($canViewProjects ?? false);
$canViewKpiSection = (bool) ($canViewKpiSection ?? false);
$canViewRevenueSection = (bool) ($canViewRevenueSection ?? false);
$canViewActivitySection = (bool) ($canViewActivitySection ?? false);

$extraScripts = '<script>window.__ADMIN_DASHBOARD_CONFIG = ' . json_encode(
    $_dashboardConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraHead = '<link rel="icon" type="image/svg+xml" href="/ui/_assets/images/favicon.svg" />';

ob_start();
?>
<div class="space-y-6 lg:space-y-8">
    <?php require base_path('public/ui/_partials/operations/dashboard/page-header.php'); ?>

    <?php if ($canViewKpiSection): ?>
        <?php require base_path('public/ui/_partials/operations/dashboard/kpi-overview.php'); ?>
    <?php endif; ?>

    <?php if ($canViewRevenueSection || $canViewActivitySection): ?>
        <div class="grid gap-4 lg:grid-cols-3">
            <?php if ($canViewRevenueSection): ?>
                <?php require base_path('public/ui/_partials/operations/dashboard/revenue-overview.php'); ?>
            <?php endif; ?>
            <?php if ($canViewActivitySection): ?>
                <?php require base_path('public/ui/_partials/operations/dashboard/activity-feed.php'); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canViewProjects): ?>
        <?php require base_path('public/ui/_partials/operations/dashboard/projects-table.php'); ?>
    <?php endif; ?>

    <?php if (!$canViewKpiSection && !$canViewRevenueSection && !$canViewActivitySection && !$canViewProjects): ?>
        <section class="rounded-xl border border-border bg-card p-5">
            <p class="text-xs text-muted-foreground">Keine Berechtigung fuer Dashboard-Sektionen.</p>
        </section>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

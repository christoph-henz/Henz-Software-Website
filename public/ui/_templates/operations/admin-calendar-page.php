<?php

declare(strict_types=1);

use App\Core\Support\PermissionBits;

$pageTitle = (string) ($pageTitle ?? 'Adminpanel – Henz Software');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');

$_displayName = trim(
    ((string) ($adminUser['first_name'] ?? '')) . ' ' . ((string) ($adminUser['last_name'] ?? ''))
);
$_displayName = $_displayName !== '' ? $_displayName : ((string) ($adminUser['email'] ?? 'Administrator'));
$calendarConfig = is_array($calendarConfig ?? null)
    ? $calendarConfig
    : (require base_path('public/ui/_config/operations/admin-calendar.php'));

$extraHead = '';
$extraScripts = '<script>window.__ADMIN_CALENDAR_CONFIG = ' . json_encode(
    $calendarConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script>window.__ADMIN_DASHBOARD_CONFIG = window.__ADMIN_CALENDAR_CONFIG;</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-calendar.js" defer></script>';

ob_start();
?>
<div class="admin-page-header">
    <h1 class="admin-page-title">Kalender</h1>
    <p class="admin-page-subtitle">
        Willkommen zurück,
        <?= htmlspecialchars($_displayName, ENT_QUOTES, 'UTF-8'); ?>
    </p>
</div>

<div class="admin-dashboard-grid">
    <section class="admin-dashboard-card" id="adminDashboardKpiRoot"></section>
    <section class="admin-dashboard-card" id="adminDashboardCalendarRoot"></section>
</div>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

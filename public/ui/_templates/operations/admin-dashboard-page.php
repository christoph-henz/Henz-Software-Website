<?php

declare(strict_types=1);

use App\Core\Support\PermissionBits;

$pageTitle = (string) ($pageTitle ?? 'Adminpanel – Getragen Begleiten');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');

$_displayName = trim(
    ((string) ($adminUser['first_name'] ?? '')) . ' ' . ((string) ($adminUser['last_name'] ?? ''))
);
$_displayName = $_displayName !== '' ? $_displayName : ((string) ($adminUser['email'] ?? 'Administrator'));
$_roleMask = (int) ($adminUser['role_mask'] ?? 0);

$_viewBookingsBit = PermissionBits::resolve('view_bookings', 1);
$_manageBookingsBit = PermissionBits::resolve('manage_bookings', 2);
$_viewPaymentsBit = PermissionBits::resolve('view_payments', 32);

$_canViewBookings = (($_roleMask & $_viewBookingsBit) !== 0) || (($_roleMask & $_manageBookingsBit) !== 0);
$_canManageBookings = (($_roleMask & $_manageBookingsBit) !== 0);
$_canViewKpi = $_canViewBookings && (($_roleMask & $_viewPaymentsBit) !== 0);

$_dashboardConfig = require base_path('public/ui/_config/operations/admin-dashboard.php');
$_dashboardConfig['can_view_bookings'] = $_canViewBookings;
$_dashboardConfig['can_view_kpi'] = $_canViewKpi;
$_dashboardConfig['can_manage_bookings'] = $_canManageBookings;

$firstName = trim((string) ($adminUser['first_name'] ?? ''));
$welcomeName = $firstName !== '' ? $firstName : $_displayName;

$kpiCards = [
    [
        'label' => 'Aktive Projekte',
        'value' => '12',
        'delta' => '+2 diese Woche',
        'color' => 'cyan',
    ],
    [
        'label' => 'Offene Tickets',
        'value' => '34',
        'delta' => '-8 diese Woche',
        'color' => 'amber',
    ],
    [
        'label' => 'Team-Auslastung',
        'value' => '87%',
        'delta' => '+4% diese Woche',
        'color' => 'emerald',
    ],
    [
        'label' => 'Monatsumsatz',
        'value' => 'EUR 94.2k',
        'delta' => '+12% diese Woche',
        'color' => 'violet',
    ],
];

$revenueSeries = [
    ['day' => 'Mo', 'amount' => 12000],
    ['day' => 'Di', 'amount' => 14500],
    ['day' => 'Mi', 'amount' => 13100],
    ['day' => 'Do', 'amount' => 16800],
    ['day' => 'Fr', 'amount' => 18200],
    ['day' => 'Sa', 'amount' => 11000],
    ['day' => 'So', 'amount' => 8600],
];

$activityItems = [
    [
        'text' => 'Neues Projekt "Web-Relaunch" wurde erstellt.',
        'time' => 'vor 12 Min.',
        'color' => 'cyan',
    ],
    [
        'text' => 'Buchung #2491 wurde bestaetigt.',
        'time' => 'vor 28 Min.',
        'color' => 'emerald',
    ],
    [
        'text' => 'Clientprofil von "Villa Athina" aktualisiert.',
        'time' => 'vor 1 Std.',
        'color' => 'amber',
    ],
    [
        'text' => 'Neues Support-Ticket wurde eingereicht.',
        'time' => 'vor 2 Std.',
        'color' => 'violet',
    ],
];

$projectRows = [
    ['name' => 'Restaurant Dionysos', 'client' => 'Dionysos AB', 'status' => 'In Arbeit', 'progress' => 72, 'due' => '18.07.2026'],
    ['name' => 'Villa Athina Portal', 'client' => 'Villa Athina', 'status' => 'Review', 'progress' => 88, 'due' => '21.07.2026'],
    ['name' => 'Parga API Plattform', 'client' => 'Restaurant Parga', 'status' => 'Backlog', 'progress' => 34, 'due' => '04.08.2026'],
    ['name' => 'Operations Dashboard', 'client' => 'Intern', 'status' => 'Live', 'progress' => 100, 'due' => 'Live'],
];

$extraScripts = '<script>window.__ADMIN_DASHBOARD_CONFIG = ' . json_encode(
    $_dashboardConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraHead = '';

ob_start();
?>
<div class="space-y-6 lg:space-y-8">
    <?php require base_path('public/ui/_partials/operations/dashboard/page-header.php'); ?>
    <?php require base_path('public/ui/_partials/operations/dashboard/kpi-overview.php'); ?>
    <div class="grid gap-4 lg:grid-cols-3">
        <?php require base_path('public/ui/_partials/operations/dashboard/revenue-overview.php'); ?>
        <?php require base_path('public/ui/_partials/operations/dashboard/activity-feed.php'); ?>
    </div>
    <?php require base_path('public/ui/_partials/operations/dashboard/projects-table.php'); ?>
</div>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

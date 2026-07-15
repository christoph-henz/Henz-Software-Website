<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'Verfügbarkeit - Getragen Begleiten');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');
$availabilityConfig = is_array($availabilityConfig ?? null) ? $availabilityConfig : [];

$extraHead = '<link rel="stylesheet" href="/ui/_assets/css/admin-availability.css" />';
$extraScripts = '<script>window.__ADMIN_AVAILABILITY_CONFIG = ' . json_encode(
    $availabilityConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-availability.js" defer></script>';

ob_start();
?>
<div class="admin-page-header admin-availability-header">
    <div>
        <h1 class="admin-page-title">Verfügbarkeitsmanagement</h1>
        <p class="admin-page-subtitle">Arbeitszeiten, Sperrzeiten und Regeln für automatische Slot-Generierung verwalten.</p>
    </div>
</div>

<div id="adminAvailabilityRoot" class="admin-availability-root"></div>
<?php
$content = ob_get_clean();

require base_path('public/ui/_partials/operations/admin-layout.php');

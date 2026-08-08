<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'Leistungen - Henz Software');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');
$servicesConfig = is_array($servicesConfig ?? null) ? $servicesConfig : [];

$extraHead = '<link rel="stylesheet" href="/ui/_assets/css/admin-services.css" />';
$extraScripts = '<script>window.__ADMIN_SERVICES_CONFIG = ' . json_encode(
    $servicesConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-services.js" defer></script>';

ob_start();
?>
<div class="admin-page-header admin-services-header">
    <div>
        <h1 class="admin-page-title">Leistungen</h1>
        <p class="admin-page-subtitle">Zwei untereinanderliegende Bereiche für Services und Referenzprojekte. Sichtbar nur mit passender Berechtigung.</p>
    </div>
</div>

<div id="adminServicesRoot" class="admin-services-root"></div>
<?php
$content = ob_get_clean();

require base_path('public/ui/_partials/operations/admin-layout.php');
 
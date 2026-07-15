<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'Clientverwaltung - Getragen Begleiten');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');
$clientsConfig = is_array($clientsConfig ?? null) ? $clientsConfig : [];

$extraHead = '<link rel="stylesheet" href="/ui/_assets/css/admin-clients.css" />';
$extraScripts = '<script>window.__ADMIN_CLIENTS_CONFIG = ' . json_encode(
    $clientsConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-clients.js" defer></script>';

ob_start();
?>
<div class="admin-page-header">
    <h1 class="admin-page-title">Clientverwaltung</h1>
    <p class="admin-page-subtitle">Client auswählen, Klientenakte vollständig einsehen, Formulardaten pflegen und Export erstellen.</p>
</div>

<?php require base_path('public/ui/_partials/operations/clients/table.php'); ?>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

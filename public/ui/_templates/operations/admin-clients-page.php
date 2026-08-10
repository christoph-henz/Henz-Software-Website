<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'Clientverwaltung - Henz Software');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');
$clientsConfig = is_array($clientsConfig ?? null) ? $clientsConfig : [];
$viewMode = (string) ($clientsConfig['view_mode'] ?? 'list');

$pageHeading = 'Clientverwaltung';
$pageSubheading = 'Client auswählen, Klientenakte vollständig einsehen, Formulardaten pflegen und Export erstellen.';
if ($viewMode === 'tickets') {
    $pageHeading = 'Tickets';
    $pageSubheading = 'Zentrale Ticketliste mit direkter Verlinkung in die jeweilige Klientenakte.';
}

$extraHead = '<link rel="stylesheet" href="/ui/_assets/css/admin-clients.css" /><link rel="icon" type="image/svg+xml" href="/ui/_assets/images/favicon.svg" />';
$extraScripts = '<script>window.__ADMIN_CLIENTS_CONFIG = ' . json_encode(
    $clientsConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-clients.js" defer></script>';

ob_start();
?>
<div class="admin-page-header">
    <h1 class="admin-page-title"><?php echo htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="admin-page-subtitle"><?php echo htmlspecialchars($pageSubheading, ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php require base_path('public/ui/_partials/operations/clients/table.php'); ?>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

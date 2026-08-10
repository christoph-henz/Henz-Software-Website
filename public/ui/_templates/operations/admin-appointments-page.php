<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'Termine - Henz Software');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');

$_displayName = trim(
    ((string) ($adminUser['first_name'] ?? '')) . ' ' . ((string) ($adminUser['last_name'] ?? ''))
);
$_displayName = $_displayName !== '' ? $_displayName : ((string) ($adminUser['email'] ?? 'Administrator'));

$appointmentsConfig = is_array($appointmentsConfig ?? null)
    ? $appointmentsConfig
    : (require base_path('public/ui/_config/operations/admin-appointments.php'));

$extraHead = '<link rel="icon" type="image/svg+xml" href="/ui/_assets/images/favicon.svg" />';
$extraScripts = '<script>window.__ADMIN_APPOINTMENTS_CONFIG = ' . json_encode(
    $appointmentsConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-operations-appointments.js" defer></script>';

ob_start();
?>
<div class="admin-page-header">
    <h1 class="admin-page-title">Appointments</h1>
    <p class="admin-page-subtitle">
        Willkommen zur Terminliste,
        <?= htmlspecialchars($_displayName, ENT_QUOTES, 'UTF-8'); ?>
    </p>
</div>

<div id="adminAppointmentsRoot" class="admin-appointments-shell" aria-live="polite"></div>
<?php
$content = (string) ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

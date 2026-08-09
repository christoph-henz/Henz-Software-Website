<?php

declare(strict_types=1);

/**
 * Admin layout partial – the single HTML frame for all admin pages.
 *
 * Every admin template must buffer its inner content and then require this file.
 * Example:
 *
 *   ob_start();
 *   // ... render inner HTML ...
 *   $content = ob_get_clean();
 *   require __DIR__ . '/../_partials/operations/admin-layout.php';
 *
 * Variables expected in scope:
 *   $pageTitle    (string)         – <title> content
 *   $adminUser    (array)          – session user: id, first_name, last_name, email, role_mask
 *   $csrfToken    (string)         – CSRF token (for logout form in topbar)
 *   $logoutAction (string)         – POST action for logout form
 *   $content      (string)         – pre-rendered inner HTML
 *   $extraHead    (string, opt.)   – additional <head> tags (e.g. page-specific CSS)
 *   $extraScripts (string, opt.)   – additional scripts before </body>
 */

$pageTitle    = (string) ($pageTitle ?? 'Adminpanel – Henz Software');
$adminUser    = is_array($adminUser ?? null) ? $adminUser : [];
$csrfToken    = (string) ($csrfToken ?? '');
$logoutAction = (string) ($logoutAction ?? '/logout');
$content      = (string) ($content ?? '');
$extraHead    = (string) ($extraHead ?? '');
$extraScripts = (string) ($extraScripts ?? '');

?><!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    
    <!-- PWA Manifest and Mobile Web App -->
    <link rel="manifest" href="/manifest.json" />
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#060a0f" />
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f4f8fc" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="Praxis" />
    <link rel="apple-touch-icon" href="/ui/_assets/images/pwa-icon-192.png" />
    
    <link rel="stylesheet" href="/ui/_assets/css/theme.css" />
    <link rel="stylesheet" href="/ui/_assets/css/tailwind.css" />
    <link rel="stylesheet" href="/ui/_assets/css/admin-layout.css" />
    <link rel="stylesheet" href="/ui/_assets/css/pwa-styles.css" />
    <?= $extraHead ?>
</head>
<body class="admin-page">

<div class="admin-overlay" id="adminOverlay" aria-hidden="true"></div>

<div class="admin-shell">

    <?php require __DIR__ . '/admin-sidebar.php'; ?>

    <div class="admin-main">

        <?php require __DIR__ . '/admin-topbar.php'; ?>

        <div class="admin-content">
            <?php require __DIR__ . '/admin-notification.php'; ?>
            <?= $content ?>
        </div>

    </div>

</div>

<?php if (($includeGlobalAdminModal ?? true) === true): ?>
<?php require __DIR__ . '/admin-modal.php'; ?>
<?php endif; ?>

<div id="adminToastContainer" class="admin-toast-container fixed right-4 bottom-4 z-80 flex w-[min(92vw,24rem)] flex-col gap-2 pointer-events-none" aria-live="polite" aria-label="Benachrichtigungen"></div>

<script src="/ui/_assets/js/admin-ui.js" defer></script>
<script src="/ui/_assets/js/pwa-register.js" defer></script>
<?= $extraScripts ?>

</body>
</html>

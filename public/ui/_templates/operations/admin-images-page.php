<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'Bilderverwaltung - Getragen Begleiten');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');
$imagesConfig = is_array($imagesConfig ?? null) ? $imagesConfig : [];

$extraHead = '<link rel="stylesheet" href="/ui/_assets/css/admin-images.css" />';
$extraScripts = '<script>window.__ADMIN_IMAGES_CONFIG = ' . json_encode(
    $imagesConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-images.js" defer></script>';

ob_start();
?>
<div class="admin-page-header admin-images-header">
    <div>
        <h1 class="admin-page-title">Bilderverwaltung</h1>
        <p class="admin-page-subtitle">Upload, Aktiv/Inaktiv, Alt-Text und Seitenzuweisung in einem Bereich.</p>
    </div>
</div>

<section class="admin-images-layout" id="adminImagesRoot" data-admin-images-root="1">
    <div class="admin-images-left">
        <?php require base_path('public/ui/_partials/operations/images/upload-form.php'); ?>
        <?php require base_path('public/ui/_partials/operations/images/grid.php'); ?>
    </div>

    <div class="admin-images-right">
        <?php require base_path('public/ui/_partials/operations/images/asset-detail.php'); ?>
    </div>
</section>

<?php require base_path('public/ui/_partials/operations/images/assign-modal.php'); ?>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'Bilderverwaltung - Henz Software');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');
$imagesConfig = is_array($imagesConfig ?? null) ? $imagesConfig : [];

$extraScripts = '<script>window.__ADMIN_IMAGES_CONFIG = ' . json_encode(
    $imagesConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-images.js" defer></script>';

ob_start();
?>
<div class="mb-6 flex flex-col gap-3 rounded-2xl border border-cyan-500/15 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 p-5 shadow-[0_24px_60px_rgba(0,0,0,0.28)] lg:flex-row lg:items-end lg:justify-between">
    <div class="max-w-3xl">
        <h1 class="text-3xl font-semibold tracking-tight text-slate-50">Bilderverwaltung</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Upload, Aktiv/Inaktiv, Alt-Text und Seitenzuweisung in einem Bereich.</p>
    </div>
</div>

<section class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]" id="adminImagesRoot" data-admin-images-root="1">
    <div class="space-y-6">
        <?php require base_path('public/ui/_partials/operations/images/upload-form.php'); ?>
        <?php require base_path('public/ui/_partials/operations/images/grid.php'); ?>
    </div>

    <div class="space-y-6 xl:sticky xl:top-6 xl:self-start">
        <?php require base_path('public/ui/_partials/operations/images/asset-detail.php'); ?>
    </div>
</section>

<?php require base_path('public/ui/_partials/operations/images/assign-modal.php'); ?>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

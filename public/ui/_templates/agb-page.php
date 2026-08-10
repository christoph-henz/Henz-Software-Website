<?php

declare(strict_types=1);

$cfg = require base_path('public/ui/_config/legal/agb.php');
$pageTitle = (string) ($cfg['page_title'] ?? 'AGB – Henz Software Solutions');
$pageDescription = (string) ($cfg['page_description'] ?? 'Allgemeine Geschäftsbedingungen von Henz Software Solutions.');
$siteUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
$pageSeo = [
    'title' => $pageTitle,
    'description' => $pageDescription,
    'canonical' => $siteUrl . '/agb',
    'og_type' => 'website',
];
?><!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
<?php require __DIR__ . '/../_partials/seo-head.php'; ?>
    <link rel="icon" type="image/svg+xml" href="/ui/_assets/images/favicon.svg" />
    <link rel="stylesheet" href="/ui/_assets/css/theme.css" />
    <link rel="stylesheet" href="/ui/_assets/css/tailwind.css" />
</head>
<body>
    <?php require __DIR__ . '/../_partials/navbar.php'; ?>

    <main class="gb-main sp-main">
        <?php require __DIR__ . '/../_partials/legal-content.php'; ?>
    </main>

    <?php require __DIR__ . '/../_partials/footer.php'; ?>
    <script src="/ui/_assets/js/navbar.js" defer></script>
</body>
</html>

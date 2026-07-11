<?php

declare(strict_types=1);

$cfg = require base_path('public/ui/_config/legal/widerruf.php');
$pageTitle = (string) ($cfg['page_title'] ?? 'Widerrufsbelehrung – Henz Software Solutions');
?><!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
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

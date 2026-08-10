<?php

declare(strict_types=1);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Henz Software</title>
  <link rel="preload" href="/ui/_assets/css/theme.css" as="style" onload="this.onload=null;this.rel='stylesheet'" />
  <link rel="preload" href="/ui/_assets/css/tailwind.css" as="style" onload="this.onload=null;this.rel='stylesheet'" />
  <noscript>
    <link rel="stylesheet" href="/ui/_assets/css/theme.css" />
    <link rel="stylesheet" href="/ui/_assets/css/tailwind.css" />
  </noscript>
</head>
<body<?= in_array(strtolower(trim((string) ($_COOKIE['hs_essential_cookies'] ?? ''))), ['accepted', '1', 'true', 'yes'], true) ? '' : ' class="gb-essential-cookie-locked"'; ?>>
  <?php require __DIR__ . '/../_partials/navbar.php'; ?>
  <?php require __DIR__ . '/../_partials/cookie-banner.php'; ?>

  <main class="gb-main gb-home-main">
    <?php require __DIR__ . '/../_partials/hero-section.php'; ?>
    <?php require __DIR__ . '/../_partials/hero-console-section.php'; ?>
    <?php require __DIR__ . '/../_partials/experience-section.php'; ?>
    <?php require __DIR__ . '/../_partials/service-section.php'; ?>
    <?php require __DIR__ . '/../_partials/project-section.php'; ?>
    <?php require __DIR__ . '/../_partials/technology-section.php'; ?>
    <?php require __DIR__ . '/../_partials/feedback-section.php'; ?>
    <?php require __DIR__ . '/../_partials/contact-section.php'; ?>
  </main>

  <?php require __DIR__ . '/../_partials/footer.php'; ?>
  <script src="/ui/_assets/js/navbar.js" defer></script>
  <script src="/ui/_assets/js/project-media.js" defer></script>
</body>
</html>

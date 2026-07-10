<?php

declare(strict_types=1);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Henz Software</title>
  <link rel="stylesheet" href="/ui/_assets/css/theme.css" />
  <link rel="stylesheet" href="/ui/_assets/css/tailwind.css" />
</head>
<body>
  <?php require __DIR__ . '/../_partials/navbar.php'; ?>

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
</body>
</html>

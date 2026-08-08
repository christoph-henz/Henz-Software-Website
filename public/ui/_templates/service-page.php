<?php

declare(strict_types=1);
$services = service_page_sections();
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
    <?php foreach ($services as $service): ?>
      <?php require __DIR__ . '/../_partials/_service-section-renderer.php'; ?>
    <?php endforeach; ?>
  </main>

  <?php require __DIR__ . '/../_partials/footer.php'; ?>
  <script src="/ui/_assets/js/navbar.js" defer></script>
</body>
</html>

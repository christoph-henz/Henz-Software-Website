<?php

declare(strict_types=1);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars((string) ($project['title'] ?? 'Projekt'), ENT_QUOTES, 'UTF-8'); ?> | Henz Software</title>
  <link rel="stylesheet" href="/ui/_assets/css/theme.css" />
  <link rel="stylesheet" href="/ui/_assets/css/tailwind.css" />
</head>
<body>
  <?php $pageProject = is_array($project ?? null) ? $project : []; ?>
  <?php require __DIR__ . '/../_partials/navbar.php'; ?>

  <main class="gb-main gb-home-main">
    <?php $service = $pageProject; ?>
    <?php require __DIR__ . '/../_partials/_service-section-renderer.php'; ?>
  </main>

  <?php require __DIR__ . '/../_partials/footer.php'; ?>
  <script src="/ui/_assets/js/navbar.js" defer></script>
</body>
</html>
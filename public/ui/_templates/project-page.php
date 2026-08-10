<?php

declare(strict_types=1);
$pageProject = is_array($project ?? null) ? $project : [];
$siteUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
$projectSlug = trim((string) ($pageProject['route_slug'] ?? $pageProject['slug'] ?? ''));
$pageTitle = trim((string) ($pageProject['title'] ?? 'Projekt'));
$pageDescription = trim((string) ($pageProject['description'] ?? ''));
$pageSeo = [
  'title' => $pageTitle,
  'description' => $pageDescription !== '' ? $pageDescription : ('Projektseite zu ' . $pageTitle),
  'canonical' => $projectSlug !== '' ? $siteUrl . '/' . ltrim($projectSlug, '/') : $siteUrl . '/',
  'og_type' => 'article',
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

  <main class="gb-main gb-home-main">
    <?php $service = $pageProject; ?>
    <?php require __DIR__ . '/../_partials/_service-section-renderer.php'; ?>
  </main>

  <?php require __DIR__ . '/../_partials/footer.php'; ?>
  <script src="/ui/_assets/js/navbar.js" defer></script>
</body>
</html>
<?php

declare(strict_types=1);

$pageSeo = is_array($pageSeo ?? null) ? $pageSeo : [];
$siteName = (string) ($pageSeo['site_name'] ?? config('app.name', 'Henz Software'));
$title = trim((string) ($pageSeo['title'] ?? ''));
$description = trim((string) ($pageSeo['description'] ?? ''));
$canonical = trim((string) ($pageSeo['canonical'] ?? ''));
$robots = trim((string) ($pageSeo['robots'] ?? 'index,follow'));
$ogType = trim((string) ($pageSeo['og_type'] ?? 'website'));
$ogImage = trim((string) ($pageSeo['og_image'] ?? ''));
$twitterCard = trim((string) ($pageSeo['twitter_card'] ?? ($ogImage !== '' ? 'summary_large_image' : 'summary')));
$jsonLd = is_array($pageSeo['json_ld'] ?? null) ? $pageSeo['json_ld'] : [];

if ($title === '') {
    $title = $siteName;
}

if (!str_contains($title, $siteName)) {
    $title .= ' | ' . $siteName;
}

?>
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<?php if ($description !== ''): ?>
  <meta name="description" content="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>" />
<?php endif; ?>
  <meta name="robots" content="<?= htmlspecialchars($robots, ENT_QUOTES, 'UTF-8'); ?>" />
<?php if ($canonical !== ''): ?>
  <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>" />
<?php endif; ?>
  <link rel="describedby" href="<?= htmlspecialchars(site_url('/llms.txt'), ENT_QUOTES, 'UTF-8'); ?>" />
  <meta property="og:site_name" content="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta property="og:title" content="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" />
<?php if ($description !== ''): ?>
  <meta property="og:description" content="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>" />
<?php endif; ?>
  <meta property="og:type" content="<?= htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8'); ?>" />
<?php if ($canonical !== ''): ?>
  <meta property="og:url" content="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>" />
<?php endif; ?>
<?php if ($ogImage !== ''): ?>
  <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>" />
<?php endif; ?>
  <meta name="twitter:card" content="<?= htmlspecialchars($twitterCard, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta name="twitter:title" content="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" />
<?php if ($description !== ''): ?>
  <meta name="twitter:description" content="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>" />
<?php endif; ?>
<?php if ($jsonLd !== []): ?>
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
<?php endif; ?>
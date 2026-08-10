<?php

declare(strict_types=1);

$cfg = require base_path('public/ui/_config/technology.php');
$slug = (string) ($cfg['slug'] ?? 'Tech Stack');
$title = (string) ($cfg['title'] ?? 'Technologien');
$intro = (string) ($cfg['intro'] ?? '');
$total = (int) ($cfg['total'] ?? 0);
$highlights = is_array($cfg['highlights'] ?? null) ? $cfg['highlights'] : [];
$technologies = is_array($cfg['technologies'] ?? null) ? $cfg['technologies'] : [];
$siteUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
$pageSeo = [
  'title' => 'Technologien',
  'description' => $intro !== '' ? $intro : 'Ein Überblick über die Technologien, die bei Henz Software produktiv eingesetzt werden.',
  'canonical' => $siteUrl . '/technology',
  'og_type' => 'website',
];

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
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
    <section class="relative overflow-hidden py-24 lg:py-32">
      <div class="absolute inset-0 pointer-events-none opacity-[0.04]" style="background-image: linear-gradient(rgb(0, 200, 255) 1px, transparent 1px), linear-gradient(90deg, rgb(0, 200, 255) 1px, transparent 1px); background-size: 56px 56px;"></div>
      <div class="absolute top-20 right-0 w-[480px] h-[480px] rounded-full opacity-10 pointer-events-none" style="background: radial-gradient(circle, rgb(0, 200, 255) 0%, transparent 70%); filter: blur(90px);"></div>

      <div class="relative z-10 mx-auto max-w-7xl px-6">
        <div class="mx-auto mb-10 max-w-3xl text-center">
          <p class="mb-3 text-xs uppercase tracking-[0.3em]" style="font-family: 'JetBrains Mono', monospace; color: rgb(90, 116, 148);">
            // <?= $escape($slug); ?>
          </p>
          <h1 class="text-3xl font-bold tracking-tight sm:text-4xl lg:text-5xl" style="font-family: 'JetBrains Mono', monospace; color: rgb(232, 237, 245);">
            <?= $escape($title); ?>
          </h1>
          <p class="mt-4 text-base leading-relaxed sm:text-lg" style="color: rgb(140, 160, 185);">
            <?= $intro !== '' ? $escape($intro) : 'Auf dieser Seite werden Technologien aus der Datenquelle technology mit Fokus auf praktischer Einordnung dargestellt.'; ?>
          </p>

          <div class="mt-7 inline-flex flex-wrap items-center justify-center gap-2 rounded-2xl border px-4 py-3" style="border-color: rgba(0, 200, 255, 0.2); background: rgba(6, 10, 15, 0.62);">
            <span class="rounded-lg border px-3 py-1 text-xs uppercase tracking-[0.22em]" style="font-family: 'JetBrains Mono', monospace; color: rgb(0, 200, 255); border-color: rgba(0, 200, 255, 0.28);">
              <?= $escape((string) $total); ?> Technologien
            </span>
            <?php foreach ($highlights as $highlight): ?>
              <?php $text = trim((string) $highlight); if ($text === '') { continue; } ?>
              <span class="rounded-lg border px-3 py-1 text-xs" style="font-family: 'JetBrains Mono', monospace; color: rgb(160, 180, 205); border-color: rgba(90, 116, 148, 0.35);">
                <?= $escape($text); ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          <?php foreach ($technologies as $tech): ?>
            <?php
            if (!is_array($tech)) {
                continue;
            }

            $label = trim((string) ($tech['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $category = trim((string) ($tech['category'] ?? 'Weitere'));
            $level = trim((string) ($tech['level'] ?? ''));
            $description = trim((string) ($tech['description'] ?? ''));
            ?>
            <article class="rounded-2xl border p-5 shadow-[0_18px_40px_rgba(0,0,0,0.25)]" style="border-color: rgba(0, 200, 255, 0.18); background: linear-gradient(180deg, rgba(6, 10, 15, 0.95), rgba(10, 16, 26, 0.9));">
              <header class="mb-3 flex items-start justify-between gap-3">
                <h2 class="text-lg font-semibold leading-tight" style="font-family: 'JetBrains Mono', monospace; color: rgb(232, 237, 245);">
                  <?= $escape($label); ?>
                </h2>
                <?php if ($level !== ''): ?>
                  <span class="shrink-0 rounded-md border px-2.5 py-1 text-xs" style="font-family: 'JetBrains Mono', monospace; color: rgb(0, 200, 255); border-color: rgba(0, 200, 255, 0.35);">
                    <?= $escape($level); ?>
                  </span>
                <?php endif; ?>
              </header>

              <p class="mb-4 text-xs uppercase tracking-[0.22em]" style="font-family: 'JetBrains Mono', monospace; color: rgb(90, 116, 148);">
                <?= $escape($category !== '' ? $category : 'Weitere'); ?>
              </p>

              <p class="text-sm leading-relaxed" style="color: rgb(180, 196, 216);">
                <?= $escape($description !== '' ? $description : ($label . ' wird in unseren Projekten produktiv eingesetzt.')); ?>
              </p>
            </article>
          <?php endforeach; ?>
        </div>

        <?php if ($technologies === []): ?>
          <p class="mt-10 text-center text-sm" style="color: rgb(160, 180, 205);">Keine Technologien verfuegbar.</p>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <?php require __DIR__ . '/../_partials/footer.php'; ?>
  <script src="/ui/_assets/js/navbar.js" defer></script>
</body>
</html>

<?php

declare(strict_types=1);

$code = isset($code) ? (int) $code : 400;
$httpStatus = isset($httpStatus) ? (int) $httpStatus : $code;
$title = isset($title) ? (string) $title : 'Anfrage konnte nicht verarbeitet werden';
$message = isset($message) ? (string) $message : 'Bitte prüfe die Anfrage und versuche es erneut.';
$supportRequestId = isset($supportRequestId) ? trim((string) $supportRequestId) : '';
$hints = isset($hints) && is_array($hints) ? $hints : [];

http_response_code($httpStatus);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8'); ?> | <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="/ui/_assets/css/theme.css" />
  <link rel="stylesheet" href="/ui/_assets/css/tailwind.css" />
</head>
<body class="min-h-screen bg-[#060a0f] text-[#e8edf5]">
  <?php require __DIR__ . '/../_partials/navbar.php'; ?>

  <main class="relative overflow-hidden pt-24">
    <div class="pointer-events-none absolute inset-0">
      <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(9,24,42,0.98),_rgba(6,10,15,1)_55%)]"></div>
      <div class="absolute -left-32 top-0 h-[28rem] w-[28rem] rounded-full bg-[#00c8ff]/16 blur-3xl"></div>
      <div class="absolute right-0 top-32 h-[24rem] w-[24rem] rounded-full bg-blue-400/12 blur-3xl"></div>
      <div class="absolute inset-0 opacity-[0.05]" style="background-image: linear-gradient(rgb(0, 200, 255) 1px, transparent 1px), linear-gradient(90deg, rgb(0, 200, 255) 1px, transparent 1px); background-size: 60px 60px;"></div>
    </div>

    <section class="relative mx-auto flex min-h-[calc(100vh-9rem)] max-w-7xl items-center px-6 py-16">
      <div class="grid w-full gap-10 lg:grid-cols-[1.15fr_0.85fr] lg:items-center">
        <div class="relative max-w-2xl overflow-hidden rounded-[2rem] border border-white/8 bg-[#09131d]/92 p-8 shadow-[0_30px_80px_rgba(0,0,0,0.45)] backdrop-blur-xl md:p-10">
          <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(135deg,rgba(0,200,255,0.08),transparent_45%,rgba(255,255,255,0.02))]"></div>
          <div class="relative mb-6 inline-flex items-center gap-3 rounded-full border border-[#00c8ff]/30 bg-[#0b1b29] px-4 py-2 text-xs uppercase tracking-[0.24em] text-[#7ee9ff]">
            <span class="h-2 w-2 rounded-full bg-[#00c8ff] shadow-[0_0_18px_rgba(0,200,255,0.9)]"></span>
            System Response
          </div>

          <p class="relative mb-4 font-mono text-sm text-[#9db8d6]">
            ERROR / HTTP <?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8'); ?>
          </p>

          <h1 class="relative mb-6 max-w-xl font-mono text-5xl font-bold leading-tight text-[#f7fbff] drop-shadow-[0_6px_20px_rgba(0,0,0,0.35)] md:text-6xl lg:text-7xl">
            <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
          </h1>

          <p class="relative mb-8 max-w-xl text-lg leading-8 text-[#d8e4f2] md:text-xl">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
          </p>

          <?php if ($supportRequestId !== '' && $supportRequestId !== 'n/a'): ?>
          <div class="relative mb-8 inline-flex items-center gap-3 rounded-2xl border border-[#00c8ff]/20 bg-[#0d1b28] px-4 py-3 font-mono text-sm text-[#eef5ff] backdrop-blur-sm">
            <span class="text-[#7ee9ff]">Support-ID</span>
            <span><?= htmlspecialchars($supportRequestId, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <?php endif; ?>

          <div class="relative flex flex-wrap gap-4">
            <a
              class="inline-flex items-center justify-center rounded-lg bg-[#00c8ff] px-7 py-3.5 font-mono text-sm font-semibold text-[#031018] shadow-[0_12px_30px_rgba(0,200,255,0.28)] transition-transform duration-200 hover:-translate-y-0.5"
              href="/"
            >
              Zur Startseite
            </a>
            <a
              class="inline-flex items-center justify-center rounded-lg border border-[#8da8c7]/30 bg-[#0b1622] px-7 py-3.5 text-sm font-medium text-[#f4f8fc] transition-colors duration-200 hover:border-[#00c8ff]/35 hover:bg-[#0e2233]"
              href="javascript:history.back()"
            >
              Zurück
            </a>
          </div>
        </div>

        <aside class="relative">
          <div class="absolute inset-0 rounded-[2rem] bg-[#00c8ff]/10 blur-2xl"></div>
          <div class="relative overflow-hidden rounded-[2rem] border border-[#00c8ff]/18 bg-[#0b1623]/95 p-6 shadow-2xl backdrop-blur-xl">
            <div class="mb-6 flex items-center justify-between border-b border-[#00c8ff]/10 pb-4">
              <div>
                <p class="font-mono text-xs uppercase tracking-[0.24em] text-[#9db8d6]">Diagnostics</p>
                <p class="mt-2 font-mono text-2xl font-bold text-[#f7fbff]">
                  <?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8'); ?>
                </p>
              </div>
              <div class="rounded-xl border border-[#00c8ff]/20 bg-[#0b1b29] px-3 py-2 font-mono text-xs text-[#7ee9ff]">
                blocked
              </div>
            </div>

            <?php if ($hints !== []): ?>
            <div class="space-y-3">
              <?php foreach ($hints as $index => $hint): ?>
              <div class="flex gap-4 rounded-2xl border border-[#183048] bg-[#0f1c2b] p-4">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-[#00c8ff]/20 bg-[#0a1722] font-mono text-sm text-[#7ee9ff]">
                  <?= $index + 1; ?>
                </div>
                <p class="text-sm leading-7 text-[#e4edf8]">
                  <?= htmlspecialchars((string) $hint, ENT_QUOTES, 'UTF-8'); ?>
                </p>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-sm leading-7 text-[#c6d3e3]">
              Für diese Fehlersituation wurden keine zusätzlichen Hinweise hinterlegt.
            </p>
            <?php endif; ?>
          </div>
        </aside>
      </div>
    </section>
  </main>

  <?php require __DIR__ . '/../_partials/footer.php'; ?>
  <script src="/ui/_assets/js/navbar.js" defer></script>
</body>
</html>
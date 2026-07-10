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
  <link rel="stylesheet" href="/ui/_assets/css/error-theme.css" />
</head>
<body>
  <?php require __DIR__ . '/../_partials/navbar.php'; ?>

  <main class="gb-main">
    <section class="gb-container gb-error-card" aria-labelledby="err-title">
      <p class="gb-code"><?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8'); ?></p>
      <h1 class="gb-title" id="err-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
      <p class="gb-message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php if ($supportRequestId !== '' && $supportRequestId !== 'n/a'): ?>
      <p class="gb-message">Support-ID: <?= htmlspecialchars($supportRequestId, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php endif; ?>

      <?php if ($hints !== []): ?>
      <ul class="gb-hints">
        <?php foreach ($hints as $hint): ?>
          <li><?= htmlspecialchars((string) $hint, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <div class="gb-actions">
        <a class="gb-btn primary" href="/">Zur Startseite</a>
        <a class="gb-btn" href="javascript:history.back()">Zurück</a>
      </div>
    </section>
  </main>

  <?php require __DIR__ . '/../_partials/footer.php'; ?>
  <script src="/ui/_assets/js/navbar.js" defer></script>
</body>
</html>

<?php

declare(strict_types=1);

$hour = (int) date('G');
if ($hour >= 5 && $hour < 12) {
    $time_greeting = 'Guten Morgen';
} elseif ($hour >= 12 && $hour < 18) {
    $time_greeting = 'Guten Tag';
} else {
    $time_greeting = 'Guten Abend';
}

$welcomeName = (string) ($welcomeName ?? 'Administrator');
?>
<div class="space-y-2">
    <div class="text-xs uppercase tracking-[0.2em] text-muted-foreground" style="font-family: 'JetBrains Mono', monospace;">
        // dashboard
    </div>
    <h1 class="text-2xl font-bold text-foreground lg:text-3xl" style="font-family: 'JetBrains Mono', monospace;">
        <?= $time_greeting ?>, <span class="text-primary"><?= htmlspecialchars($welcomeName, ENT_QUOTES, 'UTF-8'); ?></span>
    </h1>
</div>

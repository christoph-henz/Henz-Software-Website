<?php

declare(strict_types=1);

$revenueSeries = is_array($revenueSeries ?? null) ? $revenueSeries : [];
$revenueSummary = is_array($revenueSummary ?? null) ? $revenueSummary : [];
$maxAmount = 1;
foreach ($revenueSeries as $point) {
    $value = (int) ($point['amount'] ?? 0);
    if ($value > $maxAmount) {
        $maxAmount = $value;
    }
}
?>
<section class="rounded-xl border border-border bg-card p-5 lg:col-span-2">
    <div class="mb-5 flex items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-foreground">Umsatz diese Woche</h2>
            <p class="mt-1 text-xs text-muted-foreground" style="font-family: 'JetBrains Mono', monospace;"><?= htmlspecialchars((string) ($revenueSummary['week_total_label'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <span class="rounded-md border border-emerald-400/25 bg-emerald-400/10 px-2.5 py-1 text-xs text-emerald-400" style="font-family: 'JetBrains Mono', monospace;">
            <?= htmlspecialchars((string) ($revenueSummary['week_delta_label'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
        </span>
    </div>

    <?php if ($revenueSeries === []): ?>
        <p class="rounded-lg border border-border bg-background/20 p-4 text-xs text-muted-foreground">
            Keine Umsatzdaten verfuegbar.
        </p>
    <?php else: ?>
        <div class="flex h-44 items-end gap-3">
            <?php foreach ($revenueSeries as $point): ?>
                <?php
                $amount = (int) ($point['amount'] ?? 0);
                $height = max(12, (int) round(($amount / $maxAmount) * 100));
                ?>
                <div class="flex flex-1 flex-col items-center gap-2">
                    <div class="w-full rounded-md bg-primary/15 px-1 pt-1">
                        <div class="w-full rounded-sm bg-primary" style="height: <?= $height; ?>px;"></div>
                    </div>
                    <span class="text-[11px] text-muted-foreground" style="font-family: 'JetBrains Mono', monospace;">
                        <?= htmlspecialchars((string) ($point['day'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

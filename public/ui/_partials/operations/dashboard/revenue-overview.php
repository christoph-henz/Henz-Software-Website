<?php

declare(strict_types=1);

$revenueSeries = is_array($revenueSeries ?? null) ? $revenueSeries : [];
$maxAmount = 1;
foreach ($revenueSeries as $point) {
    $value = (int) ($point['amount'] ?? 0);
    if ($value > $maxAmount) {
        $maxAmount = $value;
    }
}
?>
<section class="rounded-xl border border-[#00c8ff]/10 bg-[#0c1520] p-5 lg:col-span-2">
    <div class="mb-5 flex items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-[#e8edf5]">Umsatz diese Woche</h2>
            <p class="mt-1 text-xs text-[#5a7494]" style="font-family: 'JetBrains Mono', monospace;">EUR 94.2k gesamt</p>
        </div>
        <span class="rounded-md border border-emerald-400/25 bg-emerald-400/10 px-2.5 py-1 text-xs text-emerald-400" style="font-family: 'JetBrains Mono', monospace;">
            +12.4%
        </span>
    </div>

    <div class="flex h-44 items-end gap-3">
        <?php foreach ($revenueSeries as $point): ?>
            <?php
            $amount = (int) ($point['amount'] ?? 0);
            $height = max(12, (int) round(($amount / $maxAmount) * 100));
            ?>
            <div class="flex flex-1 flex-col items-center gap-2">
                <div class="w-full rounded-md bg-[#00c8ff]/15 px-1 pt-1">
                    <div class="w-full rounded-sm bg-[#00c8ff]" style="height: <?= $height; ?>px;"></div>
                </div>
                <span class="text-[11px] text-[#5a7494]" style="font-family: 'JetBrains Mono', monospace;">
                    <?= htmlspecialchars((string) ($point['day'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

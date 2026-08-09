<?php

declare(strict_types=1);

$kpiCards = is_array($kpiCards ?? null) ? $kpiCards : [];

$colorMap = [
    'cyan' => ['icon' => 'text-primary', 'bg' => 'bg-primary/10', 'delta' => 'text-primary'],
    'amber' => ['icon' => 'text-amber-400', 'bg' => 'bg-amber-400/10', 'delta' => 'text-amber-400'],
    'emerald' => ['icon' => 'text-emerald-400', 'bg' => 'bg-emerald-400/10', 'delta' => 'text-emerald-400'],
    'violet' => ['icon' => 'text-violet-400', 'bg' => 'bg-violet-400/10', 'delta' => 'text-violet-400'],
];
?>
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach ($kpiCards as $card): ?>
        <?php
        $colorKey = (string) ($card['color'] ?? 'cyan');
        $palette = $colorMap[$colorKey] ?? $colorMap['cyan'];
        ?>
        <article class="rounded-xl border border-border bg-card p-5">
            <div class="mb-4 flex items-center justify-between">
            <p class="text-xs text-muted-foreground"><?= htmlspecialchars((string) ($card['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <a href="<?= htmlspecialchars((string) ($card['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex h-8 w-8 items-center justify-center rounded-lg <?= $palette['bg']; ?> <?= $palette['icon']; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 12h16" />
                        <path d="M12 4v16" />
                    </svg>
                </a>
            </div>
            <p class="text-2xl font-bold text-foreground" style="font-family: 'JetBrains Mono', monospace;">
                <?= htmlspecialchars((string) ($card['value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <p class="mt-1 text-xs <?= $palette['delta']; ?>" style="font-family: 'JetBrains Mono', monospace;">
                <?= htmlspecialchars((string) ($card['delta'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </p>
        </article>
    <?php endforeach; ?>
</div>

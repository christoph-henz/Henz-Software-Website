<?php

declare(strict_types=1);

$activityItems = is_array($activityItems ?? null) ? $activityItems : [];

$colorMap = [
    'cyan' => 'bg-cyan-400/15 text-cyan-300',
    'amber' => 'bg-amber-400/15 text-amber-300',
    'emerald' => 'bg-emerald-400/15 text-emerald-300',
    'violet' => 'bg-violet-400/15 text-violet-300',
];
?>
<section class="rounded-xl border border-border bg-card p-5">
    <h2 class="mb-4 text-sm font-semibold text-foreground">Letzte Aktivitäten</h2>
    <?php if ($activityItems === []): ?>
        <p class="rounded-lg border border-border bg-background/20 p-4 text-xs text-muted-foreground">
            Keine Aktivitaeten verfuegbar.
        </p>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($activityItems as $item): ?>
                <?php $badgeClass = $colorMap[(string) ($item['color'] ?? 'cyan')] ?? $colorMap['cyan']; ?>
                <article class="flex items-start gap-3 rounded-lg border border-border bg-background/20 p-3">
                    <span class="mt-0.5 inline-flex h-7 w-7 items-center justify-center rounded-lg <?= $badgeClass; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 8v4l3 3" />
                            <circle cx="12" cy="12" r="9" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-xs leading-snug text-foreground"><?= htmlspecialchars((string) ($item['text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="mt-1 text-[11px] text-muted-foreground" style="font-family: 'JetBrains Mono', monospace;">
                            <?= htmlspecialchars((string) ($item['time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

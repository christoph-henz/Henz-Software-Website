<?php

declare(strict_types=1);
?>
<section class="rounded-2xl border border-border bg-card p-5 shadow-[0_24px_60px_color-mix(in_srgb,var(--background)_65%,transparent)] backdrop-blur">
    <div class="mb-5 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold tracking-tight text-foreground">Assets</h2>
            <p class="mt-2 text-sm leading-6 text-muted-foreground">Kacheln mit Vorschau, Alt-Text und Status.</p>
        </div>
        <button type="button" class="inline-flex items-center justify-center rounded-xl border border-border bg-input-background px-4 py-2.5 text-sm font-medium text-foreground transition hover:border-primary/40 hover:bg-background/40 focus:outline-none focus:ring-2 focus:ring-primary/20" id="adminImagesRefreshBtn">Neu laden</button>
    </div>

    <div id="adminImagesGrid" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5" aria-live="polite"></div>
</section>

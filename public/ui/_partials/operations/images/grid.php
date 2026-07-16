<?php

declare(strict_types=1);
?>
<section class="rounded-2xl border border-cyan-500/15 bg-slate-950/80 p-5 shadow-[0_24px_60px_rgba(0,0,0,0.22)] backdrop-blur">
    <div class="mb-5 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold tracking-tight text-slate-50">Assets</h2>
            <p class="mt-2 text-sm leading-6 text-slate-400">Kacheln mit Vorschau, Alt-Text und Status.</p>
        </div>
        <button type="button" class="inline-flex items-center justify-center rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-2.5 text-sm font-medium text-slate-200 transition hover:border-cyan-400/40 hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-400/20" id="adminImagesRefreshBtn">Neu laden</button>
    </div>

    <div id="adminImagesGrid" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5" aria-live="polite"></div>
</section>

<?php

declare(strict_types=1);

$imagesConfig = is_array($imagesConfig ?? null) ? $imagesConfig : [];
$maxLabel = (string) ($imagesConfig['max_file_size_label'] ?? '5 MB');
$chunkLabel = (string) ($imagesConfig['upload_chunk_size_label'] ?? '500 KB');
?>
<section class="rounded-2xl border border-cyan-500/15 bg-slate-950/80 p-5 shadow-[0_24px_60px_rgba(0,0,0,0.22)] backdrop-blur">
    <div class="mb-5 space-y-2">
        <h2 class="text-lg font-semibold tracking-tight text-slate-50">Neues Bild hochladen</h2>
        <p class="text-sm leading-6 text-slate-400">Nur JPG, PNG, WebP und GIF bis <?php echo htmlspecialchars($maxLabel, ENT_QUOTES, 'UTF-8'); ?> (Stream-Upload in <?php echo htmlspecialchars($chunkLabel, ENT_QUOTES, 'UTF-8'); ?>-Batches).</p>
    </div>

    <form id="adminImagesUploadForm" class="space-y-4" novalidate>
        <label class="block space-y-2 text-sm font-medium text-slate-200">
            <span>Datei</span>
            <input
                class="block w-full rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20"
                id="adminImagesUploadFile"
                name="file"
                type="file"
                accept="image/jpeg,image/png,image/webp,image/gif"
                required
            />
        </label>

        <label class="block space-y-2 text-sm font-medium text-slate-200">
            <span>Alt-Text</span>
            <input
                class="block w-full rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20"
                id="adminImagesUploadAltText"
                name="alt_text"
                type="text"
                maxlength="255"
                placeholder="Kurze Beschreibung des Bildinhalts"
            />
        </label>

        <div id="adminImagesUploadHint" class="rounded-xl border border-dashed border-slate-700 bg-slate-900/60 px-4 py-3 text-sm text-slate-400" aria-live="polite"></div>

        <div id="adminImagesUploadProgressWrap" class="space-y-2" hidden>
            <div class="h-2 overflow-hidden rounded-full border border-slate-700 bg-slate-900" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Upload-Fortschritt">
                <div id="adminImagesUploadProgressBar" class="h-full rounded-full bg-gradient-to-r from-cyan-400 to-sky-300 transition-all duration-300" style="width: 0%;"></div>
            </div>
            <p id="adminImagesUploadProgressText" class="text-xs font-medium text-slate-400">0%</p>
        </div>

        <div class="flex justify-end">
            <button class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-cyan-400 to-sky-300 px-4 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-cyan-500/20 transition hover:-translate-y-0.5 hover:shadow-cyan-500/30 focus:outline-none focus:ring-2 focus:ring-cyan-400/30" type="submit">Upload starten</button>
        </div>
    </form>
</section>

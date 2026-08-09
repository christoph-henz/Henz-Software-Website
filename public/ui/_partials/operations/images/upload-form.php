<?php

declare(strict_types=1);

$imagesConfig = is_array($imagesConfig ?? null) ? $imagesConfig : [];
$maxLabel = (string) ($imagesConfig['max_file_size_label'] ?? '5 MB');
$chunkLabel = (string) ($imagesConfig['upload_chunk_size_label'] ?? '500 KB');
?>
<section class="rounded-2xl border border-border bg-card p-5 shadow-[0_24px_60px_color-mix(in_srgb,var(--background)_65%,transparent)] backdrop-blur">
    <div class="mb-5 space-y-2">
        <h2 class="text-lg font-semibold tracking-tight text-foreground">Neues Bild hochladen</h2>
        <p class="text-sm leading-6 text-muted-foreground">Nur JPG, PNG, WebP und GIF bis <?php echo htmlspecialchars($maxLabel, ENT_QUOTES, 'UTF-8'); ?> (Stream-Upload in <?php echo htmlspecialchars($chunkLabel, ENT_QUOTES, 'UTF-8'); ?>-Batches).</p>
    </div>

    <form id="adminImagesUploadForm" class="space-y-4" novalidate>
        <label class="block space-y-2 text-sm font-medium text-foreground">
            <span>Datei</span>
            <input
                class="block w-full rounded-xl border border-border bg-input-background px-4 py-3 text-sm text-foreground outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20"
                id="adminImagesUploadFile"
                name="file"
                type="file"
                accept="image/jpeg,image/png,image/webp,image/gif"
                required
            />
        </label>

        <label class="block space-y-2 text-sm font-medium text-foreground">
            <span>Alt-Text</span>
            <input
            class="block w-full rounded-xl border border-border bg-input-background px-4 py-3 text-sm text-foreground outline-none transition placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20"
                id="adminImagesUploadAltText"
                name="alt_text"
                type="text"
                maxlength="255"
                placeholder="Kurze Beschreibung des Bildinhalts"
            />
        </label>

        <div id="adminImagesUploadHint" class="rounded-xl border border-dashed border-border bg-input-background/70 px-4 py-3 text-sm text-muted-foreground" aria-live="polite"></div>

        <div id="adminImagesUploadProgressWrap" class="space-y-2" hidden>
            <div class="h-2 overflow-hidden rounded-full border border-border bg-input-background" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Upload-Fortschritt">
                <div id="adminImagesUploadProgressBar" class="h-full rounded-full bg-gradient-to-r from-cyan-400 to-sky-300 transition-all duration-300" style="width: 0%;"></div>
            </div>
            <p id="adminImagesUploadProgressText" class="text-xs font-medium text-muted-foreground">0%</p>
        </div>

        <div class="flex justify-end">
            <button class="inline-flex items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground shadow-lg shadow-primary/20 transition hover:-translate-y-0.5 hover:shadow-primary/30 focus:outline-none focus:ring-2 focus:ring-primary/30" type="submit">Upload starten</button>
        </div>
    </form>
</section>

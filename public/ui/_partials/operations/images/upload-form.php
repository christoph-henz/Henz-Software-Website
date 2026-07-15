<?php

declare(strict_types=1);

$imagesConfig = is_array($imagesConfig ?? null) ? $imagesConfig : [];
$maxLabel = (string) ($imagesConfig['max_file_size_label'] ?? '5 MB');
$chunkLabel = (string) ($imagesConfig['upload_chunk_size_label'] ?? '500 KB');
?>
<section class="admin-images-panel">
    <div class="admin-images-panel-head">
        <h2 class="admin-images-panel-title">Neues Bild hochladen</h2>
        <p class="admin-images-panel-subtitle">Nur JPG, PNG, WebP und GIF bis <?php echo htmlspecialchars($maxLabel, ENT_QUOTES, 'UTF-8'); ?> (Stream-Upload in <?php echo htmlspecialchars($chunkLabel, ENT_QUOTES, 'UTF-8'); ?>-Batches).</p>
    </div>

    <form id="adminImagesUploadForm" class="admin-images-upload-form" novalidate>
        <label class="admin-images-label">
            <span>Datei</span>
            <input
                class="admin-images-input"
                id="adminImagesUploadFile"
                name="file"
                type="file"
                accept="image/jpeg,image/png,image/webp,image/gif"
                required
            />
        </label>

        <label class="admin-images-label">
            <span>Alt-Text</span>
            <input
                class="admin-images-input"
                id="adminImagesUploadAltText"
                name="alt_text"
                type="text"
                maxlength="255"
                placeholder="Kurze Beschreibung des Bildinhalts"
            />
        </label>

        <div id="adminImagesUploadHint" class="admin-images-hint" aria-live="polite"></div>

        <div id="adminImagesUploadProgressWrap" class="admin-images-progress" hidden>
            <div class="admin-images-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Upload-Fortschritt">
                <div id="adminImagesUploadProgressBar" class="admin-images-progress-bar" style="width: 0%;"></div>
            </div>
            <p id="adminImagesUploadProgressText" class="admin-images-progress-text">0%</p>
        </div>

        <div class="admin-images-form-actions">
            <button class="admin-images-btn admin-images-btn--primary" type="submit">Upload starten</button>
        </div>
    </form>
</section>

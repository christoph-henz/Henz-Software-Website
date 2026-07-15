<?php

declare(strict_types=1);
?>
<section class="admin-images-panel">
    <div class="admin-images-panel-head admin-images-panel-head--row">
        <div>
            <h2 class="admin-images-panel-title">Assets</h2>
            <p class="admin-images-panel-subtitle">Kacheln mit Vorschau, Alt-Text und Status.</p>
        </div>
        <button type="button" class="admin-images-btn" id="adminImagesRefreshBtn">Neu laden</button>
    </div>

    <div id="adminImagesGrid" class="admin-images-grid" aria-live="polite"></div>
</section>

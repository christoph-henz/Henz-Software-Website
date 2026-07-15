<?php

declare(strict_types=1);
?>
<template id="adminImagesAssignModalTemplate">
    <form id="adminImagesAssignForm" class="admin-images-assign-form" novalidate>
        <label class="admin-images-label">
            <span>Seite</span>
            <select class="admin-images-select" name="page_key" id="adminImagesAssignPage" required></select>
        </label>

        <label class="admin-images-label">
            <span>Bereich</span>
            <select class="admin-images-select" name="section_key" id="adminImagesAssignSection" required></select>
        </label>

        <label class="admin-images-label">
            <span>Slot</span>
            <select class="admin-images-select" name="slot_key" id="adminImagesAssignSlot" required></select>
        </label>

        <input type="hidden" name="asset_id" id="adminImagesAssignAssetId" />
        <p class="admin-images-hint" id="adminImagesAssignHint"></p>
    </form>
</template>

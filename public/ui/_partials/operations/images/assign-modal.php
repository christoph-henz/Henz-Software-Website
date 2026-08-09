<?php

declare(strict_types=1);
?>
<template id="adminImagesAssignModalTemplate">
    <form id="adminImagesAssignForm" class="space-y-4" novalidate>
        <label class="block space-y-2 text-sm font-medium text-foreground">
            <span>Seite</span>
            <select class="block w-full rounded-xl border border-border bg-input-background px-4 py-3 text-sm text-foreground outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20" name="page_key" id="adminImagesAssignPage" required></select>
        </label>

        <label class="block space-y-2 text-sm font-medium text-foreground">
            <span>Bereich</span>
            <select class="block w-full rounded-xl border border-border bg-input-background px-4 py-3 text-sm text-foreground outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20" name="section_key" id="adminImagesAssignSection" required></select>
        </label>

        <label class="block space-y-2 text-sm font-medium text-foreground">
            <span>Slot</span>
            <select class="block w-full rounded-xl border border-border bg-input-background px-4 py-3 text-sm text-foreground outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20" name="slot_key" id="adminImagesAssignSlot" required></select>
        </label>

        <input type="hidden" name="asset_id" id="adminImagesAssignAssetId" />

        <p id="adminImagesAssignHint" class="rounded-xl border border-dashed border-border bg-input-background/70 px-4 py-3 text-sm text-muted-foreground" aria-live="polite">
            Wählen Sie eine Zielseite, einen Bereich und einen Slot.
        </p>
    </form>
</template>

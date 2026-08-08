<?php

declare(strict_types=1);
?>
<template id="adminImagesAssignModalTemplate">
    <form id="adminImagesAssignForm" class="space-y-4" novalidate>
        <label class="block space-y-2 text-sm font-medium text-slate-200">
            <span>Seite</span>
            <select class="block w-full rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20" name="page_key" id="adminImagesAssignPage" required></select>
        </label>

        <label class="block space-y-2 text-sm font-medium text-slate-200">
            <span>Bereich</span>
            <select class="block w-full rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20" name="section_key" id="adminImagesAssignSection" required></select>
        </label>

        <label class="block space-y-2 text-sm font-medium text-slate-200">
            <span>Slot</span>
            <select class="block w-full rounded-xl border border-slate-700 bg-slate-900/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20" name="slot_key" id="adminImagesAssignSlot" required></select>
        </label>

        <input type="hidden" name="asset_id" id="adminImagesAssignAssetId" />

        <p id="adminImagesAssignHint" class="rounded-xl border border-dashed border-slate-700 bg-slate-900/60 px-4 py-3 text-sm text-slate-400" aria-live="polite">
            Wählen Sie eine Zielseite, einen Bereich und einen Slot.
        </p>
    </form>
</template>

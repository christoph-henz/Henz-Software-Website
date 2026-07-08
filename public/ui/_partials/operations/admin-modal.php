<?php declare(strict_types=1); ?>
<?php
/**
 * Admin modal container.
 *
 * This is a generic, JS-controlled modal. Content is populated at runtime
 * via window.adminOpenModal(title, bodyHtml, options).
 *
 * Variants:
 *   info  – title + text body + single "Schließen" button (default)
 *   form  – title + arbitrary body HTML + configurable footer buttons
 *
 * Mobile behaviour (< 768 px): renders as a bottom sheet / fullscreen
 * panel over the content area (sidebar is hidden at that viewport).
 *
 * Usage from JavaScript:
 *   adminOpenModal('Hinweis', '<p>Inhalt</p>');
 *
 *   adminOpenModal('Buchung stornieren?', '<p>Wirklich stornieren?</p>', {
 *     type: 'form',
 *     buttons: [
 *       { label: 'Stornieren', variant: 'danger',     onClick: function() { ... } },
 *       { label: 'Abbrechen',  variant: 'secondary',  onClick: adminCloseModal }
 *     ]
 *   });
 */
?>
<div class="admin-modal-backdrop"
     id="adminModalBackdrop"
     role="dialog"
     aria-modal="true"
     aria-labelledby="adminModalTitle"
     aria-hidden="true">

    <div class="admin-modal">

        <div class="admin-modal-header">
            <h2 class="admin-modal-title" id="adminModalTitle"></h2>
            <button type="button"
                    class="admin-modal-close-btn"
                    id="adminModalCloseBtn"
                    aria-label="Dialog schließen">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="admin-modal-body" id="adminModalBody"></div>

        <div class="admin-modal-footer" id="adminModalFooter"></div>

    </div>
</div>

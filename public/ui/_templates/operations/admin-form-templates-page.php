<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'Formularverwaltung - Henz Software');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');
$templatesConfig = is_array($templatesConfig ?? null) ? $templatesConfig : [];
$includeGlobalAdminModal = false;

$extraHead = '<link rel="stylesheet" href="/ui/_assets/css/admin-form-templates.css" />';
$extraScripts = '<script>window.__ADMIN_TEMPLATES_CONFIG = ' . json_encode(
    $templatesConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';</script>';
$extraScripts .= '<script src="/ui/_assets/js/admin-form-templates.js" defer></script>';

ob_start();
?>
<div class="admin-page-header admin-templates-header">
    <div>
        <h1 class="admin-page-title">Sitzungsformulare</h1>
        <p class="admin-page-subtitle">Verwalte Formularvorlagen für die Formularname. Erstelle, bearbeite und veröffentliche neue Versionen.</p>
    </div>
    <button type="button" class="btn btn-primary btn-icon" id="btnCreateTemplate" aria-label="Neues Formular erstellen">
        <span aria-hidden="true">+</span> Neues Formular
    </button>
</div>

<div class="templates-container">
    <!-- Search & Filter Bar -->
    <div class="templates-control-bar">
        <div class="templates-search-group">
            <input type="text" 
                   id="searchInput" 
                   class="templates-search-input" 
                   placeholder="Nach Name oder Beschreibung suchen..." 
                   aria-label="Formulare durchsuchen">
            <button type="button" class="templates-filter-btn" id="btnToggleFilters" aria-label="Filter anzeigen" aria-expanded="false">
                <span aria-hidden="true">⚙</span> Filter
            </button>
        </div>
        <div class="templates-filter-panel" id="filterPanel" hidden>
            <label class="templates-filter-item">
                <input type="checkbox" id="filterActive" class="filter-checkbox" />
                <span>Nur aktive anzeigen</span>
            </label>
            <label class="templates-filter-item">
                <input type="checkbox" id="filterMyTemplates" class="filter-checkbox" />
                <span>Meine Templates</span>
            </label>
            <div class="templates-filter-item">
                <label for="sortSelect">Sortierung:</label>
                <select id="sortSelect" class="templates-sort-select">
                    <option value="name_asc">Name (A-Z)</option>
                    <option value="name_desc">Name (Z-A)</option>
                    <option value="created_desc">Neueste zuerst</option>
                    <option value="created_asc">Älteste zuerst</option>
                </select>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" id="btnResetFilters">Zurücksetzen</button>
        </div>
    </div>

    <!-- Templates List -->
    <div class="templates-list" id="templatesList">
        <div class="templates-loading" aria-live="polite">
            <span class="spinner"></span> Lade Formulare...
        </div>
    </div>

    <!-- Pagination -->
    <div class="templates-pagination" id="paginationContainer" hidden>
        <div class="pagination-info">
            <span id="paginationText">Seite 1 von 1</span>
        </div>
        <div class="pagination-controls">
            <button type="button" class="btn btn-secondary btn-sm" id="btnPrevPage" aria-label="Vorherige Seite">← Zurück</button>
            <button type="button" class="btn btn-secondary btn-sm" id="btnNextPage" aria-label="Nächste Seite">Vorwärts →</button>
        </div>
    </div>
</div>

<!-- Create/Edit Template Modal -->
<div class="admin-modal-backdrop" id="templateModal" hidden aria-labelledby="templateModalTitle" role="dialog" aria-modal="true">
    <div class="admin-modal">
        <div class="admin-modal-header">
            <h2 id="templateModalTitle" class="admin-modal-title">Neues Formular</h2>
            <button type="button" class="admin-modal-close-btn" id="btnCloseTemplateModal" aria-label="Schließen">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <form id="templateForm" class="admin-modal-body">
            <div class="form-group">
                <label for="fieldTemplateKey">Template-Schlüssel *</label>
                <input type="text" 
                       id="fieldTemplateKey" 
                       name="template_key" 
                       class="form-input" 
                       placeholder="z.B. testprotokoll" 
                       required 
                       aria-describedby="helpTemplateKey">
                <small id="helpTemplateKey" class="form-help">Nur Kleinbuchstaben, Zahlen, Bindestrich und Unterstrich. Nach Erstellung nicht änderbar.</small>
                <span class="form-error" id="errorTemplateKey"></span>
            </div>
            <div class="form-group">
                <label for="fieldName">Name *</label>
                <input type="text" 
                       id="fieldName" 
                       name="name" 
                       class="form-input" 
                       placeholder="z.B. Testprotokoll" 
                       required>
                <span class="form-error" id="errorName"></span>
            </div>
            <div class="form-group">
                <label for="fieldDescription">Beschreibung *</label>
                <textarea id="fieldDescription" 
                          name="description" 
                          class="form-input" 
                          rows="3" 
                          placeholder="Kurze Beschreibung des Formulars..." 
                          required></textarea>
                <span class="form-error" id="errorDescription"></span>
            </div>
            <div class="form-group form-checkbox">
                <label>
                    <input type="checkbox" 
                           id="fieldIsActive" 
                           name="is_active" 
                           value="1" 
                           checked>
                    <span>Formular aktivieren</span>
                </label>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelTemplate">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- Detail/Edit Template Modal -->
<div class="admin-modal-backdrop" id="detailModal" hidden aria-labelledby="detailModalTitle" role="dialog" aria-modal="true">
    <div class="admin-modal admin-modal-wide">
        <div class="admin-modal-header">
            <h2 id="detailModalTitle" class="admin-modal-title">Formulardetails</h2>
            <button type="button" class="admin-modal-close-btn" id="btnCloseDetailModal" aria-label="Schließen">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="admin-modal-body">
            <!-- Tabs -->
            <div class="modal-tabs">
                <button type="button" class="modal-tab-btn is-active" data-tab="overview" aria-selected="true">
                    Übersicht
                </button>
                <button type="button" class="modal-tab-btn" data-tab="metadata" aria-selected="false">
                    Einstellungen
                </button>
                <button type="button" class="modal-tab-btn" data-tab="versions" aria-selected="false">
                    Versionen
                </button>
            </div>

            <!-- Tab: Overview -->
            <div class="modal-tab-content is-active" data-tab="overview">
                <div class="detail-info-grid">
                    <div class="detail-info-item">
                        <span class="detail-info-label">Template-Schlüssel:</span>
                        <span class="detail-info-value" id="detailKey">-</span>
                    </div>
                    <div class="detail-info-item">
                        <span class="detail-info-label">Name:</span>
                        <span class="detail-info-value" id="detailName">-</span>
                    </div>
                    <div class="detail-info-item">
                        <span class="detail-info-label">Status:</span>
                        <span class="detail-info-value" id="detailStatus">-</span>
                    </div>
                    <div class="detail-info-item">
                        <span class="detail-info-label">Aktuelle Version:</span>
                        <span class="detail-info-value" id="detailCurrentVersion">-</span>
                    </div>
                    <div class="detail-info-item">
                        <span class="detail-info-label">Erstellt:</span>
                        <span class="detail-info-value" id="detailCreatedAt">-</span>
                    </div>
                    <div class="detail-info-item">
                        <span class="detail-info-label">Aktualisiert:</span>
                        <span class="detail-info-value" id="detailUpdatedAt">-</span>
                    </div>
                </div>
                <div class="detail-description">
                    <h4>Beschreibung</h4>
                    <p id="detailDescription">-</p>
                </div>
            </div>

            <!-- Tab: Metadata -->
            <div class="modal-tab-content" data-tab="metadata">
                <form id="metadataForm" class="form-vertical">
                    <div class="form-group">
                        <label for="editName">Name *</label>
                        <input type="text" 
                               id="editName" 
                               name="name" 
                               class="form-input" 
                               required>
                        <span class="form-error" id="errorEditName"></span>
                    </div>
                    <div class="form-group">
                        <label for="editDescription">Beschreibung *</label>
                        <textarea id="editDescription" 
                                  name="description" 
                                  class="form-input" 
                                  rows="4" 
                                  required></textarea>
                        <span class="form-error" id="errorEditDescription"></span>
                    </div>
                    <div class="form-group form-checkbox">
                        <label>
                            <input type="checkbox" 
                                   id="editIsActive" 
                                   name="is_active" 
                                   value="1">
                            <span>Aktiv</span>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" id="btnCancelMetadata">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                    </div>
                </form>
            </div>

            <!-- Tab: Versions -->
            <div class="modal-tab-content" data-tab="versions">
                <div class="versions-header">
                    <h4>Versionshistorie</h4>
                    <button type="button" class="btn btn-primary btn-sm" id="btnPublishVersion">
                        <span aria-hidden="true">+</span> Neue Version veröffentlichen
                    </button>
                </div>
                <div class="versions-list" id="versionsList">
                    <div class="loading-state">Lade Versionen...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Publish Version Modal -->
<div class="admin-modal-backdrop" id="publishModal" hidden aria-labelledby="publishModalTitle" role="dialog" aria-modal="true">
    <div class="admin-modal admin-modal-wide admin-modal-editor">
        <div class="admin-modal-header">
            <h2 id="publishModalTitle" class="admin-modal-title">Neue Version veröffentlichen</h2>
            <button type="button" class="admin-modal-close-btn" id="btnClosePublishModal" aria-label="Schließen">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <form id="publishForm" class="admin-modal-body">
            <div class="template-editor-shell">
                <div class="template-editor-toolbar">
                    <div class="template-editor-mode-switch" role="tablist" aria-label="Editor-Modus">
                        <button type="button" class="template-editor-mode-btn is-active" id="btnEditorModeVisual" data-editor-mode="visual" aria-selected="true">
                            Visueller Editor
                        </button>
                        <button type="button" class="template-editor-mode-btn" id="btnEditorModeJson" data-editor-mode="json" aria-selected="false">
                            JSON-Code
                        </button>
                    </div>
                    <div class="template-editor-toolbar-actions">
                        <button type="button" class="btn btn-secondary btn-sm" id="btnAddSection">Sektion hinzufügen</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnSyncFromJson">JSON übernehmen</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnFormatJson">JSON formatieren</button>
                    </div>
                </div>

                <div class="template-editor-layout">
                    <aside class="template-editor-sidebar" aria-label="Feldtypen">
                        <h3 class="template-editor-sidebar-title">Elemente</h3>
                        <p class="template-editor-sidebar-copy">Neue Felder werden in der markierten Sektion erzeugt. Der Briefkopf bleibt immer am Seitenanfang.</p>
                        <div class="template-editor-sidebar-actions">
                            <button type="button" class="template-editor-palette-btn" id="btnSelectLetterhead">Briefkopf bearbeiten</button>
                            <button type="button" class="template-editor-palette-btn template-editor-palette-btn-danger" id="btnDeleteActiveSection">Aktive Sektion löschen</button>
                        </div>
                        <div class="template-editor-palette">
                            <button type="button" class="template-editor-palette-btn" data-field-type="text">Textfeld</button>
                            <button type="button" class="template-editor-palette-btn" data-field-type="textarea">Mehrzeiliges Textfeld</button>
                            <button type="button" class="template-editor-palette-btn" data-field-type="number">Zahl</button>
                            <button type="button" class="template-editor-palette-btn" data-field-type="date">Datum</button>
                            <button type="button" class="template-editor-palette-btn" data-field-type="radio">Radio</button>
                            <button type="button" class="template-editor-palette-btn" data-field-type="checkbox_single">Checkbox (einzeln)</button>
                            <button type="button" class="template-editor-palette-btn" data-field-type="checkbox_multiple">Checkbox (mehrfach)</button>
                        </div>
                    </aside>

                    <div class="template-editor-workbench">
                        <div class="template-editor-visual-view" id="templateEditorVisualView">
                            <div class="template-editor-page">
                                <div class="template-editor-letterhead" id="templateEditorLetterheadPreview"></div>
                                <div class="template-editor-sections" id="templateEditorSections"></div>
                            </div>
                        </div>

                        <div class="template-editor-json-view" id="templateEditorJsonView" hidden>
                            <div class="form-group">
                                <label for="schemaJsonInput">Formular-Schema (JSON) *</label>
                                <textarea id="schemaJsonInput"
                                          name="schema_json"
                                          class="form-input form-monospace"
                                          rows="20"
                                          placeholder='[{"type": "section", "label": "Allgemein", "items": [{"field_key": "name", "type": "text", "label": "Name"}]}]'
                                          required></textarea>
                                <small class="form-help">Unterstützt flache Felder und verschachtelte Sektionen. Mindestens ein Feld mit field_key und type ist erforderlich.</small>
                                <span class="form-error" id="errorSchemaJson"></span>
                            </div>
                        </div>
                    </div>

                    <aside class="template-editor-inspector" aria-label="Eigenschaften">
                        <h3 class="template-editor-sidebar-title">Eigenschaften</h3>
                        <div id="templateEditorInspector" class="template-editor-inspector-content"></div>
                    </aside>
                </div>
            </div>

            <div class="form-group form-checkbox">
                <label>
                    <input type="checkbox" id="validateBeforePublish" checked>
                    <span>Schema vor Veröffentlichung validieren</span>
                </label>
            </div>
            <div class="admin-modal-footer">
                <button type="button" class="btn btn-secondary" id="btnCancelPublish">Abbrechen</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnValidateSchema">Validieren</button>
                <button type="submit" class="btn btn-primary">Veröffentlichen</button>
            </div>
        </form>
    </div>
</div>

<!-- Notification/Alert Container -->
<div id="notificationContainer" class="notifications" aria-live="polite" aria-atomic="true"></div>

<?php
$content = ob_get_clean();

require base_path('public/ui/_partials/operations/admin-layout.php');

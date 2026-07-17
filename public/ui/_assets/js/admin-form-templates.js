/**
 * admin-form-templates.js
 * Template management UI logic
 */

class AdminSessionTemplatesUI {
  static AUTO_CONTEXT_LINE_TEMPLATE = 'Klient: {{name}} | Erstellt am: {{created_date}}';

  constructor() {
    this.config = window.__ADMIN_TEMPLATES_CONFIG || {};
    this.currentPage = 1;
    this.perPage = 20;
    this.currentDetailTemplateId = null;
    this.currentVersions = [];
    this.searchQuery = '';
    this.lastFocusedBeforeModal = null;
    this.editorIdCounter = 0;
    this.editorMode = 'visual';
    this.selectedEditorNode = { kind: 'letterhead' };
    this.activeSectionId = null;
    this.editorState = this.createEmptyEditorState();
    this.filters = {
      active_only: false,
      my_templates: false,
      sort: 'name_asc'
    };

    this.elements = this.cacheElements();
    this.bindEvents();
    this.loadTemplates();
    this.initializeFromConfig();
  }

  cacheElements() {
    return {
      searchInput: document.getElementById('searchInput'),
      filterPanel: document.getElementById('filterPanel'),
      btnToggleFilters: document.getElementById('btnToggleFilters'),
      filterActive: document.getElementById('filterActive'),
      filterMyTemplates: document.getElementById('filterMyTemplates'),
      sortSelect: document.getElementById('sortSelect'),
      btnResetFilters: document.getElementById('btnResetFilters'),

      templatesList: document.getElementById('templatesList'),
      paginationContainer: document.getElementById('paginationContainer'),
      paginationText: document.getElementById('paginationText'),
      btnPrevPage: document.getElementById('btnPrevPage'),
      btnNextPage: document.getElementById('btnNextPage'),

      btnCreateTemplate: document.getElementById('btnCreateTemplate'),

      templateModal: document.getElementById('templateModal'),
      templateModalTitle: document.getElementById('templateModalTitle'),
      templateForm: document.getElementById('templateForm'),
      fieldTemplateKey: document.getElementById('fieldTemplateKey'),
      fieldName: document.getElementById('fieldName'),
      fieldDescription: document.getElementById('fieldDescription'),
      fieldIsActive: document.getElementById('fieldIsActive'),
      btnCloseTemplateModal: document.getElementById('btnCloseTemplateModal'),
      btnCancelTemplate: document.getElementById('btnCancelTemplate'),

      detailModal: document.getElementById('detailModal'),
      detailModalTitle: document.getElementById('detailModalTitle'),
      btnCloseDetailModal: document.getElementById('btnCloseDetailModal'),
      detailKey: document.getElementById('detailKey'),
      detailName: document.getElementById('detailName'),
      detailStatus: document.getElementById('detailStatus'),
      detailCurrentVersion: document.getElementById('detailCurrentVersion'),
      detailCreatedAt: document.getElementById('detailCreatedAt'),
      detailUpdatedAt: document.getElementById('detailUpdatedAt'),
      detailDescription: document.getElementById('detailDescription'),

      metadataForm: document.getElementById('metadataForm'),
      editName: document.getElementById('editName'),
      editDescription: document.getElementById('editDescription'),
      editIsActive: document.getElementById('editIsActive'),
      btnCancelMetadata: document.getElementById('btnCancelMetadata'),

      versionsList: document.getElementById('versionsList'),
      btnPublishVersion: document.getElementById('btnPublishVersion'),

      publishModal: document.getElementById('publishModal'),
      publishForm: document.getElementById('publishForm'),
      schemaJsonInput: document.getElementById('schemaJsonInput'),
      validateBeforePublish: document.getElementById('validateBeforePublish'),
      btnClosePublishModal: document.getElementById('btnClosePublishModal'),
      btnCancelPublish: document.getElementById('btnCancelPublish'),
      btnValidateSchema: document.getElementById('btnValidateSchema'),

      btnEditorModeVisual: document.getElementById('btnEditorModeVisual'),
      btnEditorModeJson: document.getElementById('btnEditorModeJson'),
      btnAddSection: document.getElementById('btnAddSection'),
      btnSelectLetterhead: document.getElementById('btnSelectLetterhead'),
      btnDeleteActiveSection: document.getElementById('btnDeleteActiveSection'),
      btnSyncFromJson: document.getElementById('btnSyncFromJson'),
      btnFormatJson: document.getElementById('btnFormatJson'),
      visualView: document.getElementById('templateEditorVisualView'),
      jsonView: document.getElementById('templateEditorJsonView'),
      letterheadPreview: document.getElementById('templateEditorLetterheadPreview'),
      sectionsRoot: document.getElementById('templateEditorSections'),
      inspectorRoot: document.getElementById('templateEditorInspector'),
      paletteButtons: Array.from(document.querySelectorAll('.template-editor-palette-btn')),

      notificationContainer: document.getElementById('notificationContainer')
    };
  }

  bindEvents() {
    this.elements.searchInput?.addEventListener('input', (e) => {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => {
        this.searchQuery = e.target.value;
        this.currentPage = 1;
        this.loadTemplates();
      }, 300);
    });

    this.elements.btnToggleFilters?.addEventListener('click', () => {
      const isHidden = this.elements.filterPanel.hidden;
      this.elements.filterPanel.hidden = !isHidden;
      this.elements.btnToggleFilters.setAttribute('aria-expanded', !isHidden);
    });

    this.elements.filterActive?.addEventListener('change', (e) => {
      this.filters.active_only = e.target.checked;
      this.currentPage = 1;
      this.loadTemplates();
    });

    this.elements.filterMyTemplates?.addEventListener('change', (e) => {
      this.filters.my_templates = e.target.checked;
      this.currentPage = 1;
      this.loadTemplates();
    });

    this.elements.sortSelect?.addEventListener('change', (e) => {
      this.filters.sort = e.target.value;
      this.currentPage = 1;
      this.loadTemplates();
    });

    this.elements.btnResetFilters?.addEventListener('click', () => {
      this.filters = { active_only: false, my_templates: false, sort: 'name_asc' };
      this.searchQuery = '';
      this.currentPage = 1;
      this.elements.filterActive.checked = false;
      this.elements.filterMyTemplates.checked = false;
      this.elements.sortSelect.value = 'name_asc';
      this.elements.searchInput.value = '';
      this.loadTemplates();
    });

    this.elements.btnPrevPage?.addEventListener('click', () => {
      if (this.currentPage > 1) {
        this.currentPage--;
        this.loadTemplates();
      }
    });

    this.elements.btnNextPage?.addEventListener('click', () => {
      this.currentPage++;
      this.loadTemplates();
    });

    this.elements.btnCreateTemplate?.addEventListener('click', () => {
      this.openCreateTemplateModal();
    });

    this.elements.templateForm?.addEventListener('submit', (e) => {
      e.preventDefault();
      this.submitTemplateForm();
    });

    this.elements.btnCloseTemplateModal?.addEventListener('click', () => {
      this.closeModal(this.elements.templateModal);
    });

    this.elements.btnCancelTemplate?.addEventListener('click', () => {
      this.closeModal(this.elements.templateModal);
    });

    this.elements.btnCloseDetailModal?.addEventListener('click', () => {
      this.closeModal(this.elements.detailModal);
    });

    this.elements.metadataForm?.addEventListener('submit', (e) => {
      e.preventDefault();
      this.submitMetadataForm();
    });

    this.elements.btnCancelMetadata?.addEventListener('click', () => {
      this.switchTab('overview');
    });

    this.elements.btnPublishVersion?.addEventListener('click', () => {
      this.openEditorRoute();
    });

    this.elements.publishForm?.addEventListener('submit', (e) => {
      e.preventDefault();
      this.submitPublishForm();
    });

    this.elements.btnValidateSchema?.addEventListener('click', () => {
      this.validateSchemaJson();
    });

    this.elements.btnClosePublishModal?.addEventListener('click', () => {
      this.closeModal(this.elements.publishModal);
    });

    this.elements.btnCancelPublish?.addEventListener('click', () => {
      this.closeModal(this.elements.publishModal);
    });

    [this.elements.templateModal, this.elements.detailModal, this.elements.publishModal].forEach((modal) => {
      modal?.addEventListener('click', (e) => {
        if (e.target === modal) {
          this.closeModal(modal);
        }
      });
    });

    document.addEventListener('click', (e) => {
      if (e.target.classList.contains('modal-tab-btn')) {
        this.switchTab(e.target.getAttribute('data-tab'));
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        this.closeAllModals();
      }
    });

    this.elements.btnEditorModeVisual?.addEventListener('click', () => {
      this.setEditorMode('visual');
    });

    this.elements.btnEditorModeJson?.addEventListener('click', () => {
      this.setEditorMode('json');
    });

    this.elements.btnAddSection?.addEventListener('click', () => {
      this.addSection();
    });

    this.elements.btnSelectLetterhead?.addEventListener('click', () => {
      this.selectLetterhead();
    });

    this.elements.btnDeleteActiveSection?.addEventListener('click', () => {
      this.deleteActiveSection();
    });

    this.elements.btnSyncFromJson?.addEventListener('click', () => {
      this.loadEditorFromJson();
    });

    this.elements.btnFormatJson?.addEventListener('click', () => {
      this.formatSchemaJson();
    });

    this.elements.paletteButtons.forEach((button) => {
      button.addEventListener('click', () => {
        this.addFieldToActiveSection(button.getAttribute('data-field-type'));
      });
    });

    this.elements.sectionsRoot?.addEventListener('click', (e) => {
      const actionEl = e.target.closest('[data-editor-action]');
      if (actionEl) {
        e.preventDefault();
        this.handleEditorAction(actionEl);
        return;
      }

      const fieldCard = e.target.closest('[data-field-id]');
      if (fieldCard) {
        this.selectField(fieldCard.getAttribute('data-section-id'), fieldCard.getAttribute('data-field-id'));
        return;
      }

      const sectionCard = e.target.closest('[data-section-id]');
      if (sectionCard) {
        this.selectSection(sectionCard.getAttribute('data-section-id'));
      }
    });

    this.elements.letterheadPreview?.addEventListener('click', () => {
      this.selectLetterhead();
    });

    this.elements.inspectorRoot?.addEventListener('input', (e) => {
      this.handleInspectorInput(e.target);
    });

    this.elements.inspectorRoot?.addEventListener('change', (e) => {
      this.handleInspectorInput(e.target);
    });
  }

  async loadTemplates() {
    this.elements.templatesList.innerHTML = '<div class="templates-loading"><span class="spinner"></span> Lade Formulare...</div>';

    try {
      const params = new URLSearchParams({
        page: this.currentPage,
        per_page: this.perPage,
        q: this.searchQuery,
        active_only: this.filters.active_only ? '1' : '0',
        ...(this.filters.sort && { sort: this.filters.sort })
      });

      const response = await fetch(`/form-templates/data?${params}`, {
        method: 'GET',
        headers: { Accept: 'application/json' }
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload = this.unwrapApiPayload(await response.json());
      this.renderTemplatesList(payload.data || []);
      this.updatePagination(payload.pagination || {});
    } catch (error) {
      console.error('Error loading templates:', error);
      this.elements.templatesList.innerHTML = '<div class="templates-loading"><strong>Fehler beim Laden der Formulare</strong></div>';
      this.showNotification('Fehler beim Laden der Formulare', 'error');
    }
  }

  async initializeFromConfig() {
    const templateId = Number(this.config.initial_template_id || 0);
    if (templateId <= 0) {
      return;
    }

    const initialTab = ['overview', 'metadata', 'versions'].includes(this.config.initial_tab)
      ? this.config.initial_tab
      : 'overview';
    const initialVersionNo = this.config.initial_editor_version_no ? String(this.config.initial_editor_version_no) : null;

    if (this.config.open_editor_on_load) {
      await this.openEditorForTemplate(templateId, initialVersionNo);
      return;
    }

    await this.openDetailModal(String(templateId), initialTab);
  }

  renderTemplatesList(templates) {
    if (!Array.isArray(templates)) {
      this.elements.templatesList.innerHTML = '<div class="templates-loading"><strong>Ungültige Serverantwort</strong></div>';
      this.showNotification('Unerwartetes Antwortformat beim Laden der Formulare', 'error');
      return;
    }

    if (templates.length === 0) {
      this.elements.templatesList.innerHTML = '<div class="templates-loading">Keine Formulare gefunden.</div>';
      return;
    }

    const html = templates.map((template) => this.renderTemplateCard(template)).join('');
    this.elements.templatesList.innerHTML = html;

    document.querySelectorAll('.btn-edit-template').forEach((btn) => {
      btn.addEventListener('click', () => {
        this.openDetailModal(btn.getAttribute('data-id'));
      });
    });

    document.querySelectorAll('.btn-delete-template').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (confirm('Formular wirklich soft-löschen? Dies kann nicht rückgängig gemacht werden.')) {
          this.deleteTemplate(btn.getAttribute('data-id'));
        }
      });
    });

    document.querySelectorAll('.btn-toggle-active').forEach((btn) => {
      btn.addEventListener('click', () => {
        this.toggleTemplateActive(btn.getAttribute('data-id'), btn.getAttribute('data-active') !== '1');
      });
    });
  }

  renderTemplateCard(template) {
    const isActive = template.is_active ? '1' : '0';
    const statusClass = template.is_active ? 'is-active' : 'is-inactive';
    const statusText = template.is_active ? 'Aktiv' : 'Inaktiv';
    const toggleButtonText = template.is_active ? 'Deaktivieren' : 'Aktivieren';
    const toggleButtonClass = template.is_active ? 'btn-secondary' : 'btn-primary';
    const currentVersion = Number(template.current_version || 0);
    const currentVersionText = currentVersion > 0 ? currentVersion : '-';

    return `
      <div class="template-card">
        <div class="template-card-header">
          <h3 class="template-card-title">${this.escapeHtml(template.name)}</h3>
          <span class="template-card-status ${statusClass}">${statusText}</span>
        </div>
        <p class="template-card-description">${this.escapeHtml(template.description)}</p>
        <div class="template-card-meta">
          <div class="template-card-meta-item">
            <span class="template-card-meta-label">Version:</span>
            <span class="template-card-meta-value">${currentVersionText}</span>
          </div>
          <div class="template-card-meta-item">
            <span class="template-card-meta-label">Erstellt:</span>
            <span>${this.formatDate(template.created_at)}</span>
          </div>
        </div>
        <div class="template-card-actions">
          <button type="button" class="btn btn-secondary btn-sm btn-edit-template" data-id="${template.id}">
            Bearbeiten
          </button>
          <button type="button" class="btn ${toggleButtonClass} btn-sm btn-toggle-active" data-id="${template.id}" data-active="${isActive}">
            ${toggleButtonText}
          </button>
          <button type="button" class="btn btn-danger btn-sm btn-delete-template" data-id="${template.id}">
            Löschen
          </button>
        </div>
      </div>
    `;
  }

  updatePagination(pagination) {
    const total = pagination.total || 0;
    const currentPage = pagination.page || 1;
    const perPage = pagination.per_page || this.perPage;
    const totalPages = Math.ceil(total / perPage);

    if (total <= perPage) {
      this.elements.paginationContainer.hidden = true;
      return;
    }

    this.elements.paginationContainer.hidden = false;
    this.elements.paginationText.textContent = `Seite ${currentPage} von ${totalPages}`;
    this.elements.btnPrevPage.disabled = currentPage <= 1;
    this.elements.btnNextPage.disabled = currentPage >= totalPages;
  }

  openCreateTemplateModal() {
    this.elements.templateModalTitle.textContent = 'Neues Formular';
    this.elements.templateForm.reset();
    this.elements.templateForm.removeAttribute('data-edit-id');
    this.elements.fieldTemplateKey.disabled = false;
    this.clearFormErrors();
    this.openModal(this.elements.templateModal);
  }

  async openDetailModal(templateId, initialTab = 'overview') {
    this.currentDetailTemplateId = templateId;
    try {
      const response = await fetch(`/form-templates/data/${templateId}`, {
        method: 'GET',
        headers: { Accept: 'application/json' }
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload = this.unwrapApiPayload(await response.json());
      const template = payload.template || payload.data || null;

      if (!template || typeof template !== 'object') {
        throw new Error('Ungültige Serverantwort');
      }

      this.elements.detailKey.textContent = template.template_key;
      this.elements.detailName.textContent = template.name;
      this.elements.detailStatus.textContent = template.is_active ? 'Aktiv' : 'Inaktiv';
      this.elements.detailCurrentVersion.textContent = Number(template.current_version || 0) > 0
        ? String(template.current_version)
        : '-';
      this.elements.detailCreatedAt.textContent = this.formatDate(template.created_at);
      this.elements.detailUpdatedAt.textContent = this.formatDate(template.updated_at);
      this.elements.detailDescription.textContent = template.description;

      this.elements.editName.value = template.name;
      this.elements.editDescription.value = template.description;
      this.elements.editIsActive.checked = !!template.is_active;

      await this.loadVersions(templateId);

      this.clearFormErrors();
      this.switchTab(initialTab);
      this.openModal(this.elements.detailModal);
    } catch (error) {
      console.error('Error loading template detail:', error);
      this.showNotification('Fehler beim Laden des Formulars', 'error');
    }
  }

  async loadVersions(templateId) {
    try {
      const response = await fetch(`/form-templates/data/${templateId}/versions`, {
        method: 'GET',
        headers: { Accept: 'application/json' }
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload = this.unwrapApiPayload(await response.json());
      const versions = payload.versions || payload.data || [];

      if (!Array.isArray(versions)) {
        throw new Error('Ungültige Serverantwort');
      }

      this.currentVersions = versions;

      const html = versions.map((version, idx) => {
        const creatorName = String(version.created_by_name || '').trim();
        const creatorText = creatorName !== ''
          ? creatorName
          : (version.created_by_user_id ? `User ${version.created_by_user_id}` : 'Unbekannt');

        return `
        <div class="version-item ${idx === 0 ? 'is-current' : ''}">
          <div class="version-header">
            <span class="version-number">Version ${version.version_no}</span>
            ${idx === 0 ? '<span class="version-badge">In Verwendung</span>' : ''}
          </div>
          <div class="version-meta">
            <span>Veröffentlicht: ${this.formatDate(version.published_at)}</span>
            <span>Erstellt von: ${this.escapeHtml(creatorText)}</span>
          </div>
          <div class="version-actions">
            <a class="btn btn-secondary btn-sm" href="/form-templates/${templateId}/editor/${encodeURIComponent(String(version.version_no))}">
              Im Editor öffnen
            </a>
            <a class="btn btn-secondary btn-sm" href="/form-templates/data/${templateId}/versions/${version.id}/pdf" target="_blank" rel="noopener">
              PDF exportieren
            </a>
          </div>
        </div>
      `;
      }).join('');

      this.elements.versionsList.innerHTML = html || '<div class="loading-state">Keine Versionen vorhanden.</div>';
    } catch (error) {
      console.error('Error loading versions:', error);
      this.elements.versionsList.innerHTML = '<div class="loading-state">Fehler beim Laden der Versionen.</div>';
    }
  }

  async showVersionSchema(versionId) {
    if (!this.currentDetailTemplateId || !versionId) {
      return;
    }

    try {
      const response = await fetch(`/form-templates/data/${this.currentDetailTemplateId}/versions/${versionId}`, {
        method: 'GET',
        headers: { Accept: 'application/json' }
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload = this.unwrapApiPayload(await response.json());
      const version = payload.version || payload.data || null;
      const schema = Array.isArray(version?.schema_json) ? version.schema_json : [];
      this.elements.schemaJsonInput.value = JSON.stringify(schema, null, 2);
      this.loadEditorFromJson();
      this.setEditorMode('json');
      this.openModal(this.elements.publishModal);
    } catch (error) {
      console.error('Error loading schema version:', error);
      this.showNotification('Fehler beim Laden des Schemas', 'error');
    }
  }

  async openPublishModal(preferredVersionId = null) {
    this.clearFormErrors();
    this.resetEditorState();

    const versionIdToLoad = preferredVersionId || this.currentVersions[0]?.id || null;

    if (versionIdToLoad) {
      try {
        await this.loadSchemaForVersion(versionIdToLoad);
      } catch (error) {
        console.warn('Unable to load latest version schema, falling back to default editor state.', error);
      }
    }

    this.syncJsonFromEditor();
    this.setEditorMode('visual');
    this.openModal(this.elements.publishModal);
  }

  openEditorRoute() {
    if (!this.currentDetailTemplateId) {
      return;
    }

    window.location.href = `/form-templates/${this.currentDetailTemplateId}/editor`;
  }

  async openEditorForTemplate(templateId, versionNo = null) {
    this.currentDetailTemplateId = String(templateId);

    try {
      const response = await fetch(`/form-templates/data/${templateId}`, {
        method: 'GET',
        headers: { Accept: 'application/json' }
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload = this.unwrapApiPayload(await response.json());
      const template = payload.template || payload.data || null;
      if (!template || typeof template !== 'object') {
        throw new Error('Ungültige Serverantwort');
      }

      this.currentDetailTemplateId = String(template.id || templateId);
      await this.loadVersions(this.currentDetailTemplateId);

      let versionId = null;
      if (versionNo) {
        const exactVersion = this.currentVersions.find((version) => String(version.version_no) === String(versionNo));
        versionId = exactVersion ? exactVersion.id : null;
      }

      await this.openPublishModal(versionId);
    } catch (error) {
      console.error('Error opening editor route:', error);
      this.showNotification('Fehler beim Laden des Formular-Editors', 'error');
    }
  }

  async loadSchemaForVersion(versionId) {
    const response = await fetch(`/form-templates/data/${this.currentDetailTemplateId}/versions/${versionId}`, {
      method: 'GET',
      headers: { Accept: 'application/json' }
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const payload = this.unwrapApiPayload(await response.json());
    const version = payload.version || payload.data || null;
    const schema = Array.isArray(version?.schema_json) ? version.schema_json : [];
    this.applySchemaToEditor(schema);
  }

  async submitTemplateForm() {
    const isEdit = this.elements.templateForm.getAttribute('data-edit-id');
    const payload = {
      template_key: this.elements.fieldTemplateKey.value,
      name: this.elements.fieldName.value,
      description: this.elements.fieldDescription.value,
      is_active: this.elements.fieldIsActive.checked ? '1' : '0'
    };

    try {
      const method = isEdit ? 'PATCH' : 'POST';
      const url = isEdit
        ? `/form-templates/data/${isEdit}`
        : '/form-templates/data';

      const response = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json'
        },
        body: JSON.stringify(payload)
      });

      if (!response.ok) {
        const error = await response.json();
        this.displayFormErrors(error.errors || {});
        throw new Error(error.message || 'Fehler beim Speichern');
      }

      this.showNotification(isEdit ? 'Formular aktualisiert' : 'Formular erstellt', 'success');
      this.closeModal(this.elements.templateModal);
      this.currentPage = 1;
      this.loadTemplates();
    } catch (error) {
      console.error('Error submitting template:', error);
      this.showNotification(error.message || 'Fehler beim Speichern des Formulars', 'error');
    }
  }

  async submitMetadataForm() {
    if (!this.currentDetailTemplateId) {
      return;
    }

    const payload = {
      name: this.elements.editName.value,
      description: this.elements.editDescription.value,
      is_active: this.elements.editIsActive.checked ? '1' : '0'
    };

    try {
      const response = await fetch(`/form-templates/data/${this.currentDetailTemplateId}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json'
        },
        body: JSON.stringify(payload)
      });

      if (!response.ok) {
        const error = await response.json();
        this.displayFormErrors(error.errors || {}, 'editErrorName');
        throw new Error(error.message || 'Fehler beim Speichern');
      }

      this.showNotification('Formular aktualisiert', 'success');
      this.openDetailModal(this.currentDetailTemplateId);
    } catch (error) {
      console.error('Error updating metadata:', error);
      this.showNotification(error.message || 'Fehler beim Speichern', 'error');
    }
  }

  async submitPublishForm() {
    if (!this.currentDetailTemplateId) {
      return;
    }

    this.syncJsonFromEditor();

    if (this.elements.validateBeforePublish.checked && !this.validateSchemaJson()) {
      return;
    }

    try {
      const schema = JSON.parse(this.elements.schemaJsonInput.value);
      const response = await fetch(`/form-templates/data/${this.currentDetailTemplateId}/versions`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json'
        },
        body: JSON.stringify({ schema_json: schema })
      });

      if (!response.ok) {
        const error = await response.json();
        this.displayFormErrors(error.errors || {});
        throw new Error(error.message || 'Fehler beim Veröffentlichen');
      }

      this.showNotification('Neue Version veröffentlicht', 'success');
      this.closeModal(this.elements.publishModal);
      await this.loadVersions(this.currentDetailTemplateId);
    } catch (error) {
      console.error('Error publishing version:', error);
      this.showNotification(error.message || 'Fehler beim Veröffentlichen', 'error');
    }
  }

  async deleteTemplate(templateId) {
    try {
      const response = await fetch(`/form-templates/data/${templateId}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json' }
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      this.showNotification('Formular gelöscht', 'success');
      this.loadTemplates();
    } catch (error) {
      console.error('Error deleting template:', error);
      this.showNotification('Fehler beim Löschen des Formulars', 'error');
    }
  }

  async toggleTemplateActive(templateId, makeActive) {
    try {
      const response = await fetch(`/form-templates/data/${templateId}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json'
        },
        body: JSON.stringify({ is_active: makeActive ? '1' : '0' })
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      this.showNotification(makeActive ? 'Formular aktiviert' : 'Formular deaktiviert', 'success');
      this.loadTemplates();
    } catch (error) {
      console.error('Error toggling active status:', error);
      this.showNotification('Fehler beim Aktualisieren des Status', 'error');
    }
  }

  validateSchemaJson() {
    const input = this.elements.schemaJsonInput.value.trim();

    if (!input) {
      this.setFieldError('errorSchemaJson', 'Schema-JSON ist erforderlich');
      return false;
    }

    try {
      const schema = JSON.parse(input);

      if (!Array.isArray(schema)) {
        throw new Error('Schema muss ein Array sein');
      }

      if (schema.length === 0) {
        throw new Error('Schema muss mindestens ein Element enthalten');
      }

      if (!this.schemaContainsValidField(schema)) {
        throw new Error('Schema muss mindestens ein Feld mit field_key und type enthalten');
      }

      this.clearFieldError('errorSchemaJson');
      this.showNotification('Schema ist gültig', 'success');
      return true;
    } catch (error) {
      this.setFieldError('errorSchemaJson', `JSON-Fehler: ${error.message}`);
      return false;
    }
  }

  schemaContainsValidField(items) {
    if (!Array.isArray(items)) {
      return false;
    }

    for (const item of items) {
      if (!item || typeof item !== 'object') {
        continue;
      }

      const fieldKey = String(item.field_key || '').trim();
      const type = String(item.type || '').trim();
      if (fieldKey !== '' && type !== '' && type !== 'section' && type !== 'letterhead') {
        return true;
      }

      if (Array.isArray(item.items) && this.schemaContainsValidField(item.items)) {
        return true;
      }
    }

    return false;
  }

  createEmptyEditorState() {
    const firstSection = this.createSection('Sektion 1');

    return {
      letterhead: {
        practice_name: 'Henz Software',
        form_title: 'Formularname',
        subtitle: 'Der Briefkopf steht immer am Anfang und bleibt als feste Kopfzone erhalten.',
        context_line: AdminSessionTemplatesUI.AUTO_CONTEXT_LINE_TEMPLATE
      },
      sections: [firstSection]
    };
  }

  resetEditorState() {
    this.editorState = this.createEmptyEditorState();
    this.activeSectionId = this.editorState.sections[0]?.id || null;
    this.selectedEditorNode = { kind: 'letterhead' };
    this.renderEditor();
  }

  createSection(label) {
    return {
      id: this.generateEditorId('section'),
      type: 'section',
      label: label || 'Sektion',
      description: '',
      items: []
    };
  }

  createField(type) {
    const defaults = {
      text: { label: 'Textfeld', placeholder: 'Antwort eingeben' },
      textarea: { label: 'Mehrzeilige Antwort', placeholder: 'Antwort eingeben' },
      number: { label: 'Zahlenfeld', placeholder: '0' },
      date: { label: 'Datumsfeld', placeholder: '' },
      radio: { label: 'Radio-Auswahl', options: ['Option 1', 'Option 2'] },
      checkbox_single: { label: 'Checkbox', options: ['Ja, bestätigen'] },
      checkbox_multiple: { label: 'Mehrfachauswahl', options: ['Option 1', 'Option 2', 'Option 3'] }
    };
    const fallback = defaults[type] || defaults.text;

    return {
      id: this.generateEditorId('field'),
      field_key: `${type}_${this.editorIdCounter}`.replace(/[^a-z0-9_]+/gi, '_').toLowerCase(),
      label: fallback.label,
      type,
      required: false,
      width: 'full',
      help_text: '',
      placeholder: fallback.placeholder || '',
      options: Array.isArray(fallback.options) ? [...fallback.options] : []
    };
  }

  generateEditorId(prefix) {
    this.editorIdCounter += 1;
    return `${prefix}-${this.editorIdCounter}`;
  }

  setEditorMode(mode) {
    this.editorMode = mode === 'json' ? 'json' : 'visual';
    const isVisual = this.editorMode === 'visual';

    this.elements.visualView.hidden = !isVisual;
    this.elements.jsonView.hidden = isVisual;
    this.elements.btnEditorModeVisual.classList.toggle('is-active', isVisual);
    this.elements.btnEditorModeJson.classList.toggle('is-active', !isVisual);
    this.elements.btnEditorModeVisual.setAttribute('aria-selected', isVisual ? 'true' : 'false');
    this.elements.btnEditorModeJson.setAttribute('aria-selected', !isVisual ? 'true' : 'false');

    if (!isVisual) {
      this.syncJsonFromEditor();
    }
  }

  renderEditor(options = {}) {
    this.renderLetterheadPreview();
    this.renderSections();
    this.renderInspector();
    this.updateEditorActionState();
    this.syncJsonFromEditor();

    if (options.focusSnapshot) {
      this.restoreInspectorFocus(options.focusSnapshot);
    }
  }

  createInspectorFocusSnapshot(target) {
    if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)) {
      return null;
    }

    return {
      scope: target.getAttribute('data-inspector-scope') || '',
      prop: target.getAttribute('data-inspector-prop') || '',
      value: target.value,
      selectionStart: 'selectionStart' in target ? target.selectionStart : null,
      selectionEnd: 'selectionEnd' in target ? target.selectionEnd : null
    };
  }

  restoreInspectorFocus(snapshot) {
    if (!snapshot || !snapshot.scope || !snapshot.prop) {
      return;
    }

    const selector = `[data-inspector-scope="${snapshot.scope}"][data-inspector-prop="${snapshot.prop}"]`;
    const input = this.elements.inspectorRoot?.querySelector(selector);
    if (!(input instanceof HTMLElement)) {
      return;
    }

    input.focus();

    if ((input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) && snapshot.selectionStart !== null && snapshot.selectionEnd !== null) {
      input.setSelectionRange(snapshot.selectionStart, snapshot.selectionEnd);
    }
  }

  renderLetterheadPreview() {
    const letterhead = this.editorState.letterhead;
    this.elements.letterheadPreview.classList.toggle('is-selected', this.selectedEditorNode.kind === 'letterhead');
    this.elements.letterheadPreview.setAttribute('role', 'button');
    this.elements.letterheadPreview.setAttribute('tabindex', '0');
    this.elements.letterheadPreview.setAttribute('aria-label', 'Briefkopf bearbeiten');
    this.elements.letterheadPreview.innerHTML = `
      <div class="template-letterhead-topline">Briefkopf</div>
      <div class="template-letterhead-practice">${this.escapeHtml(letterhead.practice_name)}</div>
      <div class="template-letterhead-title">${this.escapeHtml(letterhead.form_title)}</div>
      <div class="template-letterhead-subtitle">${this.escapeHtml(letterhead.subtitle)}</div>
      <div class="template-letterhead-context">${this.escapeHtml(letterhead.context_line)}</div>
    `;
  }

  renderSections() {
    const sectionsHtml = this.editorState.sections.map((section, sectionIndex) => {
      const isSectionSelected = this.selectedEditorNode.kind === 'section' && this.selectedEditorNode.sectionId === section.id;
      const itemsHtml = section.items.length > 0
        ? section.items.map((field, fieldIndex) => this.renderFieldCard(section, field, sectionIndex, fieldIndex)).join('')
        : '<div class="template-editor-empty">Diese Sektion ist noch leer. Links einen Feldtyp hinzufügen.</div>';

      return `
        <section class="template-section-card ${this.activeSectionId === section.id ? 'is-active' : ''} ${isSectionSelected ? 'is-selected' : ''}" data-section-id="${section.id}">
          <div class="template-section-header">
            <div>
              <div class="template-section-kicker">Sektion ${sectionIndex + 1}</div>
              <h4 class="template-section-title">${this.escapeHtml(section.label || 'Unbenannte Sektion')}</h4>
              <p class="template-section-description">${this.escapeHtml(section.description || 'Felder werden standardmäßig untereinander erzeugt und können danach in ihrer Position angepasst werden.')}</p>
            </div>
            <div class="template-section-actions">
              <button type="button" class="template-editor-action-btn" data-editor-action="select-section" data-section-id="${section.id}">Bearbeiten</button>
              <button type="button" class="template-editor-action-btn" data-editor-action="move-section-up" data-section-id="${section.id}" ${sectionIndex === 0 ? 'disabled' : ''}>↑</button>
              <button type="button" class="template-editor-action-btn" data-editor-action="move-section-down" data-section-id="${section.id}" ${sectionIndex === this.editorState.sections.length - 1 ? 'disabled' : ''}>↓</button>
              <button type="button" class="template-editor-action-btn is-danger" data-editor-action="delete-section" data-section-id="${section.id}" ${this.editorState.sections.length === 1 ? 'disabled' : ''}>Löschen</button>
            </div>
          </div>
          <div class="template-section-items">
            ${itemsHtml}
          </div>
        </section>
      `;
    }).join('');

    this.elements.sectionsRoot.innerHTML = sectionsHtml;
  }

  renderFieldCard(section, field, sectionIndex, fieldIndex) {
    const isSelected = this.selectedEditorNode.kind === 'field'
      && this.selectedEditorNode.sectionId === section.id
      && this.selectedEditorNode.fieldId === field.id;
    const meta = [field.required ? 'Pflichtfeld' : 'Optional', field.width === 'half' ? 'Halbe Breite' : 'Volle Breite'];
    if (this.fieldTypeSupportsOptions(field.type)) {
      meta.push(`${field.options.length} Optionen`);
    }

    return `
      <article class="template-field-card ${field.width === 'half' ? 'is-half' : ''} ${isSelected ? 'is-selected' : ''}" data-section-id="${section.id}" data-field-id="${field.id}">
        <div class="template-field-toolbar">
          <span class="template-field-tag">${this.escapeHtml(this.fieldTypeLabel(field.type))}</span>
          <div class="template-field-actions">
            <button type="button" class="template-editor-action-btn" data-editor-action="select-field" data-section-id="${section.id}" data-field-id="${field.id}">Bearbeiten</button>
            <button type="button" class="template-editor-action-btn" data-editor-action="move-field-up" data-section-id="${section.id}" data-field-id="${field.id}" ${fieldIndex === 0 ? 'disabled' : ''}>↑</button>
            <button type="button" class="template-editor-action-btn" data-editor-action="move-field-down" data-section-id="${section.id}" data-field-id="${field.id}" ${fieldIndex === section.items.length - 1 ? 'disabled' : ''}>↓</button>
            <button type="button" class="template-editor-action-btn" data-editor-action="duplicate-field" data-section-id="${section.id}" data-field-id="${field.id}">Duplizieren</button>
            <button type="button" class="template-editor-action-btn is-danger" data-editor-action="delete-field" data-section-id="${section.id}" data-field-id="${field.id}">Löschen</button>
          </div>
        </div>
        <div class="template-field-preview">
          ${this.renderFieldPreview(field)}
        </div>
        <div class="template-field-meta">${meta.map((item) => `<span>${this.escapeHtml(item)}</span>`).join('')}</div>
      </article>
    `;
  }

  renderFieldPreview(field) {
    const label = this.escapeHtml(field.label || 'Unbenanntes Feld');
    const requiredMark = field.required ? ' <span class="template-required-mark">*</span>' : '';
    const helpText = field.help_text ? `<div class="template-field-help">${this.escapeHtml(field.help_text)}</div>` : '';

    if (field.type === 'textarea') {
      return `
        <label class="template-preview-label">${label}${requiredMark}</label>
        <div class="template-preview-control template-preview-control--textarea">${this.escapeHtml(field.placeholder || 'Mehrzeilige Eingabe')}</div>
        ${helpText}
      `;
    }

    if (field.type === 'radio' || field.type === 'checkbox_multiple' || field.type === 'checkbox_single') {
      const inputType = field.type === 'radio' ? 'radio' : 'checkbox';
      const options = (field.options || []).map((option) => `
        <label class="template-choice-row">
          <span class="template-choice-indicator template-choice-indicator--${inputType}"></span>
          <span>${this.escapeHtml(option)}</span>
        </label>
      `).join('');

      return `
        <label class="template-preview-label">${label}${requiredMark}</label>
        <div class="template-choice-group">${options}</div>
        ${helpText}
      `;
    }

    return `
      <label class="template-preview-label">${label}${requiredMark}</label>
      <div class="template-preview-control">${this.escapeHtml(field.placeholder || this.fieldTypeLabel(field.type))}</div>
      ${helpText}
    `;
  }

  renderInspector() {
    if (this.selectedEditorNode.kind === 'letterhead') {
      const letterhead = this.editorState.letterhead;
      this.elements.inspectorRoot.innerHTML = `
        <div class="template-inspector-section">
          <div class="template-inspector-kicker">Briefkopf</div>
          ${this.renderInspectorInput('practice_name', 'Praxisname', letterhead.practice_name)}
          ${this.renderInspectorInput('form_title', 'Formulartitel', letterhead.form_title)}
          ${this.renderInspectorTextarea('subtitle', 'Unterzeile', letterhead.subtitle, 3)}
          ${this.renderInspectorReadonly('context_line', 'Kontextzeile (automatisch)', letterhead.context_line)}
        </div>
      `;
      return;
    }

    if (this.selectedEditorNode.kind === 'section') {
      const section = this.findSection(this.selectedEditorNode.sectionId);
      if (!section) {
        return;
      }

      this.elements.inspectorRoot.innerHTML = `
        <div class="template-inspector-section">
          <div class="template-inspector-kicker">Sektion</div>
          ${this.renderInspectorInput('label', 'Titel', section.label, 'section')}
          ${this.renderInspectorTextarea('description', 'Beschreibung', section.description, 4, 'section')}
        </div>
      `;
      return;
    }

    if (this.selectedEditorNode.kind === 'field') {
      const field = this.findField(this.selectedEditorNode.sectionId, this.selectedEditorNode.fieldId);
      if (!field) {
        return;
      }

      const fieldTypeOptions = ['text', 'textarea', 'number', 'date', 'radio', 'checkbox_single', 'checkbox_multiple']
        .map((type) => `<option value="${type}" ${field.type === type ? 'selected' : ''}>${this.escapeHtml(this.fieldTypeLabel(type))}</option>`)
        .join('');

      this.elements.inspectorRoot.innerHTML = `
        <div class="template-inspector-section">
          <div class="template-inspector-kicker">Feld</div>
          ${this.renderInspectorInput('field_key', 'Feldschlüssel', field.field_key, 'field')}
          ${this.renderInspectorInput('label', 'Label', field.label, 'field')}
          <label class="template-inspector-label">
            <span>Typ</span>
            <select class="form-input" data-inspector-scope="field" data-inspector-prop="type">
              ${fieldTypeOptions}
            </select>
          </label>
          <label class="template-inspector-label">
            <span>Layoutbreite</span>
            <select class="form-input" data-inspector-scope="field" data-inspector-prop="width">
              <option value="full" ${field.width === 'full' ? 'selected' : ''}>Volle Breite</option>
              <option value="half" ${field.width === 'half' ? 'selected' : ''}>Halbe Breite</option>
            </select>
          </label>
          ${this.renderInspectorInput('placeholder', 'Platzhalter', field.placeholder || '', 'field')}
          ${this.renderInspectorTextarea('help_text', 'Hilfetext', field.help_text || '', 3, 'field')}
          <label class="template-inspector-checkbox">
            <input type="checkbox" data-inspector-scope="field" data-inspector-prop="required" ${field.required ? 'checked' : ''}>
            <span>Pflichtfeld</span>
          </label>
          ${this.fieldTypeSupportsOptions(field.type)
            ? this.renderInspectorTextarea('options', 'Optionen (eine pro Zeile)', (field.options || []).join('\n'), 6, 'field')
            : '<div class="template-inspector-hint">Für diesen Feldtyp sind keine Auswahloptionen erforderlich.</div>'}
        </div>
      `;
      return;
    }

    this.elements.inspectorRoot.innerHTML = '<div class="template-inspector-hint">Wähle links ein Element aus.</div>';
  }

  renderInspectorInput(prop, label, value, scope = 'letterhead') {
    return `
      <label class="template-inspector-label">
        <span>${this.escapeHtml(label)}</span>
        <input type="text" class="form-input" value="${this.escapeHtml(value || '')}" data-inspector-scope="${scope}" data-inspector-prop="${prop}">
      </label>
    `;
  }

  renderInspectorTextarea(prop, label, value, rows, scope = 'letterhead') {
    return `
      <label class="template-inspector-label">
        <span>${this.escapeHtml(label)}</span>
        <textarea class="form-input" rows="${rows}" data-inspector-scope="${scope}" data-inspector-prop="${prop}">${this.escapeHtml(value || '')}</textarea>
      </label>
    `;
  }

  renderInspectorReadonly(prop, label, value, scope = 'letterhead') {
    return `
      <label class="template-inspector-label">
        <span>${this.escapeHtml(label)}</span>
        <input type="text" class="form-input" value="${this.escapeHtml(value || '')}" data-inspector-scope="${scope}" data-inspector-prop="${prop}" readonly aria-readonly="true">
      </label>
      <div class="template-inspector-hint">Diese Zeile wird beim Ausfuellen automatisch mit Vorname, Nachname und Erstellungsdatum gesetzt.</div>
    `;
  }

  handleInspectorInput(target) {
    if (!(target instanceof HTMLElement)) {
      return;
    }

    const focusSnapshot = this.createInspectorFocusSnapshot(target);

    const scope = target.getAttribute('data-inspector-scope');
    const prop = target.getAttribute('data-inspector-prop');
    if (!scope || !prop) {
      return;
    }

    const value = target instanceof HTMLInputElement && target.type === 'checkbox'
      ? target.checked
      : target.value;

    if (scope === 'letterhead') {
      if (prop === 'context_line') {
        return;
      }
      this.editorState.letterhead[prop] = value;
      this.renderEditor({ focusSnapshot });
      return;
    }

    if (scope === 'section' && this.selectedEditorNode.sectionId) {
      const section = this.findSection(this.selectedEditorNode.sectionId);
      if (section) {
        section[prop] = value;
        this.renderEditor({ focusSnapshot });
      }
      return;
    }

    if (scope === 'field' && this.selectedEditorNode.sectionId && this.selectedEditorNode.fieldId) {
      const field = this.findField(this.selectedEditorNode.sectionId, this.selectedEditorNode.fieldId);
      if (!field) {
        return;
      }

      if (prop === 'options') {
        field.options = String(value || '')
          .split('\n')
          .map((item) => item.trim())
          .filter(Boolean);
      } else {
        field[prop] = value;
      }

      if (prop === 'type' && !this.fieldTypeSupportsOptions(field.type)) {
        field.options = [];
      }

      if (prop === 'type' && this.fieldTypeSupportsOptions(field.type) && field.options.length === 0) {
        field.options = field.type === 'checkbox_single' ? ['Ja, bestätigen'] : ['Option 1', 'Option 2'];
      }

      this.renderEditor({ focusSnapshot });
    }
  }

  addSection() {
    const section = this.createSection(`Sektion ${this.editorState.sections.length + 1}`);
    this.editorState.sections.push(section);
    this.activeSectionId = section.id;
    this.selectedEditorNode = { kind: 'section', sectionId: section.id };
    this.renderEditor();
  }

  selectLetterhead() {
    this.selectedEditorNode = { kind: 'letterhead' };
    this.renderEditor();
  }

  deleteActiveSection() {
    if (!this.activeSectionId) {
      this.showNotification('Keine aktive Sektion ausgewählt.', 'warning');
      return;
    }

    this.deleteSection(this.activeSectionId);
  }

  addFieldToActiveSection(type) {
    if (!this.activeSectionId) {
      this.activeSectionId = this.editorState.sections[0]?.id || null;
    }

    const section = this.findSection(this.activeSectionId);
    if (!section) {
      this.showNotification('Lege zuerst eine Sektion an.', 'warning');
      return;
    }

    const field = this.createField(type);
    section.items.push(field);
    this.selectedEditorNode = { kind: 'field', sectionId: section.id, fieldId: field.id };
    this.renderEditor();
  }

  handleEditorAction(actionEl) {
    const action = actionEl.getAttribute('data-editor-action');
    const sectionId = actionEl.getAttribute('data-section-id');
    const fieldId = actionEl.getAttribute('data-field-id');

    if (action === 'select-section' && sectionId) {
      this.selectSection(sectionId);
      return;
    }

    if (action === 'select-field' && sectionId && fieldId) {
      this.selectField(sectionId, fieldId);
      return;
    }

    if (action === 'move-section-up' && sectionId) {
      this.moveSection(sectionId, -1);
      return;
    }

    if (action === 'move-section-down' && sectionId) {
      this.moveSection(sectionId, 1);
      return;
    }

    if (action === 'delete-section' && sectionId) {
      this.deleteSection(sectionId);
      return;
    }

    if (!sectionId || !fieldId) {
      return;
    }

    if (action === 'move-field-up') {
      this.moveField(sectionId, fieldId, -1);
      return;
    }

    if (action === 'move-field-down') {
      this.moveField(sectionId, fieldId, 1);
      return;
    }

    if (action === 'duplicate-field') {
      this.duplicateField(sectionId, fieldId);
      return;
    }

    if (action === 'delete-field') {
      this.deleteField(sectionId, fieldId);
    }
  }

  selectSection(sectionId) {
    this.activeSectionId = sectionId;
    this.selectedEditorNode = { kind: 'section', sectionId };
    this.renderEditor();
  }

  selectField(sectionId, fieldId) {
    this.activeSectionId = sectionId;
    this.selectedEditorNode = { kind: 'field', sectionId, fieldId };
    this.renderEditor();
  }

  moveSection(sectionId, direction) {
    const index = this.editorState.sections.findIndex((section) => section.id === sectionId);
    const targetIndex = index + direction;
    if (index < 0 || targetIndex < 0 || targetIndex >= this.editorState.sections.length) {
      return;
    }

    const [section] = this.editorState.sections.splice(index, 1);
    this.editorState.sections.splice(targetIndex, 0, section);
    this.renderEditor();
  }

  deleteSection(sectionId) {
    if (this.editorState.sections.length === 1) {
      this.showNotification('Mindestens eine Sektion muss bestehen bleiben.', 'warning');
      return;
    }

    this.editorState.sections = this.editorState.sections.filter((section) => section.id !== sectionId);
    this.activeSectionId = this.editorState.sections[0]?.id || null;
    this.selectedEditorNode = this.activeSectionId
      ? { kind: 'section', sectionId: this.activeSectionId }
      : { kind: 'letterhead' };
    this.renderEditor();
  }

  updateEditorActionState() {
    if (this.elements.btnDeleteActiveSection) {
      this.elements.btnDeleteActiveSection.disabled = this.editorState.sections.length <= 1 || !this.activeSectionId;
    }
  }

  moveField(sectionId, fieldId, direction) {
    const section = this.findSection(sectionId);
    if (!section) {
      return;
    }

    const index = section.items.findIndex((field) => field.id === fieldId);
    const targetIndex = index + direction;
    if (index < 0 || targetIndex < 0 || targetIndex >= section.items.length) {
      return;
    }

    const [field] = section.items.splice(index, 1);
    section.items.splice(targetIndex, 0, field);
    this.renderEditor();
  }

  duplicateField(sectionId, fieldId) {
    const section = this.findSection(sectionId);
    const field = this.findField(sectionId, fieldId);
    if (!section || !field) {
      return;
    }

    const clone = {
      ...field,
      id: this.generateEditorId('field'),
      field_key: `${field.field_key}_copy`,
      options: Array.isArray(field.options) ? [...field.options] : []
    };

    const index = section.items.findIndex((item) => item.id === fieldId);
    section.items.splice(index + 1, 0, clone);
    this.selectedEditorNode = { kind: 'field', sectionId, fieldId: clone.id };
    this.renderEditor();
  }

  deleteField(sectionId, fieldId) {
    const section = this.findSection(sectionId);
    if (!section) {
      return;
    }

    section.items = section.items.filter((field) => field.id !== fieldId);
    this.selectedEditorNode = { kind: 'section', sectionId };
    this.renderEditor();
  }

  findSection(sectionId) {
    return this.editorState.sections.find((section) => section.id === sectionId) || null;
  }

  findField(sectionId, fieldId) {
    const section = this.findSection(sectionId);
    if (!section) {
      return null;
    }

    return section.items.find((field) => field.id === fieldId) || null;
  }

  fieldTypeLabel(type) {
    const labels = {
      text: 'Textfeld',
      textarea: 'Mehrzeiliges Textfeld',
      number: 'Zahlenfeld',
      date: 'Datum',
      radio: 'Radio',
      checkbox_single: 'Checkbox (einzeln)',
      checkbox_multiple: 'Checkbox (mehrfach)'
    };

    return labels[type] || type;
  }

  fieldTypeSupportsOptions(type) {
    return ['radio', 'checkbox_single', 'checkbox_multiple'].includes(type);
  }

  syncJsonFromEditor() {
    const schema = this.buildSchemaFromEditor();
    this.elements.schemaJsonInput.value = JSON.stringify(schema, null, 2);
  }

  buildSchemaFromEditor() {
    const letterhead = {
      type: 'letterhead',
      field_key: 'letterhead',
      label: 'Briefkopf',
      practice_name: this.editorState.letterhead.practice_name,
      form_title: this.editorState.letterhead.form_title,
      subtitle: this.editorState.letterhead.subtitle,
      context_line: AdminSessionTemplatesUI.AUTO_CONTEXT_LINE_TEMPLATE
    };

    const sections = this.editorState.sections.map((section) => ({
      type: 'section',
      field_key: section.id,
      label: section.label,
      description: section.description,
      layout: 'vertical',
      items: section.items.map((field, index) => ({
        field_key: field.field_key,
        type: field.type,
        label: field.label,
        required: !!field.required,
        width: field.width,
        help_text: field.help_text,
        placeholder: field.placeholder,
        options: this.fieldTypeSupportsOptions(field.type) ? [...(field.options || [])] : [],
        position: index + 1
      }))
    }));

    return [letterhead, ...sections];
  }

  loadEditorFromJson() {
    try {
      const schema = JSON.parse(this.elements.schemaJsonInput.value || '[]');
      if (!Array.isArray(schema)) {
        throw new Error('Schema muss ein Array sein');
      }

      this.applySchemaToEditor(schema);
      this.setEditorMode('visual');
      this.showNotification('JSON in visuellen Editor übernommen', 'success');
    } catch (error) {
      this.setFieldError('errorSchemaJson', `JSON-Fehler: ${error.message}`);
      this.showNotification('JSON konnte nicht übernommen werden', 'error');
    }
  }

  applySchemaToEditor(schema) {
    const normalized = this.normalizeSchema(schema);
    this.editorState = normalized;
    this.activeSectionId = normalized.sections[0]?.id || null;
    this.selectedEditorNode = { kind: 'letterhead' };
    this.renderEditor();
  }

  normalizeSchema(schema) {
    const state = this.createEmptyEditorState();
    state.sections = [];

    const defaultSection = this.createSection('Sektion 1');
    let hasDefaultFields = false;

    schema.forEach((item) => {
      if (!item || typeof item !== 'object') {
        return;
      }

      if (item.type === 'letterhead') {
        state.letterhead.practice_name = String(item.practice_name || state.letterhead.practice_name);
        state.letterhead.form_title = String(item.form_title || item.label || state.letterhead.form_title);
        state.letterhead.subtitle = String(item.subtitle || state.letterhead.subtitle);
        state.letterhead.context_line = AdminSessionTemplatesUI.AUTO_CONTEXT_LINE_TEMPLATE;
        return;
      }

      if (item.type === 'section') {
        const section = this.createSection(String(item.label || 'Sektion'));
        section.description = String(item.description || '');
        section.items = Array.isArray(item.items)
          ? item.items
              .filter((child) => child && typeof child === 'object' && String(child.field_key || '').trim() !== '' && String(child.type || '').trim() !== '')
              .map((child) => this.normalizeField(child))
          : [];
        state.sections.push(section);
        return;
      }

      if (String(item.field_key || '').trim() !== '' && String(item.type || '').trim() !== '') {
        defaultSection.items.push(this.normalizeField(item));
        hasDefaultFields = true;
      }
    });

    if (hasDefaultFields) {
      state.sections.unshift(defaultSection);
    }

    if (state.sections.length === 0) {
      state.sections.push(this.createSection('Sektion 1'));
    }

    return state;
  }

  normalizeField(field) {
    return {
      id: this.generateEditorId('field'),
      field_key: String(field.field_key || '').trim() || this.generateEditorId('field_key'),
      label: String(field.label || 'Neues Feld'),
      type: String(field.type || 'text'),
      required: !!field.required,
      width: field.width === 'half' ? 'half' : 'full',
      help_text: String(field.help_text || ''),
      placeholder: String(field.placeholder || ''),
      options: Array.isArray(field.options)
        ? field.options.map((option) => String(option)).filter(Boolean)
        : []
    };
  }

  formatSchemaJson() {
    try {
      const parsed = JSON.parse(this.elements.schemaJsonInput.value || '[]');
      this.elements.schemaJsonInput.value = JSON.stringify(parsed, null, 2);
      this.clearFieldError('errorSchemaJson');
    } catch (error) {
      this.setFieldError('errorSchemaJson', `JSON-Fehler: ${error.message}`);
    }
  }

  switchTab(tabName, syncUrl = true) {
    document.querySelectorAll('.modal-tab-btn').forEach((btn) => {
      btn.classList.remove('is-active');
      btn.setAttribute('aria-selected', 'false');
    });

    document.querySelectorAll('.modal-tab-content').forEach((content) => {
      content.classList.remove('is-active');
    });

    const btn = document.querySelector(`[data-tab="${tabName}"]`);
    const content = document.querySelector(`.modal-tab-content[data-tab="${tabName}"]`);

    if (btn) {
      btn.classList.add('is-active');
      btn.setAttribute('aria-selected', 'true');
    }

    if (content) {
      content.classList.add('is-active');
    }

    if (syncUrl && this.currentDetailTemplateId) {
      const targetPath = this.getTemplateTabPath(this.currentDetailTemplateId, tabName);
      if (window.location.pathname !== targetPath) {
        window.history.replaceState({}, '', targetPath);
      }
    }
  }

  getTemplateTabPath(templateId, tabName) {
    if (!templateId) {
      return '/form-templates';
    }

    if (tabName === 'metadata') {
      return `/form-templates/${templateId}/settings`;
    }

    if (tabName === 'versions') {
      return `/form-templates/${templateId}/versions`;
    }

    return `/form-templates/${templateId}`;
  }

  openModal(modal) {
    this.lastFocusedBeforeModal = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;

    [this.elements.templateModal, this.elements.detailModal, this.elements.publishModal].forEach((otherModal) => {
      if (otherModal && otherModal !== modal) {
        otherModal.classList.remove('is-open');
        otherModal.hidden = true;
        otherModal.setAttribute('aria-hidden', 'true');
      }
    });

    modal.hidden = false;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    window.requestAnimationFrame(() => {
      const focusTarget = modal.querySelector('button, input, textarea, select, [href], [tabindex]:not([tabindex="-1"])');
      if (focusTarget instanceof HTMLElement) {
        focusTarget.focus();
      }
    });
  }

  closeModal(modal) {
    if (modal.contains(document.activeElement) && document.activeElement instanceof HTMLElement) {
      document.activeElement.blur();
    }

    modal.classList.remove('is-open');
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');

    const hasOpenModal = [this.elements.templateModal, this.elements.detailModal, this.elements.publishModal]
      .some((activeModal) => activeModal && activeModal.classList.contains('is-open'));

    document.body.style.overflow = hasOpenModal ? 'hidden' : '';

    if (!hasOpenModal && this.lastFocusedBeforeModal instanceof HTMLElement) {
      window.requestAnimationFrame(() => {
        this.lastFocusedBeforeModal.focus();
        this.lastFocusedBeforeModal = null;
      });
    }
  }

  closeAllModals() {
    [this.elements.templateModal, this.elements.detailModal, this.elements.publishModal].forEach((modal) => {
      if (modal) {
        this.closeModal(modal);
      }
    });
  }

  displayFormErrors(errors, prefix = '') {
    const errorIdMap = {
      template_key: prefix === 'edit' ? 'errorEditTemplateKey' : 'errorTemplateKey',
      name: prefix === 'edit' ? 'errorEditName' : 'errorName',
      description: prefix === 'edit' ? 'errorEditDescription' : 'errorDescription',
      schema_json: 'errorSchemaJson',
      payload: 'errorSchemaJson'
    };

    Object.entries(errors).forEach(([field, messages]) => {
      const errorId = errorIdMap[field] || `error${field.charAt(0).toUpperCase() + field.slice(1)}`;
      this.setFieldError(errorId, Array.isArray(messages) ? messages[0] : messages);
    });
  }

  setFieldError(errorId, message) {
    const errorEl = document.getElementById(errorId);
    if (errorEl) {
      errorEl.textContent = message;
    }
  }

  clearFieldError(errorId) {
    const errorEl = document.getElementById(errorId);
    if (errorEl) {
      errorEl.textContent = '';
    }
  }

  clearFormErrors() {
    document.querySelectorAll('.form-error').forEach((el) => {
      el.textContent = '';
    });
  }

  unwrapApiPayload(responseBody) {
    if (!responseBody || typeof responseBody !== 'object') {
      return {};
    }

    const payload = responseBody.data;
    if (payload && typeof payload === 'object' && !Array.isArray(payload)) {
      return payload;
    }

    return responseBody;
  }

  showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.setAttribute('role', 'status');
    notification.textContent = message;

    this.elements.notificationContainer.appendChild(notification);

    setTimeout(() => {
      notification.remove();
    }, 5000);
  }

  formatDate(dateString) {
    if (!dateString) {
      return '-';
    }

    const date = new Date(dateString);
    return date.toLocaleDateString('de-DE', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    });
  }

  escapeHtml(text) {
    text = String(text || '');
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, (match) => map[match]);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  window.adminTemplatesUI = new AdminSessionTemplatesUI();
});
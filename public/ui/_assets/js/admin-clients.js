(function () {
    'use strict';

    var cfg = window.__ADMIN_CLIENTS_CONFIG || {};
    var root = document.getElementById('adminClientsRoot');
    if (!root) return;

    var canView = !!cfg.can_view_clients;
    var canManage = !!cfg.can_manage_clients;
    var canUseFormTemplates = !!cfg.can_use_form_templates_for_clients;
    var canViewProjects = !!cfg.can_view_projects;
    var viewMode = cfg.view_mode === 'record' ? 'record' : 'list';

    function allowedDetailTabs() {
        var tabs = ['info', 'forms'];
        if (canUseFormTemplates) {
            tabs.push('new-form');
            tabs.push('export');
        }

        return tabs;
    }

    var state = {
        sort: cfg.default_sort || 'last_name',
        direction: cfg.default_direction || 'asc',
        page: parsePositiveInt(cfg.default_page, 1),
        perPage: resolveDefaultPerPage(),
        search: '',
        total: 0,
        totalPages: 1,
        clients: [],
        loadingList: false,
        selectedClientId: parsePositiveInt(cfg.initial_client_id, 0) || null,
        selectedClient: null,
        loadingDetail: false,
        loadingRecords: false,
        formRecords: [],
        bookings: [],
        invoiceBookings: [],
        projects: [],
        loadingProjects: false,
        projectDetails: {},
        templates: [],
        templatesLoaded: false,
        selectedTemplateId: null,
        detailTab: 'info',
        infoSubtab: cfg.initial_packages_open ? 'packages' : (cfg.initial_invoices_open ? 'invoices' : 'overview'),
        loadingHistory: false,
        historyItems: [],
        loadingContracts: false,
        contracts: [],
        loadingPackages: false,
        packages: [],
        loadingInvoices: false,
        emailValidation: { value: '', status: 'idle', message: '' },
        formDraft: {},
        formErrors: {},
        formTouched: {},
    };

    hydrateFromUrl();

    if (viewMode === 'list') {
        fetchList();
    } else {
        if (!state.selectedClientId) {
            window.location.href = '/clients';
            return;
        }
        loadRecordView();
    }

    window.addEventListener('popstate', function () {
        hydrateFromUrl();
        if (viewMode === 'list') {
            fetchList();
        } else {
            if (!state.selectedClientId) {
                window.location.href = '/clients';
                return;
            }
            loadRecordView();
        }
    });

    function hydrateFromUrl() {
        var qs = new URLSearchParams(window.location.search || '');
        state.search = trim(qs.get('q'));

        var sort = trim(qs.get('sort'));
        if (sort !== '') state.sort = sort;

        var direction = trim(qs.get('direction'));
        if (direction === 'asc' || direction === 'desc') state.direction = direction;

        var page = trim(qs.get('page'));
        if (/^\d+$/.test(page)) state.page = Math.max(1, parseInt(page, 10));

        var tab = trim(qs.get('tab'));
        if (tab !== '' && inArray(tab, allowedDetailTabs())) {
            state.detailTab = tab;
        }

        if (!canUseFormTemplates && inArray(state.detailTab, ['new-form', 'export'])) {
            state.detailTab = 'info';
        }

        var infoSection = trim(qs.get('info_section'));
        if (infoSection !== '' && inArray(infoSection, ['overview', 'projects', 'invoices', 'packages', 'contracts', 'history'])) {
            state.infoSubtab = infoSection;
        }

        var path = window.location.pathname || '/clients';
        var detailMatch = path.match(/^\/clients\/(\d+)$/);
        state.selectedClientId = detailMatch ? parseInt(detailMatch[1], 10) : null;
    }

    function listQueryString() {
        var qs = new URLSearchParams();
        qs.set('sort', state.sort);
        qs.set('direction', state.direction);
        qs.set('page', String(state.page));
        qs.set('per_page', String(state.perPage));
        if (state.search !== '') qs.set('q', state.search);
        return qs.toString();
    }

    function writeRecordUrl(push) {
        if (!state.selectedClientId) return;
        var qs = new URLSearchParams();
        if (state.detailTab !== 'info') qs.set('tab', state.detailTab);
        if (state.detailTab === 'info' && state.infoSubtab !== 'overview') qs.set('info_section', state.infoSubtab);
        var nextUrl = '/clients/' + state.selectedClientId + (qs.toString() ? ('?' + qs.toString()) : '');
        if (push) window.history.pushState(null, '', nextUrl);
        else window.history.replaceState(null, '', nextUrl);
    }

    function resolveDefaultPerPage() {
        var isMobile = window.matchMedia('(max-width: 1023px)').matches;
        var mobileDefault = parsePositiveInt(cfg.default_per_page_mobile, 10);
        var desktopDefault = parsePositiveInt(cfg.default_per_page_desktop, 20);
        return isMobile ? mobileDefault : desktopDefault;
    }

    function render() {
        if (!canView) {
            root.innerHTML = '<div class="admin-clients-empty">Keine Berechtigung fuer die Clientansicht.</div>';
            return;
        }

        if (viewMode === 'list') root.innerHTML = renderListView();
        else root.innerHTML = renderRecordView();

        bindEvents();
    }

    function renderListView() {
        var rows = '';
        if (state.loadingList) {
            rows = '<tr><td colspan="4" class="admin-clients-empty">Lade Clients...</td></tr>';
        } else if (state.clients.length === 0) {
            rows = '<tr><td colspan="4" class="admin-clients-empty">Keine Clients gefunden.</td></tr>';
        } else {
            rows = state.clients.map(function (item) {
                var address = escapeHtml(item.address || '-');
                var phone = escapeHtml(item.phone || '-');
                return '' +
                    '<tr class="admin-clients-row admin-clients-row--primary" data-row-id="' + item.id + '">' +
                    '  <td>' + escapeHtml(item.name || '') + '</td>' +
                    '  <td>' + escapeHtml(item.email || '') + '</td>' +
                    '  <td>' + escapeHtml(item.phone || '') + '</td>' +
                    '  <td class="admin-clients-desktop-only">' + escapeHtml(item.address || '') + '</td>' +
                    '</tr>' +
                    '<tr class="admin-clients-row admin-clients-row--mobile-meta" data-row-id="' + item.id + '">' +
                    '  <td>' + address + '</td>' +
                    '  <td class="admin-clients-desktop-only"></td>' +
                    '  <td class="admin-clients-desktop-only"></td>' +
                    '</tr>';
            }).join('');
        }

        return '' +
            '<div class="admin-clients-toolbar">' +
            '  <form class="admin-clients-search" data-search-form>' +
            '    <input class="admin-clients-search-input" name="q" type="search" value="' + escapeHtml(state.search) + '" placeholder="Client nach Name oder E-Mail suchen" />' +
            '    <button type="submit" class="admin-clients-page-btn">Suchen</button>' +
            '  </form>' +
            '  <div class="admin-clients-toolbar-right">' +
            (canManage ? '    <button type="button" class="admin-clients-page-btn admin-clients-create-btn" data-create-client>Client anlegen</button>' : '') +
            '    <div class="admin-clients-meta">Gesamt: ' + state.total + '</div>' +
            '  </div>' +
            '</div>' +
            '<section class="admin-clients-pane admin-clients-pane--list-only">' +
            '  <div class="admin-clients-pane-head">Client-Liste</div>' +
            '  <div class="admin-clients-table-wrap">' +
            '    <table class="admin-clients-table">' +
            '      <thead><tr>' +
            '        <th><button type="button" class="admin-clients-sort" data-sort="name">Name ' + sortIndicator('name') + '</button></th>' +
            '        <th><button type="button" class="admin-clients-sort" data-sort="email">E-Mail ' + sortIndicator('email') + '</button></th>' +
            '        <th><button type="button" class="admin-clients-sort" data-sort="phone">Telefon ' + sortIndicator('phone') + '</button></th>' +
            '        <th><button type="button" class="admin-clients-sort" data-sort="address">Adresse ' + sortIndicator('address') + '</button></th>' +
            '      </tr></thead>' +
            '      <tbody>' + rows + '</tbody>' +
            '    </table>' +
            '  </div>' +
            '  <div class="admin-clients-pagination">' +
            '    <div class="admin-clients-pagination-info">Seite ' + state.page + ' / ' + state.totalPages + '</div>' +
            '    <div class="admin-clients-pagination-controls">' +
            '      <button type="button" class="admin-clients-page-btn" data-page-prev ' + (state.page <= 1 ? 'disabled' : '') + '>Zurueck</button>' +
            '      <button type="button" class="admin-clients-page-btn" data-page-next ' + (state.page >= state.totalPages ? 'disabled' : '') + '>Weiter</button>' +
            '    </div>' +
            '  </div>' +
            '</section>';
    }

    function renderRecordView() {
        if (state.loadingDetail) {
            return '<section class="admin-clients-pane admin-clients-pane--detail-only"><div class="admin-clients-empty">Lade Klientenakte...</div></section>';
        }

        if (!state.selectedClient) {
            return '<section class="admin-clients-pane admin-clients-pane--detail-only"><div class="admin-clients-empty">Klientenakte konnte nicht geladen werden.</div></section>';
        }

        var c = state.selectedClient;
        return '' +
            '<section class="admin-clients-pane admin-clients-pane--detail-only">' +
            '  <div class="admin-clients-pane-head admin-clients-pane-head--record">' +
            '    <a class="admin-clients-back" href="/clients">Zur Listenansicht</a>' +
            '    <span>Klientenakte: ' + escapeHtml(c.name || '') + ' <span class="admin-clients-id">#' + escapeHtml(String(c.id || '')) + '</span></span>' +
            '  </div>' +
            '  <div class="admin-clients-tabs" role="tablist">' +
            tabButton('info', 'Infos') +
            tabButton('forms', 'Formulare') +
            (canUseFormTemplates ? tabButton('new-form', 'Formular ausfuellen') : '') +
            (canUseFormTemplates ? tabButton('export', 'Export') : '') +
            '  </div>' +
            '  <div class="admin-clients-detail-content">' + renderActiveTab(c) + '</div>' +
            '</section>';
    }

    function renderActiveTab(client) {
        if (!canUseFormTemplates && inArray(state.detailTab, ['new-form', 'export'])) {
            state.detailTab = 'info';
        }

        if (state.detailTab === 'forms') return renderFormsTab();
        if (state.detailTab === 'new-form') return renderNewFormTab();
        if (state.detailTab === 'export') return renderExportTab();
        return renderInfoTab(client);
    }

    function renderInfoTab(c) {
        return '' +
            '<section class="admin-clients-card">' +
            '  <h3 class="admin-clients-card-title">Klienteninformationen</h3>' +
            '  <div class="admin-clients-detail-grid">' +
            detailField('Name', '<input id="clientName" class="admin-clients-input" type="text" value="' + escapeHtml(c.name) + '" ' + (canManage ? '' : 'disabled') + ' />') +
            detailField('E-Mail', '<input id="clientEmail" class="admin-clients-input" type="email" value="' + escapeHtml(c.email || '') + '" ' + (canManage ? '' : 'disabled') + ' />') +
            detailField('Telefon', '<input id="clientPhone" class="admin-clients-input" type="text" value="' + escapeHtml(c.phone || '') + '" ' + (canManage ? '' : 'disabled') + ' />') +
            detailField('Adresse', '<textarea id="clientAddress" class="admin-clients-textarea" ' + (canManage ? '' : 'disabled') + '>' + escapeHtml(c.address || '') + '</textarea>') +
            '  </div>' +
            (canManage ? '  <div class="admin-clients-actions"><button type="button" class="admin-clients-page-btn" data-save-client>Speichern</button></div>' : '') +
            '</section>' +
            '<section class="admin-clients-card">' +
            '  <div class="admin-clients-subtabs" role="tablist" aria-label="Zusaetzliche Klienteninformationen">' +
            infoTabButton('overview', 'Übersicht') +
            infoTabButton('projects', 'Projekte') +
            infoTabButton('history', 'Buchungshistorie') +
            infoTabButton('invoices', 'Rechnungen') +
            infoTabButton('contracts', 'Verträge') +
            '  </div>' +
            '  <div class="admin-clients-subtab-content">' + renderInfoSubtab(c) + '</div>' +
            '</section>';
    }

    function renderInfoSubtab(client) {
        if (state.infoSubtab === 'projects') return renderProjectsSection();
        if (state.infoSubtab === 'history') return renderHistorySection();
        if (state.infoSubtab === 'invoices') return renderInvoicesSection();
        if (state.infoSubtab === 'contracts') return renderContractsSection();
        return renderInfoOverviewSection(client);
    }

    function renderInfoOverviewSection(client) {
        return '' +
            '<div class="admin-clients-overview-grid">' +
            overviewMetric('Projekte', String(state.projects.length), 'Alle Projekte des Clients inkl. Unterstruktur.') +
            overviewMetric('Buchungshistorie', String(state.historyItems.length), 'Alle zusammengefuehrten Ereignisse zur Klientenakte.') +
            overviewMetric('Rechnungen', String(countInvoices()), 'Buchungen mit Rechnungsbezug inklusive PDF-Status.') +
            overviewMetric('Testprotokolle', String(countTestProtocols()), 'Abgeschlossene oder laufende Testprotokolle aus Projektphasen.') +
            '</div>' +
            '<div class="admin-clients-detail-grid admin-clients-detail-grid--compact">' +
            detailField('Zeitzone', escapeHtml(client.timezone || '-')) +
            detailField('Angelegt', escapeHtml(formatDateTime(client.created_at))) +
            detailField('Zuletzt aktualisiert', escapeHtml(formatDateTime(client.updated_at))) +
            '</div>';
    }

    function renderProjectsSection() {
        if (state.loadingProjects) {
            return '<div class="admin-clients-empty">Lade Projektstruktur...</div>';
        }

        if (!Array.isArray(state.projects) || state.projects.length === 0) {
            return '<div class="admin-clients-empty">Keine Projekte vorhanden.</div>';
        }

        return '<div class="admin-clients-project-shell">' + state.projects.map(function (project) {
            var projectId = parsePositiveInt(project && project.id, 0);
            var details = projectId > 0 ? state.projectDetails[projectId] : null;
            var phases = details && Array.isArray(details.phases) ? details.phases : [];
            var members = details && Array.isArray(details.members) ? details.members : [];
            var contracts = details && Array.isArray(details.contracts) ? details.contracts : [];
            var testProtocols = details && Array.isArray(details.testProtocols) ? details.testProtocols : [];
            var files = details && Array.isArray(details.files) ? details.files : [];
            var notes = details && Array.isArray(details.notes) ? details.notes : [];
            var invoices = Array.isArray(project && project.invoices) ? project.invoices : [];
            var loadingHint = details && details.loading ? '<div class="admin-clients-project-empty">Projektdetails werden geladen...</div>' : '';

            return '' +
                '<article class="admin-clients-project-card">' +
                '  <header class="admin-clients-project-head">' +
                '    <h4 class="admin-clients-project-name">' + escapeHtml(String(project && project.name ? project.name : 'Projekt')) + '</h4>' +
                '    <div class="admin-clients-inline-actions">' +
                '      <span class="admin-clients-project-status">Status: ' + escapeHtml(String(project && project.status ? project.status : '-')) + '</span>' +
                (canViewProjects && projectId > 0
                    ? '      <a class="admin-clients-page-btn" href="/projects/' + projectId + '">Projekt ansehen</a>'
                    : '') +
                '    </div>' +
                '  </header>' +
                '  <div class="admin-clients-project-meta">' +
                renderProjectBadge('Contracts', contracts.length) +
                renderProjectBadge('Invoices', invoices.length) +
                renderProjectBadge('Phases', phases.length) +
                renderProjectBadge('TestProtocols', testProtocols.length) +
                renderProjectBadge('Members', members.length) +
                renderProjectBadge('Files', files.length) +
                renderProjectBadge('Notes', notes.length) +
                '  </div>' +
                '  <div class="admin-clients-project-details">' +
                renderProjectNode('Contracts', contracts.map(function (item) {
                    return 'Typ: ' + String(item.type || '-') + ' · Referenz: ' + String(item.reference || '-');
                })) +
                renderProjectNode('Invoices', invoices.map(function (item) {
                    var invoiceNo = item && item.invoice_number ? String(item.invoice_number) : String(item && item.id ? item.id : '-');
                    return '#' + invoiceNo + ' · ' + String(item && item.status ? item.status : '-') + ' · ' + formatCurrency(item && item.total_amount, item && item.currency_code ? item.currency_code : 'EUR');
                })) +
                renderProjectNode('Phases', phases.map(function (item) {
                    return String(item && item.phase_name ? item.phase_name : '-') + ' · ' + String(item && item.status ? item.status : '-') + ' · ' + String(item && item.progress ? item.progress : 0) + '%';
                })) +
                renderProjectNode('TestProtocols', testProtocols.map(function (item) {
                    return String(item.template_name || item.template_key || 'Protokoll') + ' · ' + String(item.status || '-') + ' · Phase: ' + String(item.phase_name || '-');
                })) +
                renderProjectNode('Members', members.map(function (item) {
                    var user = item && item.user ? item.user : {};
                    var fullName = trim(String(user.first_name || '') + ' ' + String(user.last_name || ''));
                    return (fullName !== '' ? fullName : String(user.email || '-')) + ' · Rolle: ' + String(item && item.role ? item.role : '-');
                })) +
                renderProjectNode('Files', files.map(function (item) {
                    var base = String(item && item.original_filename ? item.original_filename : 'Anhang');
                    if (item && item.download_url) {
                        return '<a class="admin-clients-link" href="' + escapeHtml(String(item.download_url)) + '">' + escapeHtml(base) + '</a>';
                    }
                    return escapeHtml(base);
                }), true) +
                renderProjectNode('Notes', notes.map(function (item) {
                    return String(item || '');
                })) +
                loadingHint +
                '  </div>' +
                '</article>';
        }).join('') + '</div>';
    }

    function renderProjectBadge(key, value) {
        return '' +
            '<div class="admin-clients-project-badge">' +
            '  <span class="admin-clients-project-badge-key">' + escapeHtml(String(key || '')) + '</span>' +
            '  <span class="admin-clients-project-badge-value">' + escapeHtml(String(value || 0)) + '</span>' +
            '</div>';
    }

    function renderProjectNode(label, values, alreadyEscaped) {
        var items = Array.isArray(values) ? values : [];
        var body = '';
        if (items.length === 0) {
            body = '<div class="admin-clients-project-empty">Keine Eintraege vorhanden.</div>';
        } else {
            body = '<ul class="admin-clients-project-list">' + items.map(function (value) {
                var content = alreadyEscaped ? String(value || '') : escapeHtml(String(value || '-'));
                return '<li class="admin-clients-project-item">' + content + '</li>';
            }).join('') + '</ul>';
        }

        return '' +
            '<details class="admin-clients-project-node">' +
            '  <summary>' + escapeHtml(String(label || '')) + '</summary>' +
            body +
            '</details>';
    }

    function renderHistorySection() {
        if (state.loadingHistory) {
            return '<div class="admin-clients-empty">Lade Buchungshistorie...</div>';
        }

        if (state.historyItems.length === 0) {
            return '<div class="admin-clients-empty">Keine Historieneinträge vorhanden.</div>';
        }

        return '<div class="admin-clients-stack">' + state.historyItems.map(function (item) {
            var link = item.booking_url
                ? '<a class="admin-clients-link" href="' + escapeHtml(String(item.booking_url)) + '">Buchung öffnen</a>'
                : '';
            return '' +
                '<article class="admin-clients-entry-card">' +
                '  <div class="admin-clients-entry-head">' +
                '    <strong>' + escapeHtml(String(item.title || 'Ereignis')) + '</strong>' +
                '    <span class="admin-clients-entry-time">' + escapeHtml(formatDateTime(item.happened_at)) + '</span>' +
                '  </div>' +
                '  <p class="admin-clients-entry-copy">' + escapeHtml(String(item.description || '')) + '</p>' +
                (link ? '  <div class="admin-clients-entry-actions">' + link + '</div>' : '') +
                '</article>';
        }).join('') + '</div>';
    }

    function renderInvoicesSection() {
        if (state.loadingInvoices) {
            return '<div class="admin-clients-empty">Lade Rechnungen...</div>';
        }

        var rows = state.invoiceBookings;
        var createButton = canManage
            ? '<div class="admin-clients-actions" style="margin-bottom:0.75rem;"><button type="button" class="admin-clients-page-btn" data-create-invoice>Rechnung erstellen</button></div>'
            : '';

        if (rows.length === 0) {
            return createButton + '<div class="admin-clients-empty">Keine Rechnungen vorhanden.</div>';
        }

        return createButton +
            '<div class="admin-clients-table-wrap">' +
            '  <table class="admin-clients-table">' +
            '    <thead><tr><th>Rechnung</th><th>Termin</th><th>Status</th><th>Betrag</th><th>Fällig</th><th>PDF</th></tr></thead>' +
            '    <tbody>' + rows.map(function (item) {
                    var invoice = item.invoice || {};
                    var pdfViewUrl = invoice.pdf_url || '';
                    var pdfDownloadUrl = invoice.pdf_download_url || invoice.pdf_url || '';
                    var pdfHtml = pdfViewUrl
                        ? '<button type="button" class="admin-clients-page-btn" data-open-invoice-pdf="1" data-pdf-url="' + escapeHtml(String(pdfViewUrl)) + '" data-pdf-download-url="' + escapeHtml(String(pdfDownloadUrl)) + '" data-invoice-label="' + escapeHtml(String(invoice.invoice_number || invoice.id || '-')) + '">PDF ansehen</button>'
                    : '<span class="admin-clients-muted">Nicht verfuegbar</span>';
                return '' +
                    '<tr>' +
                    '  <td>#' + escapeHtml(String(invoice.invoice_number || invoice.id || '-')) + '<div class="admin-clients-table-meta">' + escapeHtml(String(item.project_name || '-')) + '</div></td>' +
                    '  <td>' + escapeHtml(formatDateTime(item.booking_scheduled_at)) + '</td>' +
                    '  <td>' + escapeHtml(String(invoice.status || '-')) + '</td>' +
                    '  <td>' + escapeHtml(formatCurrency(invoice.total_amount, invoice.currency_code || 'EUR')) + '</td>' +
                    '  <td>' + escapeHtml(formatDate(invoice.due_date)) + '</td>' +
                    '  <td>' + pdfHtml + '</td>' +
                    '</tr>';
            }).join('') + '</tbody>' +
            '  </table>' +
            '</div>';
    }

    function renderContractsSection() {
        if (state.loadingContracts) {
            return '<div class="admin-clients-empty">Lade Verträge...</div>';
        }

        if (state.contracts.length === 0) {
            return '<div class="admin-clients-empty">Keine Verträge vorhanden.</div>';
        }

        return '<div class="admin-clients-stack">' + state.contracts.map(function (item) {
            return '' +
                '<article class="admin-clients-entry-card">' +
                '  <div class="admin-clients-entry-head">' +
                '    <strong>' + escapeHtml(String(item.contract_key || 'Einwilligung')) + '</strong>' +
                '    <span class="admin-clients-entry-time">' + escapeHtml(formatDateTime(item.accepted_at)) + '</span>' +
                '  </div>' +
                '  <div class="admin-clients-meta-grid">' +
                metaPill('Status', item.accepted ? 'akzeptiert' : 'abgelehnt') +
                metaPill('Version', String(item.contract_version || '-')) +
                metaPill('Kontext', String(item.context_type || '-') + ' #' + String(item.context_id || '-')) +
                metaPill('Signatur', abbreviateHash(item.signature_hash)) +
                '  </div>' +
                (item.contract_text_snapshot ? '<p class="admin-clients-entry-copy">' + escapeHtml(String(item.contract_text_snapshot)) + '</p>' : '') +
                '</article>';
        }).join('') + '</div>';
    }

    function renderFormsTab() {
        var rows = state.formRecords.length === 0
            ? '<tr><td colspan="6" class="admin-clients-empty">Keine Formulareintraege vorhanden.</td></tr>'
            : state.formRecords.map(function (r) {
                var detailAction = canUseFormTemplates
                    ? '<button type="button" class="admin-clients-page-btn" data-open-record="' + escapeHtml(String(r.id || '')) + '">Ansehen</button>'
                    : '';
                return '' +
                    '<tr>' +
                    '  <td>' + escapeHtml(String(r.id || '-')) + '</td>' +
                    '  <td>' + escapeHtml(r.template && r.template.name ? r.template.name : ('Template #' + String(r.template_id || '-'))) + '</td>' +
                    '  <td>' + escapeHtml(String(r.template && r.template.version_no ? r.template.version_no : '-')) + '</td>' +
                    '  <td>' + escapeHtml(String(r.status || '-')) + '</td>' +
                    '  <td>' + escapeHtml(formatDateTime(r.updated_at || r.created_at)) + '</td>' +
                    '  <td>' +
                    '    <div class="admin-clients-inline-actions">' +
                    '      ' + detailAction +
                    '      <button type="button" class="admin-clients-page-btn" data-open-attachments="' + escapeHtml(String(r.id || '')) + '">Anhaenge</button>' +
                    '    </div>' +
                    '  </td>' +
                    '</tr>';
            }).join('');

        return '' +
            '<section class="admin-clients-card">' +
            '  <h3 class="admin-clients-card-title">Formularhistorie (Draft + Final)</h3>' +
            (state.loadingRecords ? '<div class="admin-clients-empty">Lade Formulare...</div>' : '') +
            '  <div class="admin-clients-table-wrap">' +
            '    <table class="admin-clients-table admin-clients-table--forms">' +
            '      <thead><tr><th>ID</th><th>Template</th><th>Version</th><th>Status</th><th>Aktualisiert</th><th>Aktion</th></tr></thead>' +
            '      <tbody>' + rows + '</tbody>' +
            '    </table>' +
            '  </div>' +
            '</section>';
    }

    function renderNewFormTab() {
        var bookingOptions = state.bookings.map(function (b) {
            return '<option value="' + escapeHtml(String(b.booking_id || '')) + '">#' + escapeHtml(String(b.booking_id || '')) + ' - ' + escapeHtml(formatDateTime(b.booking_scheduled_at)) + '</option>';
        }).join('');
        if (bookingOptions === '') bookingOptions = '<option value="">Keine Buchung verfuegbar</option>';

        var templateOptions = state.templates.map(function (t) {
            return '<option value="' + t.id + '" ' + (state.selectedTemplateId === t.id ? 'selected' : '') + '>' +
                escapeHtml(t.name) + ' (v' + escapeHtml(String(t.currentVersionNo)) + ')' +
                '</option>';
        }).join('');
        if (templateOptions === '') templateOptions = '<option value="">Keine aktiven Templates verfuegbar</option>';

        var selectedTemplate = findTemplateById(state.selectedTemplateId);
        var selectedVersionId = selectedTemplate ? selectedTemplate.currentVersionId : 0;
        var formTemplateHtml = renderTemplateForm(selectedTemplate);

        return '' +
            '<section class="admin-clients-card">' +
            '  <h3 class="admin-clients-card-title">Neues Formular ausfuellen</h3>' +
            '  <p class="admin-clients-help">Felder werden direkt aus der neuesten Template-Version gerendert und clientseitig validiert.</p>' +
            '  <div class="admin-clients-form-grid">' +
            '    <div><label class="admin-clients-label" for="recordBookingId">Buchung</label><select id="recordBookingId" class="admin-clients-input">' + bookingOptions + '</select></div>' +
            '    <div><label class="admin-clients-label" for="recordTemplateId">Template</label><select id="recordTemplateId" class="admin-clients-input">' + templateOptions + '</select></div>' +
            '    <div><label class="admin-clients-label">Aktuelle Version</label><input id="recordTemplateVersionLabel" class="admin-clients-input" type="text" value="' + (selectedTemplate ? ('v' + selectedTemplate.currentVersionNo + ' (ID ' + selectedVersionId + ')') : '-') + '" disabled /></div>' +
            '  </div>' +
            '  <input id="recordTemplateVersionId" type="hidden" value="' + escapeHtml(String(selectedVersionId || 0)) + '" />' +
            formTemplateHtml +
            '  <div class="admin-clients-actions"><button type="button" class="admin-clients-page-btn" data-create-record>Formulardaten speichern</button></div>' +
            '</section>';
    }

    function renderTemplateForm(template) {
        if (!template) {
            return '<div class="admin-clients-empty">Kein Template ausgewaehlt.</div>';
        }

        var schema = Array.isArray(template.schemaJson) ? template.schemaJson : [];
        if (schema.length === 0) {
            return '<div class="admin-clients-empty">Das ausgewaehlte Template enthaelt keine Felder.</div>';
        }

        var letterhead = null;
        var bodyParts = [];
        for (var i = 0; i < schema.length; i += 1) {
            var item = schema[i];
            if (!item || typeof item !== 'object') continue;
            var type = String(item.type || '').toLowerCase();
            if (type === 'letterhead') {
                letterhead = item;
                continue;
            }
            if (type === 'section') {
                bodyParts.push(renderTemplateSection(item));
                continue;
            }
            if (String(item.field_key || '').trim() !== '' && type !== '') {
                bodyParts.push('<div class="admin-clients-template-fields">' + renderTemplateField(item) + '</div>');
            }
        }

        if (bodyParts.length === 0) {
            return '<div class="admin-clients-empty">Das ausgewaehlte Template enthaelt keine ausfuellbaren Felder.</div>';
        }

        var header = '';
        if (letterhead) {
            var contextLine = resolveTemplateContextLine(state.selectedClient, null);
            header = '' +
                '<header class="admin-clients-template-letterhead">' +
                (letterhead.practice_name ? '<div class="admin-clients-template-practice">' + escapeHtml(String(letterhead.practice_name)) + '</div>' : '') +
                (letterhead.form_title ? '<h4 class="admin-clients-template-title">' + escapeHtml(String(letterhead.form_title)) + '</h4>' : '') +
                (letterhead.subtitle ? '<p class="admin-clients-template-subtitle">' + escapeHtml(String(letterhead.subtitle)) + '</p>' : '') +
                (contextLine ? '<p class="admin-clients-template-context">' + escapeHtml(contextLine) + '</p>' : '') +
                '</header>';
        }

        return '' +
            '<div class="admin-clients-template-shell" data-template-form>' +
            header +
            bodyParts.join('') +
            '</div>';
    }

    function renderTemplateSection(section) {
        var items = Array.isArray(section.items) ? section.items : [];
        var fieldsHtml = items.map(renderTemplateField).join('');
        if (fieldsHtml === '') return '';

        return '' +
            '<section class="admin-clients-template-section">' +
            (section.label ? '<h4 class="admin-clients-template-section-title">' + escapeHtml(String(section.label)) + '</h4>' : '') +
            (section.description ? '<p class="admin-clients-template-section-desc">' + escapeHtml(String(section.description)) + '</p>' : '') +
            '<div class="admin-clients-template-fields">' + fieldsHtml + '</div>' +
            '</section>';
    }

    function renderTemplateField(field) {
        var fieldKey = String(field.field_key || '').trim();
        var fieldType = String(field.type || 'text').toLowerCase();
        if (fieldKey === '') return '';

        var required = !!field.required;
        var label = escapeHtml(String(field.label || fieldKey));
        var help = trim(field.help_text || '');
        var widthClass = field.width === 'half' ? ' admin-clients-template-field--half' : '';
        var value = state.formDraft[fieldKey];
        var touched = !!state.formTouched[fieldKey];
        var error = touched && state.formErrors[fieldKey] ? String(state.formErrors[fieldKey]) : '';
        var invalidClass = error ? ' is-invalid' : '';

        var control = '';
        if (fieldType === 'textarea') {
            control = '<textarea class="admin-clients-textarea' + invalidClass + '" id="field_' + escapeHtml(fieldKey) + '" data-form-field="' + escapeHtml(fieldKey) + '" data-field-type="textarea" placeholder="' + escapeHtml(String(field.placeholder || '')) + '">' + escapeHtml(String(value || '')) + '</textarea>';
        } else if (fieldType === 'number') {
            control = '<input class="admin-clients-input' + invalidClass + '" id="field_' + escapeHtml(fieldKey) + '" data-form-field="' + escapeHtml(fieldKey) + '" data-field-type="number" type="text" inputmode="decimal" placeholder="' + escapeHtml(String(field.placeholder || '')) + '" value="' + escapeHtml(value !== undefined && value !== null ? String(value) : '') + '" />';
        } else if (fieldType === 'date') {
            control = '<input class="admin-clients-input' + invalidClass + '" id="field_' + escapeHtml(fieldKey) + '" data-form-field="' + escapeHtml(fieldKey) + '" data-field-type="date" type="date" value="' + escapeHtml(toDateInputValue(value)) + '" />';
        } else if (fieldType === 'radio') {
            control = renderOptionGroup(field, fieldKey, 'radio', value, invalidClass);
        } else if (fieldType === 'checkbox_single') {
            control = renderOptionGroup(field, fieldKey, 'checkbox_single', value, invalidClass);
        } else if (fieldType === 'checkbox_multiple') {
            control = renderOptionGroup(field, fieldKey, 'checkbox_multiple', value, invalidClass);
        } else {
            control = '<input class="admin-clients-input' + invalidClass + '" id="field_' + escapeHtml(fieldKey) + '" data-form-field="' + escapeHtml(fieldKey) + '" data-field-type="text" type="text" placeholder="' + escapeHtml(String(field.placeholder || '')) + '" value="' + escapeHtml(value !== undefined && value !== null ? String(value) : '') + '" />';
        }

        return '' +
            '<div class="admin-clients-template-field' + widthClass + '">' +
            '  <label class="admin-clients-label" for="field_' + escapeHtml(fieldKey) + '">' + label + (required ? ' *' : '') + '</label>' +
            '  <div class="admin-clients-template-control">' + control + '</div>' +
            (help ? '  <div class="admin-clients-help">' + escapeHtml(help) + '</div>' : '') +
            '  <div class="admin-clients-field-error" data-field-error="' + escapeHtml(fieldKey) + '">' + (error ? escapeHtml(error) : '') + '</div>' +
            '</div>';
    }

    function renderOptionGroup(field, fieldKey, type, value, invalidClass) {
        var options = Array.isArray(field.options) ? field.options : [];
        if (options.length === 0) {
            options = type === 'checkbox_single' ? ['Ja, bestaetigen'] : ['Option 1'];
        }

        var selectedArray = Array.isArray(value) ? value : [];
        var selectedSingle = value !== undefined && value !== null ? String(value) : '';

        return '<div class="admin-clients-choice-group' + invalidClass + '">' + options.map(function (opt, index) {
            var optionText = String(opt);
            var checked = false;
            var inputType = 'radio';
            var name = 'field_' + fieldKey;

            if (type === 'checkbox_multiple') {
                inputType = 'checkbox';
                checked = selectedArray.indexOf(optionText) !== -1;
            } else if (type === 'checkbox_single') {
                inputType = 'checkbox';
                checked = selectedSingle === optionText;
            } else {
                checked = selectedSingle === optionText;
            }

            return '' +
                '<label class="admin-clients-choice-row">' +
                '  <input type="' + inputType + '" name="' + escapeHtml(name) + '" value="' + escapeHtml(optionText) + '" data-form-field="' + escapeHtml(fieldKey) + '" data-field-type="' + escapeHtml(type) + '" ' + (checked ? 'checked' : '') + ' />' +
                '  <span>' + escapeHtml(optionText) + '</span>' +
                '</label>';
        }).join('') + '</div>';
    }

    function renderExportTab() {
        return '' +
            '<section class="admin-clients-card">' +
            '  <h3 class="admin-clients-card-title">Klientenakte exportieren</h3>' +
            '  <p class="admin-clients-help">Erstellt einen DSAR-Export fuer die gesamte Akte des aktuell ausgewaehlten Klienten.</p>' +
            '  <div class="admin-clients-actions"><button type="button" class="admin-clients-page-btn" data-create-export>Export erstellen</button></div>' +
            '  <div class="admin-clients-inline">' +
            '    <input id="exportJobId" class="admin-clients-input" type="number" min="1" placeholder="Job-ID fuer Statuspruefung" />' +
            '    <button type="button" class="admin-clients-page-btn" data-load-export-status>Status laden</button>' +
            '  </div>' +
            '</section>';
    }

    function tabButton(tabKey, label) {
        var active = state.detailTab === tabKey;
        return '<button type="button" class="admin-clients-tab' + (active ? ' is-active' : '') + '" data-detail-tab="' + tabKey + '" role="tab" aria-selected="' + (active ? 'true' : 'false') + '">' + escapeHtml(label) + '</button>';
    }

    function infoTabButton(tabKey, label) {
        var active = state.infoSubtab === tabKey;
        return '<button type="button" class="admin-clients-subtab' + (active ? ' is-active' : '') + '" data-info-tab="' + tabKey + '" role="tab" aria-selected="' + (active ? 'true' : 'false') + '">' + escapeHtml(label) + '</button>';
    }

    function overviewMetric(label, value, hint) {
        return '' +
            '<article class="admin-clients-metric-card">' +
            '  <span class="admin-clients-metric-label">' + escapeHtml(label) + '</span>' +
            '  <strong class="admin-clients-metric-value">' + escapeHtml(value) + '</strong>' +
            '  <p class="admin-clients-metric-hint">' + escapeHtml(hint) + '</p>' +
            '</article>';
    }

    function metaPill(label, value) {
        return '' +
            '<div class="admin-clients-meta-pill">' +
            '  <span class="admin-clients-meta-pill-label">' + escapeHtml(label) + '</span>' +
            '  <span class="admin-clients-meta-pill-value">' + escapeHtml(value) + '</span>' +
            '</div>';
    }

    function bindEvents() {
        if (viewMode === 'list') {
            var searchForm = root.querySelector('[data-search-form]');
            if (searchForm) {
                searchForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var fd = new FormData(searchForm);
                    state.search = trim(fd.get('q'));
                    state.page = 1;
                    fetchList();
                });
            }

            root.querySelectorAll('[data-sort]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var nextSort = String(btn.getAttribute('data-sort') || 'last_name');
                    if (state.sort === nextSort) state.direction = state.direction === 'asc' ? 'desc' : 'asc';
                    else {
                        state.sort = nextSort;
                        state.direction = 'asc';
                    }
                    state.page = 1;
                    fetchList();
                });
            });

            root.querySelectorAll('.admin-clients-row[data-row-id]').forEach(function (row) {
                row.addEventListener('click', function () {
                    var id = parsePositiveInt(row.getAttribute('data-row-id'), 0);
                    if (id <= 0) return;
                    window.location.href = '/clients/' + id;
                });
            });

            var prev = root.querySelector('[data-page-prev]');
            var next = root.querySelector('[data-page-next]');
            if (prev) prev.addEventListener('click', function () { if (state.page > 1) { state.page -= 1; fetchList(); } });
            if (next) next.addEventListener('click', function () { if (state.page < state.totalPages) { state.page += 1; fetchList(); } });

            var createClientBtn = root.querySelector('[data-create-client]');
            if (createClientBtn) createClientBtn.addEventListener('click', openCreateClientModal);
            return;
        }

        root.querySelectorAll('[data-detail-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tab = String(btn.getAttribute('data-detail-tab') || 'info');
                if (!inArray(tab, allowedDetailTabs())) return;
                state.detailTab = tab;
                writeRecordUrl(false);
                render();
            });
        });

        root.querySelectorAll('[data-info-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tab = String(btn.getAttribute('data-info-tab') || 'overview');
                if (!inArray(tab, ['overview', 'projects', 'history', 'invoices', 'packages', 'contracts'])) return;
                state.infoSubtab = tab;
                writeRecordUrl(false);
                render();
            });
        });

        var saveBtn = root.querySelector('[data-save-client]');
        if (saveBtn) saveBtn.addEventListener('click', saveClient);

        var createInvoiceBtn = root.querySelector('[data-create-invoice]');
        if (createInvoiceBtn) createInvoiceBtn.addEventListener('click', openCreateInvoiceModal);

        var createRecordBtn = root.querySelector('[data-create-record]');
        if (createRecordBtn) createRecordBtn.addEventListener('click', createFormRecord);

        var exportBtn = root.querySelector('[data-create-export]');
        if (exportBtn) exportBtn.addEventListener('click', createExport);

        var exportStatusBtn = root.querySelector('[data-load-export-status]');
        if (exportStatusBtn) exportStatusBtn.addEventListener('click', loadExportStatus);

        var templateSelect = root.querySelector('#recordTemplateId');
        if (templateSelect) {
            templateSelect.addEventListener('change', function () {
                state.selectedTemplateId = parsePositiveInt(templateSelect.value, 0) || null;
                resetRenderedFormState();
                render();
            });
        }

        root.querySelectorAll('[data-form-field]').forEach(function (el) {
            var eventName = (el.type === 'checkbox' || el.type === 'radio' || el.tagName === 'SELECT') ? 'change' : 'input';
            el.addEventListener(eventName, function () { handleTemplateFieldInput(el, false); });
            el.addEventListener('blur', function () { handleTemplateFieldInput(el, true); });
        });

        root.querySelectorAll('[data-open-record]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parsePositiveInt(btn.getAttribute('data-open-record'), 0);
                if (id <= 0) return;
                openSessionRecordModal(id);
            });
        });

        root.querySelectorAll('[data-open-attachments]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parsePositiveInt(btn.getAttribute('data-open-attachments'), 0);
                if (id <= 0) return;
                openAttachmentsModal(id);
            });
        });

        root.querySelectorAll('[data-open-invoice-pdf]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var viewUrl = String(btn.getAttribute('data-pdf-url') || '');
                var downloadUrl = String(btn.getAttribute('data-pdf-download-url') || viewUrl);
                var invoiceLabel = String(btn.getAttribute('data-invoice-label') || '-');
                if (trim(viewUrl) === '') {
                    notify('error', 'PDF ist nicht verfuegbar.');
                    return;
                }
                openInvoicePdfModal(viewUrl, downloadUrl, invoiceLabel);
            });
        });
    }

    function openInvoicePdfModal(viewUrl, downloadUrl, invoiceLabel) {
        var safeViewUrl = trim(String(viewUrl || ''));
        var safeDownloadUrl = trim(String(downloadUrl || ''));
        var label = trim(String(invoiceLabel || '-'));

        if (safeViewUrl === '') {
            notify('error', 'PDF ist nicht verfuegbar.');
            return;
        }

        var body = '' +
            '<section class="admin-clients-attachments" data-invoice-preview>' +
            '  <div class="admin-clients-help">Rechnung #' + escapeHtml(label) + '</div>' +
            '  <div style="height:min(80vh,900px); border:1px solid #ddd; border-radius:8px; overflow:hidden; background:#fff;">' +
            '    <iframe src="' + escapeHtml(safeViewUrl) + '" title="Rechnungs-PDF" style="width:100%; height:100%; border:0; background:#fff;"></iframe>' +
            '  </div>' +
            '</section>';

        window.adminOpenModal && window.adminOpenModal('Rechnung ansehen', body, {
            type: 'form',
            modalClass: 'admin-modal--preview',
            buttons: [
                {
                    label: 'Download',
                    variant: 'secondary',
                    onClick: function () {
                        if (safeDownloadUrl !== '') {
                            downloadInvoicePdf(safeDownloadUrl, 'rechnung-' + label + '.pdf')
                                .then(function () {
                                    goToClientInvoicesSection();
                                })
                                .catch(function (err) {
                                    notify('error', err && err.message ? err.message : 'PDF konnte nicht heruntergeladen werden.');
                                });
                        }
                    }
                },
                {
                    label: 'Schliessen',
                    variant: 'primary',
                    onClick: function () {
                        window.adminCloseModal && window.adminCloseModal();
                    }
                },
            ],
        });
    }

    function downloadInvoicePdf(downloadUrl, fallbackFileName) {
        var url = trim(String(downloadUrl || ''));
        if (url === '') {
            return Promise.reject(new Error('Download-URL fehlt.'));
        }

        return fetch(url, {
            method: 'GET',
            credentials: 'include',
            headers: { Accept: 'application/pdf' },
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Download fehlgeschlagen.');
                }

                var disposition = String(response.headers.get('Content-Disposition') || '');
                var fileName = extractDownloadFileName(disposition, fallbackFileName || 'rechnung.pdf');

                return response.blob().then(function (blob) {
                    var objectUrl = URL.createObjectURL(blob);
                    var anchor = document.createElement('a');
                    anchor.href = objectUrl;
                    anchor.download = fileName;
                    anchor.style.display = 'none';
                    document.body.appendChild(anchor);
                    anchor.click();
                    document.body.removeChild(anchor);
                    window.setTimeout(function () {
                        URL.revokeObjectURL(objectUrl);
                    }, 1000);
                });
            });
    }

    function extractDownloadFileName(disposition, fallbackFileName) {
        var raw = String(disposition || '');
        var fallback = trim(String(fallbackFileName || 'rechnung.pdf')) || 'rechnung.pdf';

        var utf8Match = raw.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8Match && utf8Match[1]) {
            try {
                return decodeURIComponent(utf8Match[1]);
            } catch (_err) {
                return utf8Match[1];
            }
        }

        var simpleMatch = raw.match(/filename="?([^";]+)"?/i);
        if (simpleMatch && simpleMatch[1]) {
            return simpleMatch[1];
        }

        return fallback;
    }

    function goToClientInvoicesSection() {
        if (!state.selectedClientId) return;
        state.infoSubtab = 'invoices';
        window.location.href = '/clients/' + state.selectedClientId + '?info_section=invoices';
    }

    function openAttachmentsModal(recordId) {
        var modalBodyId = 'adminClientsAttachmentsBody';
        var maxAttachmentBytes = parsePositiveInt(cfg.session_record_attachment_max_bytes, 0);
        var maxLabel = maxAttachmentBytes > 0 ? formatBytes(maxAttachmentBytes) : '-';
        var body = '' +
            '<section class="admin-clients-attachments" data-attachments-section>' +
            '  <p class="admin-clients-help">Datei auswaehlen und direkt dem Formularsatz #' + escapeHtml(String(recordId)) + ' zuordnen. Erlaubt: PDF, Bilder, Office-Dateien bis ' + escapeHtml(maxLabel) + '.</p>' +
            '  <form class="admin-clients-attachments-upload" id="adminClientsAttachmentUploadForm" enctype="multipart/form-data">' +
            '    <input class="admin-clients-input" id="adminClientsAttachmentFile" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx,.ppt,.pptx" required />' +
            '    <button type="submit" class="admin-clients-page-btn">Anhang hochladen</button>' +
            '  </form>' +
            '  <div class="admin-clients-meta" id="adminClientsAttachmentsStatus">Lade Anhaenge...</div>' +
            '  <div id="' + modalBodyId + '"></div>' +
            '</section>';

        window.adminOpenModal && window.adminOpenModal('Anhaenge zu Formular #' + recordId, body, {
            type: 'form',
            buttons: [
                { label: 'Schliessen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } },
            ],
        });

        bindAttachmentsModalEvents(recordId, modalBodyId);
        refreshAttachmentsModal(recordId, modalBodyId);
    }

    function openSessionRecordModal(recordId) {
        var endpoint = '/admin/session-records/data/' + recordId;

        fetch(endpoint, {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) {
                    throw new Error(extractErrorMessage(result, 'Formulardaten konnten nicht geladen werden.'));
                }

                var record = result.json && result.json.data ? result.json.data.record : null;
                if (!record || typeof record !== 'object') {
                    throw new Error('Formulardaten sind nicht verfuegbar.');
                }

                var body = renderSessionRecordModalBody(record);
                window.adminOpenModal && window.adminOpenModal('Formular #' + recordId, body, {
                    type: 'form',
                    modalClass: 'admin-modal--preview',
                    buttons: [
                        { label: 'Schliessen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } },
                    ],
                });
            })
            .catch(function (err) {
                notify('error', err && err.message ? err.message : 'Formulardaten konnten nicht geladen werden.');
            });
    }

    function normalizeRecordPayload(rawPayload) {
        if (rawPayload && typeof rawPayload === 'object' && !Array.isArray(rawPayload)) {
            return rawPayload;
        }

        if (typeof rawPayload === 'string' && trim(rawPayload) !== '') {
            try {
                var parsed = JSON.parse(rawPayload);
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    return parsed;
                }
            } catch (_err) {
                return {};
            }
        }

        return {};
    }

    function flattenTemplateSchemaForDisplay(schema) {
        var out = [];
        if (!Array.isArray(schema)) return out;

        for (var i = 0; i < schema.length; i += 1) {
            var item = schema[i];
            if (!item || typeof item !== 'object') continue;

            var type = String(item.type || '').toLowerCase();
            if (type === 'letterhead') continue;

            if (type === 'section') {
                var nested = flattenTemplateSchemaForDisplay(Array.isArray(item.items) ? item.items : []);
                for (var n = 0; n < nested.length; n += 1) out.push(nested[n]);
                continue;
            }

            var key = trim(item.field_key || '');
            if (key === '') continue;

            out.push({
                key: key,
                label: trim(item.label || '') || key,
                type: type,
            });
        }

        return out;
    }

    function renderSessionRecordModalBody(record) {
        var templateName = record && record.template && record.template.name
            ? String(record.template.name)
            : ('Template #' + String(record && record.template_id ? record.template_id : '-'));
        var templateVersion = record && record.template && record.template.version_no
            ? String(record.template.version_no)
            : String(record && record.template_version_id ? record.template_version_id : '-');
        var status = String(record && record.status ? record.status : '-');
        var updated = formatDateTime(record && (record.updated_at || record.created_at) ? (record.updated_at || record.created_at) : '');

        var payload = normalizeRecordPayload(record ? record.payload_json : null);
        var schema = Array.isArray(record && record.template_schema_json) ? record.template_schema_json : [];
        var formHtml = renderSessionRecordFormFromSchema(schema, payload, record);

        var usedKeys = Object.create(null);
        collectSchemaFieldKeys(schema, usedKeys);
        var extraRows = Object.keys(payload)
            .filter(function (key) { return !usedKeys[key]; })
            .map(function (key) {
                return '' +
                    '<tr>' +
                    '  <td style="width:28%; vertical-align:top; font-weight:600;">' + escapeHtml(String(key)) + '</td>' +
                    '  <td style="vertical-align:top;">' + renderSessionRecordValue(payload[key]) + '</td>' +
                    '</tr>';
            }).join('');

        var extraBlock = extraRows === ''
            ? ''
            : '' +
            '<div class="admin-clients-table-wrap" style="margin-top:0.75rem;">' +
            '  <table class="admin-clients-table">' +
            '    <thead><tr><th>Weitere Felder</th><th>Wert</th></tr></thead>' +
            '    <tbody>' + extraRows + '</tbody>' +
            '  </table>' +
            '</div>';

        return '' +
            '<section class="admin-clients-attachments">' +
            '  <div class="admin-clients-meta-grid">' +
            metaPill('Status', status) +
            metaPill('Template', templateName) +
            metaPill('Version', templateVersion) +
            metaPill('Aktualisiert', updated) +
            (schema.length > 0 ? metaPill('Darstellung', 'Template-Schema') : metaPill('Darstellung', 'JSON (Fallback)')) +
            '  </div>' +
            '<div style="margin-top:0.75rem;">' + formHtml + '</div>' +
            extraBlock +
            '</section>';
    }

    function collectSchemaFieldKeys(schema, target) {
        if (!Array.isArray(schema)) return;

        for (var i = 0; i < schema.length; i += 1) {
            var item = schema[i];
            if (!item || typeof item !== 'object') continue;

            var type = String(item.type || '').toLowerCase();
            if (type === 'section') {
                collectSchemaFieldKeys(Array.isArray(item.items) ? item.items : [], target);
                continue;
            }

            if (type === 'letterhead') continue;
            var key = trim(item.field_key || '');
            if (key !== '') target[key] = true;
        }
    }

    function renderSessionRecordFormFromSchema(schema, payload, record) {
        if (!Array.isArray(schema) || schema.length === 0) {
            return renderSessionRecordFallbackTable(payload);
        }

        var parts = [];
        for (var i = 0; i < schema.length; i += 1) {
            var item = schema[i];
            if (!item || typeof item !== 'object') continue;

            var type = String(item.type || '').toLowerCase();
            if (type === 'letterhead') {
                parts.push(renderSessionRecordLetterhead(item, record));
                continue;
            }

            if (type === 'section') {
                var sectionHtml = renderSessionRecordSection(item, payload);
                if (sectionHtml !== '') parts.push(sectionHtml);
                continue;
            }

            var fieldHtml = renderSessionRecordFieldItem(item, payload);
            if (fieldHtml !== '') parts.push('<div class="admin-clients-card" style="padding:0.9rem;">' + fieldHtml + '</div>');
        }

        if (parts.length === 0) {
            return renderSessionRecordFallbackTable(payload);
        }

        return parts.join('');
    }

    function renderSessionRecordLetterhead(item, record) {
        var contextLine = resolveTemplateContextLine(record && record.client ? record.client : null, record && record.created_at ? record.created_at : null);
        return '' +
            '<header class="admin-clients-template-letterhead" style="margin-bottom:0.75rem;">' +
            (item.practice_name ? '<div class="admin-clients-template-practice">' + escapeHtml(String(item.practice_name)) + '</div>' : '') +
            (item.form_title ? '<h4 class="admin-clients-template-title">' + escapeHtml(String(item.form_title)) + '</h4>' : '') +
            (item.subtitle ? '<p class="admin-clients-template-subtitle">' + escapeHtml(String(item.subtitle)) + '</p>' : '') +
            (contextLine ? '<p class="admin-clients-template-context">' + escapeHtml(contextLine) + '</p>' : '') +
            '</header>';
    }

    function resolveTemplateContextLine(client, createdAtValue) {
        var fullName = trim(client && client.name ? client.name : '');
        if (fullName === '') {
            fullName = 'Unbekannt';
        }

        var dateText = formatContextDate(createdAtValue);
        return 'Klient: ' + fullName + ' | Erstellt am: ' + dateText;
    }

    function formatContextDate(value) {
        var date = null;
        if (!value) {
            date = new Date();
        } else if (value instanceof Date) {
            date = value;
        } else {
            date = new Date(String(value).replace(' ', 'T'));
        }

        if (!date || Number.isNaN(date.getTime())) {
            date = new Date();
        }

        return date.toLocaleDateString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        });
    }

    function renderSessionRecordSection(section, payload) {
        var items = Array.isArray(section.items) ? section.items : [];
        var fields = items.map(function (item) { return renderSessionRecordFieldItem(item, payload); }).filter(Boolean);
        if (fields.length === 0) return '';

        return '' +
            '<section class="admin-clients-template-section" style="margin-bottom:0.75rem;">' +
            (section.label ? '<h4 class="admin-clients-template-section-title">' + escapeHtml(String(section.label)) + '</h4>' : '') +
            (section.description ? '<p class="admin-clients-template-section-desc">' + escapeHtml(String(section.description)) + '</p>' : '') +
            '<div class="admin-clients-template-fields">' + fields.join('') + '</div>' +
            '</section>';
    }

    function renderSessionRecordFieldItem(field, payload) {
        if (!field || typeof field !== 'object') return '';

        var type = String(field.type || '').toLowerCase();
        if (type === 'section' || type === 'letterhead') return '';

        var key = trim(field.field_key || '');
        if (key === '') return '';

        var value = Object.prototype.hasOwnProperty.call(payload, key) ? payload[key] : null;
        var label = trim(field.label || '') || key;
        var help = trim(field.help_text || '');
        var widthClass = field.width === 'half' ? ' admin-clients-template-field--half' : '';

        return '' +
            '<div class="admin-clients-template-field' + widthClass + '">' +
            '  <label class="admin-clients-label">' + escapeHtml(label) + '</label>' +
            '  <div class="admin-clients-template-control" style="padding:0.5rem 0.65rem; border:1px solid var(--admin-border); border-radius:8px; background:rgba(255,255,255,0.04);">' +
            renderSessionRecordValue(value) +
            '  </div>' +
            (help ? '  <div class="admin-clients-help">' + escapeHtml(help) + '</div>' : '') +
            '</div>';
    }

    function renderSessionRecordFallbackTable(payload) {
        var keys = Object.keys(payload || {});
        var rows = keys.length === 0
            ? '<tr><td colspan="2" class="admin-clients-empty">Keine Formulardaten vorhanden.</td></tr>'
            : keys.map(function (key) {
                return '' +
                    '<tr>' +
                    '  <td style="width:28%; vertical-align:top; font-weight:600;">' + escapeHtml(String(key)) + '</td>' +
                    '  <td style="vertical-align:top;">' + renderSessionRecordValue(payload[key]) + '</td>' +
                    '</tr>';
            }).join('');

        return '' +
            '<div class="admin-clients-table-wrap">' +
            '  <table class="admin-clients-table">' +
            '    <thead><tr><th>Feld</th><th>Wert</th></tr></thead>' +
            '    <tbody>' + rows + '</tbody>' +
            '  </table>' +
            '</div>';
    }

    function renderSessionRecordValue(value) {
        if (value === null || value === undefined) {
            return '<span class="admin-clients-muted">-</span>';
        }

        if (Array.isArray(value)) {
            if (value.length === 0) {
                return '<span class="admin-clients-muted">[]</span>';
            }

            return '<ul style="margin:0; padding-left:1.1rem;">' + value.map(function (item) {
                return '<li>' + renderSessionRecordValueInline(item) + '</li>';
            }).join('') + '</ul>';
        }

        if (typeof value === 'object') {
            var json = safeJsonStringify(value);
            return '<pre style="margin:0; white-space:pre-wrap; word-break:break-word;">' + escapeHtml(json) + '</pre>';
        }

        return escapeHtml(String(value));
    }

    function renderSessionRecordValueInline(value) {
        if (value === null || value === undefined) return '<span class="admin-clients-muted">-</span>';
        if (typeof value === 'object') return escapeHtml(safeJsonStringify(value));
        return escapeHtml(String(value));
    }

    function safeJsonStringify(value) {
        try {
            return JSON.stringify(value, null, 2);
        } catch (_err) {
            return String(value);
        }
    }

    function bindAttachmentsModalEvents(recordId, modalBodyId) {
        var uploadForm = document.getElementById('adminClientsAttachmentUploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function (event) {
                event.preventDefault();

                var fileInput = document.getElementById('adminClientsAttachmentFile');
                var file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                if (!file) {
                    notify('warning', 'Bitte zuerst eine Datei auswaehlen.');
                    return;
                }

                var maxBytes = parsePositiveInt(cfg.session_record_attachment_max_bytes, 0);
                if (maxBytes > 0 && Number(file.size || 0) > maxBytes) {
                    notify('error', 'Datei ist zu gross. Maximal erlaubt: ' + formatBytes(maxBytes) + '.');
                    return;
                }

                setAttachmentStatus('Upload wird vorbereitet...');
                uploadAttachment(recordId, file)
                    .then(function () {
                        if (fileInput) fileInput.value = '';
                        notify('success', 'Anhang wurde hochgeladen.');
                        refreshAttachmentsModal(recordId, modalBodyId);
                    })
                    .catch(function (err) {
                        notify('error', err && err.message ? err.message : 'Anhang konnte nicht hochgeladen werden.');
                        setAttachmentStatus('Upload fehlgeschlagen.');
                    });
            });
        }

        var section = document.querySelector('[data-attachments-section]');
        if (!section) return;

        section.addEventListener('click', function (event) {
            var previewTrigger = event.target && event.target.closest ? event.target.closest('[data-preview-attachment]') : null;
            if (previewTrigger) {
                var previewRecordId = parsePositiveInt(previewTrigger.getAttribute('data-record-id'), 0);
                var previewAttachmentId = parsePositiveInt(previewTrigger.getAttribute('data-preview-attachment'), 0);
                if (previewRecordId !== recordId || previewAttachmentId <= 0) return;

                event.preventDefault();
                openAttachmentPreview(recordId, previewAttachmentId, String(previewTrigger.getAttribute('data-file-name') || 'Anhang'));
                return;
            }

            var downloadTrigger = event.target && event.target.closest ? event.target.closest('[data-download-attachment]') : null;
            if (!downloadTrigger) return;

            var triggerRecordId = parsePositiveInt(downloadTrigger.getAttribute('data-record-id'), 0);
            var attachmentId = parsePositiveInt(downloadTrigger.getAttribute('data-download-attachment'), 0);
            if (triggerRecordId !== recordId || attachmentId <= 0) return;

            event.preventDefault();
            requestAttachmentDownload(recordId, attachmentId)
                .catch(function (err) {
                    notify('error', err && err.message ? err.message : 'Download konnte nicht gestartet werden.');
                });
        });
    }

    function refreshAttachmentsModal(recordId, modalBodyId) {
        setAttachmentStatus('Lade Anhaenge...');
        listAttachments(recordId)
            .then(function (attachments) {
                var target = document.getElementById(modalBodyId);
                if (!target) return;
                target.innerHTML = renderAttachmentsList(recordId, attachments);
                setAttachmentStatus(attachments.length === 0 ? 'Keine Anhaenge vorhanden.' : String(attachments.length) + ' Anhang/Anhaenge geladen.');
            })
            .catch(function (err) {
                var target = document.getElementById(modalBodyId);
                if (target) {
                    target.innerHTML = '<div class="admin-clients-empty">Anhaenge konnten nicht geladen werden.</div>';
                }
                setAttachmentStatus('Fehler beim Laden.');
                notify('error', err && err.message ? err.message : 'Anhaenge konnten nicht geladen werden.');
            });
    }

    function renderAttachmentsList(recordId, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '<div class="admin-clients-empty">Noch keine Anhaenge vorhanden.</div>';
        }

        var rows = items.map(function (item) {
            var id = parsePositiveInt(item && item.id, 0);
            var fileName = String(item && item.original_filename ? item.original_filename : 'Anhang');
            var previewable = isPreviewableAttachment(fileName);
            return '' +
                '<tr>' +
                '  <td>' + escapeHtml(fileName) + '</td>' +
                '  <td>' + escapeHtml(formatBytes(item && item.size_bytes ? item.size_bytes : 0)) + '</td>' +
                '  <td>' + escapeHtml(formatDateTime(item && item.uploaded_at ? item.uploaded_at : '')) + '</td>' +
                '  <td>' + escapeHtml(abbreviateHash(item && item.checksum_sha256 ? item.checksum_sha256 : '')) + '</td>' +
                '  <td>' +
                (id > 0
                    ? (previewable
                        ? '<button type="button" class="admin-clients-page-btn" data-preview-attachment="' + escapeHtml(String(id)) + '" data-record-id="' + escapeHtml(String(recordId)) + '" data-file-name="' + escapeHtml(fileName) + '">Ansehen</button> '
                        : '') +
                    '<button type="button" class="admin-clients-page-btn" data-download-attachment="' + escapeHtml(String(id)) + '" data-record-id="' + escapeHtml(String(recordId)) + '">Download</button>'
                    : '<span class="admin-clients-muted">-</span>') +
                '  </td>' +
                '</tr>';
        }).join('');

        return '' +
            '<div class="admin-clients-table-wrap">' +
            '  <table class="admin-clients-table admin-clients-table--attachments">' +
            '    <thead><tr><th>Datei</th><th>Groesse</th><th>Hochgeladen</th><th>Checksum</th><th>Aktion</th></tr></thead>' +
            '    <tbody>' + rows + '</tbody>' +
            '  </table>' +
            '</div>';
    }

    function setAttachmentStatus(message) {
        var node = document.getElementById('adminClientsAttachmentsStatus');
        if (!node) return;
        node.textContent = String(message || '');
    }

    function listAttachments(recordId) {
        var endpoint = apiUrl(cfg.api && cfg.api.session_record_attachments_list, { id: recordId });
        if (!endpoint) endpoint = '/admin/session-records/data/' + recordId + '/attachments';

        return fetch(endpoint, {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) {
                    throw new Error(extractErrorMessage(result, 'Anhaenge konnten nicht geladen werden.'));
                }

                var payload = result.json && result.json.data ? result.json.data : {};
                return Array.isArray(payload.attachments) ? payload.attachments : [];
            });
    }

    function uploadAttachment(recordId, file) {
        var initEndpoint = apiUrl(cfg.api && cfg.api.session_record_attachments_upload_init, { id: recordId });
        if (!initEndpoint) initEndpoint = '/admin/session-records/data/' + recordId + '/attachments/chunk/init';

        var chunkEndpointTemplate = cfg.api && cfg.api.session_record_attachments_upload_chunk
            ? String(cfg.api.session_record_attachments_upload_chunk)
            : '/admin/session-records/data/{id}/attachments/chunk/{upload_id}';
        var finishEndpointTemplate = cfg.api && cfg.api.session_record_attachments_upload_finish
            ? String(cfg.api.session_record_attachments_upload_finish)
            : '/admin/session-records/data/{id}/attachments/chunk/{upload_id}/finish';

        var requestedChunkSize = getAttachmentChunkSizeBytes();
        var initPayload = {
            filename: String(file.name || 'attachment.bin'),
            mime_type: String(file.type || 'application/octet-stream'),
            total_size: Number(file.size || 0),
        };

        return fetch(initEndpoint, {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(initPayload),
        })
            .then(parseJsonResponse)
            .then(function (initResult) {
                if (initResult.status !== 200 && initResult.status !== 201) {
                    throw new Error(extractErrorMessage(initResult, 'Anhang konnte nicht hochgeladen werden.'));
                }

                var initData = initResult.json && initResult.json.data ? initResult.json.data : {};
                var uploadId = trim(initData.upload_id || '');
                if (uploadId === '') {
                    throw new Error('Upload-Session konnte nicht gestartet werden.');
                }

                var serverChunkSize = parsePositiveInt(initData.chunk_size_bytes, 0);
                var chunkSize = serverChunkSize > 0 ? serverChunkSize : requestedChunkSize;
                var totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
                var sequence = Promise.resolve();

                for (var chunkIndex = 0; chunkIndex < totalChunks; chunkIndex += 1) {
                    (function (currentChunkIndex) {
                        sequence = sequence.then(function () {
                            var start = currentChunkIndex * chunkSize;
                            var end = Math.min(start + chunkSize, file.size);
                            var chunkBlob = file.slice(start, end);
                            var chunkEndpoint = apiUrl(chunkEndpointTemplate, {
                                id: recordId,
                                upload_id: uploadId,
                            });

                            setAttachmentStatus('Upload laeuft... Chunk ' + (currentChunkIndex + 1) + ' von ' + totalChunks + '.');

                            return fetch(chunkEndpoint, {
                                method: 'POST',
                                credentials: 'include',
                                headers: {
                                    Accept: 'application/json',
                                    'Content-Type': 'application/octet-stream',
                                    'X-Chunk-Index': String(currentChunkIndex),
                                },
                                body: chunkBlob,
                            })
                                .then(parseJsonResponse)
                                .then(function (chunkResult) {
                                    if (chunkResult.status !== 200 && chunkResult.status !== 201) {
                                        throw new Error(extractErrorMessage(chunkResult, 'Chunk-Upload fehlgeschlagen.'));
                                    }
                                });
                        });
                    }(chunkIndex));
                }

                return sequence.then(function () {
                    var finishEndpoint = apiUrl(finishEndpointTemplate, {
                        id: recordId,
                        upload_id: uploadId,
                    });
                    setAttachmentStatus('Upload wird finalisiert...');

                    return fetch(finishEndpoint, {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ upload_id: uploadId }),
                    })
                        .then(parseJsonResponse)
                        .then(function (finishResult) {
                            if (finishResult.status !== 200 && finishResult.status !== 201) {
                                throw new Error(extractErrorMessage(finishResult, 'Anhang konnte nicht hochgeladen werden.'));
                            }

                            return finishResult.json && finishResult.json.data ? finishResult.json.data : {};
                        });
                });
            });
    }

    function getAttachmentChunkSizeBytes() {
        var configured = parsePositiveInt(cfg.session_record_attachment_chunk_size_bytes, 0);
        return configured > 0 ? configured : (500 * 1024);
    }

    function requestAttachmentDownload(recordId, attachmentId) {
        return requestAttachmentToken(recordId, attachmentId)
            .then(function (token) {
                var downloadUrl = buildAttachmentDownloadUrl(recordId, attachmentId, token, 'attachment');
                window.open(downloadUrl, '_blank', 'noopener');
            });
    }

    function openAttachmentPreview(recordId, attachmentId, fileName) {
        requestAttachmentToken(recordId, attachmentId)
            .then(function (token) {
                var previewUrl = buildAttachmentPreviewUrl(recordId, attachmentId, token);
                return fetch(previewUrl, {
                    method: 'GET',
                    credentials: 'include',
                    headers: { Accept: 'application/pdf,image/*,application/octet-stream' },
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Vorschau konnte nicht geladen werden.');
                        }
                        return response.arrayBuffer();
                    })
                    .then(function (buffer) {
                        var previewKind = getAttachmentPreviewKind(fileName);
                        var forcedMime = guessPreviewMimeType(fileName, previewKind);
                        var blob = forcedMime !== ''
                            ? new Blob([buffer], { type: forcedMime })
                            : new Blob([buffer]);
                        var objectUrl = URL.createObjectURL(blob);

                        // Safety cleanup in case user closes via ESC/backdrop instead of footer button.
                        window.setTimeout(function () {
                            URL.revokeObjectURL(objectUrl);
                        }, 5 * 60 * 1000);

                        var previewNode = '';
                        if (previewKind === 'image') {
                            previewNode = '<img src="' + escapeHtml(objectUrl) + '" alt="Anhang" style="display:block;width:100%;height:100%;object-fit:contain;background:#111;" />';
                        } else if (previewKind === 'pdf') {
                            previewNode = '<iframe src="' + escapeHtml(objectUrl) + '" title="Anhang Vorschau" style="width:100%; height:100%; border:0; background:#fff;"></iframe>';
                        } else {
                            previewNode = '' +
                                '<div class="admin-clients-empty" style="padding:2rem;">' +
                                'Diese Datei kann hier nicht direkt angezeigt werden. Bitte Download verwenden.' +
                                '</div>';
                        }

                        var body = '' +
                            '<section class="admin-clients-attachments" data-attachment-preview>' +
                            '  <div class="admin-clients-help">Vorschau: ' + escapeHtml(fileName) + '</div>' +
                            '  <div style="height:min(80vh,900px); border:1px solid #ddd; border-radius:8px; overflow:hidden; background:#fff;">' +
                            previewNode +
                            '  </div>' +
                            '</section>';

                        window.adminOpenModal && window.adminOpenModal('Anhang ansehen', body, {
                            type: 'form',
                            modalClass: 'admin-modal--preview',
                            buttons: [
                                {
                                    label: 'Download',
                                    variant: 'secondary',
                                    onClick: function () {
                                        requestAttachmentDownload(recordId, attachmentId).catch(function () { });
                                    }
                                },
                                {
                                    label: 'Schliessen',
                                    variant: 'primary',
                                    onClick: function () {
                                        URL.revokeObjectURL(objectUrl);
                                        window.adminCloseModal && window.adminCloseModal();
                                    }
                                },
                            ],
                        });
                    });
            })
            .catch(function (err) {
                notify('error', err && err.message ? err.message : 'Vorschau konnte nicht geladen werden.');
            });
    }

    function requestAttachmentToken(recordId, attachmentId) {
        var tokenEndpoint = apiUrl(cfg.api && cfg.api.session_record_attachment_download_token, {
            id: recordId,
            attachment_id: attachmentId,
        });
        if (!tokenEndpoint) tokenEndpoint = '/admin/session-records/data/' + recordId + '/attachments/' + attachmentId + '/download-token';

        return fetch(tokenEndpoint, {
            method: 'POST',
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) {
                    throw new Error(extractErrorMessage(result, 'Download-Token konnte nicht erstellt werden.'));
                }

                var token = result.json && result.json.data ? String(result.json.data.token || '') : '';
                if (token === '') throw new Error('Download-Token fehlt.');

                return token;
            });
    }

    function buildAttachmentDownloadUrl(recordId, attachmentId, token, disposition) {
        var downloadEndpoint = apiUrl(cfg.api && cfg.api.session_record_attachment_download, {
            id: recordId,
            attachment_id: attachmentId,
        });
        if (!downloadEndpoint) downloadEndpoint = '/admin/session-records/data/' + recordId + '/attachments/' + attachmentId + '/download';

        var query = '?token=' + encodeURIComponent(token);
        if (trim(disposition || '') !== '') {
            query += '&disposition=' + encodeURIComponent(String(disposition));
        }

        return downloadEndpoint + query;
    }

    function buildAttachmentPreviewUrl(recordId, attachmentId, token) {
        var previewEndpoint = apiUrl(cfg.api && cfg.api.session_record_attachment_preview, {
            id: recordId,
            attachment_id: attachmentId,
        });
        if (!previewEndpoint) previewEndpoint = '/admin/session-records/data/' + recordId + '/attachments/' + attachmentId + '/preview';

        return previewEndpoint + '?token=' + encodeURIComponent(token);
    }

    function isPreviewableAttachment(fileName) {
        return getAttachmentPreviewKind(fileName) !== 'none';
    }

    function getAttachmentPreviewKind(fileName) {
        var name = trim(fileName || '').toLowerCase();
        if (name === '') return 'none';

        if (/\.(png|jpe?g|webp|gif)$/i.test(name)) return 'image';
        if (/\.pdf$/i.test(name)) return 'pdf';
        return 'none';
    }

    function guessPreviewMimeType(fileName, previewKind) {
        var name = trim(fileName || '').toLowerCase();
        if (previewKind === 'pdf') return 'application/pdf';
        if (previewKind !== 'image') return '';

        if (/\.png$/i.test(name)) return 'image/png';
        if (/\.jpe?g$/i.test(name)) return 'image/jpeg';
        if (/\.webp$/i.test(name)) return 'image/webp';
        if (/\.gif$/i.test(name)) return 'image/gif';
        return 'image/*';
    }

    function handleTemplateFieldInput(element, touch) {
        var fieldKey = String(element.getAttribute('data-form-field') || '');
        if (fieldKey === '') return;

        var field = findFieldDefinition(fieldKey);
        if (!field) return;

        state.formDraft[fieldKey] = readFieldValue(fieldKey, field.type);
        if (touch) state.formTouched[fieldKey] = true;

        var validation = validateTemplatePayload(currentTemplateSchema(), state.formDraft, false);
        state.formErrors = validation.errors;
        renderFieldValidation(fieldKey);
    }

    function renderFieldValidation(fieldKey) {
        var errorNode = root.querySelector('[data-field-error="' + cssEscape(fieldKey) + '"]');
        if (!errorNode) return;

        var message = state.formTouched[fieldKey] && state.formErrors[fieldKey] ? String(state.formErrors[fieldKey]) : '';
        errorNode.textContent = message;

        root.querySelectorAll('[data-form-field="' + cssEscape(fieldKey) + '"]').forEach(function (el) {
            el.classList.toggle('is-invalid', message !== '');
        });

        var choiceGroup = errorNode.parentElement ? errorNode.parentElement.querySelector('.admin-clients-choice-group') : null;
        if (choiceGroup) choiceGroup.classList.toggle('is-invalid', message !== '');
    }

    function fetchList() {
        if (!canView) return Promise.resolve();
        state.loadingList = true;
        render();

        var params = new URLSearchParams({
            page: String(state.page),
            per_page: String(state.perPage),
            sort: state.sort,
            direction: state.direction,
        });
        if (state.search !== '') params.set('q', state.search);

        return fetch(apiUrl(cfg.api && cfg.api.list) + '?' + params.toString(), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) throw new Error('Clients konnten nicht geladen werden');
                var data = (result.json && result.json.data) || {};
                state.clients = Array.isArray(data.clients) ? data.clients : [];
                state.total = parsePositiveInt(data.meta && data.meta.total, 0);
                state.totalPages = parsePositiveInt(data.meta && data.meta.total_pages, 1);
                state.page = Math.max(1, Math.min(state.page, state.totalPages));
            })
            .catch(function (err) {
                state.clients = [];
                state.total = 0;
                state.totalPages = 1;
                notify('error', err && err.message ? err.message : 'Clients konnten nicht geladen werden.');
            })
            .finally(function () {
                state.loadingList = false;
                render();
            });
    }

    function loadRecordView() {
        state.loadingDetail = true;
        render();

        Promise.all([
            fetchClientDetail(state.selectedClientId),
            fetchClientRecords(state.selectedClientId),
            fetchClientBookings(state.selectedClientId),
            fetchClientHistory(state.selectedClientId),
            fetchClientConsents(state.selectedClientId),
            fetchClientPackages(state.selectedClientId),
            fetchLatestTemplates(),
        ])
            .then(function () {
                state.loadingDetail = false;
                render();
            })
            .catch(function (err) {
                state.loadingDetail = false;
                notify('error', err && err.message ? err.message : 'Klientenakte konnte nicht geladen werden.');
                render();
            });
    }

    function fetchClientDetail(id) {
        return fetch(apiUrl(cfg.api && cfg.api.detail, id), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) throw new Error('Client-Details konnten nicht geladen werden');
                state.selectedClient = result.json && result.json.data ? result.json.data.client : null;
            });
    }

    function fetchClientRecords(id) {
        state.loadingRecords = true;
        var params = new URLSearchParams({ client_id: String(id), page: '1', per_page: '100' });
        return fetch(apiUrl(cfg.api && cfg.api.session_records_list) + '?' + params.toString(), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                state.loadingRecords = false;
                if (result.status !== 200) {
                    state.formRecords = [];
                    return;
                }
                var data = result.json && result.json.data && result.json.data.data;
                state.formRecords = Array.isArray(data) ? data : [];
            })
            .catch(function () {
                state.loadingRecords = false;
                state.formRecords = [];
            });
    }

    function fetchClientBookings(id) {
        state.loadingInvoices = true;
        return fetch(apiUrl(cfg.api && cfg.api.invoices, id), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) {
                    state.bookings = [];
                    state.invoiceBookings = [];
                    state.projects = [];
                    state.projectDetails = {};
                    state.loadingProjects = false;
                    state.loadingInvoices = false;
                    return;
                }
                var rows = result.json && result.json.data && result.json.data.projects;
                state.projects = (Array.isArray(rows) ? rows : []).filter(isProjectActive);
                state.bookings = [];
                state.invoiceBookings = flattenProjectInvoices(state.projects);
                state.loadingInvoices = false;

                state.loadingProjects = true;
                return fetchProjectRelations(state.projects).finally(function () {
                    state.loadingProjects = false;
                });
            })
            .catch(function () {
                state.bookings = [];
                state.invoiceBookings = [];
                state.projects = [];
                state.projectDetails = {};
                state.loadingProjects = false;
                state.loadingInvoices = false;
            });
    }

    function fetchProjectRelations(projects) {
        var items = Array.isArray(projects) ? projects : [];
        if (items.length === 0) {
            state.projectDetails = {};
            return Promise.resolve();
        }

        var all = items.map(function (project) {
            var projectId = parsePositiveInt(project && project.id, 0);
            if (projectId <= 0) return Promise.resolve();

            state.projectDetails[projectId] = {
                loading: true,
                phases: [],
                members: [],
                contracts: [],
                testProtocols: [],
                files: [],
                notes: [],
            };

            return Promise.all([
                fetch(apiUrl(cfg.api && cfg.api.project_detail, projectId), { credentials: 'include', headers: { Accept: 'application/json' } }).then(parseJsonResponse),
                fetch(apiUrl(cfg.api && cfg.api.project_phases, projectId), { credentials: 'include', headers: { Accept: 'application/json' } }).then(parseJsonResponse),
                fetch(apiUrl(cfg.api && cfg.api.project_members, projectId), { credentials: 'include', headers: { Accept: 'application/json' } }).then(parseJsonResponse),
            ]).then(function (results) {
                var detailRes = results[0];
                var phasesRes = results[1];
                var membersRes = results[2];

                var projectMeta = detailRes && detailRes.status === 200 && detailRes.json && detailRes.json.data
                    ? detailRes.json.data.project
                    : {};
                var phasesRaw = phasesRes && phasesRes.status === 200 && phasesRes.json && phasesRes.json.data && Array.isArray(phasesRes.json.data.phases)
                    ? phasesRes.json.data.phases
                    : [];
                var phases = phasesRaw.filter(isPhaseActive);
                var members = membersRes && membersRes.status === 200 && membersRes.json && membersRes.json.data && Array.isArray(membersRes.json.data.members)
                    ? membersRes.json.data.members
                    : [];

                state.projectDetails[projectId] = {
                    loading: false,
                    phases: phases,
                    members: members,
                    contracts: [],
                    testProtocols: flattenProjectTestProtocols(phases),
                    files: flattenProjectFiles(projectId, phases),
                    notes: trim(projectMeta && projectMeta.description ? String(projectMeta.description) : '') !== ''
                        ? [String(projectMeta.description)]
                        : [],
                };
            }).catch(function () {
                state.projectDetails[projectId] = {
                    loading: false,
                    phases: [],
                    members: [],
                    contracts: [],
                    testProtocols: [],
                    files: [],
                    notes: [],
                };
            });
        });

        return Promise.all(all);
    }

    function flattenProjectInvoices(projects) {
        var result = [];
        var items = Array.isArray(projects) ? projects : [];
        for (var i = 0; i < items.length; i += 1) {
            var project = items[i];
            var invoices = Array.isArray(project && project.invoices) ? project.invoices : [];
            for (var j = 0; j < invoices.length; j += 1) {
                result.push({
                    project_id: parsePositiveInt(project && project.id, 0),
                    project_name: String(project && project.name ? project.name : ''),
                    project_status: String(project && project.status ? project.status : ''),
                    booking_scheduled_at: invoices[j] && invoices[j].invoice_date ? invoices[j].invoice_date : null,
                    invoice: invoices[j],
                });
            }
        }
        return result;
    }

    function flattenProjectTestProtocols(phases) {
        var result = [];
        var items = Array.isArray(phases) ? phases : [];
        for (var i = 0; i < items.length; i += 1) {
            var phase = items[i] || {};
            var testData = phase.test_data && typeof phase.test_data === 'object' ? phase.test_data : null;
            var hasTemplate = !!(testData && parsePositiveInt(testData.template_id, 0) > 0);
            if (!hasTemplate) continue;

            result.push({
                phase_name: String(phase.phase_name || ''),
                template_name: String(testData.template_name || phase.test_template_name || ''),
                template_key: String(testData.template_key || ''),
                status: String(testData.status || (phase.integration_tests_finished ? 'completed' : 'draft')),
                saved_at: String(testData.saved_at || phase.test_date || ''),
            });
        }
        return result;
    }

    function flattenProjectFiles(projectId, phases) {
        var result = [];
        var items = Array.isArray(phases) ? phases : [];
        for (var i = 0; i < items.length; i += 1) {
            var phase = items[i] || {};
            var testData = phase.test_data && typeof phase.test_data === 'object' ? phase.test_data : null;
            var attachments = testData && Array.isArray(testData.attachments) ? testData.attachments : [];
            for (var j = 0; j < attachments.length; j += 1) {
                var attachment = attachments[j] || {};
                var attachmentId = trim(String(attachment.id || ''));
                result.push({
                    id: attachmentId,
                    phase_id: parsePositiveInt(phase.id, 0),
                    phase_name: String(phase.phase_name || ''),
                    original_filename: String(attachment.original_filename || 'Anhang'),
                    mime_type: String(attachment.mime_type || ''),
                    uploaded_at: String(attachment.uploaded_at || ''),
                    download_url: attachmentId !== ''
                        ? ('/projects/' + projectId + '/phase/' + parsePositiveInt(phase.id, 0) + '/test-data/attachments/' + encodeURIComponent(attachmentId) + '/download')
                        : null,
                });
            }
        }
        return result;
    }

    function isProjectActive(project) {
        var p = project && typeof project === 'object' ? project : {};
        if (p.is_active !== undefined && p.is_active !== null) {
            if (typeof p.is_active === 'boolean') return p.is_active;
            if (typeof p.is_active === 'number') return p.is_active === 1;

            var activeText = trim(String(p.is_active)).toLowerCase();
            if (activeText === '1' || activeText === 'true' || activeText === 'yes') return true;
            if (activeText === '0' || activeText === 'false' || activeText === 'no') return false;
        }

        var status = trim(String(p.status || '')).toLowerCase();
        if (status === '') return true;
        return status !== 'completed' && status !== 'cancelled' && status !== 'archived';
    }

    function isPhaseActive(phase) {
        var p = phase && typeof phase === 'object' ? phase : {};
        var status = trim(String(p.status || '')).toLowerCase();
        if (status === '') return true;
        return status !== 'completed' && status !== 'cancelled' && status !== 'archived';
    }

    function fetchClientHistory(id) {
        state.loadingHistory = true;
        return fetch(apiUrl(cfg.api && cfg.api.history, id), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                state.loadingHistory = false;
                if (result.status !== 200) {
                    state.historyItems = [];
                    return;
                }
                var rows = result.json && result.json.data && result.json.data.history;
                state.historyItems = Array.isArray(rows) ? rows : [];
            })
            .catch(function () {
                state.loadingHistory = false;
                state.historyItems = [];
            });
    }

    function fetchClientConsents(id) {
        state.loadingConsents = true;
        return fetch(apiUrl(cfg.api && cfg.api.consents, id), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                state.loadingConsents = false;
                if (result.status !== 200) {
                    state.consents = [];
                    return;
                }
                var rows = result.json && result.json.data && result.json.data.consents;
                state.consents = Array.isArray(rows) ? rows : [];
            })
            .catch(function () {
                state.loadingConsents = false;
                state.consents = [];
            });
    }

    function fetchClientPackages(id) {
        state.loadingPackages = true;
        return fetch(apiUrl(cfg.api && cfg.api.packages, id), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                state.loadingPackages = false;
                if (result.status !== 200) {
                    state.packages = [];
                    return;
                }
                var rows = result.json && result.json.data && result.json.data.packages;
                state.packages = Array.isArray(rows) ? rows : [];
            })
            .catch(function () {
                state.loadingPackages = false;
                state.packages = [];
            });
    }

    function fetchLatestTemplates() {
        if (state.templatesLoaded) return Promise.resolve();

        var params = new URLSearchParams({ active_only: '1', page: '1', per_page: '100' });
        return fetch('/admin/form-templates/data?' + params.toString(), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) {
                    state.templates = [];
                    state.templatesLoaded = true;
                    return;
                }

                var rows = result.json && result.json.data && result.json.data.data;
                rows = Array.isArray(rows) ? rows : [];

                return Promise.all(rows.map(function (template) {
                    var templateId = parsePositiveInt(template.id, 0);
                    if (templateId <= 0) return null;

                    return fetch('/admin/form-templates/data/' + templateId + '/versions', {
                        credentials: 'include',
                        headers: { Accept: 'application/json' },
                    })
                        .then(parseJsonResponse)
                        .then(function (versionsRes) {
                            if (versionsRes.status !== 200) return null;
                            var versions = versionsRes.json && versionsRes.json.data && versionsRes.json.data.versions;
                            versions = Array.isArray(versions) ? versions : [];
                            if (versions.length === 0) return null;

                            var latest = versions.reduce(function (acc, item) {
                                if (!acc) return item;
                                return Number(item.version_no || 0) > Number(acc.version_no || 0) ? item : acc;
                            }, null);
                            if (!latest || !latest.id) return null;

                            return fetch('/admin/form-templates/data/' + templateId + '/versions/' + latest.id, {
                                credentials: 'include',
                                headers: { Accept: 'application/json' },
                            })
                                .then(parseJsonResponse)
                                .then(function (versionRes) {
                                    if (versionRes.status !== 200) return null;
                                    var version = versionRes.json && versionRes.json.data ? versionRes.json.data.version : null;
                                    var schema = version && Array.isArray(version.schema_json) ? version.schema_json : [];

                                    return {
                                        id: templateId,
                                        name: String(template.name || ('Template #' + templateId)),
                                        currentVersionId: parsePositiveInt(latest.id, 0),
                                        currentVersionNo: parsePositiveInt(latest.version_no, 0),
                                        schemaJson: schema,
                                    };
                                });
                        })
                        .catch(function () { return null; });
                }));
            })
            .then(function (items) {
                if (!Array.isArray(items)) items = [];
                state.templates = items.filter(function (x) {
                    return x && x.id > 0 && x.currentVersionId > 0;
                });
                state.templatesLoaded = true;
                if (!state.selectedTemplateId && state.templates.length > 0) {
                    state.selectedTemplateId = state.templates[0].id;
                }
                resetRenderedFormState();
            })
            .catch(function () {
                state.templates = [];
                state.templatesLoaded = true;
            });
    }

    function saveClient() {
        if (!canManage || !state.selectedClientId) return;

        var payload = {
            name: getValue('clientName'),
            email: getValue('clientEmail'),
            phone: getValue('clientPhone'),
            address: getValue('clientAddress'),
        };

        fetch(apiUrl(cfg.api && cfg.api.update, state.selectedClientId), {
            method: 'PATCH',
            credentials: 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) {
                    throw new Error(result.json && result.json.message ? result.json.message : 'Fehler beim Speichern');
                }
                state.selectedClient = result.json && result.json.data ? result.json.data.client : state.selectedClient;
                notify('success', 'Clientdaten gespeichert.');
            })
            .catch(function (err) {
                notify('error', '{' + (err && err.message ? err.message : 'Fehler') + '}');
            });
    }

    function openCreateInvoiceModal() {
        if (!state.selectedClientId) return;

        var projects = Array.isArray(state.projects) ? state.projects : [];
        if (projects.length === 0) {
            notify('warning', 'Es sind keine Projekte fuer diesen Client vorhanden.');
            return;
        }

        var projectOptions = projects.map(function (project) {
            var projectId = parsePositiveInt(project && project.id, 0);
            return '<option value="' + projectId + '">' + escapeHtml(String(project && project.name ? project.name : ('Projekt #' + projectId))) + '</option>';
        }).join('');

        var today = new Date();
        var invoiceDateDefault = today.toISOString().slice(0, 10);
        var dueDateDefault = invoiceDateDefault;

        var body = '' +
            '<section class="admin-clients-attachments">' +
            '  <p class="admin-clients-help">Projekt und optional Vertrag waehlen, Positionen erfassen und Rabatt setzen.</p>' +
            '  <div class="admin-clients-form-grid">' +
            '    <div><label class="admin-clients-label" for="clientInvoiceProjectId">Projekt</label><select id="clientInvoiceProjectId" class="admin-clients-input">' + projectOptions + '</select></div>' +
            '    <div><label class="admin-clients-label" for="clientInvoiceContractId">Vertrag (optional)</label><select id="clientInvoiceContractId" class="admin-clients-input"></select></div>' +
            '    <div><label class="admin-clients-label" for="clientInvoiceDate">Rechnungsdatum</label><input id="clientInvoiceDate" class="admin-clients-input" type="date" value="' + invoiceDateDefault + '" /></div>' +
            '    <div><label class="admin-clients-label" for="clientInvoiceDueDate">Faelligkeitsdatum</label><input id="clientInvoiceDueDate" class="admin-clients-input" type="date" value="' + dueDateDefault + '" /></div>' +
            '    <div><label class="admin-clients-label" for="clientInvoiceDiscount">Rabatt (EUR)</label><input id="clientInvoiceDiscount" class="admin-clients-input" type="number" min="0" step="0.01" value="0" /></div>' +
            '  </div>' +
            '  <div style="margin-top:0.85rem;">' +
            '    <div class="admin-clients-inline-actions" style="margin-bottom:0.55rem;">' +
            '      <strong>Positionen</strong>' +
            '      <button type="button" class="admin-clients-page-btn" id="clientInvoiceAddItem">Position hinzufuegen</button>' +
            '    </div>' +
            '    <div id="clientInvoiceItems"></div>' +
            '  </div>' +
            '  <div class="admin-clients-meta" id="clientInvoiceTotals" style="margin-top:0.75rem;"></div>' +
            '</section>';

        window.adminOpenModal && window.adminOpenModal('Rechnung erstellen', body, {
            type: 'form',
            modalClass: 'admin-modal--preview',
            buttons: [
                { label: 'Erstellen', variant: 'primary', onClick: createClientInvoice },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } },
            ],
        });

        renderInvoiceItemRows([{ description: '', quantity: '1', unit_price: '' }]);
        refreshInvoiceContractOptions();
        updateInvoiceTotals();

        var projectSelect = document.getElementById('clientInvoiceProjectId');
        if (projectSelect) {
            projectSelect.addEventListener('change', function () {
                refreshInvoiceContractOptions();
            });
        }

        var addItemBtn = document.getElementById('clientInvoiceAddItem');
        if (addItemBtn) {
            addItemBtn.addEventListener('click', function () {
                var items = collectInvoiceItemsFromDom();
                items.push({ description: '', quantity: '1', unit_price: '' });
                renderInvoiceItemRows(items);
                updateInvoiceTotals();
            });
        }

        var itemsHost = document.getElementById('clientInvoiceItems');
        if (itemsHost) {
            itemsHost.addEventListener('click', function (event) {
                var target = event.target;
                var removeBtn = target && target.closest ? target.closest('[data-remove-item]') : null;
                if (!removeBtn) return;

                var index = parsePositiveInt(removeBtn.getAttribute('data-remove-item'), 0) - 1;
                var items = collectInvoiceItemsFromDom();
                if (index >= 0 && index < items.length) {
                    items.splice(index, 1);
                }
                if (items.length === 0) {
                    items.push({ description: '', quantity: '1', unit_price: '' });
                }
                renderInvoiceItemRows(items);
                updateInvoiceTotals();
            });

            itemsHost.addEventListener('input', function () {
                updateInvoiceTotals();
            });
        }

        var discountInput = document.getElementById('clientInvoiceDiscount');
        if (discountInput) {
            discountInput.addEventListener('input', function () {
                updateInvoiceTotals();
            });
        }
    }

    function refreshInvoiceContractOptions() {
        var projectId = parsePositiveInt(getValue('clientInvoiceProjectId'), 0);
        var contractSelect = document.getElementById('clientInvoiceContractId');
        if (!contractSelect) return;

        var project = findProjectById(projectId);
        var contracts = project && Array.isArray(project.contracts) ? project.contracts : [];

        var options = '<option value="">Kein Vertrag</option>';
        options += contracts.map(function (contract) {
            var start = contract && contract.start_date ? String(contract.start_date) : '-';
            var end = contract && contract.end_date ? String(contract.end_date) : '-';
            var contractId = parsePositiveInt(contract && contract.id, 0);
            return '<option value="' + contractId + '">#' + contractId + ' (' + escapeHtml(start) + ' bis ' + escapeHtml(end) + ')</option>';
        }).join('');

        contractSelect.innerHTML = options;
    }

    function renderInvoiceItemRows(items) {
        var host = document.getElementById('clientInvoiceItems');
        if (!host) return;

        var rows = (Array.isArray(items) ? items : []).map(function (item, idx) {
            var index = idx + 1;
            return '' +
                '<div class="admin-clients-inline" style="margin-bottom:0.5rem; align-items:flex-end;">' +
                '  <div style="flex:1; min-width:12rem;"><label class="admin-clients-label">Beschreibung</label><input class="admin-clients-input" data-item-field="description" data-item-index="' + index + '" type="text" value="' + escapeHtml(String(item && item.description ? item.description : '')) + '" /></div>' +
                '  <div style="width:7rem;"><label class="admin-clients-label">Menge</label><input class="admin-clients-input" data-item-field="quantity" data-item-index="' + index + '" type="number" min="0.01" step="0.01" value="' + escapeHtml(String(item && item.quantity ? item.quantity : '1')) + '" /></div>' +
                '  <div style="width:9rem;"><label class="admin-clients-label">Einzelpreis</label><input class="admin-clients-input" data-item-field="unit_price" data-item-index="' + index + '" type="number" step="0.01" value="' + escapeHtml(String(item && item.unit_price ? item.unit_price : '')) + '" /></div>' +
                '  <button type="button" class="admin-clients-page-btn" data-remove-item="' + index + '">Entfernen</button>' +
                '</div>';
        }).join('');

        host.innerHTML = rows;
    }

    function collectInvoiceItemsFromDom() {
        var host = document.getElementById('clientInvoiceItems');
        if (!host) return [];

        var rowMap = {};
        host.querySelectorAll('[data-item-field][data-item-index]').forEach(function (node) {
            var field = String(node.getAttribute('data-item-field') || '');
            var idx = String(node.getAttribute('data-item-index') || '');
            if (!rowMap[idx]) rowMap[idx] = { description: '', quantity: '', unit_price: '' };
            rowMap[idx][field] = String(node.value || '');
        });

        return Object.keys(rowMap).sort(function (a, b) { return parseInt(a, 10) - parseInt(b, 10); }).map(function (key) {
            return rowMap[key];
        });
    }

    function updateInvoiceTotals() {
        var items = collectInvoiceItemsFromDom();
        var subtotal = 0;
        items.forEach(function (item) {
            var qty = Number(item.quantity || 0);
            var price = Number(item.unit_price || 0);
            if (!Number.isFinite(qty) || !Number.isFinite(price) || qty <= 0) return;
            subtotal += qty * price;
        });

        var discount = Number(getValue('clientInvoiceDiscount') || 0);
        if (!Number.isFinite(discount) || discount < 0) discount = 0;
        var total = Math.max(0, subtotal - discount);

        var node = document.getElementById('clientInvoiceTotals');
        if (!node) return;

        node.textContent = 'Zwischensumme: ' + formatCurrency(subtotal, 'EUR') + ' | Rabatt: ' + formatCurrency(discount, 'EUR') + ' | Gesamt: ' + formatCurrency(total, 'EUR');
    }

    function createClientInvoice() {
        if (!state.selectedClientId) return;

        var projectId = parsePositiveInt(getValue('clientInvoiceProjectId'), 0);
        var contractId = parsePositiveInt(getValue('clientInvoiceContractId'), 0);
        var invoiceDate = getValue('clientInvoiceDate');
        var dueDate = getValue('clientInvoiceDueDate');
        var discountAmount = getValue('clientInvoiceDiscount');
        var items = collectInvoiceItemsFromDom().map(function (item) {
            return {
                description: trim(item.description),
                quantity: trim(item.quantity),
                unit_price: trim(item.unit_price),
            };
        }).filter(function (item) {
            return item.description !== '';
        });

        if (projectId <= 0) {
            notify('warning', 'Bitte ein Projekt auswaehlen.');
            return;
        }

        if (invoiceDate === '') {
            notify('warning', 'Bitte ein Rechnungsdatum setzen.');
            return;
        }

        if (items.length === 0) {
            notify('warning', 'Bitte mindestens eine Position erfassen.');
            return;
        }

        var payload = {
            project_id: projectId,
            contract_id: contractId > 0 ? contractId : null,
            invoice_date: invoiceDate,
            due_date: dueDate,
            discount_amount: discountAmount,
            items: items,
        };

        fetch(apiUrl(cfg.api && cfg.api.invoices_create, state.selectedClientId), {
            method: 'POST',
            credentials: 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 201 && result.status !== 200) {
                    throw new Error(extractErrorMessage(result, 'Rechnung konnte nicht erstellt werden.'));
                }

                notify('success', 'Rechnung wurde erstellt.');
                window.adminCloseModal && window.adminCloseModal();
                return fetchClientBookings(state.selectedClientId).then(function () {
                    render();
                });
            })
            .catch(function (err) {
                notify('error', err && err.message ? err.message : 'Rechnung konnte nicht erstellt werden.');
            });
    }

    function findProjectById(projectId) {
        var id = parsePositiveInt(projectId, 0);
        if (id <= 0) return null;
        var projects = Array.isArray(state.projects) ? state.projects : [];
        for (var i = 0; i < projects.length; i += 1) {
            var project = projects[i];
            if (parsePositiveInt(project && project.id, 0) === id) {
                return project;
            }
        }
        return null;
    }

    function createFormRecord() {
        if (!state.selectedClientId) return;

        var bookingId = parsePositiveInt(getValue('recordBookingId'), 0);
        var templateId = parsePositiveInt(getValue('recordTemplateId'), 0);
        var templateVersionId = parsePositiveInt(getValue('recordTemplateVersionId'), 0);

        if (bookingId <= 0 || templateId <= 0 || templateVersionId <= 0) {
            notify('error', '{booking_id und eine aktuelle Template-Version sind Pflichtfelder}');
            return;
        }

        var validation = validateTemplatePayload(currentTemplateSchema(), state.formDraft, true);
        state.formErrors = validation.errors;
        touchAllTemplateFields();
        render();

        if (Object.keys(validation.errors).length > 0) {
            notify('error', '{Bitte korrigiere die markierten Formularfelder.}');
            return;
        }

        var requestBody = {
            booking_id: bookingId,
            client_id: state.selectedClientId,
            template_id: templateId,
            template_version_id: templateVersionId,
            payload_json: validation.payload,
            status: 'draft',
        };

        fetch(apiUrl(cfg.api && cfg.api.session_records_create), {
            method: 'POST',
            credentials: 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(requestBody),
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 201 && result.status !== 200) {
                    throw new Error(result.json && result.json.message ? result.json.message : 'Fehler beim Speichern');
                }
                notify('success', 'Formulardaten wurden gespeichert.');
                resetRenderedFormState();
                state.detailTab = 'forms';
                writeRecordUrl(false);
                fetchClientRecords(state.selectedClientId).then(render);
            })
            .catch(function (err) {
                notify('error', '{' + (err && err.message ? err.message : 'Fehler') + '}');
            });
    }

    function createExport() {
        if (!state.selectedClientId) return;

        fetch(apiUrl(cfg.api && cfg.api.dsar_export_initiate, state.selectedClientId), {
            method: 'POST',
            credentials: 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: 'both' }),
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 202 && result.status !== 200) {
                    throw new Error(result.json && result.json.message ? result.json.message : 'Export konnte nicht erstellt werden');
                }
                var data = result.json && result.json.data ? result.json.data : {};
                var jobId = data.job_id ? String(data.job_id) : '';
                notify('success', 'Export gestartet' + (jobId ? ': Job-ID ' + jobId : '.'));
                var jobInput = document.getElementById('exportJobId');
                if (jobInput && jobId !== '') jobInput.value = jobId;
            })
            .catch(function (err) {
                notify('error', '{' + (err && err.message ? err.message : 'Fehler') + '}');
            });
    }

    function loadExportStatus() {
        var jobId = parsePositiveInt(getValue('exportJobId'), 0);
        if (jobId <= 0) {
            notify('warning', 'Bitte gueltige Job-ID eingeben.');
            return;
        }

        fetch(apiUrl(cfg.api && cfg.api.dsar_export_status, jobId), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) {
                    throw new Error(result.json && result.json.message ? result.json.message : 'Status konnte nicht geladen werden');
                }
                var data = result.json && result.json.data ? result.json.data : {};
                notify('info', 'Export-Status: ' + String(data.status || 'unbekannt'));
            })
            .catch(function (err) {
                notify('error', '{' + (err && err.message ? err.message : 'Fehler') + '}');
            });
    }

    function resetRenderedFormState() {
        state.formDraft = {};
        state.formErrors = {};
        state.formTouched = {};
    }

    function currentTemplateSchema() {
        var selectedTemplate = findTemplateById(state.selectedTemplateId);
        return selectedTemplate && Array.isArray(selectedTemplate.schemaJson) ? selectedTemplate.schemaJson : [];
    }

    function findFieldDefinition(fieldKey) {
        var fields = flattenSchemaFields(currentTemplateSchema());
        for (var i = 0; i < fields.length; i += 1) {
            if (fields[i].field_key === fieldKey) return fields[i];
        }
        return null;
    }

    function readFieldValue(fieldKey, fieldType) {
        var nodes = root.querySelectorAll('[data-form-field="' + cssEscape(fieldKey) + '"]');
        if (!nodes || nodes.length === 0) return '';

        if (fieldType === 'checkbox_multiple') {
            var values = [];
            nodes.forEach(function (node) {
                if (node.checked) values.push(String(node.value || ''));
            });
            return values;
        }

        if (fieldType === 'checkbox_single') {
            var checkedNode = null;
            nodes.forEach(function (node) {
                if (node.checked) checkedNode = node;
            });
            return checkedNode ? String(checkedNode.value || '') : '';
        }

        if (fieldType === 'radio') {
            var selected = '';
            nodes.forEach(function (node) {
                if (node.checked) selected = String(node.value || '');
            });
            return selected;
        }

        return trim(nodes[0].value);
    }

    function touchAllTemplateFields() {
        var fields = flattenSchemaFields(currentTemplateSchema());
        for (var i = 0; i < fields.length; i += 1) {
            state.formTouched[fields[i].field_key] = true;
        }
    }

    function validateTemplatePayload(schema, draft, requireRequired) {
        var fields = flattenSchemaFields(schema);
        var payload = {};
        var errors = {};

        for (var i = 0; i < fields.length; i += 1) {
            var field = fields[i];
            var fieldKey = field.field_key;
            var rawValue = draft[fieldKey];
            var result = normalizeFieldValue(field, rawValue);

            if (result.hasValue) payload[fieldKey] = result.value;
            if (result.error) errors[fieldKey] = result.error;
        }

        if (requireRequired) {
            for (var j = 0; j < fields.length; j += 1) {
                var req = fields[j];
                if (!req.required) continue;
                if (!Object.prototype.hasOwnProperty.call(payload, req.field_key) || isEmptyNormalizedValue(req.type, payload[req.field_key])) {
                    errors[req.field_key] = 'Pflichtfeld ist erforderlich.';
                }
            }
        }

        return { payload: payload, errors: errors };
    }

    function normalizeFieldValue(field, rawValue) {
        var type = String(field.type || 'text').toLowerCase();

        if (type === 'checkbox_multiple') {
            var multi = Array.isArray(rawValue) ? rawValue : [];
            var cleaned = multi.map(function (x) { return trim(x); }).filter(Boolean);
            if (cleaned.length === 0) return { hasValue: false, value: [], error: '' };
            var optCheck = validateOptions(cleaned, field.options || []);
            if (!optCheck.ok) return { hasValue: false, value: [], error: 'Enthaelt ungueltige Auswahlwerte.' };
            return { hasValue: true, value: unique(cleaned), error: '' };
        }

        if (type === 'checkbox_single') {
            var singleCheck = trim(rawValue || '');
            if (singleCheck === '') return { hasValue: false, value: '', error: '' };
            var singleOptions = normalizeOptions(field.options || []);
            if (singleOptions.length > 0 && singleOptions.indexOf(singleCheck) === -1) {
                return { hasValue: false, value: '', error: 'Ungueltige Auswahl.' };
            }
            return { hasValue: true, value: singleCheck, error: '' };
        }

        if (type === 'radio' || type === 'select') {
            var selected = trim(rawValue || '');
            if (selected === '') return { hasValue: false, value: '', error: '' };
            var options = normalizeOptions(field.options || []);
            if (options.length > 0 && options.indexOf(selected) === -1) {
                return { hasValue: false, value: '', error: 'Ungueltige Auswahl.' };
            }
            return { hasValue: true, value: selected, error: '' };
        }

        if (type === 'number') {
            var numberRaw = trim(rawValue || '');
            if (numberRaw === '') return { hasValue: false, value: null, error: '' };
            var normalizedRaw = numberRaw.replace(',', '.');
            if (!/^[-+]?\d+(\.\d+)?$/.test(normalizedRaw)) {
                return { hasValue: false, value: null, error: 'Muss numerisch sein.' };
            }
            var num = Number(normalizedRaw);
            if (!Number.isFinite(num)) return { hasValue: false, value: null, error: 'Muss numerisch sein.' };

            var min = toNullableFloat(field.min);
            var max = toNullableFloat(field.max);
            if (min !== null && num < min) return { hasValue: false, value: null, error: 'Muss >= ' + min + ' sein.' };
            if (max !== null && num > max) return { hasValue: false, value: null, error: 'Muss <= ' + max + ' sein.' };

            if (field.decimals !== undefined && field.decimals !== null && String(field.decimals) !== '') {
                var decimals = String(field.decimals).trim();
                var parts = normalizedRaw.split('.');
                var scale = parts.length > 1 ? parts[1].replace(/0+$/, '').length : 0;
                if (decimals === '0' && scale > 0) return { hasValue: false, value: null, error: 'Nur ganze Zahlen erlaubt.' };
                if (/^\d+$/.test(decimals) && scale > parseInt(decimals, 10)) {
                    return { hasValue: false, value: null, error: 'Zu viele Nachkommastellen (max ' + decimals + ').' };
                }
            }

            return { hasValue: true, value: num, error: '' };
        }

        if (type === 'date') {
            var dateRaw = trim(rawValue || '');
            if (dateRaw === '') return { hasValue: false, value: null, error: '' };

            if (/^\d{4}-\d{2}-\d{2}$/.test(dateRaw)) {
                var p = dateRaw.split('-');
                return { hasValue: true, value: p[2] + '.' + p[1] + '.' + p[0], error: '' };
            }

            if (/^\d{2}\.\d{2}\.\d{4}(\s\d{2}:\d{2})?$/.test(dateRaw)) {
                return { hasValue: true, value: dateRaw, error: '' };
            }

            return { hasValue: false, value: null, error: 'Datumsformat muss TT.MM.JJJJ sein.' };
        }

        var textValue = trim(rawValue || '');
        if (textValue === '') return { hasValue: false, value: '', error: '' };
        var maxLength = type === 'textarea' ? 10000 : 4000;
        if (textValue.length > maxLength) {
            return { hasValue: false, value: '', error: 'Maximal ' + maxLength + ' Zeichen erlaubt.' };
        }

        return { hasValue: true, value: textValue, error: '' };
    }

    function flattenSchemaFields(schema) {
        var result = [];
        if (!Array.isArray(schema)) return result;

        for (var i = 0; i < schema.length; i += 1) {
            var item = schema[i];
            if (!item || typeof item !== 'object') continue;
            var type = String(item.type || '').toLowerCase();

            if (type === 'letterhead') continue;
            if (type === 'section') {
                var nested = Array.isArray(item.items) ? flattenSchemaFields(item.items) : [];
                for (var n = 0; n < nested.length; n += 1) result.push(nested[n]);
                continue;
            }

            var fieldKey = trim(item.field_key || '');
            if (fieldKey === '' || type === '') continue;

            result.push({
                field_key: fieldKey,
                type: type,
                required: !!item.required,
                options: Array.isArray(item.options) ? item.options : [],
                min: item.min,
                max: item.max,
                decimals: item.decimals,
            });
        }

        return result;
    }

    function isEmptyNormalizedValue(type, value) {
        if (type === 'checkbox_multiple') return !Array.isArray(value) || value.length === 0;
        if (value === null || value === undefined) return true;
        if (typeof value === 'string') return trim(value) === '';
        return false;
    }

    function validateOptions(values, options) {
        var normOptions = normalizeOptions(options);
        if (normOptions.length === 0) return { ok: true };

        for (var i = 0; i < values.length; i += 1) {
            if (normOptions.indexOf(values[i]) === -1) return { ok: false };
        }
        return { ok: true };
    }

    function normalizeOptions(options) {
        return (Array.isArray(options) ? options : []).map(function (o) { return trim(String(o || '')); }).filter(Boolean);
    }

    function toNullableFloat(value) {
        if (value === null || value === undefined) return null;
        var text = trim(value);
        if (text === '') return null;
        var num = Number(text.replace(',', '.'));
        return Number.isFinite(num) ? num : null;
    }

    function unique(values) {
        var seen = Object.create(null);
        var out = [];
        for (var i = 0; i < values.length; i += 1) {
            if (!seen[values[i]]) {
                seen[values[i]] = true;
                out.push(values[i]);
            }
        }
        return out;
    }

    function toDateInputValue(value) {
        var text = trim(value || '');
        if (text === '') return '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text;
        var m = text.match(/^(\d{2})\.(\d{2})\.(\d{4})/);
        if (!m) return '';
        return m[3] + '-' + m[2] + '-' + m[1];
    }

    function openCreateClientModal() {
        if (!canManage) return;

        state.emailValidation = { value: '', status: 'idle', message: '' };

        var body = '' +
            '<div class="admin-clients-detail-grid">' +
            detailField('Name*', '<input id="createClientName" class="admin-clients-input" type="text" value="" />') +
            detailField('E-Mail*', '<input id="createClientEmail" class="admin-clients-input" type="email" value="" autocomplete="off" />' +
                '<div id="createClientEmailValidation" class="admin-clients-validation-msg"></div>') +
            detailField('Telefon', '<input id="createClientPhone" class="admin-clients-input" type="text" value="" />') +
            detailField('Adresse', '<textarea id="createClientAddress" class="admin-clients-textarea"></textarea>') +
            '</div>';

        window.adminOpenModal && window.adminOpenModal('Client anlegen', body, {
            type: 'form',
            buttons: [
                { label: 'Anlegen', variant: 'primary', onClick: createClientFromModal },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } },
            ],
        });

        bindEmailValidationInModal();
    }

    function bindEmailValidationInModal() {
        var emailInput = document.getElementById('createClientEmail');
        if (!emailInput) return;

        var timer = null;
        emailInput.addEventListener('input', function () {
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () {
                var email = trim(emailInput.value).toLowerCase();
                if (email === '') {
                    state.emailValidation = { value: '', status: 'idle', message: '' };
                    renderEmailValidation();
                    return;
                }
                if (!isValidEmail(email)) {
                    state.emailValidation = { value: email, status: 'invalid', message: 'Ungültige E-Mail-Adresse.' };
                    renderEmailValidation();
                    return;
                }
                validateEmail(email)
                    .then(function (available) {
                        state.emailValidation = {
                            value: email,
                            status: available ? 'valid' : 'invalid',
                            message: available ? 'E-Mail ist verfügbar.' : 'E-Mail ist bereits vergeben.',
                        };
                        renderEmailValidation();
                    })
                    .catch(function () {
                        state.emailValidation = { value: email, status: 'invalid', message: 'E-Mail-Prüfung fehlgeschlagen.' };
                        renderEmailValidation();
                    });
            }, 250);
        });
    }

    function renderEmailValidation() {
        var target = document.getElementById('createClientEmailValidation');
        if (!target) return;
        target.className = 'admin-clients-validation-msg admin-clients-validation-msg--' + state.emailValidation.status;
        target.textContent = state.emailValidation.message || '';
    }

    function validateEmail(email) {
        var url = apiUrl(cfg.api && cfg.api.validate_email) + '?email=' + encodeURIComponent(email);
        return fetch(url, { credentials: 'include', headers: { Accept: 'application/json' } })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200) return false;
                return !!(result.json && result.json.data && result.json.data.available);
            });
    }

    function createClientFromModal() {
        var payload = {
            name: getValue('createClientName'),
            email: trim(getValue('createClientEmail')).toLowerCase(),
            phone: getValue('createClientPhone'),
            address: getValue('createClientAddress'),
        };

        if (!payload.name || !payload.email) {
            notify('error', '{Pflichtfelder fehlen}');
            return;
        }

        fetch(apiUrl(cfg.api && cfg.api.create), {
            method: 'POST',
            credentials: 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then(parseJsonResponse)
            .then(function (result) {
                if (result.status !== 200 && result.status !== 201) {
                    throw new Error(result.json && result.json.message ? result.json.message : 'Fehler');
                }

                var created = result.json && result.json.data ? result.json.data.client : null;
                if (window.adminCloseModal) window.adminCloseModal();
                notify('success', 'Client wurde angelegt.');

                if (viewMode === 'list') fetchList();
                if (created && created.id) window.location.href = '/clients/' + created.id;
            })
            .catch(function (err) {
                notify('error', '{' + (err && err.message ? err.message : 'Fehler') + '}');
            });
    }

    function parseJsonResponse(res) {
        return res.json().catch(function () { return null; }).then(function (json) {
            return { status: res.status, json: json };
        });
    }

    function notify(type, message) {
        if (window.adminShowNotification) window.adminShowNotification(type, message);
    }

    function detailField(label, valueHtml) {
        return '' +
            '<div class="admin-clients-detail-row">' +
            '  <span class="admin-clients-detail-label">' + escapeHtml(label) + '</span>' +
            '  <div class="admin-clients-detail-value">' + valueHtml + '</div>' +
            '</div>';
    }

    function sortIndicator(column) {
        if (state.sort !== column) return '';
        return state.direction === 'asc' ? '↑' : '↓';
    }

    function apiUrl(template, idOrParams) {
        var url = String(template || '');
        if (!url) return '';

        if (idOrParams !== undefined && idOrParams !== null && typeof idOrParams === 'object') {
            var keys = Object.keys(idOrParams);
            for (var i = 0; i < keys.length; i += 1) {
                var key = keys[i];
                url = url.replace('{' + key + '}', String(idOrParams[key]));
            }
            return url;
        }

        if (idOrParams !== undefined && idOrParams !== null) {
            url = url.replace('{id}', String(idOrParams));
        }
        return url;
    }

    function extractErrorMessage(result, fallback) {
        var firstDetailed = extractFirstErrorCode(result && result.json ? result.json.errors : null);
        if (firstDetailed !== '') {
            if (result && result.json && typeof result.json.message === 'string' && trim(result.json.message) !== '') {
                return result.json.message + ' (' + firstDetailed + ')';
            }
            return firstDetailed;
        }

        if (result && result.json && typeof result.json.message === 'string' && trim(result.json.message) !== '') {
            return result.json.message;
        }
        return fallback;
    }

    function extractFirstErrorCode(errors) {
        if (!errors || typeof errors !== 'object') return '';
        var keys = Object.keys(errors);
        for (var i = 0; i < keys.length; i += 1) {
            var value = errors[keys[i]];
            if (Array.isArray(value) && value.length > 0) {
                var text = trim(String(value[0] || ''));
                if (text !== '') return text;
            }
            if (typeof value === 'string' && trim(value) !== '') {
                return trim(value);
            }
        }
        return '';
    }

    function formatBytes(value) {
        var bytes = Number(value);
        if (!Number.isFinite(bytes) || bytes <= 0) return '-';

        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function countInvoices() {
        var count = 0;
        for (var i = 0; i < state.invoiceBookings.length; i += 1) {
            if (state.invoiceBookings[i] && state.invoiceBookings[i].invoice) count += 1;
        }
        return count;
    }

    function countTestProtocols() {
        var total = 0;
        var keys = Object.keys(state.projectDetails || {});
        for (var i = 0; i < keys.length; i += 1) {
            var detail = state.projectDetails[keys[i]];
            if (!detail || !Array.isArray(detail.testProtocols)) continue;
            total += detail.testProtocols.length;
        }
        return total;
    }

    function findTemplateById(id) {
        if (!id) return null;
        for (var i = 0; i < state.templates.length; i += 1) {
            if (state.templates[i].id === id) return state.templates[i];
        }
        return null;
    }

    function inArray(value, arr) {
        for (var i = 0; i < arr.length; i += 1) if (arr[i] === value) return true;
        return false;
    }

    function formatDate(value) {
        if (!value) return '-';
        var parts = String(value).split('-');
        if (parts.length !== 3) return String(value);
        return parts[2] + '.' + parts[1] + '.' + parts[0];
    }

    function formatDateTime(value) {
        if (!value) return '-';
        var d = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return String(value);
        return d.toLocaleString('de-DE', {
            day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
        });
    }

    function formatCurrency(value, currencyCode) {
        var amount = Number(value);
        if (!Number.isFinite(amount)) return '-';
        try {
            return new Intl.NumberFormat('de-DE', {
                style: 'currency',
                currency: String(currencyCode || 'EUR'),
            }).format(amount);
        } catch (_err) {
            return amount.toFixed(2) + ' ' + String(currencyCode || 'EUR');
        }
    }

    function parsePositiveInt(value, fallback) {
        var n = parseInt(String(value || ''), 10);
        return Number.isFinite(n) && n > 0 ? n : fallback;
    }

    function trim(value) {
        return String(value || '').trim();
    }

    function getValue(id) {
        var el = document.getElementById(id);
        return el ? trim(el.value) : '';
    }

    function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trim(value));
    }

    function cssEscape(value) {
        return String(value).replace(/([ !"#$%&'()*+,./:;<=>?@[\\\]^`{|}~])/g, '\\$1');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function abbreviateHash(value) {
        var text = trim(value || '');
        if (text === '') return '-';
        if (text.length <= 20) return text;
        return text.slice(0, 10) + '...' + text.slice(-6);
    }
}());

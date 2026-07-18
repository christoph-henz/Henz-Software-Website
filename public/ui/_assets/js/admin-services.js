(function () {
    'use strict';

    var cfg = window.__ADMIN_SERVICES_CONFIG || {};
    var root = document.getElementById('adminServicesRoot');
    if (!root) {
        return;
    }

    var canManage = !!cfg.can_manage_services;

    var state = {
        services: [],
        referencedProjects: [],
        loading: true,
        initialServiceId: parsePositiveInt(cfg.initial_service_id, 0) || null,
    };

    fetchData();

    function fetchData() {
        state.loading = true;
        render();

        return Promise.allSettled([
            fetchJson(apiUrl(cfg.api && cfg.api.services && cfg.api.services.list)),
            fetchJson(apiUrl(cfg.api && cfg.api.referenced_projects && cfg.api.referenced_projects.list)),
        ]).then(function (results) {
            var serviceResult = results[0] && results[0].status === 'fulfilled' ? results[0].value : null;
            var referencedProjectsResult = results[1] && results[1].status === 'fulfilled' ? results[1].value : null;
            var serviceRequestOk = !!(serviceResult && serviceResult.status < 400);
            var referencedProjectsRequestOk = !!(referencedProjectsResult && referencedProjectsResult.status < 400);

            var servicesData = serviceResult && serviceResult.json && serviceResult.json.data ? serviceResult.json.data : {};
            var referencedProjectsData = referencedProjectsResult && referencedProjectsResult.json && referencedProjectsResult.json.data ? referencedProjectsResult.json.data : {};

            state.services = serviceRequestOk && Array.isArray(servicesData.services) ? servicesData.services : [];
            state.referencedProjects = referencedProjectsRequestOk && Array.isArray(referencedProjectsData.referenced_projects)
                ? referencedProjectsData.referenced_projects
                : [];
            state.loading = false;
            render();

            if (!serviceRequestOk) {
                notify('error', 'Services konnten nicht geladen werden.');
            }

            if (!referencedProjectsRequestOk) {
                notify('error', 'Referenzprojekte konnten nicht geladen werden.');
            }

            if (state.initialServiceId) {
                var found = state.services.find(function (item) { return parsePositiveInt(item.id, 0) === state.initialServiceId; });
                if (found) {
                    state.initialServiceId = null;
                    openServiceModal(found, false);
                }
            }
        }).catch(function () {
            state.services = [];
            state.referencedProjects = [];
            state.loading = false;
            render();
            notify('error', 'Leistungen konnten nicht geladen werden.');
        });
    }

    function render() {
        if (state.loading) {
            root.innerHTML = '<div class="admin-services-section"><div class="admin-services-empty">Lade Leistungen...</div></div>';
            return;
        }

        root.innerHTML = '' +
            renderServicesSection() +
            renderReferencedProjectsSection();

        bindEvents();
    }

    function renderServicesSection() {
        var rows = state.services.length === 0
            ? '<tr><td colspan="6" class="admin-services-empty">Keine Services vorhanden.</td></tr>'
            : state.services.map(function (item) {
                return '' +
                    '<tr class="admin-services-row" data-service-row data-id="' + item.id + '">' +
                    '  <td><div class="admin-services-name">' + escapeHtml(item.name || '') + '</div><div class="admin-services-meta">' + escapeHtml(item.slug || '') + '</div></td>' +
                    '  <td>' + escapeHtml(String(item.duration_minutes || 0)) + ' Min.</td>' +
                    '  <td>' + escapeHtml(formatMoney(item.price)) + '</td>' +
                    '  <td>' + badge(item.is_active, 'Aktiv') + '</td>' +
                    '  <td>' + escapeHtml(String(item.display_order || 0)) + '</td>' +
                    '  <td>' + escapeHtml(formatDate(item.updated_at || item.created_at || '')) + '</td>' +
                    '</tr>';
            }).join('');

        return '' +
            '<section class="admin-services-section">' +
            '  <div class="admin-services-section-head">' +
            '    <div>' +
            '      <h2 class="admin-services-section-title">Services</h2>' +
            '      <p class="admin-services-section-subtitle">Vorhandene Services bearbeiten oder neue Services anlegen.</p>' +
            '    </div>' +
            (canManage ? '<button type="button" class="admin-services-action-btn" data-service-create>Neuer Service</button>' : '') +
            '  </div>' +
            '  <div class="admin-services-table-wrap">' +
            '    <table class="admin-services-table">' +
            '      <thead><tr><th>Name</th><th>Dauer</th><th>Preis</th><th>Aktiv</th><th>Sortierung</th><th>Aktualisiert</th></tr></thead>' +
            '      <tbody>' + rows + '</tbody>' +
            '    </table>' +
            '  </div>' +
            '</section>';
    }

    function renderReferencedProjectsSection() {
        var rows = state.referencedProjects.length === 0
            ? '<tr><td colspan="7" class="admin-services-empty">Keine Referenzprojekte vorhanden.</td></tr>'
            : state.referencedProjects.map(function (item) {
                return '' +
                    '<tr class="admin-services-row" data-referenced-project-row data-id="' + item.id + '">' +
                    '  <td><div class="admin-services-name">' + escapeHtml(item.title || '') + '</div><div class="admin-services-meta">' + escapeHtml(item.slug || '') + '</div></td>' +
                    '  <td>' + escapeHtml(item.project_slug || '-') + '</td>' +
                    '  <td>' + escapeHtml(item.project_url || '-') + '</td>' +
                    '  <td>' + escapeHtml(item.project_image_path || '-') + '</td>' +
                    '  <td>' + badge(item.is_active, 'Aktiv') + '</td>' +
                    '  <td>' + escapeHtml(String(item.sort_order || 0)) + '</td>' +
                    '  <td>' + escapeHtml(formatDate(item.updated_at || item.created_at || '')) + '</td>' +
                    '</tr>';
            }).join('');

        return '' +
            '<section class="admin-services-section">' +
            '  <div class="admin-services-section-head">' +
            '    <div>' +
            '      <h2 class="admin-services-section-title">Referenzprojekte</h2>' +
            '      <p class="admin-services-section-subtitle">Referenzprojekte bearbeiten oder neue Referenzprojekte anlegen.</p>' +
            '    </div>' +
            (canManage ? '<button type="button" class="admin-services-action-btn" data-referenced-project-create>Neues Referenzprojekt</button>' : '') +
            '  </div>' +
            '  <div class="admin-services-table-wrap">' +
            '    <table class="admin-services-table">' +
            '      <thead><tr><th>Titel</th><th>Route-Slug</th><th>Projekt-URL</th><th>Media-Datei</th><th>Aktiv</th><th>Sortierung</th><th>Aktualisiert</th></tr></thead>' +
            '      <tbody>' + rows + '</tbody>' +
            '    </table>' +
            '  </div>' +
            '</section>';
    }

    function bindEvents() {
        root.querySelectorAll('[data-service-row]').forEach(function (row) {
            row.addEventListener('click', function () {
                openServiceModal(findById(state.services, row.getAttribute('data-id')), true);
            });
        });

        root.querySelectorAll('[data-referenced-project-row]').forEach(function (row) {
            row.addEventListener('click', function () {
                openReferencedProjectModal(findById(state.referencedProjects, row.getAttribute('data-id')), true);
            });
        });

        var serviceCreateBtn = root.querySelector('[data-service-create]');
        if (serviceCreateBtn) {
            serviceCreateBtn.addEventListener('click', function () {
                openServiceModal(null, true);
            });
        }

        var referencedProjectCreateBtn = root.querySelector('[data-referenced-project-create]');
        if (referencedProjectCreateBtn) {
            referencedProjectCreateBtn.addEventListener('click', function () {
                openReferencedProjectModal(null, true);
            });
        }
    }

    function openServiceModal(service, pushState) {
        var isEdit = !!service;
        var title = isEdit ? 'Service #' + service.id : 'Neuer Service';
        var body = '' +
            '<div class="admin-services-modal-note">Services werden direkt aus dem Admin heraus gepflegt.</div>' +
            '<div class="admin-services-form-grid">' +
            field('Name', '<input id="serviceName" class="admin-services-input" type="text" value="' + escapeHtml(service ? service.name || '' : '') + '" />') +
            field('Slug', '<input id="serviceSlug" class="admin-services-input" type="text" value="' + escapeHtml(service ? service.slug || '' : '') + '" placeholder="automatisch aus dem Namen" />') +
            field('Dauer in Minuten', '<input id="serviceDuration" class="admin-services-input" type="number" min="1" step="1" value="' + escapeHtml(String(service ? service.duration_minutes || 0 : 60)) + '" />') +
            field('Preis', '<input id="servicePrice" class="admin-services-input" type="number" min="0" step="0.01" value="' + escapeHtml(String(service ? service.price || 0 : 0)) + '" />') +
            field('Sortierung', '<input id="serviceDisplayOrder" class="admin-services-input" type="number" step="1" value="' + escapeHtml(String(service ? service.display_order || 0 : 0)) + '" />') +
            field('Beschreibung', '<textarea id="serviceDescription" class="admin-services-textarea">' + escapeHtml(service ? service.description || '' : '') + '</textarea>', true) +
            field('Status', checkbox('serviceActive', 'Aktiv', service ? !!service.is_active : true), true) +
            '</div>';

        window.adminOpenModal && window.adminOpenModal(title, body, {
            type: 'form',
            buttons: [
                { label: 'Speichern', variant: 'primary', onClick: function () { saveService(service && service.id ? service.id : null); } },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } },
            ],
        });

        if (pushState) {
            window.history.pushState(null, '', '/services');
        }
    }

    function openReferencedProjectModal(project, pushState) {
        var isEdit = !!project;

        var body = '' +
            '<div class="admin-services-modal-note">Referenzprojekte steuern die Referenzen im Frontend inklusive Route-Slug und optionaler externer URL.</div>' +
            '<div class="admin-services-form-grid">' +
            field('Titel', '<input id="referencedProjectTitle" class="admin-services-input" type="text" value="' + escapeHtml(project ? project.title || '' : '') + '" />') +
            field('Slug', '<input id="referencedProjectSlug" class="admin-services-input" type="text" value="' + escapeHtml(project ? project.slug || '' : '') + '" placeholder="automatisch aus dem Titel" />') +
            field('Route-Slug', '<input id="referencedProjectRouteSlug" class="admin-services-input" type="text" value="' + escapeHtml(project ? project.project_slug || '' : '') + '" placeholder="z. B. projekt-dionysos" />') +
            field('Projekt-URL', '<input id="referencedProjectUrl" class="admin-services-input" type="text" value="' + escapeHtml(project ? project.project_url || '' : '') + '" placeholder="z. B. https://example.com" />') +
            field('Media-Datei', '<input id="referencedProjectImagePath" class="admin-services-input" type="text" value="' + escapeHtml(project ? project.project_image_path || '' : '') + '" placeholder="z. B. Dionysos-Website-1.mp4" />') +
            field('Sortierung', '<input id="referencedProjectSortOrder" class="admin-services-input" type="number" step="1" value="' + escapeHtml(String(project ? project.sort_order || 0 : 0)) + '" />') +
            field('Beschreibung', '<textarea id="referencedProjectDescription" class="admin-services-textarea">' + escapeHtml(project ? project.description || '' : '') + '</textarea>', true) +
            field('Status', checkbox('referencedProjectActive', 'Aktiv', project ? !!project.is_active : true), true) +
            '</div>';

        window.adminOpenModal && window.adminOpenModal(isEdit ? 'Referenzprojekt #' + project.id : 'Neues Referenzprojekt', body, {
            type: 'form',
            buttons: [
                { label: 'Speichern', variant: 'primary', onClick: function () { saveReferencedProject(project && project.id ? project.id : null); } },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } },
            ],
        });

        if (pushState) {
            window.history.pushState(null, '', '/services');
        }
    }

    function saveService(id) {
        var payload = {
            name: getValue('serviceName'),
            slug: getValue('serviceSlug'),
            duration_minutes: getValue('serviceDuration'),
            price: getValue('servicePrice'),
            display_order: getValue('serviceDisplayOrder'),
            description: getValue('serviceDescription'),
            is_active: isChecked('serviceActive'),
        };

        var endpoint = id ? apiUrl(cfg.api && cfg.api.services && cfg.api.services.update, id) : apiUrl(cfg.api && cfg.api.services && cfg.api.services.create);
        fetchJson(endpoint, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (result) {
            if (result.status >= 400) {
                throw new Error(buildErrorMessage(result, 'Service konnte nicht gespeichert werden.'));
            }

            notify('success', 'Service wurde gespeichert.');
            window.adminCloseModal && window.adminCloseModal();
            fetchData();
        }).catch(function (err) {
            notify('error', err && err.message ? err.message : 'Service konnte nicht gespeichert werden.');
        });
    }

    function saveReferencedProject(id) {
        var payload = {
            title: getValue('referencedProjectTitle'),
            slug: getValue('referencedProjectSlug'),
            project_slug: getValue('referencedProjectRouteSlug'),
            project_url: getValue('referencedProjectUrl'),
            project_image_path: getValue('referencedProjectImagePath'),
            sort_order: getValue('referencedProjectSortOrder'),
            description: getValue('referencedProjectDescription'),
            is_active: isChecked('referencedProjectActive'),
        };

        var endpoint = id ? apiUrl(cfg.api && cfg.api.referenced_projects && cfg.api.referenced_projects.update, id) : apiUrl(cfg.api && cfg.api.referenced_projects && cfg.api.referenced_projects.create);
        fetchJson(endpoint, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (result) {
            if (result.status >= 400) {
                throw new Error(buildErrorMessage(result, 'Referenzprojekt konnte nicht gespeichert werden.'));
            }

            notify('success', 'Referenzprojekt wurde gespeichert.');
            window.adminCloseModal && window.adminCloseModal();
            fetchData();
        }).catch(function (err) {
            notify('error', err && err.message ? err.message : 'Referenzprojekt konnte nicht gespeichert werden.');
        });
    }

    function fetchJson(url, options) {
        options = options || {};
        options.credentials = 'include';
        options.headers = options.headers || {};
        if (!options.headers.Accept) {
            options.headers.Accept = 'application/json';
        }

        return fetch(url, options).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (json) {
                return { status: res.status, ok: res.ok, json: json };
            });
        });
    }

    function buildErrorMessage(result, fallback) {
        var json = result && result.json ? result.json : {};
        var errors = json && typeof json === 'object' ? json.errors : null;
        var parts = [];

        if (errors && typeof errors === 'object') {
            Object.keys(errors).forEach(function (key) {
                var values = errors[key];
                if (Array.isArray(values) && values.length > 0) {
                    parts.push(String(key) + ': ' + String(values[0]));
                }
            });
        }

        if (parts.length > 0) {
            return fallback + ' Grund: ' + parts.slice(0, 2).join('; ');
        }

        var message = trim(json && json.message ? json.message : '');
        if (message !== '') {
            return fallback + ' Grund: ' + message;
        }

        return fallback;
    }

    function notify(type, message) {
        if (window.adminShowNotification) {
            window.adminShowNotification(type, message);
        }
    }

    function field(label, controlHtml, fullWidth) {
        return '' +
            '<div class="admin-services-field' + (fullWidth ? ' admin-services-field--full' : '') + '">' +
            '  <label class="admin-services-label">' + escapeHtml(label) + '</label>' +
            '  <div>' + controlHtml + '</div>' +
            '</div>';
    }

    function checkbox(id, label, checked) {
        return '<label class="admin-services-check"><input id="' + id + '" type="checkbox"' + (checked ? ' checked' : '') + ' /> ' + escapeHtml(label) + '</label>';
    }

    function badge(value, label) {
        return '<span class="admin-services-badge ' + (value ? 'admin-services-badge--yes' : 'admin-services-badge--no') + '">' + escapeHtml(label) + '</span>';
    }

    function findById(list, id) {
        var numericId = parsePositiveInt(id, 0);
        return list.find(function (item) {
            return parsePositiveInt(item.id, 0) === numericId;
        }) || null;
    }

    function getValue(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '').trim() : '';
    }

    function isChecked(id) {
        var el = document.getElementById(id);
        return !!(el && el.checked);
    }

    function parsePositiveInt(value, fallback) {
        var n = parseInt(String(value || ''), 10);
        return Number.isFinite(n) && n > 0 ? n : fallback;
    }

    function formatMoney(value) {
        var amount = Number(value || 0);
        if (!Number.isFinite(amount)) {
            return '-';
        }

        return amount.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
    }

    function formatDate(value) {
        if (!value) {
            return '-';
        }

        var d = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) {
            return String(value);
        }

        return d.toLocaleDateString('de-DE', {
            day: '2-digit', month: '2-digit', year: 'numeric'
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function apiUrl(template, id) {
        var url = String(template || '');
        if (id !== undefined && id !== null) {
            url = url.replace('{id}', String(id));
        }
        return url;
    }

    function trim(value) {
        return String(value || '').trim();
    }
}());

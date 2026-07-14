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
        packages: [],
        loading: true,
        initialServiceId: parsePositiveInt(cfg.initial_service_id, 0) || null,
    };

    fetchData();

    function fetchData() {
        state.loading = true;
        render();

        return Promise.all([
            fetchJson(apiUrl(cfg.api && cfg.api.services && cfg.api.services.list)),
            fetchJson(apiUrl(cfg.api && cfg.api.packages && cfg.api.packages.list)),
        ]).then(function (results) {
            var servicesData = results[0] && results[0].json && results[0].json.data ? results[0].json.data : {};
            var packagesData = results[1] && results[1].json && results[1].json.data ? results[1].json.data : {};

            state.services = Array.isArray(servicesData.services) ? servicesData.services : [];
            state.packages = Array.isArray(packagesData.packages) ? packagesData.packages : [];
            state.loading = false;
            render();

            if (state.initialServiceId) {
                var found = state.services.find(function (item) { return parsePositiveInt(item.id, 0) === state.initialServiceId; });
                if (found) {
                    state.initialServiceId = null;
                    openServiceModal(found, false);
                }
            }
        }).catch(function () {
            state.services = [];
            state.packages = [];
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
            renderPackagesSection();

        bindEvents();
    }

    function renderServicesSection() {
        var rows = state.services.length === 0
            ? '<tr><td colspan="7" class="admin-services-empty">Keine Services vorhanden.</td></tr>'
            : state.services.map(function (item) {
                return '' +
                    '<tr class="admin-services-row" data-service-row data-id="' + item.id + '">' +
                    '  <td><div class="admin-services-name">' + escapeHtml(item.name || '') + '</div><div class="admin-services-meta">' + escapeHtml(item.slug || '') + '</div></td>' +
                    '  <td>' + escapeHtml(String(item.duration_minutes || 0)) + ' Min.</td>' +
                    '  <td>' + escapeHtml(formatMoney(item.price)) + '</td>' +
                    '  <td>' + badge(item.is_active, 'Aktiv') + '</td>' +
                    '  <td>' + badge(item.is_featured, 'Beliebt') + '</td>' +
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
            '      <thead><tr><th>Name</th><th>Dauer</th><th>Preis</th><th>Aktiv</th><th>Beliebt</th><th>Sortierung</th><th>Aktualisiert</th></tr></thead>' +
            '      <tbody>' + rows + '</tbody>' +
            '    </table>' +
            '  </div>' +
            '</section>';
    }

    function renderPackagesSection() {
        var rows = state.packages.length === 0
            ? '<tr><td colspan="7" class="admin-services-empty">Keine Pakete vorhanden.</td></tr>'
            : state.packages.map(function (item) {
                return '' +
                    '<tr class="admin-services-row" data-package-row data-id="' + item.id + '">' +
                    '  <td><div class="admin-services-name">' + escapeHtml(item.name || '') + '</div><div class="admin-services-meta">' + escapeHtml(item.slug || '') + '</div></td>' +
                    '  <td>' + escapeHtml(item.service_name || '-') + '<div class="admin-services-meta">' + escapeHtml(item.service_slug || '') + '</div></td>' +
                    '  <td>' + escapeHtml(String(item.session_count || 0)) + '</td>' +
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
            '      <h2 class="admin-services-section-title">Pakete</h2>' +
            '      <p class="admin-services-section-subtitle">Pakete bearbeiten oder neue Pakete anlegen. Pakete zu deaktivierten Services werden nicht angezeigt.</p>' +
            '    </div>' +
            (canManage ? '<button type="button" class="admin-services-action-btn" data-package-create>Neues Paket</button>' : '') +
            '  </div>' +
            '  <div class="admin-services-table-wrap">' +
            '    <table class="admin-services-table">' +
            '      <thead><tr><th>Name</th><th>Service</th><th>Sitzungen</th><th>Preis</th><th>Aktiv</th><th>Sortierung</th><th>Aktualisiert</th></tr></thead>' +
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

        root.querySelectorAll('[data-package-row]').forEach(function (row) {
            row.addEventListener('click', function () {
                openPackageModal(findById(state.packages, row.getAttribute('data-id')), true);
            });
        });

        var serviceCreateBtn = root.querySelector('[data-service-create]');
        if (serviceCreateBtn) {
            serviceCreateBtn.addEventListener('click', function () {
                openServiceModal(null, true);
            });
        }

        var packageCreateBtn = root.querySelector('[data-package-create]');
        if (packageCreateBtn) {
            packageCreateBtn.addEventListener('click', function () {
                openPackageModal(null, true);
            });
        }
    }

    function openServiceModal(service, pushState) {
        var isEdit = !!service;
        var title = isEdit ? 'Service #' + service.id : 'Neuer Service';
        var body = '' +
            '<div class="admin-services-modal-note">Services werden direkt aus dem Admin heraus gepflegt. Das Highlight-Badge wird über <code>is_featured</code> gesteuert.</div>' +
            '<div class="admin-services-form-grid">' +
            field('Name', '<input id="serviceName" class="admin-services-input" type="text" value="' + escapeHtml(service ? service.name || '' : '') + '" />') +
            field('Slug', '<input id="serviceSlug" class="admin-services-input" type="text" value="' + escapeHtml(service ? service.slug || '' : '') + '" placeholder="automatisch aus dem Namen" />') +
            field('Dauer in Minuten', '<input id="serviceDuration" class="admin-services-input" type="number" min="1" step="1" value="' + escapeHtml(String(service ? service.duration_minutes || 0 : 60)) + '" />') +
            field('Preis', '<input id="servicePrice" class="admin-services-input" type="number" min="0" step="0.01" value="' + escapeHtml(String(service ? service.price || 0 : 0)) + '" />') +
            field('Sortierung', '<input id="serviceDisplayOrder" class="admin-services-input" type="number" step="1" value="' + escapeHtml(String(service ? service.display_order || 0 : 0)) + '" />') +
            field('Beschreibung', '<textarea id="serviceDescription" class="admin-services-textarea">' + escapeHtml(service ? service.description || '' : '') + '</textarea>', true) +
            field('Status', checkbox('serviceActive', 'Aktiv', service ? !!service.is_active : true) + ' ' + checkbox('serviceFeatured', 'Beliebt', service ? !!service.is_featured : false), true) +
            '</div>';

        window.adminOpenModal && window.adminOpenModal(title, body, {
            type: 'form',
            buttons: [
                { label: 'Speichern', variant: 'primary', onClick: function () { saveService(service && service.id ? service.id : null); } },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } },
            ],
        });

        if (pushState) {
            window.history.pushState(null, '', '/admin/services');
        }
    }

    function openPackageModal(pkg, pushState) {
        var isEdit = !!pkg;
        var activeServices = state.services.filter(function (item) { return !!item.is_active; });
        var serviceOptions = activeServices.map(function (item) {
            var selected = pkg && parsePositiveInt(pkg.service_id, 0) === parsePositiveInt(item.id, 0) ? ' selected' : '';
            return '<option value="' + item.id + '"' + selected + '>' + escapeHtml(item.name || '') + '</option>';
        }).join('');

        if (pkg && serviceOptions === '' && pkg.service_id) {
            serviceOptions = '<option value="' + escapeHtml(String(pkg.service_id)) + '" selected>' + escapeHtml(pkg.service_name || 'Service #' + pkg.service_id) + '</option>';
        }

        var body = '' +
            '<div class="admin-services-modal-note">Pakete sind nur für aktive Services auswählbar. Pakete von deaktivierten Services werden nicht angezeigt.</div>' +
            '<div class="admin-services-form-grid">' +
            field('Name', '<input id="packageName" class="admin-services-input" type="text" value="' + escapeHtml(pkg ? pkg.name || '' : '') + '" />') +
            field('Slug', '<input id="packageSlug" class="admin-services-input" type="text" value="' + escapeHtml(pkg ? pkg.slug || '' : '') + '" placeholder="automatisch aus dem Namen" />') +
            field('Service', '<select id="packageServiceId" class="admin-services-select"><option value="">Bitte wählen</option>' + serviceOptions + '</select>') +
            field('Sitzungen', '<input id="packageSessions" class="admin-services-input" type="number" min="1" step="1" value="' + escapeHtml(String(pkg ? pkg.session_count || 0 : 3)) + '" />') +
            field('Preis', '<input id="packagePrice" class="admin-services-input" type="number" min="0" step="0.01" value="' + escapeHtml(String(pkg ? pkg.price || 0 : 0)) + '" />') +
            field('Sortierung', '<input id="packageDisplayOrder" class="admin-services-input" type="number" step="1" value="' + escapeHtml(String(pkg ? pkg.display_order || 0 : 0)) + '" />') +
            field('Beschreibung', '<textarea id="packageDescription" class="admin-services-textarea">' + escapeHtml(pkg ? pkg.description || '' : '') + '</textarea>', true) +
            field('Status', checkbox('packageActive', 'Aktiv', pkg ? !!pkg.is_active : true), true) +
            '</div>';

        window.adminOpenModal && window.adminOpenModal(isEdit ? 'Paket #' + pkg.id : 'Neues Paket', body, {
            type: 'form',
            buttons: [
                { label: 'Speichern', variant: 'primary', onClick: function () { savePackage(pkg && pkg.id ? pkg.id : null); } },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } },
            ],
        });

        if (pushState) {
            window.history.pushState(null, '', '/admin/services');
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
            is_featured: isChecked('serviceFeatured'),
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

    function savePackage(id) {
        var payload = {
            name: getValue('packageName'),
            slug: getValue('packageSlug'),
            service_id: getValue('packageServiceId'),
            session_count: getValue('packageSessions'),
            price: getValue('packagePrice'),
            display_order: getValue('packageDisplayOrder'),
            description: getValue('packageDescription'),
            is_active: isChecked('packageActive'),
        };

        var endpoint = id ? apiUrl(cfg.api && cfg.api.packages && cfg.api.packages.update, id) : apiUrl(cfg.api && cfg.api.packages && cfg.api.packages.create);
        fetchJson(endpoint, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (result) {
            if (result.status >= 400) {
                throw new Error(buildErrorMessage(result, 'Paket konnte nicht gespeichert werden.'));
            }

            notify('success', 'Paket wurde gespeichert.');
            window.adminCloseModal && window.adminCloseModal();
            fetchData();
        }).catch(function (err) {
            notify('error', err && err.message ? err.message : 'Paket konnte nicht gespeichert werden.');
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

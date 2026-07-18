(function () {
    'use strict';

    var cfg = window.__ADMIN_APPOINTMENTS_CONFIG || {};
    var root = document.getElementById('adminAppointmentsRoot');

    if (!root) {
        return;
    }

    var canView = !!cfg.can_view_appointments;
    var canManage = !!cfg.can_manage_appointments;

    var state = {
        isLoading: false,
        isDetailLoading: false,
        page: parsePositiveInt(cfg.default_page, 1),
        perPage: parsePositiveInt(cfg.per_page, 25),
        sort: String(cfg.default_sort || 'scheduled_at'),
        direction: String(cfg.default_direction || 'asc'),
        query: '',
        status: '',
        totalPages: 1,
        total: 0,
        items: [],
        selectedId: parsePositiveInt(cfg.initial_appointment_id, 0) || null,
        detail: null
    };

    hydrateFromQuery();
    render();
    fetchList();

    function parsePositiveInt(value, fallback) {
        var n = parseInt(String(value || ''), 10);
        return Number.isFinite(n) && n > 0 ? n : fallback;
    }

    function hydrateFromQuery() {
        var qs = new URLSearchParams(window.location.search);
        var idFromPath = parseInt((window.location.pathname.split('/').filter(Boolean).pop() || ''), 10);

        if (Number.isFinite(idFromPath) && idFromPath > 0) {
            state.selectedId = idFromPath;
        }

        var page = parsePositiveInt(qs.get('page'), 0);
        if (page > 0) {
            state.page = page;
        }

        var q = String(qs.get('q') || '').trim();
        if (q !== '') {
            state.query = q;
        }

        var status = String(qs.get('status') || '').trim();
        if (status !== '') {
            state.status = status;
        }
    }

    function writeStateToUrl() {
        var qs = new URLSearchParams();
        qs.set('page', String(state.page));
        if (state.query !== '') {
            qs.set('q', state.query);
        }
        if (state.status !== '') {
            qs.set('status', state.status);
        }

        var basePath = state.selectedId ? '/appointments/' + state.selectedId : '/appointments';
        var nextUrl = basePath + '?' + qs.toString();
        window.history.replaceState(null, '', nextUrl);
    }

    function apiUrl(template, id) {
        var url = String(template || '');
        if (id !== null && id !== undefined) {
            url = url.replace('{id}', String(id));
        }
        return url;
    }

    function fetchList() {
        if (!canView) {
            state.items = [];
            state.total = 0;
            state.totalPages = 1;
            state.detail = null;
            render();
            return;
        }

        state.isLoading = true;
        render();

        var params = new URLSearchParams({
            page: String(state.page),
            per_page: String(state.perPage),
            sort: state.sort,
            direction: state.direction
        });

        if (state.query !== '') {
            params.set('q', state.query);
        }
        if (state.status !== '') {
            params.set('status', state.status);
        }

        fetch(apiUrl(cfg.api && cfg.api.list) + '?' + params.toString(), {
            credentials: 'include',
            headers: { Accept: 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var data = json && json.data ? json.data : {};
                state.items = Array.isArray(data.appointments) ? data.appointments : [];
                state.total = parsePositiveInt(data.meta && data.meta.total, 0);
                state.totalPages = parsePositiveInt(data.meta && data.meta.total_pages, 1);

                if (state.page > state.totalPages) {
                    state.page = state.totalPages;
                }

                if (state.selectedId) {
                    fetchDetail(state.selectedId);
                } else {
                    state.detail = null;
                    render();
                }
            })
            .catch(function () {
                state.items = [];
                state.total = 0;
                state.totalPages = 1;
                state.detail = null;
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Appointments konnten nicht geladen werden.');
                }
            })
            .finally(function () {
                state.isLoading = false;
                writeStateToUrl();
                render();
            });
    }

    function fetchDetail(id) {
        if (!id || id <= 0) {
            state.detail = null;
            state.selectedId = null;
            writeStateToUrl();
            render();
            return;
        }

        state.isDetailLoading = true;
        render();

        fetch(apiUrl(cfg.api && cfg.api.detail, id), {
            credentials: 'include',
            headers: { Accept: 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var data = json && json.data ? json.data : {};
                state.detail = data && data.appointment ? data.appointment : null;
                state.selectedId = state.detail && state.detail.id ? Number(state.detail.id) : null;
            })
            .catch(function () {
                state.detail = null;
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Detailansicht konnte nicht geladen werden.');
                }
            })
            .finally(function () {
                state.isDetailLoading = false;
                writeStateToUrl();
                render();
            });
    }

    function updateStatus(id, nextStatus) {
        if (!canManage || !id || !nextStatus) {
            return;
        }

        fetch(apiUrl(cfg.api && cfg.api.update, id), {
            method: 'PATCH',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ status: nextStatus })
        })
            .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
            .then(function (result) {
                if (!result.ok) {
                    throw new Error('status_update_failed');
                }

                if (window.adminShowNotification) {
                    var label = nextStatus === 'accepted' ? 'angenommen' : 'abgelehnt';
                    window.adminShowNotification('success', 'Appointment wurde ' + label + '.');
                }

                fetchList();
            })
            .catch(function () {
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Status konnte nicht aktualisiert werden.');
                }
            });
    }

    function statusLabel(status) {
        var labels = cfg.status_labels || {};
        var key = String(status || 'pending');
        return labels[key] || key;
    }

    function statusClass(status) {
        var s = String(status || 'pending');
        return 'admin-appointments-status admin-appointments-status--' + s;
    }

    function formatDateTime(value) {
        var raw = String(value || '').trim();
        if (raw === '') return '-';
        var d = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return raw;
        return d.toLocaleString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
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

    function render() {
        var listHtml = renderList();
        var detailHtml = renderDetail();

        root.innerHTML = '' +
            '<div class="admin-appointments-grid">' +
            '  <section class="admin-appointments-list-card">' +
            '    <div class="admin-appointments-toolbar">' +
            '      <form data-appointments-search class="admin-appointments-search">' +
            '        <input name="q" type="search" class="admin-appointments-input" placeholder="Suche nach Client oder Service" value="' + escapeHtml(state.query) + '" />' +
            '        <select name="status" class="admin-appointments-select">' +
            '          <option value="">Alle Stati</option>' +
            '          <option value="pending"' + (state.status === 'pending' ? ' selected' : '') + '>Ausstehend</option>' +
            '          <option value="accepted"' + (state.status === 'accepted' ? ' selected' : '') + '>Angenommen</option>' +
            '          <option value="declined"' + (state.status === 'declined' ? ' selected' : '') + '>Abgelehnt</option>' +
            '          <option value="completed"' + (state.status === 'completed' ? ' selected' : '') + '>Abgeschlossen</option>' +
            '          <option value="storno"' + (state.status === 'storno' ? ' selected' : '') + '>Storno</option>' +
            '        </select>' +
            '        <button type="submit" class="admin-appointments-btn">Filtern</button>' +
            '      </form>' +
            '      <div class="admin-appointments-meta">' + (state.isLoading ? 'Lade...' : ('Gesamt: ' + state.total)) + '</div>' +
            '    </div>' +
            listHtml +
            '    <div class="admin-appointments-pagination">' +
            '      <button type="button" class="admin-appointments-btn" data-page-prev ' + (state.page <= 1 ? 'disabled' : '') + '>Zurück</button>' +
            '      <span>Seite ' + state.page + ' / ' + state.totalPages + '</span>' +
            '      <button type="button" class="admin-appointments-btn" data-page-next ' + (state.page >= state.totalPages ? 'disabled' : '') + '>Weiter</button>' +
            '    </div>' +
            '  </section>' +
            '  <aside class="admin-appointments-detail-card">' + detailHtml + '</aside>' +
            '</div>';

        bindEvents();
    }

    function renderList() {
        if (!state.items.length) {
            return '<div class="admin-appointments-empty">Keine Appointments gefunden.</div>';
        }

        var rows = state.items.map(function (item) {
            var selected = Number(item.id) === Number(state.selectedId);
            var clientName = item.client && item.client.name ? item.client.name : '-';
            var serviceName = item.service && item.service.name ? item.service.name : '-';

            return '' +
                '<button type="button" class="admin-appointments-row' + (selected ? ' is-selected' : '') + '" data-id="' + item.id + '">' +
                '  <span class="admin-appointments-row-main">' +
                '    <strong>' + escapeHtml(clientName) + '</strong>' +
                '    <small>' + escapeHtml(serviceName) + '</small>' +
                '  </span>' +
                '  <span class="admin-appointments-row-side">' +
                '    <span class="' + statusClass(item.status) + '">' + escapeHtml(statusLabel(item.status)) + '</span>' +
                '    <small>' + escapeHtml(formatDateTime(item.scheduled_at)) + '</small>' +
                '  </span>' +
                '</button>';
        }).join('');

        return '<div class="admin-appointments-list">' + rows + '</div>';
    }

    function renderDetail() {
        if (state.isDetailLoading) {
            return '<h3>Details</h3><p class="admin-appointments-hint">Lade Details...</p>';
        }

        if (!state.detail) {
            return '<h3>Details</h3><p class="admin-appointments-hint">Wähle links ein Appointment aus.</p>';
        }

        var item = state.detail;
        var clientName = item.client && item.client.name ? item.client.name : '-';
        var email = item.client && item.client.email ? item.client.email : '-';
        var serviceName = item.service && item.service.name ? item.service.name : '-';
        var notes = item.notes ? String(item.notes) : '-';
        var canAct = canManage && String(item.status || '') === 'pending';

        return '' +
            '<h3>Appointment #' + item.id + '</h3>' +
            '<dl class="admin-appointments-detail-list">' +
            '  <dt>Status</dt><dd><span class="' + statusClass(item.status) + '">' + escapeHtml(statusLabel(item.status)) + '</span></dd>' +
            '  <dt>Termin</dt><dd>' + escapeHtml(formatDateTime(item.scheduled_at)) + '</dd>' +
            '  <dt>Client</dt><dd>' + escapeHtml(clientName) + '</dd>' +
            '  <dt>E-Mail</dt><dd>' + escapeHtml(email) + '</dd>' +
            '  <dt>Service</dt><dd>' + escapeHtml(serviceName) + '</dd>' +
            '  <dt>Notizen</dt><dd>' + escapeHtml(notes) + '</dd>' +
            '</dl>' +
            (canAct
                ? '<div class="admin-appointments-actions">' +
                    '<button type="button" class="admin-appointments-btn admin-appointments-btn--accept" data-status="accepted">Annehmen</button>' +
                    '<button type="button" class="admin-appointments-btn admin-appointments-btn--decline" data-status="declined">Ablehnen</button>' +
                  '</div>'
                : '');
    }

    function bindEvents() {
        var form = root.querySelector('[data-appointments-search]');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(form);
                state.query = String(fd.get('q') || '').trim();
                state.status = String(fd.get('status') || '').trim();
                state.page = 1;
                fetchList();
            });
        }

        root.querySelectorAll('.admin-appointments-row[data-id]').forEach(function (row) {
            row.addEventListener('click', function () {
                var id = parseInt(String(row.getAttribute('data-id') || '0'), 10);
                if (id > 0) {
                    state.selectedId = id;
                    fetchDetail(id);
                }
            });
        });

        var prev = root.querySelector('[data-page-prev]');
        if (prev) {
            prev.addEventListener('click', function () {
                if (state.page <= 1) return;
                state.page -= 1;
                fetchList();
            });
        }

        var next = root.querySelector('[data-page-next]');
        if (next) {
            next.addEventListener('click', function () {
                if (state.page >= state.totalPages) return;
                state.page += 1;
                fetchList();
            });
        }

        root.querySelectorAll('[data-status]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var nextStatus = String(btn.getAttribute('data-status') || '');
                if (state.detail && state.detail.id) {
                    updateStatus(Number(state.detail.id), nextStatus);
                }
            });
        });
    }
})();

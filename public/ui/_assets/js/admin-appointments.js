(function () {
    'use strict';

    var cfg = window.__ADMIN_REQUESTS_CONFIG || {};
    var root = document.getElementById('adminRequestsRoot');
    if (!root) {
        return;
    }

    var canView = !!cfg.can_view_requests;
    var canManage = !!cfg.can_manage_requests;
    var services = cfg.services || {};

    var state = {
        sort: cfg.default_sort || 'created_at',
        direction: cfg.default_direction || 'desc',
        page: parsePositiveInt(cfg.default_page, 1),
        perPage: 20,
        search: '',
        showHidden: false,
        total: 0,
        totalPages: 1,
        items: [],
        selectedId: parsePositiveInt(cfg.initial_request_id, 0) || null,
        detailItem: null,
        isLoading: false,
        alertSummary: {
            openRequests: 0
        }
    };

    function clearDetailState() {
        if (state.selectedId === null) {
            return;
        }

        state.selectedId = null;
        state.detailItem = null;
        writeStateToUrl(false);
    }

    var modalBackdrop = document.getElementById('adminModalBackdrop');
    var modalCloseBtn = document.getElementById('adminModalCloseBtn');
    if (modalCloseBtn) {
        modalCloseBtn.addEventListener('click', function () {
            clearDetailState();
        });
    }
    if (modalBackdrop) {
        modalBackdrop.addEventListener('click', function (e) {
            if (e.target === modalBackdrop) {
                clearDetailState();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            clearDetailState();
        }
    });

    hydrateFromUrl();

    function hydrateFromUrl() {
        var qs = new URLSearchParams(window.location.search);

        var sort = trim(qs.get('sort'));
        var direction = trim(qs.get('direction'));
        var page = trim(qs.get('page'));
        var perPage = trim(qs.get('per_page'));
        var search = trim(qs.get('q'));
        var showHidden = trim(qs.get('show_hidden'));

        if (sort !== '') state.sort = sort;
        if (direction === 'asc' || direction === 'desc') state.direction = direction;
        if (/^\d+$/.test(page)) state.page = Math.max(1, parseInt(page, 10));

        if (/^\d+$/.test(perPage)) {
            state.perPage = Math.max(1, parseInt(perPage, 10));
        } else {
            state.perPage = resolveDefaultPerPage();
        }

        state.search = search;
        state.showHidden = (showHidden === '1' || showHidden === 'true');

        var path = window.location.pathname || '/admin/requests';
        var detailMatch = path.match(/^\/admin\/requests\/(\d+)$/);
        if (detailMatch) {
            state.selectedId = parseInt(detailMatch[1], 10);
        }
    }

    function resolveDefaultPerPage() {
        var isMobile = window.matchMedia('(max-width: 1023px)').matches;
        var mobileDefault = parsePositiveInt(cfg.default_per_page_mobile, 10);
        var desktopDefault = parsePositiveInt(cfg.default_per_page_desktop, 20);
        return isMobile ? mobileDefault : desktopDefault;
    }

    function trim(value) {
        return String(value || '').trim();
    }

    function parsePositiveInt(value, fallback) {
        var n = parseInt(String(value || ''), 10);
        return Number.isFinite(n) && n > 0 ? n : fallback;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function mapServiceName(item) {
        var slug = trim(item && item.service_slug);
        var name = trim(item && item.service && item.service.name);
        if (name !== '' && name !== slug) {
            return name;
        }
        if (slug !== '' && services[slug]) {
            return String(services[slug]);
        }
        return slug !== '' ? slug : 'Unbekannt';
    }

    function formatDateTime(value) {
        var date = value ? new Date(String(value).replace(' ', 'T')) : null;
        if (!date || Number.isNaN(date.getTime())) {
            return '-';
        }

        return date.toLocaleString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function toDatetimeLocalValue(value) {
        var date = value ? new Date(String(value).replace(' ', 'T')) : new Date();
        if (Number.isNaN(date.getTime())) {
            date = new Date();
        }

        date.setSeconds(0, 0);
        return date.getFullYear() +
            '-' + String(date.getMonth() + 1).padStart(2, '0') +
            '-' + String(date.getDate()).padStart(2, '0') +
            'T' + String(date.getHours()).padStart(2, '0') +
            ':' + String(date.getMinutes()).padStart(2, '0');
    }

    function validateConflictDateTime(localValue) {
        if (!localValue) {
            return 'Bitte wähle ein Datum und eine Uhrzeit.';
        }

        var parts = localValue.split('T');
        if (parts.length !== 2) {
            return 'Ungueltiges Datumsformat.';
        }

        var hm = parts[1].split(':');
        if (hm.length !== 2) {
            return 'Ungueltige Uhrzeit.';
        }

        var hour = parseInt(hm[0], 10);
        var minute = parseInt(hm[1], 10);
        if (!Number.isFinite(hour) || !Number.isFinite(minute)) {
            return 'Ungueltige Uhrzeit.';
        }

        if (hour < 8 || hour > 18 || (hour === 18 && minute !== 0)) {
            return 'Bitte wähle einen Termin zwischen 08:00 und 18:00 Uhr.';
        }

        if (minute !== 0 && minute !== 30) {
            return 'Bitte nutze 30-Minuten-Slots (:00 oder :30).';
        }

        return '';
    }

    function rowStatusClass(status) {
        if (status === 'accepted') return 'admin-requests-row--accepted';
        if (status === 'rejected') return 'admin-requests-row--rejected';
        return 'admin-requests-row--new';
    }

    function updateAlertBadges() {
        var openCount = Number(state.alertSummary && state.alertSummary.openRequests ? state.alertSummary.openRequests : 0);

        if (window.adminSetBadge) {
            window.adminSetBadge('adminRequestsRibbonBadge', {
                text: '!',
                kind: 'warning',
                visible: openCount > 0,
                title: openCount > 0 ? (String(openCount) + ' offene Anfrage' + (openCount === 1 ? '' : 'n')) : '',
                ariaLabel: openCount > 0 ? (String(openCount) + ' offene Anfrage' + (openCount === 1 ? '' : 'n')) : ''
            });
        }

        if (window.adminSetMenuBadge) {
            window.adminSetMenuBadge({
                text: '!',
                kind: 'warning',
                visible: openCount > 0,
                title: openCount > 0 ? (String(openCount) + ' offene Anfrage' + (openCount === 1 ? '' : 'n')) : '',
                ariaLabel: openCount > 0 ? (String(openCount) + ' offene Anfrage' + (openCount === 1 ? '' : 'n')) : ''
            });
        }
    }

    function writeStateToUrl(push) {
        var qs = new URLSearchParams();
        qs.set('sort', state.sort);
        qs.set('direction', state.direction);
        qs.set('page', String(state.page));
        qs.set('per_page', String(state.perPage));
        if (state.search !== '') {
            qs.set('q', state.search);
        }
        if (state.showHidden) {
            qs.set('show_hidden', '1');
        }

        var path = state.selectedId ? '/admin/requests/' + state.selectedId : '/admin/requests';
        var nextUrl = path + '?' + qs.toString();

        if (push) {
            window.history.pushState(null, '', nextUrl);
        } else {
            window.history.replaceState(null, '', nextUrl);
        }
    }

    function apiUrl(template, id) {
        var url = String(template || '');
        if (id !== undefined && id !== null) {
            url = url.replace('{id}', String(id));
        }
        return url;
    }

    function fetchList() {
        if (!canView) {
            state.items = [];
            state.total = 0;
            state.totalPages = 1;
            render();
            updateAlertBadges();
            return Promise.resolve();
        }

        state.isLoading = true;
        render();

        var params = new URLSearchParams({
            page: String(state.page),
            per_page: String(state.perPage),
            sort: state.sort,
            direction: state.direction
        });
        if (state.search !== '') {
            params.set('q', state.search);
        }
        if (state.showHidden) {
            params.set('show_hidden', '1');
        }

        return fetch(apiUrl(cfg.api && cfg.api.list) + '?' + params.toString(), {
            credentials: 'include',
            headers: { Accept: 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var data = (json && json.data) || {};
                state.items = Array.isArray(data.requests) ? data.requests : [];
                state.total = parsePositiveInt(data.meta && data.meta.total, 0);
                state.totalPages = parsePositiveInt(data.meta && data.meta.total_pages, 1);
                state.page = Math.max(1, Math.min(state.page, state.totalPages));
            })
            .catch(function () {
                state.items = [];
                state.total = 0;
                state.totalPages = 1;
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Anfragen konnten nicht geladen werden.');
                }
            })
            .finally(function () {
                state.isLoading = false;
                render();
                updateAlertBadges();
                if (state.selectedId) {
                    openDetailModal(state.selectedId, false);
                }
            });
    }

    function fetchAlertSummary() {
        if (!canView) {
            state.alertSummary.openRequests = 0;
            updateAlertBadges();
            return Promise.resolve();
        }

        var params = new URLSearchParams({
            page: '1',
            per_page: '999',
            sort: 'created_at',
            direction: 'desc'
        });

        return fetch(apiUrl(cfg.api && cfg.api.list) + '?' + params.toString(), {
            credentials: 'include',
            headers: { Accept: 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var data = (json && json.data) || {};
                var requests = Array.isArray(data.requests) ? data.requests : [];
                state.alertSummary.openRequests = requests.filter(function (item) {
                    return String(item && item.status || 'new') === 'new';
                }).length;
                updateAlertBadges();
            })
            .catch(function () {
                updateAlertBadges();
            });
    }

    function render() {
        var searchValue = escapeHtml(state.search);
        var showHiddenChecked = state.showHidden ? ' checked' : '';
        var sortIndicator = function (col) {
            if (state.sort !== col) return '';
            return state.direction === 'asc' ? '↑' : '↓';
        };

        var rowsHtml = '';
        if (state.items.length === 0) {
            rowsHtml = '<tr><td colspan="5" class="admin-requests-empty">Keine Anfragen gefunden.</td></tr>';
        } else {
            rowsHtml = state.items.map(function (item) {
                var status = String(item.status || 'new');
                var canAct = canManage && status === 'new';
                var serviceName = mapServiceName(item);

                var actions = canAct
                    ? '<div class="admin-requests-actions">' +
                        '<button type="button" class="admin-requests-action-btn admin-requests-action-btn--accept" data-action="accept" data-id="' + item.id + '">Annehmen</button>' +
                        '<button type="button" class="admin-requests-action-btn admin-requests-action-btn--reject" data-action="reject" data-id="' + item.id + '">Ablehnen</button>' +
                      '</div>'
                    : '<span class="admin-requests-meta">-</span>';

                return '' +
                    '<tr class="admin-requests-row ' + rowStatusClass(status) + '" data-row-id="' + item.id + '">' +
                    '  <td><div class="admin-requests-client-name">' + escapeHtml(item.client && item.client.name ? item.client.name : '-') + '</div>' +
                    '      <div class="admin-requests-created">Eingang: ' + escapeHtml(formatDateTime(item.created_at)) + '</div></td>' +
                    '  <td><div class="admin-requests-client-email">' + escapeHtml(item.client && item.client.email ? item.client.email : '-') + '</div></td>' +
                    '  <td><div class="admin-requests-service">' + escapeHtml(serviceName) + '</div><div class="admin-requests-meta">' + escapeHtml(item.service_slug || '') + '</div></td>' +
                    '  <td><div class="admin-requests-desired">' + escapeHtml(formatDateTime(item.desired_at)) + '</div></td>' +
                    '  <td>' + actions + '</td>' +
                    '</tr>';
            }).join('');
        }

        root.innerHTML = '' +
            '<div class="admin-requests-toolbar">' +
            '  <form class="admin-requests-search" data-search-form>' +
            '    <input class="admin-requests-search-input" name="q" type="search" placeholder="Suche nach Client, Service oder E-Mail" value="' + searchValue + '" />' +
            '    <button type="submit" class="admin-requests-page-btn">Suchen</button>' +
            '  </form>' +
            '  <label class="admin-requests-meta">' +
            '    <input type="checkbox" data-show-hidden' + showHiddenChecked + ' /> Ausgeblendete anzeigen' +
            '  </label>' +
            '  <div class="admin-requests-meta">' + (state.isLoading ? 'Lade...' : ('Gesamt: ' + state.total)) + '</div>' +
            '</div>' +
            '<div class="admin-requests-table-wrap">' +
            '  <table class="admin-requests-table">' +
            '    <thead>' +
            '      <tr>' +
            '        <th><button type="button" class="admin-requests-sort" data-sort="client">Client <span class="admin-requests-sort-indicator">' + sortIndicator('client') + '</span></button></th>' +
            '        <th><button type="button" class="admin-requests-sort" data-sort="email">E-Mail <span class="admin-requests-sort-indicator">' + sortIndicator('email') + '</span></button></th>' +
            '        <th><button type="button" class="admin-requests-sort" data-sort="service_name">Service <span class="admin-requests-sort-indicator">' + sortIndicator('service_name') + '</span></button></th>' +
            '        <th><button type="button" class="admin-requests-sort" data-sort="desired_at">Gewünschter Termin <span class="admin-requests-sort-indicator">' + sortIndicator('desired_at') + '</span></button></th>' +
            '        <th><button type="button" class="admin-requests-sort" data-sort="created_at">Eingang <span class="admin-requests-sort-indicator">' + sortIndicator('created_at') + '</span></button></th>' +
            '      </tr>' +
            '    </thead>' +
            '    <tbody>' + rowsHtml + '</tbody>' +
            '  </table>' +
            '</div>' +
            '<div class="admin-requests-pagination">' +
            '  <div class="admin-requests-pagination-info">Seite ' + state.page + ' / ' + state.totalPages + '</div>' +
            '  <div class="admin-requests-pagination-controls">' +
            '    <button type="button" class="admin-requests-page-btn" data-page-prev ' + (state.page <= 1 ? 'disabled' : '') + '>Zurück</button>' +
            '    <button type="button" class="admin-requests-page-btn" data-page-next ' + (state.page >= state.totalPages ? 'disabled' : '') + '>Weiter</button>' +
            '  </div>' +
            '</div>';

        bindEvents();
    }

    function bindEvents() {
        var searchForm = root.querySelector('[data-search-form]');
        if (searchForm) {
            searchForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(searchForm);
                state.search = trim(fd.get('q'));
                state.page = 1;
                writeStateToUrl(false);
                fetchList();
                fetchAlertSummary();
            });
        }

        var showHiddenToggle = root.querySelector('[data-show-hidden]');
        if (showHiddenToggle) {
            showHiddenToggle.addEventListener('change', function () {
                state.showHidden = !!showHiddenToggle.checked;
                state.page = 1;
                writeStateToUrl(false);
                fetchList();
                fetchAlertSummary();
            });
        }

        root.querySelectorAll('[data-sort]').forEach(function (el) {
            el.addEventListener('click', function () {
                var nextSort = String(el.getAttribute('data-sort') || 'created_at');
                if (state.sort === nextSort) {
                    state.direction = state.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sort = nextSort;
                    state.direction = 'asc';
                    if (nextSort === 'created_at') {
                        state.direction = 'desc';
                    }
                }
                state.page = 1;
                writeStateToUrl(false);
                fetchList();
                fetchAlertSummary();
            });
        });

        var prev = root.querySelector('[data-page-prev]');
        var next = root.querySelector('[data-page-next]');
        if (prev) {
            prev.addEventListener('click', function () {
                if (state.page <= 1) return;
                state.page -= 1;
                writeStateToUrl(false);
                fetchList();
                fetchAlertSummary();
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                if (state.page >= state.totalPages) return;
                state.page += 1;
                writeStateToUrl(false);
                fetchList();
                fetchAlertSummary();
            });
        }

        root.querySelectorAll('.admin-requests-row[data-row-id]').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target && e.target.closest('[data-action]')) {
                    return;
                }
                var id = parseInt(String(row.getAttribute('data-row-id') || '0'), 10);
                if (id > 0) {
                    openDetailModal(id, true);
                }
            });
        });

        root.querySelectorAll('[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var id = parseInt(String(btn.getAttribute('data-id') || '0'), 10);
                var action = String(btn.getAttribute('data-action') || '');
                if (id <= 0 || action === '') return;
                if (action === 'accept') {
                    confirmAccept(id);
                }
                if (action === 'reject') {
                    confirmReject(id);
                }
            });
        });
    }

    function requestById(id) {
        return state.items.find(function (item) { return Number(item.id) === Number(id); }) || null;
    }

    function openDetailModal(id, pushState) {
        var detailUrl = apiUrl(cfg.api && cfg.api.detail, id);
        if (state.showHidden) {
            detailUrl += (detailUrl.indexOf('?') === -1 ? '?' : '&') + 'show_hidden=1';
        }
        fetch(detailUrl, {
            credentials: 'include',
            headers: { Accept: 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var item = json && json.data && json.data.request;
                if (!item) {
                    throw new Error('not_found');
                }

                state.selectedId = id;
                state.detailItem = item;
                writeStateToUrl(pushState);
                showDetailModal(item);
            })
            .catch(function () {
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Request-Detail konnte nicht geladen werden.');
                }
            });
    }

    function showDetailModal(item) {
        var body = '' +
            '<div class="admin-requests-detail-grid">' +
            detailRow('Client', item.client && item.client.name ? item.client.name : '-') +
            detailRow('E-Mail', item.client && item.client.email ? item.client.email : '-') +
            detailRow('Telefon', item.client && item.client.phone ? item.client.phone : '-') +
            detailRow('Service', mapServiceName(item) + ' (' + (item.service_slug || '-') + ')') +
            detailRow('Gewünschter Termin', formatDateTime(item.desired_at)) +
            detailRow('Eingang', formatDateTime(item.created_at)) +
            detailRow('Status', String(item.status || '-')) +
            detailRow('Nachricht', item.message || '-') +
            '</div>';

        var buttons = [
            {
                label: 'Schließen',
                variant: 'secondary',
                onClick: function () {
                    clearDetailState();
                    window.adminCloseModal && window.adminCloseModal();
                }
            }
        ];

        if (canManage && String(item.status) === 'new') {
            buttons.unshift(
                { label: 'Ablehnen', variant: 'danger', onClick: function () { confirmReject(item.id); } },
                { label: 'Annehmen', variant: 'primary', onClick: function () { confirmAccept(item.id); } }
            );
        }

        window.adminOpenModal && window.adminOpenModal('Request #' + item.id, body, {
            type: 'info',
            buttons: buttons
        });
    }

    function detailRow(label, value) {
        return '' +
            '<div class="admin-requests-detail-row">' +
            '  <span class="admin-requests-detail-label">' + escapeHtml(label) + '</span>' +
            '  <span class="admin-requests-detail-value">' + escapeHtml(value) + '</span>' +
            '</div>';
    }

    function patchRequest(id, payload) {
        return fetch(apiUrl(cfg.api && cfg.api.update, id), {
            method: 'PATCH',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().then(function (json) {
                return { status: res.status, json: json };
            });
        });
    }

    function confirmAccept(id) {
        patchRequest(id, { status: 'accepted' })
            .then(function (result) {
                if (result.status === 200) {
                    if (window.adminShowNotification) {
                        window.adminShowNotification('success', 'Request wurde angenommen.');
                    }
                    fetchList();
                    if (state.selectedId === id) {
                        openDetailModal(id, false);
                    }
                    return;
                }

                if (result.status === 409) {
                    openConflictModal(id, result.json);
                    return;
                }

                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Request konnte nicht angenommen werden.');
                }
            })
            .catch(function () {
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Request konnte nicht angenommen werden.');
                }
            });
    }

    function openConflictModal(id, responseJson) {
        var requestPayload = responseJson && responseJson.errors && responseJson.errors.request ? responseJson.errors.request : {};
        var item = requestById(id) || state.detailItem || {};
        var desiredAt = String((requestPayload && requestPayload.desired_at) || item.desired_at || '');
        var initialValue = toDatetimeLocalValue(desiredAt);

        var body = '' +
            '<p class="admin-requests-conflict-hint">Der gewuenschte Termin ist belegt. Bitte stelle einen neuen Termin zwischen 08:00 und 18:00 Uhr in 30-Minuten-Schritten ein.</p>' +
            '<input id="adminRequestConflictDateTime" class="admin-requests-slot-select" type="datetime-local" step="1800" value="' + escapeHtml(initialValue) + '" />';

        window.adminOpenModal && window.adminOpenModal('Slot-Konflikt', body, {
            type: 'form',
            buttons: [
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } },
                {
                    label: 'Termin setzen & annehmen',
                    variant: 'primary',
                    onClick: function () {
                        var input = document.getElementById('adminRequestConflictDateTime');
                        var nextSlotLocal = input ? String(input.value || '') : '';
                        var validationError = validateConflictDateTime(nextSlotLocal);
                        if (validationError !== '') {
                            if (window.adminShowNotification) {
                                window.adminShowNotification('warning', validationError);
                            }
                            return;
                        }

                        patchRequest(id, { status: 'accepted', desired_at: nextSlotLocal })
                            .then(function (result) {
                                if (result.status === 200) {
                                    window.adminCloseModal && window.adminCloseModal();
                                    if (window.adminShowNotification) {
                                        window.adminShowNotification('success', 'Request wurde auf den gewählten Termin gesetzt und angenommen.');
                                    }
                                    fetchList();
                                    if (state.selectedId === id) {
                                        openDetailModal(id, false);
                                    }
                                    return;
                                }
                                if (result.status === 409) {
                                    if (window.adminShowNotification) {
                                        window.adminShowNotification('warning', 'Dieser Termin ist ebenfalls belegt. Bitte wähle einen anderen Slot.');
                                    }
                                    return;
                                }
                                if (window.adminShowNotification) {
                                    window.adminShowNotification('error', 'Der Request konnte nicht angenommen werden.');
                                }
                            })
                            .catch(function () {
                                if (window.adminShowNotification) {
                                    window.adminShowNotification('error', 'Der Request konnte nicht angenommen werden.');
                                }
                            });
                    }
                }
            ]
        });
    }

    function confirmReject(id) {
        patchRequest(id, { status: 'rejected' })
            .then(function (result) {
                if (result.status === 200) {
                    if (window.adminShowNotification) {
                        window.adminShowNotification('success', 'Request wurde abgelehnt.');
                    }
                    fetchList();
                    if (state.selectedId === id) {
                        openDetailModal(id, false);
                    }
                    return;
                }
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Request konnte nicht abgelehnt werden.');
                }
            })
            .catch(function () {
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Request konnte nicht abgelehnt werden.');
                }
            });
    }

    window.addEventListener('popstate', function () {
        hydrateFromUrl();
        fetchList();
        fetchAlertSummary();
    });

    writeStateToUrl(false);
    fetchList();
    fetchAlertSummary();
}());

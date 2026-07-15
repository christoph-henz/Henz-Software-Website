(function () {
    'use strict';

    var cfg = window.__ADMIN_BOOKINGS_CONFIG || {};
    var root = document.getElementById('adminBookingsRoot');
    var canView = !!cfg.can_view_bookings;
    var canManage = !!cfg.can_manage_bookings;
    var canRevert = !!cfg.can_revert_bookings;

    var state = {
        sort: cfg.default_sort || 'scheduled_at',
        direction: cfg.default_direction || 'asc',
        page: parsePositiveInt(cfg.default_page, 1),
        perPage: resolveDefaultPerPage(),
        status: '',
        search: '',
        total: 0,
        totalPages: 1,
        items: [],
        selectedId: parsePositiveInt(cfg.initial_booking_id, 0) || null,
        detailItem: null,
        isLoading: false,
        meta: null,
        metaPromise: null,
        alertSummary: {
            outstandingCount: 0,
            outstandingTotal: 0
        }
    };

    hydrateFromUrl();
    attachGlobalHandlers();

    if (root) {
        fetchList();
        fetchAlertSummary();
    }

    window.adminBookingsOpenCreateModal = openCreateModal;
    window.adminBookingsRefresh = function () {
        if (!root) {
            return Promise.resolve();
        }

        fetchAlertSummary();
        return fetchList();
    };

    function attachGlobalHandlers() {
        var modalBackdrop = document.getElementById('adminModalBackdrop');
        var modalCloseBtn = document.getElementById('adminModalCloseBtn');

        if (modalCloseBtn) {
            modalCloseBtn.addEventListener('click', clearDetailState);
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

        document.addEventListener('click', function (e) {
            var createBtn = e.target && e.target.closest('[data-open-create-booking]');
            if (createBtn) {
                e.preventDefault();
                openCreateModal();
            }
        });
    }

    function hydrateFromUrl() {
        var qs = new URLSearchParams(window.location.search);
        var sort = trim(qs.get('sort'));
        var direction = trim(qs.get('direction'));
        var page = trim(qs.get('page'));
        var perPage = trim(qs.get('per_page'));
        var status = trim(qs.get('status'));
        var search = trim(qs.get('q'));

        if (sort !== '') {
            state.sort = sort;
        }

        if (direction === 'asc' || direction === 'desc') {
            state.direction = direction;
        }

        if (/^\d+$/.test(page)) {
            state.page = Math.max(1, parseInt(page, 10));
        }

        if (/^\d+$/.test(perPage)) {
            state.perPage = Math.max(1, parseInt(perPage, 10));
        }

        if (status !== '') {
            state.status = status;
        }

        state.search = search;

        var path = window.location.pathname || '/admin/bookings';
        var detailMatch = path.match(/^\/admin\/bookings\/(\d+)$/);
        if (detailMatch) {
            state.selectedId = parseInt(detailMatch[1], 10);
        }
    }

    function resolveDefaultPerPage() {
        var mobile = window.matchMedia('(max-width: 1023px)').matches;
        var mobileDefault = parsePositiveInt(cfg.default_per_page_mobile, 10);
        var desktopDefault = parsePositiveInt(cfg.default_per_page_desktop, 20);
        return mobile ? mobileDefault : desktopDefault;
    }

    function clearDetailState() {
        if (state.selectedId === null) {
            return;
        }

        state.selectedId = null;
        state.detailItem = null;
        writeStateToUrl(false);
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

    function apiUrl(template, id) {
        var url = String(template || '');
        if (id !== undefined && id !== null) {
            url = url.replace('{id}', String(id));
        }
        return url;
    }

    function fetchJson(url, options) {
        options = options || {};
        options.credentials = 'include';
        options.headers = options.headers || {};
        if (!options.headers.Accept) {
            options.headers.Accept = 'application/json';
        }

        return fetch(url, options).then(function (res) {
            return res.json().catch(function () {
                return {};
            }).then(function (json) {
                return {
                    status: res.status,
                    ok: res.ok,
                    json: json
                };
            });
        });
    }

    function buildApiErrorToastMessage(result, fallback) {
        var json = result && result.json ? result.json : {};
        var details = [];
        var errors = json && typeof json === 'object' ? json.errors : null;

        if (errors && typeof errors === 'object') {
            Object.keys(errors).forEach(function (key) {
                var values = errors[key];
                if (Array.isArray(values) && values.length > 0) {
                    var translated = translateApiErrorCode(values[0]);
                    if (String(key) === 'slot') {
                        details.push(translated);
                    } else {
                        details.push(String(key) + ': ' + translated);
                    }
                }
            });
        }

        if (details.length > 0) {
            return fallback + ' Grund: ' + details.slice(0, 2).join('; ');
        }

        var message = trim(json && json.message ? json.message : '');
        if (message !== '') {
            return fallback + ' Grund: ' + message;
        }

        return fallback;
    }

    function translateApiErrorCode(value) {
        var code = String(value || '').trim();
        var map = {
            occupied_or_blocked: 'Zeitslot ist bereits belegt oder blockiert.',
        };

        return map[code] || code;
    }

    function bookingStatusLabel(status) {
        var labels = cfg.status_labels || {};
        var key = String(status || 'pending');
        return labels[key] || key;
    }

    function paymentStatusLabel(status) {
        var labels = cfg.payment_status_labels || {};
        var key = String(status || 'pending');
        return labels[key] || key;
    }

    function bookingRowClass(status) {
        var key = String(status || 'pending');
        if (key === 'paid') return 'admin-bookings-row--paid';
        if (key === 'confirmed') return 'admin-bookings-row--confirmed';
        if (key === 'completed') return 'admin-bookings-row--completed';
        if (key === 'cancelled') return 'admin-bookings-row--cancelled';
        if (key === 'no_show') return 'admin-bookings-row--no-show';
        return 'admin-bookings-row--pending';
    }

    function sortIndicator(col) {
        if (state.sort !== col) {
            return '';
        }

        return state.direction === 'asc' ? '↑' : '↓';
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

    function formatDateOnly(value) {
        var date = value ? new Date(String(value).replace(' ', 'T')) : null;
        if (!date || Number.isNaN(date.getTime())) {
            return '-';
        }

        return date.toLocaleDateString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    }

    function formatDateYmd(date) {
        return date.getFullYear() +
            '-' + String(date.getMonth() + 1).padStart(2, '0') +
            '-' + String(date.getDate()).padStart(2, '0');
    }

    function formatMoney(value) {
        var amount = Number(value || 0);
        if (!Number.isFinite(amount)) {
            return '-';
        }

        return amount.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
    }

    function bookingOpenAmount(item) {
        if (!item) {
            return 0;
        }

        var bookingStatus = String(item.status || '').toLowerCase();
        if (bookingStatus === 'confirmed') {
            return 0;
        }

        var cancellationTiming = String(item.cancellation_timing || '').toLowerCase();
        if (bookingStatus === 'cancelled' && cancellationTiming === 'early') {
            return 0;
        }

        var invoice = item.invoice && typeof item.invoice === 'object' ? item.invoice : null;
        if (invoice) {
            var invoiceStatus = String(invoice.status || '').toLowerCase();
            var invoiceTotal = Number(invoice.total_amount || 0);
            if (invoiceTotal > 0 && invoiceStatus !== 'paid' && invoiceStatus !== 'retracted' && invoiceStatus !== 'cancelled') {
                return invoiceTotal;
            }
        }

        var paymentStatus = String(item.payment_status || 'pending').toLowerCase();
        if (paymentStatus === 'paid' || paymentStatus === 'refunded') {
            return 0;
        }

        if (item.is_package_booking && item.package_purchase && Number(item.package_purchase.id || 0) > 0) {
            return Number(item.package_purchase.price || 0) || 0;
        }

        return Number(item.service && item.service.price ? item.service.price : 0) || 0;
    }

    function updateAlertBadges() {
        var total = Number(state.alertSummary && state.alertSummary.outstandingTotal ? state.alertSummary.outstandingTotal : 0);
        var count = Number(state.alertSummary && state.alertSummary.outstandingCount ? state.alertSummary.outstandingCount : 0);
        var kind = total > 500 ? 'danger' : 'warning';
        var text = count > 0 ? '!' : '';
        var title = count > 0 ? (String(count) + ' offene Zahlung' + (count === 1 ? '' : 'en') + ' · ' + formatMoney(total)) : '';

        if (window.adminSetBadge) {
            window.adminSetBadge('adminBookingsRibbonBadge', {
                text: text,
                kind: kind,
                visible: count > 0,
                className: 'admin-badge--blink',
                title: title,
                ariaLabel: title
            });

            window.adminSetBadge('adminSidebarBookingsBadge', {
                text: text,
                kind: kind,
                visible: count > 0,
                className: 'admin-sidebar-item-badge admin-sidebar-item-badge--booking',
                title: title,
                ariaLabel: title
            });
        }
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

    function nextDateYmd(value) {
        var date = new Date(String(value || '') + 'T00:00:00');
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        date.setDate(date.getDate() + 1);
        return date.getFullYear() +
            '-' + String(date.getMonth() + 1).padStart(2, '0') +
            '-' + String(date.getDate()).padStart(2, '0');
    }

    function buildSlotCandidatesForDay(dateYmd) {
        var windowCfg = state.meta && state.meta.window ? state.meta.window : {};
        var startHour = parsePositiveInt(windowCfg.start_hour, 8);
        var endHour = parsePositiveInt(windowCfg.end_hour, 18);
        var step = parsePositiveInt(windowCfg.slot_step_minutes, 30);

        if (startHour < 0 || startHour > 23) startHour = 8;
        if (endHour < 1 || endHour > 24) endHour = 18;
        if (endHour <= startHour) endHour = Math.min(24, startHour + 8);
        if (step < 5) step = 30;

        var selectedDayStart = new Date(String(dateYmd || '') + 'T00:00:00');
        if (Number.isNaN(selectedDayStart.getTime())) {
            return [];
        }

        var now = new Date();
        var minTimestamp = now.getTime();
        var sameDay =
            now.getFullYear() === selectedDayStart.getFullYear()
            && now.getMonth() === selectedDayStart.getMonth()
            && now.getDate() === selectedDayStart.getDate();

        var out = [];
        for (var minute = startHour * 60; minute < endHour * 60; minute += step) {
            var hh = String(Math.floor(minute / 60)).padStart(2, '0');
            var mm = String(minute % 60).padStart(2, '0');
            var time = hh + ':' + mm;

            if (sameDay) {
                var candidateTs = new Date(String(dateYmd) + 'T' + time + ':00').getTime();
                if (Number.isNaN(candidateTs) || candidateTs < minTimestamp) {
                    continue;
                }
            }

            out.push(time);
        }

        return out;
    }

    function businessTimeError(localValue) {
        if (!localValue) {
            return 'Bitte wähle Datum und Uhrzeit.';
        }

        var date = new Date(String(localValue).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return 'Ungueltiges Datum oder Uhrzeit.';
        }

        var hour = date.getHours();
        var minute = date.getMinutes();

        if (hour < 8 || hour > 18 || (hour === 18 && minute !== 0)) {
            return 'Bitte wähle einen Termin zwischen 08:00 und 18:00 Uhr.';
        }

        if (minute !== 0 && minute !== 30) {
            return 'Bitte nutze 30-Minuten-Slots (:00 oder :30).';
        }

        return '';
    }

    function serviceName(item) {
        var service = item && item.service ? item.service : {};
        var name = trim(service.name);
        if (name !== '') {
            return name;
        }

        return trim(service.slug) || 'Unbekannt';
    }

    function isFreeService(item) {
        var price = Number(item && item.service && item.service.price ? item.service.price : 0);
        return Number.isFinite(price) && price <= 0;
    }

    function packageDisplayName(item) {
        var packagePurchase = item && item.package_purchase && typeof item.package_purchase === 'object' ? item.package_purchase : null;
        if (!packagePurchase) {
            return 'Paket';
        }

        var name = trim(packagePurchase.name);
        if (name !== '') {
            return name;
        }

        var slug = trim(packagePurchase.slug);
        if (slug !== '') {
            return slug.replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim();
        }

        return 'Paket';
    }

    function clientName(item) {
        var client = item && item.client ? item.client : {};
        var name = trim(client.name);
        if (name !== '') {
            return name;
        }

        var firstName = trim(client.first_name);
        var lastName = trim(client.last_name);
        return trim(firstName + ' ' + lastName) || '-';
    }

    function buildQueryString() {
        var qs = new URLSearchParams();
        qs.set('sort', state.sort);
        qs.set('direction', state.direction);
        qs.set('page', String(state.page));
        qs.set('per_page', String(state.perPage));
        if (state.status !== '') {
            qs.set('status', state.status);
        }
        if (state.search !== '') {
            qs.set('q', state.search);
        }
        return qs;
    }

    function writeStateToUrl(push) {
        var path = state.selectedId ? '/admin/bookings/' + state.selectedId : '/admin/bookings';
        var nextUrl = path + '?' + buildQueryString().toString();
        if (push) {
            window.history.pushState(null, '', nextUrl);
        } else {
            window.history.replaceState(null, '', nextUrl);
        }
    }

    function fetchList() {
        if (!canView || !root) {
            renderEmpty();
            updateAlertBadges();
            return Promise.resolve();
        }

        state.isLoading = true;
        render();

        var params = buildQueryString();

        return fetchJson(apiUrl(cfg.api && cfg.api.list) + '?' + params.toString())
            .then(function (result) {
                var data = result.json && result.json.data ? result.json.data : {};
                state.items = Array.isArray(data.bookings) ? data.bookings : [];
                state.total = parsePositiveInt(data.meta && data.meta.total, 0);
                state.totalPages = parsePositiveInt(data.meta && data.meta.total_pages, 1);
                state.page = Math.max(1, Math.min(state.page, state.totalPages));
            })
            .catch(function () {
                state.items = [];
                state.total = 0;
                state.totalPages = 1;
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Buchungen konnten nicht geladen werden.');
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
        if (!canView || !root) {
            state.alertSummary.outstandingCount = 0;
            state.alertSummary.outstandingTotal = 0;
            updateAlertBadges();
            return Promise.resolve();
        }

        var summaryUrl = cfg.api && cfg.api.summary ? apiUrl(cfg.api.summary) : '/admin/bookings/summary';

        return fetchJson(summaryUrl)
            .then(function (result) {
                var data = result.json && result.json.data ? result.json.data : {};
                var summary = data.summary && typeof data.summary === 'object' ? data.summary : null;

                if (summary) {
                    state.alertSummary.outstandingCount = Number(summary.outstanding_count || 0);
                    state.alertSummary.outstandingTotal = Number(summary.outstanding_total || 0);
                } else {
                    throw new Error('summary_missing');
                }

                updateAlertBadges();
            })
            .catch(function () {
                var params = new URLSearchParams({
                    page: '1',
                    per_page: '999',
                    sort: 'scheduled_at',
                    direction: 'asc'
                });

                return fetchJson(apiUrl(cfg.api && cfg.api.list) + '?' + params.toString())
                    .then(function (result) {
                        var data = result.json && result.json.data ? result.json.data : {};
                        var items = Array.isArray(data.bookings) ? data.bookings : [];
                        var outstandingCount = 0;
                        var outstandingTotal = 0;

                        items.forEach(function (item) {
                            var amount = bookingOpenAmount(item);
                            if (amount <= 0) {
                                return;
                            }

                            outstandingCount += 1;
                            outstandingTotal += amount;
                        });

                        state.alertSummary.outstandingCount = outstandingCount;
                        state.alertSummary.outstandingTotal = outstandingTotal;
                        updateAlertBadges();
                    })
                    .catch(function () {
                        updateAlertBadges();
                    });
            });
    }

    function renderEmpty() {
        if (!root) {
            return;
        }

        root.innerHTML = '<div class="admin-bookings-empty"></div>';
    }

    function render() {
        if (!root) {
            return;
        }

        if (!canView) {
            renderEmpty();
            return;
        }

        var statusOptions = ['<option value="">Alle Status</option>'];
        Object.keys(cfg.status_labels || {}).forEach(function (key) {
            statusOptions.push('<option value="' + escapeHtml(key) + '"' + (state.status === key ? ' selected' : '') + '>' + escapeHtml(cfg.status_labels[key]) + '</option>');
        });

        var rowsHtml = '';
        if (state.items.length === 0) {
            rowsHtml = '<tr><td colspan="7" class="admin-bookings-empty">Keine Buchungen gefunden.</td></tr>';
        } else {
            rowsHtml = state.items.map(function (item) {
                var status = String(item.status || 'pending');
                var canReschedule = canManage && ['pending', 'confirmed'].indexOf(status) !== -1;
                var canCancel = canManage && ['pending', 'confirmed'].indexOf(status) !== -1;

                return '' +
                    '<tr class="admin-bookings-row ' + bookingRowClass(status) + '" data-row-id="' + item.id + '">' +
                    '  <td><div class="admin-bookings-primary">' + escapeHtml(clientName(item)) + '</div></td>' +
                    '  <td><div class="admin-bookings-client">' + escapeHtml((item.client && item.client.email) || '-') + '</div></td>' +
                    '  <td><div class="admin-bookings-service">' + escapeHtml(serviceName(item)) + '</div><div class="admin-bookings-meta">' + escapeHtml((item.service && item.service.slug) || '-') + '</div></td>' +
                    '  <td><div class="admin-bookings-time">' + escapeHtml(formatDateTime(item.scheduled_at)) + '</div></td>' +
                    '  <td><div class="admin-bookings-time">' + escapeHtml(formatDateTime(item.created_at)) + '</div></td>' +
                    '  <td><span class="admin-bookings-status">' + escapeHtml(bookingStatusLabel(status)) + '</span></td>' +
                    '  <td>' + renderRowActions(item, canReschedule, canCancel) + '</td>' +
                    '</tr>';
            }).join('');
        }

        var toolbar = '' +
            '<div class="admin-bookings-toolbar">' +
            '  <form class="admin-bookings-search" data-bookings-search-form>' +
            '    <input class="admin-bookings-input" name="q" type="search" placeholder="Suche nach Client, Service oder E-Mail" value="' + escapeHtml(state.search) + '" />' +
            '    <select class="admin-bookings-select" name="status">' + statusOptions.join('') + '</select>' +
            '    <button type="submit" class="admin-bookings-page-btn">Filtern</button>' +
            '  </form>' +
            '  <div class="admin-bookings-meta">' + (state.isLoading ? 'Lade...' : ('Gesamt: ' + state.total)) + '</div>' +
            '</div>';

        var tableHtml = '' +
            '<div class="admin-bookings-table-wrap">' +
            '  <table class="admin-bookings-table">' +
            '    <thead>' +
            '      <tr>' +
            '        <th><button type="button" class="admin-bookings-sort" data-sort="client_name">Client <span class="admin-bookings-sort-indicator">' + sortIndicator('client_name') + '</span></button></th>' +
            '        <th><button type="button" class="admin-bookings-sort" data-sort="email">E-Mail <span class="admin-bookings-sort-indicator">' + sortIndicator('email') + '</span></button></th>' +
            '        <th><button type="button" class="admin-bookings-sort" data-sort="service_name">Service <span class="admin-bookings-sort-indicator">' + sortIndicator('service_name') + '</span></button></th>' +
            '        <th><button type="button" class="admin-bookings-sort" data-sort="scheduled_at">Termin <span class="admin-bookings-sort-indicator">' + sortIndicator('scheduled_at') + '</span></button></th>' +
            '        <th><button type="button" class="admin-bookings-sort" data-sort="created_at">Eingang <span class="admin-bookings-sort-indicator">' + sortIndicator('created_at') + '</span></button></th>' +
            '        <th><button type="button" class="admin-bookings-sort" data-sort="status">Status <span class="admin-bookings-sort-indicator">' + sortIndicator('status') + '</span></button></th>' +
            '        <th>Aktionen</th>' +
            '      </tr>' +
            '    </thead>' +
            '    <tbody>' + rowsHtml + '</tbody>' +
            '  </table>' +
            '</div>';

        var paginationHtml = '' +
            '<div class="admin-bookings-pagination">' +
            '  <div class="admin-bookings-pagination-info">Seite ' + state.page + ' / ' + state.totalPages + '</div>' +
            '  <div class="admin-bookings-pagination-controls">' +
            '    <button type="button" class="admin-bookings-page-btn" data-page-prev ' + (state.page <= 1 ? 'disabled' : '') + '>Zurück</button>' +
            '    <button type="button" class="admin-bookings-page-btn" data-page-next ' + (state.page >= state.totalPages ? 'disabled' : '') + '>Weiter</button>' +
            '  </div>' +
            '</div>';

        root.innerHTML = toolbar + tableHtml + paginationHtml;
        bindEvents();
    }

    function renderRowActions(item, canReschedule, canCancel) {
        if (!canManage) {
            return '<span class="admin-bookings-meta">-</span>';
        }

        var html = '<div class="admin-bookings-actions">';
        html += '<button type="button" class="admin-bookings-action-btn" data-action="detail" data-id="' + item.id + '">Detail</button>';
        if (canReschedule) {
            html += '<button type="button" class="admin-bookings-action-btn" data-action="reschedule" data-id="' + item.id + '">Umbuchen</button>';
        }
        if (canCancel) {
            html += '<button type="button" class="admin-bookings-action-btn" data-action="cancel" data-id="' + item.id + '">Storno</button>';
        }
        html += '</div>';
        return html;
    }

    function bindEvents() {
        var searchForm = root.querySelector('[data-bookings-search-form]');
        if (searchForm) {
            searchForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(searchForm);
                state.search = trim(fd.get('q'));
                state.status = trim(fd.get('status'));
                state.page = 1;
                writeStateToUrl(false);
                fetchList();
                fetchAlertSummary();
            });
        }

        root.querySelectorAll('[data-sort]').forEach(function (el) {
            el.addEventListener('click', function () {
                var nextSort = String(el.getAttribute('data-sort') || 'scheduled_at');
                if (state.sort === nextSort) {
                    state.direction = state.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sort = nextSort;
                    state.direction = nextSort === 'scheduled_at' || nextSort === 'created_at' ? 'asc' : 'asc';
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
                if (state.page <= 1) {
                    return;
                }
                state.page -= 1;
                writeStateToUrl(false);
                fetchList();
                fetchAlertSummary();
            });
        }

        if (next) {
            next.addEventListener('click', function () {
                if (state.page >= state.totalPages) {
                    return;
                }
                state.page += 1;
                writeStateToUrl(false);
                fetchList();
                fetchAlertSummary();
            });
        }

        root.querySelectorAll('.admin-bookings-row[data-row-id]').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target && e.target.closest('[data-action]')) {
                    return;
                }

                var id = parsePositiveInt(row.getAttribute('data-row-id'), 0);
                if (id > 0) {
                    openDetailModal(id, true);
                }
            });
        });

        root.querySelectorAll('[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var id = parsePositiveInt(btn.getAttribute('data-id'), 0);
                var action = String(btn.getAttribute('data-action') || '');

                if (id <= 0) {
                    return;
                }

                if (action === 'detail') {
                    openDetailModal(id, true);
                } else if (action === 'reschedule') {
                    openRescheduleModal(requestById(id));
                } else if (action === 'cancel') {
                    openCancelModal(requestById(id));
                }
            });
        });
    }

    function requestById(id) {
        return state.items.find(function (item) {
            return Number(item.id) === Number(id);
        }) || state.detailItem || null;
    }

    function openDetailModal(id, pushState) {
        fetchJson(apiUrl(cfg.api && cfg.api.detail, id))
            .then(function (result) {
                var item = result.json && (result.json.data && result.json.data.booking ? result.json.data.booking : result.json.booking);
                if (!item) {
                    throw new Error('not_found');
                }

                state.selectedId = id;
                state.detailItem = item;
                writeStateToUrl(pushState);
                showDetailModal(item);
            })
            .catch(function (err) {
                var msg = 'Buchungs-Detail konnte nicht geladen werden.';
                if (err && err.message) {
                    msg += ' (' + String(err.message) + ')';
                }
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', msg);
                }
                console.error('[openDetailModal]', err);
            });
    }

    function detailRow(label, value) {
        return '' +
            '<div class="admin-bookings-detail-row">' +
            '  <span class="admin-bookings-detail-label">' + escapeHtml(label) + '</span>' +
            '  <div class="admin-bookings-detail-value">' + value + '</div>' +
            '</div>';
    }

    function showDetailModal(item) {
        var status = String(item.status || 'pending');
        var paymentStatus = String(item.payment_status || 'pending');
        var scheduledPassed = hasScheduledAtPassed(item.scheduled_at);
        var invoice = item.invoice && typeof item.invoice === 'object' ? item.invoice : null;
        var hasInvoice = !!(invoice && Number(invoice.id || 0) > 0);
        var paymentAutomationEnabled = !!cfg.payment_automation_enabled;
        var isPackageFirstSession = !!item.is_package_booking && Number(item.package_session_no || 0) === 1;
        var canCreateInvoice = canManage && status === 'pending' && paymentStatus !== 'paid' && (paymentAutomationEnabled || isPackageFirstSession);
        var canConfirmFree = canManage && status === 'pending' && isFreeService(item);
        var canMarkPaid = canManage
            && ['pending', 'confirmed', 'no_show'].indexOf(status) !== -1
            && paymentStatus !== 'paid'
            && (status === 'no_show' || !paymentAutomationEnabled || hasInvoice);
        var canMarkCompleted = canManage && status === 'confirmed' && scheduledPassed;
        var canMarkNoShow = canManage && ['confirmed', 'completed', 'cancelled'].indexOf(status) !== -1;
        var canReset = canManage && canRevert && ['confirmed', 'cancelled', 'no_show'].indexOf(status) !== -1 && (status === 'no_show' || !scheduledPassed);
        var canReschedule = canManage && ['pending', 'confirmed'].indexOf(status) !== -1;
        var canCancel = canManage && ['pending', 'confirmed'].indexOf(status) !== -1;
        var reasonLabel = status === 'no_show' ? 'No-Show Grund' : 'Stornogrund';

        var body = '' +
            '<div class="admin-bookings-detail-grid">' +
            detailRow('Buchung ID', String(item.id || '-')) +
            detailRow('Client', escapeHtml(clientName(item))) +
            detailRow('Client E-Mail', escapeHtml(item.client && item.client.email ? item.client.email : '-')) +
            detailRow('Client Telefon', escapeHtml(item.client && item.client.phone ? item.client.phone : '-')) +
            detailRow('Service', escapeHtml(serviceName(item)) + ' (' + escapeHtml((item.service && item.service.slug) || '-') + ')') +
            detailRow('Leistung', escapeHtml(formatMoney(item.service && item.service.price ? item.service.price : 0)) + ' / ' + escapeHtml(String((item.service && item.service.duration_minutes) || 0)) + ' Min.') +
            detailRow('Termin', escapeHtml(formatDateTime(item.scheduled_at))) +
            detailRow('Eingang', escapeHtml(formatDateTime(item.created_at))) +
            detailRow('Status', escapeHtml(bookingStatusLabel(status))) +
            detailRow('Zahlungsstatus', escapeHtml(paymentStatusLabel(item.payment_status || '-'))) +
            detailRow('Rechnung', hasInvoice ? ('#' + escapeHtml(String(invoice.invoice_number || invoice.id || '-'))) : '-') +
            detailRow('Rechnungsstatus', hasInvoice ? escapeHtml(String(invoice.status || '-')) : '-') +
            detailRow('Rechnungsbetrag', hasInvoice ? escapeHtml(formatMoney(invoice.total_amount || 0)) : '-') +
            detailRow('Rechnungsfälligkeit', hasInvoice ? escapeHtml(String(invoice.due_date || '-')) : '-') +
            detailRow('Notizen', escapeHtml(String(item.notes || '-'))) +
            detailRow(reasonLabel, escapeHtml(String(item.cancellation_reason || '-'))) +
            detailRow('Stornierung', escapeHtml(String(item.cancellation_timing || '-'))) +
            detailRow('Abgesagt am', escapeHtml(formatDateTime(item.cancelled_at))) +
            detailRow('Status geändert am', escapeHtml(formatDateTime(item.status_changed_at))) +
            detailRow('Geändert von User ID', escapeHtml(String(item.status_changed_by_user_id || '-'))) +
            '</div>';

        if (canManage && canReset) {
            body += '' +
                '<div class="admin-bookings-panel" style="margin-top:0.75rem">' +
                '  <label class="admin-bookings-detail-label" for="adminBookingRevertReason">Reset-Grund (optional)</label>' +
                '  <textarea id="adminBookingRevertReason" class="admin-bookings-textarea" placeholder="Wird im Audit als Revert-Grund gespeichert."></textarea>' +
                '</div>';
        }

        var quickActions = [];
        if (canManage) {
            if (canCreateInvoice) {
                quickActions.push({ value: 'invoice', label: hasInvoice ? 'Neue Rechnung erstellen' : 'Rechnung erstellen', tone: 'primary' });
            }
            if (canConfirmFree) {
                quickActions.push({ value: 'confirm', label: 'Freigeben (confirmed)', tone: 'primary' });
            }
            if (canMarkPaid) {
                quickActions.push({ value: 'paid', label: 'Rechnung bezahlt setzen', tone: 'primary' });
            }
            if (canMarkCompleted) {
                quickActions.push({ value: 'completed', label: 'Als completed markieren', tone: 'primary' });
            }
            if (canMarkNoShow) {
                quickActions.push({ value: 'no_show', label: 'Als No Show markieren', tone: 'danger' });
            }
            if (canReset) {
                quickActions.push({ value: 'reset_pending', label: isFreeService(item) ? 'Reset auf confirmed' : 'Reset auf pending', tone: 'secondary' });
            }
            if (canReschedule) {
                quickActions.push({ value: 'reschedule', label: 'Umbuchen', tone: 'secondary' });
            }
            if (canCancel) {
                quickActions.push({ value: 'cancel', label: 'Stornieren', tone: 'danger' });
            }
        }

        if (quickActions.length > 0) {
            body += '' +
                '<div class="admin-bookings-panel" style="margin-top:0.75rem">' +
                '  <label class="admin-bookings-detail-label">Schnellaktionen</label>' +
                '  <div class="admin-bookings-quick-actions">' +
                quickActions.map(function (opt) {
                    return '<button type="button" class="admin-bookings-action-btn admin-bookings-quick-action-btn admin-bookings-quick-action-btn--' + escapeHtml(opt.tone || 'secondary') + '" data-booking-detail-action="' + escapeHtml(opt.value) + '">' + escapeHtml(opt.label) + '</button>';
                }).join('') +
                '  </div>' +
                '</div>';
        }

        var buttons = [{ label: 'Schließen', variant: 'secondary', onClick: clearDetailState }];

        window.adminOpenModal('Buchung #' + item.id, body, {
            type: 'form',
            buttons: buttons
        });

        if (quickActions.length > 0) {
            bindDetailQuickActions(item);
        }
    }

    function bindDetailQuickActions(item) {
        setTimeout(function () {
            document.querySelectorAll('[data-booking-detail-action]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var action = String(btn.getAttribute('data-booking-detail-action') || '');
                    runDetailQuickAction(action, item);
                });
            });
        }, 0);
    }

    function runDetailQuickAction(action, item) {
        if (action === 'invoice') {
            openInvoiceModal(item);
            return;
        }

        if (action === 'paid') {
            patchBookingUpdate(item.id, { payment_status: 'paid' }, 'Zahlungsstatus wurde auf paid gesetzt.').then(function () {
                if (state.selectedId === item.id) {
                    openDetailModal(item.id, false);
                }
            });
            return;
        }

        if (action === 'confirm') {
            patchBookingUpdate(item.id, { status: 'confirmed' }, 'Buchung wurde freigegeben (confirmed).').then(function () {
                if (state.selectedId === item.id) {
                    openDetailModal(item.id, false);
                }
            });
            return;
        }

        if (action === 'completed') {
            patchBookingUpdate(item.id, { status: 'completed' }, 'Buchung wurde auf completed gesetzt.').then(function () {
                if (state.selectedId === item.id) {
                    openDetailModal(item.id, false);
                }
            });
            return;
        }

        if (action === 'no_show') {
            openNoShowModal(item);
            return;
        }

        if (action === 'reset_pending') {
            var revertReason = document.getElementById('adminBookingRevertReason');
            var reason = revertReason ? trim(revertReason.value) : '';
            var payload = { status: 'pending' };
            if (reason !== '') {
                payload.revert_reason = reason;
            }

            var successText = isFreeService(item)
                ? 'Buchung wurde auf confirmed zurückgesetzt (kostenloser Service).'
                : 'Buchung wurde auf pending zurückgesetzt.';

            patchBookingUpdate(item.id, payload, successText).then(function () {
                if (state.selectedId === item.id) {
                    openDetailModal(item.id, false);
                }
            });
            return;
        }

        if (action === 'reschedule') {
            openRescheduleModal(item);
            return;
        }

        if (action === 'cancel') {
            openCancelModal(item);
        }
    }

    function patchBookingUpdate(id, body, successMessage) {
        return fetchJson(apiUrl(cfg.api && cfg.api.status, id), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (result) {
            if (!result.ok) {
                if (result.status === 403 && window.adminShowNotification) {
                    window.adminShowNotification('warning', buildApiErrorToastMessage(result, 'Keine Berechtigung für diesen Statuswechsel.'));
                } else if (result.status === 409 && window.adminShowNotification) {
                    window.adminShowNotification('warning', buildApiErrorToastMessage(result, 'Statuswechsel ist in diesem Zustand nicht erlaubt.'));
                } else if (window.adminShowNotification) {
                    window.adminShowNotification('error', buildApiErrorToastMessage(result, 'Status konnte nicht geändert werden.'));
                }
                return Promise.reject(result);
            }

            if (window.adminShowNotification) {
                window.adminShowNotification('success', successMessage || 'Status wurde aktualisiert.');
            }

            if (typeof window.adminDashboardRefresh === 'function') {
                window.adminDashboardRefresh();
            }

            fetchList();
            return result;
        });
    }

    function hasScheduledAtPassed(value) {
        var dt = value ? new Date(String(value).replace(' ', 'T')) : null;
        if (!dt || Number.isNaN(dt.getTime())) {
            return true;
        }

        return dt.getTime() <= Date.now();
    }

    function parseDateOnly(value) {
        if (!value) {
            return null;
        }

        var parsed = new Date(String(value) + 'T00:00:00');
        if (Number.isNaN(parsed.getTime())) {
            return null;
        }

        parsed.setHours(0, 0, 0, 0);
        return parsed;
    }

    function previousBusinessDay(dateValue) {
        var d = new Date(dateValue.getTime());
        d.setDate(d.getDate() - 1);

        while (d.getDay() === 0 || d.getDay() === 6) {
            d.setDate(d.getDate() - 1);
        }

        d.setHours(0, 0, 0, 0);
        return d;
    }

    function calculateMaxDueDaysForAppointment(item, invoiceDateValue) {
        var appointment = item && item.scheduled_at ? new Date(String(item.scheduled_at).replace(' ', 'T')) : null;
        if (!appointment || Number.isNaN(appointment.getTime())) {
            return 90;
        }

        appointment.setHours(0, 0, 0, 0);
        var latestAllowedDueDate = previousBusinessDay(appointment);

        var invoiceDate = parseDateOnly(invoiceDateValue);
        if (!invoiceDate) {
            invoiceDate = new Date();
            invoiceDate.setHours(0, 0, 0, 0);
        }

        var diffMs = latestAllowedDueDate.getTime() - invoiceDate.getTime();
        return Math.floor(diffMs / 86400000);
    }

    function syncInvoiceDueDaysBoundary(item, defaultDueDays) {
        var invoiceDateEl = document.getElementById('adminInvoiceDate');
        var dueDaysEl = document.getElementById('adminInvoiceDueDays');
        var hintEl = document.getElementById('adminInvoiceDueHint');

        if (!dueDaysEl) {
            return { valid: true, dueDays: defaultDueDays };
        }

        var invoiceDateValue = invoiceDateEl ? String(invoiceDateEl.value || '') : '';
        var maxDueDays = calculateMaxDueDaysForAppointment(item, invoiceDateValue);
        if (maxDueDays > 90) {
            maxDueDays = 90;
        }

        if (maxDueDays < 1) {
            dueDaysEl.value = '';
            dueDaysEl.min = '0';
            dueDaysEl.max = '0';
            dueDaysEl.disabled = true;
            if (hintEl) {
                hintEl.textContent = 'Keine Fälligkeit möglich: Die Sitzung ist zu nah. Die Rechnung wird ohne Fälligkeitsdatum erstellt. Die Leistung wird nur erbracht, wenn der Betrag vor Antritt vollständig beglichen wurde.';
            }

            return {
                valid: true,
                noDueDate: true,
                dueDays: null
            };
        }

        dueDaysEl.disabled = false;
        dueDaysEl.min = '1';
        dueDaysEl.max = String(maxDueDays);

        var dueDays = Number(dueDaysEl.value || defaultDueDays);
        if (!Number.isFinite(dueDays) || dueDays < 1) {
            dueDays = defaultDueDays;
        }

        if (dueDays > maxDueDays) {
            dueDays = maxDueDays;
            dueDaysEl.value = String(dueDays);
        }

        if (hintEl) {
            hintEl.textContent = 'Maximal ' + String(maxDueDays) + ' Tag(e), damit die Fälligkeit spätestens einen Werktag vor der Sitzung liegt.';
        }

        return {
            valid: true,
            dueDays: dueDays,
            maxDueDays: maxDueDays
        };
    }

    function openInvoiceModal(item) {
        if (!item || !item.id) {
            return;
        }

        var defaultDueDays = Number(cfg.invoice_default_due_days || 7);
        if (!Number.isFinite(defaultDueDays) || defaultDueDays < 1) {
            defaultDueDays = 7;
        }

        var now = new Date();
        var y = String(now.getFullYear());
        var m = String(now.getMonth() + 1).padStart(2, '0');
        var d = String(now.getDate()).padStart(2, '0');
        var invoiceDate = y + '-' + m + '-' + d;

        var body = '' +
            '<div class="admin-bookings-create-form">' +
            '  <div class="admin-bookings-panel">' +
            '    <label class="admin-bookings-detail-label">Postenübersicht</label>' +
            '    <div id="adminInvoicePreviewList" class="admin-bookings-detail-grid"></div>' +
            '    <div class="admin-bookings-detail-row">' +
            '      <span class="admin-bookings-detail-label">Gesamtsumme</span>' +
            '      <div id="adminInvoicePreviewTotal" class="admin-bookings-detail-value">0,00 EUR</div>' +
            '    </div>' +
            '  </div>' +
            '  <div class="admin-bookings-panel">' +
            '    <label class="admin-bookings-checkbox-row"><input id="adminInvoiceIncludeDefault" type="checkbox" checked /> Standard-Leistung als Position übernehmen</label>' +
            '  </div>' +
            '  <div class="admin-bookings-panel">' +
            '    <label class="admin-bookings-detail-label">Zusätzliche Posten</label>' +
            '    <div id="adminInvoiceExtraItems" class="admin-invoice-extra-items"></div>' +
            '    <button type="button" id="adminInvoiceAddItem" class="admin-bookings-form-btn admin-invoice-add-btn">+ Posten hinzufügen</button>' +
            '  </div>' +
            '  <div class="admin-bookings-panel">' +
            '    <label class="admin-bookings-detail-label" for="adminInvoiceDiscount">Rabatt (EUR, optional)</label>' +
            '    <input id="adminInvoiceDiscount" class="admin-bookings-input" type="number" step="0.01" min="0" />' +
            '  </div>' +
            '  <div class="admin-bookings-panel">' +
            '    <label class="admin-bookings-detail-label" for="adminInvoiceDate">Rechnungsdatum</label>' +
            '    <input id="adminInvoiceDate" class="admin-bookings-input" type="date" value="' + escapeHtml(invoiceDate) + '" />' +
            '    <label class="admin-bookings-detail-label" for="adminInvoiceDueDays" style="margin-top:0.5rem">Fällig in Tagen</label>' +
            '    <input id="adminInvoiceDueDays" class="admin-bookings-input" type="number" min="1" max="90" value="' + escapeHtml(String(defaultDueDays)) + '" />' +
            '    <div id="adminInvoiceDueHint" class="admin-bookings-detail-value" style="margin-top:0.5rem"></div>' +
            '  </div>' +
            '</div>';

        window.adminOpenModal('Rechnung erstellen', body, {
            type: 'form',
            buttons: [
                {
                    label: 'Rechnung erstellen',
                    variant: 'primary',
                    onClick: function () {
                        var includeDefaultEl = document.getElementById('adminInvoiceIncludeDefault');
                        var discountEl = document.getElementById('adminInvoiceDiscount');
                        var invoiceDateEl = document.getElementById('adminInvoiceDate');
                        var dueBoundary = syncInvoiceDueDaysBoundary(item, defaultDueDays);

                        var includeDefault = !!(includeDefaultEl && includeDefaultEl.checked);
                        var discount = discountEl ? Number(discountEl.value || 0) : 0;
                        var dueDays = dueBoundary.noDueDate ? null : Number(dueBoundary.dueDays || defaultDueDays);

                        var additionalItems = readInvoiceExtraItemRows();

                        createInvoice(item.id, {
                            include_default_item: includeDefault,
                            additional_items: additionalItems,
                            discount_amount: Number.isFinite(discount) ? discount : 0,
                            invoice_date: invoiceDateEl ? String(invoiceDateEl.value || '') : '',
                            due_days: dueBoundary.noDueDate ? null : (Number.isFinite(dueDays) ? dueDays : defaultDueDays),
                            no_due_date: !!dueBoundary.noDueDate
                        }).then(function () {
                            if (state.selectedId === item.id) {
                                openDetailModal(item.id, false);
                            }
                            window.adminCloseModal && window.adminCloseModal();
                        });
                    }
                },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } }
            ]
        });

        setTimeout(function () {
            bindInvoiceModalEvents(item);
            syncInvoiceDueDaysBoundary(item, defaultDueDays);
            renderInvoicePreview(item);
        }, 0);
    }

    function bindInvoiceModalEvents(item) {
        ['adminInvoiceIncludeDefault', 'adminInvoiceDiscount', 'adminInvoiceDate', 'adminInvoiceDueDays'].forEach(function (id) {
            var element = document.getElementById(id);
            if (!element) {
                return;
            }

            element.addEventListener('input', function () {
                if (id === 'adminInvoiceDate' || id === 'adminInvoiceDueDays') {
                    syncInvoiceDueDaysBoundary(item, Number(cfg.invoice_default_due_days || 7));
                }
                renderInvoicePreview(item);
            });
            element.addEventListener('change', function () {
                if (id === 'adminInvoiceDate' || id === 'adminInvoiceDueDays') {
                    syncInvoiceDueDaysBoundary(item, Number(cfg.invoice_default_due_days || 7));
                }
                renderInvoicePreview(item);
            });
        });

        var addBtn = document.getElementById('adminInvoiceAddItem');
        var container = document.getElementById('adminInvoiceExtraItems');
        if (addBtn && container) {
            addBtn.addEventListener('click', function () {
                addInvoiceExtraItemRow(container, function () { renderInvoicePreview(item); });
                renderInvoicePreview(item);
            });
        }
    }

    function addInvoiceExtraItemRow(container, refreshFn) {
        var row = document.createElement('div');
        row.className = 'admin-invoice-extra-item';
        row.innerHTML =
            '<input class="admin-bookings-input admin-invoice-item-desc" type="text" placeholder="Beschreibung" />' +
            '<input class="admin-bookings-input admin-invoice-item-amount" type="number" step="0.01" placeholder="Betrag (EUR)" />' +
            '<button type="button" class="admin-invoice-item-remove" title="Posten entfernen">&times;</button>';
        row.querySelector('.admin-invoice-item-remove').addEventListener('click', function () {
            row.parentNode && row.parentNode.removeChild(row);
            refreshFn();
        });
        row.querySelector('.admin-invoice-item-desc').addEventListener('input', refreshFn);
        row.querySelector('.admin-invoice-item-amount').addEventListener('input', refreshFn);
        container.appendChild(row);
    }

    function readInvoiceExtraItemRows() {
        var container = document.getElementById('adminInvoiceExtraItems');
        if (!container) {
            return [];
        }
        var rows = container.querySelectorAll('.admin-invoice-extra-item');
        var items = [];
        rows.forEach(function (row) {
            var descEl = row.querySelector('.admin-invoice-item-desc');
            var amountEl = row.querySelector('.admin-invoice-item-amount');
            var desc = descEl ? trim(descEl.value) : '';
            var amount = amountEl ? Number(amountEl.value || 0) : 0;
            if (desc !== '' && Number.isFinite(amount) && amount !== 0) {
                items.push({ description: desc, quantity: 1, unit_price: amount });
            }
        });
        return items;
    }

    function renderInvoicePreview(item) {
        var list = document.getElementById('adminInvoicePreviewList');
        var totalNode = document.getElementById('adminInvoicePreviewTotal');
        if (!list || !totalNode) {
            return;
        }

        var preview = buildInvoicePreview(item);
        if (preview.items.length === 0) {
            list.innerHTML = detailRow('Posten', 'Noch keine Positionen ausgewählt.');
        } else {
            list.innerHTML = preview.items.map(function (entry) {
                return detailRow(entry.label, entry.value);
            }).join('');
        }

        totalNode.textContent = formatMoney(preview.total);
    }

    function buildInvoicePreview(item) {
        var includeDefaultEl = document.getElementById('adminInvoiceIncludeDefault');
        var discountEl = document.getElementById('adminInvoiceDiscount');

        var includeDefault = !!(includeDefaultEl && includeDefaultEl.checked);
        var discount = discountEl ? Number(discountEl.value || 0) : 0;
        var items = [];
        var total = 0;

        if (includeDefault) {
            var defaultPrice = Number(item.service && item.service.price ? item.service.price : 0);
            var defaultLabel = serviceName(item) + ' (' + formatDateTime(item.scheduled_at) + ')';
            if (item.is_package_booking && Number(item.package_session_no || 0) === 1 && item.package_purchase) {
                defaultPrice = Number(item.package_purchase.price || 0);
                defaultLabel = 'Paket: ' + packageDisplayName(item);
                var packageServiceName = serviceName(item);
                if (packageServiceName !== '') {
                    defaultLabel += ' (' + packageServiceName + ')';
                }
            }
            items.push({
                label: 'Automatisch',
                value: escapeHtml(defaultLabel) + ' - ' + escapeHtml(formatMoney(defaultPrice))
            });
            total += defaultPrice;
        }

        var extraItems = readInvoiceExtraItemRows();
        extraItems.forEach(function (extraItem) {
            items.push({
                label: 'Zusatzposten',
                value: escapeHtml(extraItem.description) + ' - ' + escapeHtml(formatMoney(extraItem.unit_price))
            });
            total += extraItem.unit_price;
        });

        if (Number.isFinite(discount) && discount > 0) {
            items.push({
                label: 'Rabatt',
                value: '- ' + escapeHtml(formatMoney(discount))
            });
            total -= discount;
        }

        return {
            items: items,
            total: Math.round(total * 100) / 100
        };
    }

    function createInvoice(id, payload) {
        return fetchJson(apiUrl(cfg.api && cfg.api.invoice, id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {})
        }).then(function (result) {
            if (!result.ok) {
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', buildApiErrorToastMessage(result, 'Rechnung konnte nicht erstellt werden.'));
                }
                return Promise.reject(result);
            }

            if (window.adminShowNotification) {
                window.adminShowNotification('success', 'Rechnung wurde erstellt.');
            }

            if (typeof window.adminDashboardRefresh === 'function') {
                window.adminDashboardRefresh();
            }

            fetchList();
            return result;
        });
    }

    function openRescheduleModal(item) {
        if (!item) {
            return;
        }

        var currentValue = toDatetimeLocalValue(item.scheduled_at);
        var body = '' +
            '<div class="admin-bookings-create-form">' +
            '  <div class="admin-bookings-panel">' +
            '    <label class="admin-bookings-detail-label" for="adminBookingRescheduleStart">Neuer Termin</label>' +
            '    <input id="adminBookingRescheduleStart" class="admin-bookings-input" type="datetime-local" step="1800" value="' + escapeHtml(currentValue) + '" />' +
            '  </div>' +
            '</div>';

        window.adminOpenModal('Umbuchen', body, {
            type: 'form',
            buttons: [
                {
                    label: 'Umbuchen',
                    variant: 'primary',
                    onClick: function () {
                        var input = document.getElementById('adminBookingRescheduleStart');
                        var startedAt = input ? String(input.value || '') : '';
                        var error = businessTimeError(startedAt);
                        if (error !== '') {
                            if (window.adminShowNotification) {
                                window.adminShowNotification('warning', error);
                            }
                            return;
                        }

                        fetchJson(apiUrl(cfg.api && cfg.api.reschedule, item.id), {
                            method: 'PATCH',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ started_at: startedAt })
                        }).then(function (result) {
                            if (!result.ok) {
                                if (window.adminShowNotification) {
                                    window.adminShowNotification('error', buildApiErrorToastMessage(result, 'Umbuchung konnte nicht gespeichert werden.'));
                                }
                                return;
                            }

                            if (window.adminShowNotification) {
                                window.adminShowNotification('success', 'Buchung wurde umgebucht.');
                            }

                            fetchList();
                            if (state.selectedId === item.id) {
                                openDetailModal(item.id, false);
                            }

                            if (typeof window.adminDashboardRefresh === 'function') {
                                window.adminDashboardRefresh();
                            }

                            window.adminCloseModal && window.adminCloseModal();
                        });
                    }
                },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } }
            ]
        });
    }

    function openCancelModal(item) {
        if (!item) {
            return;
        }

        var body = '' +
            '<div class="admin-bookings-create-form">' +
            '  <div class="admin-bookings-panel">' +
            '    <label class="admin-bookings-detail-label" for="adminBookingCancelReason">Stornogrund (optional)</label>' +
            '    <textarea id="adminBookingCancelReason" class="admin-bookings-textarea" placeholder="Wird im Modal angezeigt, ist aber nicht verpflichtend."></textarea>' +
            '  </div>' +
            '</div>';

        window.adminOpenModal('Stornieren', body, {
            type: 'form',
            buttons: [
                {
                    label: 'Stornieren',
                    variant: 'danger',
                    onClick: function () {
                        var input = document.getElementById('adminBookingCancelReason');
                        var reason = input ? trim(input.value) : '';
                        fetchJson(apiUrl(cfg.api && cfg.api.cancel, item.id), {
                            method: 'PATCH',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ cancellation_reason: reason })
                        }).then(function (result) {
                            if (!result.ok) {
                                if (window.adminShowNotification) {
                                    window.adminShowNotification('error', buildApiErrorToastMessage(result, 'Stornierung konnte nicht gespeichert werden.'));
                                }
                                return;
                            }

                            if (window.adminShowNotification) {
                                window.adminShowNotification('success', 'Buchung wurde storniert.');
                            }

                            fetchList();
                            if (state.selectedId === item.id) {
                                openDetailModal(item.id, false);
                            }

                            if (typeof window.adminDashboardRefresh === 'function') {
                                window.adminDashboardRefresh();
                            }

                            window.adminCloseModal && window.adminCloseModal();
                        });
                    }
                },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } }
            ]
        });
    }

    function openNoShowModal(item) {
        if (!item) {
            return;
        }

        var body = '' +
            '<div class="admin-bookings-create-form">' +
            '  <div class="admin-bookings-panel">' +
            '    <p class="admin-bookings-meta" style="margin:0 0 0.6rem 0;">No Show ist zahlungspflichtig und wird im Verlauf protokolliert.</p>' +
            '    <label class="admin-bookings-detail-label" for="adminBookingNoShowReason">No-Show Grund (optional)</label>' +
            '    <textarea id="adminBookingNoShowReason" class="admin-bookings-textarea" placeholder="Kurz notieren, warum als No Show markiert wurde."></textarea>' +
            '  </div>' +
            '</div>';

        window.adminOpenModal('No Show setzen', body, {
            type: 'form',
            buttons: [
                {
                    label: 'Als No Show markieren',
                    variant: 'danger',
                    onClick: function () {
                        var input = document.getElementById('adminBookingNoShowReason');
                        var reason = input ? trim(input.value) : '';
                        var payload = { status: 'no_show' };
                        if (reason !== '') {
                            payload.no_show_reason = reason;
                        }

                        patchBookingUpdate(item.id, payload, 'Buchung wurde auf No Show gesetzt.').then(function () {
                            if (state.selectedId === item.id) {
                                openDetailModal(item.id, false);
                            }
                        });
                    }
                },
                { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } }
            ]
        });
    }

    function ensureMeta() {
        if (state.meta) {
            return Promise.resolve(state.meta);
        }

        var preloadedMeta = cfg.preloaded_meta || {};
        if (Array.isArray(preloadedMeta.services) || Array.isArray(preloadedMeta.packages) || Array.isArray(preloadedMeta.clients)) {
            state.meta = {
                services: Array.isArray(preloadedMeta.services) ? preloadedMeta.services : [],
                packages: Array.isArray(preloadedMeta.packages) ? preloadedMeta.packages : [],
                clients: Array.isArray(preloadedMeta.clients) ? preloadedMeta.clients : [],
                window: preloadedMeta.window || { start_hour: 8, end_hour: 18, slot_step_minutes: 30 }
            };

            if (state.meta.services.length > 0 || state.meta.packages.length > 0 || state.meta.clients.length > 0) {
                return Promise.resolve(state.meta);
            }
        }

        if (!canManage) {
            state.meta = { services: [], packages: [], clients: [], window: { start_hour: 8, end_hour: 18, slot_step_minutes: 30 } };
            return Promise.resolve(state.meta);
        }

        if (!state.metaPromise) {
            state.metaPromise = fetchJson(apiUrl(cfg.api && cfg.api.meta)).then(function (result) {
                var data = result.json && result.json.data ? result.json.data : {};
                state.meta = {
                    services: Array.isArray(data.services) ? data.services : [],
                    packages: Array.isArray(data.packages) ? data.packages : [],
                    clients: Array.isArray(data.clients) ? data.clients : [],
                    window: data.window || { start_hour: 8, end_hour: 18, slot_step_minutes: 30 }
                };
                return state.meta;
            }).catch(function () {
                state.meta = { services: [], packages: [], clients: [], window: { start_hour: 8, end_hour: 18, slot_step_minutes: 30 } };
                return state.meta;
            });
        }

        return state.metaPromise;
    }

    function openCreateModal(prefill) {
        if (!canManage) {
            if (window.adminShowNotification) {
                window.adminShowNotification('warning', 'Keine Berechtigung für neue Buchungen.');
            }
            return;
        }

        ensureMeta().then(function () {
            var body = buildCreateModalHtml();
            window.adminOpenModal('Neue Buchung', body, {
                type: 'form',
                buttons: [
                    {
                        label: 'Speichern',
                        variant: 'primary',
                        onClick: submitCreateModal
                    },
                    { label: 'Abbrechen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } }
                ]
            });

            setTimeout(function () {
                bindCreateModalEvents(prefill || null);
            }, 0);
        });
    }

    function buildCreateModalHtml() {
        var meta = state.meta || { services: [], packages: [], clients: [] };
        var services = Array.isArray(meta.services) ? meta.services : [];
        var packages = Array.isArray(meta.packages) ? meta.packages : [];
        var clients = Array.isArray(meta.clients) ? meta.clients : [];

        var serviceOptions = services.length === 0
            ? '<option value="">Keine Services verfügbar</option>'
            : services.map(function (service) {
                var label = service.name + (service.duration_minutes ? ' (' + service.duration_minutes + ' Min.)' : '');
                if (service.is_active === false || String(service.is_active) === '0') {
                    label += ' (inaktiv)';
                }
                return '<option value="' + escapeHtml(String(service.id)) + '" data-slug="' + escapeHtml(String(service.slug || '')) + '" data-name="' + escapeHtml(String(service.name || '')) + '" data-duration="' + escapeHtml(String(service.duration_minutes || '')) + '"' + ((service.is_active === false || String(service.is_active) === '0') ? ' disabled' : '') + '>' + escapeHtml(label) + '</option>';
            }).join('');

        var clientOptions = clients.length === 0
            ? '<option value="">Keine Clients vorhanden</option>'
            : clients.map(function (client) {
                var text = client.name || client.email || ('Client #' + client.id);
                if (client.email) {
                    text += ' - ' + client.email;
                }
                return '<option value="' + escapeHtml(String(client.id)) + '">' + escapeHtml(text) + '</option>';
            }).join('');

        var packageOptions = ['<option value="">Keine Paketnutzung (normaler Service)</option>'].concat(
            packages.map(function (pkg) {
                var label = (pkg.name || ('Paket #' + pkg.id)) +
                    ' - ' + String(pkg.session_count || 0) + ' Sitzungen' +
                    (pkg.price ? ' (' + formatMoney(pkg.price) + ')' : '');
                return '<option value="' + escapeHtml(String(pkg.id)) + '" data-service-id="' + escapeHtml(String(pkg.service_id || '')) + '">' + escapeHtml(label) + '</option>';
            })
        ).join('');

        return '' +
            '<div class="admin-bookings-create-form">' +
            '  <div class="admin-bookings-panel">' +
            '    <div class="admin-bookings-radio-group" role="radiogroup">' +
            '      <label class="admin-bookings-radio-item"><input type="radio" name="booking_mode" value="existing" checked /> Bestehender Client</label>' +
            '      <label class="admin-bookings-radio-item"><input type="radio" name="booking_mode" value="new" /> Neuer Client</label>' +
            '    </div>' +
            '  </div>' +
            '  <div class="admin-bookings-form-grid">' +
            '    <div class="admin-bookings-panel" data-create-block="booking">' +
            '      <label class="admin-bookings-detail-label" for="createService">Service</label>' +
            '      <select id="createService" class="admin-bookings-select">' + serviceOptions + '</select>' +
            '    </div>' +
            '    <div class="admin-bookings-panel" data-create-block="booking">' +
            '      <label class="admin-bookings-detail-label" for="createPackage">Paket (optional)</label>' +
            '      <select id="createPackage" class="admin-bookings-select">' + packageOptions + '</select>' +
            '    </div>' +
            '    <div class="admin-bookings-panel" data-create-block="booking">' +
            '      <label class="admin-bookings-detail-label" for="createDate">Datum</label>' +
            '      <input id="createDate" class="admin-bookings-input" type="date" />' +
            '    </div>' +
            '    <div class="admin-bookings-panel" data-create-block="booking">' +
            '      <label class="admin-bookings-detail-label" for="createSlot">Zeitslot</label>' +
            '      <select id="createSlot" class="admin-bookings-select"><option value="">Bitte Service und Datum wählen</option></select>' +
            '    </div>' +
            '    <div class="admin-bookings-panel" data-create-block="booking">' +
            '      <label class="admin-bookings-detail-label" for="createNotes">Notizen (optional)</label>' +
            '      <textarea id="createNotes" class="admin-bookings-textarea" placeholder="Interne Notizen"></textarea>' +
            '    </div>' +
            '    <div class="admin-bookings-panel" data-create-block="existing">' +
            '      <label class="admin-bookings-detail-label" for="createClientId">Bestehender Client</label>' +
            '      <select id="createClientId" class="admin-bookings-select">' + clientOptions + '</select>' +
            '    </div>' +
            '    <div class="admin-bookings-panel admin-bookings-section-hidden" data-create-block="new">' +
            '      <label class="admin-bookings-detail-label" for="createFirstName">Vorname</label>' +
            '      <input id="createFirstName" class="admin-bookings-input" type="text" />' +
            '      <label class="admin-bookings-detail-label" for="createLastName" style="margin-top:0.5rem">Nachname</label>' +
            '      <input id="createLastName" class="admin-bookings-input" type="text" />' +
            '      <label class="admin-bookings-detail-label" for="createEmail" style="margin-top:0.5rem">E-Mail</label>' +
            '      <input id="createEmail" class="admin-bookings-input" type="email" />' +
            '      <label class="admin-bookings-detail-label" for="createPhone" style="margin-top:0.5rem">Telefon</label>' +
            '      <input id="createPhone" class="admin-bookings-input" type="tel" />' +
            '      <label class="admin-bookings-detail-label" for="createDateOfBirth" style="margin-top:0.5rem">Geburtsdatum</label>' +
            '      <input id="createDateOfBirth" class="admin-bookings-input" type="date" />' +
            '    </div>' +
            '  </div>' +
                '  <div class="admin-bookings-hint">Buchungen nutzen 30-Minuten-Slots mit Availability-Prüfung.</div>' +
            '</div>';
    }

    function bindCreateModalEvents(prefill) {
        var radios = document.querySelectorAll('input[name="booking_mode"]');
        var serviceSelect = document.getElementById('createService');
        var packageSelect = document.getElementById('createPackage');
        var dateInput = document.getElementById('createDate');
        var slotSelect = document.getElementById('createSlot');
        var lastMode = '';
        var prefillApplied = false;

        if (dateInput && !dateInput.value) {
            dateInput.value = formatDateYmd(new Date());
        }

        function applyMode() {
            var mode = getCreateMode();
            document.querySelectorAll('[data-create-block]').forEach(function (el) {
                var block = String(el.getAttribute('data-create-block') || '');
                el.classList.toggle('admin-bookings-section-hidden', block !== mode && block !== 'booking');
            });

            var bookingBlocks = document.querySelectorAll('[data-create-block="booking"]');
            var existingBlock = document.querySelectorAll('[data-create-block="existing"]');
            var newBlock = document.querySelectorAll('[data-create-block="new"]');

            bookingBlocks.forEach(function (el) { el.classList.remove('admin-bookings-section-hidden'); });
            existingBlock.forEach(function (el) { el.classList.toggle('admin-bookings-section-hidden', mode !== 'existing'); });
            newBlock.forEach(function (el) { el.classList.toggle('admin-bookings-section-hidden', mode !== 'new'); });

            if (mode !== lastMode) {
                selectDefaultServiceForCreateMode(mode, serviceSelect);
            }

            lastMode = mode;

            refreshCreateSlots();

            refreshPackageOptions();

            if (!prefillApplied && prefill && typeof prefill === 'object') {
                prefillApplied = true;
                applyCreatePrefill(prefill);
            }
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', applyMode);
        });

        if (serviceSelect) {
            serviceSelect.addEventListener('change', function () {
                var packageSelect = document.getElementById('createPackage');
                if (packageSelect) {
                    packageSelect.value = '';
                }
                refreshCreateSlots();
                refreshPackageOptions();
            });
        }

        if (dateInput) {
            dateInput.addEventListener('change', refreshCreateSlots);
        }

        applyMode();
    }

    function applyCreatePrefill(prefill) {
        var dateInput = document.getElementById('createDate');
        var slotSelect = document.getElementById('createSlot');
        if (!dateInput || !slotSelect) {
            return;
        }

        var parsed = parseCreatePrefill(prefill);
        if (!parsed) {
            return;
        }

        dateInput.value = parsed.date;

        refreshCreateSlots().then(function () {
            var targetValue = parsed.date + 'T' + parsed.time + ':00';
            var exactOption = null;
            var fallbackOption = null;

            Array.prototype.forEach.call(slotSelect.options || [], function (option) {
                if (!option || !option.value) {
                    return;
                }

                if (option.value === targetValue) {
                    exactOption = option;
                }

                if (!fallbackOption && option.value.indexOf(parsed.date + 'T' + parsed.time) === 0) {
                    fallbackOption = option;
                }
            });

            var selected = exactOption || fallbackOption;
            if (selected && !selected.disabled) {
                slotSelect.value = selected.value;
            }
        });
    }

    function parseCreatePrefill(prefill) {
        var raw = '';
        if (prefill && typeof prefill === 'object') {
            raw = String(prefill.startedAt || prefill.started_at || prefill.slot || '').trim();
        } else if (typeof prefill === 'string') {
            raw = prefill.trim();
        }

        if (raw === '') {
            return null;
        }

        var date = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return null;
        }

        var time = String(raw.replace(' ', 'T').slice(11, 16) || '').trim();
        if (!/^\d{2}:\d{2}$/.test(time)) {
            return null;
        }

        return {
            date: formatDateYmd(date),
            time: time
        };
    }

    function refreshPackageOptions() {
        var serviceSelect = document.getElementById('createService');
        var packageSelect = document.getElementById('createPackage');

        if (!serviceSelect || !packageSelect) {
            return;
        }

        var serviceId = parsePositiveInt(serviceSelect.value, 0);
        var lastServiceId = parsePositiveInt(packageSelect.getAttribute('data-active-service-id') || '', 0);
        var current = String(packageSelect.value || '');
        var hasCurrent = false;

        if (serviceId !== lastServiceId) {
            packageSelect.value = '';
            current = '';
            packageSelect.setAttribute('data-active-service-id', String(serviceId || ''));
        }

        Array.prototype.forEach.call(packageSelect.options || [], function (option) {
            var value = String(option.value || '');
            if (value === '') {
                option.hidden = false;
                return;
            }

            var optionServiceId = parsePositiveInt(option.getAttribute('data-service-id') || '', 0);
            var visible = serviceId > 0 && optionServiceId === serviceId;
            option.hidden = !visible;
            if (visible && value === current) {
                hasCurrent = true;
            }
        });

        if (!hasCurrent) {
            packageSelect.value = '';
        }
    }

    function getCreateMode() {
        var checked = document.querySelector('input[name="booking_mode"]:checked');
        return checked ? String(checked.value || 'existing') : 'existing';
    }

    function normalizeServiceText(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/ä/g, 'ae')
            .replace(/ö/g, 'oe')
            .replace(/ü/g, 'ue')
            .replace(/ß/g, 'ss');
    }

    function selectDefaultServiceForCreateMode(mode, serviceSelect) {
        if (!serviceSelect || !serviceSelect.options || serviceSelect.options.length === 0) {
            return;
        }

        var targetIndex = -1;
        for (var i = 0; i < serviceSelect.options.length; i++) {
            var option = serviceSelect.options[i];
            if (!option || !option.value) {
                continue;
            }

            var slug = normalizeServiceText(option.getAttribute('data-slug') || '');
            var name = normalizeServiceText(option.getAttribute('data-name') || option.text || '');
            var duration = parsePositiveInt(option.getAttribute('data-duration') || '', 0);

            if (mode === 'existing') {
                if (slug.indexOf('einzelbegleitung-60') !== -1 || slug.indexOf('einzelstunde-60') !== -1) {
                    targetIndex = i;
                    break;
                }
                if ((name.indexOf('einzelstunde') !== -1 || name.indexOf('einzelbegleitung') !== -1) && duration === 60) {
                    targetIndex = i;
                }
            }

            if (mode === 'new') {
                if (slug.indexOf('kennenlerngespraech') !== -1 || name.indexOf('kennenlerngespraech') !== -1) {
                    targetIndex = i;
                    break;
                }
            }
        }

        if (targetIndex >= 0) {
            serviceSelect.selectedIndex = targetIndex;
        }
    }

    function refreshCreateSlots() {
        var serviceSelect = document.getElementById('createService');
        var dateInput = document.getElementById('createDate');
        var slotSelect = document.getElementById('createSlot');

        if (!serviceSelect || !dateInput || !slotSelect) {
            return Promise.resolve();
        }

        var option = serviceSelect.options[serviceSelect.selectedIndex];
        var serviceSlug = option ? String(option.getAttribute('data-slug') || '') : '';
        var dateValue = String(dateInput.value || '');

        if (serviceSlug === '' || dateValue === '') {
            slotSelect.innerHTML = '<option value="">Bitte Service und Datum wählen</option>';
            return Promise.resolve();
        }

        slotSelect.innerHTML = '<option value="">Lade freie Slots ...</option>';

        var params = new URLSearchParams({
            service_slug: serviceSlug,
            from: dateValue + 'T00:00:00',
            to: nextDateYmd(dateValue) + 'T00:00:00',
            timezone: 'Europe/Berlin'
        });

        return fetchJson(apiUrl(cfg.api && cfg.api.slots) + '?' + params.toString()).then(function (result) {
            var slots = result.json && result.json.data && Array.isArray(result.json.data.slots) ? result.json.data.slots : [];

            var availableTimes = {};
            slots.forEach(function (slot) {
                var start = String(slot.start || '');
                if (start.length < 16) {
                    return;
                }

                var ymd = start.slice(0, 10);
                var hhmm = start.slice(11, 16);
                if (ymd === dateValue) {
                    availableTimes[hhmm] = true;
                }
            });

            var candidates = buildSlotCandidatesForDay(dateValue);
            if (candidates.length === 0) {
                slotSelect.innerHTML = '<option value="">Keine freien Slots gefunden</option>';
                return;
            }

            var hasAnyAvailable = false;
            slotSelect.innerHTML = '<option value="">Zeitslot wählen ...</option>';
            candidates.forEach(function (time) {
                var dt = dateValue + 'T' + time + ':00';
                var isAvailable = !!availableTimes[time];
                var label = isAvailable ? formatDateTime(dt) : (formatDateTime(dt) + ' (nicht verfügbar)');
                var optionHtml = '<option value="' + escapeHtml(dt) + '"' + (isAvailable ? '' : ' disabled') + '>' + escapeHtml(label) + '</option>';
                slotSelect.innerHTML += optionHtml;

                if (isAvailable) {
                    hasAnyAvailable = true;
                }
            });

            if (!hasAnyAvailable) {
                slotSelect.innerHTML = '<option value="">Keine freien Slots gefunden</option>';
            }
        }).catch(function () {
            slotSelect.innerHTML = '<option value="">Slots konnten nicht geladen werden</option>';
        });
    }

    function submitCreateModal() {
        var mode = getCreateMode();

        var serviceSelect = document.getElementById('createService');
        var dateInput = document.getElementById('createDate');
        var slotSelect = document.getElementById('createSlot');
        var packageSelect = document.getElementById('createPackage');
        var notes = document.getElementById('createNotes');
        var payload = {};

        if (!serviceSelect || !dateInput || !slotSelect) {
            return;
        }

        var serviceId = parsePositiveInt(serviceSelect.value, 0);
        var startedAt = String(slotSelect.value || '');
        if (serviceId <= 0) {
            if (window.adminShowNotification) {
                window.adminShowNotification('warning', 'Bitte einen Service wählen.');
            }
            return;
        }

        if (startedAt === '') {
            if (window.adminShowNotification) {
                window.adminShowNotification('warning', 'Bitte einen freien Slot wählen.');
            }
            return;
        }

        payload.service_id = serviceId;
        var packageId = packageSelect ? parsePositiveInt(packageSelect.value, 0) : 0;
        if (packageId > 0) {
            payload.package_id = packageId;
        }
        payload.started_at = startedAt;
        payload.notes = notes ? trim(notes.value) : '';

        if (mode === 'existing') {
            var clientId = document.getElementById('createClientId');
            var selectedClientId = clientId ? parsePositiveInt(clientId.value, 0) : 0;
            if (selectedClientId <= 0) {
                if (window.adminShowNotification) {
                    window.adminShowNotification('warning', 'Bitte einen bestehenden Client wählen.');
                }
                return;
            }
            payload.client_id = selectedClientId;
        } else {
            var firstName = document.getElementById('createFirstName');
            var lastName = document.getElementById('createLastName');
            var email = document.getElementById('createEmail');
            var phone = document.getElementById('createPhone');
            var dateOfBirth = document.getElementById('createDateOfBirth');

            payload.first_name = firstName ? trim(firstName.value) : '';
            payload.last_name = lastName ? trim(lastName.value) : '';
            payload.email = email ? trim(email.value) : '';
            payload.phone = phone ? trim(phone.value) : '';
            payload.date_of_birth = dateOfBirth ? trim(dateOfBirth.value) : '';

            if (payload.date_of_birth === '') {
                if (window.adminShowNotification) {
                    window.adminShowNotification('warning', 'Bitte ein Geburtsdatum für den neuen Client angeben.');
                }
                return;
            }
        }

        fetchJson(apiUrl(cfg.api && cfg.api.create), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(handleCreateResponse('Buchung wurde gespeichert.'));
    }

    function handleCreateResponse(successMessage) {
        return function (result) {
            if (!result.ok) {
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', buildApiErrorToastMessage(result, 'Die Anlage konnte nicht gespeichert werden.'));
                }
                return;
            }

            if (window.adminShowNotification) {
                window.adminShowNotification('success', successMessage);
            }

            if (typeof window.adminDashboardRefresh === 'function') {
                window.adminDashboardRefresh();
            }

            fetchList();
            fetchAlertSummary();
            window.adminCloseModal && window.adminCloseModal();
        };
    }

})();
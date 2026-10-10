(function () {
    'use strict';

    var cfg = window.__ADMIN_APPOINTMENTS_CONFIG || {};
    var root = document.getElementById('adminAppointmentsRoot');

    if (!root) {
        return;
    }

    var canView = !!cfg.can_view_appointments;
    var canManage = !!cfg.can_manage_appointments;
    var canStorno = !!cfg.can_storno_appointments;

    var state = {
        isLoading: false,
        isDetailLoading: false,
        page: parsePositiveInt(cfg.default_page, 1),
        perPage: parsePositiveInt(cfg.per_page, 10),
        sort: String(cfg.default_sort || 'scheduled_at'),
        direction: String(cfg.default_direction || 'asc'),
        query: '',
        status: '',
        dateFrom: defaultDateFilter(-1),
        dateTo: defaultDateFilter(3),
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

    function defaultDateFilter(monthOffset) {
        var date = new Date();
        date.setHours(12, 0, 0, 0);
        date.setMonth(date.getMonth() + monthOffset);

        return date.getFullYear() + '-' +
            String(date.getMonth() + 1).padStart(2, '0') + '-' +
            String(date.getDate()).padStart(2, '0');
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

        var hasDateFrom = qs.has('date_from');
        var hasDateTo = qs.has('date_to');
        if (hasDateFrom) {
            state.dateFrom = String(qs.get('date_from') || '').trim();
        }
        if (hasDateTo) {
            state.dateTo = String(qs.get('date_to') || '').trim();
        }
        if (hasDateFrom && !hasDateTo) {
            state.dateTo = '';
        } else if (!hasDateFrom && hasDateTo) {
            state.dateFrom = '';
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
        if (state.dateFrom !== '') {
            qs.set('date_from', state.dateFrom);
        }
        if (state.dateTo !== '') {
            qs.set('date_to', state.dateTo);
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
        if (state.dateFrom !== '') {
            params.set('date_from', state.dateFrom);
        }
        if (state.dateTo !== '') {
            params.set('date_to', state.dateTo);
        }

        fetch(apiUrl(cfg.api && cfg.api.list) + '?' + params.toString(), {
            credentials: 'include',
            headers: { Accept: 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var data = json && json.data ? json.data : {};
                state.items = sortCurrentPageAppointments(Array.isArray(data.appointments) ? data.appointments : []);
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

    function sortCurrentPageAppointments(items) {
        return items
            .map(function (item, index) {
                return { item: item, index: index };
            })
            .sort(function (left, right) {
                var leftStatus = String(left.item && left.item.status || '').toLowerCase();
                var rightStatus = String(right.item && right.item.status || '').toLowerCase();
                var leftPriority = appointmentStatusPriority(leftStatus);
                var rightPriority = appointmentStatusPriority(rightStatus);

                if (leftPriority !== rightPriority) {
                    return leftPriority - rightPriority;
                }

                var leftDate = appointmentTimestamp(left.item && left.item.scheduled_at);
                var rightDate = appointmentTimestamp(right.item && right.item.scheduled_at);
                if (leftDate !== rightDate) {
                    return leftDate - rightDate;
                }

                return left.index - right.index;
            })
            .map(function (entry) {
                return entry.item;
            });
    }

    function appointmentStatusPriority(status) {
        if (status === 'pending' || status === 'open' || status === 'new') {
            return 0;
        }

        return status === 'completed' ? 2 : 1;
    }

    function appointmentTimestamp(value) {
        var parsed = new Date(String(value || '').replace(' ', 'T')).getTime();
        return Number.isNaN(parsed) ? Number.MAX_SAFE_INTEGER : parsed;
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

        function parsePromptDateTime(value) {
            var input = String(value || '').trim();
            if (input === '') {
                return null;
            }

            var normalized = input.replace('T', ' ').replace(/\s+/g, ' ');
            var match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})$/);
            if (!match) {
                return null;
            }

            var year = parseInt(match[1], 10);
            var month = parseInt(match[2], 10) - 1;
            var day = parseInt(match[3], 10);
            var hour = parseInt(match[4], 10);
            var minute = parseInt(match[5], 10);
            var date = new Date(year, month, day, hour, minute, 0, 0);

            if (date.getFullYear() !== year || date.getMonth() !== month || date.getDate() !== day || date.getHours() !== hour || date.getMinutes() !== minute) {
                return null;
            }

            return year + '-' + match[2] + '-' + match[3] + ' ' + match[4] + ':' + match[5] + ':00';
        }

        function toInputDateTimeParts(value) {
            var normalized = normalizeDisplayDateTime(value);
            var match = normalized.match(/^(\d{4}-\d{2}-\d{2})\s(\d{2}:\d{2})$/);
            if (!match) {
                var now = new Date();
                return {
                    date: now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0'),
                    time: String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0')
                };
            }

            return { date: match[1], time: match[2] };
        }

        function normalizeDisplayDateTime(value) {
            var raw = String(value || '').trim();
            if (raw === '') {
                return '';
            }

            var match = raw.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/);
            if (match) {
                return match[1] + ' ' + match[2];
            }

            var parsed = new Date(raw.replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) {
                return raw;
            }

            return parsed.getFullYear() + '-' + String(parsed.getMonth() + 1).padStart(2, '0') + '-' + String(parsed.getDate()).padStart(2, '0') + ' ' + String(parsed.getHours()).padStart(2, '0') + ':' + String(parsed.getMinutes()).padStart(2, '0');
        }

        function hasLocalAppointmentConflict(id, startedAt, durationMinutes) {
            var newStart = new Date(String(startedAt || '').replace(' ', 'T'));
            if (Number.isNaN(newStart.getTime())) {
                return false;
            }

            var newEnd = new Date(newStart.getTime() + (parsePositiveInt(durationMinutes, 60) * 60000));

            for (var i = 0; i < state.items.length; i += 1) {
                var item = state.items[i];
                if (!item || Number(item.id) === Number(id)) {
                    continue;
                }

                var currentStart = new Date(String(item.scheduled_at || '').replace(' ', 'T'));
                if (Number.isNaN(currentStart.getTime())) {
                    continue;
                }

                var currentDuration = parsePositiveInt(item.duration_minutes, durationMinutes);
                var currentEnd = new Date(currentStart.getTime() + (currentDuration * 60000));
                if (newStart < currentEnd && newEnd > currentStart) {
                    return true;
                }
            }

            return false;
        }

        function cancelAppointment(id) {
            if (!canStorno || !id) {
                return;
            }

            var canUseModal = typeof window.adminOpenModal === 'function' && typeof window.adminCloseModal === 'function';
            if (!canUseModal) {
                var fallbackReason = window.prompt('Storno-Grund (optional):', '');
                if (fallbackReason === null) {
                    return;
                }

                submitCancellation(id, String(fallbackReason || '').trim());
                return;
            }

            var body = '' +
                '<div class="admin-appointments-cancel-form">' +
                '  <label for="appointmentCancelReason" class="admin-appointments-hint" style="display:block;margin-bottom:6px;">Stornierungsgrund (optional)</label>' +
                '  <textarea id="appointmentCancelReason" class="admin-appointments-input" rows="4" placeholder="z. B. Terminwunsch geändert"></textarea>' +
                '</div>';

            window.adminOpenModal('Termin stornieren', body, {
                buttons: [
                    {
                        label: 'Abbrechen',
                        onClick: function () {
                            window.adminCloseModal();
                        }
                    },
                    {
                        label: 'Stornieren',
                        onClick: function () {
                            var reasonInput = document.getElementById('appointmentCancelReason');
                            var reason = reasonInput ? String(reasonInput.value || '').trim() : '';
                            submitCancellation(id, reason, true);
                        }
                    }
                ]
            });
        }

        function submitCancellation(id, reason, closeModalOnSuccess) {
            fetch(apiUrl(cfg.api && cfg.api.cancel, id), {
                method: 'PATCH',
                credentials: 'include',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ cancellation_reason: String(reason || '').trim() })
            })
                .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
                .then(function (result) {
                    if (!result.ok) {
                        throw new Error('cancel_failed');
                    }

                    if (closeModalOnSuccess && typeof window.adminCloseModal === 'function') {
                        window.adminCloseModal();
                    }

                    if (window.adminShowNotification) {
                        window.adminShowNotification('success', 'Appointment wurde storniert.');
                    }

                    fetchList();
                })
                .catch(function () {
                    if (window.adminShowNotification) {
                        window.adminShowNotification('error', 'Storno konnte nicht durchgeführt werden.');
                    }
                });
        }

        function rescheduleAppointment(id) {
            if (!canManage || !id || !state.detail) {
                return;
            }

            var canUseModal = typeof window.adminOpenModal === 'function' && typeof window.adminCloseModal === 'function';
            if (!canUseModal) {
                var fallbackValue = normalizeDisplayDateTime(state.detail.scheduled_at || '');
                var promptValue = window.prompt('Neuer Termin (YYYY-MM-DD HH:MM):', fallbackValue);
                if (promptValue === null) {
                    return;
                }

                submitRescheduleFromValue(id, promptValue, parsePositiveInt(state.detail.duration_minutes, 60));
                return;
            }

            var current = toInputDateTimeParts(state.detail.scheduled_at || '');
            var body = '' +
                '<div class="admin-appointments-reschedule-form">' +
                '  <label for="appointmentRescheduleDate" class="admin-appointments-hint" style="display:block;margin-bottom:6px;">Datum</label>' +
                '  <input id="appointmentRescheduleDate" type="date" class="admin-appointments-input" value="' + escapeHtml(current.date) + '" />' +
                '  <label for="appointmentRescheduleTime" class="admin-appointments-hint" style="display:block;margin:12px 0 6px;">Uhrzeit</label>' +
                '  <input id="appointmentRescheduleTime" type="time" step="1800" class="admin-appointments-input" value="' + escapeHtml(current.time) + '" />' +
                '</div>';

            window.adminOpenModal('Termin umbuchen', body, {
                buttons: [
                    {
                        label: 'Abbrechen',
                        onClick: function () {
                            window.adminCloseModal();
                        }
                    },
                    {
                        label: 'Umbuchen',
                        onClick: function () {
                            var dateInput = document.getElementById('appointmentRescheduleDate');
                            var timeInput = document.getElementById('appointmentRescheduleTime');
                            var dateValue = dateInput ? String(dateInput.value || '').trim() : '';
                            var timeValue = timeInput ? String(timeInput.value || '').trim() : '';

                            if (dateValue === '' || timeValue === '') {
                                if (window.adminShowNotification) {
                                    window.adminShowNotification('error', 'Bitte Datum und Uhrzeit auswählen.');
                                }
                                return;
                            }

                            submitRescheduleFromValue(id, dateValue + ' ' + timeValue, parsePositiveInt(state.detail.duration_minutes, 60), true);
                        }
                    }
                ]
            });
        }

        function submitRescheduleFromValue(id, nextValue, durationMinutes, closeModalOnSuccess) {
            var parsed = parsePromptDateTime(nextValue);
            if (!parsed) {
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Bitte ein gültiges Datum im Format YYYY-MM-DD HH:MM eingeben.');
                }
                return;
            }

            if (hasLocalAppointmentConflict(id, parsed, durationMinutes)) {
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Der neue Termin kollidiert mit einem bestehenden Termin.');
                }
                return;
            }

            fetch(apiUrl(cfg.api && cfg.api.reschedule, id), {
                method: 'PATCH',
                credentials: 'include',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    started_at: parsed,
                    duration_minutes: durationMinutes
                })
            })
                .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
                .then(function (result) {
                    if (!result.ok) {
                        throw new Error('reschedule_failed');
                    }

                    if (closeModalOnSuccess && typeof window.adminCloseModal === 'function') {
                        window.adminCloseModal();
                    }

                    if (window.adminShowNotification) {
                        window.adminShowNotification('success', 'Appointment wurde umgebucht.');
                    }

                    fetchList();
                })
                .catch(function () {
                    if (window.adminShowNotification) {
                        window.adminShowNotification('error', 'Umbuchung konnte nicht durchgeführt werden.');
                    }
                });
        }

            function markNoShow(id) {
                if (!canManage || !id) {
                    return;
                }

                fetch(apiUrl(cfg.api && cfg.api.update, id), {
                    method: 'PATCH',
                    credentials: 'include',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ status: 'no_show' })
                })
                    .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
                    .then(function (result) {
                        if (!result.ok) {
                            throw new Error('no_show_failed');
                        }

                        if (window.adminShowNotification) {
                            window.adminShowNotification('success', 'Termin als No-Show markiert.');
                        }

                        fetchList();
                    })
                    .catch(function () {
                        if (window.adminShowNotification) {
                            window.adminShowNotification('error', 'No-Show konnte nicht gesetzt werden.');
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

    function parseNotes(value) {
        var raw = String(value || '').trim();
        if (raw === '') return '-';

        if (raw[0] !== '{' && raw[0] !== '[') {
            return raw;
        }

        var parsed;
        try {
            parsed = JSON.parse(raw);
        } catch (_err) {
            return raw;
        }

        var message = extractPreferredMessage(parsed);
        if (message !== '') {
            return message;
        }

        return 'Kontaktformular-Anfrage';
    }

    function extractPreferredMessage(node) {
        if (!node || typeof node !== 'object') {
            return '';
        }

        var preferredKeys = ['message', 'update_details', 'steps', 'details', 'note', 'notes'];
        for (var i = 0; i < preferredKeys.length; i += 1) {
            var key = preferredKeys[i];
            if (typeof node[key] === 'string' && node[key].trim() !== '') {
                return node[key].trim();
            }
        }

        var keys = Object.keys(node);
        for (var j = 0; j < keys.length; j += 1) {
            var nested = node[keys[j]];
            if (nested && typeof nested === 'object') {
                var nestedMessage = extractPreferredMessage(nested);
                if (nestedMessage !== '') {
                    return nestedMessage;
                }
            }
        }

        return '';
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
            '        <label class="admin-appointments-hint">Von <input name="date_from" type="date" class="admin-appointments-input" value="' + escapeHtml(state.dateFrom) + '" /></label>' +
            '        <label class="admin-appointments-hint">Bis <input name="date_to" type="date" class="admin-appointments-input" value="' + escapeHtml(state.dateTo) + '" /></label>' +
            '        <select name="status" class="admin-appointments-select">' +
            '          <option value="">Alle Stati</option>' +
            '          <option value="pending"' + (state.status === 'pending' ? ' selected' : '') + '>Ausstehend</option>' +
            '          <option value="accepted"' + (state.status === 'accepted' ? ' selected' : '') + '>Angenommen</option>' +
            '          <option value="declined"' + (state.status === 'declined' ? ' selected' : '') + '>Abgelehnt</option>' +
            '          <option value="completed"' + (state.status === 'completed' ? ' selected' : '') + '>Abgeschlossen</option>' +
            '          <option value="no_show"' + (state.status === 'no_show' ? ' selected' : '') + '>No-Show</option>' +
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
        var notes = parseNotes(item.notes);
        var status = String(item.status || '').toLowerCase();
        var canAct = canManage && status === 'pending';
        var canReschedule = canManage && (status === 'pending' || status === 'accepted');
        var canCancel = canStorno && status === 'accepted';
        var canNoShow = canManage && status === 'completed';

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
                        ((canAct || canReschedule || canCancel || canNoShow)
                                ? '<div class="admin-appointments-actions">' +
                                        (canAct ? '<button type="button" class="admin-appointments-btn admin-appointments-btn--accept" data-status="accepted">Annehmen</button>' : '') +
                                        (canAct ? '<button type="button" class="admin-appointments-btn admin-appointments-btn--decline" data-status="declined">Ablehnen</button>' : '') +
                                        (canReschedule ? '<button type="button" class="admin-appointments-btn" data-action="reschedule">Umbuchen</button>' : '') +
                                        (canCancel ? '<button type="button" class="admin-appointments-btn admin-appointments-btn--danger" data-action="cancel">Stornieren</button>' : '') +
                                        (canNoShow ? '<button type="button" class="admin-appointments-btn admin-appointments-btn--decline" data-action="no-show">No-Show markieren</button>' : '') +
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
                state.dateFrom = String(fd.get('date_from') || '').trim();
                state.dateTo = String(fd.get('date_to') || '').trim();
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

        var rescheduleBtn = root.querySelector('[data-action="reschedule"]');
        if (rescheduleBtn) {
            rescheduleBtn.addEventListener('click', function () {
                if (state.detail && state.detail.id) {
                    rescheduleAppointment(Number(state.detail.id));
                }
            });
        }

        var cancelBtn = root.querySelector('[data-action="cancel"]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                if (state.detail && state.detail.id) {
                    cancelAppointment(Number(state.detail.id));
                }
            });
        }

        var noShowBtn = root.querySelector('[data-action="no-show"]');
        if (noShowBtn) {
            noShowBtn.addEventListener('click', function () {
                if (state.detail && state.detail.id) {
                    markNoShow(Number(state.detail.id));
                }
            });
        }
    }
})();

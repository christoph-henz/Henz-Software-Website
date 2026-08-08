/**
 * admin-dashboard.js - D-007 custom calendar dashboard.
 */
(function () {
    'use strict';

    var cfg = window.__ADMIN_CALENDAR_CONFIG || window.__ADMIN_DASHBOARD_CONFIG || {};
    var canViewBookings = !!cfg.can_view_appointments;
    var canViewKpi = !!cfg.can_view_kpi;
    var canManageBookings = !!cfg.can_manage_appointments;

    function formatStatusLabel(status) {
        var labels = cfg.status_labels || {};
        return String(labels[String(status)] || status);
    }

    function paymentStatusLabel(status) {
        var labels = cfg.payment_status_labels || {};
        var key = String(status || 'pending');
        return labels[key] || key;
    }

    var calendarRoot = document.getElementById('adminDashboardCalendarRoot');
    var kpiRoot = document.getElementById('adminDashboardKpiRoot');

    if (!calendarRoot && !kpiRoot) {
        return;
    }

    window.adminCalendarRefresh = fetchBookings;
    window.adminDashboardRefresh = fetchBookings;

    var state = {
        view: cfg.default_view || 'week',
        month: formatMonth(new Date()),
        week: formatDateYmd(startOfWeekDate(new Date())),
        page: parsePositiveInt(cfg.default_page, 1),
        perPage: parsePositiveInt(cfg.per_page, 100),
        window: {
            startHour: parsePositiveInt(cfg.opening_start_hour, 9),
            endHour: parsePositiveInt(cfg.opening_end_hour, 18),
            slotStepMinutes: parsePositiveInt(cfg.slot_step_minutes, 30)
        },
        totalPages: 1,
        weekBodyHeight: 0,
        bookings: [],
        blockedSlots: [],
        selectedBooking: null,
        selectedBookingAnchorTop: null,
        selectedKpi: 'revenue'
    };
    var createMetaCache = null;

    hydrateStateFromQuery();
    window.adminAppointmentsOpenCreateModal = openCreateAppointmentModal;

    function hydrateStateFromQuery() {
        var qs = new URLSearchParams(window.location.search);
        var queryView = qs.get('view');
        var queryMonth = qs.get('month');
        var queryWeek = qs.get('week');
        var queryPage = qs.get('page');

        if ((cfg.allowed_views || []).indexOf(queryView) !== -1) {
            state.view = queryView;
        }

        if (/^\d{4}-\d{2}$/.test(String(queryMonth || ''))) {
            state.month = queryMonth;
        }

        if (/^\d{4}-\d{2}-\d{2}$/.test(String(queryWeek || ''))) {
            var weekDate = new Date(queryWeek + 'T00:00:00');
            if (!Number.isNaN(weekDate.getTime())) {
                var weekStart = startOfWeekDate(weekDate);
                state.week = formatDateYmd(weekStart);
                state.month = formatMonth(weekStart);
            }
        }

        if (/^\d+$/.test(String(queryPage || ''))) {
            state.page = Math.max(1, parseInt(queryPage, 10));
        }
    }

    function writeStateToQuery() {
        var qs = new URLSearchParams(window.location.search);
        qs.set('view', state.view);
        qs.set('month', state.month);
        qs.set('week', state.week);
        qs.set('page', String(state.page));
        var nextUrl = window.location.pathname + '?' + qs.toString();
        window.history.replaceState(null, '', nextUrl);
    }

    function parsePositiveInt(value, fallback) {
        var n = parseInt(String(value || ''), 10);
        return Number.isFinite(n) && n > 0 ? n : fallback;
    }

    function formatMonth(date) {
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        return y + '-' + m;
    }

    function formatDateYmd(date) {
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function startOfWeekDate(date) {
        var d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        var day = d.getDay();
        var shift = (day + 6) % 7;
        d.setDate(d.getDate() - shift);
        d.setHours(0, 0, 0, 0);
        return d;
    }

    function monthLabel(monthStr) {
        var d = new Date(monthStr + '-01T00:00:00');
        return d.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });
    }

    function weekLabel(weekStartStr) {
        var start = new Date(weekStartStr + 'T00:00:00');
        if (Number.isNaN(start.getTime())) {
            return '';
        }
        var end = new Date(start);
        end.setDate(start.getDate() + 6);
        var left = start.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
        var right = end.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
        return left + ' - ' + right;
    }

    function toDateSafe(dateStr) {
        var iso = String(dateStr || '').replace(' ', 'T');
        var d = new Date(iso);
        return Number.isNaN(d.getTime()) ? null : d;
    }

    function statusClass(booking) {
        var s = String((booking && booking.status) || 'pending');
        if (s === 'completed') return 'admin-calendar-event--completed';
        if (s === 'accepted') return 'admin-calendar-event--accepted';
        if (s === 'declined') return 'admin-calendar-event--declined';
        if (s === 'storno') return 'admin-calendar-event--storno';
        if (String((booking && booking.payment_status) || '') === 'paid') return 'admin-calendar-event--paid';
        return 'admin-calendar-event--pending';
    }

    function fetchBookings() {
        if (!canViewBookings) {
            state.bookings = [];
            state.blockedSlots = [];
            renderAll();
            return Promise.resolve();
        }

        var params = new URLSearchParams({
            per_page: '999',
            sort: cfg.default_sort || 'scheduled_at',
            direction: cfg.default_direction || 'asc'
        });

        var bookingsFetch = fetch((cfg.api && cfg.api.appointments ? cfg.api.appointments : '/appointments/data') + '?' + params.toString(), {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var data = json && json.data ? json.data : {};
                state.bookings = Array.isArray(data.appointments) ? data.appointments : [];
                state.totalPages = parsePositiveInt(data.meta && data.meta.total_pages, 1);
                if (data.meta && data.meta.window) {
                    state.window.startHour = parsePositiveInt(data.meta.window.start_hour, state.window.startHour);
                    state.window.endHour = parsePositiveInt(data.meta.window.end_hour, state.window.endHour);
                    state.window.slotStepMinutes = parsePositiveInt(data.meta.window.slot_step_minutes, state.window.slotStepMinutes);
                }
            })
            .catch(function () {
                state.bookings = [];
                state.totalPages = 1;
            });

        var blockedFetch = (cfg.api && cfg.api.blocked_slots)
            ? fetch(cfg.api.blocked_slots, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
              })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    var data = json && json.data ? json.data : {};
                    state.blockedSlots = Array.isArray(data.blocked_slots) ? data.blocked_slots : [];
                })
                .catch(function () { state.blockedSlots = []; })
            : Promise.resolve();

        return Promise.all([bookingsFetch, blockedFetch]).then(function () {
            renderAll();
        });
    }

    function renderAll() {
        renderCalendar();
        renderKpis();
    }

    function renderCalendar() {
        if (!calendarRoot) return;

        if (!canViewBookings) {
            calendarRoot.innerHTML = '<div class="admin-dashboard-empty"></div>';
            return;
        }

        if (state.view !== 'week') {
            state.selectedBookingAnchorTop = null;
            state.weekBodyHeight = 0;
        }

        var viewHtml = state.view === 'week' ? buildWeekHtml() : buildMonthHtml();
        var detailsClass = 'admin-calendar-details' + (state.view === 'week' ? ' admin-calendar-details--week' : '');
        var detailsStyle = state.view === 'week' && state.weekBodyHeight > 0
            ? ' style="min-height:' + state.weekBodyHeight + 'px"'
            : '';

        calendarRoot.innerHTML =
            '<div class="admin-calendar-shell">' +
            '  <div class="admin-calendar-toolbar">' +
            '    <div class="admin-calendar-toolbar-group">' +
            '      <button type="button" class="admin-calendar-btn" data-cal-prev>Zurück</button>' +
            '      <button type="button" class="admin-calendar-btn" data-cal-next>Weiter</button>' +
            '      <button type="button" class="admin-calendar-btn" data-cal-today>Heute</button>' +
            (canManageBookings ? '      <button type="button" class="admin-calendar-btn" data-cal-create>Neuer Termin</button>' : '') +
            '    </div>' +
            '    <h3 class="admin-calendar-title">' + escapeHtml(state.view === 'week' ? weekLabel(state.week) : monthLabel(state.month)) + '</h3>' +
            '    <div class="admin-calendar-toolbar-group">' +
            '      <label class="sr-only" for="adminCalendarView">Ansicht</label>' +
            '      <select id="adminCalendarView" class="admin-calendar-select" data-cal-view>' +
            '        <option value="month"' + (state.view === 'month' ? ' selected' : '') + '>Monat</option>' +
            '        <option value="week"' + (state.view === 'week' ? ' selected' : '') + '>Woche</option>' +
            '      </select>' +
            '    </div>' +
            '  </div>' +
            '  <div class="admin-calendar-layout">' +
            '    <div class="admin-calendar-view">' + viewHtml + '</div>' +
            '    <aside class="' + detailsClass + '"' + detailsStyle + '><div class="admin-calendar-details-card" id="adminCalendarDetailsCard">' + renderBookingDetailsHtml() + '</div></aside>' +
            '  </div>' +
            '</div>';

        bindCalendarEvents();
        alignDetailsCardToSelection();
    }

    function buildMonthHtml() {
        var monthStart = new Date(state.month + '-01T00:00:00');
        var year = monthStart.getFullYear();
        var monthIndex = monthStart.getMonth();

        var first = new Date(year, monthIndex, 1);
        var last = new Date(year, monthIndex + 1, 0);
        var startOffset = (first.getDay() + 6) % 7;
        var daysInMonth = last.getDate();
        var monthDays = [];
        for (var dayNum = 1; dayNum <= daysInMonth; dayNum++) {
            monthDays.push(new Date(year, monthIndex, dayNum));
        }

        var dow = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        var html = '<div class="admin-calendar-month">';

        dow.forEach(function (day) {
            html += '<div class="admin-calendar-dow">' + day + '</div>';
        });

        for (var i = 0; i < startOffset; i++) {
            html += '<div class="admin-calendar-day is-other"></div>';
        }

        monthDays.forEach(function (day) {
            var dayNum = day.getDate();
            var dateKey = formatDateYmd(day);
            var bookings = bookingsForDay(dateKey);
            var blocked = blockedSlotsForDay(dateKey);
            var dayClass = 'admin-calendar-day' + (isWeekendDate(day) ? ' is-weekend' : '');
            html += '<div class="' + dayClass + '">';
            html += '<div class="admin-calendar-day-number">' + dayNum + '</div>';
            bookings.slice(0, 4).forEach(function (booking) {
                html += renderEventChip(booking);
            });
            if (bookings.length > 4) {
                html += '<div class="admin-calendar-event">+' + (bookings.length - 4) + ' weitere</div>';
            }
            blocked.forEach(function (slot) {
                html += renderBlockedChip(slot, dateKey);
            });
            html += '</div>';
        });

        html += '</div>';
        return html;
    }

    function buildWeekHtml() {
        var monday = new Date(state.week + 'T00:00:00');
        if (Number.isNaN(monday.getTime())) {
            monday = startOfWeekDate(new Date());
            state.week = formatDateYmd(monday);
            state.month = formatMonth(monday);
        }

        var days = [];
        for (var i = 0; i < 7; i++) {
            var d = new Date(monday);
            d.setDate(monday.getDate() + i);
            days.push(d);
        }

        var showWeekend = days.some(function (day) {
            var dk = formatDateYmd(day);
            return isWeekendDate(day) && (bookingsForDay(dk).length > 0 || blockedSlotsForDay(dk).length > 0);
        });

        var visibleDays = days.filter(function (day) {
            return showWeekend || !isWeekendDate(day);
        });

        var slotStep = Math.max(5, state.window.slotStepMinutes || 30);
        var range = resolveWeekTimeRange(visibleDays, slotStep);
        var slots = buildTimeSlots(range.startHour, range.endHour, slotStep);
        var slotHeight = 42;
        var slotInset = 4;
        var bodyHeight = slots.length * slotHeight;
        state.weekBodyHeight = bodyHeight;

        var html = '<div class="admin-week-board admin-week-board--' + visibleDays.length + '">';
        html += '  <div class="admin-week-axis-head"></div>';
        html += '  <div class="admin-week-days-head">';
        visibleDays.forEach(function (day) {
            var dowShort = day.toLocaleDateString('de-DE', { weekday: 'short' });
            var dateShort = day.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
            html += '<div class="admin-week-head">' + escapeHtml(dowShort + ' ' + dateShort) + '</div>';
        });
        html += '  </div>';

        html += '  <div class="admin-week-axis" style="height:' + bodyHeight + 'px">';
        slots.forEach(function (slot, idx) {
            html += '<div class="admin-week-time" style="top:' + ((idx * slotHeight) + slotInset) + 'px">' + slot + '</div>';
        });
        html += '  </div>';

        html += '  <div class="admin-week-days">';
        visibleDays.forEach(function (day) {
            var dateKey = formatDateYmd(day);
            html += '<div class="admin-week-day-col">';
            html += '  <div class="admin-week-day-body" style="height:' + bodyHeight + 'px">';

            slots.forEach(function (_, idx) {
                html += '<div class="admin-week-slot-line" style="top:' + ((idx * slotHeight) + slotInset) + 'px"></div>';
            });

            if (canManageBookings) {
                slots.forEach(function (slotTime, idx) {
                    var topPx = (idx * slotHeight) + slotInset;
                    var slotStart = dateKey + ' ' + slotTime + ':00';
                    var slotLabelDate = day.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
                    html += '<button type="button" class="admin-week-empty-slot" data-slot-start="' + escapeHtml(slotStart) + '" aria-label="Neue Buchung um ' + escapeHtml(slotTime) + ' am ' + escapeHtml(slotLabelDate) + '" style="top:' + topPx + 'px;height:' + Math.max(24, slotHeight - (slotInset * 2)) + 'px"></button>';
                });
            }

            bookingsForDay(dateKey).forEach(function (booking) {
                var startIndex = slotIndexForBooking(booking, range.startHour, slotStep);
                if (startIndex < 0) {
                    return;
                }

                var durationMinutes = Number((booking.service && booking.service.duration_minutes) || 30);
                if (!Number.isFinite(durationMinutes) || durationMinutes <= 0) {
                    durationMinutes = 30;
                }
                var spanSlots = Math.max(1, Math.round(durationMinutes / slotStep));
                var topPx = (startIndex * slotHeight) + slotInset;
                var heightPx = Math.max(26, (spanSlots * slotHeight) - (slotInset * 2));
                html += renderWeekEventBlock(booking, topPx, heightPx, durationMinutes);
            });

            blockedSlotsForDay(dateKey).forEach(function (slot) {
                var segment = blockedSlotSegmentForDay(slot, dateKey);
                if (!segment) {
                    return;
                }
                var startIndex = slotIndexForTime(segment.start_at, range.startHour, slotStep);
                if (startIndex < 0) {
                    return;
                }
                var durationMinutes = Number(segment.duration_minutes || 30);
                var spanSlots = Math.max(1, Math.round(durationMinutes / slotStep));
                var topPx = (startIndex * slotHeight) + slotInset;
                var heightPx = Math.max(26, (spanSlots * slotHeight) - (slotInset * 2));
                html += renderWeekBlockedSlot(slot, segment, topPx, heightPx);
            });

            html += '  </div>';
            html += '</div>';
        });
        html += '  </div>';
        html += '</div>';
        return html;
    }

    function buildTimeSlots(startHour, endHour, stepMinutes) {
        var slots = [];
        for (var hour = startHour; hour <= endHour; hour++) {
            for (var minute = 0; minute < 60; minute += stepMinutes) {
                if (hour === endHour && minute > 0) {
                    continue;
                }
                slots.push(String(hour).padStart(2, '0') + ':' + String(minute).padStart(2, '0'));
            }
        }
        return slots;
    }

    function isWeekendDate(date) {
        var day = date.getDay();
        return day === 0 || day === 6;
    }

    function resolveWeekTimeRange(days, slotStepMinutes) {
        var defaultStartHour = clampHour(state.window.startHour, 9);
        var defaultEndHour = clampHour(state.window.endHour, 18);
        if (defaultEndHour <= defaultStartHour) {
            defaultEndHour = Math.min(24, defaultStartHour + 8);
        }

        var bookingMinMinutes = null;
        var bookingMaxMinutes = null;
        var hasBookings = false;

        days.forEach(function (day) {
            var dateKey = formatDateYmd(day);
            bookingsForDay(dateKey).forEach(function (booking) {
                var dt = toDateSafe(booking.scheduled_at);
                if (!dt) {
                    return;
                }

                hasBookings = true;
                var start = dt.getHours() * 60 + dt.getMinutes();
                var duration = Number((booking.service && booking.service.duration_minutes) || 30);
                if (!Number.isFinite(duration) || duration <= 0) {
                    duration = 30;
                }
                var end = start + duration;

                if (bookingMinMinutes === null || start < bookingMinMinutes) {
                    bookingMinMinutes = start;
                }
                if (bookingMaxMinutes === null || end > bookingMaxMinutes) {
                    bookingMaxMinutes = end;
                }
            });

            blockedSlotsForDay(dateKey).forEach(function (slot) {
                var segment = blockedSlotSegmentForDay(slot, dateKey);
                if (!segment) return;
                hasBookings = true;
                var startDt = toDateSafe(segment.start_at);
                var endDt = toDateSafe(segment.end_at);
                if (!startDt || !endDt) return;
                var start = startDt.getHours() * 60 + startDt.getMinutes();
                var end = endDt.getHours() * 60 + endDt.getMinutes();
                if (end <= start) {
                    end = start + Number(segment.duration_minutes || 30);
                }
                if (bookingMinMinutes === null || start < bookingMinMinutes) bookingMinMinutes = start;
                if (bookingMaxMinutes === null || end > bookingMaxMinutes) bookingMaxMinutes = end;
            });
        });

        if (hasBookings) {
            bookingMinMinutes = Math.floor(bookingMinMinutes / slotStepMinutes) * slotStepMinutes;
            bookingMaxMinutes = Math.ceil(bookingMaxMinutes / slotStepMinutes) * slotStepMinutes;
            var resolvedStart = Math.max(0, Math.floor(bookingMinMinutes / 60));
            var resolvedEnd = Math.min(24, Math.ceil(bookingMaxMinutes / 60));
            return {
                startHour: resolvedStart,
                endHour: resolvedEnd
            };
        }

        return {
            startHour: defaultStartHour,
            endHour: defaultEndHour
        };
    }

    function clampHour(value, fallback) {
        var n = parsePositiveInt(value, fallback);
        if (n < 0) {
            return 0;
        }
        if (n > 24) {
            return 24;
        }
        return n;
    }

    function slotIndexForBooking(booking, startHour, slotStepMinutes) {
        var dt = toDateSafe(booking.scheduled_at);
        if (!dt) {
            return -1;
        }
        var totalMinutes = (dt.getHours() * 60) + dt.getMinutes();
        var startMinutes = startHour * 60;
        return Math.max(0, Math.round((totalMinutes - startMinutes) / slotStepMinutes));
    }

    function renderWeekEventBlock(booking, topPx, heightPx, durationMinutes) {
        var dt = toDateSafe(booking.scheduled_at);
        var time = dt ? dt.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : '--:--';
        var clientName = booking.client && booking.client.name ? booking.client.name : 'Unbekannt';
        var durationLabel = durationMinutes + ' Min.';

        return '' +
            '<div class="admin-week-event ' + statusClass(booking) + '" data-booking-id="' + booking.id + '" data-booking-top="' + Math.round(topPx) + '" style="top:' + topPx + 'px;height:' + heightPx + 'px">' +
            '  <div class="admin-week-event-time">' + escapeHtml(time) + ' (' + escapeHtml(durationLabel) + ')</div>' +
            '  <div class="admin-week-event-client">' + escapeHtml(clientName) + '</div>' +
            '</div>';
    }

    function renderBlockedChip(slot, dateKey) {
        var segment = blockedSlotSegmentForDay(slot, dateKey);
        if (!segment) {
            return '';
        }

        var timeLabel = blockedSegmentLabel(segment);
        return '' +
            '<div class="admin-calendar-event admin-calendar-event--blocked" data-blocked-id="' + slot.id + '">' +
            '  <strong>' + escapeHtml(timeLabel) + '</strong> Sperrzeit' +
            '</div>';
    }

    function renderEventChip(booking) {
        var dt = toDateSafe(booking.scheduled_at);
        var time = dt ? dt.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : '--:--';
        var clientName = booking.client && booking.client.name ? booking.client.name : 'Unbekannt';
        return '' +
            '<div class="admin-calendar-event ' + statusClass(booking) + '" data-booking-id="' + booking.id + '">' +
            '  <strong>' + escapeHtml(time) + '</strong> ' + escapeHtml(clientName) +
            '</div>';
    }

    function blockedSlotsForDay(dateKey) {
        return state.blockedSlots.filter(function (s) {
            return blockedSlotSegmentForDay(s, dateKey) !== null;
        });
    }

    function slotIndexForTime(datetimeStr, startHour, slotStepMinutes) {
        var dt = toDateSafe(datetimeStr);
        if (!dt) return -1;
        var totalMinutes = (dt.getHours() * 60) + dt.getMinutes();
        var startMinutes = startHour * 60;
        return Math.max(0, Math.round((totalMinutes - startMinutes) / slotStepMinutes));
    }

    function renderWeekBlockedSlot(slot, segment, topPx, heightPx) {
        var dt = toDateSafe(segment.start_at);
        var time = dt ? dt.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : '--:--';
        var durationLabel = formatDurationLabel(Number(slot && slot.duration_minutes || segment.duration_minutes || 0));
        return '' +
            '<div class="admin-week-event admin-calendar-event--blocked" data-blocked-id="' + slot.id + '" data-booking-top="' + Math.round(topPx) + '" style="top:' + topPx + 'px;height:' + heightPx + 'px">' +
            '  <div class="admin-week-event-time">' + escapeHtml(time) + ' (' + escapeHtml(durationLabel) + ')</div>' +
            '  <div class="admin-week-event-client">Sperrzeit</div>' +
            '</div>';
    }

    function blockedSlotSegmentForDay(slot, dateKey) {
        var start = toDateSafe(slot && slot.started_at);
        if (!start) {
            return null;
        }

        var end = toDateSafe(slot && slot.ends_at);
        if (!end) {
            var fallbackDuration = Math.max(1, Number(slot && slot.duration_minutes || 0));
            end = new Date(start.getTime() + (fallbackDuration * 60000));
        }

        if (!(end instanceof Date) || Number.isNaN(end.getTime()) || end <= start) {
            return null;
        }

        var dayWindow = businessWindowForDateKey(dateKey);
        if (!dayWindow) {
            return null;
        }

        var dayStart = dayWindow.start;
        var dayEnd = dayWindow.end;

        if (start >= dayEnd || end <= dayStart) {
            return null;
        }

        var segmentStart = start > dayStart ? start : dayStart;
        var segmentEnd = end < dayEnd ? end : dayEnd;
        var durationMinutes = Math.max(1, Math.round((segmentEnd.getTime() - segmentStart.getTime()) / 60000));

        return {
            start_at: toSqlDateTime(segmentStart),
            end_at: toSqlDateTime(segmentEnd),
            duration_minutes: durationMinutes,
            is_full_day: segmentStart.getTime() === dayStart.getTime() && segmentEnd.getTime() === dayEnd.getTime(),
            continues_before: start < dayStart,
            continues_after: end > dayEnd
        };
    }

    function businessWindowForDateKey(dateKey) {
        var base = new Date(dateKey + 'T00:00:00');
        if (Number.isNaN(base.getTime())) {
            return null;
        }

        var startHour = clampHour(state.window.startHour, 9);
        var endHour = clampHour(state.window.endHour, 18);
        if (endHour <= startHour) {
            endHour = Math.min(24, startHour + 8);
        }

        var start = new Date(base);
        start.setHours(startHour, 0, 0, 0);

        var end = new Date(base);
        if (endHour >= 24) {
            end.setDate(end.getDate() + 1);
            end.setHours(0, 0, 0, 0);
        } else {
            end.setHours(endHour, 0, 0, 0);
        }

        return {
            start: start,
            end: end
        };
    }

    function blockedSegmentLabel(segment) {
        var start = toDateSafe(segment.start_at);
        var end = toDateSafe(segment.end_at);
        if (!start || !end) {
            return 'Sperrzeit';
        }

        if (segment.is_full_day) {
            return 'Ganztägig';
        }

        var startLabel = segment.continues_before
            ? '00:00'
            : start.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
        var endLabel = segment.continues_after
            ? '24:00'
            : end.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });

        return startLabel + ' - ' + endLabel;
    }

    function formatDurationLabel(durationMinutes) {
        var minutes = Math.max(0, Number(durationMinutes || 0));
        if (minutes >= (23 * 60)) {
            var days = minutes / 1440;
            return formatCompactNumber(days) + ' ' + (Math.abs(days - 1) < 0.05 ? 'Tag' : 'Tage');
        }

        if (minutes >= 90) {
            var hours = minutes / 60;
            return formatCompactNumber(hours) + ' Std.';
        }

        return String(Math.round(minutes)) + ' Min.';
    }

    function formatCompactNumber(value) {
        var rounded = Math.round(value * 10) / 10;
        if (Math.abs(rounded - Math.round(rounded)) < 0.05) {
            return String(Math.round(rounded));
        }

        return rounded.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    }

    function toSqlDateTime(date) {
        return date.getFullYear() +
            '-' + String(date.getMonth() + 1).padStart(2, '0') +
            '-' + String(date.getDate()).padStart(2, '0') +
            ' ' + String(date.getHours()).padStart(2, '0') +
            ':' + String(date.getMinutes()).padStart(2, '0') +
            ':' + String(date.getSeconds()).padStart(2, '0');
    }

    function bookingsForDay(dateKey) {
        return state.bookings.filter(function (b) {
            var dateMatch = String(b.scheduled_at || '').slice(0, 10) === dateKey;
            var status = String(b.status || '');
            return dateMatch && status !== 'storno' && status !== 'declined';
        });
    }

    function renderBookingDetailsHtml() {
        if (!state.selectedBooking) {
            return '<h3>Termin-Details</h3><p class="admin-calendar-hint">Hover oder Klick auf einen Termin zeigt Datum, Status und Client.</p>';
        }

        var b = state.selectedBooking;
        var dt = toDateSafe(b.scheduled_at);
        var dateText = dt ? dt.toLocaleString('de-DE') : '-';
        var clientName = b.client && b.client.name ? b.client.name : '-';
        var serviceName = b.service && b.service.name ? b.service.name : '-';

        return '' +
            '<h3>Termin-Details</h3>' +
            '<div class="admin-calendar-details-list">' +
            '  <div class="admin-calendar-detail-item" data-booking-id="' + b.id + '"><strong>Datum:</strong> ' + escapeHtml(dateText) + '</div>' +
            '  <div class="admin-calendar-detail-item" data-booking-id="' + b.id + '"><strong>Service:</strong> ' + escapeHtml(serviceName) + '</div>' +
            '  <div class="admin-calendar-detail-item" data-booking-id="' + b.id + '"><strong>Status:</strong> ' + escapeHtml(formatStatusLabel(b.status || '-')) + '</div>' +
            '  <div class="admin-calendar-detail-item" data-booking-id="' + b.id + '"><strong>Zahlung:</strong> ' + escapeHtml(paymentStatusLabel(b.payment_status || '-')) + '</div>' +
            '  <div class="admin-calendar-detail-item" data-booking-id="' + b.id + '"><strong>Client:</strong> ' + escapeHtml(clientName) + '</div>' +
            '</div>';
    }

    function updateDetailsCard() {
        var card = document.getElementById('adminCalendarDetailsCard');
        if (!card) {
            return;
        }
        card.innerHTML = renderBookingDetailsHtml();
    }

    function renderKpis() {
        if (!kpiRoot) return;

        if (!canViewKpi) {
            kpiRoot.innerHTML = '<div class="admin-dashboard-empty"></div>';
            return;
        }

        var monthPrefix = state.month + '-';
        var monthBookings = state.bookings.filter(function (b) {
            return String(b.scheduled_at || '').indexOf(monthPrefix) === 0;
        }).filter(function (b) {
            return isKpiRelevantBooking(b);
        });

        var monthPackagePurchases = collectMonthPackagePurchases(state.bookings, monthPrefix);

        var revenueBookings = monthBookings.filter(function (b) {
            return isRevenueEligibleBooking(b);
        });
        var outstandingBookings = monthBookings.filter(function (b) {
            return String(b.payment_status || '') !== 'paid' && String(b.status || '') !== 'storno';
        });
        var cancelledBookings = monthBookings.filter(function (b) {
            return String(b.status || '') === 'storno';
        });

        var revenuePackagePurchases = monthPackagePurchases.filter(function (purchase) {
            return String(purchase.payment_status || '').toLowerCase() === 'paid';
        });
        var outstandingPackagePurchases = monthPackagePurchases.filter(function (purchase) {
            var status = String(purchase.payment_status || '').toLowerCase();
            return status !== 'paid' && status !== 'refunded';
        });

        var revenue = sumServicePrice(revenueBookings) + sumPackagePurchasePrice(revenuePackagePurchases);
        var outstanding = sumServicePrice(outstandingBookings) + sumPackagePurchasePrice(outstandingPackagePurchases);

        var selectedList = revenueBookings.concat(toPackageKpiItems(revenuePackagePurchases));
        if (state.selectedKpi === 'outstanding') selectedList = outstandingBookings.concat(toPackageKpiItems(outstandingPackagePurchases));
        if (state.selectedKpi === 'cancelled') selectedList = cancelledBookings;

        kpiRoot.innerHTML = '' +
            '<div class="admin-kpi-grid">' +
            renderKpiCard('revenue', 'Monatsumsatz', euro(revenue), state.selectedKpi === 'revenue') +
            renderKpiCard('outstanding', 'Ausstehende Einnahmen', euro(outstanding), state.selectedKpi === 'outstanding') +
            renderKpiCard('cancelled', 'Stornierungen (Monat)', String(cancelledBookings.length), state.selectedKpi === 'cancelled') +
            '</div>' +
            '<div class="admin-kpi-list">' +
            renderKpiList(selectedList) +
            '</div>';

        bindKpiEvents();
    }

    function renderKpiCard(kind, label, value, selected) {
        return '' +
            '<article class="admin-kpi-card' + (selected ? ' is-selected' : '') + '" data-kpi-kind="' + kind + '">' +
            '  <p class="admin-kpi-label">' + escapeHtml(label) + '</p>' +
            '  <p class="admin-kpi-value">' + escapeHtml(value) + '</p>' +
            '</article>';
    }

    function renderKpiList(bookings) {
        if (!bookings.length) {
            return '<p class="admin-calendar-hint">Keine passenden Buchungen im aktuellen Monat.</p>';
        }

        return bookings.slice(0, 12).map(function (b) {
            if (b && b.__kpiType === 'package_purchase') {
                var purchaseDate = toDateSafe(b.purchased_at || b.paid_at);
                var purchaseDateText = purchaseDate ? purchaseDate.toLocaleString('de-DE') : '-';
                var purchaseStatus = String(b.payment_status || '-');
                return '' +
                    '<div class="admin-kpi-item">' +
                    '<strong>Datum:</strong> ' + escapeHtml(purchaseDateText) + ' | ' +
                    '<strong>Status:</strong> ' + escapeHtml(purchaseStatus) + ' | ' +
                    '<strong>Typ:</strong> Paketkauf' +
                    '</div>';
            }

            var dt = toDateSafe(b.scheduled_at);
            var dateText = dt ? dt.toLocaleString('de-DE') : '-';
            var clientName = b.client && b.client.name ? b.client.name : '-';
            return '' +
                '<div class="admin-kpi-item" data-booking-id="' + b.id + '">' +
                '<strong>Datum:</strong> ' + escapeHtml(dateText) + ' | ' +
                '<strong>Status:</strong> ' + escapeHtml(String(b.status || '-')) + ' | ' +
                '<strong>Client:</strong> ' + escapeHtml(clientName) +
                '</div>';
        }).join('');
    }

    function sumServicePrice(bookings) {
        return bookings.reduce(function (sum, b) {
            var price = Number(b.service && b.service.price ? b.service.price : 0);
            return sum + (Number.isFinite(price) ? price : 0);
        }, 0);
    }

    function sumPackagePurchasePrice(purchases) {
        return purchases.reduce(function (sum, p) {
            var price = Number(p && p.price ? p.price : 0);
            return sum + (Number.isFinite(price) ? price : 0);
        }, 0);
    }

    function collectMonthPackagePurchases(bookings, monthPrefix) {
        var unique = {};

        bookings.forEach(function (booking) {
            if (!booking || booking.is_package_booking !== true) {
                return;
            }

            var purchase = booking.package_purchase;
            if (!purchase || !purchase.id) {
                return;
            }

            var purchaseId = String(purchase.id);
            if (unique[purchaseId]) {
                return;
            }

            var purchasedAt = String(purchase.purchased_at || '');
            var paidAt = String(purchase.paid_at || '');
            var purchaseMonthValue = purchasedAt !== '' ? purchasedAt : paidAt;

            if (purchaseMonthValue.indexOf(monthPrefix) !== 0) {
                return;
            }

            unique[purchaseId] = {
                id: purchase.id,
                price: Number(purchase.price || 0),
                payment_status: String(purchase.payment_status || ''),
                purchased_at: purchasedAt,
                paid_at: paidAt
            };
        });

        return Object.keys(unique).map(function (id) {
            return unique[id];
        });
    }

    function toPackageKpiItems(purchases) {
        return purchases.map(function (p) {
            return {
                __kpiType: 'package_purchase',
                id: p.id,
                price: p.price,
                payment_status: p.payment_status,
                purchased_at: p.purchased_at,
                paid_at: p.paid_at
            };
        });
    }

    function euro(value) {
        return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0);
    }

    function openCreateAppointmentModal(options) {
        if (!canManageBookings || !window.adminOpenModal) {
            return;
        }

        var defaults = options && typeof options === 'object' ? options : {};
        var defaultStartedAt = normalizeSqlOrIsoDateTime(defaults.startedAt || '');

        loadCreateMeta().then(function (meta) {
            var services = Array.isArray(meta.services) ? meta.services : [];
            var clients = Array.isArray(meta.clients) ? meta.clients : [];

            var serviceOptions = services.map(function (service) {
                var label = String(service.name || ('Service #' + service.id));
                return '<option value="' + escapeHtml(String(service.id || '')) + '">' + escapeHtml(label) + '</option>';
            }).join('');

            var clientOptions = clients.map(function (client) {
                var name = String(client.name || ('Client #' + client.id));
                var email = String(client.email || '');
                var label = email !== '' ? (name + ' (' + email + ')') : name;
                return '<option value="' + escapeHtml(String(client.id || '')) + '">' + escapeHtml(label) + '</option>';
            }).join('');

            var body = '' +
                '<div class="admin-calendar-create-form">' +
                '  <label class="admin-calendar-create-field">' +
                '    <span>Service</span>' +
                '    <select id="adminCalendarCreateService" class="admin-calendar-select">' + serviceOptions + '</select>' +
                '  </label>' +
                '  <label class="admin-calendar-create-field">' +
                '    <span>Client</span>' +
                '    <select id="adminCalendarCreateClient" class="admin-calendar-select">' + clientOptions + '</select>' +
                '  </label>' +
                '  <label class="admin-calendar-create-field">' +
                '    <span>Startzeit</span>' +
                '    <input id="adminCalendarCreateStartedAt" class="admin-calendar-select" type="datetime-local" value="' + escapeHtml(defaultStartedAt) + '">' +
                '  </label>' +
                '  <label class="admin-calendar-create-field">' +
                '    <span>Notizen</span>' +
                '    <textarea id="adminCalendarCreateNotes" class="admin-calendar-select" rows="4" placeholder="Optional"></textarea>' +
                '  </label>' +
                '</div>';

            window.adminOpenModal('Neuen Termin anlegen', body, {
                type: 'info',
                buttons: [
                    {
                        label: 'Abbrechen',
                        variant: 'secondary',
                        onClick: function () {
                            window.adminCloseModal && window.adminCloseModal();
                        }
                    },
                    {
                        label: 'Termin anlegen',
                        variant: 'primary',
                        onClick: function () {
                            submitCreateAppointment();
                        }
                    }
                ]
            });
        }).catch(function () {
            if (window.adminShowNotification) {
                window.adminShowNotification('error', 'Termin-Metadaten konnten nicht geladen werden.');
            }
        });
    }

    function loadCreateMeta() {
        if (createMetaCache) {
            return Promise.resolve(createMetaCache);
        }

        return fetch(String((cfg.api && cfg.api.meta) || '/appointments/data/meta'), {
            credentials: 'include',
            headers: { Accept: 'application/json' }
        })
            .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
            .then(function (result) {
                if (!result.ok) {
                    throw new Error('meta_load_failed');
                }

                createMetaCache = (result.json && result.json.data) ? result.json.data : {};
                return createMetaCache;
            });
    }

    function submitCreateAppointment() {
        var serviceEl = document.getElementById('adminCalendarCreateService');
        var clientEl = document.getElementById('adminCalendarCreateClient');
        var startedAtEl = document.getElementById('adminCalendarCreateStartedAt');
        var notesEl = document.getElementById('adminCalendarCreateNotes');

        var serviceId = serviceEl ? parseInt(String(serviceEl.value || '0'), 10) : 0;
        var clientId = clientEl ? parseInt(String(clientEl.value || '0'), 10) : 0;
        var startedAtRaw = startedAtEl ? String(startedAtEl.value || '').trim() : '';
        var notes = notesEl ? String(notesEl.value || '').trim() : '';

        if (serviceId <= 0 || clientId <= 0 || startedAtRaw === '') {
            if (window.adminShowNotification) {
                window.adminShowNotification('warning', 'Bitte Service, Client und Startzeit ausfüllen.');
            }
            return;
        }

        var startedAt = startedAtRaw.length === 16 ? (startedAtRaw + ':00') : startedAtRaw;

        fetch(String((cfg.api && cfg.api.store) || '/appointments/data'), {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                service_id: serviceId,
                client_id: clientId,
                started_at: startedAt,
                notes: notes
            })
        })
            .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
            .then(function (result) {
                if (!result.ok) {
                    throw new Error('create_failed');
                }

                window.adminCloseModal && window.adminCloseModal();
                if (window.adminShowNotification) {
                    window.adminShowNotification('success', 'Termin wurde angelegt.');
                }
                fetchBookings();
            })
            .catch(function () {
                if (window.adminShowNotification) {
                    window.adminShowNotification('error', 'Termin konnte nicht angelegt werden.');
                }
            });
    }

    function normalizeSqlOrIsoDateTime(value) {
        var raw = String(value || '').trim();
        if (raw === '') {
            return '';
        }

        var normalized = raw.replace(' ', 'T');
        if (normalized.length >= 19) {
            return normalized.slice(0, 19);
        }
        if (normalized.length === 16) {
            return normalized;
        }
        return normalized;
    }

    function bindCalendarEvents() {
        var tabArmed = false;

        calendarRoot.querySelectorAll('[data-slot-start]').forEach(function (el) {
            var slotStart = String(el.getAttribute('data-slot-start') || '');
            if (slotStart === '') {
                return;
            }

            function openCreateForSlot() {
                if (!window.adminAppointmentsOpenCreateModal) {
                    return;
                }
                window.adminAppointmentsOpenCreateModal({ startedAt: slotStart });
            }

            el.addEventListener('dblclick', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openCreateForSlot();
            });

            el.addEventListener('keydown', function (e) {
                if (e.key === 'Tab') {
                    tabArmed = true;
                    return;
                }

                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openCreateForSlot();
                }
            });

            el.addEventListener('focus', function () {
                if (!tabArmed) {
                    return;
                }

                tabArmed = false;
                openCreateForSlot();
            });

            el.addEventListener('blur', function () {
                tabArmed = false;
            });
        });

        calendarRoot.querySelectorAll('[data-blocked-id]').forEach(function (el) {
            var slotId = parseInt(el.getAttribute('data-blocked-id') || '0', 10);
            var slot = state.blockedSlots.find(function (s) { return s.id === slotId; });
            if (!slot) return;

            el.addEventListener('dblclick', function (e) {
                e.stopPropagation();
                openBlockedSlotModal(slot);
            });
        });

        calendarRoot.querySelectorAll('.admin-week-event[data-booking-id], .admin-calendar-event[data-booking-id]').forEach(function (el) {
            var bookingId = parseInt(el.getAttribute('data-booking-id') || '0', 10);
            var booking = state.bookings.find(function (b) { return b.id === bookingId; });
            if (!booking) return;

            var anchorTopRaw = el.getAttribute('data-booking-top');
            var anchorTop = anchorTopRaw !== null ? parseInt(anchorTopRaw, 10) : null;

            function selectBooking(scrollIntoView) {
                state.selectedBooking = booking;
                state.selectedBookingAnchorTop = Number.isFinite(anchorTop) ? anchorTop : null;
                updateDetailsCard();
                alignDetailsCardToSelection();

                if (scrollIntoView && isNarrowViewport()) {
                    var card = document.getElementById('adminCalendarDetailsCard');
                    if (card) {
                        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }
            }

            el.addEventListener('mouseenter', function () {
                if (!isNarrowViewport()) {
                    selectBooking(false);
                }
            });

            el.addEventListener('click', function () {
                selectBooking(true);
            });

            el.addEventListener('dblclick', function () {
                window.location.href = '/appointments/' + booking.id;
            });
        });

        calendarRoot.querySelectorAll('.admin-calendar-detail-item[data-booking-id]').forEach(function (el) {
            var bookingId = el.getAttribute('data-booking-id');
            el.addEventListener('dblclick', function () {
                window.location.href = '/appointments/' + bookingId;
            });
        });

        var viewSelect = calendarRoot.querySelector('[data-cal-view]');
        if (viewSelect) {
            viewSelect.addEventListener('change', function (e) {
                state.view = e.target.value;
                writeStateToQuery();
                renderCalendar();
            });
        }

        var prev = calendarRoot.querySelector('[data-cal-prev]');
        var next = calendarRoot.querySelector('[data-cal-next]');
        var today = calendarRoot.querySelector('[data-cal-today]');
        var create = calendarRoot.querySelector('[data-cal-create]');

        if (prev) prev.addEventListener('click', function () { shiftMonth(-1); });
        if (next) next.addEventListener('click', function () { shiftMonth(1); });
        if (today) today.addEventListener('click', function () {
            var now = new Date();
            state.month = formatMonth(now);
            state.week = formatDateYmd(startOfWeekDate(now));
            state.selectedBookingAnchorTop = null;
            writeStateToQuery();
            fetchBookings();
        });
        if (create) {
            create.addEventListener('click', function () {
                if (window.adminAppointmentsOpenCreateModal) {
                    window.adminAppointmentsOpenCreateModal();
                }
            });
        }
    }

    function alignDetailsCardToSelection() {
        if (state.view !== 'week') {
            return;
        }

        var aside = calendarRoot.querySelector('.admin-calendar-details--week');
        var card = document.getElementById('adminCalendarDetailsCard');
        if (!aside || !card) {
            return;
        }

        if (isNarrowViewport()) {
            card.style.top = '0px';
            return;
        }

        var anchor = Number.isFinite(state.selectedBookingAnchorTop) ? state.selectedBookingAnchorTop : 8;
        var maxTop = Math.max(8, aside.clientHeight - card.offsetHeight - 8);
        var top = Math.max(8, Math.min(maxTop, anchor));
        card.style.top = top + 'px';
    }

    function isNarrowViewport() {
        return window.matchMedia('(max-width: 1023px)').matches;
    }

    function bindKpiEvents() {
        if (!kpiRoot) return;

        kpiRoot.querySelectorAll('[data-kpi-kind]').forEach(function (card) {
            var kind = card.getAttribute('data-kpi-kind');
            function activate() {
                state.selectedKpi = kind;
                renderKpis();
            }
            card.addEventListener('mouseenter', activate);
            card.addEventListener('click', activate);
        });

        kpiRoot.querySelectorAll('[data-booking-id]').forEach(function (row) {
            var bookingId = row.getAttribute('data-booking-id');
            row.addEventListener('dblclick', function () {
                window.location.href = '/appointments/' + bookingId;
            });
        });
    }

    function isRevenueEligibleBooking(booking) {
        if (!isKpiRelevantBooking(booking)) {
            return false;
        }

        var paymentStatus = String((booking && booking.payment_status) || '').toLowerCase();
        if (paymentStatus !== 'paid') {
            return false;
        }

        var status = String((booking && booking.status) || '').toLowerCase();
        if (status === 'accepted' || status === 'completed') {
            return true;
        }

        var isCancelled = status === 'storno';
        if (!isCancelled) {
            return false;
        }

        var cancellationTiming = String((booking && booking.cancellation_timing) || '').toLowerCase();
        return cancellationTiming === 'late';
    }

    function isKpiRelevantBooking(booking) {
        return !(booking && booking.is_package_booking === true);
    }

    function openBlockedSlotModal(slot) {
        if (!window.adminOpenModal) return;

        var start = toDateSafe(slot.started_at);
        var end = toDateSafe(slot.ends_at);
        var startText = start ? start.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';
        var endText = end ? end.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

        var body = '' +
            '<dl style="display:grid;grid-template-columns:auto 1fr;gap:0.4rem 0.9rem;font-size:0.85rem">' +
            '  <dt style="color:var(--admin-muted)">Start</dt><dd>' + escapeHtml(startText) + '</dd>' +
            '  <dt style="color:var(--admin-muted)">Ende</dt><dd>' + escapeHtml(endText) + '</dd>' +
            '  <dt style="color:var(--admin-muted)">Dauer</dt><dd>' + escapeHtml(formatDurationLabel(slot.duration_minutes || 0)) + '</dd>' +
            '  <dt style="color:var(--admin-muted)">Grund</dt><dd>' + escapeHtml(slot.reason || '-') + '</dd>' +
            '  <dt style="color:var(--admin-muted)">Erstellt</dt><dd>' + escapeHtml(String(slot.created_at || '-')) + '</dd>' +
            '</dl>';

        var buttons = [
            { label: 'Schließen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } }
        ];

        if (canManageBookings) {
            buttons.unshift({
                label: 'Block löschen',
                variant: 'danger',
                onClick: function () {
                    var deleteUrl = String((cfg.api && cfg.api.delete_blocked) || '').replace('{id}', String(slot.id));
                    fetch(deleteUrl, {
                        method: 'DELETE',
                        credentials: 'include',
                        headers: { Accept: 'application/json' }
                    })
                        .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
                        .then(function (result) {
                            if (result.ok) {
                                window.adminCloseModal && window.adminCloseModal();
                                if (window.adminShowNotification) {
                                    window.adminShowNotification('success', 'Sperrzeit wurde gelöscht.');
                                }
                                fetchBookings();
                            } else {
                                if (window.adminShowNotification) {
                                    window.adminShowNotification('error', 'Sperrzeit konnte nicht gelöscht werden.');
                                }
                            }
                        })
                        .catch(function () {
                            if (window.adminShowNotification) {
                                window.adminShowNotification('error', 'Sperrzeit konnte nicht gelöscht werden.');
                            }
                        });
                }
            });
        }

        window.adminOpenModal('Sperrzeit #' + slot.id, body, { type: 'info', buttons: buttons });
    }

    function shiftMonth(delta) {
        if (state.view === 'week') {
            shiftWeek(delta);
            return;
        }

        var d = new Date(state.month + '-01T00:00:00');
        d.setMonth(d.getMonth() + delta);
        state.month = formatMonth(d);
        state.week = formatDateYmd(startOfWeekDate(d));
        writeStateToQuery();
        fetchBookings();
    }

    function shiftWeek(delta) {
        var week = new Date(state.week + 'T00:00:00');
        if (Number.isNaN(week.getTime())) {
            week = startOfWeekDate(new Date());
        }
        week.setDate(week.getDate() + (delta * 7));
        var weekStart = startOfWeekDate(week);
        state.week = formatDateYmd(weekStart);
        state.month = formatMonth(weekStart);
        writeStateToQuery();
        fetchBookings();
    }

    function shiftPage(delta) {
        state.page = Math.max(1, Math.min(state.totalPages || 1, state.page + delta));
        writeStateToQuery();
        fetchBookings();
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowLeft') {
            shiftMonth(-1);
        }
        if (e.key === 'ArrowRight') {
            shiftMonth(1);
        }
    });

    window.addEventListener('popstate', function () {
        hydrateStateFromQuery();
        fetchBookings();
    });

    writeStateToQuery();
    fetchBookings();

    setInterval(function () {
        fetchBookings();
    }, parsePositiveInt(cfg.refresh_ms, 30000));
}());

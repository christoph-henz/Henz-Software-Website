(function () {
    'use strict';

    var cfg = window.__ADMIN_AVAILABILITY_CONFIG || {};
    var root = document.getElementById('adminAvailabilityRoot');
    if (!root) {
        return;
    }

    var state = {
        loading: true,
        rules: {
            appointments_enabled: 1,
            appointments_day_start_hour: 8,
            appointments_day_end_hour: 18,
            appointments_min_hours_notice: 24,
            appointments_advance_days: 60,
            buffer_minutes: 0,
            max_appointments_per_day: 0,
            cancellation_hours_notice: 48,
            reminder_hours_before: 24,
        },
        recurring: [],
        blocked: [],
    };

    var dayLabels = {
        1: 'Montag',
        2: 'Dienstag',
        3: 'Mittwoch',
        4: 'Donnerstag',
        5: 'Freitag',
        6: 'Samstag',
        7: 'Sonntag'
    };

    loadData();

    function loadData() {
        state.loading = true;
        render();

        return fetchJson(apiUrl(cfg.api && cfg.api.overview)).then(function (result) {
            if (result.status >= 400) {
                throw new Error(buildErrorMessage(result, 'Verfügbarkeitsdaten konnten nicht geladen werden.'));
            }

            var data = result.json && result.json.data ? result.json.data : {};
            state.rules = Object.assign({}, state.rules, data.rules || {});
            state.recurring = Array.isArray(data.recurring_availability) ? data.recurring_availability : [];
            state.blocked = Array.isArray(data.blocked_times) ? data.blocked_times : [];
            state.loading = false;
            render();
        }).catch(function (err) {
            state.loading = false;
            render();
            notify('error', err && err.message ? err.message : 'Verfügbarkeitsdaten konnten nicht geladen werden.');
        });
    }

    function render() {
        if (state.loading) {
            root.innerHTML = '<div class="admin-availability-card"><div class="admin-availability-empty">Lade Verfügbarkeit...</div></div>';
            return;
        }

        root.innerHTML = '' +
            '<div class="admin-availability-grid">' +
            renderRulesCard() +
            renderRecurringCard() +
            '</div>' +
            renderBlockedCard();

        bindEvents();
    }

    function renderRulesCard() {
        var appointmentsEnabled = Number(state.rules.appointments_enabled || 0) === 1;
        var ticketsEnabled = Number(state.rules.tickets_enabled || 0) === 1;

        return '' +
            '<section class="admin-availability-card">' +
            '  <h2>Appointment-Einstellungen</h2>' +
            '  <p class="admin-availability-subtext">Steuert Terminbuchung, Vorlauf, Planungsfenster und den sichtbaren Tagesbereich im Kalender.</p>' +
            '  <div class="admin-availability-row">' +
            field('Appointments aktiviert', '<input id="ruleAppointmentsEnabled" class="admin-availability-input" type="checkbox"' + (appointmentsEnabled ? ' checked' : '') + '>') +
            field('Tickets aktiviert (WIP)', '<input id="ruleTicketsEnabled" disabled="true" class="admin-availability-input" type="checkbox"' + (ticketsEnabled ? ' checked' : '') + '>') +
            field('Kalender Startstunde', '<input id="ruleDayStartHour" class="admin-availability-input" type="number" min="0" max="23" value="' + esc(String(state.rules.appointments_day_start_hour || 8)) + '">') +
            field('Kalender Endstunde', '<input id="ruleDayEndHour" class="admin-availability-input" type="number" min="1" max="24" value="' + esc(String(state.rules.appointments_day_end_hour || 18)) + '">') +
            field('Mindestvorlauf (Stunden)', '<input id="ruleMinNotice" class="admin-availability-input" type="number" min="0" max="720" value="' + esc(String(state.rules.appointments_min_hours_notice || 24)) + '">') +
            field('Vorausplanung (Tage)', '<input id="ruleAdvanceDays" class="admin-availability-input" type="number" min="1" max="3650" value="' + esc(String(state.rules.appointments_advance_days || 60)) + '">') +
            field('Puffer (Minuten)', '<input id="ruleBufferMinutes" class="admin-availability-input" type="number" min="0" max="180" value="' + esc(String(state.rules.buffer_minutes || 0)) + '">') +
            field('Max. Termine/Tag (0 = unbegrenzt)', '<input id="ruleMaxAppointments" class="admin-availability-input" type="number" min="0" max="100" value="' + esc(String(state.rules.max_appointments_per_day || 0)) + '">') +
            field('Stornofrist (Stunden)', '<input id="ruleCancellationNotice" class="admin-availability-input" type="number" min="1" max="720" value="' + esc(String(state.rules.cancellation_hours_notice || 48)) + '">') +
            field('Erinnerung vor Termin (Stunden)', '<input id="ruleReminderHoursBefore" class="admin-availability-input" type="number" min="1" max="720" value="' + esc(String(state.rules.reminder_hours_before || 24)) + '">') +
            '  </div>' +
            (cfg.can_manage_availability ? '<div class="admin-availability-actions"><button class="admin-availability-btn" data-save-rules>Regeln speichern</button></div>' : '') +
            '</section>';
    }

    function renderRecurringCard() {
        var rows = [1, 2, 3, 4, 5, 6, 7].map(function (day) {
            var item = state.recurring.find(function (entry) {
                return parseInt(entry.day_of_week, 10) === day && !!entry.is_active;
            });

            var isEnabled = !!item;
            var start = item ? String(item.start_time || '').slice(0, 5) : '08:00';
            var end = item ? String(item.end_time || '').slice(0, 5) : '18:00';

            return '' +
                '<tr>' +
                '<td>' + esc(dayLabels[day]) + '</td>' +
                '<td><input type="checkbox" data-day-active="' + day + '"' + (isEnabled ? ' checked' : '') + (cfg.can_manage_availability ? '' : ' disabled') + '></td>' +
                '<td><input class="admin-availability-input" type="time" data-day-start="' + day + '" value="' + esc(start) + '"' + (cfg.can_manage_availability ? '' : ' disabled') + '></td>' +
                '<td><input class="admin-availability-input" type="time" data-day-end="' + day + '" value="' + esc(end) + '"' + (cfg.can_manage_availability ? '' : ' disabled') + '></td>' +
                '</tr>';
        }).join('');

        return '' +
            '<section class="admin-availability-card">' +
            '  <h2>Öffnungszeiten</h2>' +
            '  <p class="admin-availability-subtext">Pro Wochentag ein aktives Zeitfenster. Deaktivierte Tage erzeugen keine Slots.</p>' +
            '  <table class="admin-availability-table">' +
            '      <thead><tr><th>Tag</th><th>Aktiv</th><th>Start</th><th>Ende</th></tr></thead>' +
            '      <tbody>' + rows + '</tbody>' +
            '  </table>' +
            (cfg.can_manage_availability ? '<div class="admin-availability-actions"><button class="admin-availability-btn" data-save-recurring>Arbeitszeiten speichern</button></div>' : '') +
            '</section>';
    }

    function renderBlockedCard() {
        var rows = state.blocked.length === 0
            ? '<tr><td colspan="4" class="admin-availability-empty">Keine Sperrzeiten vorhanden.</td></tr>'
            : state.blocked.map(function (item) {
                return '' +
                    '<tr>' +
                    '  <td>' + esc(formatDateTime(item.starts_at)) + '</td>' +
                    '  <td>' + esc(formatDateTime(item.ends_at)) + '</td>' +
                    '  <td>' + esc(item.reason || '-') + '</td>' +
                    '  <td>' + (cfg.can_manage_availability ? '<button class="admin-availability-btn admin-availability-btn--secondary" data-delete-blocked="' + esc(String(item.id)) + '">Entfernen</button>' : '-') + '</td>' +
                    '</tr>';
            }).join('');

        var addForm = cfg.can_manage_availability
            ? '' +
                '<div class="admin-availability-row">' +
                field('Start', '<input id="blockedStart" class="admin-availability-input" type="datetime-local">') +
                field('Ende', '<input id="blockedEnd" class="admin-availability-input" type="datetime-local">') +
                field('Grund', '<input id="blockedReason" class="admin-availability-input" type="text" placeholder="z.B. Urlaub">') +
                '</div>' +
                '<div class="admin-availability-actions"><button class="admin-availability-btn" data-add-blocked>Sperrzeit hinzufügen</button></div>'
            : '';

        return '' +
            '<section class="admin-availability-card">' +
            '  <h2>Betriebsurlaub und Sperrzeiten</h2>' +
            '  <p class="admin-availability-subtext">Urlaub, Feiertage oder sonstige Ausnahmen blockieren Slots auch innerhalb aktiver Öffnungszeiten.</p>' +
            addForm +
            '  <table class="admin-availability-table">' +
            '      <thead><tr><th>Von</th><th>Bis</th><th>Grund</th><th>Aktion</th></tr></thead>' +
            '      <tbody>' + rows + '</tbody>' +
            '  </table>' +
            '</section>';
    }

    function bindEvents() {
        var saveRulesButton = root.querySelector('[data-save-rules]');
        if (saveRulesButton) {
            saveRulesButton.addEventListener('click', saveRules);
        }

        var saveRecurringButton = root.querySelector('[data-save-recurring]');
        if (saveRecurringButton) {
            saveRecurringButton.addEventListener('click', saveRecurring);
        }

        var addBlockedButton = root.querySelector('[data-add-blocked]');
        if (addBlockedButton) {
            addBlockedButton.addEventListener('click', addBlockedTime);
        }

        root.querySelectorAll('[data-delete-blocked]').forEach(function (button) {
            button.addEventListener('click', function () {
                var id = button.getAttribute('data-delete-blocked');
                if (id) {
                    removeBlockedTime(id);
                }
            });
        });
    }

    function saveRules() {
        var appointmentsEnabledElement = document.getElementById('ruleAppointmentsEnabled');

        var payload = {
            appointments_enabled: appointmentsEnabledElement && appointmentsEnabledElement.checked ? 1 : 0,
            appointments_day_start_hour: intValue('ruleDayStartHour', 8),
            appointments_day_end_hour: intValue('ruleDayEndHour', 18),
            appointments_min_hours_notice: intValue('ruleMinNotice', 24),
            appointments_advance_days: intValue('ruleAdvanceDays', 60),
            buffer_minutes: intValue('ruleBufferMinutes', 0),
            max_appointments_per_day: intValue('ruleMaxAppointments', 0),
            cancellation_hours_notice: intValue('ruleCancellationNotice', 48),
            reminder_hours_before: intValue('ruleReminderHoursBefore', 24)
        };

        fetchJson(apiUrl(cfg.api && cfg.api.rules), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (result) {
            if (result.status >= 400) {
                throw new Error(buildErrorMessage(result, 'Regeln konnten nicht gespeichert werden.'));
            }

            notify('success', 'Regeln gespeichert.');
            loadData();
        }).catch(function (err) {
            notify('error', err && err.message ? err.message : 'Regeln konnten nicht gespeichert werden.');
        });
    }

    function saveRecurring() {
        var entries = [];

        [1, 2, 3, 4, 5, 6, 7].forEach(function (day) {
            var active = root.querySelector('[data-day-active="' + day + '"]');
            var start = root.querySelector('[data-day-start="' + day + '"]');
            var end = root.querySelector('[data-day-end="' + day + '"]');

            if (!active || !start || !end || !active.checked) {
                return;
            }

            entries.push({
                day_of_week: day,
                start_time: String(start.value || '').trim(),
                end_time: String(end.value || '').trim(),
                is_active: true
            });
        });

        fetchJson(apiUrl(cfg.api && cfg.api.recurring), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ entries: entries })
        }).then(function (result) {
            if (result.status >= 400) {
                throw new Error(buildErrorMessage(result, 'Arbeitszeiten konnten nicht gespeichert werden.'));
            }

            notify('success', 'Arbeitszeiten gespeichert.');
            loadData();
        }).catch(function (err) {
            notify('error', err && err.message ? err.message : 'Arbeitszeiten konnten nicht gespeichert werden.');
        });
    }

    function addBlockedTime() {
        var startsAt = value('blockedStart');
        var endsAt = value('blockedEnd');

        var payload = {
            starts_at: toIsoLocal(startsAt),
            ends_at: toIsoLocal(endsAt),
            reason: value('blockedReason')
        };

        fetchJson(apiUrl(cfg.api && cfg.api.blocked && cfg.api.blocked.create), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (result) {
            if (result.status >= 400) {
                throw new Error(buildErrorMessage(result, 'Sperrzeit konnte nicht gespeichert werden.'));
            }

            notify('success', 'Sperrzeit hinzugefuegt.');
            loadData();
        }).catch(function (err) {
            notify('error', err && err.message ? err.message : 'Sperrzeit konnte nicht gespeichert werden.');
        });
    }

    function removeBlockedTime(id) {
        fetchJson(apiUrl(cfg.api && cfg.api.blocked && cfg.api.blocked.delete, id), {
            method: 'DELETE'
        }).then(function (result) {
            if (result.status >= 400) {
                throw new Error(buildErrorMessage(result, 'Sperrzeit konnte nicht entfernt werden.'));
            }

            notify('success', 'Sperrzeit entfernt.');
            loadData();
        }).catch(function (err) {
            notify('error', err && err.message ? err.message : 'Sperrzeit konnte nicht entfernt werden.');
        });
    }

    function field(label, control) {
        return '' +
            '<label>' +
            '  <div class="admin-availability-field-label">' + esc(label) + '</div>' +
            '  <div>' + control + '</div>' +
            '</label>';
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
                return { status: res.status, json: json };
            });
        });
    }

    function buildErrorMessage(result, fallback) {
        var json = result && result.json ? result.json : {};
        var errors = json && json.errors ? json.errors : null;
        if (errors && typeof errors === 'object') {
            var keys = Object.keys(errors);
            if (keys.length > 0 && Array.isArray(errors[keys[0]]) && errors[keys[0]].length > 0) {
                var code = String(errors[keys[0]][0] || '');
                if (code === 'overlaps_existing_booking') {
                    return fallback + ' Grund: Im gewählten Zeitraum existiert bereits eine Buchung. Bitte den Termin zuerst umbuchen oder einen anderen Sperrzeitraum wählen.';
                }

                return fallback + ' Grund: ' + keys[0] + ' - ' + code;
            }
        }

        var message = json && json.message ? String(json.message) : '';
        if (message) {
            return fallback + ' Grund: ' + message;
        }

        return fallback;
    }

    function formatDateTime(value) {
        if (!value) {
            return '-';
        }

        var d = new Date(String(value).replace(' ', 'T'));
        if (isNaN(d.getTime())) {
            return String(value);
        }

        return d.toLocaleString('de-DE', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function value(id) {
        var element = document.getElementById(id);
        return element ? String(element.value || '').trim() : '';
    }

    function intValue(id, fallback) {
        var n = parseInt(value(id), 10);
        return Number.isFinite(n) ? n : fallback;
    }

    function toIsoLocal(datetimeLocal) {
        if (!datetimeLocal) {
            return '';
        }

        return datetimeLocal.length === 16 ? datetimeLocal + ':00' : datetimeLocal;
    }

    function apiUrl(template, id) {
        var url = String(template || '');
        if (id !== undefined && id !== null) {
            url = url.replace('{id}', encodeURIComponent(String(id)));
        }

        return url;
    }

    function notify(type, message) {
        if (window.adminShowNotification) {
            window.adminShowNotification(type, message);
        }
    }

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})();

(function () {
    'use strict';

    var cfg = window.__ADMIN_PROJECT_DETAIL_CONFIG || {};
    var projectDataUrl = cfg.project_data_url || '';
    var phasesUrl = cfg.project_phases_url || '';
    var membersUrl = cfg.project_members_url || '';
    var usersUrl = cfg.project_users_url || '';
    var phaseTestDataUrlBase = cfg.phase_test_data_url_base || '';
    var phaseTestsUrlBase = cfg.phase_tests_url_base || '';
    var canManage = !!cfg.can_manage_projects;

    var root = document.getElementById('adminProjectDetailRoot');
    if (!root) return;

    var state = {
        project: null,
        phases: [],
        members: [],
        users: [],
        testTemplates: [],
    };

    function fetchJson(url, options) {
        return fetch(url, options || { credentials: 'same-origin' })
            .then(function (r) {
                return r.text().then(function (txt) {
                    try {
                        return JSON.parse(txt);
                    } catch (_err) {
                        throw new Error('Ungueltige Serverantwort');
                    }
                });
            });
    }

    function loadAll() {
        Promise.all([
            fetchJson(projectDataUrl, { credentials: 'same-origin' }),
            fetchJson(phasesUrl, { credentials: 'same-origin' }),
            fetchJson(membersUrl, { credentials: 'same-origin' }),
            canManage ? fetchJson(usersUrl, { credentials: 'same-origin' }) : Promise.resolve({ success: true, data: { users: [] } }),
        ]).then(function (responses) {
            var projectRes = responses[0] || {};
            var phaseRes = responses[1] || {};
            var memberRes = responses[2] || {};
            var usersRes = responses[3] || {};

            if (!projectRes.success) throw new Error(projectRes.message || 'Projekt konnte nicht geladen werden.');
            if (!phaseRes.success) throw new Error(phaseRes.message || 'Phasen konnten nicht geladen werden.');
            if (!memberRes.success) throw new Error(memberRes.message || 'Mitglieder konnten nicht geladen werden.');
            if (canManage && !usersRes.success) throw new Error(usersRes.message || 'User konnten nicht geladen werden.');

            state.project = projectRes.data.project || null;
            state.phases = phaseRes.data.phases || [];
            state.members = memberRes.data.members || [];
            state.users = usersRes.data.users || [];
            state.testTemplates = phaseRes.data.test_templates || [];

            renderProject();
            renderPhases();
            renderMembers();
            renderUserSelect();
        }).catch(function (err) {
            var meta = document.getElementById('projectDetailMeta');
            if (meta) {
                meta.textContent = 'Fehler beim Laden: ' + String(err.message || 'Unbekannter Fehler');
            }
        });
    }

    function renderProject() {
        var title = document.getElementById('projectDetailTitle');
        var meta = document.getElementById('projectDetailMeta');
        if (!state.project) return;

        if (title) {
            title.textContent = state.project.name || ('Projekt #' + String(state.project.id || ''));
        }

        if (meta) {
            meta.innerHTML =
                '<article class="admin-project-meta-card">' +
                    '<span class="admin-project-meta-label">Klient</span>' +
                    '<strong class="admin-project-meta-value">' + esc(state.project.client_name || '-') + '</strong>' +
                '</article>' +
                '<article class="admin-project-meta-card">' +
                    '<span class="admin-project-meta-label">Status</span>' +
                    '<strong class="admin-project-meta-value"><span class="admin-project-status-pill">' + esc(state.project.status || '-') + '</span></strong>' +
                '</article>' +
                '<article class="admin-project-meta-card">' +
                    '<span class="admin-project-meta-label">Faellig</span>' +
                    '<strong class="admin-project-meta-value">' + esc(state.project.due_date || '-') + '</strong>' +
                '</article>' +
                '<article class="admin-project-meta-card admin-project-meta-card--description">' +
                    '<span class="admin-project-meta-label">Beschreibung</span>' +
                    '<p class="admin-project-meta-text">' + esc(state.project.description || 'Keine Beschreibung') + '</p>' +
                '</article>';
        }
    }

    function renderPhases() {
        var container = document.getElementById('projectPhasesContainer');
        if (!container) return;

        if (!Array.isArray(state.phases) || state.phases.length === 0) {
            container.innerHTML = '<p class="admin-project-empty-state">Noch keine Phasen vorhanden.</p>';
            return;
        }

        var rows = state.phases.map(function (phase) {
            var controls = canManage
                ? '<button class="admin-users-action-btn" data-action="save-phase" data-id="' + phase.id + '">Speichern</button>' +
                  '<button class="admin-users-action-btn admin-users-action-btn--danger" data-action="delete-phase" data-id="' + phase.id + '">Loeschen</button>'
                : '';

            var testDataLink = phaseTestDataUrlBase
                ? (phaseTestDataUrlBase + '/' + phase.id + '/test-data')
                : ('/projects/' + String(phase.project_id || '') + '/phase/' + phase.id + '/test-data');

            var phaseTestsUrl = phaseTestsUrlBase
                ? (phaseTestsUrlBase + '/' + phase.id + '/tests')
                : ('/projects/' + String(phase.project_id || '') + '/phase/' + phase.id + '/tests');

            var testedBy = phase.tested_by_name || 'Unbekannt';
            var testedAt = phase.test_date || '-';
            var templateName = phase.test_template_name || '-';

            var secondRowContent = '';
            if (phase.integration_tests_finished) {
                secondRowContent =
                    '<div class="admin-project-phase-meta">' +
                        '<span><strong>Tests:</strong> abgeschlossen</span>' +
                        '<span><strong>Von:</strong> ' + esc(testedBy) + '</span>' +
                        '<span><strong>Am:</strong> ' + esc(testedAt) + '</span>' +
                        '<span><strong>Template:</strong> ' + esc(templateName) + '</span>' +
                        '<a class="admin-users-action-btn" href="' + esc(testDataLink) + '" target="_blank" rel="noopener">Testdaten ansehen</a>' +
                    '</div>';
            } else if (canManage && (parseInt(phase.progress, 10) || 0) > 80) {
                var templateOptions = '<option value="">Form Template waehlen</option>';
                templateOptions += (Array.isArray(state.testTemplates) ? state.testTemplates : []).map(function (tpl) {
                    return '<option value="' + tpl.id + '">' + esc((tpl.name || 'Template') + (tpl.template_key ? ' (' + tpl.template_key + ')' : '')) + '</option>';
                }).join('');

                secondRowContent =
                    '<div class="admin-project-phase-meta">' +
                        '<span><strong>Tests:</strong> noch nicht erstellt</span>' +
                        '<span><strong>Bedingung erfuellt:</strong> Progress > 80%</span>' +
                        '<select class="admin-users-input admin-project-test-template-select" data-phase-test-template="' + phase.id + '">' + templateOptions + '</select>' +
                        '<button class="admin-users-action-btn" data-action="create-tests" data-id="' + phase.id + '" data-url="' + esc(phaseTestsUrl) + '">Tests erstellen</button>' +
                    '</div>';
            } else {
                secondRowContent =
                    '<div class="admin-project-phase-meta">' +
                        '<span><strong>Tests:</strong> noch nicht erstellt</span>' +
                        '<span>Tests koennen ab Progress > 80% gestartet werden.</span>' +
                    '</div>';
            }

            return '<tr class="admin-project-phase-row">' +
                '<td><strong>' + esc(phase.phase_name) + '</strong></td>' +
                '<td><input class="admin-users-input" data-phase-field="progress" data-id="' + phase.id + '" type="number" min="0" max="100" value="' + esc(String(phase.progress || 0)) + '"' + (canManage ? '' : ' disabled') + ' /></td>' +
                '<td>' +
                    '<select class="admin-users-input" data-phase-field="status" data-id="' + phase.id + '"' + (canManage ? '' : ' disabled') + '>' +
                        statusOptions(phase.status) +
                    '</select>' +
                '</td>' +
                '<td><input class="admin-users-input" data-phase-field="due_date" data-id="' + phase.id + '" type="date" value="' + esc(String(phase.due_date || '')) + '"' + (canManage ? '' : ' disabled') + ' /></td>' +
                '<td><div class="admin-users-actions admin-project-table-actions">' + controls + '</div></td>' +
            '</tr>' +
            '<tr class="admin-project-phase-subrow"><td colspan="5">' + secondRowContent + '</td></tr>';
        }).join('');

        container.innerHTML =
            '<table class="admin-users-table"><thead><tr><th>Phase</th><th>Progress</th><th>Status</th><th>Faellig</th><th>Aktionen</th></tr></thead><tbody>' + rows + '</tbody></table>';

        if (canManage) {
            container.querySelectorAll('[data-action="save-phase"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    savePhase(parseInt(btn.getAttribute('data-id'), 10) || 0);
                });
            });
            container.querySelectorAll('[data-action="delete-phase"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    deletePhase(parseInt(btn.getAttribute('data-id'), 10) || 0);
                });
            });
            container.querySelectorAll('[data-action="create-tests"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    createTestsForPhase(
                        parseInt(btn.getAttribute('data-id'), 10) || 0,
                        String(btn.getAttribute('data-url') || '')
                    );
                });
            });
        }
    }

    function renderMembers() {
        var container = document.getElementById('projectMembersContainer');
        if (!container) return;

        if (!Array.isArray(state.members) || state.members.length === 0) {
            container.innerHTML = '<p class="admin-project-empty-state">Noch keine Mitglieder vorhanden.</p>';
            return;
        }

        var rows = state.members.map(function (member) {
            var fullName = ((member.user && member.user.first_name) || '') + ' ' + ((member.user && member.user.last_name) || '');
            var removeBtn = canManage
                ? '<button class="admin-users-action-btn admin-users-action-btn--danger" data-action="delete-member" data-id="' + member.id + '">Entfernen</button>'
                : '';
            return '<tr>' +
                '<td><strong>' + esc(fullName.trim()) + '</strong></td>' +
                '<td>' + esc((member.user && member.user.email) || '') + '</td>' +
                '<td><span class="admin-project-status-pill">' + esc(member.role || 'developer') + '</span></td>' +
                '<td><div class="admin-users-actions admin-project-table-actions">' + removeBtn + '</div></td>' +
            '</tr>';
        }).join('');

        container.innerHTML =
            '<table class="admin-users-table"><thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Aktionen</th></tr></thead><tbody>' + rows + '</tbody></table>';

        if (canManage) {
            container.querySelectorAll('[data-action="delete-member"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    deleteMember(parseInt(btn.getAttribute('data-id'), 10) || 0);
                });
            });
        }
    }

    function renderUserSelect() {
        var select = document.getElementById('memberUserId');
        var form = document.getElementById('createMemberForm');
        var phaseForm = document.getElementById('createPhaseForm');
        if (!select) return;

        if (!canManage) {
            if (form) form.style.display = 'none';
            if (phaseForm) phaseForm.style.display = 'none';
            return;
        }

        var options = '<option value="">User auswaehlen</option>';
        options += state.users.map(function (user) {
            var name = (String(user.first_name || '') + ' ' + String(user.last_name || '')).trim();
            return '<option value="' + user.id + '">' + esc(name + ' (' + String(user.email || '') + ')') + '</option>';
        }).join('');
        select.innerHTML = options;
    }

    function statusOptions(selected) {
        var values = ['pending', 'in_progress', 'review', 'completed', 'on_hold', 'cancelled'];
        return values.map(function (value) {
            var sel = value === selected ? ' selected' : '';
            return '<option value="' + value + '"' + sel + '>' + value + '</option>';
        }).join('');
    }

    function savePhase(id) {
        if (!id) return;
        var progress = readPhaseField(id, 'progress');
        var status = readPhaseField(id, 'status');
        var dueDate = readPhaseField(id, 'due_date');

        var body = JSON.stringify({ progress: progress, status: status, due_date: dueDate });
        fetchJson(phasesUrl + '/' + id, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: body,
        }).then(function (json) {
            if (!json.success) throw new Error(json.message || 'Phase konnte nicht gespeichert werden.');
            loadAll();
        }).catch(function (err) {
            setAlert('phaseAlert', String(err.message || 'Fehler'));
        });
    }

    function deletePhase(id) {
        if (!id) return;
        fetchJson(phasesUrl + '/' + id, {
            method: 'DELETE',
            credentials: 'same-origin',
        }).then(function () {
            loadAll();
        }).catch(function (err) {
            setAlert('phaseAlert', String(err.message || 'Fehler'));
        });
    }

    function createTestsForPhase(phaseId, url) {
        if (!phaseId || !url) return;

        var select = document.querySelector('[data-phase-test-template="' + phaseId + '"]');
        var templateId = select ? parseInt(select.value, 10) || 0 : 0;
        if (!templateId) {
            setAlert('phaseAlert', 'Bitte zuerst ein Form Template auswaehlen.');
            return;
        }

        fetchJson(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ template_id: templateId }),
        }).then(function (json) {
            if (!json.success) throw new Error(json.message || 'Tests konnten nicht erstellt werden.');
            loadAll();
        }).catch(function (err) {
            setAlert('phaseAlert', String(err.message || 'Fehler'));
        });
    }

    function deleteMember(id) {
        if (!id) return;
        fetchJson(membersUrl + '/' + id, {
            method: 'DELETE',
            credentials: 'same-origin',
        }).then(function () {
            loadAll();
        }).catch(function (err) {
            setAlert('memberAlert', String(err.message || 'Fehler'));
        });
    }

    function readPhaseField(id, field) {
        var el = document.querySelector('[data-phase-field="' + field + '"][data-id="' + id + '"]');
        return el ? el.value : '';
    }

    function setAlert(id, text) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = text;
        el.style.display = '';
    }

    function bindForms() {
        var phaseForm = document.getElementById('createPhaseForm');
        if (phaseForm) {
            phaseForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!canManage) return;

                var payload = {
                    phase_name: (document.getElementById('phaseName') || {}).value || '',
                    due_date: (document.getElementById('phaseDueDate') || {}).value || '',
                    status: (document.getElementById('phaseStatus') || {}).value || 'pending',
                    progress: parseInt((document.getElementById('phaseProgress') || {}).value, 10) || 0,
                };

                fetchJson(phasesUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                }).then(function (json) {
                    if (!json.success) throw new Error(json.message || 'Phase konnte nicht angelegt werden.');
                    phaseForm.reset();
                    loadAll();
                }).catch(function (err) {
                    setAlert('phaseAlert', String(err.message || 'Fehler'));
                });
            });
        }

        var memberForm = document.getElementById('createMemberForm');
        if (memberForm) {
            memberForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!canManage) return;

                var payload = {
                    user_id: parseInt((document.getElementById('memberUserId') || {}).value, 10) || 0,
                    role: (document.getElementById('memberRole') || {}).value || 'developer',
                };

                fetchJson(membersUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                }).then(function (json) {
                    if (!json.success) throw new Error(json.message || 'Mitglied konnte nicht hinzugefuegt werden.');
                    memberForm.reset();
                    loadAll();
                }).catch(function (err) {
                    setAlert('memberAlert', String(err.message || 'Fehler'));
                });
            });
        }
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    bindForms();
    loadAll();
}());

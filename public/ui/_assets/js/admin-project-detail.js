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
        activeTestModal: null,
    };

    function fetchJson(url, options) {
        return fetch(url, options || { credentials: 'same-origin' })
            .then(function (r) {
                return r.text().then(function (txt) {
                    var json;
                    try {
                        json = JSON.parse(txt);
                    } catch (_err) {
                        throw new Error('Ungueltige Serverantwort');
                    }
                    if (!r.ok || !json.success) {
                        throw new Error(String((json && json.message) || ('HTTP ' + r.status)));
                    }
                    return json;
                });
            });
    }

    function phaseBaseUrl(phaseId) {
        if (phaseTestsUrlBase) return phaseTestsUrlBase + '/' + phaseId;
        var projectId = state.project && state.project.id ? state.project.id : (cfg.project_id || '');
        return '/projects/' + String(projectId) + '/phase/' + phaseId;
    }

    function testDataUrl(phaseId) {
        if (phaseTestDataUrlBase) return phaseTestDataUrlBase + '/' + phaseId + '/test-data';
        return phaseBaseUrl(phaseId) + '/test-data';
    }

    function loadAll() {
        return Promise.all([
            fetchJson(projectDataUrl, { credentials: 'same-origin' }),
            fetchJson(phasesUrl, { credentials: 'same-origin' }),
            fetchJson(membersUrl, { credentials: 'same-origin' }),
            canManage ? fetchJson(usersUrl, { credentials: 'same-origin' }) : Promise.resolve({ data: { users: [] } }),
        ]).then(function (responses) {
            var projectRes = responses[0] || {};
            var phaseRes = responses[1] || {};
            var memberRes = responses[2] || {};
            var usersRes = responses[3] || {};

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
            throw err;
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

            var testData = phase.test_data && typeof phase.test_data === 'object' ? phase.test_data : null;
            var hasStartedTest = !!(testData && parseInt(testData.template_id, 10) > 0);

            var secondRowContent = '';
            if (hasStartedTest) {
                secondRowContent =
                    '<div class="admin-project-phase-meta">' +
                        '<span><strong>Tests:</strong> ' + (phase.integration_tests_finished ? 'abgeschlossen' : 'in Bearbeitung') + '</span>' +
                        '<button class="admin-users-action-btn" data-action="open-test" data-id="' + phase.id + '">' + (phase.integration_tests_finished ? 'Testdaten anzeigen' : (canManage ? 'Test durchfuehren' : 'Testdaten anzeigen')) + '</button>' +
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
                        '<button class="admin-users-action-btn" data-action="create-tests" data-id="' + phase.id + '" data-url="' + esc(phaseBaseUrl(phase.id) + '/tests') + '">Tests erstellen</button>' +
                    '</div>';
            } else {
                secondRowContent =
                    '<div class="admin-project-phase-meta">' +
                        '<span><strong>Tests:</strong> noch nicht erstellt</span>' +
                        '<span>Tests koennen ab Progress > 80% gestartet werden.</span>' +
                    '</div>';
            }

            var rowActionsCell = canManage
                ? '<td><div class="admin-users-actions admin-project-table-actions">' + controls + '</div></td>'
                : '';
            var subrowColspan = canManage ? '5' : '4';

            return '<tr class="admin-project-phase-row">' +
                '<td><strong>' + esc(phase.phase_name) + '</strong></td>' +
                '<td><input class="admin-users-input" data-phase-field="progress" data-id="' + phase.id + '" type="number" min="0" max="100" value="' + esc(String(phase.progress || 0)) + '"' + (canManage ? '' : ' disabled') + ' /></td>' +
                '<td>' +
                    '<select class="admin-users-input" data-phase-field="status" data-id="' + phase.id + '"' + (canManage ? '' : ' disabled') + '>' +
                        statusOptions(phase.status) +
                    '</select>' +
                '</td>' +
                '<td><input class="admin-users-input" data-phase-field="due_date" data-id="' + phase.id + '" type="date" value="' + esc(String(phase.due_date || '')) + '"' + (canManage ? '' : ' disabled') + ' /></td>' +
                rowActionsCell +
            '</tr>' +
            '<tr class="admin-project-phase-subrow"><td colspan="' + subrowColspan + '">' + secondRowContent + '</td></tr>';
        }).join('');

        var phaseActionsHeader = canManage ? '<th>Aktionen</th>' : '';
        container.innerHTML =
            '<table class="admin-users-table"><thead><tr><th>Phase</th><th>Progress</th><th>Status</th><th>Faellig</th>' + phaseActionsHeader + '</tr></thead><tbody>' + rows + '</tbody></table>';

        container.querySelectorAll('[data-action="open-test"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openTestModal(parseInt(btn.getAttribute('data-id'), 10) || 0);
            });
        });

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

    function openTestModal(phaseId) {
        if (!phaseId) return;
        var phase = state.phases.find(function (item) { return item.id === phaseId; });
        if (!phase || !phase.test_data || parseInt(phase.test_data.template_id, 10) <= 0) {
            setAlert('phaseAlert', 'Fuer diese Phase wurden noch keine Tests initialisiert.');
            return;
        }

        var testData = phase.test_data;
        var schema = Array.isArray(testData.schema_json) ? testData.schema_json : [];
        var payload = (testData.payload_json && typeof testData.payload_json === 'object') ? testData.payload_json : {};
        var attachments = Array.isArray(testData.attachments) ? testData.attachments : [];
        var isCompleted = !!phase.integration_tests_finished;
        var readOnly = !canManage || isCompleted;
        var canUploadAttachments = !!canManage;

        state.activeTestModal = {
            phaseId: phaseId,
            schema: schema,
            payload: payload,
            readOnly: readOnly,
        };

        var body = '' +
            '<section class="admin-project-test-modal">' +
            '  <div class="admin-project-test-meta">' +
            '    <span><strong>Phase:</strong> ' + esc(phase.phase_name || ('#' + phaseId)) + '</span>' +
            '    <span><strong>Template:</strong> ' + esc(testData.template_name || '-') + '</span>' +
            '    <span><strong>Version:</strong> v' + esc(String(testData.template_version_no || '-')) + '</span>' +
            '    <span><strong>Status:</strong> ' + esc(phase.integration_tests_finished ? 'abgeschlossen' : 'in Bearbeitung') + '</span>' +
            '  </div>' +
            '  <div id="projectTestFormRoot">' + renderTestForm(schema, payload, readOnly) + '</div>' +
            '  <div class="admin-project-test-actions">' +
            (readOnly ? '' : '<button type="button" class="admin-users-action-btn" id="projectTestSaveBtn">Testdaten speichern</button>') +
            '    <span id="projectTestSaveStatus" class="admin-project-test-status"></span>' +
            '  </div>' +
            '  <hr class="admin-project-test-divider" />' +
            '  <div class="admin-project-test-attachments">' +
            '    <h4>Anhaenge</h4>' +
            (canUploadAttachments ?
            '    <form id="projectTestAttachmentForm" class="admin-project-test-upload" enctype="multipart/form-data">' +
            '      <input type="file" id="projectTestAttachmentFile" class="admin-users-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx,.ppt,.pptx" required />' +
            '      <button type="submit" class="admin-users-action-btn">Anhang hochladen</button>' +
            '    </form>' : '') +
            '    <div id="projectTestAttachmentList">' + renderAttachmentList(phaseId, attachments) + '</div>' +
            '  </div>' +
            '</section>';

        if (window.adminOpenModal) {
            window.adminOpenModal((isCompleted ? 'Testdaten anzeigen: ' : 'Phasentest: ') + (phase.phase_name || ('#' + phaseId)), body, {
                type: 'form',
                modalClass: 'admin-modal--preview',
                buttons: [{ label: 'Schliessen', variant: 'secondary', onClick: function () { window.adminCloseModal && window.adminCloseModal(); } }],
            });
        }

        bindTestModalEvents(phaseId);
    }

    function bindTestModalEvents(phaseId) {
        var saveBtn = document.getElementById('projectTestSaveBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                savePhaseTestData(phaseId);
            });
        }

        var uploadForm = document.getElementById('projectTestAttachmentForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function (e) {
                e.preventDefault();
                uploadPhaseTestAttachment(phaseId);
            });
        }

        var attachmentList = document.getElementById('projectTestAttachmentList');
        if (attachmentList) {
            attachmentList.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('[data-download-attachment]') : null;
                if (!btn) return;
                var attachmentId = String(btn.getAttribute('data-download-attachment') || '');
                if (attachmentId === '') return;
                window.open(testDataUrl(phaseId) + '/attachments/' + encodeURIComponent(attachmentId) + '/download', '_blank', 'noopener');
            });
        }
    }

    function renderTestForm(schema, payload, readOnly) {
        if (!Array.isArray(schema) || schema.length === 0) {
            return '<div class="admin-project-empty-state">Dieses Template hat kein ausfuellbares Schema.</div>';
        }

        var letterhead = null;
        var sections = [];

        schema.forEach(function (item) {
            if (!item || typeof item !== 'object') return;
            var type = String(item.type || '').toLowerCase();
            if (type === 'letterhead') {
                letterhead = item;
                return;
            }
            if (type === 'section') {
                sections.push(renderSection(item, payload, readOnly));
                return;
            }
            if (String(item.field_key || '').trim() !== '' && type !== '') {
                sections.push('<div class="admin-project-test-fields">' + renderField(item, payload, readOnly) + '</div>');
            }
        });

        if (sections.length === 0) {
            return '<div class="admin-project-empty-state">Dieses Template hat keine ausfuellbaren Felder.</div>';
        }

        var header = '';
        if (letterhead) {
            header = '' +
                '<header class="admin-project-test-letterhead">' +
                (letterhead.practice_name ? '<div class="admin-project-test-practice">' + esc(String(letterhead.practice_name)) + '</div>' : '') +
                (letterhead.form_title ? '<h3 class="admin-project-test-title">' + esc(String(letterhead.form_title)) + '</h3>' : '') +
                (letterhead.subtitle ? '<p class="admin-project-test-subtitle">' + esc(String(letterhead.subtitle)) + '</p>' : '') +
                '</header>';
        }

        return '<div class="admin-project-test-shell">' + header + sections.join('') + '</div>';
    }

    function renderSection(section, payload, readOnly) {
        var items = Array.isArray(section.items) ? section.items : [];
        var fields = items.map(function (item) { return renderField(item, payload, readOnly); }).filter(Boolean);
        if (fields.length === 0) return '';

        return '' +
            '<section class="admin-project-test-section">' +
            (section.label ? '<h4 class="admin-project-test-section-title">' + esc(String(section.label)) + '</h4>' : '') +
            (section.description ? '<p class="admin-project-test-section-desc">' + esc(String(section.description)) + '</p>' : '') +
            '<div class="admin-project-test-fields">' + fields.join('') + '</div>' +
            '</section>';
    }

    function renderField(field, payload, readOnly) {
        if (!field || typeof field !== 'object') return '';

        var key = String(field.field_key || '').trim();
        var type = String(field.type || 'text').toLowerCase();
        if (key === '' || type === 'section' || type === 'letterhead') return '';

        var label = String(field.label || key);
        var value = payload && Object.prototype.hasOwnProperty.call(payload, key) ? payload[key] : '';
        var requiredMark = field.required ? ' *' : '';
        var disabledAttr = readOnly ? ' disabled' : '';

        var control = '';
        if (type === 'textarea') {
            control = '<textarea class="admin-users-input admin-project-test-input" data-test-field="' + esc(key) + '" data-test-type="textarea" rows="4"' + disabledAttr + '>' + esc(String(value || '')) + '</textarea>';
        } else if (type === 'number') {
            control = '<input class="admin-users-input admin-project-test-input" data-test-field="' + esc(key) + '" data-test-type="number" type="number" step="any" value="' + esc(String(value || '')) + '"' + disabledAttr + ' />';
        } else if (type === 'date') {
            control = '<input class="admin-users-input admin-project-test-input" data-test-field="' + esc(key) + '" data-test-type="date" type="date" value="' + esc(toDateInput(value)) + '"' + disabledAttr + ' />';
        } else if (type === 'radio' || type === 'checkbox_multiple' || type === 'checkbox_single') {
            control = renderChoiceGroup(key, type, field.options, value, readOnly);
        } else {
            control = '<input class="admin-users-input admin-project-test-input" data-test-field="' + esc(key) + '" data-test-type="text" type="text" value="' + esc(String(value || '')) + '"' + disabledAttr + ' />';
        }

        return '' +
            '<div class="admin-project-test-field">' +
            '  <label class="admin-users-label">' + esc(label) + requiredMark + '</label>' +
            '  ' + control +
            '  <div class="admin-project-test-error" data-test-error="' + esc(key) + '"></div>' +
            '</div>';
    }

    function renderChoiceGroup(fieldKey, type, options, currentValue, readOnly) {
        var normalized = Array.isArray(options) && options.length > 0 ? options : (type === 'checkbox_single' ? ['Ja, bestaetigen'] : ['Option 1']);
        var disabledAttr = readOnly ? ' disabled' : '';
        var selectedArray = Array.isArray(currentValue) ? currentValue : [];
        var selectedSingle = currentValue == null ? '' : String(currentValue);
        var inputType = type === 'radio' ? 'radio' : 'checkbox';

        return '<div class="admin-project-test-choice-group">' + normalized.map(function (opt) {
            var value = String(opt);
            var checked = false;
            if (type === 'radio') checked = selectedSingle === value;
            else if (type === 'checkbox_single') checked = selectedSingle === value;
            else checked = selectedArray.indexOf(value) !== -1;

            return '' +
                '<label class="admin-project-test-choice">' +
                '  <input type="' + inputType + '" data-test-field="' + esc(fieldKey) + '" data-test-type="' + esc(type) + '" name="test_' + esc(fieldKey) + '" value="' + esc(value) + '" ' + (checked ? 'checked ' : '') + disabledAttr + ' />' +
                '  <span>' + esc(value) + '</span>' +
                '</label>';
        }).join('') + '</div>';
    }

    function collectModalPayload(schema) {
        var fields = flattenSchemaFields(schema);
        var payload = {};
        var errors = {};

        fields.forEach(function (field) {
            var key = field.field_key;
            var value = readFieldValue(key, field.type);

            if (field.type === 'checkbox_multiple') {
                if (Array.isArray(value) && value.length > 0) payload[key] = value;
            } else if (field.type === 'number') {
                if (String(value).trim() !== '') {
                    var normalized = String(value).replace(',', '.');
                    if (!/^[-+]?\d+(\.\d+)?$/.test(normalized)) {
                        errors[key] = 'Muss numerisch sein.';
                    } else {
                        payload[key] = Number(normalized);
                    }
                }
            } else if (field.type === 'date') {
                if (String(value).trim() !== '') {
                    payload[key] = toGermanDate(String(value));
                }
            } else if (String(value).trim() !== '') {
                payload[key] = String(value).trim();
            }

            if (field.required) {
                var hasValue = Object.prototype.hasOwnProperty.call(payload, key);
                if (!hasValue || isEmptyValue(payload[key])) {
                    errors[key] = 'Pflichtfeld ist erforderlich.';
                }
            }
        });

        return { payload: payload, errors: errors };
    }

    function savePhaseTestData(phaseId) {
        var ctx = state.activeTestModal;
        if (!ctx || ctx.phaseId !== phaseId) return;

        var statusNode = document.getElementById('projectTestSaveStatus');
        if (statusNode) statusNode.textContent = '';

        clearAllTestErrors();
        var collected = collectModalPayload(ctx.schema);
        var keys = Object.keys(collected.errors);
        if (keys.length > 0) {
            keys.forEach(function (key) {
                renderTestError(key, collected.errors[key]);
            });
            if (statusNode) statusNode.textContent = 'Bitte markierte Felder korrigieren.';
            return;
        }

        if (statusNode) statusNode.textContent = 'Speichere Testdaten...';

        fetchJson(testDataUrl(phaseId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payload_json: collected.payload }),
        }).then(function () {
            if (statusNode) statusNode.textContent = 'Gespeichert.';
            return loadAll();
        }).then(function () {
            openTestModal(phaseId);
        }).catch(function (err) {
            if (statusNode) statusNode.textContent = 'Speichern fehlgeschlagen.';
            setAlert('phaseAlert', String(err.message || 'Fehler'));
        });
    }

    function uploadPhaseTestAttachment(phaseId) {
        var fileInput = document.getElementById('projectTestAttachmentFile');
        var file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        if (!file) {
            setAlert('phaseAlert', 'Bitte zuerst eine Datei auswaehlen.');
            return;
        }

        var formData = new FormData();
        formData.append('file', file);

        fetch(testDataUrl(phaseId) + '/attachments', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        }).then(function (r) {
            return r.text().then(function (txt) {
                var json = {};
                try {
                    json = JSON.parse(txt);
                } catch (_err) {
                    throw new Error('Ungueltige Serverantwort');
                }
                if (!r.ok || !json.success) {
                    throw new Error(String(json.message || 'Upload fehlgeschlagen'));
                }
                return json;
            });
        }).then(function () {
            if (fileInput) fileInput.value = '';
            return loadAll();
        }).then(function () {
            openTestModal(phaseId);
        }).catch(function (err) {
            setAlert('phaseAlert', String(err.message || 'Upload fehlgeschlagen'));
        });
    }

    function renderAttachmentList(phaseId, attachments) {
        if (!Array.isArray(attachments) || attachments.length === 0) {
            return '<p class="admin-project-empty-state">Noch keine Anhaenge vorhanden.</p>';
        }

        var rows = attachments.map(function (item) {
            var id = String(item && item.id ? item.id : '');
            return '<tr>' +
                '<td>' + esc(String(item && item.original_filename ? item.original_filename : '-')) + '</td>' +
                '<td>' + esc(formatBytes(item && item.size_bytes ? item.size_bytes : 0)) + '</td>' +
                '<td>' + esc(formatDateTime(item && item.uploaded_at ? item.uploaded_at : '')) + '</td>' +
                '<td>' + (id ? '<button type="button" class="admin-users-action-btn" data-download-attachment="' + esc(id) + '" data-phase-id="' + phaseId + '">Download</button>' : '-') + '</td>' +
                '</tr>';
        }).join('');

        return '<div class="admin-project-detail-table-wrap"><table class="admin-users-table"><thead><tr><th>Datei</th><th>Groesse</th><th>Hochgeladen</th><th>Aktion</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    function flattenSchemaFields(schema) {
        var out = [];
        if (!Array.isArray(schema)) return out;

        schema.forEach(function (item) {
            if (!item || typeof item !== 'object') return;
            var type = String(item.type || '').toLowerCase();
            if (type === 'letterhead') return;
            if (type === 'section') {
                var nested = flattenSchemaFields(Array.isArray(item.items) ? item.items : []);
                nested.forEach(function (n) { out.push(n); });
                return;
            }
            if (String(item.field_key || '').trim() === '') return;
            out.push({
                field_key: String(item.field_key),
                type: type,
                required: !!item.required,
            });
        });

        return out;
    }

    function readFieldValue(fieldKey, type) {
        var nodes = document.querySelectorAll('[data-test-field="' + cssEscape(fieldKey) + '"]');
        if (!nodes || nodes.length === 0) return '';

        if (type === 'checkbox_multiple') {
            var values = [];
            nodes.forEach(function (node) {
                if (node.checked) values.push(String(node.value || ''));
            });
            return values;
        }

        if (type === 'checkbox_single') {
            var selected = '';
            nodes.forEach(function (node) {
                if (node.checked) selected = String(node.value || '');
            });
            return selected;
        }

        if (type === 'radio') {
            var radioValue = '';
            nodes.forEach(function (node) {
                if (node.checked) radioValue = String(node.value || '');
            });
            return radioValue;
        }

        return String(nodes[0].value || '').trim();
    }

    function clearAllTestErrors() {
        document.querySelectorAll('[data-test-error]').forEach(function (node) {
            node.textContent = '';
        });
    }

    function renderTestError(fieldKey, message) {
        var errorNode = document.querySelector('[data-test-error="' + cssEscape(fieldKey) + '"]');
        if (errorNode) errorNode.textContent = String(message || '');
    }

    function isEmptyValue(value) {
        if (value === null || value === undefined) return true;
        if (Array.isArray(value)) return value.length === 0;
        if (typeof value === 'string') return value.trim() === '';
        return false;
    }

    function toGermanDate(iso) {
        var value = String(iso || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
        var parts = value.split('-');
        return parts[2] + '.' + parts[1] + '.' + parts[0];
    }

    function toDateInput(value) {
        var text = String(value || '').trim();
        if (text === '') return '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text;
        var m = text.match(/^(\d{2})\.(\d{2})\.(\d{4})/);
        if (!m) return '';
        return m[3] + '-' + m[2] + '-' + m[1];
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
            var memberActionsCell = canManage
                ? '<td><div class="admin-users-actions admin-project-table-actions">' + removeBtn + '</div></td>'
                : '';
            return '<tr>' +
                '<td><strong>' + esc(fullName.trim()) + '</strong></td>' +
                '<td>' + esc((member.user && member.user.email) || '') + '</td>' +
                '<td><span class="admin-project-status-pill">' + esc(member.role || 'developer') + '</span></td>' +
                memberActionsCell +
            '</tr>';
        }).join('');

        var memberActionsHeader = canManage ? '<th>Aktionen</th>' : '';
        container.innerHTML =
            '<table class="admin-users-table"><thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th>' + memberActionsHeader + '</tr></thead><tbody>' + rows + '</tbody></table>';

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
        }).then(function () {
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
        }).then(function () {
            return loadAll();
        }).then(function () {
            openTestModal(phaseId);
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
                }).then(function () {
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
                }).then(function () {
                    memberForm.reset();
                    loadAll();
                }).catch(function (err) {
                    setAlert('memberAlert', String(err.message || 'Fehler'));
                });
            });
        }
    }

    function formatDateTime(value) {
        if (!value) return '-';
        var date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString('de-DE', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function formatBytes(bytes) {
        var value = parseInt(bytes, 10) || 0;
        if (value <= 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var idx = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
        var normalized = value / Math.pow(1024, idx);
        return normalized.toFixed(idx === 0 ? 0 : 1) + ' ' + units[idx];
    }

    function cssEscape(value) {
        var text = String(value || '');
        if (window.CSS && window.CSS.escape) return window.CSS.escape(text);
        return text.replace(/([ #;?%&,.+*~\':\"!^$\[\]()=>|\/@])/g, '\\$1');
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

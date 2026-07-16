/* global adminOpenModal, adminCloseModal */
(function () {
    'use strict';

    var cfg = window.__ADMIN_PROJECTS_CONFIG || {};
    var dataUrl = cfg.data_url || '/admin/projects/data';
    var clientsDataUrl = cfg.clients_data_url || '/clients/data';
    var canManage = !!cfg.can_manage;
    var canManageAdminSettings = !!cfg.can_manage_admin_settings;
    var currentProjectId = parseInt(cfg.current_project_id || 0, 10) || 0;
    var currentRoleMask = parseInt(cfg.current_role_mask || 0, 10) || 0;
    var permissionCatalog = Array.isArray(cfg.permission_catalog)
        ? cfg.permission_catalog
            .map(function (item) {
                var bit = parseInt(item.bit_value, 10) || 0;
                return {
                    slug: String(item.slug || ''),
                    name: String(item.name || item.slug || ''),
                    description: String(item.description || ''),
                    bit_value: bit,
                };
            })
            .filter(function (item) { return item.bit_value > 0; })
            .sort(function (a, b) { return a.bit_value - b.bit_value; })
        : [];

    var root = document.getElementById('adminProjectsRoot');
    if (!root) return;

    // ── State ────────────────────────────────────────────────────────────────

    var state = {
        page: 1,
        perPage: cfg.per_page || 20,
        totalPages: 1,
        q: '',
        projects: [],
        clients: [],
        clientsLoaded: false,
        clientsLoading: false,
        loading: false,
    };

    // ── Render toolbar + table ────────────────────────────────────────────────

    function render() {
        root.innerHTML =
            '<div class="admin-users-toolbar">' +
                '<input type="search" id="projectsSearch" class="admin-users-search" ' +
                    'placeholder="Suche nach Projektname, Beschreibung oder Klientenname\u2026" value="' + escHtml(state.q) + '" />' +
            '</div>' +
            '<div id="projectsTableContainer"></div>' +
            '<div class="admin-users-pagination" id="projectsPagination"></div>';

        document.getElementById('projectsSearch').addEventListener('input', debounce(function (e) {
            state.q = e.target.value;
            state.page = 1;
            loadProjects();
        }, 350));

        renderTable();
        renderPagination();
    }

    function renderTable() {
        var container = document.getElementById('projectsTableContainer');
        if (!container) return;

        if (state.loading) {
            container.innerHTML = '<p class="admin-projects-loading">Projekte werden geladen\u2026</p>';
            return;
        }

        if (state.projects.length === 0) {
            container.innerHTML = '<p class="admin-projects-empty">Keine Projekte gefunden.</p>';
            return;
        }
        
        var rows = state.projects.map(function (p) {
            var badge = p.is_active
                ? '<span class="admin-projects-badge admin-projects-badge--active">Aktiv</span>'
                : '<span class="admin-projects-badge admin-projects-badge--inactive">Inaktiv</span>';

            var actions =
                '<button class="admin-projects-action-btn" data-action="edit" data-id="' + p.id + '">Bearbeiten</button>';

            if (canManage) {
                actions += '<button class="admin-projects-action-btn admin-projects-action-btn--danger" data-action="delete" data-id="' + p.id + '">Löschen</button>';
            }

            return '<tr>' +
                '<td><a href="/projects/' + p.id + '" class="admin-users-action-btn" style="display:inline-flex;padding:4px 8px;text-decoration:none">' + escHtml(p.name) + '</a></td>' +
                '<td>' + escHtml(p.client_name) + '</td>' +
                '<td>' + escHtml(p.description) + '</td>' +
                '<td>' + badge + '</td>' +
                '<td>' + escHtml(p.status) + '</td>' +
                '<td><div class="admin-projects-actions">' + actions + '</div></td>' +
                '</tr>';
        }).join('');

        container.innerHTML =
            '<table class="admin-projects-table">' +
                '<thead><tr>' +
                    '<th>Name</th><th>Client</th><th>Beschreibung</th><th>Aktiv</th><th>Status</th><th>Aktionen</th>' +
                '</tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
            '</table>';

        container.querySelectorAll('[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-action');
                var id = parseInt(btn.getAttribute('data-id'), 10);
                if (action === 'edit') openEditModal(id);
                if (action === 'delete') confirmDelete(id);
            });
        });
    }

    function renderPagination() {
        var pag = document.getElementById('projectsPagination');
        if (!pag) return;
        if (state.totalPages <= 1) { pag.innerHTML = ''; return; }

        var html = '';
        for (var i = 1; i <= state.totalPages; i++) {
            html += '<button class="admin-projects-page-btn' +
                (i === state.page ? ' admin-projects-page-btn--active' : '') +
                '" data-page="' + i + '">' + i + '</button>';
        }
        pag.innerHTML = html;
        pag.querySelectorAll('[data-page]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                state.page = parseInt(btn.getAttribute('data-page'), 10);
                loadProjects();
            });
        });
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    function loadProjects() {
        state.loading = true;
        renderTable();

        var url = dataUrl + '?page=' + state.page + '&per_page=' + state.perPage;
        if (state.q) url += '&q=' + encodeURIComponent(state.q);

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success) throw new Error(json.message || 'Fehler');
                state.projects = json.data.projects || [];
                state.totalPages = json.data.meta.total_pages || 1;
                state.loading = false;
                renderTable();
                renderPagination();
            })
            .catch(function (err) {
                state.loading = false;
                var container = document.getElementById('projectsTableContainer');
                if (container) {
                    container.innerHTML = '<p class="admin-projects-empty">Fehler beim Laden: ' + escHtml(err.message) + '</p>';
                }
            });
    }

    // ── Create modal ──────────────────────────────────────────────────────────

    function openCreateModal(draft) {
        var createDraft = draft || {
            name: '',
            client_id: '',
            description: '',
            due_date: '',
            status: 'pending',
            is_active: 1,
        };

        var clientOptions = '<option value="">Bitte Klient auswaehlen</option>';
        if (state.clientsLoaded && state.clients.length > 0) {
            clientOptions += state.clients.map(function (client) {
                var selected = String(client.id) === String(createDraft.client_id) ? ' selected' : '';
                return '<option value="' + client.id + '"' + selected + '>' + escHtml(client.display_name) + '</option>';
            }).join('');
        }

        var statusOptions = [
            { value: 'pending', label: 'Pending' },
            { value: 'backlog', label: 'Backlog' },
            { value: 'in_progress', label: 'In Progress' },
            { value: 'review', label: 'Review' },
            { value: 'completed', label: 'Completed' },
            { value: 'on_hold', label: 'On Hold' },
            { value: 'cancelled', label: 'Cancelled' },
        ].map(function (entry) {
            var selected = entry.value === createDraft.status ? ' selected' : '';
            return '<option value="' + entry.value + '"' + selected + '>' + entry.label + '</option>';
        }).join('');

        var clientHint = state.clientsLoading
            ? '<small class="admin-users-permission-helper">Klienten werden geladen...</small>'
            : (state.clientsLoaded && state.clients.length === 0
                ? '<small class="admin-users-permission-helper">Keine Klienten verfuegbar.</small>'
                : '');

        var body =
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="cpName">Projektname *</label>' +
                '<input type="text" id="cpName" class="admin-users-input" value="' + escHtml(createDraft.name) + '" />' +
            '</div>' +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="cpClientId">Klient *</label>' +
                '<select id="cpClientId" class="admin-users-input"' + (state.clientsLoading ? ' disabled' : '') + '>' +
                    clientOptions +
                '</select>' +
                clientHint +
            '</div>' +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="cpDescription">Beschreibung</label>' +
                '<textarea id="cpDescription" class="admin-users-input">' + escHtml(createDraft.description) + '</textarea>' +
            '</div>' +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="cpDueDate">Faellig am</label>' +
                '<input type="date" id="cpDueDate" class="admin-users-input" value="' + escHtml(createDraft.due_date) + '" />' +
            '</div>' +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="cpStatus">Status</label>' +
                '<select id="cpStatus" class="admin-users-input">' +
                    statusOptions +
                '</select>' +
            '</div>' +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label admin-users-label--checkbox">' +
                    '<input type="checkbox" id="cpActive"' + (createDraft.is_active ? ' checked' : '') + ' /> Projekt aktiv' +
                '</label>' +
            '</div>' +
            '<div id="cpAlert" class="admin-users-alert" style="display:none"></div>';

        adminOpenModal('Neues Projekt anlegen', body, {
            type: 'form',
            buttons: [
                {
                    label: 'Projekt anlegen',
                    variant: 'primary',
                    onClick: submitCreate,
                },
                { label: 'Abbrechen', variant: 'secondary', onClick: adminCloseModal },
            ],
        });

        if (!state.clientsLoaded && !state.clientsLoading) {
            loadClientsForCreate(createDraft);
        }
    }

    function loadClientsForCreate(createDraft) {
        state.clientsLoading = true;

        fetch(clientsDataUrl + '?page=1&per_page=200&sort=last_name&direction=asc', {
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success) throw new Error(json.message || 'Klienten konnten nicht geladen werden.');

                state.clients = (json.data && Array.isArray(json.data.clients) ? json.data.clients : [])
                    .map(function (client) {
                        var displayNameFromName = String(client.name || '').trim();
                        var firstName = String(client.first_name || '').trim();
                        var lastName = String(client.last_name || '').trim();
                        var fullName = (firstName + ' ' + lastName).trim();
                        var displayName = displayNameFromName !== '' ? displayNameFromName : fullName;
                        return {
                            id: parseInt(client.id, 10) || 0,
                            display_name: displayName !== '' ? displayName : ('Klient #' + String(client.id || '')),
                        };
                    })
                    .filter(function (client) { return client.id > 0; });

                state.clientsLoaded = true;
                state.clientsLoading = false;
                openCreateModal(createDraft || {});
            })
            .catch(function () {
                state.clientsLoading = false;
                state.clientsLoaded = true;
                state.clients = [];
                openCreateModal(createDraft || {});
            });
    }

    function submitCreate() {
        var name = document.getElementById('cpName') ? document.getElementById('cpName').value.trim() : '';
        var clientId = document.getElementById('cpClientId') ? parseInt(document.getElementById('cpClientId').value, 10) : 0;
        var description = document.getElementById('cpDescription') ? document.getElementById('cpDescription').value.trim() : '';
        var dueDate = document.getElementById('cpDueDate') ? document.getElementById('cpDueDate').value.trim() : '';
        var status = document.getElementById('cpStatus') ? document.getElementById('cpStatus').value : 'pending';
        var isActive = document.getElementById('cpActive') && document.getElementById('cpActive').checked ? 1 : 0;
        var alert = document.getElementById('cpAlert');

        if (!name || !clientId) {
            if (alert) {
                alert.textContent = 'Bitte Projektname und Klient auswaehlen.';
                alert.style.display = '';
            }
            return;
        }

        var body = new URLSearchParams({
            name: name,
            client_id: String(clientId),
            description: description,
            due_date: dueDate,
            status: status,
            is_active: String(isActive),
        });

        fetch(dataUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success) {
                    if (alert) {
                        alert.textContent = json.message || 'Fehler beim Anlegen.';
                        alert.style.display = '';
                    }
                    return;
                }

                adminCloseModal();
                loadProjects();
            })
            .catch(function () {
                if (alert) {
                    alert.textContent = 'Netzwerkfehler. Bitte erneut versuchen.';
                    alert.style.display = '';
                }
            });
    }

    // ── Edit modal ────────────────────────────────────────────────────────────

    function openEditModal(id) {
        var project = state.projects.find(function (u) { return u.id === id; });
        if (!project) return;

        var editDraft = {
            id: id,
            first_name: project.first_name,
            last_name: project.last_name,
            role_mask: parseInt(project.role_mask, 10) || 0,
            is_active: !!project.is_active,
        };

        renderEditModal(editDraft);
    }

    function renderEditModal(editDraft) {
        var isSelf = (editDraft.id === currentProjectId);
        var isActiveChecked = editDraft.is_active ? ' checked' : '';
        var isActiveDisabled = isSelf ? ' disabled' : '';
        var selfActiveHint = isSelf
            ? '<span class="admin-projects-permission-locked">\uD83D\uDD12 Eigener Account kann nicht deaktiviert werden.</span>'
            : '';

        var body =
            '<div class="admin-projects-field">' +
                '<label class="admin-projects-label" for="euFn">Vorname</label>' +
                '<input type="text" id="euFn" class="admin-projects-input" value="' + escHtml(editDraft.first_name) + '" />' +
            '</div>' +
            '<div class="admin-projects-field">' +
                '<label class="admin-projects-label" for="euLn">Nachname</label>' +
                '<input type="text" id="euLn" class="admin-projects-input" value="' + escHtml(editDraft.last_name) + '" />' +
            '</div>' +
            (function () {
                var isSelf = (editDraft.id === currentProjectId);
                var permBtn = isSelf
                    ? '<span class="admin-projects-permission-locked">\uD83D\uDD12 Eigene Berechtigungen k\xF6nnen nicht bearbeitet werden.</span>'
                    : '<button type="button" id="euPermissionsBtn" class="admin-projects-action-btn">Berechtigungen bearbeiten</button>';
                return '<div class="admin-projects-field">' +
                    '<label class="admin-projects-label">Berechtigungen</label>' +
                    permBtn +
                    '<div id="euPermSummary" class="admin-projects-permission-summary">' + escHtml(permissionSummary(editDraft.role_mask)) + '</div>' +
                    '</div>';
            }()) +
            '<div class="admin-projects-field">' +
                '<label class="admin-projects-label admin-projects-label--checkbox">' +
                    '<input type="checkbox" id="euActive"' + isActiveChecked + isActiveDisabled + ' /> Benutzer aktiv' +
                '</label>' +
                selfActiveHint +
            '</div>' +
            '<div id="euAlert" class="admin-projects-alert" style="display:none"></div>';

        adminOpenModal('Benutzer bearbeiten', body, {
            type: 'form',
            buttons: [
                {
                    label: 'Speichern',
                    variant: 'primary',
                    onClick: function () { submitEdit(editDraft.id, editDraft.role_mask); },
                },
                { label: 'Abbrechen', variant: 'secondary', onClick: adminCloseModal },
            ],
        });

        bindEditPermissionButton(editDraft);
    }

    function bindEditPermissionButton(editDraft) {
        var btn = document.getElementById('euPermissionsBtn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            editDraft.first_name = document.getElementById('euFn') ? document.getElementById('euFn').value.trim() : '';
            editDraft.last_name = document.getElementById('euLn') ? document.getElementById('euLn').value.trim() : '';
            editDraft.is_active = document.getElementById('euActive') ? !!document.getElementById('euActive').checked : editDraft.is_active;

            openPermissionModal({
                title: 'Berechtigungen bearbeiten',
                roleMask: editDraft.role_mask,
                onApply: function (nextMask) {
                    editDraft.role_mask = nextMask;
                    renderEditModal(editDraft);
                },
            });
        });
    }

    function submitEdit(id, roleMask) {
        var fn       = document.getElementById('euFn')    ? document.getElementById('euFn').value.trim()    : '';
        var ln       = document.getElementById('euLn')    ? document.getElementById('euLn').value.trim()    : '';
        var isActive = document.getElementById('euActive') ? (document.getElementById('euActive').checked ? 1 : 0) : 1;
        var alert    = document.getElementById('euAlert');

        var payload = { first_name: fn, last_name: ln, is_active: isActive };
        if (id !== currentProjectId) {
            payload.role_mask = roleMask;
        }

        fetch(dataUrl + '/' + id, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success) {
                    if (alert) { alert.textContent = json.message || 'Fehler beim Speichern.'; alert.style.display = ''; }
                    return;
                }
                adminCloseModal();
                loadProjects();
            })
            .catch(function () {
                if (alert) { alert.textContent = 'Netzwerkfehler.'; alert.style.display = ''; }
            });
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    function confirmDelete(id) {
        var project = state.projects.find(function (u) { return u.id === id; });
        var name = project ? project.first_name + ' ' + project.last_name : 'diesen Benutzer';

        adminOpenModal('Benutzer löschen', '<p>Soll ' + escHtml(name) + ' wirklich gelöscht werden?</p>', {
            type: 'form',
            buttons: [
                {
                    label: 'Löschen',
                    variant: 'danger',
                    onClick: function () { executeDelete(id); },
                },
                { label: 'Abbrechen', variant: 'secondary', onClick: adminCloseModal },
            ],
        });
    }

    function executeDelete(id) {
        fetch(dataUrl + '/' + id, {
            method: 'DELETE',
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success) {
                    adminOpenModal('Fehler', '<p>' + escHtml(json.message || 'Unbekannter Fehler') + '</p>');
                    return;
                }
                adminCloseModal();
                loadProjects();
            });
    }

    // ── Invite ────────────────────────────────────────────────────────────────

    function regenerateInvite(id) {
        fetch(dataUrl + '/' + id + '/invite', {
            method: 'POST',
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success) {
                    adminOpenModal('Fehler', '<p>' + escHtml(json.message || 'Fehler') + '</p>');
                    return;
                }
                showInviteLink(json.data.invite_link);
            });
    }

    function showInviteLink(link) {
        adminOpenModal(
            'Einladungslink',
            '<p style="font-size:0.88rem;color:var(--admin-muted);margin:0 0 0.5rem">' +
                'Link kopieren und an den Benutzer senden. Gültig für 2 Stunden.' +
            '</p>' +
            '<div class="admin-projects-invite-link-box">' + escHtml(link) + '</div>',
            {
                type: 'form',
                buttons: [
                    {
                        label: 'Kopieren',
                        variant: 'secondary',
                        onClick: function () {
                            if (navigator.clipboard) {
                                navigator.clipboard.writeText(link);
                            }
                        },
                    },
                    { label: 'Schließen', variant: 'primary', onClick: adminCloseModal },
                ],
            }
        );
    }

    // ── Permissions modal ────────────────────────────────────────────────────

    function openPermissionModal(options) {
        var roleMask = parseInt(options.roleMask || 0, 10) || 0;
        var editableMask = editablePermissionMask();

        var rows = permissionCatalog.map(function (perm) {
            var checked = (roleMask & perm.bit_value) !== 0 ? ' checked' : '';
            var canEditThis = canManageAdminSettings || ((editableMask & perm.bit_value) !== 0);
            var disabled = canEditThis ? '' : ' disabled';
            var desc = perm.description ? '<span class="admin-projects-permission-desc">' + escHtml(perm.description) + '</span>' : '';

            return '<label class="admin-projects-permission-item' + (canEditThis ? '' : ' admin-projects-permission-item--locked') + '">' +
                '<input type="checkbox" class="admin-projects-permission-check" data-bit="' + perm.bit_value + '"' + checked + disabled + ' />' +
                '<span class="admin-projects-permission-label">' + escHtml(perm.name) + ' <span class="admin-projects-permission-bit">(' + perm.bit_value + ')</span></span>' +
                desc +
            '</label>';
        }).join('');

        var helperText = canManageAdminSettings
            ? 'Du kannst alle Berechtigungen bearbeiten.'
            : 'Du kannst nur Berechtigungen bearbeiten, die du selbst besitzt.';

        var body =
            '<p class="admin-projects-permission-helper">' + escHtml(helperText) + '</p>' +
            '<div class="admin-projects-permission-list">' + rows + '</div>';

        adminOpenModal(options.title || 'Berechtigungen', body, {
            type: 'form',
            buttons: [
                {
                    label: 'Übernehmen',
                    variant: 'primary',
                    onClick: function () {
                        var nextMask = collectPermissionMask(roleMask, editableMask);
                        if (typeof options.onApply === 'function') {
                            options.onApply(nextMask);
                        }
                    },
                },
                {
                    label: 'Abbrechen',
                    variant: 'secondary',
                    onClick: function () {
                        if (typeof options.onApply === 'function') {
                            options.onApply(roleMask);
                        }
                    },
                },
            ],
        });
    }

    function collectPermissionMask(originalMask, editableMask) {
        var selectedEditableMask = 0;
        document.querySelectorAll('.admin-projects-permission-check').forEach(function (el) {
            var bit = parseInt(el.getAttribute('data-bit') || '0', 10) || 0;
            if (bit <= 0) return;
            if (!el.checked) return;
            selectedEditableMask |= bit;
        });

        var preservedMask = originalMask & ~editableMask;
        return preservedMask | (selectedEditableMask & editableMask);
    }

    function editablePermissionMask() {
        if (canManageAdminSettings) {
            return allPermissionsMask();
        }
        return currentRoleMask;
    }

    function allPermissionsMask() {
        return permissionCatalog.reduce(function (mask, perm) {
            return mask | perm.bit_value;
        }, 0);
    }

    function permissionSummary(mask) {
        var names = permissionCatalog
            .filter(function (perm) { return (mask & perm.bit_value) !== 0; })
            .map(function (perm) { return perm.name; });

        if (names.length === 0) {
            return 'Keine Berechtigungen ausgewählt.';
        }

        return names.join(', ');
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function debounce(fn, ms) {
        var timer;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(this, args); }, ms);
        };
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    render();
    loadProjects();

    var createBtn = document.getElementById('openCreateProject');
    if (createBtn) {
        createBtn.addEventListener('click', function () { openCreateModal(); });
    }
}());

/* global adminOpenModal, adminCloseModal */
(function () {
    'use strict';

    var cfg = window.__ADMIN_USERS_CONFIG || {};
    var dataUrl = cfg.data_url || '/users/data';
    var canManage = !!cfg.can_manage;
    var canManageAdminSettings = !!cfg.can_manage_admin_settings;
    var currentUserId = parseInt(cfg.current_user_id || 0, 10) || 0;
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

    var root = document.getElementById('adminUsersRoot');
    if (!root) return;

    // ── State ────────────────────────────────────────────────────────────────

    var state = {
        page: 1,
        perPage: cfg.per_page || 20,
        totalPages: 1,
        q: '',
        users: [],
        loading: false,
    };

    // ── Render toolbar + table ────────────────────────────────────────────────

    function render() {
        root.innerHTML =
            '<div class="admin-users-toolbar">' +
                '<input type="search" id="usersSearch" class="admin-users-search" ' +
                    'placeholder="Suche nach Name oder E-Mail\u2026" value="' + escHtml(state.q) + '" />' +
            '</div>' +
            '<div id="usersTableContainer"></div>' +
            '<div class="admin-users-pagination" id="usersPagination"></div>';

        document.getElementById('usersSearch').addEventListener('input', debounce(function (e) {
            state.q = e.target.value;
            state.page = 1;
            loadUsers();
        }, 350));

        renderTable();
        renderPagination();
    }

    function renderTable() {
        var container = document.getElementById('usersTableContainer');
        if (!container) return;

        if (state.loading) {
            container.innerHTML = '<p class="admin-users-loading">Benutzer werden geladen\u2026</p>';
            return;
        }

        if (state.users.length === 0) {
            container.innerHTML = '<p class="admin-users-empty">Keine Benutzer gefunden.</p>';
            return;
        }

        var rows = state.users.map(function (u) {
            var badge = u.is_active
                ? '<span class="admin-users-badge admin-users-badge--active">Aktiv</span>'
                : '<span class="admin-users-badge admin-users-badge--inactive">Inaktiv</span>';

            var actions =
                '<button class="admin-users-action-btn" data-action="edit" data-id="' + u.id + '">Bearbeiten</button>' +
                '<button class="admin-users-action-btn" data-action="invite" data-id="' + u.id + '">Passwortlink senden</button>';

            if (canManage) {
                actions += '<button class="admin-users-action-btn admin-users-action-btn--danger" data-action="delete" data-id="' + u.id + '">Löschen</button>';
            }

            return '<tr>' +
                '<td>' + escHtml(u.first_name + ' ' + u.last_name) + '</td>' +
                '<td>' + escHtml(u.email) + '</td>' +
                '<td>' + badge + '</td>' +
                '<td>' + (u.last_login_at ? escHtml(u.last_login_at.replace('T', ' ').substring(0, 16)) : '—') + '</td>' +
                '<td><div class="admin-users-actions">' + actions + '</div></td>' +
                '</tr>';
        }).join('');

        container.innerHTML =
            '<table class="admin-users-table">' +
                '<thead><tr>' +
                    '<th>Name</th><th>E-Mail</th><th>Status</th><th>Letzter Login</th><th>Aktionen</th>' +
                '</tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
            '</table>';

        container.querySelectorAll('[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-action');
                var id = parseInt(btn.getAttribute('data-id'), 10);
                if (action === 'edit') openEditModal(id);
                if (action === 'invite') regenerateInvite(id);
                if (action === 'delete') confirmDelete(id);
            });
        });
    }

    function renderPagination() {
        var pag = document.getElementById('usersPagination');
        if (!pag) return;
        if (state.totalPages <= 1) { pag.innerHTML = ''; return; }

        var html = '';
        for (var i = 1; i <= state.totalPages; i++) {
            html += '<button class="admin-users-page-btn' +
                (i === state.page ? ' admin-users-page-btn--active' : '') +
                '" data-page="' + i + '">' + i + '</button>';
        }
        pag.innerHTML = html;
        pag.querySelectorAll('[data-page]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                state.page = parseInt(btn.getAttribute('data-page'), 10);
                loadUsers();
            });
        });
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    function loadUsers() {
        state.loading = true;
        renderTable();

        var url = dataUrl + '?page=' + state.page + '&per_page=' + state.perPage;
        if (state.q) url += '&q=' + encodeURIComponent(state.q);

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
            .then(function (r) {
                var contentType = r.headers.get('content-type') || '';
                if (contentType.indexOf('application/json') === -1) {
                    return r.text().then(function (body) {
                        throw new Error('Unerwartete Antwort vom Server (kein JSON). URL: ' + url + ', Status: ' + r.status + ', Body: ' + body.slice(0, 140));
                    });
                }
                return r.json();
            })
            .then(function (json) {
                if (!json.success) throw new Error(json.message || 'Fehler');
                state.users = json.data.users || [];
                state.totalPages = json.data.meta.total_pages || 1;
                state.loading = false;
                renderTable();
                renderPagination();
            })
            .catch(function (err) {
                state.loading = false;
                var container = document.getElementById('usersTableContainer');
                if (container) {
                    container.innerHTML = '<p class="admin-users-empty">Fehler beim Laden: ' + escHtml(err.message) + '</p>';
                }
            });
    }

    // ── Create modal ──────────────────────────────────────────────────────────

    function openCreateModal(draft) {
        var createDraft = draft || { first_name: '', last_name: '', email: '', role_mask: 0 };

        var body =
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="cuFn">Vorname *</label>' +
                '<input type="text" id="cuFn" class="admin-users-input" value="' + escHtml(createDraft.first_name) + '" />' +
            '</div>' +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="cuLn">Nachname *</label>' +
                '<input type="text" id="cuLn" class="admin-users-input" value="' + escHtml(createDraft.last_name) + '" />' +
            '</div>' +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="cuEmail">E-Mail *</label>' +
                '<input type="email" id="cuEmail" class="admin-users-input" value="' + escHtml(createDraft.email) + '" />' +
            '</div>' +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label">Berechtigungen</label>' +
                '<button type="button" id="cuPermissionsBtn" class="admin-users-action-btn">Berechtigungen auswählen</button>' +
                '<div id="cuPermSummary" class="admin-users-permission-summary">' + escHtml(permissionSummary(createDraft.role_mask)) + '</div>' +
            '</div>' +
            '<div id="cuAlert" class="admin-users-alert" style="display:none"></div>';

        adminOpenModal('Neuen Benutzer anlegen', body, {
            type: 'form',
            buttons: [
                {
                    label: 'Anlegen \u0026 Einladen',
                    variant: 'primary',
                    onClick: function () { submitCreate(createDraft.role_mask); },
                },
                { label: 'Abbrechen', variant: 'secondary', onClick: adminCloseModal },
            ],
        });

        bindCreatePermissionButton(createDraft);
    }

    function bindCreatePermissionButton(createDraft) {
        var btn = document.getElementById('cuPermissionsBtn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            createDraft.first_name = document.getElementById('cuFn') ? document.getElementById('cuFn').value.trim() : '';
            createDraft.last_name = document.getElementById('cuLn') ? document.getElementById('cuLn').value.trim() : '';
            createDraft.email = document.getElementById('cuEmail') ? document.getElementById('cuEmail').value.trim() : '';

            openPermissionModal({
                title: 'Berechtigungen auswählen',
                roleMask: createDraft.role_mask,
                onApply: function (nextMask) {
                    createDraft.role_mask = nextMask;
                    openCreateModal(createDraft);
                },
            });
        });
    }

    function submitCreate(roleMask) {
        var fn    = document.getElementById('cuFn')    ? document.getElementById('cuFn').value.trim()    : '';
        var ln    = document.getElementById('cuLn')    ? document.getElementById('cuLn').value.trim()    : '';
        var email = document.getElementById('cuEmail') ? document.getElementById('cuEmail').value.trim() : '';
        var alert = document.getElementById('cuAlert');

        if (!fn || !ln || !email) {
            if (alert) { alert.textContent = 'Bitte alle Pflichtfelder ausfüllen.'; alert.style.display = ''; }
            return;
        }

        var body = new URLSearchParams({ first_name: fn, last_name: ln, email: email, role_mask: String(roleMask || 0) });
        fetch(dataUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success) {
                    var msg = json.message || 'Fehler beim Anlegen.';
                    if (json.errors && json.errors.email) msg = 'E-Mail bereits vergeben.';
                    if (alert) { alert.textContent = msg; alert.style.display = ''; }
                    return;
                }
                adminCloseModal();
                loadUsers();
                showInviteLink(json.data.invite_link);
            })
            .catch(function () {
                if (alert) { alert.textContent = 'Netzwerkfehler. Bitte erneut versuchen.'; alert.style.display = ''; }
            });
    }

    // ── Edit modal ────────────────────────────────────────────────────────────

    function openEditModal(id) {
        var user = state.users.find(function (u) { return u.id === id; });
        if (!user) return;

        var editDraft = {
            id: id,
            first_name: user.first_name,
            last_name: user.last_name,
            role_mask: parseInt(user.role_mask, 10) || 0,
            is_active: !!user.is_active,
        };

        renderEditModal(editDraft);
    }

    function renderEditModal(editDraft) {
        var isSelf = (editDraft.id === currentUserId);
        var isActiveChecked = editDraft.is_active ? ' checked' : '';
        var isActiveDisabled = isSelf ? ' disabled' : '';
        var selfActiveHint = isSelf
            ? '<span class="admin-users-permission-locked">\uD83D\uDD12 Eigener Account kann nicht deaktiviert werden.</span>'
            : '';

        var body =
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="euFn">Vorname</label>' +
                '<input type="text" id="euFn" class="admin-users-input" value="' + escHtml(editDraft.first_name) + '" />' +
            '</div>' +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label" for="euLn">Nachname</label>' +
                '<input type="text" id="euLn" class="admin-users-input" value="' + escHtml(editDraft.last_name) + '" />' +
            '</div>' +
            (function () {
                var isSelf = (editDraft.id === currentUserId);
                var permBtn = isSelf
                    ? '<span class="admin-users-permission-locked">\uD83D\uDD12 Eigene Berechtigungen k\xF6nnen nicht bearbeitet werden.</span>'
                    : '<button type="button" id="euPermissionsBtn" class="admin-users-action-btn">Berechtigungen bearbeiten</button>';
                return '<div class="admin-users-field">' +
                    '<label class="admin-users-label">Berechtigungen</label>' +
                    permBtn +
                    '<div id="euPermSummary" class="admin-users-permission-summary">' + escHtml(permissionSummary(editDraft.role_mask)) + '</div>' +
                    '</div>';
            }()) +
            '<div class="admin-users-field">' +
                '<label class="admin-users-label admin-users-label--checkbox">' +
                    '<input type="checkbox" id="euActive"' + isActiveChecked + isActiveDisabled + ' /> Benutzer aktiv' +
                '</label>' +
                selfActiveHint +
            '</div>' +
            '<div id="euAlert" class="admin-users-alert" style="display:none"></div>';

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
        if (id !== currentUserId) {
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
                loadUsers();
            })
            .catch(function () {
                if (alert) { alert.textContent = 'Netzwerkfehler.'; alert.style.display = ''; }
            });
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    function confirmDelete(id) {
        var user = state.users.find(function (u) { return u.id === id; });
        var name = user ? user.first_name + ' ' + user.last_name : 'diesen Benutzer';

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
                loadUsers();
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
            '<div class="admin-users-invite-link-box">' + escHtml(link) + '</div>',
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
            var desc = perm.description ? '<span class="admin-users-permission-desc">' + escHtml(perm.description) + '</span>' : '';

            return '<label class="admin-users-permission-item' + (canEditThis ? '' : ' admin-users-permission-item--locked') + '">' +
                '<input type="checkbox" class="admin-users-permission-check" data-bit="' + perm.bit_value + '"' + checked + disabled + ' />' +
                '<span class="admin-users-permission-label">' + escHtml(perm.name) + ' <span class="admin-users-permission-bit">(' + perm.bit_value + ')</span></span>' +
                desc +
            '</label>';
        }).join('');

        var helperText = canManageAdminSettings
            ? 'Du kannst alle Berechtigungen bearbeiten.'
            : 'Du kannst nur Berechtigungen bearbeiten, die du selbst besitzt.';

        var body =
            '<p class="admin-users-permission-helper">' + escHtml(helperText) + '</p>' +
            '<div class="admin-users-permission-list">' + rows + '</div>';

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
        document.querySelectorAll('.admin-users-permission-check').forEach(function (el) {
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
    loadUsers();

    var createBtn = document.getElementById('openCreateUser');
    if (createBtn) {
        createBtn.addEventListener('click', function () { openCreateModal(); });
    }
}());

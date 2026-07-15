/**
 * admin-ui.js – Sidebar toggle, modal API, toast notification system
 */
(function () {
    'use strict';

    // ----------------------------------------------------------------
    // Sidebar
    // ----------------------------------------------------------------
    var sidebar   = document.getElementById('adminSidebar');
    var overlay   = document.getElementById('adminOverlay');
    var toggleBtn = document.getElementById('adminMenuToggle');
    var menuBadge = document.getElementById('adminMenuBadge');
    var sidebarBookingsBadge = document.getElementById('adminSidebarBookingsBadge');

    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('is-open');
        if (overlay) overlay.classList.add('is-active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('is-open');
        if (overlay) overlay.classList.remove('is-active');
        if (!document.getElementById('adminModalBackdrop')?.classList.contains('is-open')) {
            document.body.style.overflow = '';
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            sidebar && sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar();
        });
    }

    if (overlay) overlay.addEventListener('click', closeSidebar);

    function fetchOpenRequestCount() {
        return fetch('/admin/requests/data?page=1&per_page=999&sort=created_at&direction=desc', {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                var data = json && json.data ? json.data : {};
                var requests = Array.isArray(data.requests) ? data.requests : [];
                return requests.filter(function (item) {
                    return String(item && item.status || 'new') === 'new';
                }).length;
            })
            .catch(function () {
                return 0;
            });
    }

    function fetchOpenBookingSummary() {
        return fetch('/admin/bookings/summary', {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return {};
                }).then(function (json) {
                    return {
                        status: res.status,
                        ok: res.ok,
                        json: json
                    };
                });
            })
            .then(function (result) {
                var data = result && result.json && result.json.data ? result.json.data : {};
                var summary = data && typeof data.summary === 'object' ? data.summary : null;
                if (!summary) {
                    return { count: 0, total: 0 };
                }

                return {
                    count: Number(summary.outstanding_count || 0),
                    total: Number(summary.outstanding_total || 0)
                };
            })
            .catch(function () {
                return { count: 0, total: 0 };
            });
    }

    function parseAmount(value) {
        if (typeof value === 'number') {
            return Number.isFinite(value) ? value : 0;
        }

        var raw = String(value || '').trim();
        if (raw === '') {
            return 0;
        }

        if (raw.indexOf(',') !== -1) {
            raw = raw.replace(/\./g, '').replace(',', '.');
        }

        var parsed = Number(raw);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function setBadgeElement(element, options) {
        if (!element) return;

        options = options || {};
        var text = String(options.text || '');
        var visible = options.visible !== false && (text !== '' || options.allowEmptyText === true);
        var kind = String(options.kind || 'warning');
        var mustHaveTextIds = {
            adminSidebarRequestsBadge: true,
            adminSidebarBookingsBadge: true,
            adminRequestsRibbonBadge: true,
            adminBookingsRibbonBadge: true
        };

        if (mustHaveTextIds[element.id] && text === '') {
            visible = false;
        }

        element.className = 'admin-badge ' + (options.className || '') + ' admin-badge--' + kind;
        element.textContent = text;
        element.hidden = !visible;

        if (options.title) {
            element.title = String(options.title);
        } else {
            element.removeAttribute('title');
        }

        if (options.ariaLabel) {
            element.setAttribute('aria-label', String(options.ariaLabel));
        } else if (visible) {
            element.setAttribute('aria-label', text);
        } else {
            element.removeAttribute('aria-label');
        }
    }

    window.adminSetBadge = function (target, options) {
        var element = typeof target === 'string' ? document.getElementById(target) : target;
        setBadgeElement(element, options);
    };

    window.adminSetMenuBadge = function (options) {
        setBadgeElement(menuBadge, options);
    };

    function updateRequestBadges() {
        fetchOpenRequestCount().then(function (count) {
            var visible = count > 0;
            var label = visible ? '!' : '';
            var title = visible ? (String(count) + ' offene Anfrage' + (count === 1 ? '' : 'n')) : '';

            window.adminSetBadge('adminSidebarRequestsBadge', {
                text: label,
                kind: 'warning',
                visible: visible,
                className: 'admin-sidebar-item-badge',
                title: title,
                ariaLabel: title
            });

            window.adminSetMenuBadge({
                text: label,
                kind: 'warning',
                visible: visible,
                title: title,
                ariaLabel: title
            });
        });
    }

    function updateBookingBadges() {
        if (!sidebarBookingsBadge) {
            return;
        }

        fetchOpenBookingSummary().then(function (summary) {
            var count = Number(summary && summary.count ? summary.count : 0);
            var total = parseAmount(summary && summary.total ? summary.total : 0);
            var visible = count > 0;
            var kind = total > 500 ? 'danger' : 'warning';
            var label = visible ? '!' : '';
            var title = visible
                ? (String(count) + ' offene Zahlung' + (count === 1 ? '' : 'en') + ' · ' + total.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }))
                : '';

            window.adminSetBadge('adminSidebarBookingsBadge', {
                text: label,
                kind: kind,
                visible: visible,
                className: 'admin-sidebar-item-badge admin-sidebar-item-badge--booking',
                title: title,
                ariaLabel: title
            });
        });
    }

    updateRequestBadges();
    updateBookingBadges();
    setInterval(updateRequestBadges, 30000);
    setInterval(updateBookingBadges, 30000);

    // ----------------------------------------------------------------
    // Modal
    // ----------------------------------------------------------------
    var modalBackdrop = document.getElementById('adminModalBackdrop');
    var modalTitle    = document.getElementById('adminModalTitle');
    var modalBody     = document.getElementById('adminModalBody');
    var modalFooter   = document.getElementById('adminModalFooter');
    var modalCloseBtn = document.getElementById('adminModalCloseBtn');
    var modalPanel    = modalBackdrop ? modalBackdrop.querySelector('.admin-modal') : null;
    var lastFocusedBeforeModal = null;
    var activeModalClass = '';

    /**
     * Open the admin modal.
     *
     * @param {string} title      – Dialog title
     * @param {string} bodyHtml   – Inner HTML for the body slot
     * @param {Object} [options]
     * @param {string}  [options.type='info']    – 'info' | 'form'
     * @param {Array}   [options.buttons]         – [{label, variant, onClick}]
     *                   variant: 'primary' | 'secondary' | 'danger'
     */
    function openModal(title, bodyHtml, options) {
        options = options || {};
        var type    = options.type    || 'info';
        var buttons = options.buttons || [];
        var modalClass = String(options.modalClass || '').trim();

        if (modalPanel && activeModalClass !== '') {
            modalPanel.classList.remove(activeModalClass);
            activeModalClass = '';
        }

        if (modalPanel && modalClass !== '') {
            modalPanel.classList.add(modalClass);
            activeModalClass = modalClass;
        }

        lastFocusedBeforeModal = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;

        if (modalTitle) modalTitle.textContent = title || '';
        if (modalBody)  modalBody.innerHTML    = bodyHtml || '';

        if (modalFooter) {
            modalFooter.innerHTML = '';

            if (type === 'info' && buttons.length === 0) {
                buttons = [{ label: 'Schließen', variant: 'secondary', onClick: closeModal }];
            }

            buttons.forEach(function (btn) {
                var b = document.createElement('button');
                b.type      = 'button';
                b.className = 'admin-modal-btn admin-modal-btn--' + (btn.variant || 'secondary');
                b.textContent = btn.label || '';
                b.addEventListener('click', function () {
                    (typeof btn.onClick === 'function') ? btn.onClick() : closeModal();
                });
                modalFooter.appendChild(b);
            });

            modalFooter.style.display = buttons.length === 0 ? 'none' : '';
        }

        if (modalBackdrop) {
            // Re-trigger animation on re-open
            var modal = modalBackdrop.querySelector('.admin-modal');
            if (modal) {
                modal.style.animation = 'none';
                modal.offsetHeight; // reflow
                modal.style.animation = '';
            }
            modalBackdrop.classList.add('is-open');
            modalBackdrop.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            window.requestAnimationFrame(function () {
                if (modalCloseBtn) {
                    modalCloseBtn.focus();
                }
            });
        }
    }

    function closeModal() {
        if (!modalBackdrop) return;

        if (modalPanel && activeModalClass !== '') {
            modalPanel.classList.remove(activeModalClass);
            activeModalClass = '';
        }

        if (modalBackdrop.contains(document.activeElement) && document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }

        modalBackdrop.classList.remove('is-open');
        modalBackdrop.setAttribute('aria-hidden', 'true');
        if (!sidebar || !sidebar.classList.contains('is-open')) {
            document.body.style.overflow = '';
        }

        if (lastFocusedBeforeModal instanceof HTMLElement) {
            window.requestAnimationFrame(function () {
                lastFocusedBeforeModal.focus();
                lastFocusedBeforeModal = null;
            });
        }
    }

    window.adminOpenModal  = openModal;
    window.adminCloseModal = closeModal;

    if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);

    if (modalBackdrop) {
        modalBackdrop.addEventListener('click', function (e) {
            if (e.target === modalBackdrop) closeModal();
        });
    }

    // ----------------------------------------------------------------
    // Keyboard: Escape
    // ----------------------------------------------------------------
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (modalBackdrop && modalBackdrop.classList.contains('is-open')) {
            closeModal();
        } else if (sidebar && sidebar.classList.contains('is-open')) {
            closeSidebar();
        }
    });

    // ----------------------------------------------------------------
    // Toast notification queue (top-right fixed overlay)
    // ----------------------------------------------------------------
    var TOAST_DURATION = 10000; // ms

    var toastContainer = (function () {
        var el = document.getElementById('adminToastContainer');
        if (!el) {
            el = document.createElement('div');
            el.id        = 'adminToastContainer';
            el.className = 'admin-toast-container';
            document.body.appendChild(el);
        }
        return el;
    }());

    var CLOSE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" ' +
        'stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" ' +
        'aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/>' +
        '<line x1="6" y1="6" x2="18" y2="18"/></svg>';

    /**
     * Show a toast notification in the top-right queue.
     *
     * @param {'error'|'warning'|'success'|'info'} type
     * @param {string} message
     */
    function showNotification(type, message) {
        var allowed = { error: 1, warning: 1, success: 1, info: 1 };
        if (!allowed[type]) type = 'info';

        var toast = document.createElement('div');
        toast.className = 'admin-toast admin-toast--' + type;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.innerHTML =
            '<span class="admin-toast-body">' + _escapeHtml(message) + '</span>' +
            '<button type="button" class="admin-toast-close" aria-label="Meldung schließen">' +
            CLOSE_SVG + '</button>' +
            '<span class="admin-toast-progress" aria-hidden="true"></span>';

        var closeBtn = toast.querySelector('.admin-toast-close');
        closeBtn.addEventListener('click', function () { dismissToast(toast); });

        toastContainer.appendChild(toast);

        var timer = setTimeout(function () { dismissToast(toast); }, TOAST_DURATION);

        // Cancel auto-dismiss while hovering
        toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
        toast.addEventListener('mouseleave', function () {
            timer = setTimeout(function () { dismissToast(toast); }, 3000);
        });
    }

    function dismissToast(toast) {
        if (!toast || !toast.parentNode) return;
        toast.classList.add('is-leaving');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 230);
    }

    function _escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    window.adminShowNotification = showNotification;

}());

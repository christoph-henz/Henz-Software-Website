(() => {
  const SIDEBAR_RIBBON_STORAGE_KEY = 'admin.sidebar.ribbon.state.v1';

  const onReady = (fn) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
      return;
    }
    fn();
  };

  const bindLayoutInteractions = () => {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminOverlay');
    const toggle = document.getElementById('adminMenuToggle');

    if (!sidebar || !overlay || !toggle) {
      return;
    }

    const closeSidebar = () => {
      sidebar.classList.remove('is-open');
      overlay.classList.remove('is-visible');
      toggle.setAttribute('aria-expanded', 'false');
    };

    const openSidebar = () => {
      sidebar.classList.add('is-open');
      overlay.classList.add('is-visible');
      toggle.setAttribute('aria-expanded', 'true');
    };

    toggle.addEventListener('click', () => {
      if (sidebar.classList.contains('is-open')) {
        closeSidebar();
        return;
      }
      openSidebar();
    });

    overlay.addEventListener('click', closeSidebar);

    window.addEventListener('resize', () => {
      if (window.innerWidth >= 1024) {
        closeSidebar();
      }
    });
  };

  const bindModal = () => {
    const backdrop = document.getElementById('adminModalBackdrop');
    const titleEl = document.getElementById('adminModalTitle');
    const bodyEl = document.getElementById('adminModalBody');
    const footerEl = document.getElementById('adminModalFooter');
    const closeBtn = document.getElementById('adminModalCloseBtn');

    if (!backdrop || !titleEl || !bodyEl || !footerEl) {
      window.adminOpenModal = () => {};
      window.adminCloseModal = () => {};
      return;
    }

    const closeModal = () => {
      backdrop.classList.remove('is-open');
      backdrop.setAttribute('aria-hidden', 'true');
      bodyEl.innerHTML = '';
      footerEl.innerHTML = '';
    };

    window.adminCloseModal = closeModal;

    window.adminOpenModal = (title, bodyHtml, options = {}) => {
      titleEl.textContent = typeof title === 'string' ? title : '';
      bodyEl.innerHTML = typeof bodyHtml === 'string' ? bodyHtml : '';
      footerEl.innerHTML = '';

      const buttons = Array.isArray(options.buttons) ? options.buttons : [];
      if (buttons.length === 0) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'admin-logout-btn';
        btn.textContent = 'Schließen';
        btn.addEventListener('click', closeModal);
        footerEl.appendChild(btn);
      } else {
        buttons.forEach((buttonCfg) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'admin-logout-btn';
          btn.textContent = String(buttonCfg.label ?? 'OK');
          btn.addEventListener('click', () => {
            if (typeof buttonCfg.onClick === 'function') {
              buttonCfg.onClick();
            }
          });
          footerEl.appendChild(btn);
        });
      }

      backdrop.classList.add('is-open');
      backdrop.setAttribute('aria-hidden', 'false');
    };

    if (closeBtn) {
      closeBtn.addEventListener('click', closeModal);
    }

    backdrop.addEventListener('click', (event) => {
      if (event.target === backdrop) {
        closeModal();
      }
    });
  };

  const bindToasts = () => {
    const container = document.getElementById('adminToastContainer');

    const typeStyles = {
      success: {
        toast: 'border-emerald-400/40 bg-emerald-400/14',
        iconWrap: 'bg-emerald-400/20 text-emerald-300 border-emerald-300/30',
        icon: '✓',
      },
      error: {
        toast: 'border-rose-400/40 bg-rose-400/14',
        iconWrap: 'bg-rose-400/20 text-rose-300 border-rose-300/30',
        icon: '!',
      },
      warning: {
        toast: 'border-amber-400/40 bg-amber-400/14',
        iconWrap: 'bg-amber-400/20 text-amber-300 border-amber-300/30',
        icon: '!',
      },
      info: {
        toast: 'border-sky-400/40 bg-sky-400/14',
        iconWrap: 'bg-sky-400/20 text-sky-300 border-sky-300/30',
        icon: 'i',
      },
    };

    window.adminShowNotification = (type, message) => {
      if (!container || typeof message !== 'string' || message.trim() === '') {
        return;
      }

      const safeType = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
      const style = typeStyles[safeType] ?? typeStyles.info;

      const toast = document.createElement('div');
      toast.className = `pointer-events-auto w-full rounded-xl border px-4 py-3 text-sm text-foreground shadow-lg backdrop-blur transition-all duration-200 ${style.toast}`;
      toast.setAttribute('role', 'status');

      const row = document.createElement('div');
      row.className = 'flex items-start gap-3';

      const icon = document.createElement('span');
      icon.className = `mt-0.5 inline-flex h-5 w-5 flex-none items-center justify-center rounded-full border text-xs font-bold leading-none ${style.iconWrap}`;
      icon.textContent = style.icon;

      const text = document.createElement('p');
      text.className = 'm-0 leading-5';
      text.textContent = message;

      row.appendChild(icon);
      row.appendChild(text);
      toast.appendChild(row);
      container.appendChild(toast);

      window.setTimeout(() => {
        toast.remove();
      }, 4200);
    };
  };

  const bindSidebarNavToggles = () => {
    const toggles = document.querySelectorAll('.admin-sidebar-nav-toggle');
    if (toggles.length === 0) {
      return;
    }

    const readRibbonState = () => {
      try {
        const raw = window.sessionStorage.getItem(SIDEBAR_RIBBON_STORAGE_KEY);
        if (!raw) {
          return {};
        }

        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : {};
      } catch (_err) {
        return {};
      }
    };

    const writeRibbonState = (state) => {
      try {
        window.sessionStorage.setItem(SIDEBAR_RIBBON_STORAGE_KEY, JSON.stringify(state || {}));
      } catch (_err) {
        // Ignore storage errors.
      }
    };

    const groupKeyForToggle = (toggle, index) => {
      const explicitKey = String(toggle.getAttribute('data-ribbon-key') || '').trim();
      if (explicitKey !== '') {
        return explicitKey;
      }

      const label = String(toggle.textContent || '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

      return 'idx:' + index + '|label:' + label;
    };

    const ribbonState = readRibbonState();

    toggles.forEach((toggle, index) => {
      const key = groupKeyForToggle(toggle, index);
      const group = toggle.closest('.admin-sidebar-nav-group');
      if (!group) {
        return;
      }

      if (Object.prototype.hasOwnProperty.call(ribbonState, key)) {
        const shouldExpand = !!ribbonState[key];
        group.classList.toggle('is-expanded', shouldExpand);
        toggle.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
      }

      toggle.addEventListener('click', () => {
        const isExpanded = group.classList.toggle('is-expanded');
        toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');

        ribbonState[key] = isExpanded;
        writeRibbonState(ribbonState);
      });
    });
  };

  const bindBadgeManager = () => {
    const sources = new Map();
    const values = new Map();
    let legacyMenuBadge = { visible: false, text: '!', title: '' };
    const sourceDefinitions = [
      { key: 'appointments', url: '/appointments/data/summary', targetId: 'adminSidebarBookingsBadge', group: 'clients' },
      { key: 'tickets', url: '/tickets/data/summary', targetId: 'adminSidebarTicketsBadge', group: 'clients' },
    ];

    const setElementBadge = (id, count, title) => {
      const element = document.getElementById(id);
      if (!element) {
        return;
      }

      const visible = count > 0;
      element.textContent = visible ? '!' : '';
      element.hidden = !visible;
      element.setAttribute('aria-hidden', visible ? 'false' : 'true');
      if (visible) {
        element.title = title;
        element.setAttribute('aria-label', title);
      } else {
        element.removeAttribute('title');
        element.removeAttribute('aria-label');
      }
    };

    const refreshGroupBadges = () => {
      const groups = new Set(Array.from(sources.values()).map((source) => source.group).filter(Boolean));
      groups.forEach((groupKey) => {
        let total = 0;
        const labels = [];
        sources.forEach((source) => {
          if (source.group !== groupKey) {
            return;
          }
          const count = Number(values.get(source.key) || 0);
          total += count;
          if (count > 0) {
            labels.push(`${count} ${source.label}`);
          }
        });

        const groupElement = document.querySelector('[data-badge-group="' + groupKey + '"]');
        const group = groupElement ? groupElement.closest('.admin-sidebar-nav-group') : null;
        const expanded = !!(group && group.classList.contains('is-expanded'));
        const targetId = 'adminSidebar' + groupKey.charAt(0).toUpperCase() + groupKey.slice(1) + 'Badge';
        setElementBadge(targetId, expanded ? 0 : total, labels.join(', '));
      });
    };

    const refreshMenuBadge = () => {
      let total = 0;
      values.forEach((value) => {
        total += Number(value || 0);
      });
      renderMenuBadge({
        text: '!',
        kind: 'warning',
        visible: total > 0 || legacyMenuBadge.visible,
        title: total > 0 ? 'Offene Termine oder Tickets vorhanden' : legacyMenuBadge.title,
        ariaLabel: total > 0 ? 'Offene Termine oder Tickets vorhanden' : legacyMenuBadge.title,
      });
    };

    const renderMenuBadge = (options = {}) => {
      const element = document.getElementById('adminMenuBadge');
      if (!element) return;
      const visible = !!options.visible;
      element.textContent = visible ? String(options.text || '!') : '';
      element.hidden = !visible;
      element.setAttribute('aria-hidden', visible ? 'false' : 'true');
      if (visible) element.title = String(options.title || '');
      else element.removeAttribute('title');
    };

    const refreshBadges = () => {
      sources.forEach((source) => {
        const count = Number(values.get(source.key) || 0);
        setElementBadge(source.targetId, count, `${count} ${source.label}`);
      });
      refreshGroupBadges();
      refreshMenuBadge();
    };

    const readSource = (source) => fetch(source.url, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((json) => {
        const summary = json && json.data && json.data.summary ? json.data.summary : {};
        values.set(source.key, Math.max(0, Number(summary[source.valueKey] || 0)));
        refreshBadges();
      })
      .catch(() => {
        values.set(source.key, 0);
        refreshBadges();
      });

    const register = (definition) => {
      const source = {
        key: String(definition.key || ''),
        url: String(definition.url || ''),
        valueKey: String(definition.valueKey || 'count'),
        targetId: String(definition.targetId || ''),
        group: String(definition.group || ''),
        label: String(definition.label || definition.key || 'offene Einträge'),
      };
      if (source.key === '' || source.url === '') {
        return;
      }
      sources.set(source.key, source);
      values.set(source.key, 0);
      readSource(source);
    };

    window.adminSetBadge = (id, options = {}) => {
      setElementBadge(String(id || ''), options.visible ? 1 : 0, String(options.title || options.ariaLabel || ''));
    };
    window.adminSetMenuBadge = (options = {}) => {
      legacyMenuBadge = {
        visible: !!options.visible,
        text: String(options.text || '!'),
        title: String(options.title || options.ariaLabel || ''),
      };
      renderMenuBadge(legacyMenuBadge);
    };
    window.adminRegisterBadgeSource = register;

    sourceDefinitions.forEach((definition) => register({
      ...definition,
      valueKey: definition.key === 'appointments' ? 'pending_count' : 'open_count',
      label: definition.key === 'appointments' ? 'offene Termine' : 'offene Tickets',
    }));

    document.querySelectorAll('.admin-sidebar-nav-toggle').forEach((toggle) => {
      toggle.addEventListener('click', () => window.setTimeout(refreshBadges, 0));
    });

    window.setInterval(() => sources.forEach(readSource), 30000);
  };

  onReady(() => {
    bindLayoutInteractions();
    bindModal();
    bindToasts();
    bindSidebarNavToggles();
    bindBadgeManager();
  });
})();

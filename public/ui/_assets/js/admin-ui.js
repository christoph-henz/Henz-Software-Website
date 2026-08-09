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

  onReady(() => {
    bindLayoutInteractions();
    bindModal();
    bindToasts();
    bindSidebarNavToggles();
  });
})();

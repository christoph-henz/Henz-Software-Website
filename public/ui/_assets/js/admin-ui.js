(() => {
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

    window.adminShowNotification = (type, message) => {
      if (!container || typeof message !== 'string' || message.trim() === '') {
        return;
      }

      const safeType = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';

      const toast = document.createElement('div');
      toast.className = `admin-toast admin-toast--${safeType}`;
      toast.textContent = message;
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

    toggles.forEach((toggle) => {
      toggle.addEventListener('click', () => {
        const group = toggle.closest('.admin-sidebar-nav-group');
        if (!group) {
          return;
        }

        const isExpanded = group.classList.toggle('is-expanded');
        toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
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

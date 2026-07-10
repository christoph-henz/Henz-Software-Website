(function () {
  const toggle = document.querySelector('.gb-nav-toggle');
  const nav = document.querySelector('.gb-nav');
  const submenuButtons = document.querySelectorAll('.gb-submenu-toggle');

  const isDesktop = function () {
    return window.matchMedia('(min-width: 768px)').matches;
  };

  const closeAllSubmenus = function () {
    submenuButtons.forEach(function (button) {
      const item = button.closest('.has-submenu');
      const submenu = item ? item.querySelector('.gb-submenu') : null;
      const caret = button.querySelector('[data-caret]');

      if (submenu) {
        submenu.classList.add('hidden');
      }

      if (caret) {
        caret.classList.remove('rotate-180');
      }

      button.setAttribute('aria-expanded', 'false');
    });
  };

  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      const isNowHidden = nav.classList.toggle('hidden');
      toggle.setAttribute('aria-expanded', isNowHidden ? 'false' : 'true');
    });

    document.addEventListener('click', function (event) {
      if (nav.contains(event.target) || toggle.contains(event.target)) {
        return;
      }

      if (!isDesktop()) {
        nav.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
      }

      closeAllSubmenus();
    });

    window.addEventListener('resize', function () {
      if (isDesktop()) {
        nav.classList.remove('hidden');
      } else {
        nav.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
      }

      closeAllSubmenus();
    });

    if (isDesktop()) {
      nav.classList.remove('hidden');
    } else {
      nav.classList.add('hidden');
      toggle.setAttribute('aria-expanded', 'false');
    }
  }

  submenuButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      const item = button.closest('.has-submenu');
      const submenu = item ? item.querySelector('.gb-submenu') : null;
      const caret = button.querySelector('[data-caret]');

      if (!submenu) {
        return;
      }

      const isNowHidden = submenu.classList.toggle('hidden');
      button.setAttribute('aria-expanded', isNowHidden ? 'false' : 'true');

      if (caret) {
        caret.classList.toggle('rotate-180', !isNowHidden);
      }

      submenuButtons.forEach(function (otherButton) {
        if (otherButton === button) {
          return;
        }

        const otherItem = otherButton.closest('.has-submenu');
        const otherSubmenu = otherItem ? otherItem.querySelector('.gb-submenu') : null;
        const otherCaret = otherButton.querySelector('[data-caret]');

        if (otherSubmenu) {
          otherSubmenu.classList.add('hidden');
        }

        if (otherCaret) {
          otherCaret.classList.remove('rotate-180');
        }

        otherButton.setAttribute('aria-expanded', 'false');
      });
    });
  });
})();

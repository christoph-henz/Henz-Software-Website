(function () {
  const toggle = document.querySelector('.gb-nav-toggle');
  const nav = document.querySelector('.gb-nav');

  if (toggle && nav) {
    const closeMobileNav = function () {
      nav.classList.remove('is-open');
      nav.classList.add('hidden');
      toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', function () {
      const isOpen = nav.classList.toggle('is-open');
      nav.classList.toggle('hidden', !isOpen);
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        if (window.matchMedia('(max-width: 767px)').matches) {
          closeMobileNav();
        }
      });
    });
  }

  document.querySelectorAll('.gb-submenu-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
      const item = button.closest('.has-submenu');
      if (!item) {
        return;
      }

      const isOpen = item.classList.toggle('is-open');
      button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

      const submenu = item.querySelector('.gb-submenu');
      if (submenu) {
        submenu.classList.toggle('hidden', !isOpen);
      }
    });
  });
})();

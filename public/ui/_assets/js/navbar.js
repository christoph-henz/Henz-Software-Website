(function () {
  const toggle = document.querySelector('.gb-nav-toggle');
  const nav = document.querySelector('.gb-nav');

  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      const isOpen = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
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
    });
  });
})();

/**
 * PWA Service Worker Registration
 * Handles service worker lifecycle and update notifications
 */

(function () {
  // Check if service workers are supported
  if (!('serviceWorker' in navigator)) {
    return;
  }

  // Register the service worker
  navigator.serviceWorker
    .register('/sw.js', { scope: '/' })
    .then((registration) => {
      console.log('Service Worker registered:', registration);

      // Check for updates periodically
      setInterval(() => {
        registration.update();
      }, 60000); // Check every 60 seconds

      // Listen for updates
      registration.addEventListener('updatefound', () => {
        const newWorker = registration.installing;
        if (!newWorker) return;

        newWorker.addEventListener('statechange', () => {
          if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
            // New service worker available; notify user
            notifyAppUpdate();
          }
        });
      });
    })
    .catch((error) => {
      console.error('Service Worker registration failed:', error);
    });

  /**
   * Notify user that an app update is available
   */
  function notifyAppUpdate() {
    const message = document.createElement('div');
    message.setAttribute('role', 'alert');
    message.setAttribute('aria-live', 'polite');
    message.className = 'pwa-update-notification';
    message.innerHTML = `
      <div class="pwa-update-content">
        <p>Eine neue Version ist verfügbar.</p>
        <button type="button" class="pwa-update-btn" id="pwaUpdateBtn">Aktualisieren</button>
        <button type="button" class="pwa-update-dismiss" id="pwaUpdateDismiss">Später</button>
      </div>
    `;

    document.body.appendChild(message);

    // Handle update button click
    document.getElementById('pwaUpdateBtn').addEventListener('click', () => {
      // Signal the new service worker to take over
      const controller = navigator.serviceWorker.controller;
      if (controller) {
        controller.postMessage({ type: 'SKIP_WAITING' });
      }
      // Wait a moment, then reload
      setTimeout(() => window.location.reload(), 500);
    });

    // Handle dismiss button click
    document.getElementById('pwaUpdateDismiss').addEventListener('click', () => {
      message.remove();
    });
  }

  // Auto-reload when a new service worker becomes active
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    console.log('Service Worker updated, reloading page...');
    window.location.reload();
  });
})();

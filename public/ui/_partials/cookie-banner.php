<?php

declare(strict_types=1);

$cookieValue = strtolower(trim((string) ($_COOKIE['hs_essential_cookies'] ?? '')));
if (in_array($cookieValue, ['accepted', '1', 'true', 'yes'], true)) {
    return;
}

$bannerError = trim((string) ($_GET['cookie_consent'] ?? ''));
$consentText = 'Ich stimme der Nutzung essenzieller Cookies zu, damit diese Website technisch bereitgestellt werden kann.';

$renderBanner = static function (bool $templateMode = false) use ($bannerError, $consentText): void {
    ?>
    <section
    id="gb-essential-cookie-banner"
      class="gb-cookie-overlay fixed inset-0 z-[9999] flex items-end justify-center px-3 py-3 sm:px-4 sm:py-6 lg:items-center lg:py-8"
        role="dialog"
        aria-modal="true"
        aria-labelledby="gb-essential-cookie-title"
        aria-describedby="gb-essential-cookie-description"
    >
      <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_color-mix(in_srgb,var(--primary)_28%,transparent),_transparent_58%)]"></div>
      <div class="gb-cookie-card relative w-full max-w-5xl overflow-hidden rounded-[1.5rem] sm:rounded-[2rem]">
            <div class="grid gap-0 lg:grid-cols-[1.2fr_0.8fr]">
          <div class="p-5 sm:p-8 lg:p-10">
            <div class="gb-cookie-kicker mb-4 inline-flex items-center gap-3 rounded-full px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] sm:px-4 sm:text-xs sm:tracking-[0.22em]">
              <span class="h-2 w-2 rounded-full" style="background: var(--primary);"></span>
                        Cookie Gate
                    </div>

            <h1 id="gb-essential-cookie-title" class="max-w-3xl font-mono text-2xl font-bold leading-tight sm:text-4xl lg:text-5xl" style="color: var(--foreground);">
                        Essenzielle Cookies aktivieren, um die Website zu nutzen.
                    </h1>

            <p id="gb-essential-cookie-description" class="mt-4 max-w-2xl text-sm leading-7 sm:mt-5 sm:text-lg sm:leading-8" style="color: var(--muted-foreground);">
                        Ohne die Zustimmung zu essenziellen Cookies können nicht alle Inhalte angezeigt werden.
                    </p>

                    <?php if ($bannerError !== ''): ?>
              <p class="mt-4 rounded-2xl border px-4 py-3 text-sm" style="border-color: color-mix(in srgb, var(--destructive) 36%, transparent); color: color-mix(in srgb, var(--destructive) 78%, var(--foreground)); background: color-mix(in srgb, var(--destructive) 14%, transparent);">
                            Die Zustimmung konnte gerade nicht gespeichert werden. Bitte versuche es erneut.
                        </p>
                    <?php endif; ?>

                    <form action="/cookie-consent" method="post" class="mt-7 space-y-4" toolname="acceptEssentialCookies" tooldescription="Speichert die Zustimmung zu essenziellen Cookies und schaltet die Website frei." toolautosubmit>
                        <input type="hidden" name="redirect_to" value="/" />
                        <input type="hidden" name="context_type" value="site" />
                        <input type="hidden" name="consent_version" value="site-1.0" />
                      <input type="hidden" name="consents[0][consent_key]" value="essential_cookies" toolparamdescription="Technischer Schlüssel der erforderlichen Einwilligung." />
                      <input type="hidden" name="consents[0][accepted]" value="1" toolparamdescription="Einwilligung wird erteilt." />
                      <input type="hidden" name="consents[0][consent_text_snapshot]" value="<?= htmlspecialchars($consentText, ENT_QUOTES, 'UTF-8'); ?>" toolparamdescription="Text, dem zugestimmt wird." />
                      <input type="hidden" name="consents[0][accepted_at]" value="<?= htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?>" toolparamdescription="Zeitpunkt der Zustimmung." />

                        <button
                            type="submit"
                          class="inline-flex min-h-12 items-center justify-center rounded-2xl px-6 py-3 font-mono text-sm font-semibold transition-transform duration-200 hover:-translate-y-0.5"
                          style="background: var(--primary); color: var(--primary-foreground); box-shadow: 0 16px 36px color-mix(in srgb, var(--primary) 28%, transparent);"
                        >
                            Essenzielle Cookies akzeptieren
                        </button>
                    </form>
                </div>

                    <aside class="gb-cookie-aside border-t p-5 sm:p-8 lg:border-l lg:border-t-0 lg:p-10">
                      <div class="rounded-[1.25rem] border p-4 sm:rounded-[1.5rem] sm:p-5" style="border-color: var(--border); background: color-mix(in srgb, var(--card) 88%, var(--background) 12%);">
                        <p class="font-mono text-[11px] uppercase tracking-[0.16em] sm:text-xs sm:tracking-[0.22em]" style="color: var(--muted-foreground);">Erreichbar ohne Zustimmung</p>
                        <div class="mt-4 space-y-3">
                          <a class="block rounded-xl border px-4 py-3 text-sm transition-colors duration-200" style="border-color: var(--border); background: color-mix(in srgb, var(--input-background) 70%, transparent); color: var(--foreground);" href="/impressum">Impressum</a>
                          <a class="block rounded-xl border px-4 py-3 text-sm transition-colors duration-200" style="border-color: var(--border); background: color-mix(in srgb, var(--input-background) 70%, transparent); color: var(--foreground);" href="/agb">AGB</a>
                          <a class="block rounded-xl border px-4 py-3 text-sm transition-colors duration-200" style="border-color: var(--border); background: color-mix(in srgb, var(--input-background) 70%, transparent); color: var(--foreground);" href="/datenschutz">Datenschutz</a>
                        </div>
                        <p class="mt-5 text-sm leading-7" style="color: var(--muted-foreground);">
                            Bitte akzeptiere die Cookies damit du den Rest der Seite erreichen kannst.
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    </section>
    <?php
};
?>

<template id="gb-essential-cookie-banner-template">
    <?php $renderBanner(true); ?>
</template>

<?php $renderBanner(false); ?>

<style>
  .gb-cookie-overlay {
    background: color-mix(in srgb, var(--background) 76%, #000 24%);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    padding-bottom: max(0.75rem, env(safe-area-inset-bottom));
  }

  .gb-cookie-card {
    border: 1px solid var(--border);
    background: color-mix(in srgb, var(--card) 94%, var(--background) 6%);
    box-shadow: 0 34px 110px color-mix(in srgb, #000 56%, transparent);
    max-height: min(92dvh, 780px);
    overflow-y: auto;
  }

  .gb-cookie-kicker {
    border: 1px solid color-mix(in srgb, var(--primary) 30%, transparent);
    background: color-mix(in srgb, var(--secondary) 84%, var(--background) 16%);
    color: color-mix(in srgb, var(--primary) 72%, var(--foreground));
  }

  .gb-cookie-aside {
    border-color: color-mix(in srgb, var(--primary) 18%, transparent);
    background: color-mix(in srgb, var(--secondary) 76%, var(--background) 24%);
  }

  @media (max-width: 640px) {
    .gb-cookie-card {
      max-height: min(92dvh, 700px);
    }
  }
</style>

<script>
(function () {
  var cookieName = 'hs_essential_cookies';
  var acceptedValues = ['accepted', '1', 'true', 'yes'];
  var bannerId = 'gb-essential-cookie-banner';
  var templateId = 'gb-essential-cookie-banner-template';

  function getCookie(name) {
    var escaped = name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1');
    var match = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function hasConsent() {
    return acceptedValues.indexOf(getCookie(cookieName).toLowerCase()) !== -1;
  }

  function lockBody() {
    document.body.classList.toggle('gb-essential-cookie-locked', !hasConsent());
  }

  function ensureBanner() {
    var existing = document.getElementById(bannerId);
    if (hasConsent()) {
      if (existing && existing.parentNode) {
        existing.parentNode.removeChild(existing);
      }
      lockBody();
      return;
    }

    if (existing) {
      existing.removeAttribute('hidden');
      existing.style.display = '';
      existing.style.visibility = '';
      existing.style.pointerEvents = '';
      lockBody();
      return;
    }

    var template = document.getElementById(templateId);
    if (!template || !template.content || !template.content.firstElementChild) {
      return;
    }

    var clone = template.content.firstElementChild.cloneNode(true);
    document.body.appendChild(clone);
    lockBody();
  }

  function boot() {
    lockBody();
    ensureBanner();

    var observer = new MutationObserver(function () {
      ensureBanner();
    });
    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class', 'style', 'hidden', 'aria-hidden']
    });

    setInterval(ensureBanner, 1200);
    window.addEventListener('focus', ensureBanner);
    window.addEventListener('pageshow', ensureBanner);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
</script>

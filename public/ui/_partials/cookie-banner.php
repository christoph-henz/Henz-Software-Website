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
        class="fixed inset-0 z-[9999] flex items-end justify-center bg-[#060a0f]/85 px-4 py-4 backdrop-blur-xl sm:items-center sm:py-8"
        role="dialog"
        aria-modal="true"
        aria-labelledby="gb-essential-cookie-title"
        aria-describedby="gb-essential-cookie-description"
    >
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(0,200,255,0.18),_transparent_55%)]"></div>
        <div class="relative w-full max-w-5xl overflow-hidden rounded-[2rem] border border-[#00c8ff]/18 bg-[#08121b]/96 shadow-[0_35px_110px_rgba(0,0,0,0.62)]">
            <div class="grid gap-0 lg:grid-cols-[1.2fr_0.8fr]">
                <div class="p-6 sm:p-8 lg:p-10">
                    <div class="mb-5 inline-flex items-center gap-3 rounded-full border border-[#00c8ff]/24 bg-[#0b1b29] px-4 py-2 text-xs font-semibold uppercase tracking-[0.22em] text-[#8defff]">
                        <span class="h-2 w-2 rounded-full bg-[#00c8ff] shadow-[0_0_20px_rgba(0,200,255,0.95)]"></span>
                        Cookie Gate
                    </div>

                    <h1 id="gb-essential-cookie-title" class="max-w-3xl font-mono text-3xl font-bold leading-tight text-[#f7fbff] sm:text-4xl lg:text-5xl">
                        Essenzielle Cookies aktivieren, um die Website zu nutzen.
                    </h1>

                    <p id="gb-essential-cookie-description" class="mt-5 max-w-2xl text-base leading-8 text-[#d5e2f1] sm:text-lg">
                        Ohne die Zustimmung zu essenziellen Cookies können nicht alle Inhalte angezeigt werden.
                    </p>

                    <?php if ($bannerError !== ''): ?>
                        <p class="mt-4 rounded-2xl border border-red-400/25 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                            Die Zustimmung konnte gerade nicht gespeichert werden. Bitte versuche es erneut.
                        </p>
                    <?php endif; ?>

                    <form action="/cookie-consent" method="post" class="mt-7 space-y-4">
                        <input type="hidden" name="redirect_to" value="/" />
                        <input type="hidden" name="context_type" value="site" />
                        <input type="hidden" name="consent_version" value="site-1.0" />
                        <input type="hidden" name="consents[0][consent_key]" value="essential_cookies" />
                        <input type="hidden" name="consents[0][accepted]" value="1" />
                        <input type="hidden" name="consents[0][consent_text_snapshot]" value="<?= htmlspecialchars($consentText, ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="consents[0][accepted_at]" value="<?= htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?>" />

                        <button
                            type="submit"
                            class="inline-flex min-h-12 items-center justify-center rounded-2xl bg-[#00c8ff] px-6 py-3 font-mono text-sm font-semibold text-[#041018] shadow-[0_16px_36px_rgba(0,200,255,0.28)] transition-transform duration-200 hover:-translate-y-0.5"
                        >
                            Essenzielle Cookies akzeptieren
                        </button>
                    </form>
                </div>

                <aside class="border-t border-[#00c8ff]/10 bg-[#0b1623]/95 p-6 sm:p-8 lg:border-l lg:border-t-0 lg:p-10">
                    <div class="rounded-[1.5rem] border border-[#1b3146] bg-[#0f1c2b] p-5">
                        <p class="font-mono text-xs uppercase tracking-[0.22em] text-[#8db1d6]">Erreichbar ohne Zustimmung</p>
                        <div class="mt-4 space-y-3">
                            <a class="block rounded-xl border border-[#00c8ff]/12 bg-[#091522] px-4 py-3 text-sm text-[#f4f8fc] transition-colors duration-200 hover:border-[#00c8ff]/25 hover:bg-[#0c2132]" href="/impressum">Impressum</a>
                            <a class="block rounded-xl border border-[#00c8ff]/12 bg-[#091522] px-4 py-3 text-sm text-[#f4f8fc] transition-colors duration-200 hover:border-[#00c8ff]/25 hover:bg-[#0c2132]" href="/agb">AGB</a>
                            <a class="block rounded-xl border border-[#00c8ff]/12 bg-[#091522] px-4 py-3 text-sm text-[#f4f8fc] transition-colors duration-200 hover:border-[#00c8ff]/25 hover:bg-[#0c2132]" href="/datenschutz">Datenschutz</a>
                        </div>
                        <p class="mt-5 text-sm leading-7 text-[#b9c9db]">
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
  body.gb-essential-cookie-locked {
    overflow: hidden;
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

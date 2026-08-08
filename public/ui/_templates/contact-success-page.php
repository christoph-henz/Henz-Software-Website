<?php

declare(strict_types=1);

$pageTitle = 'Anfrage erfolgreich gesendet - Henz Software';
?><!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/ui/_assets/css/theme.css" />
    <link rel="stylesheet" href="/ui/_assets/css/tailwind.css" />
</head>
<body>
    <?php require __DIR__ . '/../_partials/navbar.php'; ?>

    <main class="gb-main sp-main">
        <section class="relative py-24">
            <div class="mx-auto max-w-4xl px-6">
                <div class="rounded-3xl border border-cyan-400/20 bg-[#060a0f] p-8 md:p-10 shadow-2xl shadow-cyan-500/10">
                    <div class="mb-6 inline-flex h-14 w-14 items-center justify-center rounded-full bg-cyan-400/15 text-3xl text-cyan-300">✓</div>
                    <h1 class="text-3xl md:text-4xl font-bold text-slate-100 mb-3">Vielen Dank für Ihre Anfrage</h1>
                    <p class="text-slate-300 mb-8">Ihre Angaben wurden erfolgreich übermittelt. Unten sehen Sie eine Übersicht Ihrer Eingaben.</p>

                    <div id="contactSuccessMeta" class="mb-6 rounded-xl border border-cyan-400/10 bg-[#0b1119] p-4 text-sm text-slate-300"></div>
                    <div id="contactSuccessSummary" class="space-y-3"></div>

                    <div class="mt-10 flex flex-wrap gap-3">
                        <a href="/kontakt" class="inline-flex items-center justify-center rounded-xl px-6 py-3 font-semibold font-mono bg-cyan-400 text-[#060a0f] hover:translate-y-[-1px] transition-transform">Neue Anfrage senden</a>
                        <a href="/" class="inline-flex items-center justify-center rounded-xl px-6 py-3 font-semibold font-mono border border-slate-500/40 text-slate-200 hover:border-cyan-300/60 hover:text-cyan-200 transition-colors">Zur Startseite</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php require __DIR__ . '/../_partials/footer.php'; ?>
    <script src="/ui/_assets/js/navbar.js" defer></script>
    <script>
        (function () {
            'use strict';

            var metaEl = document.getElementById('contactSuccessMeta');
            var summaryEl = document.getElementById('contactSuccessSummary');
            var raw = sessionStorage.getItem('contactFormSuccessSummary');

            if (!summaryEl || !metaEl || !raw) {
                if (metaEl) {
                    metaEl.textContent = 'Keine gespeicherten Formulardaten gefunden. Bitte senden Sie das Kontaktformular erneut ab.';
                }
                return;
            }

            var data;
            try {
                data = JSON.parse(raw);
            } catch (_err) {
                metaEl.textContent = 'Die gespeicherten Formulardaten konnten nicht gelesen werden.';
                return;
            }

            var submittedAt = data && data.submitted_at ? String(data.submitted_at) : '';
            var typeLabel = data && data.service_type_label ? String(data.service_type_label) : '-';
            var reference = data && data.reference ? String(data.reference) : '';

            metaEl.innerHTML = '' +
                '<div><strong>Typ:</strong> ' + escapeHtml(typeLabel) + '</div>' +
                (submittedAt ? '<div><strong>Zeitpunkt:</strong> ' + escapeHtml(formatDate(submittedAt)) + '</div>' : '') +
                (reference ? '<div><strong>Referenz:</strong> ' + escapeHtml(reference) + '</div>' : '');

            var items = Array.isArray(data && data.items) ? data.items : [];
            if (!items.length) {
                summaryEl.innerHTML = '<p class="text-slate-400">Es wurden keine anzeigbaren Felder gespeichert.</p>';
                return;
            }

            summaryEl.innerHTML = items.map(function (item) {
                var label = escapeHtml(item && item.label ? String(item.label) : '-');
                var value = escapeHtml(item && item.value ? String(item.value) : '-').replace(/\n/g, '<br>');
                return '' +
                    '<article class="rounded-xl border border-cyan-400/10 bg-[#0b1119] p-4">' +
                    '  <h2 class="text-sm font-semibold text-cyan-300 mb-2">' + label + '</h2>' +
                    '  <p class="text-slate-200 leading-relaxed">' + value + '</p>' +
                    '</article>';
            }).join('');

            sessionStorage.removeItem('contactFormSuccessSummary');

            function formatDate(iso) {
                var date = new Date(iso);
                if (Number.isNaN(date.getTime())) return iso;
                return date.toLocaleString('de-DE', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }
        })();
    </script>
</body>
</html>

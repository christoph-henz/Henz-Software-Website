<?php

declare(strict_types=1);

$pageTitle = (string) ($pageTitle ?? 'E-Mail-Vorlagen - Henz Software');
$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$logoutAction = (string) ($logoutAction ?? '/logout');
$csrfToken = (string) ($csrfToken ?? '');
$templates = is_array($templates ?? null) ? $templates : [];
$placeholderGroups = is_array($placeholderGroups ?? null) ? $placeholderGroups : [];

$extraHead = '<link rel="stylesheet" href="/ui/_assets/css/admin-email-templates.css" /><link rel="icon" type="image/svg+xml" href="/ui/_assets/images/favicon.svg" />';
$extraScripts = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('.admin-email-templates-form[data-preview-endpoint]');

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function highlightHtmlSnippet(html) {
        var escaped = escapeHtml(html);

        escaped = escaped.replace(/&lt;!--[\s\S]*?--&gt;/g, function (m) {
            return '<span class="admin-email-templates-code-tok-comment">' + m + '</span>';
        });

        escaped = escaped.replace(/(&lt;\/?)([a-zA-Z0-9:-]+)([\s\S]*?)(\/?&gt;)/g, function (_, open, tag, attrs, close) {
            var highlightedAttrs = attrs.replace(/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(\s*=\s*)(&quot;[^&]*?&quot;|\'[^']*?\'|[^\s&]+)?/g, function (__ , name, eq, value) {
                var out = '<span class="admin-email-templates-code-tok-attr">' + name + '</span>' + eq;
                if (value) {
                    out += '<span class="admin-email-templates-code-tok-value">' + value + '</span>';
                }
                return out;
            });

            return '<span class="admin-email-templates-code-tok-tag">' + open + tag + '</span>' + highlightedAttrs + '<span class="admin-email-templates-code-tok-tag">' + close + '</span>';
        });

        return escaped;
    }

    forms.forEach(function (form) {
        var trigger = form.querySelector('[data-preview-trigger]');
        var panel = form.querySelector('[data-preview-panel]');
        var status = form.querySelector('[data-preview-status]');
        var subject = form.querySelector('[data-preview-subject]');
        var frame = form.querySelector('[data-preview-frame]');
        var warningBox = form.querySelector('[data-preview-warnings]');
        var warningList = form.querySelector('[data-preview-warnings-list]');
        var htmlInput = form.querySelector('textarea[name="html_template"]');
        var codeOverlay = form.querySelector('[data-html-highlight-overlay]');
        var requestSeq = 0;

        if (!trigger || !panel || !status || !subject || !frame || !warningBox || !warningList || !htmlInput || !codeOverlay) {
            return;
        }

        function renderCodeHighlight() {
            var source = String(htmlInput.value || '');
            // Preserve the final visual line in pre-wrap overlays.
            if (source.endsWith('\n')) {
                source += ' ';
            }
            codeOverlay.innerHTML = highlightHtmlSnippet(source);
            codeOverlay.scrollTop = htmlInput.scrollTop;
            codeOverlay.scrollLeft = htmlInput.scrollLeft;
        }

        htmlInput.addEventListener('input', renderCodeHighlight);
        htmlInput.addEventListener('scroll', function () {
            codeOverlay.scrollTop = htmlInput.scrollTop;
            codeOverlay.scrollLeft = htmlInput.scrollLeft;
        });
        renderCodeHighlight();

        trigger.addEventListener('click', async function () {
            var endpoint = String(form.getAttribute('data-preview-endpoint') || '').trim();
            if (endpoint === '') {
                return;
            }

            requestSeq += 1;
            var currentRequestSeq = requestSeq;

            trigger.disabled = true;
            trigger.textContent = 'Vorschau wird geladen...';
            panel.hidden = false;
            status.textContent = 'Rendern der Vorschau...';
            status.className = 'admin-email-templates-preview-status';
            warningBox.hidden = true;
            warningList.textContent = '';

            try {
                var response = await fetch(endpoint, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });

                var payload = await response.json();
                if (currentRequestSeq !== requestSeq) {
                    return;
                }

                if (!response.ok || !payload || payload.success !== true) {
                    var errorMessage = payload && payload.message ? String(payload.message) : 'Vorschau konnte nicht erzeugt werden.';
                    throw new Error(errorMessage);
                }

                subject.textContent = String(payload.subject || '(leer)');
                frame.setAttribute('srcdoc', String(payload.html || ''));

                var unknown = [];
                if (payload.warnings && Array.isArray(payload.warnings.unknown_placeholders)) {
                    unknown = payload.warnings.unknown_placeholders.map(function (entry) {
                        return String(entry || '').trim();
                    }).filter(function (entry) {
                        return entry !== '';
                    });
                }

                if (unknown.length > 0) {
                    warningBox.hidden = false;
                    warningList.textContent = unknown.join(', ');
                } else {
                    warningBox.hidden = true;
                    warningList.textContent = '';
                }

                status.textContent = 'Vorschau erfolgreich aktualisiert.';
                status.className = 'admin-email-templates-preview-status is-success';
            } catch (error) {
                if (currentRequestSeq !== requestSeq) {
                    return;
                }

                status.textContent = error instanceof Error ? error.message : 'Unbekannter Fehler bei der Vorschau.';
                status.className = 'admin-email-templates-preview-status is-error';
            } finally {
                if (currentRequestSeq !== requestSeq) {
                    return;
                }

                trigger.disabled = false;
                trigger.textContent = 'Vorschau rendern';
            }
        });
    });
});
</script>
HTML;

ob_start();
?>
<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">E-Mail-Vorlagen</h1>
        <p class="admin-page-subtitle">Eigene Admin-Sektion für E-Mail-Vorlagen mit Testversand an die Support-Adresse.</p>
    </div>
</div>

<section class="rounded-2xl border border-border bg-card p-5 shadow-[0_24px_60px_color-mix(in_srgb,var(--background)_28%,transparent)] backdrop-blur">
    <h2 class="admin-email-templates-hint-title">Verfügbare Platzhalter</h2>
    <p class="admin-email-templates-hint-text">Alle Felder koennen als Platzhalter mit doppelten geschweiften Klammern verwendet werden, z. B. {{client.first_name}}.</p>

    <div class="admin-email-templates-placeholder-grid text-sm text-muted-foreground">
        <?php foreach ($placeholderGroups as $groupName => $placeholders): ?>
        <div class="admin-email-templates-placeholder-group rounded-lg border border-border bg-input-background/70 p-4 shadow-[0_24px_60px_color-mix(in_srgb,var(--background)_24%,transparent)] backdrop-blur">
            <h3><?= htmlspecialchars((string) $groupName, ENT_QUOTES, 'UTF-8'); ?></h3>
            <ul>
                <?php foreach ((array) $placeholders as $placeholder): ?>
                <li><?= htmlspecialchars((string) $placeholder, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="admin-email-templates-list">
    <?php if ($templates === []): ?>
    <p class="admin-email-templates-empty">Keine E-Mail-Vorlagen vorhanden.</p>
    <?php endif; ?>

    <?php foreach ($templates as $tpl): ?>
    <?php
        $id = (int) ($tpl['id'] ?? 0);
        $name = (string) ($tpl['display_name'] ?? 'Vorlage');
        $key = (string) ($tpl['template_key'] ?? '');
        $subject = (string) ($tpl['subject_template'] ?? '');
        $htmlTemplate = (string) ($tpl['html_template'] ?? '');
        $isActive = (int) ($tpl['is_active'] ?? 0) === 1;
        $updatedAt = (string) ($tpl['updated_at'] ?? '');
    ?>
    <article class="admin-email-templates-card">
        <div class="admin-email-templates-card-head">
            <div>
                <h2 class="admin-email-templates-card-title"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="admin-email-templates-card-meta">Key: <span><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></span></p>
            </div>
            <div class="admin-email-templates-status<?= $isActive ? ' is-active' : ' is-inactive'; ?>">
                <?= $isActive ? 'Aktiv' : 'Inaktiv'; ?>
            </div>
        </div>

        <form method="post" action="/email-templates/<?= $id; ?>" class="admin-email-templates-form" data-preview-endpoint="/email-templates/<?= $id; ?>/preview">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />

            <label class="admin-email-templates-label" for="subject-<?= $id; ?>">Betreff</label>
            <input
                id="subject-<?= $id; ?>"
                class="admin-email-templates-input"
                type="text"
                name="subject_template"
                value="<?= htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?>"
                required
            />

            <label class="admin-email-templates-label" for="html-<?= $id; ?>">HTML-Inhalt</label>
            <div class="admin-email-templates-editor-wrap">
                <pre class="admin-email-templates-editor-overlay" data-html-highlight-overlay aria-hidden="true"></pre>
                <textarea
                    id="html-<?= $id; ?>"
                    class="admin-email-templates-textarea admin-email-templates-textarea--highlighted"
                    name="html_template"
                    rows="10"
                    spellcheck="false"
                    required
                ><?= htmlspecialchars($htmlTemplate, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <label class="admin-email-templates-checkbox-label" for="active-<?= $id; ?>">
                <input id="active-<?= $id; ?>" type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : ''; ?> />
                <span>Vorlage aktiv</span>
            </label>

            <div class="admin-email-templates-actions">
                <button type="submit" class="admin-email-templates-btn">Speichern</button>
                <button type="button" class="admin-email-templates-btn admin-email-templates-btn--secondary" data-preview-trigger>Vorschau rendern</button>
                <button
                    type="submit"
                    class="admin-email-templates-btn admin-email-templates-btn--secondary"
                    formaction="/email-templates/<?= $id; ?>/test"
                    formmethod="post"
                >Testmail an Support senden</button>
            </div>

            <section class="admin-email-templates-preview" data-preview-panel hidden>
                <p class="admin-email-templates-preview-status" data-preview-status>Bereit.</p>
                <div class="admin-email-templates-preview-warnings" data-preview-warnings hidden>
                    <p class="admin-email-templates-preview-warnings-title">Unbekannte Platzhalter erkannt</p>
                    <p class="admin-email-templates-preview-warnings-list" data-preview-warnings-list></p>
                </div>
                <p class="admin-email-templates-preview-subject-label">Gerenderter Betreff</p>
                <div class="admin-email-templates-preview-subject" data-preview-subject></div>
                <p class="admin-email-templates-preview-body-label">Gerenderter HTML-Body</p>
                <iframe class="admin-email-templates-preview-frame" data-preview-frame sandbox="allow-same-origin"></iframe>
            </section>

            <?php if ($updatedAt !== ''): ?>
            <p class="admin-email-templates-updated">Zuletzt aktualisiert: <?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
        </form>
    </article>
    <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();

require __DIR__ . '/../../_partials/operations/admin-layout.php';

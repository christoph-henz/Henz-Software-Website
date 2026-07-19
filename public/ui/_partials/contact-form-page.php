<?php

declare(strict_types=1);

$cfg = require __DIR__ . '/../_config/contact-page.php';
$hero = is_array($cfg['hero'] ?? null) ? $cfg['hero'] : [];
$process = is_array($cfg['process'] ?? null) ? $cfg['process'] : [];
$form = is_array($cfg['form'] ?? null) ? $cfg['form'] : [];
$legalHint = (string) ($cfg['legal_hint'] ?? '');

$action = (string) ($form['action'] ?? '/api/v1/bookings');
$method = (string) ($form['method'] ?? 'post');
$serviceOptions = is_array($form['services'] ?? null) ? $form['services'] : [];
$fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
$packageOptions = is_array($form['packages'] ?? null) ? $form['packages'] : [];
$slotPicker = is_array($form['slot_picker'] ?? null) ? $form['slot_picker'] : [];
$consents = is_array($form['consents'] ?? null) ? $form['consents'] : [];
$submitLabel = (string) ($form['submit_label'] ?? 'Anfrage absenden');
$processSteps = is_array($process['steps'] ?? null) ? $process['steps'] : [];

$slotPickerEndpoint = (string) ($slotPicker['slots_endpoint'] ?? '/v1/availability/slots');
$slotPickerTimezone = (string) ($slotPicker['timezone'] ?? 'Europe/Berlin');
$slotPickerStepMinutes = (int) ($slotPicker['slot_step_minutes'] ?? 30);
if ($slotPickerStepMinutes < 5) {
    $slotPickerStepMinutes = 30;
}
$slotPickerMinNoticeHours = (int) ($slotPicker['booking_min_hours_notice'] ?? 24);
if ($slotPickerMinNoticeHours < 0) {
    $slotPickerMinNoticeHours = 24;
}
$slotPickerAdvanceDays = (int) ($slotPicker['booking_advance_days'] ?? 60);
if ($slotPickerAdvanceDays < 1) {
    $slotPickerAdvanceDays = 60;
}
$slotPickerWindows = is_array($slotPicker['work_windows_by_day'] ?? null) ? $slotPicker['work_windows_by_day'] : [];
$slotPickerWindowsJson = htmlspecialchars(
    json_encode($slotPickerWindows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ENT_QUOTES,
    'UTF-8'
);

// Pre-select service from query parameter (sanitized to slug characters only)
$preselectedService = isset($_GET['service']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['service'])) : '';
$preselectedPackage = isset($_GET['package']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['package'])) : '';
$selectedPackage = is_array($packageOptions[$preselectedPackage] ?? null) ? $packageOptions[$preselectedPackage] : null;
$lockedServiceSlug = is_array($selectedPackage) ? (string) ($selectedPackage['service_slug'] ?? '') : '';
if ($lockedServiceSlug !== '') {
    $preselectedService = $lockedServiceSlug;
}

$selectedPackageName = is_array($selectedPackage) ? (string) ($selectedPackage['name'] ?? '') : '';
$selectedPackageSessions = is_array($selectedPackage) ? (int) ($selectedPackage['session_count'] ?? 0) : 0;
$selectedPackagePrice = is_array($selectedPackage) ? (string) ($selectedPackage['price'] ?? '') : '';
?>
<section class="sp-section sp-booking-hero">
    <div class="gb-container">
        <div class="gb-tag"><?= htmlspecialchars((string) ($hero['tag'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        <h1><?= htmlspecialchars((string) ($hero['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="sp-intro"><?= htmlspecialchars((string) ($hero['text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</section>

<section class="sp-section sp-section--alt sp-booking-main">
    <div class="gb-container sp-booking-grid">

        <!-- Info sidebar -->
        <aside class="sp-booking-info">
            <h2><?= htmlspecialchars((string) ($process['title'] ?? 'So geht es weiter'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <ol class="sp-booking-steps">
                <?php foreach ($processSteps as $step): ?>
                    <li><?= htmlspecialchars((string) $step, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ol>
            <div class="sp-booking-note">
                <strong>Kostenlos & unverbindlich:</strong> Das Kennenlerngespräch ist kostenfrei und verpflichtet Sie zu nichts.
            </div>
        </aside>

        <!-- Form -->
        <div class="sp-booking-form-wrap">
            <form
                id="booking-form"
                action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>"
                method="<?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8'); ?>"
                data-locked-service-slug="<?= htmlspecialchars($lockedServiceSlug, ENT_QUOTES, 'UTF-8'); ?>"
                data-slots-endpoint="<?= htmlspecialchars($slotPickerEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
                data-slots-timezone="<?= htmlspecialchars($slotPickerTimezone, ENT_QUOTES, 'UTF-8'); ?>"
                data-slot-step-minutes="<?= htmlspecialchars((string) $slotPickerStepMinutes, ENT_QUOTES, 'UTF-8'); ?>"
                data-slot-min-notice-hours="<?= htmlspecialchars((string) $slotPickerMinNoticeHours, ENT_QUOTES, 'UTF-8'); ?>"
                data-slot-advance-days="<?= htmlspecialchars((string) $slotPickerAdvanceDays, ENT_QUOTES, 'UTF-8'); ?>"
                data-slot-work-windows="<?= $slotPickerWindowsJson; ?>"
                novalidate
            >
                <div class="sp-booking-note">
                    <strong>Hinweis zu Paketen:</strong>
                    Wenn Sie bereits ein Paket erworben haben, werden Termine bei passender Gesprächsform aus dem Paketkontingent genutzt.
                    <br>
                    In diesem Fall müssen Sie das Paket <strong>nicht</strong> erneut auswählen, können aber bei Bedarf hier ein anderes Paket auswählen oder die Auswahl leer lassen.
                </div>

                <?php if ($packageOptions !== []): ?>
                    <div class="gb-fl-wrap gb-fl-wrap--select">
                        <select name="package_slug" id="booking-package-select">
                            <option value="">Kein Paket auswählen</option>
                            <?php foreach ($packageOptions as $pkgSlug => $pkg): ?>
                                <?php
                                $pkgLabelParts = [];
                                $pkgSessions = (int) ($pkg['session_count'] ?? 0);
                                if ($pkgSessions > 0) {
                                    $pkgLabelParts[] = $pkgSessions . ' Sitzungen';
                                }
                                $pkgPrice = trim((string) ($pkg['price'] ?? ''));
                                if ($pkgPrice !== '') {
                                    $pkgLabelParts[] = $pkgPrice;
                                }
                                $pkgLabelSuffix = $pkgLabelParts !== [] ? ' (' . implode(' - ', $pkgLabelParts) . ')' : '';
                                ?>
                                <option
                                    value="<?= htmlspecialchars((string) $pkgSlug, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-service-slug="<?= htmlspecialchars((string) ($pkg['service_slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    <?= ((string) $pkgSlug === $preselectedPackage) ? ' selected' : ''; ?>
                                >
                                    <?= htmlspecialchars((string) ($pkg['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?= htmlspecialchars($pkgLabelSuffix, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label>Paket (optional)</label>
                    </div>
                <?php endif; ?>

                <?php if ($selectedPackage !== null): ?>
                    <div class="sp-booking-note" id="selected-package-panel">
                        <strong>Paket ausgewählt:</strong>
                        <?= htmlspecialchars($selectedPackageName, ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($selectedPackageSessions > 0): ?>
                            (<?= htmlspecialchars((string) $selectedPackageSessions, ENT_QUOTES, 'UTF-8'); ?> Sitzungen)
                        <?php endif; ?>
                        <?php if ($selectedPackagePrice !== ''): ?>
                            – <?= htmlspecialchars($selectedPackagePrice, ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Service selection -->
                <div class="gb-fl-wrap gb-fl-wrap--select">
                    <select name="service" required>
                        <?php foreach ($serviceOptions as $opt): ?>
                            <?php
                            $optVal = (string) ($opt['value'] ?? '');
                            $isPlaceholder = $optVal === '';
                            $isSelected = !$isPlaceholder && $optVal === $preselectedService;
                            $isLockedOut = $lockedServiceSlug !== '' && !$isPlaceholder && $optVal !== $lockedServiceSlug;
                            $attrs = $isPlaceholder
                                ? ' disabled' . ($preselectedService === '' ? ' selected' : '')
                                : ($isSelected ? ' selected' : '') . ($isLockedOut ? ' disabled' : '');
                            ?>
                            <option value="<?= htmlspecialchars($optVal, ENT_QUOTES, 'UTF-8'); ?>"<?= $attrs; ?>>
                                <?= htmlspecialchars((string) ($opt['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>Gesprächsform *</label>
                </div>

                <!-- Other fields -->
                <div class="sp-form-fields">
                    <?php foreach ($fields as $field): ?>
                        <?php
                        $name = (string) ($field['name'] ?? '');
                        $type = (string) ($field['type'] ?? 'text');
                        $label = (string) ($field['label'] ?? $name);
                        $required = (bool) ($field['required'] ?? false);
                        $col = (string) ($field['col'] ?? 'full');
                        ?>
                        <?php if ($name === 'termin' && $type === 'datetime-local'): ?>
                            <div class="gb-fl-wrap gb-fl-wrap--half">
                                <input type="date" name="termin_date" id="booking-termin-date" placeholder=" "<?= $required ? ' required' : ''; ?> />
                                <label>Wunschtermin: Datum *</label>
                            </div>
                            <div class="gb-fl-wrap gb-fl-wrap--half gb-fl-wrap--select">
                                <select name="termin_slot" id="booking-termin-slot"<?= $required ? ' required' : ''; ?>>
                                    <option value="">Bitte Datum wählen …</option>
                                </select>
                                <label>Wunschtermin: Uhrzeit *</label>
                            </div>
                            <input type="hidden" name="termin" id="booking-termin-value" />
                            <?php continue; ?>
                        <?php endif; ?>
                        <div class="gb-fl-wrap gb-fl-wrap--<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($type === 'textarea'): ?>
                                <textarea name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" placeholder=" "<?= $required ? ' required' : ''; ?>></textarea>
                            <?php else: ?>
                <?php
                                $extraAttrs = '';
                                foreach ((array) ($field['attrs'] ?? []) as $attrKey => $attrVal) {
                                    $extraAttrs .= ' ' . htmlspecialchars((string) $attrKey, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $attrVal, ENT_QUOTES, 'UTF-8') . '"';
                                }
                                ?>
                                <input
                                    type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>"
                                    name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder=" "
                                    <?= $required ? 'required' : ''; ?>
                                    <?= $extraAttrs; ?>
                                />
                            <?php endif; ?>
                            <label><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <input type="hidden" name="form_started_at" value="<?= time(); ?>" />

                <!-- Honeypot field for bot detection -->
                <div class="sp-honeypot" aria-hidden="true">
                    <label for="company">Unternehmen</label>
                    <input type="text" id="company" name="company" tabindex="-1" autocomplete="off" />
                </div>

                <!-- Consents -->
                <div class="sp-consents">
                    <?php foreach ($consents as $idx => $consent): ?>
                        <?php
                        $consentKey = (string) ($consent['key'] ?? '');
                        $consentLabel = (string) ($consent['label'] ?? '');
                        ?>
                        <label class="gb-check">
                            <input 
                                type="checkbox" 
                                name="consent_check_<?= htmlspecialchars($consentKey, ENT_QUOTES, 'UTF-8'); ?>"
                                data-consent-key="<?= htmlspecialchars($consentKey, ENT_QUOTES, 'UTF-8'); ?>"
                                required 
                            />
                            <span><?= htmlspecialchars($consentLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="sp-submit-btn">
                    <?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8'); ?>
                </button>

                <?php if ($legalHint !== ''): ?>
                    <p class="sp-legal-hint"><?= $legalHint; /* intentionally not escaped – contains <a> tag */ ?></p>
                <?php endif; ?>
            </form>

            <div id="booking-success" class="sp-booking-success" hidden>
                <div class="sp-success-icon">✓</div>
                <h3>Ihre Anfrage ist eingegangen!</h3>
                <p>Ich melde mich innerhalb von 24 Stunden bei Ihnen.</p>
            </div>
        </div>
    </div>
</section>

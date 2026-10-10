<?php

declare(strict_types=1);

$cfg = require __DIR__ . '/../_config/contact-page.php';
$hero = is_array($cfg['hero'] ?? null) ? $cfg['hero'] : [];
$process = is_array($cfg['process'] ?? null) ? $cfg['process'] : [];
$form = is_array($cfg['form'] ?? null) ? $cfg['form'] : [];
$legalHint = (string) ($cfg['legal_hint'] ?? '');

$selectedType = $_GET['type'] ?? '';


$action = (string) ($form['action'] ?? '/api/v1/appointment');
$method = (string) ($form['method'] ?? 'post');
$serviceOptions = is_array($form['services'] ?? null) ? $form['services'] : [];

$slotPicker = is_array($form['slot_picker'] ?? null) ? $form['slot_picker'] : [];
$consents = is_array($form['consents'] ?? null) ? $form['consents'] : [];
$submitLabel = (string) ($form['submit_label'] ?? 'Anfrage absenden');
$successRedirectUrl = (string) ($form['success_redirect_url'] ?? '/kontakt/erfolg');
$processSteps = is_array($process['steps'] ?? null) ? $process['steps'] : [];

$slotPickerEndpoint = (string) ($slotPicker['slots_endpoint'] ?? '/v1/availability/slots');
$slotPickerDaysEndpoint = (string) ($slotPicker['days_endpoint'] ?? '/v1/availability/days');
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
$fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$formState = $_SESSION['contact_form'] ?? [];
// echo '<pre>';
// var_dump(session_id());
// var_dump($_SESSION['contact_form'] ?? []);
// echo '</pre>';
// die();
function renderFields(array $fields, string $path = '', array $formState = []): void
{
    foreach ($fields as $field) {
        // var_dump($field);
        $name = (string) ($field['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $type = (string) ($field['type'] ?? 'text');
        $label = (string) ($field['label'] ?? '');
        $placeholder = (string) ($field['placeholder'] ?? '');
        $col = (string) ($field['col'] ?? 'full');
        $validators = [];

        if (!empty($field['required'])) {
            $validators[] = [
                'rule' => 'required',
                'message' => 'Dieses Feld ist erforderlich.'
            ];
        }

        if (!empty($field['validation'])) {

            if (isset($field['validation']['rule'])) {
                // Einzelner Validator
                $validators[] = $field['validation'];
            } else {
                // Mehrere Validatoren
                foreach ($field['validation'] as $validator) {
                    $validators[] = $validator;
                }
            }

        }

        $required = (bool) ($field['required'] ?? false);
        // var_dump($validators);
        // die();
        $choices = is_array($field['choices'] ?? null)
            ? $field['choices']
            : [];

        $fieldId = $path === ''
            ? $name
            : $path . '.' . $name;
        $value = $formState[$fieldId] ?? '';
        // if ($fieldId === 'service_type.contact.firstname') {
        //     var_dump($formState['service_type.contact.firstname'] ?? null);
        //     var_dump($value);
        //     die();
        // }
        $requiredAttr = $required ? 'required' : '';
        $placeholderAttr = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');
        $toolParamDescription = $label !== ''
            ? $label . ($required ? ' (Pflichtfeld)' : '')
            : ($name . ($required ? ' (Pflichtfeld)' : ''));
        $toolParamDescriptionAttr = htmlspecialchars($toolParamDescription, ENT_QUOTES, 'UTF-8');

        $colSpanClass = $col === 'half'
            ? 'md:col-span-1'
            : 'md:col-span-2';

        ?>

        <div class="relative gb-fl-wrap my-6 <?= $colSpanClass ?>" data-field="<?= htmlspecialchars($fieldId) ?>">

            <?php

            switch ($type) {

                case 'text':
                case 'email':
                case 'tel':
                case 'date':
                    ?>

                    <input
                        class="peer w-full rounded-xl border px-5 pt-7 pb-3 focus:outline-none transition-colors"
                        style="border-color: var(--border); background: var(--card); color: var(--foreground);"
                        type="<?= htmlspecialchars($type) ?>" id="<?= htmlspecialchars($fieldId) ?>"
                        name="<?= htmlspecialchars($fieldId) ?>" placeholder="<?= $placeholderAttr ?>"
                        toolparamdescription="<?= $toolParamDescriptionAttr ?>"
                        value="<?= htmlspecialchars((string) $value) ?>" data-validators='<?= htmlspecialchars(
                               json_encode($validators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                               ENT_QUOTES,
                               'UTF-8'
                           ) ?>'>
                    <div class="validation-errors mt-2"></div>
                    <label
                        class="absolute left-5 top-5 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"
                        style="color: var(--muted-foreground);"
                        for="<?= htmlspecialchars($fieldId) ?>">
                        <?= htmlspecialchars($label) ?>
                    </label>

                    <?php
                    break;
                case 'time':
                    ?>

                    <input
                        class="peer w-full rounded-xl border px-5 pt-7 pb-3 focus:outline-none transition-colors"
                        style="border-color: var(--border); background: var(--card); color: var(--foreground);"
                        type="<?= htmlspecialchars($type) ?>" id="<?= htmlspecialchars($fieldId) ?>"
                        name="<?= htmlspecialchars($fieldId) ?>" placeholder="<?= $placeholderAttr ?>"
                        toolparamdescription="<?= $toolParamDescriptionAttr ?>"
                        value="<?= htmlspecialchars((string) $value) ?>" data-validators='<?= htmlspecialchars(
                               json_encode($validators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                               ENT_QUOTES,
                               'UTF-8'
                           ) ?>'>
                    <div class="validation-errors mt-2"></div>
                    <label
                        class="absolute left-5 top-5 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"
                        style="color: var(--muted-foreground);"
                        for="<?= htmlspecialchars($fieldId) ?>">
                        <?= htmlspecialchars($label) ?>
                    </label>

                    <?php
                    break;
                case 'file':
                    ?>

                    <input
                        class="peer w-full rounded-xl border px-5 pt-7 pb-3 focus:outline-none transition-colors"
                        style="border-color: var(--border); background: var(--card); color: var(--foreground);"
                        type="<?= htmlspecialchars($type) ?>" id="<?= htmlspecialchars($fieldId) ?>"
                        name="<?= htmlspecialchars($fieldId) ?>" placeholder="<?= $placeholderAttr ?>"
                        toolparamdescription="<?= $toolParamDescriptionAttr ?>"
                        value="<?= htmlspecialchars((string) $value) ?>" data-validators='<?= htmlspecialchars(
                               json_encode($validators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                               ENT_QUOTES,
                               'UTF-8'
                           ) ?>'>
                    <div class="validation-errors mt-2"></div>
                    <label
                        class="absolute left-5 top-5 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"
                        style="color: var(--muted-foreground);"
                        for="<?= htmlspecialchars($fieldId) ?>">
                        <?= htmlspecialchars($label) ?>
                    </label>

                    <?php
                    break;

                case 'textarea':
                    ?>

                    <textarea
                        class="peer min-h-[180px] w-full rounded-xl border px-5 pt-7 pb-3 resize-y focus:outline-none transition-colors"
                        style="border-color: var(--border); background: var(--card); color: var(--foreground);"
                        id="<?= htmlspecialchars($fieldId) ?>" name="<?= htmlspecialchars($fieldId) ?>"
                        toolparamdescription="<?= $toolParamDescriptionAttr ?>"
                        placeholder="<?= $placeholderAttr ?>" data-validators='<?= htmlspecialchars(
                              json_encode($validators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                              ENT_QUOTES,
                              'UTF-8'
                          ) ?>'><?= htmlspecialchars((string) $value) ?></textarea>
                    <div class="validation-errors mt-2"></div>
                    <label
                        class="absolute left-5 top-5 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"
                        style="color: var(--muted-foreground);"
                        for="<?= htmlspecialchars($fieldId) ?>">
                        <?= htmlspecialchars($label) ?>
                    </label>

                    <?php
                    break;

                case 'select':
                case 'choice':
                    ?>

                    <select
                        class="peer w-full rounded-xl border px-5 pt-7 pb-3 appearance-none focus:outline-none transition-colors"
                        style="border-color: var(--border); background: var(--card); color: var(--foreground);"
                        toolparamdescription="<?= $toolParamDescriptionAttr ?>"
                        id="<?= htmlspecialchars($fieldId) ?>" name="<?= htmlspecialchars($fieldId) ?>" data-validators='<?= htmlspecialchars(
                                json_encode($validators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>'>

                        <option value="">
                            <?= htmlspecialchars($placeholder !== '' ? $placeholder : 'Bitte auswählen…') ?>
                        </option>

                        <?php foreach ($choices as $choice): ?>

                            <option value="<?= htmlspecialchars($choice['value']) ?>"
                                data-target="<?= htmlspecialchars($fieldId . '.' . $choice['value']) ?>" <?= $value === $choice['value'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($choice['label']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                    <div class="validation-errors mt-2"></div>

                    <label
                        class="absolute left-5 top-5 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"
                        style="color: var(--muted-foreground);"><?= htmlspecialchars($label) ?></label>

                    <?php
                    break;
            }

            ?>

        </div>

        <?php

        /*
         * Hier beginnt die Rekursion
         */

        foreach ($choices as $option) {

            if (empty($option['fields'])) {
                continue;
            }

            ?>

            <div class="option-container hidden" data-parent="<?= htmlspecialchars($fieldId) ?>"
                data-value="<?= htmlspecialchars($option['value']) ?>">

                <?php
                renderFields(
                    $option['fields'],
                    $fieldId . '.' . $option['value'],
                    $formState
                );
                ?>

            </div>

            <?php
        }
    }
}

?>
<section class="">
    <div class="absolute right-0 top-20 w-[480px] h-[480px] rounded-full
        bg-cyan-400/20 blur-[120px] opacity-20 pointer-events-none">
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6">
        <div class="text-xs uppercase tracking-[0.35em]
        text-cyan-400 font-mono"><?= htmlspecialchars((string) ($hero['tag'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        <h1 class="mt-5 text-5xl lg:text-7xl
        font-bold leading-tight
        font-mono" style="color: var(--foreground);"><?= htmlspecialchars((string) ($hero['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="sp-intro mt-8 text-lg leading-relaxed" style="color: var(--muted-foreground);">
            <?= htmlspecialchars((string) ($hero['text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        </p>
    </div>
</section>

<section class="relative overflow-hidden py-24 lg:py-32">

    <!-- Hintergrund -->
    <div class="absolute inset-0 opacity-[0.04] pointer-events-none
        bg-[linear-gradient(rgb(0,200,255)_1px,transparent_1px),linear-gradient(90deg,rgb(0,200,255)_1px,transparent_1px)]
        bg-[length:56px_56px]">
    </div>

    <div class="absolute right-0 top-20 w-[480px] h-[480px] rounded-full
        bg-cyan-400/20 blur-[120px] opacity-20 pointer-events-none">
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6 grid grid-cols-1 lg:grid-cols-[320px_1fr] gap-8 items-start">

        <!-- Info sidebar -->
        <aside class="sp-booking-info rounded-[28px] border p-8 space-y-8" style="border-color: var(--border); background: var(--card);">
            <h2 class="font-mono text-2xl font-bold" style="color: var(--foreground);">
                <?= htmlspecialchars((string) ($process['title'] ?? 'So geht es weiter'), ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            <ol class="sp-booking-steps space-y-4">
                <?php foreach ($processSteps as $step): ?>
                    <li><?= htmlspecialchars((string) $step, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ol>
            <div class="sp-booking-note text-sm leading-relaxed" style="color: var(--muted-foreground);">
                <strong style="color: var(--foreground);">Kostenlos & unverbindlich:</strong> Das Kennenlerngespräch ist kostenfrei
                und verpflichtet Sie
                zu nichts.
            </div>
        </aside>

        <!-- Form -->

        <div
            class="sp-booking-form-wrap rounded-[32px] border shadow-2xl overflow-hidden p-8 lg:p-10"
            style="border-color: var(--border); background: var(--card); box-shadow: 0 24px 60px color-mix(in srgb, var(--primary) 12%, transparent);">
            <form class="gap-6" id="booking-form" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>"
                method="<?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8'); ?>"
                toolname="submitContactRequest"
                tooldescription="Sendet eine Kontaktanfrage mit Leistungswunsch, Kontaktdaten, Termin und Einwilligungen."
                data-success-url="<?= htmlspecialchars($successRedirectUrl, ENT_QUOTES, 'UTF-8'); ?>"
                data-slots-endpoint="<?= htmlspecialchars($slotPickerEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
                data-days-endpoint="<?= htmlspecialchars($slotPickerDaysEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
                data-slots-timezone="<?= htmlspecialchars($slotPickerTimezone, ENT_QUOTES, 'UTF-8'); ?>"
                data-slot-step-minutes="<?= htmlspecialchars((string) $slotPickerStepMinutes, ENT_QUOTES, 'UTF-8'); ?>"
                data-slot-min-notice-hours="<?= htmlspecialchars((string) $slotPickerMinNoticeHours, ENT_QUOTES, 'UTF-8'); ?>"
                data-slot-advance-days="<?= htmlspecialchars((string) $slotPickerAdvanceDays, ENT_QUOTES, 'UTF-8'); ?>"
                data-slot-work-windows="<?= $slotPickerWindowsJson; ?>" novalidate>

                <?php
                //var_dump($formState);
                //die();
                //phpinfo();
                renderFields($fields, '', $formState); ?>
                <!-- Honeypot field for bot detection -->
                <div class="sp-honeypot absolute -left-[9999px] opacity-0 pointer-events-none" aria-hidden="true">
                    <label for="company">Unternehmen</label>
                    <input type="text" id="company" name="company" tabindex="-1" autocomplete="off" />
                </div>

                <!-- Consents -->
                <div class="sp-consents md:col-span-2 space-y-4 pt-2">
                    <?php foreach ($consents as $idx => $consent): ?>
                        <?php
                        $consentKey = (string) ($consent['key'] ?? '');
                        $consentLabel = (string) ($consent['label'] ?? '');
                        ?>
                        <label class="gb-checkflex flex gap-3 items-start cursor-pointer">
                            <input
                                class="mt-1 w-5 h-5 shrink-0 rounded"
                                style="border-color: color-mix(in srgb, var(--primary) 30%, transparent); background: var(--card); color: var(--primary);"
                                type="checkbox"
                                name="consent_check_<?= htmlspecialchars($consentKey, ENT_QUOTES, 'UTF-8'); ?>"
                                toolparamdescription="<?= htmlspecialchars('Einwilligung: ' . $consentLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                data-consent-key="<?= htmlspecialchars($consentKey, ENT_QUOTES, 'UTF-8'); ?>" required />
                            <span
                                class="text-sm leading-relaxed" style="color: var(--foreground);"><?= htmlspecialchars($consentLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <button type="submit"
                    class="sp-submit-btn md:col-span-2 mt-2 group inline-flex items-center justify-center gap-3 w-full rounded-xl px-8 py-5 font-semibold font-mono transition-all duration-200 hover:translate-y-[-2px]"
                    style="background: var(--primary); color: var(--primary-foreground); box-shadow: 0 12px 30px color-mix(in srgb, var(--primary) 34%, transparent);">
                    <?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8'); ?>
                </button>

                <?php if ($legalHint !== ''): ?>
                    <p class="sp-legal-hint md:col-span-2 text-xs leading-relaxed" style="color: var(--muted-foreground);">


                        <?= $legalHint; /* intentionally not escaped – contains <a> tag */ ?>
                    </p>
                <?php endif; ?>

        </div>

        <script type="module">
            import { FormValidator } from "/ui/_assets/js/form-validators.js";
            document.addEventListener("DOMContentLoaded", () => {
                const form = document.querySelector("#booking-form");
                if (window.initBookingAvailabilityPicker && form) {
                    window.initBookingAvailabilityPicker(form);
                } else if (form) {
                    initContactAvailabilityPickerFallback(form);
                }
                const validator = new FormValidator(form);
                const successUrl = form.dataset.successUrl || "/kontakt/erfolg";

                function initContactAvailabilityPickerFallback(currentForm) {
                    const serviceField = currentForm.querySelector('select[name$=".service"]');
                    const dateField = currentForm.querySelector('input[name$=".appointment_date"]');
                    const timeField = currentForm.querySelector('input[name$=".appointment_time"]');

                    if (!(serviceField instanceof HTMLSelectElement) || !(dateField instanceof HTMLInputElement) || !(timeField instanceof HTMLInputElement)) {
                        return;
                    }

                    const slotsEndpoint = String(currentForm.dataset.slotsEndpoint || "").trim();
                    const daysEndpoint = String(currentForm.dataset.daysEndpoint || "").trim();
                    const timezone = String(currentForm.dataset.slotsTimezone || "Europe/Berlin").trim() || "Europe/Berlin";
                    const slotStepMinutes = Math.max(5, parseInt(String(currentForm.dataset.slotStepMinutes || "30"), 10) || 30);
                    const minNoticeHours = Math.max(0, parseInt(String(currentForm.dataset.slotMinNoticeHours || "24"), 10) || 24);
                    const advanceDays = Math.max(1, parseInt(String(currentForm.dataset.slotAdvanceDays || "60"), 10) || 60);

                    const now = new Date();
                    const minDateTime = new Date(now.getTime() + (minNoticeHours * 60 * 60 * 1000));
                    const maxDate = new Date(now.getTime() + (advanceDays * 24 * 60 * 60 * 1000));

                    const dayOptions = parseDayWindows(String(currentForm.dataset.slotWorkWindows || "{}"));

                    const dateSelect = replaceInputWithSelect(dateField, "Datum wählen …");
                    const timeSelect = replaceInputWithSelect(timeField, "Uhrzeit wählen …");
                    if (!(dateSelect instanceof HTMLSelectElement) || !(timeSelect instanceof HTMLSelectElement)) {
                        return;
                    }

                    if (String(serviceField.value || "").trim() === "") {
                        const firstEnabled = Array.from(serviceField.options || []).find(option => {
                            return !option.disabled && String(option.value || "").trim() !== "";
                        });
                        if (firstEnabled) {
                            serviceField.value = String(firstEnabled.value || "").trim();
                        }
                    }

                    const toYmd = date => {
                        const y = date.getFullYear();
                        const m = String(date.getMonth() + 1).padStart(2, "0");
                        const d = String(date.getDate()).padStart(2, "0");
                        return `${y}-${m}-${d}`;
                    };

                    const formatLabel = date => new Intl.DateTimeFormat("de-DE", {
                        weekday: "short",
                        day: "2-digit",
                        month: "2-digit",
                        year: "numeric",
                    }).format(date);

                    const enumerateDays = () => {
                        const out = [];
                        const cursor = new Date(minDateTime.getFullYear(), minDateTime.getMonth(), minDateTime.getDate());
                        const end = new Date(maxDate.getFullYear(), maxDate.getMonth(), maxDate.getDate());
                        while (cursor <= end) {
                            out.push(new Date(cursor.getTime()));
                            cursor.setDate(cursor.getDate() + 1);
                        }
                        return out;
                    };

                    const availableDatesForService = async serviceSlug => {
                        const availabilityByDate = new Map();
                        if (!daysEndpoint || !serviceSlug) {
                            return availabilityByDate;
                        }

                        const months = [];
                        const cursor = new Date(minDateTime.getFullYear(), minDateTime.getMonth(), 1);
                        const end = new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);
                        while (cursor <= end) {
                            months.push(`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, "0")}`);
                            cursor.setMonth(cursor.getMonth() + 1);
                        }

                        for (const month of months) {
                            const url = new URL(daysEndpoint, window.location.origin);
                            url.searchParams.set("service_slug", serviceSlug);
                            url.searchParams.set("month", month);
                            url.searchParams.set("timezone", timezone);

                            try {
                                const res = await fetch(url.toString(), { headers: { Accept: "application/json" } });
                                if (!res.ok) {
                                    continue;
                                }

                                const json = await res.json().catch(() => ({}));
                                const days = Array.isArray(json?.data?.days) ? json.data.days : [];
                                days.forEach(day => {
                                    if (!day || !day.date) {
                                        return;
                                    }

                                    availabilityByDate.set(String(day.date), {
                                        hasAvailability: day.has_availability === true,
                                        fullDayBlocked: day.full_day_blocked === true,
                                        reason: typeof day.unavailable_reason === "string" ? day.unavailable_reason.trim() : "",
                                    });
                                });
                            } catch (_err) {
                            }
                        }

                        return availabilityByDate;
                    };

                    const availableTimesForDate = async (serviceSlug, selectedDate) => {
                        const availability = {
                            availableTimes: new Set(),
                            blockedReasons: new Map(),
                        };
                        if (!slotsEndpoint || !serviceSlug || !selectedDate) {
                            return availability;
                        }

                        const from = `${selectedDate}T00:00:00`;
                        const next = new Date(`${selectedDate}T00:00:00`);
                        next.setDate(next.getDate() + 1);
                        const to = `${toYmd(next)}T00:00:00`;

                        const url = new URL(slotsEndpoint, window.location.origin);
                        url.searchParams.set("service_slug", serviceSlug);
                        url.searchParams.set("from", from);
                        url.searchParams.set("to", to);
                        url.searchParams.set("timezone", timezone);

                        try {
                            const res = await fetch(url.toString(), { headers: { Accept: "application/json" } });
                            if (!res.ok) {
                                return availability;
                            }

                            const json = await res.json().catch(() => ({}));
                            const slots = Array.isArray(json?.data?.slots) ? json.data.slots : [];
                            slots.forEach(slot => {
                                const start = String(slot?.start || "");
                                if (start.length >= 16 && start.slice(0, 10) === selectedDate) {
                                    availability.availableTimes.add(start.slice(11, 16));
                                }
                            });

                            const unavailableSlots = Array.isArray(json?.data?.unavailable_slots)
                                ? json.data.unavailable_slots
                                : [];

                            unavailableSlots.forEach(slot => {
                                const start = String(slot?.start || "");
                                if (start.length < 16 || start.slice(0, 10) !== selectedDate) {
                                    return;
                                }

                                const time = start.slice(11, 16);
                                const reason = String(slot?.reason || "").trim();
                                availability.blockedReasons.set(time, reason !== "" ? reason : "belegt");
                            });
                        } catch (_err) {
                        }

                        return availability;
                    };

                    const candidateTimes = selectedDate => {
                        const date = new Date(`${selectedDate}T00:00:00`);
                        const day = date.getDay() === 0 ? 7 : date.getDay();
                        const windows = Array.isArray(dayOptions[day]) ? dayOptions[day] : [];
                        const out = [];

                        windows.forEach(window => {
                            const start = timeToMinutes(window.start);
                            const end = timeToMinutes(window.end);
                            if (start === null || end === null || end <= start) {
                                return;
                            }

                            for (let minute = start; minute < end; minute += slotStepMinutes) {
                                out.push(`${String(Math.floor(minute / 60)).padStart(2, "0")}:${String(minute % 60).padStart(2, "0")}`);
                            }
                        });

                        return Array.from(new Set(out)).sort();
                    };

                    const renderDateOptions = async () => {
                        const serviceSlug = String(serviceField.value || "").trim();
                        const dateAvailability = await availableDatesForService(serviceSlug);

                        dateSelect.innerHTML = '<option value="">Datum wählen …</option>';
                        let firstAvailable = "";
                        enumerateDays().forEach(day => {
                            const ymd = toYmd(day);
                            const dayMeta = dateAvailability.get(ymd) || null;
                            const available = dayMeta ? dayMeta.hasAvailability : false;
                            const option = document.createElement("option");
                            option.value = ymd;
                            option.disabled = !available;
                            option.textContent = available ? formatLabel(day) : `${formatLabel(day)} (nicht verfügbar)`;
                            if (available && firstAvailable === "") {
                                firstAvailable = ymd;
                            }
                            dateSelect.appendChild(option);
                        });

                        if (firstAvailable !== "") {
                            dateSelect.value = firstAvailable;
                        }

                        await renderTimeOptions();
                    };

                    const renderTimeOptions = async () => {
                        const serviceSlug = String(serviceField.value || "").trim();
                        const selectedDate = String(dateSelect.value || "").trim();
                        const slotAvailability = await availableTimesForDate(serviceSlug, selectedDate);
                        const availableTimes = slotAvailability.availableTimes;

                        timeSelect.innerHTML = '<option value="">Uhrzeit wählen …</option>';
                        candidateTimes(selectedDate).forEach(time => {
                            const option = document.createElement("option");
                            option.value = time;
                            option.disabled = !availableTimes.has(time);
                            option.textContent = availableTimes.has(time)
                                ? time
                                : `${time} (nicht verfügbar)`;
                            timeSelect.appendChild(option);
                        });
                    };

                    serviceField.addEventListener("change", () => {
                        renderDateOptions().catch(() => {});
                    });
                    dateSelect.addEventListener("change", () => {
                        renderTimeOptions().catch(() => {});
                    });

                    renderDateOptions().catch(() => {});

                    function replaceInputWithSelect(input, placeholder) {
                        const select = document.createElement("select");
                        select.name = input.name;
                        select.id = input.id;
                        select.className = input.className;
                        select.required = input.required;
                        if (input.hasAttribute("style")) {
                            select.setAttribute("style", input.getAttribute("style") || "");
                        }
                        if (input.dataset.validators) {
                            select.dataset.validators = input.dataset.validators;
                        }
                        select.innerHTML = `<option value="">${placeholder}</option>`;
                        input.parentNode.replaceChild(select, input);
                        return select;
                    }

                    function parseDayWindows(raw) {
                        try {
                            const data = JSON.parse(raw || "{}");
                            return data && typeof data === "object" ? data : {};
                        } catch (_err) {
                            return {};
                        }
                    }

                    function timeToMinutes(value) {
                        const text = String(value || "").trim();
                        if (!/^\d{2}:\d{2}$/.test(text)) {
                            return null;
                        }
                        const [h, m] = text.split(":").map(v => parseInt(v, 10));
                        if (!Number.isFinite(h) || !Number.isFinite(m) || h < 0 || h > 23 || m < 0 || m > 59) {
                            return null;
                        }
                        return (h * 60) + m;
                    }
                }

                form.addEventListener("submit", async e => {
                    e.preventDefault();

                    const submitButton = form.querySelector("button[type='submit']");
                    const originalLabel = submitButton ? submitButton.textContent : "";

                    normalizeNumericLengthFields();

                    if (!(await validator.validateForm())) {
                        return;
                    }

                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.textContent = "Wird gesendet ...";
                    }

                    const payload = buildPayload(form);

                    try {
                        const response = await fetch(form.action, {
                            method: (form.method || "POST").toUpperCase(),
                            headers: {
                                "Content-Type": "application/json",
                                "Accept": "application/json"
                            },
                            body: JSON.stringify(payload)
                        });

                        const responseJson = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            const errors = responseJson && typeof responseJson === "object"
                                ? responseJson.errors
                                : null;
                            applyServerErrors(errors);
                            alert((responseJson && responseJson.message) || "Die Anfrage konnte nicht gesendet werden.");
                            if (submitButton) {
                                submitButton.disabled = false;
                                submitButton.textContent = originalLabel;
                            }
                            return;
                        }

                        const summary = buildSuccessSummary(form, payload, responseJson && responseJson.data ? responseJson.data : {});
                        sessionStorage.setItem("contactFormSuccessSummary", JSON.stringify(summary));

                        fetch("/form-state", { method: "DELETE" }).catch(() => {});

                        window.location.href = successUrl;
                    } catch (_err) {
                        alert("Verbindungsfehler. Bitte versuchen Sie es erneut.");
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.textContent = originalLabel;
                        }
                    }
                });

                function buildPayload(currentForm) {
                    const payload = {};
                    const formData = new FormData(currentForm);

                    for (const [key, value] of formData.entries()) {
                        if (key.startsWith("consent_check_")) {
                            continue;
                        }

                        payload[key] = value;
                    }

                    const consentsPayload = [];
                    currentForm.querySelectorAll("input[data-consent-key]").forEach(input => {
                        if (!(input instanceof HTMLInputElement) || !input.checked) {
                            return;
                        }

                        const consentKey = String(input.dataset.consentKey || "").trim();
                        const labelText = String(input.closest("label")?.querySelector("span")?.textContent || "").trim();

                        if (consentKey === "") {
                            return;
                        }

                        consentsPayload.push({
                            consent_key: consentKey,
                            accepted: true,
                            consent_text_snapshot: labelText,
                        });
                    });

                    payload.consents = consentsPayload;
                    payload.consent_version = "1.0";

                    return payload;
                }

                function applyServerErrors(errors) {
                    if (!errors || typeof errors !== "object") {
                        return;
                    }

                    Object.entries(errors).forEach(([fieldName, fieldErrors]) => {
                        const messages = Array.isArray(fieldErrors)
                            ? fieldErrors.map(item => mapErrorToMessage(item)).filter(Boolean)
                            : [];

                        const field = resolveFormField(fieldName);
                        if (!field || messages.length === 0) {
                            return;
                        }

                        validator.renderErrors(field, messages);
                    });
                }

                function resolveFormField(fieldName) {
                    const normalized = String(fieldName || "").trim();
                    if (normalized === "") {
                        return null;
                    }

                    const direct = form.querySelector(`[name="${selectorEscape(normalized)}"]`);
                    if (direct) {
                        return direct;
                    }

                    const underscored = normalized.replace(/\./g, "_");
                    const fallback = form.querySelector(`[name="${selectorEscape(underscored)}"]`);
                    return fallback || null;
                }

                function mapErrorToMessage(code) {
                    const value = String(code || "").trim();
                    if (value === "") {
                        return "Ungültige Eingabe.";
                    }

                    const map = {
                        required: "Dieses Feld ist erforderlich.",
                        email: "Bitte geben Sie eine gültige E-Mail-Adresse ein.",
                        invalid_option: "Bitte wählen Sie einen gültigen Wert aus.",
                        appointments_disabled: "Terminbuchung ist derzeit deaktiviert.",
                        tickets_disabled: "Tickets sind derzeit deaktiviert.",
                        invalid_service: "Bitte wählen Sie ein gültiges Angebot aus.",
                        invalid_datetime: "Bitte geben Sie ein gültiges Datum und eine gültige Uhrzeit an.",
                        min_notice: "Der gewünschte Termin liegt zu nah in der Zukunft. Bitte wählen Sie einen späteren Termin.",
                        max_advance: "Der gewünschte Termin liegt zu weit in der Zukunft. Bitte wählen Sie einen früheren Termin.",
                        invalid_slot_interval: "Bitte wählen Sie eine Uhrzeit im vorgegebenen Zeitraster.",
                        outside_working_hours: "Die gewählte Uhrzeit liegt außerhalb der verfügbaren Zeiten.",
                        termin_not_available: "Dieser Termin ist bereits belegt oder gesperrt. Bitte wählen Sie einen anderen Slot.",
                        invalid_client_number: "Bitte geben Sie eine gültige Kundennummer an.",
                    };

                    return map[value] || value;
                }

                function buildSuccessSummary(currentForm, payload, responseData) {
                    const items = [];
                    const ignoredKeys = new Set(["company", "consents", "consent_version"]);
                    const submittedFieldNames = collectSubmittedFieldNames(currentForm);

                    Object.entries(payload).forEach(([key, rawValue]) => {
                        if (!submittedFieldNames.has(key)) {
                            return;
                        }

                        if (ignoredKeys.has(key)) {
                            return;
                        }

                        const value = String(rawValue ?? "").trim();
                        if (value === "") {
                            return;
                        }

                        const label = resolveFieldLabel(currentForm, key);
                        const displayValue = resolveDisplayValue(currentForm, key, String(rawValue ?? ""));
                        if (String(displayValue).trim() === "") {
                            return;
                        }

                        items.push({
                            key,
                            label,
                            value: displayValue,
                        });
                    });

                    const consentItems = Array.isArray(payload.consents) ? payload.consents : [];
                    if (consentItems.length > 0) {
                        const consentLabels = consentItems
                            .map(item => String(item && item.consent_text_snapshot ? item.consent_text_snapshot : "").trim())
                            .filter(Boolean);

                        if (consentLabels.length > 0) {
                            items.push({
                                key: "consents",
                                label: "Bestätigte Einwilligungen",
                                value: consentLabels.join("\n"),
                            });
                        }
                    }

                    const serviceTypeValue = String(payload.service_type || "").trim();
                    const serviceTypeLabel = resolveDisplayValue(currentForm, "service_type", serviceTypeValue) || serviceTypeValue;
                    const reference = resolveReference(responseData);

                    return {
                        submitted_at: new Date().toISOString(),
                        service_type: serviceTypeValue,
                        service_type_label: serviceTypeLabel,
                        reference,
                        items,
                    };
                }

                function collectSubmittedFieldNames(currentForm) {
                    const names = new Set();

                    currentForm.querySelectorAll("input, select, textarea").forEach(field => {
                        if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) {
                            return;
                        }

                        if (!field.name || field.disabled) {
                            return;
                        }

                        if (field.closest(".hidden") || field.closest(".sp-honeypot")) {
                            return;
                        }

                        names.add(field.name);
                    });

                    return names;
                }

                function resolveReference(responseData) {
                    if (!responseData || typeof responseData !== "object") {
                        return "";
                    }

                    const requestType = String(responseData.request_type || "").trim();
                    const appointmentId = Number(responseData.appointment_id || 0);
                    const ticketId = Number(responseData.ticket_id || 0);

                    if (requestType === "contact" && appointmentId > 0) {
                        return `Termin #${appointmentId}`;
                    }

                    if ((requestType === "ticket" || requestType === "service") && ticketId > 0) {
                        return `Ticket #${ticketId}`;
                    }

                    return "";
                }

                function resolveFieldLabel(currentForm, fieldName) {
                    const field = currentForm.querySelector(`[name="${selectorEscape(fieldName)}"]`);
                    if (!field) {
                        return fieldName;
                    }

                    const inputId = field.getAttribute("id");
                    if (!inputId) {
                        return fieldName;
                    }

                    const label = currentForm.querySelector(`label[for="${selectorEscape(inputId)}"]`);
                    if (!label) {
                        return fieldName;
                    }

                    return String(label.textContent || fieldName).replace(/\*/g, "").trim();
                }

                function resolveDisplayValue(currentForm, fieldName, rawValue) {
                    const field = currentForm.querySelector(`[name="${selectorEscape(fieldName)}"]`);
                    if (field && field.tagName === "SELECT") {
                        const select = /** @type {HTMLSelectElement} */ (field);
                        const option = select.selectedOptions && select.selectedOptions[0]
                            ? select.selectedOptions[0]
                            : null;

                        if (option && String(option.value).trim() !== "") {
                            return String(option.textContent || rawValue).trim();
                        }
                    }

                    return String(rawValue || "");
                }

                function selectorEscape(value) {
                    const text = String(value || "");
                    if (window.CSS && typeof window.CSS.escape === "function") {
                        return window.CSS.escape(text);
                    }

                    return text.replace(/([\"\\])/g, "\\$1");
                }

                /**
                 * Speichert ein Feld in der Session.
                 */
                async function saveField(name, value) {

                    try {

                        await fetch("/form-state", {

                            method: "POST",

                            headers: {
                                "Content-Type": "application/json"
                            },

                            body: JSON.stringify({
                                field: name,
                                value: value
                            })

                        });

                    } catch (e) {

                        console.error("Fehler beim Speichern:", e);

                    }

                }

                /**
                 * Füllt numerische Felder mit führenden Nullen auf,
                 * wenn eine Längenregel (length/minLength/maxLength) vorhanden ist.
                 */
                function padNumericValueByRule(input) {
                    if (!(input instanceof HTMLInputElement)) {
                        return;
                    }

                    if (input.disabled || input.closest(".hidden")) {
                        return;
                    }

                    const raw = String(input.value || "").trim();
                    if (raw === "" || !/^\d+$/.test(raw)) {
                        return;
                    }

                    const rules = parseValidators(input.dataset.validators);
                    const targetLength = resolveNumericTargetLength(rules);

                    if (targetLength <= 0 || raw.length >= targetLength) {
                        return;
                    }

                    input.value = raw.padStart(targetLength, "0");
                }

                function normalizeNumericLengthFields() {
                    form.querySelectorAll("input").forEach(input => {
                        padNumericValueByRule(input);
                    });
                }

                function parseValidators(rawValidators) {
                    try {
                        const parsed = JSON.parse(rawValidators || "[]");
                        return Array.isArray(parsed) ? parsed : [];
                    } catch (_err) {
                        return [];
                    }
                }

                function resolveNumericTargetLength(rules) {
                    if (!Array.isArray(rules)) {
                        return 0;
                    }

                    let target = 0;

                    for (const rule of rules) {
                        if (!rule || typeof rule !== "object") {
                            continue;
                        }

                        const name = String(rule.rule || "").toLowerCase();
                        const value = Number(rule.value || 0);

                        if (!Number.isFinite(value) || value <= 0) {
                            continue;
                        }

                        if (name === "length") {
                            return Math.trunc(value);
                        }

                        if ((name === "minlength" || name === "maxlength") && Math.trunc(value) > target) {
                            target = Math.trunc(value);
                        }
                    }

                    return target;
                }

                /**
                 * Blendet einen kompletten Ast inkl. aller Unteräste aus.
                 */
                function hideBranch(container) {

                    container.classList.add("hidden");

                    container.querySelectorAll("input, select, textarea").forEach(input => {

                        input.disabled = true;

                    });

                }

                /**
                 * Aktualisiert abhängige Felder.
                 */
                function updateField(select) {

                    const fieldName = select.name;
                    const value = select.value;

                    document
                        .querySelectorAll(`.option-container[data-parent="${CSS.escape(fieldName)}"]`)
                        .forEach(container => {

                            if (container.dataset.value === value) {

                                container.classList.remove("hidden");

                                container
                                    .querySelectorAll(":scope input, :scope select, :scope textarea")
                                    .forEach(input => {

                                        input.disabled = false;

                                    });

                            } else {

                                hideBranch(container);

                            }

                        });

                }

                /**
                 * URL aktualisieren
                 */
                function updateUrl() {

                    const url = new URL(window.location);

                    document.querySelectorAll("select").forEach(select => {
                        url.searchParams.delete(select.name);
                    });

                    document.querySelectorAll("select").forEach(select => {

                        if (select.disabled || !select.value) {
                            return;
                        }

                        url.searchParams.set(select.name, select.value);

                    });

                    history.replaceState({}, "", url);

                }

                /**
                 * URL wiederherstellen
                 */
                function restoreFromUrl() {

                    const url = new URL(window.location);

                    let changed;

                    do {

                        changed = false;

                        document.querySelectorAll("select").forEach(select => {

                            if (select.disabled) {
                                return;
                            }

                            const value = url.searchParams.get(select.name);

                            if (!value) {
                                return;
                            }

                            if (select.value !== value) {

                                select.value = value;
                                updateField(select);

                                changed = true;

                            }

                        });

                    } while (changed);

                }

                /**
                 * Selects
                 */
                document.querySelectorAll("select").forEach(select => {

                    updateField(select);

                    select.addEventListener("change", async () => {

                        await validator.validateField(select);

                        updateField(select);
                        updateUrl();
                        saveField(select.name, select.value);

                    });

                });

                /**
                 * Textfelder
                 */
                document.querySelectorAll("input, textarea").forEach(input => {

                    input.addEventListener("blur", async () => {

                        padNumericValueByRule(input);

                        await validator.validateField(input);

                        saveField(input.name, input.value);

                    });

                });

                restoreFromUrl();
                updateUrl();

            });
        </script>
        </form>

        <div id="booking-success" class="sp-booking-success" hidden>
            <div class="sp-success-icon">✓</div>
            <h3>Ihre Anfrage ist eingegangen!</h3>
            <p>Wir melden uns innerhalb von 24 Stunden bei Ihnen.</p>
        </div>
    </div>
    </div>
</section>
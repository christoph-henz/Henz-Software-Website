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
                        class="peer w-full rounded-xl border border-cyan-400/10 bg-[#0b1119] text-slate-100 px-5 pt-7 pb-3 focus:outline-none focus:border-cyan-400 transition-colors"
                        type="<?= htmlspecialchars($type) ?>" id="<?= htmlspecialchars($fieldId) ?>"
                        name="<?= htmlspecialchars($fieldId) ?>" placeholder="<?= $placeholderAttr ?>"
                        value="<?= htmlspecialchars((string) $value) ?>" data-validators='<?= htmlspecialchars(
                               json_encode($validators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                               ENT_QUOTES,
                               'UTF-8'
                           ) ?>'>
                    <div class="validation-errors mt-2"></div>
                    <label
                        class="absolute left-5 top-5 text-slate-500 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-focus:text-cyan-400 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"
                        for="<?= htmlspecialchars($fieldId) ?>">
                        <?= htmlspecialchars($label) ?>
                    </label>

                    <?php
                    break;
                case 'time':
                    ?>

                    <input
                        class="peer w-full rounded-xl border border-cyan-400/10 bg-[#0b1119] text-slate-100 px-5 pt-7 pb-3 focus:outline-none focus:border-cyan-400 transition-colors"
                        type="<?= htmlspecialchars($type) ?>" id="<?= htmlspecialchars($fieldId) ?>"
                        name="<?= htmlspecialchars($fieldId) ?>" placeholder="<?= $placeholderAttr ?>"
                        value="<?= htmlspecialchars((string) $value) ?>" data-validators='<?= htmlspecialchars(
                               json_encode($validators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                               ENT_QUOTES,
                               'UTF-8'
                           ) ?>'>
                    <div class="validation-errors mt-2"></div>
                    <label
                        class="absolute left-5 top-5 text-slate-500 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-focus:text-cyan-400 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"
                        for="<?= htmlspecialchars($fieldId) ?>">
                        <?= htmlspecialchars($label) ?>
                    </label>

                    <?php
                    break;
                case 'file':
                    ?>

                    <input
                        class="peer w-full rounded-xl border border-cyan-400/10 bg-[#0b1119] text-slate-100 px-5 pt-7 pb-3 focus:outline-none focus:border-cyan-400 transition-colors"
                        type="<?= htmlspecialchars($type) ?>" id="<?= htmlspecialchars($fieldId) ?>"
                        name="<?= htmlspecialchars($fieldId) ?>" placeholder="<?= $placeholderAttr ?>"
                        value="<?= htmlspecialchars((string) $value) ?>" data-validators='<?= htmlspecialchars(
                               json_encode($validators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                               ENT_QUOTES,
                               'UTF-8'
                           ) ?>'>
                    <div class="validation-errors mt-2"></div>
                    <label
                        class="absolute left-5 top-5 text-slate-500 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-focus:text-cyan-400 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"
                        for="<?= htmlspecialchars($fieldId) ?>">
                        <?= htmlspecialchars($label) ?>
                    </label>

                    <?php
                    break;

                case 'textarea':
                    ?>

                    <textarea
                        class="peer min-h-[180px] w-full rounded-xl border border-cyan-400/10 bg-[#0b1119] text-slate-100 px-5 pt-7 pb-3 resize-y focus:outline-none focus:border-cyan-400 transition-colors"
                        id="<?= htmlspecialchars($fieldId) ?>" name="<?= htmlspecialchars($fieldId) ?>"
                        placeholder="<?= $placeholderAttr ?>" data-validators='<?= htmlspecialchars(
                              json_encode($validators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                              ENT_QUOTES,
                              'UTF-8'
                          ) ?>'><?= htmlspecialchars((string) $value) ?></textarea>
                    <div class="validation-errors mt-2"></div>
                    <label
                        class="absolute left-5 top-5 text-slate-500 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-focus:text-cyan-400 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"
                        for="<?= htmlspecialchars($fieldId) ?>">
                        <?= htmlspecialchars($label) ?>
                    </label>

                    <?php
                    break;

                case 'select':
                case 'choice':
                    ?>

                    <select
                        class="peer w-full rounded-xl border border-cyan-400/10 bg-[#0b1119] text-slate-100 px-5 pt-7 pb-3 appearance-none focus:outline-none focus:border-cyan-400 transition-colors"
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
                        class="absolute left-5 top-5 text-slate-500 transition-all pointer-events-none peer-placeholder-shown:text-base peer-placeholder-shown:top-5 peer-focus:text-xs peer-focus:-translate-y-3 peer-focus:text-cyan-400 peer-not-placeholder-shown:text-xs peer-not-placeholder-shown:-translate-y-3"><?= htmlspecialchars($label) ?></label>

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
        font-bold leading-tight text-slate-100
        font-mono"><?= htmlspecialchars((string) ($hero['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="sp-intro mt-8 text-lg leading-relaxed text-slate-400">
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
        <aside class="sp-booking-info rounded-[28px] border border-cyan-400/10 bg-[#060a0f] p-8 space-y-8">
            <h2 class="font-mono text-2xl font-bold text-slate-100">
                <?= htmlspecialchars((string) ($process['title'] ?? 'So geht es weiter'), ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            <ol class="sp-booking-steps space-y-4">
                <?php foreach ($processSteps as $step): ?>
                    <li><?= htmlspecialchars((string) $step, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ol>
            <div class="sp-booking-note text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-200">Kostenlos & unverbindlich:</strong> Das Kennenlerngespräch ist kostenfrei
                und verpflichtet Sie
                zu nichts.
            </div>
        </aside>

        <!-- Form -->

        <div
            class="sp-booking-form-wrap rounded-[32px] border border-cyan-400/10 bg-[#060a0f] shadow-2xl shadow-cyan-500/5 overflow-hidden p-8 lg:p-10">
            <form class="gap-6" id="booking-form" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>"
                method="<?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8'); ?>"
                data-success-url="<?= htmlspecialchars($successRedirectUrl, ENT_QUOTES, 'UTF-8'); ?>"
                data-slots-endpoint="<?= htmlspecialchars($slotPickerEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
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
                                class="mt-1 w-5 h-5 shrink-0 rounded border-cyan-400/30 bg-[#0b1119] text-cyan-400 focus:ring-cyan-400"
                                type="checkbox"
                                name="consent_check_<?= htmlspecialchars($consentKey, ENT_QUOTES, 'UTF-8'); ?>"
                                data-consent-key="<?= htmlspecialchars($consentKey, ENT_QUOTES, 'UTF-8'); ?>" required />
                            <span
                                class="text-slate-300 text-sm leading-relaxed"><?= htmlspecialchars($consentLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <button type="submit"
                    class="sp-submit-btn md:col-span-2 mt-2 group inline-flex items-center justify-center gap-3 w-full rounded-xl px-8 py-5 font-semibold font-mono bg-cyan-400 text-[#060a0f] transition-all duration-200 hover:translate-y-[-2px] hover:shadow-xl hover:shadow-cyan-400/30">
                    <?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8'); ?>
                </button>

                <?php if ($legalHint !== ''): ?>
                    <p class="sp-legal-hint md:col-span-2 text-xs text-slate-500 leading-relaxed">


                        <?= $legalHint; /* intentionally not escaped – contains <a> tag */ ?>
                    </p>
                <?php endif; ?>

        </div>

        <script type="module">
            import { FormValidator } from "/ui/_assets/js/form-validators.js";
            document.addEventListener("DOMContentLoaded", () => {
                const form = document.querySelector("#booking-form");
                const validator = new FormValidator(form);
                const successUrl = form.dataset.successUrl || "/kontakt/erfolg";

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
                        invalid_service: "Bitte wählen Sie ein gültiges Angebot aus.",
                        invalid_datetime: "Bitte geben Sie ein gültiges Datum und eine gültige Uhrzeit an.",
                        invalid_client_number: "Bitte geben Sie eine gültige Kundennummer an.",
                    };

                    return map[value] || value;
                }

                function buildSuccessSummary(currentForm, payload, responseData) {
                    const items = [];
                    const ignoredKeys = new Set(["company", "consents", "consent_version"]);

                    Object.entries(payload).forEach(([key, rawValue]) => {
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
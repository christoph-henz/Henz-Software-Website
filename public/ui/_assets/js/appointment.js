/**
 * booking.js – Handles booking form submission via fetch API
 */
(function () {
  'use strict';

  const form = document.getElementById('booking-form');
  const successEl = document.getElementById('booking-success');
  const serviceSelect = form ? form.querySelector('select[name="service"]') : null;
  const packageSelect = form ? form.querySelector('select[name="package_slug"]') : null;
  let lockedServiceSlug = form ? String(form.getAttribute('data-locked-service-slug') || '').trim() : '';
  let serviceLockChangeBound = false;

  window.initBookingAvailabilityPicker = initBookingAvailabilityPicker;

  if (!form) return;

  bindPackageServiceLock();
  initBookingAvailabilityPicker(form);

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearAllErrors(form);

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalLabel = submitBtn ? submitBtn.textContent : '';

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Wird gesendet …';
    }

    const clientErrors = validateClientSide(form);
    if (Object.keys(clientErrors).length > 0) {
      showValidationErrors(form, clientErrors, 'Bitte korrigieren Sie die markierten Felder.');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
      }
      return;
    }

    try {
      const payload = buildRequestPayload(form);
      const response = await fetch(form.action, {
        method: form.method.toUpperCase() || 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
      });

      if (response.ok) {
        form.hidden = true;
        if (successEl) successEl.hidden = false;
      } else {
        const json = await response.json().catch(() => ({}));
        const requestId = response.headers.get('X-Request-Id') || String(json.request_id || '');

        if ((response.status === 422 || response.status === 409 || response.status === 429) && json.errors && typeof json.errors === 'object') {
          showValidationErrors(form, json.errors, json.message || 'Bitte korrigieren Sie die markierten Felder.');
        } else {
          const baseMessage = json.message || 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.';
          const message = shouldShowSupportId(response.status) && requestId !== ''
            ? `${baseMessage} Support-ID: ${requestId}`
            : baseMessage;
          showBannerError(form, message);
        }
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
        }
      }
    } catch (_err) {
      showBannerError(form, 'Verbindungsfehler. Bitte prüfen Sie Ihre Internetverbindung.');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
      }
    }
  });

  /**
   * Show support ID only for severe errors, not for user-correctable validation issues.
   * @param {number} status
   * @returns {boolean}
   */
  function shouldShowSupportId(status) {
    return status >= 500 || status === 403 || status === 404;
  }

  function initBookingAvailabilityPicker(targetForm) {
    initTerminSlotPicker(targetForm);
    initContactAvailabilityPicker(targetForm);
  }

  /**
   * Build JSON payload from form fields and consents
   * @param {HTMLFormElement} form
   * @returns {Object}
   */
  function buildRequestPayload(form) {
    const formData = new FormData(form);
    const payload = {};
    const consents = [];

    for (const [key, value] of formData.entries()) {
      if (key.startsWith('consent_check_')) continue;
      payload[key] = value;
    }

    // consent_text_snapshot = exact visible text the user read and confirmed
    const consentCheckboxes = form.querySelectorAll('input[data-consent-key]');
    for (const checkbox of consentCheckboxes) {
      if (checkbox.checked) {
        const consentKey = checkbox.getAttribute('data-consent-key');
        const visibleText = checkbox.closest('label')?.querySelector('span')?.textContent?.trim() ?? '';
        consents.push({
          consent_key: consentKey,
          accepted: true,
          consent_text_snapshot: visibleText,
        });
      }
    }

    payload.consents = consents;
    payload.consent_version = '1.0';

    return payload;
  }

  function enforceLockedService() {
    if (!serviceSelect) {
      return;
    }

    for (const option of serviceSelect.options) {
      if (!option.value) {
        continue;
      }
      if (lockedServiceSlug === '') {
        option.disabled = false;
      } else {
        option.disabled = option.value !== lockedServiceSlug;
        if (option.value === lockedServiceSlug) {
          option.selected = true;
        }
      }
    }

    if (lockedServiceSlug !== '') {
      serviceSelect.value = lockedServiceSlug;
    }

    if (!serviceLockChangeBound) {
      serviceSelect.addEventListener('change', () => {
        if (lockedServiceSlug !== '' && serviceSelect.value !== lockedServiceSlug) {
          serviceSelect.value = lockedServiceSlug;
        }
      });
      serviceLockChangeBound = true;
    }
  }

  function bindPackageServiceLock() {
    enforceLockedService();

    if (!packageSelect) {
      return;
    }

    const applyPackageLock = () => {
      const selectedOption = packageSelect.selectedOptions && packageSelect.selectedOptions.length > 0
        ? packageSelect.selectedOptions[0]
        : null;
      const packageServiceSlug = selectedOption ? String(selectedOption.getAttribute('data-service-slug') || '').trim() : '';
      lockedServiceSlug = packageServiceSlug;
      enforceLockedService();
    };

    packageSelect.addEventListener('change', applyPackageLock);
    applyPackageLock();
  }

  /**
   * Map server error keys to form field names or consent keys.
   * Server returns keys like: "firstname", "email", "consents.privacy_policy.accepted"
   */
  const FIELD_LABELS = {
    firstname: 'Vorname',
    lastname: 'Nachname',
    dob: 'Geburtsdatum',
    email: 'E-Mail-Adresse',
    phone: 'Telefonnummer',
    service: 'Gesprächsform',
    package: 'Paket',
    termin: 'Wunschtermin',
    request: 'Anfrage',
    consents: 'Einwilligungen',
    consent_version: 'Consent-Version',
  };

  const ERROR_MESSAGES = {
    required: 'Dieses Feld ist erforderlich.',
    required_true: 'Diese Einwilligung muss bestätigt werden.',
    email: 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
    invalid_service: 'Bitte wählen Sie eine gültige Gesprächsform aus.',
    inactive_service: 'Diese Gesprächsform ist aktuell nicht verfügbar. Bitte wählen Sie eine andere.',
    invalid_package: 'Bitte wählen Sie ein gültiges Paket aus.',
    inactive_package: 'Dieses Paket ist aktuell nicht verfügbar.',
    package_service_mismatch: 'Dieses Paket passt nicht zur gewählten Gesprächsform.',
    invalid_datetime: 'Bitte geben Sie ein gültiges Datum an.',
    invalid_date: 'Bitte geben Sie ein gültiges Datum an.',
    in_past: 'Das Datum darf nicht in der Vergangenheit liegen.',
    min_notice: 'Der Wunschtermin liegt zu nah in der Zukunft. Bitte wählen Sie einen späteren Termin.',
    max_advance: 'Der Wunschtermin liegt zu weit in der Zukunft. Bitte wählen Sie einen früheren Termin.',
    invalid_slot_interval: 'Bitte wählen Sie eine Uhrzeit im vorgesehenen Zeitraster.',
    termin_not_available: 'Dieser Termin ist nicht mehr verfügbar. Bitte wählen Sie einen anderen Slot.',
    text_mismatch: 'Der Einwilligungstext stimmt nicht überein. Bitte laden Sie die Seite neu.',
    unsupported_version: 'Ungültige Consent-Version. Bitte laden Sie die Seite neu.',
    invalid_item: 'Ungültiger Eintrag.',
    duplicate_request: 'Diese Anfrage wurde bereits gestellt.',
    bot_detected: 'Die Anfrage konnte nicht verarbeitet werden. Bitte versuchen Sie es erneut.',
    too_fast_submit: 'Das Formular wurde zu schnell abgesendet. Bitte versuchen Sie es erneut.',
    rate_limited: 'Zu viele Anfragen. Bitte warten Sie kurz und versuchen Sie es erneut.',
  };

  const FIELD_ERRORS = {
    service: {
      required: 'Bitte wählen Sie eine Gesprächsform aus.',
      invalid_service: 'Bitte wählen Sie eine gültige Gesprächsform aus.',
      inactive_service: 'Diese Gesprächsform ist aktuell nicht verfügbar. Bitte wählen Sie eine andere.',
    },
    package: {
      invalid_package: 'Bitte wählen Sie ein gültiges Paket aus.',
      inactive_package: 'Dieses Paket ist aktuell nicht verfügbar.',
      package_service_mismatch: 'Dieses Paket passt nicht zur gewählten Gesprächsform.',
    },
    firstname: { required: 'Bitte geben Sie Ihren Vornamen ein.' },
    lastname: { required: 'Bitte geben Sie Ihren Nachnamen ein.' },
    dob: {
      required: 'Bitte geben Sie Ihr Geburtsdatum ein.',
      invalid_date: 'Bitte geben Sie ein gültiges Geburtsdatum ein.',
    },
    phone: { required: 'Bitte geben Sie Ihre Telefonnummer ein.' },
    email: { required: 'Bitte geben Sie Ihre E-Mail-Adresse ein.', email: 'Bitte geben Sie eine gültige E-Mail-Adresse ein.' },
    termin: {
      required: 'Bitte wählen Sie einen Wunschtermin aus.',
      invalid_datetime: 'Bitte geben Sie ein gültiges Datum an.',
      in_past: 'Das Datum darf nicht in der Vergangenheit liegen.',
      min_notice: 'Der Wunschtermin liegt zu nah in der Zukunft. Bitte wählen Sie einen späteren Termin.',
      max_advance: 'Der Wunschtermin liegt zu weit in der Zukunft. Bitte wählen Sie einen früheren Termin.',
      invalid_slot_interval: 'Bitte wählen Sie eine Uhrzeit im vorgesehenen Zeitraster.',
      termin_not_available: 'Dieser Termin ist nicht mehr verfügbar. Bitte wählen Sie einen anderen Slot.',
    },
    consents: { required: 'Bitte bestätigen Sie alle erforderlichen Einwilligungen.' },
    request: {
      bot_detected: 'Die Anfrage konnte nicht verarbeitet werden. Bitte versuchen Sie es erneut.',
      too_fast_submit: 'Das Formular wurde zu schnell abgesendet. Bitte warten Sie kurz und senden Sie erneut.',
      rate_limited: 'Zu viele Anfragen. Bitte warten Sie kurz und senden Sie erneut.',
    },
  };

  /**
   * Initialize desired slot picker with calendar date + adjacent slot list.
   * @param {HTMLFormElement} form
   */
  function initTerminSlotPicker(form) {
    const dateInput = form.querySelector('input[name="termin_date"]');
    const slotSelect = form.querySelector('select[name="termin_slot"]');
    const terminHidden = form.querySelector('input[name="termin"]');
    if (!(dateInput instanceof HTMLInputElement) || !(slotSelect instanceof HTMLSelectElement) || !(terminHidden instanceof HTMLInputElement)) {
      return;
    }

    const slotsEndpoint = String(form.getAttribute('data-slots-endpoint') || '').trim();
    const timezone = String(form.getAttribute('data-slots-timezone') || 'Europe/Berlin').trim() || 'Europe/Berlin';
    const slotStepMinutes = readSlotStepMinutes(form);
    const minNoticeHours = Number.parseInt(String(form.getAttribute('data-slot-min-notice-hours') || '24'), 10) || 24;
    const advanceDays = Number.parseInt(String(form.getAttribute('data-slot-advance-days') || '60'), 10) || 60;
    const workWindowsByDay = parseWorkWindowsByDay(form.getAttribute('data-slot-work-windows'));

    const now = new Date();
    const minDateTime = new Date(now.getTime() + (Math.max(0, minNoticeHours) * 60 * 60 * 1000));
    const maxDate = new Date(now.getTime() + (Math.max(1, advanceDays) * 24 * 60 * 60 * 1000));

    dateInput.min = toYmd(minDateTime);
    dateInput.max = toYmd(maxDate);

    if (serviceSelect && String(serviceSelect.value || '').trim() === '') {
      const firstEnabledService = Array.from(serviceSelect.options).find(option => {
        return !option.disabled && String(option.value || '').trim() !== '';
      });
      if (firstEnabledService) {
        serviceSelect.value = String(firstEnabledService.value || '').trim();
      }
    }

    if (String(dateInput.value || '').trim() === '') {
      const firstDate = findFirstDateWithWindow(minDateTime, maxDate, workWindowsByDay);
      if (firstDate !== '') {
        dateInput.value = firstDate;
      }
    }

    const updateHiddenValue = () => {
      const dateValue = String(dateInput.value || '').trim();
      const slotValue = String(slotSelect.value || '').trim();
      terminHidden.value = dateValue !== '' && slotValue !== '' ? `${dateValue}T${slotValue}` : '';
    };

    const renderSlotOptions = async () => {
      const serviceSlug = String(serviceSelect?.value || '').trim();
      const selectedDate = String(dateInput.value || '').trim();

      renderSlotPlaceholder(slotSelect, 'Wunschtermin wird geladen …');
      updateHiddenValue();

      if (serviceSlug === '') {
        renderSlotPlaceholder(slotSelect, 'Bitte Gesprächsform wählen …');
        return;
      }

      if (selectedDate === '') {
        renderSlotPlaceholder(slotSelect, 'Bitte Datum wählen …');
        return;
      }

      const candidateTimes = buildCandidateTimesForDate(selectedDate, slotStepMinutes, workWindowsByDay);
      if (candidateTimes.length === 0) {
        renderSlotPlaceholder(slotSelect, 'An diesem Tag gibt es keine Öffnungszeit.');
        return;
      }

      const filteredCandidateTimes = candidateTimes.filter(time => {
        const candidateDate = new Date(`${selectedDate}T${time}:00`);
        if (Number.isNaN(candidateDate.getTime())) {
          return false;
        }
        return candidateDate.getTime() >= minDateTime.getTime();
      });

      if (filteredCandidateTimes.length === 0) {
        renderSlotPlaceholder(slotSelect, 'Für dieses Datum sind keine Uhrzeiten mehr buchbar.');
        return;
      }

      const slotAvailability = await fetchAvailableTimes(slotsEndpoint, serviceSlug, selectedDate, timezone);
      const availableTimes = slotAvailability.availableTimes;
      const previousSelection = String(slotSelect.value || '').trim();

      slotSelect.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Uhrzeit wählen …';
      slotSelect.appendChild(placeholder);

      let hasAvailableOption = false;
      for (const time of filteredCandidateTimes) {
        const option = document.createElement('option');
        option.value = time;
        const isAvailable = availableTimes.has(time);
        option.disabled = !isAvailable;
        option.textContent = isAvailable ? time : `${time} (nicht verfügbar)`;
        if (isAvailable) {
          hasAvailableOption = true;
          if (previousSelection !== '' && previousSelection === time) {
            option.selected = true;
          }
        }
        slotSelect.appendChild(option);
      }

      slotSelect.disabled = false;
      updateHiddenValue();
    };

    dateInput.addEventListener('change', () => {
      renderSlotOptions().catch(() => {
        renderSlotPlaceholder(slotSelect, 'Slots konnten nicht geladen werden. Bitte erneut versuchen.');
      });
    });

    slotSelect.addEventListener('change', updateHiddenValue);
    serviceSelect?.addEventListener('change', () => {
      renderSlotOptions().catch(() => {
        renderSlotPlaceholder(slotSelect, 'Slots konnten nicht geladen werden. Bitte erneut versuchen.');
      });
    });
    packageSelect?.addEventListener('change', () => {
      renderSlotOptions().catch(() => {
        renderSlotPlaceholder(slotSelect, 'Slots konnten nicht geladen werden. Bitte erneut versuchen.');
      });
    });

    renderSlotOptions().catch(() => {
      renderSlotPlaceholder(slotSelect, 'Slots konnten nicht geladen werden. Bitte erneut versuchen.');
    });
  }

  function initContactAvailabilityPicker(form) {
    const contactServiceSelect = form.querySelector('select[name$=".service"]');
    const dateField = form.querySelector('[name$=".appointment_date"]');
    const timeField = form.querySelector('[name$=".appointment_time"]');

    if (!(contactServiceSelect instanceof HTMLSelectElement) || !dateField || !timeField) {
      return;
    }

    const dateSelect = ensureSelectField(dateField, 'Datum wählen …');
    const timeSelect = ensureSelectField(timeField, 'Uhrzeit wählen …');
    if (!(dateSelect instanceof HTMLSelectElement) || !(timeSelect instanceof HTMLSelectElement)) {
      return;
    }

    const slotsEndpoint = String(form.getAttribute('data-slots-endpoint') || '').trim();
    const daysEndpointRaw = String(form.getAttribute('data-days-endpoint') || '').trim();
    const daysEndpoint = daysEndpointRaw !== ''
      ? daysEndpointRaw
      : (slotsEndpoint !== '' ? slotsEndpoint.replace(/\/slots(?:\?.*)?$/u, '/days') : '');
    const timezone = String(form.getAttribute('data-slots-timezone') || 'Europe/Berlin').trim() || 'Europe/Berlin';
    const slotStepMinutes = readSlotStepMinutes(form);
    const minNoticeHours = Number.parseInt(String(form.getAttribute('data-slot-min-notice-hours') || '24'), 10) || 24;
    const advanceDays = Number.parseInt(String(form.getAttribute('data-slot-advance-days') || '60'), 10) || 60;
    const workWindowsByDay = parseWorkWindowsByDay(form.getAttribute('data-slot-work-windows'));

    const now = new Date();
    const minDateTime = new Date(now.getTime() + (Math.max(0, minNoticeHours) * 60 * 60 * 1000));
    const maxDate = new Date(now.getTime() + (Math.max(1, advanceDays) * 24 * 60 * 60 * 1000));

    const renderDateOptions = async () => {
      const serviceSlug = String(contactServiceSelect.value || '').trim();
      dateSelect.innerHTML = '';

      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = serviceSlug === '' ? 'Bitte Angebot wählen …' : 'Datum wählen …';
      dateSelect.appendChild(placeholder);

      if (serviceSlug === '') {
        timeSelect.innerHTML = '<option value="">Bitte zuerst Angebot wählen …</option>';
        return;
      }

      const dateAvailability = await fetchAvailableDates(daysEndpoint, serviceSlug, timezone, minDateTime, maxDate);
      const previousDate = String(dateSelect.value || '').trim();
      let firstAvailableDate = '';

      for (const day of enumerateDays(minDateTime, maxDate)) {
        const ymd = toYmd(day);
        const option = document.createElement('option');
        option.value = ymd;

        const dayMeta = dateAvailability.get(ymd) || null;
        const available = dayMeta ? dayMeta.hasAvailability : false;
        option.disabled = !available;
        option.textContent = available
          ? formatDateLabel(day)
          : `${formatDateLabel(day)} (nicht verfügbar)`;

        if (available && firstAvailableDate === '') {
          firstAvailableDate = ymd;
        }

        dateSelect.appendChild(option);
      }

      if (previousDate !== '' && (dateAvailability.get(previousDate)?.hasAvailability === true)) {
        dateSelect.value = previousDate;
      } else if (firstAvailableDate !== '') {
        dateSelect.value = firstAvailableDate;
      } else {
        dateSelect.value = '';
      }

      await renderTimeOptions();
    };

    const renderTimeOptions = async () => {
      const serviceSlug = String(contactServiceSelect.value || '').trim();
      const selectedDate = String(dateSelect.value || '').trim();

      timeSelect.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Uhrzeit wählen …';
      timeSelect.appendChild(placeholder);

      if (serviceSlug === '' || selectedDate === '') {
        return;
      }

      const candidateTimes = buildCandidateTimesForDate(selectedDate, slotStepMinutes, workWindowsByDay);
      if (candidateTimes.length === 0) {
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.disabled = true;
        emptyOption.textContent = 'Keine Öffnungszeit';
        timeSelect.appendChild(emptyOption);
        return;
      }

      const slotAvailability = await fetchAvailableTimes(slotsEndpoint, serviceSlug, selectedDate, timezone);
      const availableTimes = slotAvailability.availableTimes;
      const previousTime = String(timeSelect.value || '').trim();
      let firstAvailableTime = '';

      for (const time of candidateTimes) {
        const candidateDate = new Date(`${selectedDate}T${time}:00`);
        if (Number.isNaN(candidateDate.getTime()) || candidateDate.getTime() < minDateTime.getTime()) {
          continue;
        }

        const option = document.createElement('option');
        option.value = time;
        const available = availableTimes.has(time);
        option.disabled = !available;
        option.textContent = available ? time : `${time} (nicht verfügbar)`;

        if (available && firstAvailableTime === '') {
          firstAvailableTime = time;
        }

        timeSelect.appendChild(option);
      }

      if (previousTime !== '' && availableTimes.has(previousTime)) {
        timeSelect.value = previousTime;
      } else if (firstAvailableTime !== '') {
        timeSelect.value = firstAvailableTime;
      } else {
        timeSelect.value = '';
      }
    };

    contactServiceSelect.addEventListener('change', () => {
      renderDateOptions().catch(() => {
        dateSelect.innerHTML = '<option value="">Datum konnte nicht geladen werden.</option>';
      });
    });

    dateSelect.addEventListener('change', () => {
      renderTimeOptions().catch(() => {
        timeSelect.innerHTML = '<option value="">Uhrzeiten konnten nicht geladen werden.</option>';
      });
    });

    renderDateOptions().catch(() => {
      dateSelect.innerHTML = '<option value="">Datum konnte nicht geladen werden.</option>';
    });
  }

  function ensureSelectField(field, placeholderText) {
    if (field instanceof HTMLSelectElement) {
      return field;
    }

    if (!(field instanceof HTMLInputElement)) {
      return null;
    }

    const select = document.createElement('select');
    select.name = field.name;
    select.id = field.id;
    select.className = field.className;
    select.required = field.required;

    const style = field.getAttribute('style');
    if (style) {
      select.setAttribute('style', style);
    }

    const validators = field.getAttribute('data-validators');
    if (validators) {
      select.setAttribute('data-validators', validators);
    }

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = placeholderText;
    select.appendChild(placeholder);

    field.parentNode && field.parentNode.replaceChild(select, field);
    return select;
  }

  function enumerateDays(fromDate, toDate) {
    const days = [];
    const cursor = new Date(fromDate.getFullYear(), fromDate.getMonth(), fromDate.getDate());
    const end = new Date(toDate.getFullYear(), toDate.getMonth(), toDate.getDate());

    while (cursor <= end) {
      days.push(new Date(cursor.getTime()));
      cursor.setDate(cursor.getDate() + 1);
    }

    return days;
  }

  async function fetchAvailableDates(endpoint, serviceSlug, timezone, minDate, maxDate) {
    const availabilityByDate = new Map();
    if (endpoint === '' || serviceSlug === '') {
      return availabilityByDate;
    }

    const months = enumerateMonths(minDate, maxDate);
    for (const month of months) {
      const url = new URL(endpoint, window.location.origin);
      url.searchParams.set('service_slug', serviceSlug);
      url.searchParams.set('month', month);
      url.searchParams.set('timezone', timezone);

      try {
        const response = await fetch(url.toString(), {
          method: 'GET',
          headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
          continue;
        }

        const json = await response.json().catch(() => ({}));
        const days = Array.isArray(json?.data?.days)
          ? json.data.days
          : (Array.isArray(json?.days) ? json.days : []);

        for (const day of days) {
          const date = typeof day?.date === 'string' ? day.date : '';
          if (date === '') {
            continue;
          }

          availabilityByDate.set(date, {
            hasAvailability: day?.has_availability === true,
            fullDayBlocked: day?.full_day_blocked === true,
            reason: normalizeUnavailableReason(day?.unavailable_reason),
          });
        }
      } catch (_error) {
        // ignore and continue with next month
      }
    }

    return availabilityByDate;
  }

  function normalizeUnavailableReason(rawReason) {
    return typeof rawReason === 'string' ? rawReason.trim() : '';
  }

  function enumerateMonths(minDate, maxDate) {
    const values = [];
    const cursor = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
    const end = new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);

    while (cursor <= end) {
      values.push(`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`);
      cursor.setMonth(cursor.getMonth() + 1);
    }

    return values;
  }

  function readSlotStepMinutes(form) {
    const raw = Number.parseInt(String(form.getAttribute('data-slot-step-minutes') || '30'), 10);
    if (!Number.isFinite(raw) || raw < 5) {
      return 30;
    }
    return raw;
  }

  function parseWorkWindowsByDay(raw) {
    if (typeof raw !== 'string' || raw.trim() === '') {
      return {};
    }

    try {
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') {
        return {};
      }

      const normalized = {};
      for (const [day, windows] of Object.entries(parsed)) {
        if (!Array.isArray(windows)) {
          continue;
        }
        normalized[day] = windows
          .map(window => {
            const start = typeof window?.start === 'string' ? window.start.trim().slice(0, 5) : '';
            const end = typeof window?.end === 'string' ? window.end.trim().slice(0, 5) : '';
            return { start, end };
          })
          .filter(window => isTimeString(window.start) && isTimeString(window.end));
      }

      return normalized;
    } catch (_error) {
      return {};
    }
  }

  function findFirstDateWithWindow(minDateTime, maxDate, workWindowsByDay) {
    const start = new Date(minDateTime.getFullYear(), minDateTime.getMonth(), minDateTime.getDate());
    const end = new Date(maxDate.getFullYear(), maxDate.getMonth(), maxDate.getDate());

    for (let cursor = new Date(start.getTime()); cursor <= end; cursor.setDate(cursor.getDate() + 1)) {
      const dayOfWeek = isoDayOfWeek(cursor);
      const windows = Array.isArray(workWindowsByDay[String(dayOfWeek)]) ? workWindowsByDay[String(dayOfWeek)] : [];
      if (windows.length > 0) {
        return toYmd(cursor);
      }
    }

    return '';
  }

  function buildCandidateTimesForDate(dateYmd, stepMinutes, workWindowsByDay) {
    const [year, month, day] = dateYmd.split('-').map(part => Number.parseInt(part, 10));
    if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) {
      return [];
    }

    const date = new Date(year, month - 1, day);
    if (Number.isNaN(date.getTime())) {
      return [];
    }

    const dayOfWeek = isoDayOfWeek(date);
    const windows = Array.isArray(workWindowsByDay[String(dayOfWeek)]) ? workWindowsByDay[String(dayOfWeek)] : [];
    if (windows.length === 0) {
      return [];
    }

    const times = [];
    for (const window of windows) {
      const startMinutes = timeToMinutes(window.start);
      const endMinutes = timeToMinutes(window.end);
      if (startMinutes === null || endMinutes === null || endMinutes <= startMinutes) {
        continue;
      }

      for (let minute = startMinutes; minute < endMinutes; minute += stepMinutes) {
        times.push(minutesToTime(minute));
      }
    }

    return Array.from(new Set(times)).sort();
  }

  async function fetchAvailableTimes(endpoint, serviceSlug, selectedDate, timezone) {
    const availability = {
      availableTimes: new Set(),
      blockedReasons: new Map(),
    };

    if (endpoint === '') {
      return availability;
    }

    const rangeFrom = `${selectedDate}T00:00:00`;
    const rangeToDate = new Date(`${selectedDate}T00:00:00`);
    rangeToDate.setDate(rangeToDate.getDate() + 1);
    const rangeTo = `${toYmd(rangeToDate)}T00:00:00`;

    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('service_slug', serviceSlug);
    url.searchParams.set('from', rangeFrom);
    url.searchParams.set('to', rangeTo);
    url.searchParams.set('timezone', timezone);

    const response = await fetch(url.toString(), {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
      return availability;
    }

    const json = await response.json().catch(() => ({}));
    const slots = Array.isArray(json?.data?.slots)
      ? json.data.slots
      : (Array.isArray(json?.slots) ? json.slots : []);
    for (const slot of slots) {
      const start = typeof slot?.start === 'string' ? slot.start : '';
      if (start.length < 16) {
        continue;
      }

      const ymd = start.slice(0, 10);
      const hhmm = start.slice(11, 16);
      if (ymd === selectedDate && isTimeString(hhmm)) {
        availability.availableTimes.add(hhmm);
      }
    }

    const unavailableSlots = Array.isArray(json?.data?.unavailable_slots)
      ? json.data.unavailable_slots
      : (Array.isArray(json?.unavailable_slots) ? json.unavailable_slots : []);

    for (const slot of unavailableSlots) {
      const start = typeof slot?.start === 'string' ? slot.start : '';
      if (start.length < 16) {
        continue;
      }

      const ymd = start.slice(0, 10);
      const hhmm = start.slice(11, 16);
      if (ymd !== selectedDate || !isTimeString(hhmm)) {
        continue;
      }

      const reason = typeof slot?.reason === 'string' ? slot.reason.trim() : '';
      availability.blockedReasons.set(hhmm, reason !== '' ? reason : 'belegt');
    }

    return availability;
  }

  function renderSlotPlaceholder(slotSelect, message) {
    slotSelect.innerHTML = '';
    const option = document.createElement('option');
    option.value = '';
    option.textContent = message;
    slotSelect.appendChild(option);
    slotSelect.disabled = false;
  }

  function toYmd(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function formatDateLabel(date) {
    return new Intl.DateTimeFormat('de-DE', {
      weekday: 'short',
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    }).format(date);
  }

  function isoDayOfWeek(date) {
    const day = date.getDay();
    return day === 0 ? 7 : day;
  }

  function isTimeString(value) {
    return /^\d{2}:\d{2}$/.test(value);
  }

  function timeToMinutes(value) {
    if (!isTimeString(value)) {
      return null;
    }

    const [hoursRaw, minutesRaw] = value.split(':');
    const hours = Number.parseInt(hoursRaw, 10);
    const minutes = Number.parseInt(minutesRaw, 10);
    if (!Number.isInteger(hours) || !Number.isInteger(minutes) || hours < 0 || hours > 23 || minutes < 0 || minutes > 59) {
      return null;
    }

    return (hours * 60) + minutes;
  }

  function minutesToTime(minutesOfDay) {
    const hours = Math.floor(minutesOfDay / 60);
    const minutes = minutesOfDay % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
  }

  /**
   * Basic client-side guard so users get inline hints even if API is unreachable.
   * @param {HTMLFormElement} form
   * @returns {Object<string, string[]>}
   */
  function validateClientSide(form) {
    const errors = {};

    const requiredFields = ['firstname', 'lastname', 'dob', 'email', 'phone', 'service', 'termin'];
    for (const fieldName of requiredFields) {
      const input = form.querySelector(`[name="${fieldName}"]`);
      const value = typeof input?.value === 'string' ? input.value.trim() : '';
      if (value === '') {
        errors[fieldName] = ['required'];
      }
    }

    const emailValue = String(form.querySelector('[name="email"]')?.value ?? '').trim();
    if (emailValue !== '') {
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailPattern.test(emailValue)) {
        errors.email = [...(errors.email ?? []), 'email'];
      }
    }

    const terminValue = String(form.querySelector('[name="termin"]')?.value ?? '').trim();
    if (terminValue !== '') {
      const timestamp = Date.parse(terminValue);
      if (Number.isNaN(timestamp)) {
        errors.termin = [...(errors.termin ?? []), 'invalid_datetime'];
      } else if (timestamp < Date.now()) {
        errors.termin = [...(errors.termin ?? []), 'in_past'];
      } else {
        const slotStepMinutes = readSlotStepMinutes(form);
        const date = new Date(timestamp);
        const minuteOfDay = (date.getHours() * 60) + date.getMinutes();
        if ((minuteOfDay % slotStepMinutes) !== 0) {
          errors.termin = [...(errors.termin ?? []), 'invalid_slot_interval'];
        }
      }
    }

    const dobValue = String(form.querySelector('[name="dob"]')?.value ?? '').trim();
    if (dobValue !== '') {
      const dobDate = new Date(dobValue + 'T00:00:00');
      if (Number.isNaN(dobDate.getTime())) {
        errors.dob = [...(errors.dob ?? []), 'invalid_date'];
      }
    }

    const consentCheckboxes = Array.from(form.querySelectorAll('input[data-consent-key]'));
    const unchecked = consentCheckboxes.filter(cb => !cb.checked);
    if (unchecked.length > 0) {
      errors.consents = ['required'];
    }

    return errors;
  }

  /**
   * Show field-level and consent-level validation errors from server response.
   * @param {HTMLFormElement} form
   * @param {Object} errors  – server errors object
   * @param {string} bannerMsg
   */
  function showValidationErrors(form, errors, bannerMsg) {
    const unhandled = [];

    for (const [key, messages] of Object.entries(errors)) {
      const msgList = Array.isArray(messages) ? messages : [messages];
      const fieldOverrides = FIELD_ERRORS[key.split('.')[0]] ?? {};
      const humanMsg = msgList.map(m => fieldOverrides[m] ?? ERROR_MESSAGES[m] ?? m).join(' ');

      // Consent field errors: "consents.privacy_policy" or "consents.privacy_policy.accepted"
      if (key.startsWith('consents.')) {
        const parts = key.split('.');
        const consentKey = parts[1]; // e.g. "privacy_policy"
        const checkbox = form.querySelector(`input[data-consent-key="${consentKey}"]`);
        if (checkbox) {
          markConsentError(checkbox, humanMsg);
          continue;
        }
      }

      // Top-level "consents" key – mark ALL consent checkboxes
      if (key === 'consents') {
        const allCheckboxes = form.querySelectorAll('input[data-consent-key]');
        allCheckboxes.forEach(cb => markConsentError(cb, 'Diese Einwilligung muss bestätigt werden.'));
        continue;
      }

      // Regular form field
      if (key === 'termin') {
        markTerminError(form, humanMsg);
        continue;
      }

      const input = form.querySelector(`[name="${key}"]`) ?? form.querySelector(`select[name="${key}"]`);
      if (input) {
        markFieldError(input, humanMsg);
        continue;
      }

      // Fallback: collect for banner
      const label = FIELD_LABELS[key.split('.')[0]] ?? key;
      unhandled.push(`${label}: ${humanMsg}`);
    }

    // Show banner with summary
    const bannerText = unhandled.length > 0
      ? bannerMsg + ' ' + unhandled.join(' | ')
      : bannerMsg;
    showBannerError(form, bannerText);

    // Scroll to first error
    const firstError = form.querySelector('.sp-field--error, .sp-consent--error');
    firstError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  /**
   * @param {HTMLElement} input
   * @param {string} message
   */
  function markFieldError(input, message) {
    const wrap = input.closest('.gb-fl-wrap') ?? input.parentElement;
    if (wrap) wrap.classList.add('sp-field--error');

    let hint = wrap?.querySelector('.sp-field-hint');
    if (!hint && wrap) {
      hint = document.createElement('span');
      hint.className = 'sp-field-hint';
      wrap.appendChild(hint);
    }
    if (hint) hint.textContent = message;
  }

  function markTerminError(form, message) {
    const dateInput = form.querySelector('input[name="termin_date"]');
    const slotSelect = form.querySelector('select[name="termin_slot"]');

    if (dateInput) {
      markFieldError(dateInput, message);
    }
    if (slotSelect) {
      markFieldError(slotSelect, message);
    }

    if (!dateInput && !slotSelect) {
      const hiddenTermin = form.querySelector('input[name="termin"]');
      if (hiddenTermin) {
        markFieldError(hiddenTermin, message);
      }
    }
  }

  /**
   * @param {HTMLInputElement} checkbox
   * @param {string} message
   */
  function markConsentError(checkbox, message) {
    const label = checkbox.closest('label');
    if (label) label.classList.add('sp-consent--error');

    let hint = label?.parentElement?.querySelector(`.sp-consent-hint[data-key="${checkbox.getAttribute('data-consent-key')}"]`);
    if (!hint) {
      hint = document.createElement('span');
      hint.className = 'sp-consent-hint';
      hint.setAttribute('data-key', checkbox.getAttribute('data-consent-key') ?? '');
      label?.insertAdjacentElement('afterend', hint);
    }
    hint.textContent = message;
  }

  /**
   * @param {HTMLElement} form
   * @param {string} message
   */
  function showBannerError(form, message) {
    let banner = form.querySelector('.sp-form-error-banner');
    if (!banner) {
      banner = document.createElement('div');
      banner.className = 'sp-form-error-banner';
      form.prepend(banner);
    }
    banner.textContent = message;
    banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  /**
   * Remove all error states before a new submit attempt.
   * @param {HTMLElement} form
   */
  function clearAllErrors(form) {
    form.querySelectorAll('.sp-field--error').forEach(el => el.classList.remove('sp-field--error'));
    form.querySelectorAll('.sp-consent--error').forEach(el => el.classList.remove('sp-consent--error'));
    form.querySelectorAll('.sp-field-hint, .sp-consent-hint').forEach(el => el.remove());
    form.querySelector('.sp-form-error-banner')?.remove();
  }
})();
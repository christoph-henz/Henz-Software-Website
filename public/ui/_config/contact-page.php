<?php

declare(strict_types=1);

$services = [
    ['value' => '', 'label' => 'Gesprächsform wählen …'],
];

$packages = [];
$bookingAdvanceDays = 60;
$bookingMinHoursNotice = 24;
$slotStepMinutes = 30;
$timezoneName = (string) config('app.timezone', 'Europe/Berlin');
$workWindowsByDay = [
    1 => [['start' => '08:00', 'end' => '18:00']],
    2 => [['start' => '08:00', 'end' => '18:00']],
    3 => [['start' => '08:00', 'end' => '18:00']],
    4 => [['start' => '08:00', 'end' => '18:00']],
    5 => [['start' => '08:00', 'end' => '18:00']],
    6 => [],
    7 => [],
];

$toAsciiSlug = static function (string $value): string {
    $value = trim($value);
    $value = strtr($value, [
        'Ä' => 'ae',
        'Ö' => 'oe',
        'Ü' => 'ue',
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
    ]);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    return trim($value, '-');
};

$formatEuro = static function (float $amount): string {
    if ($amount <= 0) {
        return 'kostenlos';
    }

    if (abs($amount - round($amount)) < 0.01) {
        return sprintf('%d €', (int) round($amount));
    }

    return number_format($amount, 2, ',', '.') . ' €';
};

try {
    $availabilityRuleRows = db('availability_rules')
        ->select(['rule_key', 'rule_value'])
        ->get();

    $availabilityRulesByKey = [];
    foreach (is_array($availabilityRuleRows) ? $availabilityRuleRows : [] as $ruleRow) {
        if (!is_array($ruleRow)) {
            continue;
        }

        $key = trim((string) ($ruleRow['rule_key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $availabilityRulesByKey[$key] = (string) ($ruleRow['rule_value'] ?? '');
    }

    $bookingAdvanceDays = (int) ($availabilityRulesByKey['booking_advance_days'] ?? $availabilityRulesByKey['advance_days'] ?? $bookingAdvanceDays);
    $bookingMinHoursNotice = (int) ($availabilityRulesByKey['booking_min_hours_notice'] ?? $availabilityRulesByKey['min_notice_hours'] ?? $bookingMinHoursNotice);

    $slotStepRaw = db('settings')
        ->where('`key`', 'booking_slot_interval_minutes')
        ->select(['value'])
        ->first();
    if (is_array($slotStepRaw) && is_numeric($slotStepRaw['value'] ?? null)) {
        $slotStepMinutes = (int) $slotStepRaw['value'];
    }
    if ($slotStepMinutes < 5) {
        $slotStepMinutes = 30;
    }

    $recurringRows = db('recurring_availability')
        ->where('is_active', true)
        ->select(['day_of_week', 'start_time', 'end_time'])
        ->orderBy('day_of_week', 'asc')
        ->orderBy('start_time', 'asc')
        ->get();

    if (is_array($recurringRows) && $recurringRows !== []) {
        $workWindowsByDay = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => []];
        foreach ($recurringRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $day = (int) ($row['day_of_week'] ?? 0);
            if ($day < 1 || $day > 7) {
                continue;
            }

            $start = substr((string) ($row['start_time'] ?? ''), 0, 5);
            $end = substr((string) ($row['end_time'] ?? ''), 0, 5);
            if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
                continue;
            }

            $workWindowsByDay[$day][] = [
                'start' => $start,
                'end' => $end,
            ];
        }
    }

    $serviceRows = db('services')
        ->where('is_active', true)
        ->select(['id', 'name', 'slug', 'duration_minutes', 'price', 'display_order'])
        ->get();

    $serviceSlugById = [];

    if ($serviceRows !== []) {
        usort(
            $serviceRows,
            static fn (array $a, array $b): int => (int) ($a['display_order'] ?? 0) <=> (int) ($b['display_order'] ?? 0)
        );

        $services = [
            ['value' => '', 'label' => 'Gesprächsform wählen …'],
        ];

        foreach ($serviceRows as $row) {
            $slugRaw = (string) ($row['slug'] ?? '');
            $slug = $toAsciiSlug($slugRaw !== '' ? $slugRaw : (string) ($row['name'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            $durationMinutes = (int) ($row['duration_minutes'] ?? 0);
            $durationText = $durationMinutes > 0 ? sprintf('%d Min.', $durationMinutes) : '';
            $priceText = $formatEuro((float) ($row['price'] ?? 0));

            $labelSuffix = trim($durationText . ($priceText !== '' ? ' – ' . $priceText : ''));
            $label = $name;
            if ($labelSuffix !== '') {
                $label .= ' (' . $labelSuffix . ')';
            }

            $services[] = [
                'value' => $slug,
                'label' => $label,
            ];

            $serviceId = (int) ($row['id'] ?? 0);
            if ($serviceId > 0) {
                $serviceSlugById[$serviceId] = $slug;
            }
        }

        $packageRows = db('service_packages')
            ->where('is_active', true)
            ->select(['id', 'name', 'slug', 'service_id', 'session_count', 'price', 'display_order'])
            ->get();

        if ($packageRows !== []) {
            usort(
                $packageRows,
                static fn (array $a, array $b): int => (int) ($a['display_order'] ?? 0) <=> (int) ($b['display_order'] ?? 0)
            );

            foreach ($packageRows as $row) {
                $packageSlugRaw = (string) ($row['slug'] ?? '');
                $packageSlug = $toAsciiSlug($packageSlugRaw !== '' ? $packageSlugRaw : (string) ($row['name'] ?? ''));
                if ($packageSlug === '') {
                    continue;
                }

                $serviceId = (int) ($row['service_id'] ?? 0);
                $serviceSlug = (string) ($serviceSlugById[$serviceId] ?? '');
                if ($serviceSlug === '') {
                    continue;
                }

                $packages[$packageSlug] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'slug' => $packageSlug,
                    'name' => (string) ($row['name'] ?? ''),
                    'service_id' => $serviceId,
                    'service_slug' => $serviceSlug,
                    'session_count' => (int) ($row['session_count'] ?? 0),
                    'price' => $formatEuro((float) ($row['price'] ?? 0)),
                ];
            }
        }
    }
} catch (Exception) {
    // Keep empty package/service lists when DB is unavailable.
}

$apiBaseUrl = trim((string) env('API_BASE_URL', ''));
$requestAction = $apiBaseUrl !== ''
    ? rtrim($apiBaseUrl, '/') . '/v1/request'
    : '/v1/request';
$slotsEndpoint = $apiBaseUrl !== ''
    ? rtrim($apiBaseUrl, '/') . '/v1/availability/slots'
    : '/v1/availability/slots';

return [
    'page_title' => 'Termin buchen – Getragen Begleiten',

    'hero' => [
        'tag' => 'Termin buchen',
        'title' => 'Ich freue mich auf Sie',
        'text' => 'Füllen Sie das Formular aus – ich melde mich innerhalb von 24 Stunden bei Ihnen.',
    ],

    'process' => [
        'title' => 'So geht es weiter',
        'steps' => [
            'Formular ausfüllen und absenden',
            'Eingangsbestätigung per E-Mail (innerhalb 24 Std.)',
            'Rechnung & Zahlungsinformationen per E-Mail',
            'Terminbestätigung nach Zahlungseingang',
            'Erinnerung 24 Std. vor dem Termin',
        ],
    ],

    'form' => [
        'action' => $requestAction,
        'method' => 'post',
        'slot_picker' => [
            'slots_endpoint' => $slotsEndpoint,
            'timezone' => $timezoneName,
            'slot_step_minutes' => $slotStepMinutes,
            'booking_min_hours_notice' => $bookingMinHoursNotice,
            'booking_advance_days' => $bookingAdvanceDays,
            'work_windows_by_day' => $workWindowsByDay,
        ],
        'services' => $services,
        'packages' => $packages,
        'fields' => [
            [
                'name' => 'firstname',
                'type' => 'text',
                'label' => 'Vorname *',
                'required' => true,
                'col' => 'half',
            ],
            [
                'name' => 'lastname',
                'type' => 'text',
                'label' => 'Nachname *',
                'required' => true,
                'col' => 'half',
            ],
            [
                'name' => 'dob',
                'type' => 'date',
                'label' => 'Geburtsdatum *',
                'required' => true,
                'col' => 'half',
            ],
            [
                'name' => 'email',
                'type' => 'email',
                'label' => 'E-Mail-Adresse *',
                'required' => true,
                'col' => 'full',
            ],
            [
                'name' => 'phone',
                'type' => 'tel',
                'label' => 'Telefonnummer *',
                'required' => true,
                'col' => 'full',
            ],
            [
                'name' => 'termin',
                'type' => 'datetime-local',
                'label' => 'Wunschtermin *',
                'required' => true,
                'col' => 'full',
                'attrs' => ['step' => '1800'],
            ],
            [
                'name' => 'message',
                'type' => 'textarea',
                'label' => 'Nachricht (optional)',
                'required' => false,
                'col' => 'full',
            ],
        ],
        'consents' => [
            [
                'key' => 'privacy_policy',
                'label' => 'Ich habe die Datenschutzerklärung gelesen und stimme der Verarbeitung meiner personenbezogenen Daten zum Zwecke der Kontaktaufnahme und Terminvermittlung zu.',
            ],
            [
                'key' => 'early_service_start',
                'label' => 'Ich bin damit einverstanden, dass die Leistung vor Ablauf der gesetzlichen Widerrufsfrist von 14 Tagen beginnen kann und nehme zur Kenntnis, dass mein Widerrufsrecht damit erlischt.',
            ],
        ],
        'submit_label' => 'Termin anfragen',
    ],

    'legal_hint' => 'Mit dem Absenden dieser Anfrage stimmen Sie der Verarbeitung Ihrer personenbezogenen Daten zum Zweck der Terminvermittlung zu. Details entnehmen Sie unserer <a href="/datenschutz">Datenschutzerklärung</a>.',
];

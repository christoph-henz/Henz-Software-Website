<?php

declare(strict_types=1);

$services = [
    ['value' => '', 'label' => 'Thema wählen …'],
];

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
        ->select(['id', 'name', 'slug', 'sort_order'])
        ->get();

    if (is_array($serviceRows) && $serviceRows !== []) {
        usort(
            $serviceRows,
            static fn(array $a, array $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0)
        );

        $services = [];

        foreach ($serviceRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['slug'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $serviceId = (int) ($row['id'] ?? 0);

            if ($name === '') {
                continue;
            }

            $value = $slug !== ''
                ? $slug
                : ($serviceId > 0 ? (string) $serviceId : '');

            if ($value === '') {
                continue;
            }

            $services[] = [
                'value' => $value,
                'label' => $name,
            ];
        }
    }
} catch (Exception) {
    // Keep empty service/service lists when DB is unavailable.
}

/**
 * @var mixed
 * @todo ContactRequestController mit serverseitiger Validation erstellen, um die Anfrage zu verarbeiten und an die einzelnen Controller wie TicketController weiterzuleiten.
 */
$apiBaseUrl = trim((string) env('API_BASE_URL', ''));
$requestAction = $apiBaseUrl !== ''
    ? rtrim($apiBaseUrl, '/') . '/v1/contact-request'
    : '/v1/contact-request';

$slotsEndpoint = $apiBaseUrl !== ''
    ? rtrim($apiBaseUrl, '/') . '/v1/availability/slots'
    : '/v1/availability/slots';

$daysEndpoint = $apiBaseUrl !== ''
    ? rtrim($apiBaseUrl, '/') . '/v1/availability/days'
    : '/v1/availability/days';


//     'name' => 'example_field',
//     'type' => 'text',
//     'label' => 'Beispiel Feld',
//     'required' => true,
//     'validation' => [

//         [
//             'rule' => 'required',
//             'message' => 'Dieses Feld ist erforderlich.'
//         ],

//         [
//             'rule' => 'minLength',
//             'value' => 6,
//             'message' => 'Mindestens 6 Zeichen.'
//         ],

//         [
//             'rule' => 'maxLength',
//             'value' => 20,
//             'message' => 'Maximal 20 Zeichen.'
//         ],

//         [
//             'rule' => 'length',
//             'value' => 10,
//             'message' => 'Genau 10 Zeichen.'
//         ],

//         [
//             'rule' => 'regex',
//             'pattern' => '^[A-Z0-9]+$',
//             'message' => 'Nur Großbuchstaben und Zahlen.'
//         ],

//         [
//             'rule' => 'email',
//             'message' => 'Ungültige E-Mail-Adresse.'
//         ],

//         [
//             'rule' => 'number',
//             'message' => 'Es muss eine Zahl sein.'
//         ],

//         [
//             'rule' => 'integer',
//             'message' => 'Es muss eine ganze Zahl sein.'
//         ],

//         [
//             'rule' => 'min',
//             'value' => 18,
//             'message' => 'Mindestens 18.'
//         ],

//         [
//             'rule' => 'max',
//             'value' => 99,
//             'message' => 'Maximal 99.'
//         ],

//         [
//             'rule' => 'range',
//             'min' => 20,
//             'max' => 80,
//             'message' => 'Wert muss zwischen 20 und 80 liegen.'
//         ],

//         [
//             'rule' => 'url',
//             'message' => 'Bitte eine gültige URL eingeben.'
//         ],

//         [
//             'rule' => 'date',
//             'message' => 'Ungültiges Datum.'
//         ],

//         [
//             'rule' => 'afterDate',
//             'value' => '2026-01-01',
//             'message' => 'Datum muss nach dem 01.01.2026 liegen.'
//         ],

//         [
//             'rule' => 'beforeDate',
//             'value' => '2030-01-01',
//             'message' => 'Datum muss vor dem 01.01.2030 liegen.'
//         ],

//         [
//             'rule' => 'equals',
//             'field' => 'password_confirmation',
//             'message' => 'Die Werte stimmen nicht überein.'
//         ],

//         [
//             'rule' => 'fileSize',
//             'value' => 5 * 1024 * 1024,
//             'message' => 'Datei darf maximal 5 MB groß sein.'
//         ],

//         [
//             'rule' => 'fileExtension',
//             'extensions' => [
//                 'pdf',
//                 'doc',
//                 'docx',
//                 'jpg',
//                 'jpeg',
//                 'png'
//             ],
//             'message' => 'Dateityp nicht erlaubt.'
//         ]

//     ]
// ]


return [

    'page_title' => 'Kontaktanfrage – Henz Software',

    'form' => [

        'action' => $requestAction,
        'method' => 'post',
        'success_redirect_url' => '/kontakt/erfolg',
        'slot_picker' => [
            'slots_endpoint' => $slotsEndpoint,
            'days_endpoint' => $daysEndpoint,
            'timezone' => $timezoneName,
            'slot_step_minutes' => $slotStepMinutes,
            'booking_min_hours_notice' => $bookingMinHoursNotice,
            'booking_advance_days' => $bookingAdvanceDays,
            'work_windows_by_day' => $workWindowsByDay,
        ],

        'fields' => [

            [
                'name' => 'service_type',
                'type' => 'select',
                'label' => 'Worum geht es? *',
                'required' => true,

                'choices' => [
                    [
                        'value' => 'contact',
                        'label' => 'Angebot anfragen',

                        'fields' => [
                            [
                                'name' => 'service',
                                'type' => 'choice',
                                'label' => 'Angebot *',
                                'required' => true,
                                'choices' => $services
                            ],

                            [
                                'name' => 'firstname',
                                'type' => 'text',
                                'label' => 'Vorname *',
                                'required' => true
                            ],

                            [
                                'name' => 'lastname',
                                'type' => 'text',
                                'label' => 'Nachname *',
                                'required' => true
                            ],

                            [
                                'name' => 'email',
                                'type' => 'email',
                                'label' => 'E-Mail *',
                                'validation' => [
                                    'rule' => 'email',
                                    'message' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.'
                                ]
                            ],
                            [
                                'name' => 'appointment_date',
                                'type' => 'date',
                                'label' => 'Gewünschtes Datum *',
                                'required' => true,
                                'validation' => [
                                    [
                                        'rule' => 'afterDate',
                                        'value' => (new DateTime())
                                            ->modify('+2 days')
                                            ->format('Y-m-d'),
                                        'message' => 'Bitte wählen Sie einen Termin, der mindestens 2 Tage in der Zukunft liegt.'
                                    ]
                                ]
                            ],
                            [
                                'name' => 'appointment_time',
                                'type' => 'time',
                                'label' => 'Gewünschte Uhrzeit *',
                                'required' => true,
                                'choices' => [
                                    'slot_step_minutes' => $slotStepMinutes,
                                    'work_windows_by_day' => $workWindowsByDay,
                                    'timezone_name' => $timezoneName
                                ]
                            ],

                            [
                                'name' => 'message',
                                'type' => 'textarea',
                                'label' => 'Kurzbeschreibung *',
                                'required' => true,
                                'placeholder' => "\nBitte geben Sie eine kurze Beschreibung Ihres Anliegens ein.",
                                'validation' => [
                                    'rule' => 'minLength',
                                    'value' => 30,
                                    'message' => 'Bitte geben Sie eine kurze Beschreibung Ihres Anliegens ein (mindestens 30 Zeichen).'
                                ]
                            ]
                        ]
                    ],
                    [
                        'value' => 'service',
                        'label' => 'Bestehender Service',

                        'fields' => [

                            [
                                'name' => 'client_number',
                                'type' => 'text',
                                'label' => 'Kundennummer *',
                                'required' => true,
                                'col' => 'half',
                                'validation' => [
                                    'rule' => 'minLength',
                                    'value' => 6,
                                    'message' => 'Die Kundennummer muss aus 6 Ziffern bestehen.'
                                ]
                            ],

                            [
                                'name' => 'project_number',
                                'type' => 'text',
                                'label' => 'Projektnummer (falls vorhanden)',
                                'col' => 'half',
                                'validation' => [
                                    'rule' => 'minLength',
                                    'value' => 6,
                                    'message' => 'Die Projektnummer muss aus 6 Ziffern bestehen.'
                                ]
                            ],
                            [
                                'name' => 'contract_number',
                                'type' => 'text',
                                'label' => 'Vertragsnummer (falls vorhanden)',
                                'validation' => [
                                    'rule' => 'minLength',
                                    'value' => 6,
                                    'message' => 'Die Vertragsnummer muss aus 6 Ziffern bestehen.'
                                ]
                            ],
                            [
                                'name' => 'service_action',
                                'type' => 'select',
                                'label' => 'Gewünschte Aktion *',
                                'required' => true,
                                'choices' => [
                                    [
                                        'value' => 'update',
                                        'label' => 'Service aktualisieren / erweitern',
                                        'fields' => [
                                            [
                                                'name' => 'update_details',
                                                'type' => 'textarea',
                                                'label' => 'Details zur Aktualisierung',
                                                'required' => true,
                                                'validation' => [
                                                    'rule' => 'minLength',
                                                    'value' => 30,
                                                    'message' => 'Bitte geben Sie eine kurze Beschreibung der gewünschten Aktualisierung ein (mindestens 30 Zeichen).'
                                                ]
                                            ],
                                            [
                                                'name' => 'file_upload',
                                                'type' => 'file',
                                                'label' => 'Datei hochladen'
                                            ]
                                        ]
                                    ],
                                    [
                                        'value' => 'cancel',
                                        'label' => 'Service kündigen',
                                        'fields' => [
                                            [
                                                'name' => 'cancel_date',
                                                'type' => 'date',
                                                'label' => 'Gewünschtes Kündigungsdatum',
                                                'required' => true
                                            ],
                                            [
                                                'name' => 'message',
                                                'type' => 'textarea',
                                                'label' => 'Grund für die Kündigung',
                                                'required' => true,
                                                'validation' => [
                                                    'rule' => 'minLength',
                                                    'value' => 30,
                                                    'message' => 'Bitte geben Sie einen Grund für die Kündigung an (mindestens 30 Zeichen).'
                                                ]
                                            ],
                                        ]
                                    ],
                                    [
                                        'value' => 'other',
                                        'label' => 'Sonstiges',
                                        'fields' => [
                                            [
                                                'name' => 'message',
                                                'type' => 'textarea',
                                                'label' => 'Beschreibung'
                                            ],
                                            [
                                                'name' => 'file_upload',
                                                'type' => 'file',
                                                'label' => 'Datei hochladen'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],

                    [
                        'value' => 'ticket',
                        'label' => 'Problem melden',

                        'fields' => [

                            [
                                'name' => 'client_number',
                                'type' => 'text',
                                'label' => 'Kundennummer *',
                                'required' => true
                            ],
                            [
                                'name' => 'ticket_category',
                                'type' => 'select',
                                'label' => 'Problemkategorie *',

                                'choices' => [

                                    [
                                        'value' => 'technical',
                                        'label' => 'Technisches Problem',

                                        'fields' => [

                                            [
                                                'name' => 'operating_system',
                                                'type' => 'select',
                                                'label' => 'Betriebssystem *',
                                                'required' => true,
                                                'choices' => [

                                                    [
                                                        'value' => 'windows',
                                                        'label' => 'Windows',

                                                        'fields' => [

                                                            [
                                                                'name' => 'distribution',
                                                                'type' => 'select',
                                                                'label' => 'Windows-Version *',
                                                                'required' => true,
                                                                'choices' => [

                                                                    [
                                                                        'value' => 'windows_11',
                                                                        'label' => 'Windows 11'
                                                                    ],
                                                                    [
                                                                        'value' => 'windows_10',
                                                                        'label' => 'Windows 10'
                                                                    ],
                                                                    [
                                                                        'value' => 'windows_8_1',
                                                                        'label' => 'Windows 8.1'
                                                                    ],
                                                                    [
                                                                        'value' => 'windows_8',
                                                                        'label' => 'Windows 8'
                                                                    ],
                                                                    [
                                                                        'value' => 'windows_7',
                                                                        'label' => 'Windows 7'
                                                                    ],
                                                                    [
                                                                        'value' => 'windows_vista',
                                                                        'label' => 'Windows Vista'
                                                                    ],
                                                                    [
                                                                        'value' => 'windows_xp',
                                                                        'label' => 'Windows XP'
                                                                    ],
                                                                ]
                                                            ]
                                                        ]
                                                    ],
                                                    [
                                                        'value' => 'macos',
                                                        'label' => 'macOS',

                                                        'fields' => [

                                                            [
                                                                'name' => 'distribution',
                                                                'type' => 'select',
                                                                'label' => 'macOS-Version *',
                                                                'required' => true,
                                                                'choices' => [
                                                                    [
                                                                        'value' => 'macos_tahoe',
                                                                        'label' => 'macOS 26 Tahoe (2025)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_sequoia',
                                                                        'label' => 'macOS 15 Sequoia (2024)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_sonoma',
                                                                        'label' => 'macOS 14 Sonoma (2023)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_ventura',
                                                                        'label' => 'macOS 13 Ventura (2022)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_monterey',
                                                                        'label' => 'macOS 12 Monterey (2021)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_big_sur',
                                                                        'label' => 'macOS 11 Big Sur (2020)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_catalina',
                                                                        'label' => 'macOS 10.15 Catalina (2019)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_mojave',
                                                                        'label' => 'macOS 10.14 Mojave (2018)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_high_sierra',
                                                                        'label' => 'macOS 10.13 High Sierra (2017)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_sierra',
                                                                        'label' => 'macOS 10.12 Sierra (2016)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_el_capitan',
                                                                        'label' => 'OS X 10.11 El Capitan (2015)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_yosemite',
                                                                        'label' => 'OS X 10.10 Yosemite (2014)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_mavericks',
                                                                        'label' => 'OS X 10.9 Mavericks (2013)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_mountain_lion',
                                                                        'label' => 'OS X 10.8 Mountain Lion (2012)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_lion',
                                                                        'label' => 'OS X 10.7 Lion (2011)'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_snow_leopard',
                                                                        'label' => 'Mac OS X 10.6 Snow Leopard'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_leopard',
                                                                        'label' => 'Mac OS X 10.5 Leopard'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_tiger',
                                                                        'label' => 'Mac OS X 10.4 Tiger'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_panther',
                                                                        'label' => 'Mac OS X 10.3 Panther'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_jaguar',
                                                                        'label' => 'Mac OS X 10.2 Jaguar'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_puma',
                                                                        'label' => 'Mac OS X 10.1 Puma'
                                                                    ],
                                                                    [
                                                                        'value' => 'macos_cheetah',
                                                                        'label' => 'Mac OS X 10.0 Cheetah'
                                                                    ]
                                                                ]
                                                            ]
                                                        ]
                                                    ],
                                                    [
                                                        'value' => 'linux',
                                                        'label' => 'Linux',

                                                        'fields' => [

                                                            [
                                                                'name' => 'distribution',
                                                                'type' => 'select',
                                                                'label' => 'Distribution(sfanmilie) *',
                                                                'required' => true,
                                                                'choices' => [
                                                                    [
                                                                        'value' => 'debian',
                                                                        'label' => 'Debian',
                                                                        'fields' => [
                                                                            [
                                                                                'name' => 'debian_type',
                                                                                'type' => 'select',
                                                                                'label' => 'Debian-Distro',
                                                                                'choices' => [
                                                                                    [
                                                                                        'value' => 'debian_ubuntu',
                                                                                        'label' => 'Ubuntu'
                                                                                    ],
                                                                                    [
                                                                                        'value' => 'debian_debian',
                                                                                        'label' => 'Debian'
                                                                                    ],
                                                                                    [
                                                                                        'value' => 'debian_mint',
                                                                                        'label' => 'Linux Mint'
                                                                                    ],
                                                                                    [
                                                                                        'value' => 'debian_kali',
                                                                                        'label' => 'Kali Linux'
                                                                                    ],
                                                                                    [
                                                                                        'value' => 'debian_elementary',
                                                                                        'label' => 'Elementary OS'
                                                                                    ]
                                                                                ]
                                                                            ]
                                                                        ]
                                                                    ],
                                                                    [
                                                                        'value' => 'redhat',
                                                                        'label' => 'Red Hat Enterprise Linux',
                                                                        'fields' => [
                                                                            [
                                                                                'name' => 'redhat_type',
                                                                                'type' => 'select',
                                                                                'label' => 'Red Hat-Distro',
                                                                                'choices' => [
                                                                                    [
                                                                                        'value' => 'redhat_rhel',
                                                                                        'label' => 'RHEL'
                                                                                    ],
                                                                                    [
                                                                                        'value' => 'redhat_centos',
                                                                                        'label' => 'CentOS'
                                                                                    ],
                                                                                    [
                                                                                        'value' => 'redhat_fedora',
                                                                                        'label' => 'Fedora'
                                                                                    ],
                                                                                    [
                                                                                        'value' => 'redhat_rockylinux',
                                                                                        'label' => 'Rocky Linux'
                                                                                    ]
                                                                                ]
                                                                            ]
                                                                        ]
                                                                    ],
                                                                    [
                                                                        'value' => 'arch',
                                                                        'label' => 'Arch Linux'
                                                                    ],
                                                                    [
                                                                        'value' => 'suse',
                                                                        'label' => 'openSUSE'
                                                                    ],
                                                                    [
                                                                        'value' => 'others',
                                                                        'label' => 'Andere',
                                                                        'fields' => [
                                                                            [
                                                                                'name' => 'other_distribution',
                                                                                'type' => 'text',
                                                                                'label' => 'Andere Distribution'
                                                                            ]
                                                                        ]
                                                                    ]
                                                                ]
                                                            ],
                                                            [
                                                                'name' => 'kernel_version',
                                                                'type' => 'text',
                                                                'label' => 'Kernel-Version'
                                                            ]
                                                        ]
                                                    ]
                                                ]
                                            ],
                                            [
                                                'name' => 'browser',
                                                'type' => 'select',
                                                'label' => 'Browser',
                                                'choices' => [
                                                    [
                                                        'value' => 'chrome',
                                                        'label' => 'Google Chrome'
                                                    ],
                                                    [
                                                        'value' => 'firefox',
                                                        'label' => 'Mozilla Firefox'
                                                    ],
                                                    [
                                                        'value' => 'edge',
                                                        'label' => 'Microsoft Edge'
                                                    ],
                                                    [
                                                        'value' => 'safari',
                                                        'label' => 'Apple Safari'
                                                    ],
                                                    [
                                                        'value' => 'opera',
                                                        'label' => 'Opera'
                                                    ],
                                                    [
                                                        'value' => 'other',
                                                        'label' => 'Andere',
                                                        'fields' => [
                                                            [
                                                                'name' => 'other_browser',
                                                                'type' => 'text',
                                                                'label' => 'Andere Browser'
                                                            ]
                                                        ]
                                                    ]
                                                ]
                                            ],
                                            [
                                                'name' => 'steps',
                                                'type' => 'textarea',
                                                'label' => 'Reproduktionsschritte *',
                                                'required' => true
                                            ],
                                            [
                                                'name' => 'ticket_priority',
                                                'type' => 'select',
                                                'label' => 'Priorität *',
                                                'required' => true,
                                                'choices' => [
                                                    [
                                                        'value' => 'low',
                                                        'label' => 'Niedrig (keine Serviceeinschränkung)'
                                                    ],
                                                    [
                                                        'value' => 'medium',
                                                        'label' => 'Mittel (Serviceeinschränkung möglich)'
                                                    ],
                                                    [
                                                        'value' => 'high',
                                                        'label' => 'Hoch (Serviceeinschränkung)'
                                                    ],
                                                    [
                                                        'value' => 'urgent',
                                                        'label' => 'Kritisch (Systemausfall)'
                                                    ]
                                                ]
                                            ],
                                            [
                                                'name' => 'file_upload',
                                                'type' => 'file',
                                                'label' => 'Datei hochladen'
                                            ]
                                        ]
                                    ],

                                    [
                                        'value' => 'invoice',
                                        'label' => 'Rechnung',

                                        'fields' => [

                                            [
                                                'name' => 'invoice_number',
                                                'type' => 'text',
                                                'label' => 'Rechnungsnummer'
                                            ],
                                            [
                                                'name' => 'invoice_date',
                                                'type' => 'date',
                                                'label' => 'Rechnungsdatum'
                                            ],
                                            [
                                                'name' => 'message',
                                                'type' => 'textarea',
                                                'label' => 'Problembeschreibung'
                                            ]
                                        ]
                                    ],

                                    [
                                        'value' => 'other',
                                        'label' => 'Sonstiges',

                                        'fields' => [

                                            [
                                                'name' => 'message',
                                                'type' => 'textarea',
                                                'label' => 'Problembeschreibung'
                                            ],
                                            [
                                                'name' => 'file_upload',
                                                'type' => 'file',
                                                'label' => 'Datei hochladen'
                                            ]
                                        ]
                                    ],
                                ]
                            ]
                        ]
                    ]

                ]
            ]

        ]
    ]
];
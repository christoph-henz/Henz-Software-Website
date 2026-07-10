<?php

declare(strict_types=1);

$legalContact = require __DIR__ . '/legal-contact.php';
$contactEmail = (string) ($legalContact['contact_email'] ?? 'info@henz-software.com');
$supportEmail = (string) ($legalContact['support_email'] ?? 'support@henz-software.com');
$contactPhone = (string) ($legalContact['contact_phone'] ?? 'Nicht verfügbar');

return [
    'page_title' => 'Datenschutzerklärung – Henz Software Solutions',

    'hero' => [
        'tag' => 'Rechtliches',
        'title' => 'Datenschutzerklärung',
        'intro' => 'Informationen zur Verarbeitung personenbezogener Daten gemäß Datenschutz-Grundverordnung (DSGVO).',
    ],

    'sections' => [

        [
            'title' => '1. Verantwortliche',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => "Verantwortlich für die inhaltlichen Beratungsleistungen:\n\nChristoph Henz\nHenz Software Solutions\nE-Mail: {$contactEmail}\nTelefon: {$contactPhone}",
                ],
                [
                    'type' => 'text',
                    'text' => "Verantwortlich für technischen Betrieb, Hosting und IT-Sicherheit:\n\nChristoph Henz\nE-Mail: {$supportEmail}\nTelefon: {$contactPhone}",
                ],
            ],
        ],

        [
            'title' => '2. Allgemeine Hinweise zur Datenverarbeitung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Wir verarbeiten personenbezogene Daten ausschließlich im Rahmen der gesetzlichen Vorschriften der Datenschutz-Grundverordnung (DSGVO) sowie weiterer anwendbarer Datenschutzbestimmungen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Die Verarbeitung erfolgt nur, soweit dies zur Bereitstellung der Website, zur Bearbeitung von Anfragen, zur Terminorganisation oder zur Erfüllung gesetzlicher Pflichten erforderlich ist.',
                ],
            ],
        ],

        [
            'title' => '3. Zwecke und Rechtsgrundlagen der Verarbeitung',
            'blocks' => [
                [
                    'type' => 'list',
                    'items' => [
                        'Bearbeitung von Kontakt- und Buchungsanfragen (Art. 6 Abs. 1 lit. b DSGVO).',
                        'Durchführung organisatorischer Terminprozesse (Art. 6 Abs. 1 lit. b DSGVO).',
                        'Versand von Termin- und Service-E-Mails (Art. 6 Abs. 1 lit. b DSGVO).',
                        'Erfüllung gesetzlicher Aufbewahrungs- und Nachweispflichten (Art. 6 Abs. 1 lit. c DSGVO).',
                        'Schutz der Website und IT-Systeme vor Missbrauch und Angriffen (Art. 6 Abs. 1 lit. f DSGVO).',
                        'Dokumentation rechtlicher Einwilligungen und Widerrufsbestätigungen (Art. 6 Abs. 1 lit. c und lit. f DSGVO).',
                    ],
                ],
            ],
        ],

        [
            'title' => '4. Verarbeitete Datenkategorien',
            'blocks' => [
                [
                    'type' => 'list',
                    'items' => [
                        'Kontaktdaten: Vorname, Nachname, E-Mail-Adresse, Telefonnummer.',
                        'Vertrags- und Termindaten.',
                        'Kommunikationsdaten aus Kontaktformularen und E-Mails.',
                        'Zahlungs- und Statusinformationen.',
                        'Technische Zugriffsdaten wie IP-Adresse, Browserinformationen, Zeitstempel und Request-IDs.',
                        'Einwilligungs- und Nachweisdaten wie Consent-Key, Consent-Version, Zeitstempel und Signatur-Hash.',
                    ],
                ],
            ],
        ],

        [
            'title' => '5. Speicherung und Löschung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Personenbezogene Daten werden nur solange gespeichert, wie dies zur Erfüllung der jeweiligen Zwecke erforderlich ist oder gesetzliche Aufbewahrungspflichten bestehen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Einwilligungs- und Nachweisdaten können zur rechtlichen Dokumentation länger gespeichert werden.',
                ],
            ],
        ],

        [
            'title' => '6. Hosting und technische Protokollierung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Beim Aufruf der Website werden technische Zugriffsdaten verarbeitet, um die Stabilität, Sicherheit und Funktionsfähigkeit der Website sicherzustellen.',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'IP-Adresse',
                        'Datum und Uhrzeit des Zugriffs',
                        'Browsertyp und Betriebssystem',
                        'aufgerufene Seiten und Ressourcen',
                        'Referrer-Informationen',
                    ],
                ],
            ],
        ],

        [
            'title' => '7. Kontakt- und Buchungsformulare',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Die über Formulare übermittelten Daten werden zur Bearbeitung der Anfrage, zur Terminorganisation sowie zur Kommunikation im Zusammenhang mit den angebotenen Leistungen verarbeitet.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Die Verarbeitung erfolgt über eine technisch getrennte Web- und API-Infrastruktur.',
                ],
            ],
        ],

        [
            'title' => '8. E-Mail-Kommunikation',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Im Rahmen der Terminorganisation können automatische oder manuelle E-Mails versendet werden.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Dabei können Versandzeitpunkte, Zustellstatus und organisatorische Kommunikationsdaten protokolliert werden.',
                ],
            ],
        ],

        [
            'title' => '9. Cookies und lokale Speicherung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Die Website verwendet technisch notwendige Mechanismen zur Sitzungsverwaltung und Sicherheitsfunktionen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Sofern zukünftig zusätzliche Cookies oder externe Dienste eingesetzt werden, werden diese gesondert beschrieben.',
                ],
            ],
        ],

        [
            'title' => '10. Rechte betroffener Personen',
            'blocks' => [
                [
                    'type' => 'list',
                    'items' => [
                        'Auskunft gemäß Art. 15 DSGVO',
                        'Berichtigung gemäß Art. 16 DSGVO',
                        'Löschung gemäß Art. 17 DSGVO',
                        'Einschränkung der Verarbeitung gemäß Art. 18 DSGVO',
                        'Datenübertragbarkeit gemäß Art. 20 DSGVO',
                        'Widerspruch gemäß Art. 21 DSGVO',
                        'Widerruf erteilter Einwilligungen mit Wirkung für die Zukunft',
                    ],
                ],
            ],
        ],

        [
            'title' => '11. Beschwerderecht',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Betroffene Personen haben das Recht, sich bei einer Datenschutzaufsichtsbehörde über die Verarbeitung personenbezogener Daten zu beschweren.',
                ],
            ],
        ],

        [
            'title' => '12. Datensicherheit',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Die Website verwendet technische und organisatorische Sicherheitsmaßnahmen zum Schutz personenbezogener Daten.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Hierzu gehören insbesondere Zugriffsbeschränkungen, sichere Datenübertragung über HTTPS sowie Maßnahmen zur Protokollierung und Missbrauchserkennung.',
                ],
            ],
        ],

        [
            'title' => '13. Stand und Änderungen',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Diese Datenschutzerklärung kann angepasst werden, wenn technische oder rechtliche Änderungen dies erforderlich machen.',
                ],
                [
                    'type' => 'text',
                    'text' => "\nStand der letzten Aktualisierung: Juli 2026.",
                ],
            ],
        ],

    ],
];
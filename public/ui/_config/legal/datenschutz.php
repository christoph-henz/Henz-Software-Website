<?php

declare(strict_types=1);

$legalContact = require __DIR__ . '/legal-contact.php';
$contactEmail = (string) ($legalContact['contact_email'] ?? 'info@henz-software.de');
$supportEmail = (string) ($legalContact['support_email'] ?? 'support@henz-software.de');
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
            'title' => '1. Verantwortlicher',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => "Verantwortlich für die Verarbeitung personenbezogener Daten auf dieser Website:\n\nChristoph Henz\nHenz Software Solutions\nE-Mail: {$contactEmail}\nTelefon: {$contactPhone}",
                ],
            ],
        ],

        [
            'title' => '2. Allgemeine Hinweise zur Datenverarbeitung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Wir verarbeiten personenbezogene Daten ausschließlich im Rahmen der geltenden datenschutzrechtlichen Vorschriften, insbesondere der Datenschutz-Grundverordnung (DSGVO).',
                ],
                [
                    'type' => 'text',
                    'text' => 'Personenbezogene Daten werden nur verarbeitet, soweit dies zur Bereitstellung dieser Website, zur Bearbeitung von Anfragen oder zur Durchführung unserer Dienstleistungen erforderlich ist.',
                ],
            ],
        ],

        [
            'title' => '3. Zwecke und Rechtsgrundlagen der Verarbeitung',
            'blocks' => [
                [
                    'type' => 'list',
                    'items' => [
                        'Bereitstellung der Website (Art. 6 Abs. 1 lit. f DSGVO).',
                        'Bearbeitung von Kontaktanfragen (Art. 6 Abs. 1 lit. b DSGVO).',
                        'Anbahnung und Durchführung von Verträgen (Art. 6 Abs. 1 lit. b DSGVO).',
                        'Erfüllung gesetzlicher Verpflichtungen (Art. 6 Abs. 1 lit. c DSGVO).',
                        'Gewährleistung der IT-Sicherheit und Missbrauchserkennung (Art. 6 Abs. 1 lit. f DSGVO).',
                    ],
                ],
            ],
        ],

        [
            'title' => '4. Verarbeitete Daten',
            'blocks' => [
                [
                    'type' => 'list',
                    'items' => [
                        'Name',
                        'E-Mail-Adresse',
                        'Telefonnummer (sofern angegeben)',
                        'Inhalte Ihrer Anfrage',
                        'IP-Adresse',
                        'Browser- und Geräteinformationen',
                        'Datum und Uhrzeit des Zugriffs',
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
            'title' => '6. Hosting und Server-Logfiles',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Beim Aufruf dieser Website werden durch den Hostinganbieter automatisch Informationen in sogenannten Server-Logfiles erfasst. Die Verarbeitung erfolgt zur Bereitstellung der Website sowie zur Gewährleistung der Stabilität und Sicherheit des Betriebs.',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'IP-Adresse',
                        'Datum und Uhrzeit des Zugriffs',
                        'Browsertyp und Browserversion',
                        'Betriebssystem',
                        'aufgerufene Seiten und Dateien',
                        'Referrer-URL (sofern übermittelt)',
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => 'Die Verarbeitung erfolgt auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse besteht in einem sicheren und störungsfreien Betrieb der Website.',
                ],
            ],
        ],

        [
            'title' => '7. Kontaktformular',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Wenn Sie uns über das Kontaktformular kontaktieren, verarbeiten wir die von Ihnen übermittelten personenbezogenen Daten ausschließlich zur Bearbeitung Ihrer Anfrage sowie zur weiteren Kommunikation.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, sofern Ihre Anfrage auf den Abschluss oder die Durchführung eines Vertrags abzielt. Andernfalls erfolgt die Verarbeitung auf Grundlage unseres berechtigten Interesses gemäß Art. 6 Abs. 1 lit. f DSGVO.',
                ],
            ],
        ],

        [
            'title' => '8. Kommunikation per E-Mail',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Wenn Sie uns per E-Mail kontaktieren, werden Ihre Angaben ausschließlich zur Bearbeitung Ihrer Anfrage verarbeitet.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Für den Versand von E-Mails verwenden wir die PHP-Bibliothek PHPMailer. Dabei werden ausschließlich die für den Versand erforderlichen personenbezogenen Daten verarbeitet. Die Übertragung erfolgt über den von uns genutzten Mailserver.',
                ],
            ],
        ],

        [
            'title' => '9. Cookies und Einwilligungsmanagement',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Diese Website verwendet technisch notwendige Cookies, die für den sicheren Betrieb der Website erforderlich sind.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Darüber hinaus werden Analyse-Cookies ausschließlich nach Ihrer ausdrücklichen Einwilligung eingesetzt. Ihre Einwilligung können Sie jederzeit mit Wirkung für die Zukunft widerrufen.',
                ],
            ],
        ],

        [
            'title' => '10. Google Analytics',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Diese Website verwendet Google Analytics, einen Webanalysedienst der Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Google Analytics hilft uns dabei, die Nutzung unserer Website statistisch auszuwerten und unser Angebot kontinuierlich zu verbessern.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Die Verarbeitung erfolgt ausschließlich auf Grundlage Ihrer Einwilligung gemäß Art. 6 Abs. 1 lit. a DSGVO. Ohne Ihre Zustimmung wird Google Analytics nicht geladen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Weitere Informationen finden Sie in der Datenschutzerklärung von Google: https://policies.google.com/privacy',
                ],
            ],
        ],

        [
            'title' => '11. Google Fonts',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Zur einheitlichen Darstellung von Schriftarten verwenden wir Google Fonts. Die Schriftarten sind lokal auf unserem Server eingebunden. Es erfolgt keine Verbindung zu Servern von Google und keine Übermittlung personenbezogener Daten an Google.',
                ],
            ],
        ],

        [
            'title' => '12. GitHub',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Auf unserer Website können Verlinkungen zu unserem GitHub-Profil oder zu Projekten auf GitHub enthalten sein.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Erst durch Anklicken eines entsprechenden Links wird eine Verbindung zu GitHub hergestellt. Es gelten die Datenschutzbestimmungen der GitHub, Inc.',
                ],
            ],
        ],

        [
            'title' => '13. Rechte betroffener Personen',
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
            'title' => '14. Beschwerderecht',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Betroffene Personen haben das Recht, sich bei einer Datenschutzaufsichtsbehörde über die Verarbeitung personenbezogener Daten zu beschweren.',
                ],
            ],
        ],

        [
            'title' => '15. Datensicherheit',
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
            'title' => '16. Stand und Änderungen',
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
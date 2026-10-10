<?php

declare(strict_types=1);

$legalContact = require __DIR__ . '/legal-contact.php';
$contactEmail = (string) ($legalContact['contact_email'] ?? 'info@henz-software.de');
$supportEmail = (string) ($legalContact['support_email'] ?? 'support@henz-software.de');
$contactPhone = (string) ($legalContact['contact_phone'] ?? 'Nicht verfügbar');

return [
    'page_title' => 'Widerrufsbelehrung – Henz Software Solutions',

    'hero' => [
        'tag' => 'Rechtliches',
        'title' => 'Widerrufsbelehrung',
        'intro' => 'Informationen zum gesetzlichen Widerrufsrecht für Verbraucher bei Dienstleistungsverträgen.',
    ],

    'sections' => [

        [
            'title' => 'Widerrufsrecht',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Verbraucher haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag des Vertragsabschlusses.',
                ],
            ],
        ],

        [
            'title' => 'Ausübung des Widerrufsrechts',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Um Ihr Widerrufsrecht auszuüben, müssen Sie uns mittels einer eindeutigen Erklärung über Ihren Entschluss informieren, diesen Vertrag zu widerrufen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Der Widerruf kann per E-Mail oder postalisch erfolgen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Zur Wahrung der Widerrufsfrist genügt es, dass Sie die Mitteilung über die Ausübung des Widerrufsrechts vor Ablauf der Widerrufsfrist absenden.',
                ],
                [
                    'type' => 'contact',
                    'items' => [
                        'Henz Software Solutions',
                        'Christoph Henz',
                        'E-Mail (Kontakt): ' . $contactEmail,
                        'E-Mail (Support): ' . $supportEmail,
                        'Telefon: ' . $contactPhone,
                    ],
                ],
            ],
        ],

        [
            'title' => 'Folgen des Widerrufs',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Wenn Sie diesen Vertrag widerrufen, erstatten wir Ihnen alle Zahlungen, die wir von Ihnen erhalten haben, unverzüglich und spätestens binnen vierzehn Tagen ab dem Tag, an dem die Mitteilung über Ihren Widerruf bei uns eingegangen ist.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Für die Rückzahlung verwenden wir dasselbe Zahlungsmittel, das Sie bei der ursprünglichen Transaktion eingesetzt haben, es sei denn, mit Ihnen wurde ausdrücklich etwas anderes vereinbart.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Für diese Rückzahlung werden Ihnen keine Entgelte berechnet.',
                ],
            ],
        ],

        [
            'title' => 'Vorzeitiges Erlöschen des Widerrufsrechts',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Bei Dienstleistungsverträgen erlischt das Widerrufsrecht, wenn wir die Dienstleistung vollständig erbracht haben und mit der Ausführung erst begonnen haben, nachdem Sie ausdrücklich zugestimmt haben, dass wir vor Ablauf der Widerrufsfrist mit der Ausführung beginnen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Sie bestätigen außerdem, dass Ihnen bekannt ist, dass Sie mit vollständiger Vertragserfüllung Ihr Widerrufsrecht verlieren.',
                ],
            ],
        ],

        [
            'title' => 'Hinweis zu individuell angefertigten Leistungen',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Bei individuell für den Kunden entwickelten Softwarelösungen, Websites oder sonstigen auf persönliche Anforderungen zugeschnittenen Leistungen kann das gesetzliche Widerrufsrecht nach den gesetzlichen Vorschriften ausgeschlossen oder vorzeitig erlöschen. Maßgeblich sind die jeweiligen vertraglichen Vereinbarungen sowie die gesetzlichen Bestimmungen.',
                ],
            ],
        ],

        [
            'title' => 'Muster-Widerrufsformular',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Wenn Sie den Vertrag widerrufen möchten, können Sie uns eine formlose Erklärung zusenden oder folgendes Muster verwenden:',
                ],
                [
                    'type' => 'quote',
                    'text' => "Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über die Erbringung der folgenden Dienstleistung:

                                Bestellt am:

                                Name des/der Verbraucher(s):

                                Anschrift des/der Verbraucher(s):

                                Datum:

                                Unterschrift (nur bei Mitteilung auf Papier)

                                (*) Unzutreffendes streichen.",
                ],
            ],
        ],

        [
            'title' => 'Kontaktinformationen',
            'blocks' => [
                [
                    'type' => 'contact',
                    'text' => "
                        Henz Software Solutions
                        Christoph Henz
                        E-Mail (Kontakt): " . $contactEmail . "
                        Telefon: " . $contactPhone
                ],
            ],
        ],
    ],
];
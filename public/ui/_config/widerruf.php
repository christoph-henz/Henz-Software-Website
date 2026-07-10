<?php

declare(strict_types=1);

$legalContact = require __DIR__ . '/legal-contact.php';
$contactEmail = (string) ($legalContact['contact_email'] ?? 'info@henz-software.com');
$supportEmail = (string) ($legalContact['support_email'] ?? 'support@henz-software.com');
$contactPhone = (string) ($legalContact['contact_phone'] ?? 'Nicht verfügbar');

return [
    'page_title' => 'Widerrufsbelehrung – Henz Software Solutions',

    'hero' => [
        'tag' => 'Rechtliches',
        'title' => 'Widerrufsbelehrung',
        'intro' => 'Informationen zum gesetzlichen Widerrufsrecht für Dienstleistungsverträge mit Henz Software Solutions.',
    ],

    'sections' => [

        [
            'title' => 'Widerrufsrecht',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen.',
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
            ],
        ],

        [
            'title' => 'Vorzeitiges Erlöschen des Widerrufsrechts',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Bei Dienstleistungen erlischt das Widerrufsrecht vorzeitig, wenn die Dienstleistung vollständig erbracht wurde und mit der Ausführung der Dienstleistung erst begonnen wurde, nachdem Sie ausdrücklich zugestimmt haben, dass wir vor Ablauf der Widerrufsfrist mit der Ausführung beginnen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Sie bestätigen außerdem, dass Ihnen bekannt ist, dass Sie durch Ihre Zustimmung mit vollständiger Vertragserfüllung Ihr Widerrufsrecht verlieren.',
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
                    'text' => "Hiermit widerrufe ich den von mir abgeschlossenen Vertrag über die Erbringung der folgenden Dienstleistung:\n\nBestellt am:\nName:\nAnschrift:\n\nDatum:\nUnterschrift (nur bei Mitteilung auf Papier)",
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
                        E-Mail (Support): " . $supportEmail . "
                        Telefon: " . $contactPhone
                ],
            ],
        ],
    ],
];
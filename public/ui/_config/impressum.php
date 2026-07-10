<?php

declare(strict_types=1);

$legalContact = require __DIR__ . '/legal-contact.php';
$contactEmail = (string) ($legalContact['contact_email'] ?? 'info@getragen-begleiten.com');
$supportEmail = (string) ($legalContact['support_email'] ?? 'support@getragen-begleiten.com');
$contactPhone = (string) ($legalContact['contact_phone'] ?? 'Nicht verfügbar');

return [
    'page_title' => 'Impressum – Henz Software Solutions',

    'hero' => [
        'tag' => 'Rechtliches',
        'title' => 'Impressum',
        'intro' => 'Angaben gemäß § 5 DDG.',
    ],

    'sections' => [

        [
            'title' => 'Anbieter',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => "Henz Software Solutions\nChristoph Henz\nGüterberg 30a\n63739 Aschaffenburg",
                ],
            ],
        ],

        [
            'title' => 'Kontakt',
            'blocks' => [
                [
                    'type' => 'list',
                    'items' => [
                        'E-Mail: ' . $contactEmail,
                        'Technische Anfragen: ' . $supportEmail,
                        'Telefon: ' . $contactPhone,
                    ],
                ],
            ],
        ],

        [
            'title' => 'Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => "Christoph Henz\nGüterberg 30a\n63739 Aschaffenburg",
                ],
            ],
        ],

        [
            'title' => 'Technische Umsetzung und Betreuung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => "Christoph Henz\nE-Mail: {$supportEmail}\nTelefon: {$contactPhone}",
                ],
            ],
        ],
    ],
];
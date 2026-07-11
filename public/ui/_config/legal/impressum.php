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
            'title' => 'Diensteanbieter',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => "Henz Software Solutions\nInhaber: Christoph Henz\nGüterberg 30a\n63739 Aschaffenburg",
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
                        'Support: ' . $supportEmail,
                        'Telefon: ' . $contactPhone,
                    ],
                ],
            ],
        ],

        [
            'title' => 'Verantwortlich für den Inhalt',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => "Christoph Henz\nGüterberg 30a\n63739 Aschaffenburg",
                ],
            ],
        ],

        [
            'title' => 'Leistungen',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => "Entwicklung individueller Softwarelösungen, Webanwendungen, Websites sowie technische Beratung und Betreuung.",
                ],
            ],
        ],

    ],
];
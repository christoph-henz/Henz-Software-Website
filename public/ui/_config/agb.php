<?php

declare(strict_types=1);
 
$legalContact = require __DIR__ . '/legal-contact.php';
$contactEmail = (string) ($legalContact['contact_email'] ?? 'info@henz-software.com');
$supportEmail = (string) ($legalContact['support_email'] ?? 'support@henz-software.com');
$contactPhone = (string) ($legalContact['contact_phone'] ?? 'Nicht verfügbar');

return [
    'page_title' => 'AGB – Henz Software Solutions',

    'hero' => [
        'tag' => 'Rechtliches',
        'title' => 'Allgemeine Geschäftsbedingungen (AGB)',
        'intro' => 'Diese Allgemeinen Geschäftsbedingungen regeln die Nutzung der Leistungen von Henz Software Solutions.',
    ],

    'sections' => [

        [
            'title' => '1. Anbieter',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => "Anbieter der Leistungen ist:\n\nHenz Software Solutions\nChristoph Henz\nE-Mail: {$contactEmail}\nTelefon: {$contactPhone}",
                ],
                [
                    'type' => 'text',
                    'text' => "Technischer Betreiber der Website:\nChristoph Henz\nE-Mail: {$supportEmail}\nTelefon: {$contactPhone}",
                ],
            ],
        ],

        [
            'title' => '11. Verbraucherstreitbeilegung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Es besteht keine Verpflichtung und keine Bereitschaft zur Teilnahme an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle.',
                ],
            ],
        ],

        [
            'title' => '12. Schlussbestimmungen',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Es gilt das Recht der Bundesrepublik Deutschland unter Ausschluss des UN-Kaufrechts.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Sollten einzelne Bestimmungen dieser AGB unwirksam sein oder werden, bleibt die Wirksamkeit der übrigen Bestimmungen unberührt.',
                ],
            ],
        ],

    ],
];
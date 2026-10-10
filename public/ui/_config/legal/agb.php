<?php

declare(strict_types=1);

$legalContact = require __DIR__ . '/legal-contact.php';
$contactEmail = (string) ($legalContact['contact_email'] ?? 'info@henz-software.de');
$supportEmail = (string) ($legalContact['support_email'] ?? 'support@henz-software.de');
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
            'title' => '2. Geltungsbereich',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Diese Allgemeinen Geschäftsbedingungen gelten für sämtliche Verträge zwischen Henz Software Solutions und ihren Kunden über die Entwicklung, Bereitstellung, Wartung und Betreuung von Software, Websites sowie sonstigen IT-Dienstleistungen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Das Angebot von Henz Software Solutions richtet sich ausschließlich an Unternehmer im Sinne des § 14 BGB, juristische Personen des öffentlichen Rechts sowie öffentlich-rechtliche Sondervermögen.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Verträge mit Verbrauchern im Sinne des § 13 BGB werden grundsätzlich nicht geschlossen.',
                ],
            ],
        ],

        [
            'title' => '3. Vertragsgegenstand',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Art und Umfang der jeweiligen Leistungen ergeben sich aus dem individuellen Angebot, Vertrag oder der Leistungsbeschreibung. Änderungen und Erweiterungen bedürfen einer gesonderten Vereinbarung.',
                ],
            ],
        ],

        [
            'title' => '4. Vertragsschluss',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Ein Vertrag kommt durch schriftliche Annahme eines Angebots, eine Auftragsbestätigung oder den Abschluss eines individuellen Vertrags zustande.',
                ],
            ],
        ],

        [
            'title' => '5. Leistungen und Mitwirkungspflichten',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Der Kunde stellt Henz Software Solutions sämtliche zur Leistungserbringung erforderlichen Informationen, Zugangsdaten, Inhalte und Freigaben rechtzeitig zur Verfügung.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Verzögerungen, die durch fehlende Mitwirkung des Kunden entstehen, verlängern vereinbarte Fristen entsprechend.',
                ],
            ],
        ],

        [
            'title' => '6. Vergütung und Zahlungsbedingungen',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Vergütung, Zahlungsfristen sowie weitere wirtschaftliche Bedingungen ergeben sich ausschließlich aus dem jeweiligen Angebot oder Vertrag.',
                ],
            ],
        ],

        [
            'title' => '7. Abnahme',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Soweit eine Abnahme vereinbart ist, gilt eine Leistung als abgenommen, wenn der Kunde diese ausdrücklich bestätigt oder produktiv nutzt und innerhalb einer angemessenen Frist keine wesentlichen Mängel geltend macht.',
                ],
            ],
        ],

        [
            'title' => '8. Nutzungsrechte an der Software',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Soweit einzelvertraglich nichts Abweichendes vereinbart wurde, verbleiben sämtliche Urheber-, Eigentums- und Nutzungsrechte an entwickelter Software, Quellcodes, Frameworks, Bibliotheken, Entwicklungswerkzeugen, Konzepten sowie sonstigen technischen Komponenten bei Henz Software Solutions.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Der Kunde erhält ausschließlich die im jeweiligen Vertrag vereinbarten Nutzungsrechte. Eine Weitergabe, Vervielfältigung oder Bearbeitung ist nur im vereinbarten Umfang zulässig.',
                ],
            ],
        ],

        [
            'title' => '9. Open-Source-Komponenten',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Die entwickelte Software kann Komponenten enthalten, die unter Open-Source-Lizenzen veröffentlicht wurden.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Für diese Komponenten gelten ausschließlich die jeweiligen Lizenzbedingungen der entsprechenden Rechteinhaber. Die Rechte an diesen Komponenten verbleiben bei den jeweiligen Urhebern.',
                ],
            ],
        ],

        [
            'title' => '10. Datenhoheit',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Die vom Kunden im Rahmen der Nutzung erzeugten produktiven Daten verbleiben im Eigentum des Kunden.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Technische Analyse-, Diagnose-, Performance-, Sicherheits- und Protokolldaten können von Henz Software Solutions verarbeitet werden, soweit dies zur Bereitstellung, Fehleranalyse, Sicherheit oder Weiterentwicklung der Software erforderlich ist.',
                ],
            ],
        ],

        [
            'title' => '11. Datensicherung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Soweit vertraglich nichts anderes vereinbart wurde, ist der Kunde für die regelmäßige Sicherung seiner produktiven Daten verantwortlich.',
                ],
            ],
        ],

        [
            'title' => '12. Wartung und Support',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Wartungs-, Support- oder Hosting-Leistungen werden ausschließlich erbracht, sofern diese ausdrücklich Bestandteil des jeweiligen Vertrags sind.',
                ],
            ],
        ],

        [
            'title' => '13. Vertragsbeendigung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Nach Beendigung des Vertrags erhält der Kunde auf Wunsch sämtliche produktiven Daten in einem gängigen maschinenlesbaren Format.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Soweit Daten verschlüsselt gespeichert werden, werden die hierfür erforderlichen Schlüssel oder die Daten in entschlüsselter Form übergeben, sofern keine gesetzlichen Aufbewahrungspflichten entgegenstehen.',
                ],
            ],
        ],

        [
            'title' => '14. Inhalte des Kunden',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Für sämtliche Inhalte, Bilder, Texte, Dateien sowie sonstige Informationen, die auf einer Website oder innerhalb einer Software veröffentlicht oder verarbeitet werden, ist ausschließlich der Kunde verantwortlich.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Der Kunde stellt sicher, dass sämtliche Inhalte den geltenden gesetzlichen Bestimmungen entsprechen und keine Rechte Dritter verletzen.',
                ],
            ],
        ],

        [
            'title' => '15. Rechtliche Inhalte der Website',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Der Kunde ist für die inhaltliche Richtigkeit und Aktualität aller rechtlichen Informationen, insbesondere Impressum, Datenschutzerklärung, Allgemeine Geschäftsbedingungen sowie weiterer rechtlich erforderlicher Inhalte verantwortlich.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Änderungen rechtlicher oder unternehmensbezogener Angaben sind Henz Software Solutions unverzüglich mitzuteilen, sofern deren Umsetzung Bestandteil des Vertrags ist.',
                ],
            ],
        ],

        [
            'title' => '16. Haftung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Henz Software Solutions haftet unbeschränkt bei Vorsatz, grober Fahrlässigkeit sowie bei Schäden aus der Verletzung von Leben, Körper oder Gesundheit.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Bei leicht fahrlässiger Verletzung wesentlicher Vertragspflichten ist die Haftung auf den vertragstypischen vorhersehbaren Schaden begrenzt.',
                ],
                [
                    'type' => 'text',
                    'text' => 'Für Inhalte, Daten und rechtliche Angaben des Kunden übernimmt Henz Software Solutions keine Haftung.',
                ],
            ],
        ],

        [
            'title' => '17. Gewährleistung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Es gelten die gesetzlichen Gewährleistungsvorschriften, soweit einzelvertraglich nichts Abweichendes vereinbart wurde.',
                ],
            ],
        ],

        [
            'title' => '18. Höhere Gewalt',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Für Verzögerungen oder Leistungsausfälle aufgrund höherer Gewalt oder sonstiger unvorhersehbarer Ereignisse außerhalb des Einflussbereichs von Henz Software Solutions wird keine Haftung übernommen.',
                ],
            ],
        ],

        [
            'title' => '19. Vertraulichkeit',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Beide Vertragsparteien verpflichten sich, sämtliche im Rahmen der Zusammenarbeit bekannt gewordenen vertraulichen Informationen ausschließlich zur Durchführung des Vertrags zu verwenden und gegenüber Dritten geheim zu halten, soweit keine gesetzliche Offenlegungspflicht besteht.',
                ],
            ],
        ],

        [
            'title' => '20. Referenznennung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Henz Software Solutions ist berechtigt, abgeschlossene Projekte nach vorheriger Zustimmung des Kunden als Referenz auf der eigenen Website oder in sonstigen Werbematerialien zu nennen.',
                ],
            ],
        ],

        [
            'title' => '21. Verbraucherstreitbeilegung',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => 'Es besteht keine Verpflichtung und keine Bereitschaft zur Teilnahme an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle.',
                ],
            ],
        ],

        [
            'title' => '22. Schlussbestimmungen',
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
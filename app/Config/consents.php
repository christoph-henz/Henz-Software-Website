<?php

declare(strict_types=1);

/**
 * Kanonische Consent-Texte pro Version.
 *
 * Der gesendete consent_text_snapshot wird gegen diese Texte geprüft.
 * Bei Textänderungen MUSS die Versionsnummer erhöht werden.
 * Alte Versionen bleiben für Audit-Zwecke erhalten.
 */
return [
    'required_keys' => ['privacy_policy'],

    'versions' => [
        '1.0' => [
            'privacy_policy' => 'Ich habe die Datenschutzerklärung gelesen und stimme der Verarbeitung meiner personenbezogenen Daten zum Zwecke der Kontaktaufnahme und Terminvermittlung zu.',
            ],
    ],
];

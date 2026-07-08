<?php

declare(strict_types=1);

$code = 402;
$title = 'Zahlung erforderlich';
$message = 'Diese Funktion ist nur nach erfolgreicher Zahlung verfügbar.';
$hints = [
    'Prüfe den Rechnungsstatus deines Kontos.',
    'Aktualisiere hinterlegte Zahlungsdaten.',
    'Versuche den Vorgang nach dem Zahlungseingang erneut.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

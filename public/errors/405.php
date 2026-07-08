<?php

declare(strict_types=1);

$code = 405;
$title = 'Methode nicht erlaubt';
$message = 'Die angeforderte HTTP-Methode ist für diese Ressource nicht zulässig.';
$hints = [
    'Prüfe, ob GET, POST, PUT, PATCH oder DELETE erwartet wird.',
    'Vergleiche die API-Dokumentation für den Endpoint.',
    'Sende die Anfrage mit der korrekten Methode erneut.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

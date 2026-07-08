<?php

declare(strict_types=1);

$code = 408;
$title = 'Zeitüberschreitung';
$message = 'Die Anfrage hat zu lange gedauert und wurde beendet.';
$hints = [
    'Prüfe deine Netzwerkverbindung.',
    'Reduziere große Uploads oder Datenmengen.',
    'Wiederhole die Anfrage in wenigen Sekunden.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

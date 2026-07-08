<?php

declare(strict_types=1);

$code = 404;
$title = 'Seite nicht gefunden';
$message = 'Die angeforderte Seite existiert nicht oder wurde verschoben.';
$hints = [
    'Prüfe die URL auf Schreibfehler.',
    'Nutze die Navigation, um einen gültigen Bereich zu finden.',
    'Starte ggf. auf der Startseite neu.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

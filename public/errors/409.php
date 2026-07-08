<?php

declare(strict_types=1);

$code = 409;
$title = 'Konflikt';
$message = 'Die Anfrage steht im Konflikt mit dem aktuellen Zustand der Ressource.';
$hints = [
    'Aktualisiere zuerst die betroffenen Daten.',
    'Prüfe auf doppelte Einträge oder Versionskonflikte.',
    'Sende den Vorgang anschließend erneut.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

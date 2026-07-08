<?php

declare(strict_types=1);

$code = 410;
$title = 'Inhalt nicht mehr verfügbar';
$message = 'Die Ressource wurde dauerhaft entfernt.';
$hints = [
    'Nutze aktuelle Links aus Navigation oder Sitemap.',
    'Suche nach einer Nachfolge-Seite zum Inhalt.',
    'Kontaktiere uns, falls der Inhalt wiederhergestellt werden soll.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

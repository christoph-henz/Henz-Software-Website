<?php

declare(strict_types=1);

$code = 451;
$title = 'Inhalt rechtlich nicht verfügbar';
$message = 'Die angeforderte Ressource ist aus rechtlichen Gründen nicht verfügbar.';
$hints = [
    'Prüfe regionale oder rechtliche Einschränkungen.',
    'Kontaktiere den Betreiber für weitere Details.',
    'Nutze, falls vorhanden, alternative Inhalte.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

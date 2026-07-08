<?php

declare(strict_types=1);

$code = 403;
$title = 'Zugriff verweigert';
$message = 'Du hast keine Berechtigung, diese Seite aufzurufen.';
$hints = [
    'Prüfe deine Rolle und Berechtigungen.',
    'Melde dich mit einem berechtigten Konto an.',
    'Wenn das ein Fehler ist, kontaktiere den Administrator.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

<?php

declare(strict_types=1);

$code = 401;
$title = 'Nicht autorisiert';
$message = 'Für diese Ressource ist eine gültige Anmeldung erforderlich.';
$hints = [
    'Melde dich mit einem gültigen Konto an.',
    'Erneuere ein abgelaufenes Login oder Access-Token.',
    'Kontaktiere den Support bei unerwarteter Sperre.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

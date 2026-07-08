<?php

declare(strict_types=1);

$code = 400;
$title = 'Ungültige Anfrage';
$message = 'Die Anfrage konnte vom Server nicht verstanden werden.';
$hints = [
    'Prüfe die URL auf Tippfehler.',
    'Kontrolliere übermittelte Formularfelder.',
    'Sende die Anfrage anschließend erneut.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

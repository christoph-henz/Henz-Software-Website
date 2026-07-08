<?php

declare(strict_types=1);

$code = 418;
$httpStatus = 400;
$title = 'Ich bin eine Teekanne';
$message = 'Die Anfrage war technisch gültig, aber absichtlich nicht ausführbar.';
$hints = [
    'Verwende einen regulären API- oder Browser-Request.',
    'Prüfe Testscripte auf Sonderfälle.',
    'Nutze bei Bedarf einen produktiven Endpoint.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

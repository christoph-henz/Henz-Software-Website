<?php

declare(strict_types=1);

$code = 429;
$title = 'Zu viele Anfragen';
$message = 'Es wurden in kurzer Zeit zu viele Requests gesendet.';
$hints = [
    'Warte kurz und versuche es erneut.',
    'Implementiere Exponential Backoff in API-Clients.',
    'Verringere parallele Requests pro Benutzer.',
];

require __DIR__ . '/../ui/_templates/error-page.php';

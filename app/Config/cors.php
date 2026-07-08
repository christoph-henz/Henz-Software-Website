<?php

declare(strict_types=1);

return [
    'allowed_origins' => array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))),
    'allowed_methods' => array_map('trim', explode(',', (string) env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS'))),
    'allowed_headers' => array_map('trim', explode(',', (string) env('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization'))),
];

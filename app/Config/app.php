<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Application'),
    'env' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'log_channel' => env('LOG_CHANNEL', 'file'),
    'log_level' => env('LOG_LEVEL', 'debug'),
    'log_path' => base_path((string) env('LOG_PATH', 'storage/logs/app.log')),
];

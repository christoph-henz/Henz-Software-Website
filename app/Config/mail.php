<?php

declare(strict_types=1);

return [
    'provider' => env('MAIL_PROVIDER', 'strato'),
    'transport' => env('MAIL_TRANSPORT', 'smtp'),
    'embed_inline_images' => filter_var(env('MAIL_EMBED_INLINE_IMAGES', false), FILTER_VALIDATE_BOOL),
    'automation' => [
        // Default-off in all environments; automation is enabled explicitly.
        'enabled' => filter_var(env('EMAIL_AUTOMATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    /*'payment' => [
        'automation_enabled' => filter_var(env('PAYMENT_AUTOMATION_ENABLED', false), FILTER_VALIDATE_BOOL),
        'default_due_days' => (int) env('PAYMENT_INVOICE_DUE_DAYS', 7),
        'currency' => 'EUR',
    ],*/
    'smtp' => [
        'host' => env('MAIL_HOST', ''),
        'port' => (int) env('MAIL_PORT', 587),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'username' => env('MAIL_USERNAME', ''),
        'password' => env('MAIL_PASSWORD', ''),
        'timeout_seconds' => (int) env('MAIL_TIMEOUT_SECONDS', 10),
    ],
    'senders' => [
        'communication' => [
            'address' => env('MAIL_FROM_COMMUNICATION', 'info@henz-software.de'),
            'name' => env('MAIL_FROM_COMMUNICATION_NAME', 'Henz Software Solutions'),
        ],
        'noreply' => [
            'address' => env('MAIL_FROM_NOREPLY', 'noreply@henz-software.de'),
            'name' => env('MAIL_FROM_NOREPLY_NAME', 'Henz Software Solutions'),
        ],
        'support' => [
            'address' => env('MAIL_FROM_SUPPORT', 'support@henz-software.de'),
            'name' => env('MAIL_FROM_SUPPORT_NAME', 'Henz Software Solutions Support'),
        ],
    ],
    'logging' => [
        // PII-free logging is required: refer only to technical IDs.
        'pii_free' => true,
        'channel' => env('MAIL_LOG_CHANNEL', 'app'),
    ],
];
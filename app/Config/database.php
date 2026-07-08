<?php

declare(strict_types=1);

return [
    'default' => env('DB_DEFAULT', 'mysql'),
    'connections' => [
        'production' => [
            'driver' => 'mysql',
            'host' => env('DB1_HOST', '127.0.0.1'),
            'port' => (int) env('DB1_PORT', 3306),
            'database' => env('DB1_DATABASE', ''),
            'username' => env('DB1_USERNAME', ''),
            'password' => env('DB1_PASSWORD', ''),
            'charset' => env('DB1_CHARSET', 'utf8mb4'),
            'collation' => env('DB1_COLLATION', 'utf8mb4_unicode_ci'),
        ],
        'logging' => [
            'driver' => 'mysql',
            'host' => env('DB2_HOST', '127.0.0.1'),
            'port' => (int) env('DB2_PORT', 3306),
            'database' => env('DB2_DATABASE', ''),
            'username' => env('DB2_USERNAME', ''),
            'password' => env('DB2_PASSWORD', ''),
            'charset' => env('DB2_CHARSET', 'utf8mb4'),
            'collation' => env('DB2_COLLATION', 'utf8mb4_unicode_ci'),
        ],
    ],
];

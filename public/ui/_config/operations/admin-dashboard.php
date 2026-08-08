<?php

declare(strict_types=1);

return [
    'default_view' => 'week',
    'allowed_views' => ['month', 'week'],
    'default_page' => 1,
    'per_page' => 100,
    'default_sort' => 'scheduled_at',
    'default_direction' => 'asc',
    'refresh_ms' => 30000,
    'opening_start_hour' => 9,
    'opening_end_hour' => 18,
    'slot_step_minutes' => 30,
    'api' => [
        'bookings' => '/dashboard/bookings',
        'blocked_slots' => '/bookings/data/blocked',
        'delete_blocked' => '/bookings/data/blocked/{id}',
    ],
    'timezone' => 'Europe/Berlin',
    'status_labels' => [
        'pending' => 'Ausstehend',
        'confirmed' => 'Bestätigt',
        'completed' => 'Abgeschlossen',
        'cancelled' => 'Storniert',
    ],
    'payment_status_labels' => [
        'pending' => 'Zahlung ausstehend',
        'paid' => 'Bezahlt',
        'refunded' => 'Erstattet',
    ],
];

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
        'appointments' => '/appointments/data',
        'blocked_slots' => '/appointments/data/blocked',
        'delete_blocked' => '/appointments/data/blocked/{id}',
        'meta' => '/appointments/data/meta',
        'show' => '/appointments/data/{id}',
        'store' => '/appointments/data',
        'update' => '/appointments/data/{id}',
        'reschedule' => '/appointments/data/{id}/reschedule',
        'cancel' => '/appointments/data/{id}/cancel',
    ],
    'timezone' => 'Europe/Berlin',
    'status_labels' => [
        'pending' => 'Ausstehend',
        'accepted' => 'Angenommen',
        'declined' => 'Abgelehnt',
        'completed' => 'Abgeschlossen',
        'storno' => 'Storniert',
    ],
    'payment_status_labels' => [
        'pending' => 'Zahlung ausstehend',
        'paid' => 'Bezahlt',
        'refunded' => 'Erstattet',
    ],
];

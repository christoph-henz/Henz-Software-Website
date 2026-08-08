<?php

declare(strict_types=1);

return [
    'can_view_availability' => false,
    'can_manage_availability' => false,
    'api' => [
        'overview' => '/availability/data',
        'rules' => '/availability/data/rules',
        'recurring' => '/availability/data/recurring',
        'blocked' => [
            'create' => '/availability/data/blocked',
            'delete' => '/availability/data/blocked/{id}',
        ],
    ],
];

<?php

declare(strict_types=1);

return [
    'can_manage_services' => false,
    'initial_service_id' => null,
    'api' => [
        'services' => [
            'list' => '/services/data',
            'detail' => '/services/data/{id}',
            'create' => '/services/data',
            'update' => '/services/data/{id}',
        ],
        'packages' => [
            'list' => '/packages/data',
            'detail' => '/packages/data/{id}',
            'create' => '/packages/data',
            'update' => '/packages/data/{id}',
        ],
    ],
];

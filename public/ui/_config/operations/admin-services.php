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
        'referenced_projects' => [
            'list' => '/referenced-projects/data',
            'detail' => '/referenced-projects/data/{id}',
            'create' => '/referenced-projects/data',
            'update' => '/referenced-projects/data/{id}',
        ],
    ],
];

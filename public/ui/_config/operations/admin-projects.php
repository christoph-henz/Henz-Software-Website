<?php

declare(strict_types=1);

return [
    'data_url'                   => '/projects/data',
    'clients_data_url'           => '/projects/data/clients',
    'per_page'                   => 20,
    'can_manage'                 => false, // overridden by ProjectsPageController
    'current_role_mask'          => 0,
    'can_manage_settings'        => false,
    'can_manage_admin_settings'  => false,
    'permission_catalog'         => [],
];
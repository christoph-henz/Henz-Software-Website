<?php

declare(strict_types=1);

return [
    'data_url'                   => '/users/data',
    'per_page'                   => 20,
    'can_manage'                 => false, // overridden by UsersPageController
    'current_role_mask'          => 0,
    'can_manage_settings'        => false,
    'can_manage_admin_settings'  => false,
    'permission_catalog'         => [],
];

<?php

declare(strict_types=1);

return [
    'login_path' => '/login',
    'dashboard_path' => '/operations/calender',
    'subdomain' => (string) env('OPERATIONS_SUBDOMAIN', 'operations'),
    'enforce_subdomain' => filter_var(env('OPERATIONS_ENFORCE_SUBDOMAIN', true), FILTER_VALIDATE_BOOL),
    'session_key' => 'operations_user',
    'required_role_mask' => (int) env('OPERATIONS_REQUIRED_ROLE_MASK', 3072),
    'password_hash' => (string) env('OPERATIONS_LOGIN_PASSWORD_HASH', ''),
];
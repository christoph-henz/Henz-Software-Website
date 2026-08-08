<?php

declare(strict_types=1);

$operations = require __DIR__ . '/operations.php';

return [
    'login_path' => (string) ($operations['login_path'] ?? '/login'),
    'dashboard_path' => (string) ($operations['dashboard_path'] ?? '/dashboard'),
    'session_key' => (string) ($operations['session_key'] ?? 'operations_user'),
    'password_hash' => (string) ($operations['password_hash'] ?? ''),
    'required_role_mask' => (int) ($operations['required_role_mask'] ?? 3072),
];
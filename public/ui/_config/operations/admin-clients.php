<?php

declare(strict_types=1);

$attachmentMaxBytes = 5 * 1024 * 1024;
try {
    $row = db('settings')
        ->where('`key`', 'media_max_file_size')
        ->select(['value'])
        ->first();

    $rawValue = (string) ($row['value'] ?? '');
    if (is_numeric($rawValue)) {
        $maxMb = (int) $rawValue;
        if ($maxMb < 1) {
            $maxMb = 5;
        }
        $attachmentMaxBytes = min($maxMb, 5120) * 1024 * 1024;
    }
} catch (\Throwable) {
    $attachmentMaxBytes = 5 * 1024 * 1024;
}

return [
    'default_sort' => 'last_name',
    'default_direction' => 'asc',
    'default_page' => 1,
    'default_per_page_desktop' => 20,
    'default_per_page_mobile' => 10,
    'can_view_clients' => false,
    'can_manage_clients' => false,
    'can_use_form_templates_for_clients' => false,
    'can_view_projects' => false,
    'initial_client_id' => null,
    'initial_packages_open' => false,
    'initial_invoices_open' => false,
    'session_record_attachment_max_bytes' => $attachmentMaxBytes,
    'session_record_attachment_chunk_size_bytes' => 500 * 1024,
    'api' => [
        'list' => '/clients/data',
        'create' => '/clients/data',
        'validate_email' => '/clients/data/validate-email',
        'detail' => '/clients/data/{id}',
        'update' => '/clients/data/{id}',
        'history' => '/clients/data/{id}/history',
        'consents' => '/clients/data/{id}/consents',
        'packages' => '/clients/data/{id}/packages',
        'invoices' => '/clients/data/{id}/invoices',
        'project_detail' => '/projects/data/{id}',
        'project_phases' => '/projects/data/{id}/phases',
        'project_members' => '/projects/data/{id}/members',
    ],
];

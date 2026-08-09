<?php

declare(strict_types=1);

$attachmentMaxBytes = 5 * 1024 * 1024;
$paymentSettings = [
    'bank_data_name' => '',
    'bank_data_iban' => '',
    'bank_data_bic' => '',
];

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

try {
    foreach (array_keys($paymentSettings) as $settingKey) {
        $row = db('settings')
            ->where('`key`', $settingKey)
            ->select(['value'])
            ->first();

        $paymentSettings[$settingKey] = trim((string) ($row['value'] ?? ''));
    }
} catch (\Throwable) {
    $paymentSettings = [
        'bank_data_name' => '',
        'bank_data_iban' => '',
        'bank_data_bic' => '',
    ];
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
    'payment_settings' => $paymentSettings,
    'api' => [
        'list' => '/clients/data',
        'create' => '/clients/data',
        'validate_email' => '/clients/data/validate-email',
        'detail' => '/clients/data/{id}',
        'update' => '/clients/data/{id}',
        'history' => '/clients/data/{id}/history',
        'appointments' => '/clients/data/{id}/appointments',
        'consents' => '/clients/data/{id}/consents',
        'tickets' => '/clients/data/{id}/tickets',
        'tickets_list' => '/tickets/data',
        'ticket_detail' => '/tickets/data/{ticket_id}',
        'ticket_update' => '/tickets/data/{ticket_id}',
        'ticket_protocol_create' => '/tickets/data/{ticket_id}/protocols',
        'packages' => '/clients/data/{id}/packages',
        'contracts' => '/clients/data/{id}/contracts',
        'contracts_create' => '/clients/data/{id}/contracts',
        'contracts_upload' => '/clients/data/{id}/contracts/upload',
        'contract_update' => '/clients/data/{id}/contracts/{contract_id}',
        'contract_download' => '/clients/data/{id}/contracts/{contract_id}/download',
        'invoices' => '/clients/data/{id}/invoices',
        'invoices_create' => '/clients/data/{id}/invoices',
        'project_detail' => '/projects/data/{id}',
        'project_phases' => '/projects/data/{id}/phases',
        'project_members' => '/projects/data/{id}/members',
    ],
];

<?php

declare(strict_types=1);

$defaults = [
    'contact_email' => 'info@henz-software.com',
    'support_email' => 'support@henz-software.com',
    'contact_phone' => 'Nicht verfügbar',
];

try {
    foreach (['contact_email', 'support_email', 'contact_phone'] as $settingKey) {
        $row = db('settings')
            ->where('`key`', $settingKey)
            ->select(['value'])
            ->first();

        $value = trim((string) ($row['value'] ?? ''));
        if ($value !== '') {
            $defaults[$settingKey] = $value;
        }
    }
} catch (\Throwable) {
    // Keep defaults if settings table is unavailable.
}

return $defaults;

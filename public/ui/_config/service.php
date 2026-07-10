<?php

declare(strict_types=1);

/**
 * Configuration for "Angebot in Kürze" section
 * Loads services from the database
 */

$services = [];
try {
    $services = db('henz_software_main.services')
        ->where('is_active', true)
        ->select(['id','slug','icon_path','name','description','cta_url'])
        ->orderBy('sort_order', 'asc')
        ->get();
} catch (Exception $e) {
    // Fallback if DB is not available
}

$rows = [];
foreach ($services as $service) {
    $rows[] = [
        'id' => $service['id'] ?? null,
        'slug' => $service['slug'] ?? '',
        'icon_path' => $service['icon_path'] ?? '',
        'name' => $service['name'] ?? '',
        'description' => $service['description'] ?? '',
        'cta_url' => $service['cta_url'] ?? '#',
    ];
}

return [
    'slug' => 'services',
    'header1' => 'Was wir',
    'header2' => 'machen',
    'tag' => 'Angebot in Kürze',
    'title' => 'Meine Begleitungsangebote',
    'rows' => $rows,
    'cta_text' => 'Mehr erfahren',
];

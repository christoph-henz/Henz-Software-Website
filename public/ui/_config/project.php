<?php

declare(strict_types=1);

/**
 * Configuration for "Angebot in Kürze" section
 * Loads services from the database
 */

$services = [];
try {
    $services = db('henz_software_main.referenced_projects')
        ->where('is_active', true)
        ->select(['id','slug','title','description','project_image_path','project_slug','project_url'])
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
        'title' => $service['title'] ?? '',
        'description' => $service['description'] ?? '',
        'project_media_path' => $service['project_image_path'] ?? '',
        'project_slug' => $service['project_slug'] ?? '',
        'project_url' => $service['project_url'] ?? '#',
    ];
}

return [
    'slug' => 'unsere projekte',
    'header1' => 'In die Produktion',
    'header2' => 'integriert',
    'projects_cta' => ['text' => 'Alle Projekte ansehen', 'url' => '/projekte'],
    'rows' => $rows,
    'cta_text' => 'Projekte ansehen',
];

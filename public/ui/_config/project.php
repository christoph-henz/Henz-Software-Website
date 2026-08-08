<?php

declare(strict_types=1);

/**
 * Configuration for "Angebot in Kürze" section
 * Loads services from the database
 */

$projects = project_page_entries();

$rows = [];
foreach ($projects as $project) {
    $rows[] = [
        'id' => $project['id'] ?? null,
        'slug' => $project['slug'] ?? '',
        'title' => $project['title'] ?? '',
        'description' => $project['description'] ?? '',
        'project_media_path' => $project['project_media_path'] ?? '',
        'project_slug' => $project['route_slug'] ?? '',
        'project_url' => $project['project_url'] ?? '#',
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

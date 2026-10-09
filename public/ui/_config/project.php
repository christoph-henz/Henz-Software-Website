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
    'slug' => 'Referenzen',
    'header1' => 'Unsere',
    'header2' => 'Projekte',
    'projects_cta' => ['text' => 'Alle Projekte ansehen', 'url' => '/projekte'],
    'rows' => $rows,
    'cta_text' => 'Projekt ansehen',
];

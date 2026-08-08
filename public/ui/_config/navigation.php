<?php

declare(strict_types=1);

$referenced_projects=[];

try {
    $referenced_projects = db('referenced_projects')->
        where('is_active', true)->
        select(['slug','project_slug'])->
        orderBy('sort_order', 'ASC')->
        get();
} catch (\Exception $e) {
    // Fallback if DB is not available
    var_dump('Error fetching referenced projects: ' . $e->getMessage());
    die();
}

$rows = [];
foreach ($referenced_projects as $navProject) {
    $rows[] = [
        'name' => $navProject['slug'] ?? '',
        'slug' => $navProject['project_slug'] ?? '',
    ];
}   



return [
    [
        'label' => 'Leistungen',
        'href' => '/leistungen',
    ],
    empty($rows) ? [] : [
        'label' => 'Referenzen',
        'href' => '/#',
        'children' => array_map(function ($row) {
            return [
                'label' => $row['name'],
                'href' => "/{$row['slug']}",
            ];
        }, $rows),
    ],
    [
        'label' => 'Technologien',
        'href' => '/technologien',
    ],
    [
        'label' => 'Kontakt',
        'href' => '/kontakt',
    ],
];


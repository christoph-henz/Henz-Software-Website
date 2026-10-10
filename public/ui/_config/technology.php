<?php

declare(strict_types=1);

$fallbackRows = [
    ['label' => 'C#', 'category' => '.NET', 'description' => 'Objektorientierte Sprache für robuste Business- und API-Logik.'],
    ['label' => '.NET MAUI', 'category' => '.NET', 'description' => 'Cross-Platform Apps mit gemeinsamer Codebasis für Desktop und Mobile.'],
    ['label' => '.NET Blazor', 'category' => '.NET', 'description' => 'Interaktive Web-UIs mit C# und komponentenbasiertem Ansatz.'],
    ['label' => '.NET WPF', 'category' => '.NET', 'description' => 'Desktop-Oberflaechen mit starker Datenbindung für interne Tools.'],
    ['label' => 'PHP', 'category' => 'Backend', 'description' => 'Serverlogik und API-Entwicklung für webbasierte Anwendungen.'],
    ['label' => 'JavaScript', 'category' => 'Frontend', 'description' => 'Dynamische Interaktionen und datengetriebene Oberflaechen im Browser.'],
    ['label' => 'HTML', 'category' => 'Frontend', 'description' => 'Semantische Basisstruktur für zugaengliche Weboberflaechen.'],
    ['label' => 'Tailwind CSS', 'category' => 'Frontend', 'description' => 'Utility-First Styling für schnelle, konsistente UI-Entwicklung.'],
    ['label' => 'Python', 'category' => 'Backend', 'description' => 'Automatisierung, Skripting und datennahe Services.'],
    ['label' => 'React', 'category' => 'Frontend', 'description' => 'Komponentenbasierte Entwicklung moderner Webanwendungen.'],
    ['label' => 'Next.js', 'category' => 'Frontend', 'description' => 'Framework für SSR, Routing und performante Web-Experiences.'],
    ['label' => 'PostgreSQL', 'category' => 'Database', 'description' => 'Relationale Datenbank für strukturierte, transaktionale Datenmodelle.'],
];

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
$normalizeRows = static function (array $rows): array {
    $normalized = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $isActive = $row['is_active'] ?? $row['active'] ?? null;
        if ($isActive !== null && (int) $isActive === 0) {
            continue;
        }

        $label = trim((string) (
            $row['name']
            ?? $row['title']
            ?? $row['label']
            ?? $row['technology']
            ?? $row['slug']
            ?? ''
        ));

        if ($label === '') {
            continue;
        }

        $category = trim((string) (
            $row['category']
            ?? $row['group_name']
            ?? $row['group']
            ?? $row['type']
            ?? 'Weitere'
        ));

        if ($category === '') {
            $category = 'Weitere';
        }

        $level = trim((string) (
            $row['level']
            ?? $row['expertise_level']
            ?? $row['proficiency']
            ?? ''
        ));

        $normalized[] = [
            'label' => $label,
            'category' => $category,
            'level' => $level,
            'description' => trim((string) (
                $row['description']
                ?? $row['details']
                ?? $row['summary']
                ?? $row['text']
                ?? ''
            )),
            'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : PHP_INT_MAX,
        ];
    }

    usort($normalized, static function (array $left, array $right): int {
        $leftOrder = (int) ($left['sort_order'] ?? PHP_INT_MAX);
        $rightOrder = (int) ($right['sort_order'] ?? PHP_INT_MAX);

        if ($leftOrder !== $rightOrder) {
            return $leftOrder <=> $rightOrder;
        }

        $categoryCompare = strcmp((string) ($left['category'] ?? ''), (string) ($right['category'] ?? ''));
        if ($categoryCompare !== 0) {
            return $categoryCompare;
        }

        return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    return $normalized;
};

$rows = [];

try {
    $dbRows = db('technology')->select(['*'])->get();
    if (is_array($dbRows)) {
        $rows = $normalizeRows($dbRows);
    }
} catch (Throwable) {
    $rows = [];
}

if ($rows === []) {
    $rows = $normalizeRows($fallbackRows);
}

$groups = [];
foreach ($rows as $row) {
    $category = (string) ($row['category'] ?? 'Weitere');
    $label = (string) ($row['label'] ?? '');
    $description = trim((string) ($row['description'] ?? ''));
    $level = (string) ($row['level'] ?? '');

    if ($description === '' && $label !== '') {
        $description = $label . ' wird im Bereich ' . $category . ' eingesetzt';

        if ($level !== '') {
            $description .= ' (' . $level . ')';
        }

        $description .= '.';
    }

    if (!isset($groups[$category])) {
        $groups[$category] = [
            'name' => $category,
            'items' => [],
        ];
    }

    $groups[$category]['items'][] = [
        'label' => $label,
        'level' => $level,
        'description' => $description,
    ];
}

$groupList = array_values($groups);

usort($groupList, static function (array $left, array $right): int {
    return count((array) ($right['items'] ?? [])) <=> count((array) ($left['items'] ?? []));
});

$highlights = [];
foreach (array_slice($groupList, 0, 3) as $group) {
    $highlights[] = sprintf('%s (%d)', (string) ($group['name'] ?? 'Weitere'), count((array) ($group['items'] ?? [])));
}

$technologies = [];
foreach ($groupList as $group) {
    $groupName = (string) ($group['name'] ?? 'Weitere');
    $items = is_array($group['items'] ?? null) ? $group['items'] : [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = trim((string) ($item['label'] ?? ''));
        if ($label === '') {
            continue;
        }

        $technologies[] = [
            'label' => $label,
            'category' => $groupName,
            'level' => trim((string) ($item['level'] ?? '')),
            'description' => trim((string) ($item['description'] ?? '')),
        ];
    }
}

$items = [];
foreach ($technologies as $technology) {
    $items[] = (string) ($technology['label'] ?? '');
}

return [
    'slug' => 'Tech Stack',
    'title' => 'Technologien im Einsatz',
    'intro' => 'Wir setzen auf moderne Technologien, um robuste und skalierbare Softwarelösungen zu entwickeln. Unser Technologie-Stack umfasst eine Vielzahl von Programmiersprachen, Frameworks und Tools, die es uns ermöglichen, maßgeschneiderte Anwendungen für unterschiedliche Anforderungen zu erstellen.',
    'groups' => $groupList,
    'technologies' => $technologies,
    'highlights' => $highlights,
    'total' => count($rows),
    'items' => $items,
];
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$service = [
    'structure' => [
        ['type' => 'intro', 'title' => 'title'],
        ['type' => 'split_panel', 'title' => 'title'],
        ['type' => 'stats', 'items' => 'stats'],
        ['type' => 'feature_grid', 'items' => 'features'],
    ],
    'data' => [
        'title' => 'Reference layout',
        'stats' => [['value' => '100', 'label' => 'Test']],
        'features' => [['title' => 'Feature', 'description' => 'Test']],
    ],
];

ob_start();
require base_path('public/ui/_partials/_service-section-renderer.php');
$html = (string) ob_get_clean();
$dom = new DOMDocument();
@$dom->loadHTML($html);
$images = $dom->getElementsByTagName('img');
if ($images->length !== 2) {
    throw new RuntimeException('Expected intro and split-panel images');
}
foreach ($images as $image) {
    $classes = $image->getAttribute('class');
    if (!str_contains($classes, 'gb-reference-media') && !str_contains($classes, 'gb-split-panel-image')) {
        throw new RuntimeException('Image must use aspect-ratio-preserving styles');
    }
    if (str_contains($classes, 'object-cover') || str_contains($classes, 'h-full')) {
        throw new RuntimeException('Section images must not be cropped or stretched');
    }
}

$overview = file_get_contents(base_path('public/ui/_partials/project-section.php'));
if (substr_count($overview, 'class="gb-reference-media w-full h-full') !== 2) {
    throw new RuntimeException('Overview images and videos must both preserve their aspect ratio');
}

$css = file_get_contents(base_path('public/ui/_assets/css/theme.css'));
foreach (['gb-reference-media', 'gb-split-panel-image'] as $class) {
    if (!preg_match('/\.' . $class . '\s*\{[^}]*object-fit:\s*contain;/s', $css)) {
        throw new RuntimeException($class . ' must use object-fit: contain');
    }
}
echo "Reference image and video layout checks passed.\n";

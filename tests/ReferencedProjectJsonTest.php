<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\Api\V1\Admin\ServiceAdminController;
use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Logging\Logger;

$controller = new ServiceAdminController();
$normalize = new ReflectionMethod($controller, 'normalizeJsonPayload');
$format = new ReflectionMethod($controller, 'formatReferencedProjectRow');
$checks = 0;

$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

foreach ([
    ['[]', true],
    ['[{"type":"hero","options":{}}]', true],
    ['{}', false],
    ['{"0":"zero","nested":{},"items":[]}', false],
    ['{"title":"Grüße","html":"</textarea>"}', false],
    ['[]', false],
] as [$json, $requireList]) {
    $errors = [];
    $saved = $normalize->invokeArgs($controller, [$json, $requireList, 'json', &$errors]);
    $check($errors === [], 'Valid JSON must be accepted: ' . $json);
    $check(
        json_encode(json_decode($saved)) === json_encode(json_decode($json)),
        'JSON shapes must survive normalization: ' . $json
    );

    $row = $format->invoke(null, [
        'structure' => $requireList ? $saved : '[]',
        'data' => $requireList ? '{}' : $saved,
    ]);
    $field = $requireList ? 'structure' : 'data';
    $check(
        json_encode($row[$field]) === json_encode(json_decode($json)),
        'JSON shapes must survive the API response: ' . $json
    );
}

foreach ([
    ['{', false, 'invalid_json'],
    ['null', false, 'invalid_json'],
    ['true', false, 'invalid_json'],
    ['"text"', false, 'invalid_json'],
    ['1', false, 'invalid_json'],
    ['{}', true, 'invalid_shape'],
    ['{"0":"not a list"}', true, 'invalid_shape'],
] as [$json, $requireList, $expected]) {
    $errors = [];
    $normalize->invokeArgs($controller, [$json, $requireList, 'json', &$errors]);
    $check(($errors['json'][0] ?? null) === $expected, 'Invalid JSON must be rejected: ' . $json);
}

$errors = [];
$saved = $normalize->invokeArgs($controller, [[['type' => 'hero']], true, 'structure', &$errors]);
$check($errors === [] && $saved === '[{"type":"hero"}]', 'Existing array payloads must remain supported');

try {
    $format->invoke(null, ['structure' => '[', 'data' => '{}']);
    throw new RuntimeException('Corrupt stored JSON must not be silently replaced');
} catch (JsonException) {
    $checks++;
}

$logFile = tempnam(sys_get_temp_dir(), 'reference-preview-test-');
$_app_container = new Container();
$_app_container->bind(Logger::class, static fn (): Logger => new Logger($logFile));
session_start();

try {
    $request = static fn (array $payload): Request => new Request(
        [], [], [], [], [], ['REQUEST_METHOD' => 'POST'], json_encode($payload, JSON_THROW_ON_ERROR)
    );
    $_SESSION['admin_user'] = ['role_mask' => 0];
    $response = $controller->previewReferencedProject($request(['structure' => '[]', 'data' => '{}']));
    $check($response->statusCode() === 403, 'Preview must enforce permissions');

    $_SESSION['admin_user'] = ['role_mask' => 8192];
    $response = $controller->previewReferencedProject($request(['structure' => '{}', 'data' => '{}']));
    $check($response->statusCode() === 422, 'Preview must validate JSON root shapes');

    $response = $controller->previewReferencedProject($request([
        'structure' => '[{"type":"intro","title":"title","lead":"lead"}]',
        'data' => '{"title":"Unsaved preview","lead":"<script>alert(1)</script>"}',
    ]));
    $html = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR)['data']['html'];
    $check($response->statusCode() === 200, 'Valid preview must render');
    $check(str_contains($html, 'Unsaved preview'), 'Preview must render unsaved JSON data');
    $check(str_contains($html, '&lt;script&gt;'), 'Preview must escape user content');
    $check(!str_contains($html, '<script>'), 'Preview must not contain executable scripts');
    $check(str_contains($html, '/ui/_assets/css/tailwind.css'), 'Preview must use public styles');
    $check(($response->headers()['Cache-Control'] ?? '') === 'no-store', 'Unsaved previews must not be cached');

    $response = $controller->previewReferencedProject($request([
        'title' => 'Fallback title',
        'description' => 'Fallback description',
        'structure' => '[]',
        'data' => '{}',
    ]));
    $html = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR)['data']['html'];
    $check(str_contains($html, 'Fallback title'), 'Empty structures must match the public default layout');
    $check(str_contains($html, 'Fallback description'), 'Default layout must use current metadata');

    $response = $controller->previewReferencedProject($request([
        'structure' => '[{"type":"split_panel","slug":"slug","title":"title","accent":"accent","body":"body","points":"points","reverse":true}]',
        'data' => '{"slug":"details","title":"Panel title","accent":"Panel accent","body":"Panel body","points":["Panel point"]}',
    ]));
    $html = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR)['data']['html'];
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $heading = $xpath->query('//h3')->item(0);
    $panel = $heading->parentNode;
    $check(str_contains($panel->getAttribute('style'), 'background: var(--background)'), 'Split-panel background must follow the theme');
    $check(str_contains($heading->getAttribute('style'), 'color: var(--foreground)'), 'Split-panel heading must follow the theme');
    $check(str_contains($xpath->query('.//h3/span', $panel)->item(0)->getAttribute('style'), 'var(--primary)'), 'Split-panel accent must follow the theme');
    $check(str_contains($xpath->query('.//p', $panel)->item(0)->getAttribute('style'), 'var(--muted-foreground)'), 'Split-panel body must follow the theme');
    $check(str_contains($panel->getAttribute('class'), 'lg:order-2'), 'Split-panel reverse layout must be preserved');
    $check(str_contains($panel->textContent, 'Panel point'), 'Split-panel points must remain visible');
    $check(str_contains($panel->parentNode->getAttribute('class'), 'gb-content-card'), 'Split panels must use the shared card shadow');
    $image = $xpath->query('.//img', $panel->parentNode)->item(0);
    $check(!str_contains($image->getAttribute('class'), 'object-cover'), 'Split-panel images must not be cropped');
    $check(str_contains($image->getAttribute('class'), 'gb-split-panel-image'), 'Split-panel images must use responsive mobile sizing');
    $check(str_contains($image->parentNode->getAttribute('class'), 'overflow-hidden'), 'Overflowing mobile images must be clipped');
    $check(str_contains($image->parentNode->getAttribute('class'), 'min-w-0'), 'Natural image width must not expand the panel');
    $check(!str_contains($image->parentNode->getAttribute('class'), 'min-h-['), 'Image containers must not force a minimum height');
    $check(!str_contains($image->getAttribute('class'), 'h-full'), 'Image height must follow its natural aspect ratio');
    $check(!str_contains($image->getAttribute('class'), 'resize-y'), 'Images must not be manually stretched');
} finally {
    session_destroy();
    unlink($logFile);
}

echo $checks . " referenced-project JSON and preview checks passed.\n";

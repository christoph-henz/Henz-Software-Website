<?php

declare(strict_types=1);

use App\Core\Container\Container;
use App\Core\Config\ConfigRepository;
use App\Core\Database\Database;

if (!function_exists('app')) {
    /**
     * Retrieve the application container instance or a specific service
     *
     * @template T
     * @param class-string<T>|null $abstract
     * @return ($abstract is null ? Container : T)
     */
    function app(?string $abstract = null): mixed
    {
        global $_app_container;

        if ($abstract === null) {
            return $_app_container ?? new Container();
        }

        return ($_app_container ?? new Container())->get($abstract);
    }
}

if (!function_exists('db')) {
    /**
     * Get a database connection and return a query builder for the specified table
     *
     * 1 or null => DB1 (henz_software_main)
     * 2 => DB2 (henz_software_logging)
     *
     * @param int|string|array<int, int|string>|null $database
     */
    function db(string $table, int|string|array|null $database = 1): \App\Core\Database\QueryBuilder|\App\Core\Database\MultiConnectionQueryBuilder
    {
        $normalizeConnection = static function (int|string|null $connection): string {
            return match ($connection) {
                null, 1, '1', 'db1', 'main', 'production', 'mysql', 'henz_software_main' => 'henz_software_main',
                2, '2', 'db2', 'log', 'logging', 'henz_software_logging' => 'henz_software_logging',
                default => (string) $connection,
            };
        };

        if (is_array($database)) {
            $connections = [];
            foreach ($database as $connection) {
                $connections[] = $normalizeConnection(is_int($connection) || is_string($connection) ? $connection : null);
            }

            return app(Database::class)->table($table, $connections);
        }

        return app(Database::class)->table($table, $normalizeConnection($database));
    }
}

if (!function_exists('config')) {
    /**
     * Get application configuration value with dot notation
     */
    function config(string $key, mixed $default = null): mixed
    {
        return ConfigRepository::instance()->get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 3);
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('admin_flash')) {
    /**
     * Set a session flash notification for the next admin page render.
     *
     * The admin-notification partial reads and clears this value on the
     * following request (survives one redirect). Call this before Response::redirect().
     *
     * @param 'error'|'warning'|'success'|'info' $type
     */
    function admin_flash(string $type, string $message): void
    {
        $allowed = ['error', 'warning', 'success', 'info'];

        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
        }
    }
}

if (!function_exists('media_asset_public_url')) {
    function media_asset_public_url(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return '';
        }

        if (str_starts_with($filename, '/')) {
            return $filename;
        }

        if (str_contains($filename, '://')) {
            return $filename;
        }

        return '/storage/media/' . ltrim($filename, '/');
    }
}

if (!function_exists('page_media_slot_url')) {
    function page_media_slot_url(string $pageKey, string $sectionKey, string $slotKey, string $fallback = ''): string
    {
        try {
            $assignment = db('page_media_assignments')
                ->where('page_key', $pageKey)
                ->where('section_key', $sectionKey)
                ->where('slot_key', $slotKey)
                ->where('asset_id', 0, '>')
                ->select(['asset_id'])
                ->orderBy('sort_order', 'ASC')
                ->first();

            $assetId = (int) ($assignment['asset_id'] ?? 0);
            if ($assetId <= 0) {
                return $fallback;
            }

            $asset = db('media_assets')
                ->where('id', $assetId)
                ->where('is_active', 1)
                ->select(['filename'])
                ->first();

            $filename = (string) ($asset['filename'] ?? '');
            if ($filename === '') {
                return $fallback;
            }

            return media_asset_public_url($filename);
        } catch (Throwable) {
            return $fallback;
        }
    }
}

if (!function_exists('service_page_sections')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function service_page_sections(): array
    {
        try {
            $rows = db('services')
                ->where('is_active', 1)
                ->select(['*'])
                ->orderBy('name', 'ASC')
                ->get();

            if (!is_array($rows)) {
                return [];
            }

            usort($rows, static function (array $left, array $right): int {
                $leftOrder = (int) ($left['display_order'] ?? $left['sort_order'] ?? PHP_INT_MAX);
                $rightOrder = (int) ($right['display_order'] ?? $right['sort_order'] ?? PHP_INT_MAX);

                if ($leftOrder !== $rightOrder) {
                    return $leftOrder <=> $rightOrder;
                }

                return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            });

            $services = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $slug = trim((string) ($row['cta_url'] ?? ''));

                if ($slug === '') {
                    continue;
                }

                $slug = ltrim((string) strrchr($slug, '#'), '#');

                $structureRaw = $row['structure'] ?? '[]';
                $dataRaw = $row['data'] ?? '{}';
                $structure = is_string($structureRaw) ? json_decode($structureRaw, true) : $structureRaw;
                $data = is_string($dataRaw) ? json_decode($dataRaw, true) : $dataRaw;

                if (!is_array($structure) || $structure === []) {
                    continue;
                }

                if (!is_array($data)) {
                    $data = [];
                }

                $service = [
                    'id' => (int) ($row['id'] ?? 0),
                    'slug' => $slug,
                    'name' => (string) ($row['name'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'structure' => $structure,
                    'data' => $data,
                ];

                foreach (structured_page_image_slots($structure) as $slotKey) {
                    $service[$slotKey] = page_media_slot_url(
                        'service',
                        $slug,
                        $slotKey,
                        '/ui/_assets/images/hero-image.png'
                    );
                }

                $services[] = $service;
            }

            return $services;
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('service_page_image_slots')) {
    /**
     * @param array<int|string, mixed> $structure
     * @return array<int, string>
     */
    function service_page_image_slots(array $structure): array
    {
        return structured_page_image_slots($structure);
    }
}

if (!function_exists('structured_page_image_slots')) {
    /**
     * @param array<int|string, mixed> $structure
     * @return array<int, string>
     */
    function structured_page_image_slots(array $structure): array
    {
        $slots = [];

        foreach ($structure as $node) {
            if (!is_array($node)) {
                continue;
            }

            $slot = trim((string) ($node['image_var'] ?? $node['src_var'] ?? ''));
            if ($slot !== '') {
                $slots[$slot] = true;
            }
        }

        return array_keys($slots);
    }
}

if (!function_exists('project_media_public_url')) {
    function project_media_public_url(string $mediaFile, string $fallback = '/ui/_assets/images/profile-placeholder.svg'): string
    {
        $mediaFile = trim($mediaFile);
        if ($mediaFile === '') {
            return $fallback;
        }

        $mediaFile = ltrim(str_replace('\\', '/', $mediaFile), '/');
        $mediaFile = preg_replace('#^(?:storage/media/)?referenced_projects/#i', '', $mediaFile) ?? $mediaFile;
        $absolutePath = base_path('storage/media/referenced_projects/' . $mediaFile);

        if (!is_file($absolutePath)) {
            return $fallback;
        }

        return '/storage/media/referenced_projects/' . rawurlencode($mediaFile);
    }
}

if (!function_exists('project_page_default_payload')) {
    /**
     * @param array<string, mixed> $row
     * @return array{structure: array<int, array<string, mixed>>, data: array<string, mixed>}
     */
    function project_page_default_payload(array $row): array
    {
        $routeSlug = trim((string) ($row['project_slug'] ?? $row['slug'] ?? ''));
        $title = trim((string) ($row['title'] ?? 'Projekt'));
        $description = trim((string) ($row['description'] ?? ''));
        $projectUrl = trim((string) ($row['project_url'] ?? ''));
        if ($projectUrl !== '' && !str_contains($projectUrl, '://')) {
            $projectUrl = 'https://' . ltrim($projectUrl, '/');
        }

        return [
            'structure' => [
                [
                    'type' => 'intro',
                    'slug' => 'slug',
                    'eyebrow' => 'eyebrow',
                    'title' => 'title',
                    'accent' => 'title_accent',
                    'lead' => 'lead',
                    'primary_cta' => 'primary_cta',
                    'image_var' => 'main_image',
                    'image_alt' => 'main_image_alt',
                ],
                [
                    'type' => 'split_panel',
                    'slug' => 'detail_slug',
                    'title' => 'detail_title',
                    'accent' => 'detail_accent',
                    'body' => 'detail_body',
                    'image_var' => 'detail_image',
                    'image_alt' => 'detail_image_alt',
                    'reverse' => true,
                ],
            ],
            'data' => [
                'slug' => $routeSlug !== '' ? $routeSlug : trim((string) ($row['slug'] ?? 'projekt')),
                'eyebrow' => 'Projekt',
                'title' => $title,
                'title_accent' => '',
                'lead' => $description,
                'primary_cta' => $projectUrl !== '' ? ['label' => 'Projekt besuchen', 'href' => $projectUrl] : ['label' => 'Zurueck zu Referenzen', 'href' => '/#referenzen'],
                'main_image_alt' => $title,
                'detail_slug' => 'projektueberblick',
                'detail_title' => 'Projekt',
                'detail_accent' => 'im Einsatz',
                'detail_body' => $description,
                'detail_image_alt' => $title,
            ],
        ];
    }
}

if (!function_exists('project_page_entries')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function project_page_entries(): array
    {
        try {
            $rows = db('referenced_projects')
                ->where('is_active', 1)
                ->select(['*'])
                ->orderBy('sort_order', 'ASC')
                ->orderBy('title', 'ASC')
                ->get();

            if (!is_array($rows)) {
                return [];
            }

            $projects = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $routeSlug = trim((string) ($row['project_slug'] ?? $row['slug'] ?? ''));
                if ($routeSlug === '') {
                    continue;
                }

                $structureRaw = $row['structure'] ?? [];
                $dataRaw = $row['data'] ?? [];
                $structure = is_string($structureRaw) ? json_decode($structureRaw, true) : $structureRaw;
                $data = is_string($dataRaw) ? json_decode($dataRaw, true) : $dataRaw;

                if (!is_array($structure) || !is_array($data) || $structure === []) {
                    $fallbackPayload = project_page_default_payload($row);
                    $structure = $fallbackPayload['structure'];
                    $data = $fallbackPayload['data'];
                }

                ensure_structured_page_media_assignments('project', $routeSlug, $structure);

                $mediaFallback = project_media_public_url((string) ($row['project_image_path'] ?? ''));
                $project = [
                    'id' => (int) ($row['id'] ?? 0),
                    'slug' => $routeSlug,
                    'route_slug' => $routeSlug,
                    'title' => (string) ($row['title'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'project_url' => (string) ($row['project_url'] ?? ''),
                    'project_media_path' => (string) ($row['project_image_path'] ?? ''),
                    'structure' => $structure,
                    'data' => $data,
                ];

                foreach (structured_page_image_slots($structure) as $slotKey) {
                    $slotFallback = in_array($slotKey, ['main_image', 'detail_image'], true)
                        ? $mediaFallback
                        : '/ui/_assets/images/profile-placeholder.svg';

                    $project[$slotKey] = page_media_slot_url(
                        'project',
                        $routeSlug,
                        $slotKey,
                        $slotFallback
                    );
                }

                if (!isset($project['main_image'])) {
                    $project['main_image'] = $mediaFallback;
                }

                if (!isset($project['detail_image'])) {
                    $project['detail_image'] = $mediaFallback;
                }

                $projects[] = $project;
            }

            return $projects;
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('ensure_structured_page_media_assignments')) {
    /**
     * @param array<int|string, mixed> $structure
     */
    function ensure_structured_page_media_assignments(string $pageKey, string $sectionKey, array $structure): void
    {
        $sectionKey = trim($sectionKey);
        if ($pageKey === '' || $sectionKey === '') {
            return;
        }

        try {
            foreach (structured_page_image_slots($structure) as $slotKey) {
                $existing = db('page_media_assignments')
                    ->where('page_key', $pageKey)
                    ->where('section_key', $sectionKey)
                    ->where('slot_key', $slotKey)
                    ->select(['id'])
                    ->first();

                if (is_array($existing)) {
                    continue;
                }

                db('page_media_assignments')->insert([
                    'page_key' => $pageKey,
                    'section_key' => $sectionKey,
                    'slot_key' => $slotKey,
                    'asset_id' => null,
                    'gallery_id' => null,
                    'sort_order' => 1,
                ]);
            }
        } catch (Throwable) {
            return;
        }
    }
}


if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

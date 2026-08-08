<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class ServiceAdminController extends BaseApiController
{
    private const MANAGE_SERVICES_BIT = 8192;

    public function services(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $rows = db('services')
            ->select(['*'])
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        return $this->ok([
            'services' => array_map(static fn (array $row): array => self::formatServiceRow($row), is_array($rows) ? $rows : []),
        ]);
    }

    public function showService(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
            ]);
        }

        $row = $this->fetchServiceRow($id);
        if ($row === null) {
            return $this->fail('Service not found', 404);
        }

        return $this->ok([
            'service' => self::formatServiceRow($row),
        ]);
    }

    public function storeService(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $data = $request->all();
        $name = trim((string) ($data['name'] ?? ''));
        $slug = $this->slugify((string) ($data['slug'] ?? ''), $name);
        $durationMinutes = (int) ($data['duration_minutes'] ?? 0);
        $price = (float) ($data['price'] ?? 0);
        $displayOrder = (int) ($data['display_order'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));
        $isActive = $this->normalizeBool($data['is_active'] ?? true);
        $isFeatured = $this->normalizeBool($data['is_featured'] ?? false);
        $serviceColumns = $this->serviceColumnSet();
        $errors = [];
        $structurePayload = $this->normalizeJsonPayload($data['structure'] ?? [], true, 'structure', $errors);
        $dataPayload = $this->normalizeJsonPayload($data['data'] ?? [], false, 'data', $errors);
        if ($name === '') {
            $errors['name'][] = 'required';
        }
        if ($slug === '') {
            $errors['slug'][] = 'required';
        }
        if (isset($serviceColumns['duration_minutes']) && $durationMinutes <= 0) {
            $errors['duration_minutes'][] = 'invalid';
        }
        if ($price < 0) {
            $errors['price'][] = 'invalid';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($this->slugExists('services', $slug)) {
            return $this->fail('Validation failed', 422, [
                'slug' => ['already_exists'],
            ]);
        }

        $insertData = [
            'name' => $name,
            'slug' => $slug,
        ];

        if (isset($serviceColumns['duration_minutes'])) {
            $insertData['duration_minutes'] = max(1, $durationMinutes);
        }

        if (isset($serviceColumns['price'])) {
            $insertData['price'] = $price;
        } elseif (isset($serviceColumns['price_min'])) {
            $insertData['price_min'] = (int) max(0, round($price));
        }

        if (isset($serviceColumns['description'])) {
            $insertData['description'] = $description !== '' ? $description : null;
        }

        if (isset($serviceColumns['is_active'])) {
            $insertData['is_active'] = $isActive;
        }

        if (isset($serviceColumns['display_order'])) {
            $insertData['display_order'] = $displayOrder;
        } elseif (isset($serviceColumns['sort_order'])) {
            $insertData['sort_order'] = $displayOrder;
        }

        if (isset($serviceColumns['is_featured'])) {
            $insertData['is_featured'] = $isFeatured;
        }

        if (isset($serviceColumns['structure'])) {
            $insertData['structure'] = $structurePayload;
        }

        if (isset($serviceColumns['data'])) {
            $insertData['data'] = $dataPayload;
        }

        $pdo = app(Database::class)->connection();
        $columns = array_keys($insertData);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $stmt = $pdo->prepare(
            'INSERT INTO services (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );

        $bindings = [];
        foreach ($insertData as $column => $value) {
            $bindings[':' . $column] = $value;
        }

        $stmt->execute($bindings);

        $id = (int) $pdo->lastInsertId();
        $this->ensureServiceMediaAssignments($slug, $this->decodeJsonArray($structurePayload));
        $row = $this->fetchServiceRow($id);

        return $this->ok([
            'service' => self::formatServiceRow($row ?? []),
        ], 201);
    }

    public function updateService(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
            ]);
        }

        $existing = $this->fetchServiceRow($id);
        if ($existing === null) {
            return $this->fail('Service not found', 404);
        }

        $data = $request->all();
        $name = trim((string) ($data['name'] ?? $existing['name'] ?? ''));
        $slug = $this->slugify((string) ($data['slug'] ?? ($existing['slug'] ?? '')), $name);
        $durationMinutes = (int) ($data['duration_minutes'] ?? ($existing['duration_minutes'] ?? 0));
        $price = (float) ($data['price'] ?? ($existing['price'] ?? 0));
        $displayOrder = (int) ($data['display_order'] ?? ($existing['display_order'] ?? 0));
        $description = trim((string) ($data['description'] ?? ($existing['description'] ?? '')));
        $isActive = $this->normalizeBool($data['is_active'] ?? ($existing['is_active'] ?? true));
        $isFeatured = $this->normalizeBool($data['is_featured'] ?? ($existing['is_featured'] ?? false));
        $serviceColumns = $this->serviceColumnSet();
        $errors = [];
        $structurePayload = $this->normalizeJsonPayload($data['structure'] ?? ($existing['structure'] ?? []), true, 'structure', $errors);
        $dataPayload = $this->normalizeJsonPayload($data['data'] ?? ($existing['data'] ?? []), false, 'data', $errors);
        if ($name === '') {
            $errors['name'][] = 'required';
        }
        if ($slug === '') {
            $errors['slug'][] = 'required';
        }
        if (isset($serviceColumns['duration_minutes']) && $durationMinutes <= 0) {
            $errors['duration_minutes'][] = 'invalid';
        }
        if ($price < 0) {
            $errors['price'][] = 'invalid';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($this->slugExists('services', $slug, $id)) {
            return $this->fail('Validation failed', 422, [
                'slug' => ['already_exists'],
            ]);
        }

        $updateData = [
            'name' => $name,
            'slug' => $slug,
        ];

        if (isset($serviceColumns['duration_minutes'])) {
            $updateData['duration_minutes'] = max(1, $durationMinutes);
        }

        if (isset($serviceColumns['price'])) {
            $updateData['price'] = $price;
        } elseif (isset($serviceColumns['price_min'])) {
            $updateData['price_min'] = (int) max(0, round($price));
        }

        if (isset($serviceColumns['description'])) {
            $updateData['description'] = $description !== '' ? $description : null;
        }

        if (isset($serviceColumns['is_active'])) {
            $updateData['is_active'] = $isActive;
        }

        if (isset($serviceColumns['display_order'])) {
            $updateData['display_order'] = $displayOrder;
        } elseif (isset($serviceColumns['sort_order'])) {
            $updateData['sort_order'] = $displayOrder;
        }

        if (isset($serviceColumns['is_featured'])) {
            $updateData['is_featured'] = $isFeatured;
        }

        if (isset($serviceColumns['structure'])) {
            $updateData['structure'] = $structurePayload;
        }

        if (isset($serviceColumns['data'])) {
            $updateData['data'] = $dataPayload;
        }

        $sets = [];
        $bindings = [':id' => $id];
        foreach ($updateData as $column => $value) {
            $sets[] = $column . ' = :' . $column;
            $bindings[':' . $column] = $value;
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'UPDATE services SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute($bindings);

        $previousSlug = (string) ($existing['slug'] ?? '');
        if ($previousSlug !== '' && $previousSlug !== $slug) {
            db('page_media_assignments')
                ->where('page_key', 'service')
                ->where('section_key', $previousSlug)
                ->update([
                    'section_key' => $slug,
                ]);
        }

        $this->ensureServiceMediaAssignments($slug, $this->decodeJsonArray($structurePayload));

        $row = $this->fetchServiceRow($id);

        return $this->ok([
            'service' => self::formatServiceRow($row ?? $existing),
        ]);
    }

    public function referencedProjects(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $rows = db('referenced_projects')
            ->select(['*'])
            ->orderBy('sort_order', 'asc')
            ->orderBy('title', 'asc')
            ->get();

        return $this->ok([
            'referenced_projects' => array_map(static fn (array $row): array => self::formatReferencedProjectRow($row), is_array($rows) ? $rows : []),
        ]);
    }

    public function showReferencedProject(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
            ]);
        }

        $row = $this->fetchReferencedProjectRow($id);
        if ($row === null) {
            return $this->fail('Referenced project not found', 404);
        }

        return $this->ok([
            'referenced_project' => self::formatReferencedProjectRow($row),
        ]);
    }

    public function storeReferencedProject(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $data = $request->all();
        $title = trim((string) ($data['title'] ?? ''));
        $slug = $this->slugify((string) ($data['slug'] ?? ''), $title);
        $projectSlug = $this->slugify((string) ($data['project_slug'] ?? ''), $slug);
        $projectImagePath = trim((string) ($data['project_image_path'] ?? ''));
        $projectUrl = trim((string) ($data['project_url'] ?? ''));
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));
        $isActive = $this->normalizeBool($data['is_active'] ?? true);
        $errors = [];
        $structurePayload = $this->normalizeJsonPayload($data['structure'] ?? [], true, 'structure', $errors);
        $dataPayload = $this->normalizeJsonPayload($data['data'] ?? [], false, 'data', $errors);

        if ($title === '') {
            $errors['title'][] = 'required';
        }
        if ($slug === '') {
            $errors['slug'][] = 'required';
        }
        if ($projectSlug === '') {
            $errors['project_slug'][] = 'required';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($this->slugExists('referenced_projects', $slug)) {
            return $this->fail('Validation failed', 422, [
                'slug' => ['already_exists'],
            ]);
        }

        if ($this->valueExists('referenced_projects', 'project_slug', $projectSlug)) {
            return $this->fail('Validation failed', 422, [
                'project_slug' => ['already_exists'],
            ]);
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO referenced_projects (slug, title, description, project_image_path, project_slug, project_url, structure, data, sort_order, is_active)
             VALUES (:slug, :title, :description, :project_image_path, :project_slug, :project_url, :structure, :data, :sort_order, :is_active)'
        );
        $stmt->execute([
            ':slug' => $slug,
            ':title' => $title,
            ':description' => $description !== '' ? $description : null,
            ':project_image_path' => $projectImagePath !== '' ? $projectImagePath : null,
            ':project_slug' => $projectSlug,
            ':project_url' => $projectUrl !== '' ? $projectUrl : null,
            ':structure' => $structurePayload,
            ':data' => $dataPayload,
            ':sort_order' => $sortOrder,
            ':is_active' => $isActive,
        ]);

        $id = (int) $pdo->lastInsertId();
        $row = $this->fetchReferencedProjectRow($id);

        return $this->ok([
            'referenced_project' => self::formatReferencedProjectRow($row ?? []),
        ], 201);
    }

    public function updateReferencedProject(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['required'],
            ]);
        }

        $existing = $this->fetchReferencedProjectRow($id);
        if ($existing === null) {
            return $this->fail('Referenced project not found', 404);
        }

        $data = $request->all();
        $title = trim((string) ($data['title'] ?? $existing['title'] ?? ''));
        $slug = $this->slugify((string) ($data['slug'] ?? ($existing['slug'] ?? '')), $title);
        $projectSlug = $this->slugify((string) ($data['project_slug'] ?? ($existing['project_slug'] ?? '')), $slug);
        $projectImagePath = trim((string) ($data['project_image_path'] ?? ($existing['project_image_path'] ?? '')));
        $projectUrl = trim((string) ($data['project_url'] ?? ($existing['project_url'] ?? '')));
        $sortOrder = (int) ($data['sort_order'] ?? ($existing['sort_order'] ?? 0));
        $description = trim((string) ($data['description'] ?? ($existing['description'] ?? '')));
        $isActive = $this->normalizeBool($data['is_active'] ?? ($existing['is_active'] ?? true));
        $errors = [];
        $structurePayload = $this->normalizeJsonPayload($data['structure'] ?? ($existing['structure'] ?? []), true, 'structure', $errors);
        $dataPayload = $this->normalizeJsonPayload($data['data'] ?? ($existing['data'] ?? []), false, 'data', $errors);

        if ($title === '') {
            $errors['title'][] = 'required';
        }
        if ($slug === '') {
            $errors['slug'][] = 'required';
        }
        if ($projectSlug === '') {
            $errors['project_slug'][] = 'required';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($this->slugExists('referenced_projects', $slug, $id)) {
            return $this->fail('Validation failed', 422, [
                'slug' => ['already_exists'],
            ]);
        }

        if ($this->valueExists('referenced_projects', 'project_slug', $projectSlug, $id)) {
            return $this->fail('Validation failed', 422, [
                'project_slug' => ['already_exists'],
            ]);
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'UPDATE referenced_projects
             SET slug = :slug,
                 title = :title,
                 description = :description,
                 project_image_path = :project_image_path,
                 project_slug = :project_slug,
                 project_url = :project_url,
                 structure = :structure,
                 data = :data,
                 is_active = :is_active,
                 sort_order = :sort_order,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':slug' => $slug,
            ':title' => $title,
            ':description' => $description !== '' ? $description : null,
            ':project_image_path' => $projectImagePath !== '' ? $projectImagePath : null,
            ':project_slug' => $projectSlug,
            ':project_url' => $projectUrl !== '' ? $projectUrl : null,
            ':structure' => $structurePayload,
            ':data' => $dataPayload,
            ':is_active' => $isActive,
            ':sort_order' => $sortOrder,
            ':id' => $id,
        ]);

        $row = $this->fetchReferencedProjectRow($id);

        return $this->ok([
            'referenced_project' => self::formatReferencedProjectRow($row ?? $existing),
        ]);
    }

    private function canManageServices(Request $request): bool
    {
        return ($this->actorRoleMask($request) & self::MANAGE_SERVICES_BIT) !== 0;
    }

    private function actorRoleMask(Request $request): int
    {
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $request->session()[$sessionKey] ?? [];

        return (int) ($adminUser['role_mask'] ?? 0);
    }

    /** @return array<string, true> */
    private function serviceColumnSet(): array
    {
        static $columnSet = null;

        if (is_array($columnSet)) {
            return $columnSet;
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->query('SHOW COLUMNS FROM services');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $columnSet = [];
        foreach ($rows as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columnSet[$field] = true;
            }
        }

        return $columnSet;
    }

    /** @return array<string, mixed>|null */
    private function fetchServiceRow(int $id): ?array
    {
        $row = db('services')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function fetchReferencedProjectRow(int $id): ?array
    {
        $row = db('referenced_projects')
            ->where('id', $id)
            ->select(['*'])
            ->first();

        return is_array($row) ? $row : null;
    }

    private function slugExists(string $table, string $slug, ?int $ignoreId = null): bool
    {
        $query = db($table)
            ->where('slug', $slug);

        if ($ignoreId !== null && $ignoreId > 0) {
            $query->where('id', $ignoreId, '<>');
        }

        return $query->select(['id'])->first() !== null;
    }

    private function valueExists(string $table, string $column, string $value, ?int $ignoreId = null): bool
    {
        $query = db($table)
            ->where($column, $value);

        if ($ignoreId !== null && $ignoreId > 0) {
            $query->where('id', $ignoreId, '<>');
        }

        return $query->select(['id'])->first() !== null;
    }

    private function slugify(string $value, string $fallbackName = ''): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = trim($fallbackName);
        }

        $value = strtr($value, [
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        ]);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';

        return trim($value, '-');
    }

    private function normalizeBool(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0 ? 1 : 0;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    private function normalizeJsonPayload(mixed $value, bool $requireList, string $field, array &$errors): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $errors[$field][] = 'invalid_json';
                return $requireList ? '[]' : '{}';
            }

            if ($requireList && !array_is_list($decoded)) {
                $errors[$field][] = 'invalid_shape';
                return '[]';
            }

            return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (!is_array($value)) {
            $errors[$field][] = 'invalid_json';
            return $requireList ? '[]' : '{}';
        }

        if ($requireList && !array_is_list($value)) {
            $errors[$field][] = 'invalid_shape';
            return '[]';
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<int, array<string, mixed>> */
    private function decodeJsonArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $structure
     */
    private function ensureServiceMediaAssignments(string $slug, array $structure): void
    {
        $slots = [];

        foreach ($structure as $node) {
            if (!is_array($node)) {
                continue;
            }

            $slotKey = trim((string) ($node['image_var'] ?? $node['src_var'] ?? ''));
            if ($slotKey !== '') {
                $slots[$slotKey] = true;
            }
        }

        foreach (array_keys($slots) as $slotKey) {
            $existing = db('page_media_assignments')
                ->where('page_key', 'service')
                ->where('section_key', $slug)
                ->where('slot_key', $slotKey)
                ->select(['id'])
                ->first();

            if (is_array($existing)) {
                continue;
            }

            db('page_media_assignments')->insert([
                'page_key' => 'service',
                'section_key' => $slug,
                'slot_key' => $slotKey,
                'asset_id' => null,
                'gallery_id' => null,
                'sort_order' => 1,
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private static function formatServiceRow(array $row): array
    {
        $structureRaw = $row['structure'] ?? [];
        $dataRaw = $row['data'] ?? [];

        $duration = isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : 60;
        if ($duration <= 0) {
            $duration = 60;
        }

        $price = 0.0;
        if (isset($row['price'])) {
            $price = (float) $row['price'];
        } elseif (isset($row['price_min'])) {
            $price = (float) $row['price_min'];
        }

        $displayOrder = isset($row['display_order']) ? (int) $row['display_order'] : (int) ($row['sort_order'] ?? 0);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'duration_minutes' => $duration,
            'price' => $price,
            'description' => (string) ($row['description'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'is_featured' => (int) ($row['is_featured'] ?? 0) === 1,
            'display_order' => $displayOrder,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'structure' => is_string($structureRaw) ? (json_decode($structureRaw, true) ?: []) : (is_array($structureRaw) ? $structureRaw : []),
            'data' => is_string($dataRaw) ? (json_decode($dataRaw, true) ?: []) : (is_array($dataRaw) ? $dataRaw : []),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function formatReferencedProjectRow(array $row): array
    {
        $structureRaw = $row['structure'] ?? [];
        $dataRaw = $row['data'] ?? [];

        return [
            'id' => (int) ($row['id'] ?? 0),
            'slug' => (string) ($row['slug'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'project_image_path' => (string) ($row['project_image_path'] ?? ''),
            'project_slug' => (string) ($row['project_slug'] ?? ''),
            'project_url' => (string) ($row['project_url'] ?? ''),
            'structure' => is_string($structureRaw) ? (json_decode($structureRaw, true) ?: []) : (is_array($structureRaw) ? $structureRaw : []),
            'data' => is_string($dataRaw) ? (json_decode($dataRaw, true) ?: []) : (is_array($dataRaw) ? $dataRaw : []),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}

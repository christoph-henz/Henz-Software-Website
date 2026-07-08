<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Support\PermissionBits;

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
            ->select(['id', 'name', 'slug', 'duration_minutes', 'price', 'description', 'is_active', 'is_featured', 'display_order', 'created_at', 'updated_at'])
            ->orderBy('display_order', 'asc')
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

        $errors = [];
        if ($name === '') {
            $errors['name'][] = 'required';
        }
        if ($slug === '') {
            $errors['slug'][] = 'required';
        }
        if ($durationMinutes <= 0) {
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

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO services (name, slug, duration_minutes, price, description, is_active, display_order, is_featured)
             VALUES (:name, :slug, :duration_minutes, :price, :description, :is_active, :display_order, :is_featured)'
        );
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':duration_minutes' => $durationMinutes,
            ':price' => $price,
            ':description' => $description !== '' ? $description : null,
            ':is_active' => $isActive,
            ':display_order' => $displayOrder,
            ':is_featured' => $isFeatured,
        ]);

        $id = (int) $pdo->lastInsertId();
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

        $errors = [];
        if ($name === '') {
            $errors['name'][] = 'required';
        }
        if ($slug === '') {
            $errors['slug'][] = 'required';
        }
        if ($durationMinutes <= 0) {
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

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'UPDATE services
             SET name = :name,
                 slug = :slug,
                 duration_minutes = :duration_minutes,
                 price = :price,
                 description = :description,
                 is_active = :is_active,
                 display_order = :display_order,
                 is_featured = :is_featured,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':duration_minutes' => $durationMinutes,
            ':price' => $price,
            ':description' => $description !== '' ? $description : null,
            ':is_active' => $isActive,
            ':display_order' => $displayOrder,
            ':is_featured' => $isFeatured,
            ':id' => $id,
        ]);

        $row = $this->fetchServiceRow($id);

        return $this->ok([
            'service' => self::formatServiceRow($row ?? $existing),
        ]);
    }

    public function packages(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT
                sp.id,
                sp.name,
                sp.slug,
                sp.service_id,
                sp.session_count,
                sp.price,
                sp.description,
                sp.is_active,
                sp.display_order,
                sp.created_at,
                sp.updated_at,
                s.name AS service_name,
                s.slug AS service_slug,
                s.is_active AS service_is_active
             FROM service_packages sp
             INNER JOIN services s ON s.id = sp.service_id
             WHERE s.is_active = 1
             ORDER BY sp.display_order ASC, sp.name ASC, sp.id ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->ok([
            'packages' => array_map(static fn (array $row): array => self::formatPackageRow($row), is_array($rows) ? $rows : []),
        ]);
    }

    public function showPackage(Request $request): Response
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

        $row = $this->fetchPackageRow($id);
        if ($row === null) {
            return $this->fail('Package not found', 404);
        }

        return $this->ok([
            'package' => self::formatPackageRow($row),
        ]);
    }

    public function storePackage(Request $request): Response
    {
        if (!$this->canManageServices($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $data = $request->all();
        $name = trim((string) ($data['name'] ?? ''));
        $slug = $this->slugify((string) ($data['slug'] ?? ''), $name);
        $serviceId = (int) ($data['service_id'] ?? 0);
        $sessionCount = (int) ($data['session_count'] ?? 0);
        $price = (float) ($data['price'] ?? 0);
        $displayOrder = (int) ($data['display_order'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));
        $isActive = $this->normalizeBool($data['is_active'] ?? true);

        $errors = [];
        if ($name === '') {
            $errors['name'][] = 'required';
        }
        if ($slug === '') {
            $errors['slug'][] = 'required';
        }
        if ($serviceId <= 0) {
            $errors['service_id'][] = 'required';
        }
        if ($sessionCount <= 0) {
            $errors['session_count'][] = 'invalid';
        }
        if ($price < 0) {
            $errors['price'][] = 'invalid';
        }

        $serviceRow = $serviceId > 0 ? $this->fetchServiceRow($serviceId) : null;
        if (!is_array($serviceRow) || (int) ($serviceRow['is_active'] ?? 0) !== 1) {
            $errors['service_id'][] = 'service_inactive';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($this->slugExists('service_packages', $slug)) {
            return $this->fail('Validation failed', 422, [
                'slug' => ['already_exists'],
            ]);
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO service_packages (name, slug, service_id, session_count, price, description, is_active, display_order)
             VALUES (:name, :slug, :service_id, :session_count, :price, :description, :is_active, :display_order)'
        );
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':service_id' => $serviceId,
            ':session_count' => $sessionCount,
            ':price' => $price,
            ':description' => $description !== '' ? $description : null,
            ':is_active' => $isActive,
            ':display_order' => $displayOrder,
        ]);

        $id = (int) $pdo->lastInsertId();
        $row = $this->fetchPackageRow($id);

        return $this->ok([
            'package' => self::formatPackageRow($row ?? []),
        ], 201);
    }

    public function updatePackage(Request $request): Response
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

        $existing = $this->fetchPackageRow($id);
        if ($existing === null) {
            return $this->fail('Package not found', 404);
        }

        $data = $request->all();
        $name = trim((string) ($data['name'] ?? $existing['name'] ?? ''));
        $slug = $this->slugify((string) ($data['slug'] ?? ($existing['slug'] ?? '')), $name);
        $serviceId = (int) ($data['service_id'] ?? ($existing['service_id'] ?? 0));
        $sessionCount = (int) ($data['session_count'] ?? ($existing['session_count'] ?? 0));
        $price = (float) ($data['price'] ?? ($existing['price'] ?? 0));
        $displayOrder = (int) ($data['display_order'] ?? ($existing['display_order'] ?? 0));
        $description = trim((string) ($data['description'] ?? ($existing['description'] ?? '')));
        $isActive = $this->normalizeBool($data['is_active'] ?? ($existing['is_active'] ?? true));

        $errors = [];
        if ($name === '') {
            $errors['name'][] = 'required';
        }
        if ($slug === '') {
            $errors['slug'][] = 'required';
        }
        if ($serviceId <= 0) {
            $errors['service_id'][] = 'required';
        }
        if ($sessionCount <= 0) {
            $errors['session_count'][] = 'invalid';
        }
        if ($price < 0) {
            $errors['price'][] = 'invalid';
        }

        $serviceRow = $serviceId > 0 ? $this->fetchServiceRow($serviceId) : null;
        if (!is_array($serviceRow) || (int) ($serviceRow['is_active'] ?? 0) !== 1) {
            $errors['service_id'][] = 'service_inactive';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($this->slugExists('service_packages', $slug, $id)) {
            return $this->fail('Validation failed', 422, [
                'slug' => ['already_exists'],
            ]);
        }

        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'UPDATE service_packages
             SET name = :name,
                 slug = :slug,
                 service_id = :service_id,
                 session_count = :session_count,
                 price = :price,
                 description = :description,
                 is_active = :is_active,
                 display_order = :display_order,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':service_id' => $serviceId,
            ':session_count' => $sessionCount,
            ':price' => $price,
            ':description' => $description !== '' ? $description : null,
            ':is_active' => $isActive,
            ':display_order' => $displayOrder,
            ':id' => $id,
        ]);

        $row = $this->fetchPackageRow($id);

        return $this->ok([
            'package' => self::formatPackageRow($row ?? $existing),
        ]);
    }

    private function canManageServices(Request $request): bool
    {
        $bit = PermissionBits::resolve('manage_services', self::MANAGE_SERVICES_BIT);
        return ($this->actorRoleMask($request) & $bit) !== 0;
    }

    private function actorRoleMask(Request $request): int
    {
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $request->session()[$sessionKey] ?? [];

        return (int) ($adminUser['role_mask'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    private function fetchServiceRow(int $id): ?array
    {
        $row = db('services')
            ->where('id', $id)
            ->select(['id', 'name', 'slug', 'duration_minutes', 'price', 'description', 'is_active', 'is_featured', 'display_order', 'created_at', 'updated_at'])
            ->first();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function fetchPackageRow(int $id): ?array
    {
        $pdo = app(Database::class)->connection();
        $stmt = $pdo->prepare(
            'SELECT
                sp.id,
                sp.name,
                sp.slug,
                sp.service_id,
                sp.session_count,
                sp.price,
                sp.description,
                sp.is_active,
                sp.display_order,
                sp.created_at,
                sp.updated_at,
                s.name AS service_name,
                s.slug AS service_slug,
                s.is_active AS service_is_active
             FROM service_packages sp
             INNER JOIN services s ON s.id = sp.service_id
             WHERE sp.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row) || (int) ($row['service_is_active'] ?? 0) !== 1) {
            return null;
        }

        return $row;
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

    /** @param array<string, mixed> $row */
    private static function formatServiceRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 0),
            'price' => isset($row['price']) ? (float) $row['price'] : 0.0,
            'description' => (string) ($row['description'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'is_featured' => (int) ($row['is_featured'] ?? 0) === 1,
            'display_order' => (int) ($row['display_order'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function formatPackageRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'service_id' => (int) ($row['service_id'] ?? 0),
            'service_name' => (string) ($row['service_name'] ?? ''),
            'service_slug' => (string) ($row['service_slug'] ?? ''),
            'session_count' => (int) ($row['session_count'] ?? 0),
            'price' => isset($row['price']) ? (float) $row['price'] : 0.0,
            'description' => (string) ($row['description'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'display_order' => (int) ($row['display_order'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}

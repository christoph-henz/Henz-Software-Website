<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\QueryBuilder;

final class FormTemplateRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'form_templates';
    }

    /** @param array<string, mixed> $filters */
    /** @return array{data: array<int, array<string, mixed>>, total: int} */
    public function list(array $filters = [], string $sort = 'name', string $direction = 'ASC', int $page = 1, int $perPage = 20): array
    {
        return $this->run('form_templates.list', function () use ($filters, $sort, $direction, $page, $perPage): array {
            $page = max(1, $page);
            $perPage = max(1, min(100, $perPage));
            $offset = ($page - 1) * $perPage;

            $sortColumn = $this->resolveSortColumn($sort);
            $sortDirection = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

            $dataQuery = $this->query()->select([
                'id',
                'template_key',
                'name',
                'description',
                'is_active',
                '(SELECT MAX(ftv.version_no) FROM form_template_versions ftv WHERE ftv.template_id = form_templates.id) AS current_version',
                'created_at',
                'updated_at',
            ]);
            $this->applyFilters($dataQuery, $filters);

            $rows = $dataQuery
                ->orderBy($sortColumn, $sortDirection)
                ->limit($perPage)
                ->offset($offset)
                ->get();

            $countQuery = $this->query();
            $this->applyFilters($countQuery, $filters);
            $total = $countQuery->count();

            return [
                'data' => $rows,
                'total' => $total,
            ];
        });
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id, bool $includeDeleted = false): ?array
    {
        return $this->run('form_templates.findById', function () use ($id, $includeDeleted): ?array {
            $query = $this->query()->select([
                'id',
                'template_key',
                'name',
                'description',
                'is_active',
                '(SELECT MAX(ftv.version_no) FROM form_template_versions ftv WHERE ftv.template_id = form_templates.id) AS current_version',
                'created_at',
                'updated_at',
            ])->where('id', $id);

            return $query->first();
        });
    }

    public function existsByTemplateKey(string $templateKey, ?int $excludeId = null): bool
    {
        return $this->run('form_templates.existsByTemplateKey', function () use ($templateKey, $excludeId): bool {
            $query = $this->query()
                ->select(['id'])
                ->where('template_key', $templateKey);

            $rows = $query->get();
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                if ($excludeId !== null && $id === $excludeId) {
                    continue;
                }

                return true;
            }

            return false;
        });
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->run('form_templates.create', function () use ($data): int {
            return $this->query()->insert([
                'template_key' => (string) $data['template_key'],
                'name' => (string) $data['name'],
                'description' => isset($data['description']) ? (string) $data['description'] : null,
                'is_active' => !empty($data['is_active']) ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateById(int $id, array $data): int
    {
        return $this->run('form_templates.updateById', function () use ($id, $data): int {
            $payload = [];

            if (array_key_exists('template_key', $data)) {
                $payload['template_key'] = (string) $data['template_key'];
            }

            if (array_key_exists('name', $data)) {
                $payload['name'] = (string) $data['name'];
            }

            if (array_key_exists('description', $data)) {
                $payload['description'] = $data['description'] !== null ? (string) $data['description'] : null;
            }

            if (array_key_exists('is_active', $data)) {
                $payload['is_active'] = !empty($data['is_active']) ? 1 : 0;
            }

            if ($payload === []) {
                return 0;
            }

            $payload['updated_at'] = date('Y-m-d H:i:s');

            return $this->query()
                ->where('id', $id)
                ->update($payload);
        });
    }

    public function softDeleteById(int $id): int
    {
        return $this->run('form_templates.softDeleteById', function () use ($id): int {
            return $this->query()
                ->where('id', $id)
                ->update([
                    'updated_at' => date('Y-m-d H:i:s'),
                    'is_active' => 0,
                ]);
        });
    }

    private function applyFilters(QueryBuilder $query, array $filters): void
    {
        if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $needle = '%' . trim($filters['q']) . '%';
            $query->whereRaw('(name LIKE :q_name OR description LIKE :q_description)', [
                'q_name' => $needle,
                'q_description' => $needle,
            ]);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (int) (bool) $filters['is_active']);
        }

        if (isset($filters['template_key']) && is_string($filters['template_key']) && trim($filters['template_key']) !== '') {
            $query->where('template_key', trim($filters['template_key']));
        }

        if (isset($filters['name']) && is_string($filters['name']) && trim($filters['name']) !== '') {
            $query->where('name', '%' . trim($filters['name']) . '%', 'LIKE');
        }

        if (isset($filters['description']) && is_string($filters['description']) && trim($filters['description']) !== '') {
            $query->where('description', '%' . trim($filters['description']) . '%', 'LIKE');
        }
    }

    private function resolveSortColumn(string $sort): string
    {
        return match ($sort) {
            'name' => 'name',
            'description' => 'description',
            'created_at' => 'created_at',
            default => 'name',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FormTemplateVersionRepository extends BaseRepository
{
    private const CREATOR_NAME_SQL = "NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '') AS created_by_name";

    protected function table(): string
    {
        return 'form_template_versions';
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->run('form_template_versions.findById', fn (): ?array => $this->query()
            ->select([
                'form_template_versions.*',
                self::CREATOR_NAME_SQL,
            ])
            ->join('users u', 'u.id', '=', 'form_template_versions.created_by_user_id', 'LEFT')
            ->where('form_template_versions.id', $id)
            ->first());
    }

    /** @return array<int, array<string, mixed>> */
    public function listByTemplateId(int $templateId): array
    {
        return $this->run('form_template_versions.listByTemplateId', fn (): array => $this->query()
            ->select([
                'form_template_versions.*',
                self::CREATOR_NAME_SQL,
            ])
            ->join('users u', 'u.id', '=', 'form_template_versions.created_by_user_id', 'LEFT')
            ->where('form_template_versions.template_id', $templateId)
            ->orderBy('form_template_versions.version_no', 'DESC')
            ->get());
    }

    /** @return array<string, mixed>|null */
    public function findByTemplateAndId(int $templateId, int $versionId): ?array
    {
        return $this->run('form_template_versions.findByTemplateAndId', fn (): ?array => $this->query()
            ->select([
                'form_template_versions.*',
                self::CREATOR_NAME_SQL,
            ])
            ->join('users u', 'u.id', '=', 'form_template_versions.created_by_user_id', 'LEFT')
            ->where('form_template_versions.template_id', $templateId)
            ->where('form_template_versions.id', $versionId)
            ->first());
    }

    /** @return array<string, mixed>|null */
    public function findLatestByTemplateId(int $templateId): ?array
    {
        return $this->run('form_template_versions.findLatestByTemplateId', fn (): ?array => $this->query()
            ->select([
                'form_template_versions.*',
                self::CREATOR_NAME_SQL,
            ])
            ->join('users u', 'u.id', '=', 'form_template_versions.created_by_user_id', 'LEFT')
            ->where('form_template_versions.template_id', $templateId)
            ->orderBy('form_template_versions.version_no', 'DESC')
            ->first());
    }

    /** @param array<string, mixed> $schema */
    public function createVersion(int $templateId, int $createdByUserId, array $schema, ?string $publishedAt = null, ?PDO $pdo = null): int
    {
        return $this->run('form_template_versions.createVersion', function () use ($templateId, $createdByUserId, $schema, $publishedAt, $pdo): int {
            $connection = $pdo ?? $this->pdo();
            $versionNo = $this->nextVersionNumber($templateId, $connection);

            $stmt = $connection->prepare(
                 'INSERT INTO form_template_versions (template_id, version_no, schema_json, published_at, created_by_user_id, created_at, updated_at)
                 VALUES (:template_id, :version_no, :schema_json, :published_at, :created_by_user_id, :created_at, :updated_at)'
            );

            $now = date('Y-m-d H:i:s');
            $stmt->execute([
                'template_id' => $templateId,
                'version_no' => $versionNo,
                'schema_json' => json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'published_at' => $publishedAt,
                'created_by_user_id' => $createdByUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) $connection->lastInsertId();
        });
    }

    public function nextVersionNumber(int $templateId, ?PDO $pdo = null): int
    {
        return $this->run('form_template_versions.nextVersionNumber', function () use ($templateId, $pdo): int {
            $connection = $pdo ?? $this->pdo();
            $stmt = $connection->prepare(
                'SELECT COALESCE(MAX(version_no), 0) + 1 AS next_version
                  FROM form_template_versions
                 WHERE template_id = :template_id
                 FOR UPDATE'
            );
            $stmt->execute(['template_id' => $templateId]);

            $value = $stmt->fetchColumn();
            return max(1, (int) ($value !== false ? $value : 1));
        });
    }
}

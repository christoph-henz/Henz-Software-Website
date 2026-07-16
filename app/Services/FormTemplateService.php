<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Database;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Exceptions\ValidationHttpException;
use App\DTO\Form\FormTemplateDto;
use App\DTO\Form\FormTemplateVersionDto;
use App\Repositories\FormTemplateRepository;
use App\Repositories\FormTemplateVersionRepository;

final class FormTemplateService
{
    private const AUTO_CONTEXT_LINE_TEMPLATE = 'Klient: {{name}} | Erstellt am: {{created_date}}';

    public function __construct(
        private readonly Database $db,
        private readonly FormTemplateRepository $templates,
        private readonly FormTemplateVersionRepository $versions,
    ) {
    }

    /** @param array<string, mixed> $filters */
    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function list(array $filters = [], string $sort = 'name', string $direction = 'ASC', int $page = 1, int $perPage = 20): array
    {
        $result = $this->templates->list($filters, $sort, $direction, $page, $perPage);
        $items = array_map(
            static fn (array $row): array => FormTemplateDto::fromRow($row)->toArray(),
            $result['data']
        );

        return [
            'data' => $items,
            'meta' => [
                'page' => max(1, $page),
                'per_page' => max(1, min(100, $perPage)),
                'total' => $result['total'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function getById(int $id, bool $includeDeleted = false): array
    {
        $row = $this->templates->findById($id, $includeDeleted);
        if ($row === null) {
            throw new NotFoundHttpException('Form template not found');
        }

        return FormTemplateDto::fromRow($row)->toArray();
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function createTemplate(array $payload): array
    {
        $this->validateTemplatePayload($payload, true);

        $templateKey = trim((string) $payload['template_key']);
        if ($this->templates->existsByTemplateKey($templateKey)) {
            throw new ValidationHttpException([
                'template_key' => ['template_key already exists'],
            ]);
        }

        $id = $this->templates->create([
            'template_key' => $templateKey,
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'is_active' => $this->normalizeBool($payload['is_active'] ?? true),
        ]);

        return $this->requireTemplate($id);
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function updateTemplate(int $id, array $payload): array
    {
        $this->validateTemplatePayload($payload, false);
        $existing = $this->templates->findById($id);
        if ($existing === null) {
            throw new NotFoundHttpException('Form template not found');
        }

        $updatePayload = [];

        if (array_key_exists('template_key', $payload)) {
            $templateKey = trim((string) $payload['template_key']);
            if ($this->templates->existsByTemplateKey($templateKey, $id)) {
                throw new ValidationHttpException([
                    'template_key' => ['template_key already exists'],
                ]);
            }
            $updatePayload['template_key'] = $templateKey;
        }

        if (array_key_exists('name', $payload)) {
            $updatePayload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('description', $payload)) {
            $updatePayload['description'] = trim((string) $payload['description']);
        }

        if (array_key_exists('is_active', $payload)) {
            $updatePayload['is_active'] = $this->normalizeBool($payload['is_active']);
        }

        if ($updatePayload === [] || !$this->hasEffectiveChanges($existing, $updatePayload)) {
            throw new ValidationHttpException([
                'payload' => ['no_effective_changes'],
            ]);
        }

        $this->templates->updateById($id, $updatePayload);
        return $this->requireTemplate($id);
    }

    /** @return array<string, mixed> */
    public function softDeleteTemplate(int $id): array
    {
        $existing = $this->templates->findById($id);
        if ($existing === null) {
            throw new NotFoundHttpException('Form template not found');
        }

        $this->templates->softDeleteById($id);

        return [
            'id' => $id,
            'deleted' => true,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listVersions(int $templateId): array
    {
        if ($templateId <= 0) {
            throw new ValidationHttpException(['template_id' => ['template_id must be a positive integer']]);
        }

        $this->ensureTemplateExists($templateId);

        $rows = $this->versions->listByTemplateId($templateId);
        return array_map(
            static fn (array $row): array => FormTemplateVersionDto::fromRow($row)->toArray(),
            $rows
        );
    }

    /** @return array<string, mixed> */
    public function getVersionById(int $templateId, int $versionId): array
    {
        if ($templateId <= 0) {
            throw new ValidationHttpException(['template_id' => ['template_id must be a positive integer']]);
        }

        if ($versionId <= 0) {
            throw new ValidationHttpException(['version_id' => ['version_id must be a positive integer']]);
        }

        $this->ensureTemplateExists($templateId);

        $row = $this->versions->findByTemplateAndId($templateId, $versionId);
        if ($row === null) {
            throw new NotFoundHttpException('Template version not found');
        }

        return FormTemplateVersionDto::fromRow($row)->toArray();
    }

    /** @param array<string, mixed> $schema */
    /** @return array<string, mixed> */
    public function publishVersion(int $templateId, int $actorUserId, array $schema): array
    {
        if ($templateId <= 0) {
            throw new ValidationHttpException(['template_id' => ['template_id must be a positive integer']]);
        }

        if ($actorUserId <= 0) {
            throw new ValidationHttpException(['actor_user_id' => ['actor_user_id must be a positive integer']]);
        }

        $schema = $this->enforceAutomaticContextLine($schema);
        $this->validateSchemaForPublish($schema);

        $this->ensureTemplateExists($templateId);

        // S-004: publish timestamp is controlled by the server.
        $effectivePublishedAt = date('Y-m-d H:i:s');

        $versionId = $this->db->transaction(function ($pdo) use ($templateId, $actorUserId, $schema, $effectivePublishedAt): int {
            return $this->versions->createVersion($templateId, $actorUserId, $schema, $effectivePublishedAt, $pdo);
        });

        $row = $this->versions->findById($versionId);
        if ($row === null) {
            throw new NotFoundHttpException('Template version not found after creation');
        }

        return FormTemplateVersionDto::fromRow($row)->toArray();
    }

    /** @param array<string, mixed> $schema */
    private function validateSchemaForPublish(array $schema): void
    {
        if ($schema === []) {
            throw new ValidationHttpException([
                'schema_json' => ['schema_json must not be empty'],
            ]);
        }

        if (!$this->schemaContainsPublishableField($schema)) {
            throw new ValidationHttpException([
                'schema_json' => ['schema_json must contain at least one field with field_key and type'],
            ]);
        }
    }

    /** @param array<int, mixed> $schema */
    private function schemaContainsPublishableField(array $schema): bool
    {
        foreach ($schema as $item) {
            if (!is_array($item)) {
                continue;
            }

            $fieldKey = trim((string) ($item['field_key'] ?? ''));
            $type = trim((string) ($item['type'] ?? ''));

            if ($fieldKey !== '' && $type !== '' && $type !== 'section' && $type !== 'letterhead') {
                return true;
            }

            $children = $item['items'] ?? null;
            if (is_array($children) && $this->schemaContainsPublishableField($children)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $schema */
    /** @return array<string, mixed> */
    private function enforceAutomaticContextLine(array $schema): array
    {
        foreach ($schema as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((string) ($item['type'] ?? '') === 'letterhead') {
                $schema[$index]['context_line'] = self::AUTO_CONTEXT_LINE_TEMPLATE;
            }
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function requireTemplate(int $id): array
    {
        $row = $this->templates->findById($id);
        if ($row === null) {
            throw new NotFoundHttpException('Form template not found');
        }

        return FormTemplateDto::fromRow($row)->toArray();
    }

    private function ensureTemplateExists(int $id): void
    {
        if ($this->templates->findById($id) === null) {
            throw new NotFoundHttpException('Form template not found');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateTemplatePayload(array $payload, bool $isCreate): void
    {
        $errors = [];

        if ($isCreate || array_key_exists('template_key', $payload)) {
            $templateKey = trim((string) ($payload['template_key'] ?? ''));
            if ($templateKey === '') {
                $errors['template_key'][] = 'template_key is required';
            } elseif (strlen($templateKey) < 3 || strlen($templateKey) > 64) {
                $errors['template_key'][] = 'template_key must be between 3 and 64 characters';
            } elseif (!preg_match('/^[a-z0-9_-]+$/', $templateKey)) {
                $errors['template_key'][] = 'template_key may only contain lowercase letters, numbers, underscore and dash';
            }
        }

        if ($isCreate || array_key_exists('name', $payload)) {
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                $errors['name'][] = 'name is required';
            } elseif (strlen($name) < 3 || strlen($name) > 120) {
                $errors['name'][] = 'name must be between 3 and 120 characters';
            }
        }

        if ($isCreate || array_key_exists('description', $payload)) {
            $description = trim((string) ($payload['description'] ?? ''));
            if ($description === '') {
                $errors['description'][] = 'description is required';
            } elseif (strlen($description) < 10 || strlen($description) > 4000) {
                $errors['description'][] = 'description must be between 10 and 4000 characters';
            }
        }

        if (array_key_exists('is_active', $payload)) {
            try {
                $this->normalizeBool($payload['is_active']);
            } catch (ValidationHttpException $exception) {
                $errors = array_merge($errors, $exception->errors());
            }
        }

        if ($errors !== []) {
            throw new ValidationHttpException($errors);
        }
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 0 || $value === 1) {
                return $value === 1;
            }
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false'], true)) {
                return false;
            }
        }

        throw new ValidationHttpException([
            'is_active' => ['is_active must be a boolean'],
        ]);
    }

    /** @param array<string, mixed> $existing */
    /** @param array<string, mixed> $updatePayload */
    private function hasEffectiveChanges(array $existing, array $updatePayload): bool
    {
        foreach ($updatePayload as $field => $value) {
            if ($field === 'is_active') {
                $current = (bool) ($existing['is_active'] ?? false);
                if ($current !== (bool) $value) {
                    return true;
                }
                continue;
            }

            $current = isset($existing[$field]) ? trim((string) $existing[$field]) : '';
            $incoming = trim((string) $value);
            if ($current !== $incoming) {
                return true;
            }
        }

        return false;
    }
}

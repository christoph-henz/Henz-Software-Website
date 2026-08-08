<?php

declare(strict_types=1);

namespace App\DTO\Form;

final readonly class FormTemplateVersionDto
{
    /** @param array<string, mixed> $schema */
    public function __construct(
        public ?int $id,
        public int $templateId,
        public int $versionNo,
        public array $schema,
        public ?string $publishedAt,
        public ?int $createdByUserId,
        public ?string $createdByName,
        public ?string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (int) ($row['template_id'] ?? 0),
            (int) ($row['version_no'] ?? 0),
            self::decodeJsonField($row['schema_json'] ?? null),
            isset($row['published_at']) ? (string) $row['published_at'] : null,
            isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            isset($row['created_by_name']) ? (string) $row['created_by_name'] : null,
            isset($row['created_at']) ? (string) $row['created_at'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->templateId,
            'version_no' => $this->versionNo,
            'schema_json' => $this->schema,
            'published_at' => $this->publishedAt,
            'created_by_user_id' => $this->createdByUserId,
            'created_by_name' => $this->createdByName,
            'created_at' => $this->createdAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function decodeJsonField(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}

<?php

declare(strict_types=1);

namespace App\DTO\Form;

final readonly class FormTemplateDto
{
    public function __construct(
        public ?int $id,
        public string $templateKey,
        public string $name,
        public ?string $description,
        public bool $isActive,
        public ?int $currentVersion,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $deletedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (string) ($row['template_key'] ?? ''),
            (string) ($row['name'] ?? ''),
            isset($row['description']) ? (string) $row['description'] : null,
            (bool) ($row['is_active'] ?? false),
                isset($row['current_version']) ? (int) $row['current_version'] : null,
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            isset($row['deleted_at']) ? (string) $row['deleted_at'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'template_key' => $this->templateKey,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->isActive,
                'current_version' => $this->currentVersion,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }
}

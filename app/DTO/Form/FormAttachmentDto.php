<?php

declare(strict_types=1);

namespace App\DTO\Form;

final readonly class FormAttachmentDto
{
    public function __construct(
        public ?int $id,
        public int $sessionRecordId,
        public string $storagePath,
        public string $originalFilename,
        public string $checksumSha256,
        public ?string $uploadedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (int) ($row['session_record_id'] ?? 0),
            (string) ($row['storage_path'] ?? ''),
            (string) ($row['original_filename'] ?? ''),
            (string) ($row['checksum_sha256'] ?? ''),
            isset($row['uploaded_at']) ? (string) $row['uploaded_at'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_record_id' => $this->sessionRecordId,
            'storage_path' => $this->storagePath,
            'original_filename' => $this->originalFilename,
            'checksum_sha256' => $this->checksumSha256,
            'uploaded_at' => $this->uploadedAt,
        ];
    }
}

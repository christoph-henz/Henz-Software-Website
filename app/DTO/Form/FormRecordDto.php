<?php

declare(strict_types=1);

namespace App\DTO\Form;

final readonly class FormRecordDto
{
    /** @param array<string, mixed>|null $payload */
    /** @param array<string, mixed>|null $encryptedPayload */
    public function __construct(
        public ?int $id,
        public int $bookingId,
        public int $clientId,
        public int $templateId,
        public int $templateVersionId,
        public string $status,
        public ?array $payload,
        public ?array $encryptedPayload,
        public ?int $createdByUserId,
        public ?int $updatedByUserId,
        public ?string $finalizedAt,
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
            (int) ($row['booking_id'] ?? 0),
            (int) ($row['client_id'] ?? 0),
            (int) ($row['template_id'] ?? 0),
            (int) ($row['template_version_id'] ?? 0),
            (string) ($row['status'] ?? 'draft'),
            self::decodeJsonField($row['payload_json'] ?? null),
            self::decodeJsonField($row['encrypted_payload_json'] ?? null),
            isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            isset($row['updated_by_user_id']) ? (int) $row['updated_by_user_id'] : null,
            isset($row['finalized_at']) ? (string) $row['finalized_at'] : null,
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
            'booking_id' => $this->bookingId,
            'client_id' => $this->clientId,
            'template_id' => $this->templateId,
            'template_version_id' => $this->templateVersionId,
            'status' => $this->status,
            'payload_json' => $this->payload,
            'encrypted_payload_json' => $this->encryptedPayload,
            'created_by_user_id' => $this->createdByUserId,
            'updated_by_user_id' => $this->updatedByUserId,
            'finalized_at' => $this->finalizedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function decodeJsonField(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}

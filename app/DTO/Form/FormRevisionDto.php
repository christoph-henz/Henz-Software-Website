<?php

declare(strict_types=1);

namespace App\DTO\Form;

final readonly class FormRevisionDto
{
    /** @param array<string, mixed>|null $payloadSnapshot */
    /** @param array<string, mixed>|null $changedFields */
    public function __construct(
        public ?int $id,
        public int $sessionRecordId,
        public int $revisionNo,
        public ?array $payloadSnapshot,
        public ?array $changedFields,
        public string $changeReason,
        public ?int $changedByUserId,
        public ?string $changedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (int) ($row['session_record_id'] ?? 0),
            (int) ($row['revision_no'] ?? 0),
            self::decodeJsonField($row['payload_json_snapshot'] ?? null),
            self::decodeJsonField($row['changed_fields_json'] ?? null),
            (string) ($row['change_reason'] ?? ''),
            isset($row['changed_by_user_id']) ? (int) $row['changed_by_user_id'] : null,
            isset($row['changed_at']) ? (string) $row['changed_at'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_record_id' => $this->sessionRecordId,
            'revision_no' => $this->revisionNo,
            'payload_json_snapshot' => $this->payloadSnapshot,
            'changed_fields_json' => $this->changedFields,
            'change_reason' => $this->changeReason,
            'changed_by_user_id' => $this->changedByUserId,
            'changed_at' => $this->changedAt,
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

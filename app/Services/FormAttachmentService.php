<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Exceptions\ValidationHttpException;
use App\DTO\Form\FormAttachmentDto;
use App\Repositories\FormAttachmentRepository;
use App\Repositories\FormAuditRepository;
use App\Repositories\FormRecordRepository;
use Throwable;

final class FormAttachmentService
{
    public function __construct(
        private readonly FormAttachmentRepository $attachments,
        private readonly FormRecordRepository $records,
        private readonly FormAuditRepository $audits,
    ) {
    }

    /** @param array<string, mixed> $payload */
    /** @param array<string, mixed> $auditContext */
    /** @return array<string, mixed> */
    public function createAttachment(array $payload, int $actorUserId, array $auditContext = []): array
    {
        $this->validateAttachmentPayload($payload);

        $recordId = (int) $payload['session_record_id'];
        if ($this->records->findById($recordId) === null) {
            throw new NotFoundHttpException('Form record not found');
        }

        $id = $this->attachments->create([
            'session_record_id' => $recordId,
            'storage_path' => trim((string) $payload['storage_path']),
            'original_filename' => trim((string) $payload['original_filename']),
            'checksum_sha256' => strtolower(trim((string) $payload['checksum_sha256'])),
            'uploaded_at' => $payload['uploaded_at'] ?? null,
        ]);

        $this->writeAuditSafe([
            'actor_user_id' => $actorUserId,
            'action' => 'create',
            'resource_type' => 'session_attachment',
            'resource_id' => (string) $id,
            'field_scope' => $auditContext['field_scope'] ?? null,
            'purpose_code' => $auditContext['purpose_code'] ?? null,
            'ip_address' => $auditContext['ip_address'] ?? null,
            'user_agent' => $auditContext['user_agent'] ?? null,
        ]);

        $row = $this->attachments->findById($id);
        if ($row === null) {
            throw new NotFoundHttpException('Form attachment not found after create');
        }

        return FormAttachmentDto::fromRow($row)->toArray();
    }

    /** @param array<string, mixed> $auditContext */
    /** @return array<int, array<string, mixed>> */
    public function listByFormRecord(int $sessionRecordId, array $auditContext = []): array
    {
        if ($this->records->findById($sessionRecordId) === null) {
            throw new NotFoundHttpException('Form record not found');
        }

        $rows = $this->attachments->listByFormRecordId($sessionRecordId);

        return array_map(
            static fn (array $row): array => FormAttachmentDto::fromRow($row)->toArray(),
            $rows
        );
    }

    /** @return array<string, mixed> */
    public function getAttachment(int $sessionRecordId, int $attachmentId): array
    {
        if ($this->records->findById($sessionRecordId) === null) {
            throw new NotFoundHttpException('Form record not found');
        }

        $row = $this->attachments->findByIdForRecord($attachmentId, $sessionRecordId);
        if ($row === null) {
            throw new NotFoundHttpException('Form attachment not found');
        }

        return FormAttachmentDto::fromRow($row)->toArray();
    }

    /** @return array<string, mixed>|null */
    public function findByChecksum(string $checksumSha256): ?array
    {
        $checksum = strtolower(trim($checksumSha256));
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new ValidationHttpException([
                'checksum_sha256' => ['checksum_sha256 must be a 64-char lowercase hex sha256'],
            ]);
        }

        $row = $this->attachments->findByChecksum($checksum);
        if ($row === null) {
            return null;
        }

        return FormAttachmentDto::fromRow($row)->toArray();
    }

    /** @param array<string, mixed> $auditContext */
    public function auditDownload(int $attachmentId, int $actorUserId, array $auditContext = []): void
    {
        $this->writeAuditSafe([
            'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'action' => 'view',
            'resource_type' => 'session_attachment',
            'resource_id' => (string) $attachmentId,
            'field_scope' => 'attachment_file',
            'purpose_code' => $auditContext['purpose_code'] ?? 'ATTACHMENT_DOWNLOAD',
            'ip_address' => $auditContext['ip_address'] ?? null,
            'user_agent' => $auditContext['user_agent'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function writeAuditSafe(array $payload): void
    {
        try {
            $this->audits->create($payload);
        } catch (Throwable) {
            // S-011 policy: audit failures must not block business operations.
            return;
        }
    }

    public function ensureRecordExists(int $sessionRecordId): void
    {
        if ($sessionRecordId <= 0) {
            throw new ValidationHttpException([
                'session_record_id' => ['session_record_id must be a positive integer'],
            ]);
        }

        if ($this->records->findById($sessionRecordId) === null) {
            throw new NotFoundHttpException('Form record not found');
        }
    }

    public function assertDuplicateFitsRecord(array $existingAttachment, int $sessionRecordId): void
    {
        $existingRecordId = (int) ($existingAttachment['session_record_id'] ?? 0);
        if ($existingRecordId > 0 && $existingRecordId !== $sessionRecordId) {
            throw new HttpException(
                'Attachment with identical checksum already exists on another session record',
                409,
                'ATTACHMENT_DUPLICATE_CHECKSUM_CONFLICT'
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateAttachmentPayload(array $payload): void
    {
        $errors = [];

        if ((int) ($payload['session_record_id'] ?? 0) <= 0) {
            $errors['session_record_id'][] = 'session_record_id must be a positive integer';
        }

        if (trim((string) ($payload['storage_path'] ?? '')) === '') {
            $errors['storage_path'][] = 'storage_path is required';
        }

        if (trim((string) ($payload['original_filename'] ?? '')) === '') {
            $errors['original_filename'][] = 'original_filename is required';
        }

        $checksum = strtolower(trim((string) ($payload['checksum_sha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            $errors['checksum_sha256'][] = 'checksum_sha256 must be a 64-char lowercase hex sha256';
        }

        if ($errors !== []) {
            throw new ValidationHttpException($errors);
        }
    }
}

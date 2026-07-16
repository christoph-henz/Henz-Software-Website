<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Exceptions\ValidationHttpException;
use App\DTO\Form\FormRecordDto;
use App\DTO\Form\FormRevisionDto;
use App\Repositories\FormAuditRepository;
use App\Repositories\FormRecordRepository;
use App\Repositories\FormRevisionRepository;
use App\Repositories\FormTemplateRepository;
use App\Repositories\FormTemplateVersionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class FormRecordService
{
    private const USE_FORMS_PERMISSION = 'use_form_templates_for_clients';

    public function __construct(
        private readonly Database $db,
        private readonly FormRecordRepository $records,
        private readonly FormRevisionRepository $revisions,
        private readonly FormAuditRepository $audits,
        private readonly FormTemplateRepository $templates,
        private readonly FormTemplateVersionRepository $versions,
        private readonly FormRecordValidationService $payloadValidator,
        private readonly EncryptionService $encryption,
        private readonly ClientFieldEncryptionService $clientCrypto,
    ) {
    }

    /** @param array<string, mixed> $payload */
    /** @param array<string, mixed> $auditContext */
    /** @return array<string, mixed> */
    public function createRecord(array $payload, int $actorUserId, array $auditContext = []): array
    {
        $this->validateRecordPayload($payload, true);

        $context = $this->ensureRecordContext(
            (int) $payload['booking_id'],
            (int) $payload['client_id'],
            (int) $payload['template_id'],
            (int) $payload['template_version_id'],
            true
        );

        $schema = $this->extractVersionSchema($context['template_version']);
        $timezone = $this->resolveTimezone((string) ($context['client']['timezone'] ?? ''));
        $incomingPayload = is_array($payload['payload_json'] ?? null) ? $payload['payload_json'] : [];
        $this->assertWritableFields($incomingPayload, $schema, 'draft');
        $normalizedPayload = $this->payloadValidator->validateAndNormalizePayload(
            $incomingPayload,
            $schema,
            $timezone,
            true
        );

        $encryptedPayload = $this->encryptPayload($normalizedPayload, $schema);

        $recordId = $this->db->transaction(function ($pdo) use ($payload, $actorUserId, $auditContext, $normalizedPayload, $encryptedPayload): int {
            $recordId = $this->records->create([
                'booking_id' => (int) $payload['booking_id'],
                'client_id' => (int) $payload['client_id'],
                'template_id' => (int) $payload['template_id'],
                'template_version_id' => (int) $payload['template_version_id'],
                'status' => 'draft',
                'payload_json' => $encryptedPayload,
                'encrypted_payload_json' => $encryptedPayload,
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
                'finalized_at' => null,
            ], $pdo);

            $revisionNo = $this->revisions->nextRevisionNo($recordId, $pdo);
            $this->revisions->createRevision(
                $recordId,
                $revisionNo,
                $normalizedPayload,
                array_values(array_keys($normalizedPayload)),
                'Created',
                $actorUserId,
                $pdo
            );

            $this->writeAudit($actorUserId, 'create', 'session_record', (string) $recordId, $auditContext, $pdo);

            return $recordId;
        });

        return $this->requireEnrichedRecord($recordId, false);
    }

    /** @param array<string, mixed> $payload */
    /** @param array<string, mixed> $auditContext */
    /** @return array<string, mixed> */
    public function updateRecord(int $recordId, array $payload, int $actorUserId, array $auditContext = []): array
    {
        $existing = $this->records->findById($recordId);
        if ($existing === null) {
            throw new NotFoundHttpException('Form record not found');
        }

        if ((string) ($existing['status'] ?? '') !== 'draft') {
            throw new HttpException('Only draft records can be updated', 403);
        }

        $this->validateRecordPayload($payload, false);

        $context = $this->ensureRecordContext(
            (int) ($existing['booking_id'] ?? 0),
            (int) ($existing['client_id'] ?? 0),
            (int) ($existing['template_id'] ?? 0),
            (int) ($existing['template_version_id'] ?? 0),
            true
        );

        $schema = $this->extractVersionSchema($context['template_version']);
        $timezone = $this->resolveTimezone((string) ($context['client']['timezone'] ?? ''));
        $currentPayload = $this->resolveCurrentPayload($existing, $schema);

        $incomingPatch = is_array($payload['payload_json'] ?? null) ? $payload['payload_json'] : [];
        $this->assertWritableFields($incomingPatch, $schema, 'draft');
        $mergedPayload = array_merge($currentPayload, $incomingPatch);
        $normalizedPayload = $this->payloadValidator->validateAndNormalizePayload($mergedPayload, $schema, $timezone, true);
        $changedFields = $this->resolveChangedFields($currentPayload, $normalizedPayload);
        $changeReason = trim((string) ($payload['change_reason'] ?? ''));
        if ($changeReason === '') {
            $changeReason = 'Updated';
        }

        $encryptedPayload = $this->encryptPayload($normalizedPayload, $schema);

        $this->db->transaction(function ($pdo) use ($recordId, $actorUserId, $auditContext, $normalizedPayload, $encryptedPayload, $changedFields, $changeReason): void {
            $updatePayload = [
                'payload_json' => $encryptedPayload,
                'encrypted_payload_json' => $encryptedPayload,
                'updated_by_user_id' => $actorUserId,
            ];
            $this->records->updateById($recordId, $updatePayload, $pdo);

            $revisionNo = $this->revisions->nextRevisionNo($recordId, $pdo);
            $this->revisions->createRevision(
                $recordId,
                $revisionNo,
                $normalizedPayload,
                $changedFields,
                $changeReason,
                $actorUserId,
                $pdo
            );

            $this->writeAudit($actorUserId, 'update', 'session_record', (string) $recordId, $auditContext, $pdo);
        });

        return $this->requireEnrichedRecord($recordId, false);
    }

    /** @param array<string, mixed> $auditContext */
    /** @return array{record: array<string, mixed>, no_op: bool} */
    public function finalizeRecord(int $recordId, int $actorUserId, string $changeReason = 'Finalized', array $auditContext = []): array
    {
        $existing = $this->records->findById($recordId);
        if ($existing === null) {
            throw new NotFoundHttpException('Form record not found');
        }

        if ((string) ($existing['status'] ?? '') === 'final') {
            return [
                'record' => $this->requireEnrichedRecord($recordId, false),
                'no_op' => true,
            ];
        }

        if ((string) ($existing['status'] ?? '') !== 'draft') {
            throw new ValidationHttpException([
                'status' => ['status must be draft to finalize'],
            ]);
        }

        $context = $this->ensureRecordContext(
            (int) ($existing['booking_id'] ?? 0),
            (int) ($existing['client_id'] ?? 0),
            (int) ($existing['template_id'] ?? 0),
            (int) ($existing['template_version_id'] ?? 0),
            false
        );

        $schema = $this->extractVersionSchema($context['template_version']);
        $timezone = $this->resolveTimezone((string) ($context['client']['timezone'] ?? ''));
        $currentPayload = $this->resolveCurrentPayload($existing, $schema);

        $this->payloadValidator->validateAndNormalizePayload($currentPayload, $schema, $timezone, true);

        $trimmedReason = trim($changeReason);
        if ($trimmedReason === '') {
            $trimmedReason = 'Finalized';
        }

        $finalizedAt = date('Y-m-d H:i:s');
        $encryptedPayload = $this->encryptPayload($currentPayload, $schema);

        $this->db->transaction(function ($pdo) use ($recordId, $actorUserId, $auditContext, $currentPayload, $trimmedReason, $finalizedAt, $encryptedPayload): void {
            $this->records->updateById($recordId, [
                'status' => 'final',
                'finalized_at' => $finalizedAt,
                'payload_json' => $encryptedPayload,
                'encrypted_payload_json' => $encryptedPayload,
                'updated_by_user_id' => $actorUserId,
            ], $pdo);

            $revisionNo = $this->revisions->nextRevisionNo($recordId, $pdo);
            $this->revisions->createRevision(
                $recordId,
                $revisionNo,
                $currentPayload,
                ['status', 'finalized_at'],
                $trimmedReason,
                $actorUserId,
                $pdo
            );

            $this->writeAudit($actorUserId, 'update', 'session_record', (string) $recordId, $auditContext, $pdo);
        });

        return [
            'record' => $this->requireEnrichedRecord($recordId, false),
            'no_op' => false,
        ];
    }

    /**
     * @param array<string, mixed> $payloadPatch
     * @param array<string, mixed> $auditContext
     * @return array<string, mixed>
     */
    public function amendFinalRecord(int $recordId, array $payloadPatch, string $changeReason, int $actorUserId, array $auditContext = []): array
    {
        $existing = $this->records->findById($recordId);
        if ($existing === null) {
            throw new NotFoundHttpException('Form record not found');
        }

        if ((string) ($existing['status'] ?? '') !== 'final') {
            throw new ValidationHttpException([
                'status' => ['record must be final for S-007 amend flow'],
            ]);
        }

        if ($payloadPatch === []) {
            throw new ValidationHttpException([
                'payload_json' => ['payload_json patch is required'],
            ]);
        }

        $trimmedReason = trim($changeReason);
        if ($trimmedReason === '') {
            throw new ValidationHttpException([
                'change_reason' => ['change_reason is required for final record amendment'],
            ]);
        }

        $context = $this->ensureRecordContext(
            (int) ($existing['booking_id'] ?? 0),
            (int) ($existing['client_id'] ?? 0),
            (int) ($existing['template_id'] ?? 0),
            (int) ($existing['template_version_id'] ?? 0),
            false
        );

        $schema = $this->extractVersionSchema($context['template_version']);
        $timezone = $this->resolveTimezone((string) ($context['client']['timezone'] ?? ''));
        $currentPayload = $this->resolveCurrentPayload($existing, $schema);

        $this->assertWritableFields($payloadPatch, $schema, 'final');
        $mergedPayload = array_merge($currentPayload, $payloadPatch);
        $normalizedPayload = $this->payloadValidator->validateAndNormalizePayload($mergedPayload, $schema, $timezone, true);
        $changedFields = $this->resolveChangedFields($currentPayload, $normalizedPayload);

        if ($changedFields === []) {
            throw new ValidationHttpException([
                'payload_json' => ['no effective changes for final amendment'],
            ]);
        }

        $encryptedPayload = $this->encryptPayload($normalizedPayload, $schema);

        $this->db->transaction(function ($pdo) use ($recordId, $actorUserId, $auditContext, $normalizedPayload, $encryptedPayload, $changedFields, $changeReason): void {
            $this->records->updateById($recordId, [
                'payload_json' => $encryptedPayload,
                'encrypted_payload_json' => $encryptedPayload,
                'updated_by_user_id' => $actorUserId,
            ], $pdo);

            $revisionNo = $this->revisions->nextRevisionNo($recordId, $pdo);
            $this->revisions->createRevision(
                $recordId,
                $revisionNo,
                $normalizedPayload,
                $changedFields,
                $changeReason,
                $actorUserId,
                $pdo
            );

            $this->writeAudit($actorUserId, 'update', 'session_record', (string) $recordId, $auditContext, $pdo);
        });

        return $this->requireEnrichedRecord($recordId, false);
    }

    /** @param array<string, mixed> $payload */
    /** @param array<string, mixed> $auditContext */
    /** @return array<string, mixed> */
    public function updateRevision(int $revisionId, array $payload, int $actorUserId, array $auditContext = []): array
    {
        $revision = $this->revisions->findById($revisionId);
        if ($revision === null) {
            throw new NotFoundHttpException('Form revision not found');
        }

        if (!isset($payload['change_reason']) || trim((string) $payload['change_reason']) === '') {
            throw new ValidationHttpException(['change_reason' => ['change_reason is required for revision update']]);
        }

        $this->db->transaction(function ($pdo) use ($revisionId, $payload, $actorUserId, $auditContext): void {
            $this->revisions->updateRevisionById($revisionId, [
                'payload_json_snapshot' => $payload['payload_json_snapshot'] ?? null,
                'changed_fields_json' => $payload['changed_fields_json'] ?? null,
                'change_reason' => (string) $payload['change_reason'],
                'changed_by_user_id' => $actorUserId,
            ], $pdo);

            $this->writeAudit($actorUserId, 'update', 'session_revision', (string) $revisionId, $auditContext, $pdo);
        });

        $updated = $this->revisions->findById($revisionId);
        if ($updated === null) {
            throw new NotFoundHttpException('Form revision not found after update');
        }

        return FormRevisionDto::fromRow($updated)->toArray();
    }

    /** @param array<string, mixed> $auditContext */
    /** @return array<string, mixed> */
    public function softDeleteRecord(int $recordId, int $actorUserId, array $auditContext = []): array
    {
        $record = $this->records->findById($recordId);
        if ($record === null) {
            throw new NotFoundHttpException('Form record not found');
        }

        $this->db->transaction(function ($pdo) use ($recordId, $actorUserId, $auditContext): void {
            $this->records->softDeleteById($recordId, $actorUserId, $pdo);
            $this->writeAudit($actorUserId, 'delete', 'session_record', (string) $recordId, $auditContext, $pdo);
        });

        return ['id' => $recordId, 'deleted' => true];
    }

    /** @param array<string, mixed> $auditContext */
    /** @return array<string, mixed> */
    public function getEnrichedRecord(int $recordId, array $auditContext = [], bool $includeRevisions = false, bool $logReadOperations = false): array
    {
        $record = $this->requireEnrichedRecord($recordId, $includeRevisions);

        if ($logReadOperations) {
            $this->writeAuditFromService('view', 'session_record', (string) $recordId, $auditContext);
        }

        return $record;
    }

    /** @param array<string, mixed> $filters */
    /** @param array<string, mixed> $auditContext */
    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function listEnriched(
        array $filters = [],
        string $sort = 'created_at',
        string $direction = 'DESC',
        int $page = 1,
        int $perPage = 20,
        array $auditContext = [],
        bool $logReadOperations = false
    ): array
    {
        $result = $this->records->listEnriched($filters, $sort, $direction, $page, $perPage);
        $items = array_map(fn (array $row): array => $this->serializeEnrichedRow($row, decryptSensitiveFields: false), $result['data']);

        if ($logReadOperations) {
            $this->writeAuditFromService('view', 'session_record_list', 'list', $auditContext);
        }

        return [
            'data' => $items,
            'meta' => [
                'page' => max(1, $page),
                'per_page' => max(1, min(100, $perPage)),
                'total' => $result['total'],
                'last_page' => (int) max(1, ceil($result['total'] / max(1, min(100, $perPage)))),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function requireEnrichedRecord(int $recordId, bool $includeRevisions): array
    {
        $row = $this->records->findEnrichedById($recordId);
        if ($row === null) {
            throw new NotFoundHttpException('Form record not found');
        }

        $serialized = $this->serializeEnrichedRow($row);
        if (!$includeRevisions) {
            return $serialized;
        }

        $timezone = $this->resolveTimezone(isset($row['client_timezone']) ? (string) $row['client_timezone'] : null);
        $serialized['revisions'] = array_map(
            fn (array $revision): array => $this->serializeRevisionRow($revision, $timezone),
            $this->revisions->listByRecordId($recordId)
        );

        return $serialized;
    }

    /** @param array<string, mixed> $row */
    /** @return array<string, mixed> */
    private function serializeEnrichedRow(array $row, bool $decryptSensitiveFields = true): array
    {
        $dto = FormRecordDto::fromRow($row);
        $base = $dto->toArray();

        $timezone = $this->resolveTimezone(isset($row['client_timezone']) ? (string) $row['client_timezone'] : null);
        $base['created_at_local'] = $this->toLocalTime($dto->createdAt, $timezone);
        $base['updated_at_local'] = $this->toLocalTime($dto->updatedAt, $timezone);
        $base['finalized_at_local'] = $this->toLocalTime($dto->finalizedAt, $timezone);

        $schema = $this->extractVersionSchema($row);
        $base['template_schema_json'] = $schema;
        
        // S-008: Decrypt sensitive fields from encrypted_payload_json with fallback to payload_json
        // Only decrypt for Detail/Export reads (not for List reads)
        $payloadData = is_array($base['payload_json'] ?? null) ? $base['payload_json'] : [];
        
        if ($decryptSensitiveFields) {
            // Use DTO-decoded JSON field; raw DB row value is often a JSON string.
            $encryptedPayloadData = is_array($base['encrypted_payload_json'] ?? null)
                ? $base['encrypted_payload_json']
                : [];
            
            if ($encryptedPayloadData !== []) {
                try {
                    $payloadData = $this->decryptPayload($encryptedPayloadData, $schema);
                } catch (HttpException $e) {
                    if ($e->errorCode() !== 'ENCRYPTION_KEY_INVALID') {
                        throw $e;
                    }
                    // Fallback to plaintext payload_json (Legacy)
                    $payloadData = is_array($base['payload_json'] ?? null) ? $base['payload_json'] : [];
                }
            }
        }
        
        $payloadData = $this->filterReadableFields($payloadData, $schema, (string) $dto->status);
        $base['payload_json'] = $payloadData;
        
        $dateFieldMap = $this->dateFieldMapFromSchema($schema);
        if (is_array($base['payload_json'] ?? null)) {
            $base['payload_json'] = $this->denormalizePayloadDates($base['payload_json'], $dateFieldMap, $timezone);
        }

        $base['template'] = [
            'key' => $row['template_key'] ?? null,
            'name' => $row['template_name'] ?? null,
            'description' => $row['template_description'] ?? null,
            'status' => ((int) ($row['template_is_active'] ?? 0) === 1) ? 'active' : 'inactive',
            'version_no' => isset($row['template_version_no']) ? (int) $row['template_version_no'] : null,
        ];

        $decryptedClient = $this->clientCrypto->decryptClientRow([
            'first_name' => $row['client_first_name'] ?? null,
            'last_name' => $row['client_last_name'] ?? null,
            'email' => $row['client_email'] ?? null,
            'timezone' => $timezone,
        ]);

        $base['client'] = [
            'first_name' => $decryptedClient['first_name'] ?? null,
            'last_name' => $decryptedClient['last_name'] ?? null,
            'email' => $decryptedClient['email'] ?? null,
            'timezone' => $decryptedClient['timezone'] ?? $timezone,
        ];

        $base['booking'] = [
            'scheduled_at' => $row['booking_scheduled_at'] ?? null,
            'scheduled_at_local' => $this->toLocalTime(
                isset($row['booking_scheduled_at']) ? (string) $row['booking_scheduled_at'] : null,
                $timezone
            ),
            'status' => $row['booking_status'] ?? null,
        ];

        return $base;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeRevisionRow(array $row, string $timezone): array
    {
        $dto = FormRevisionDto::fromRow($row);

        return [
            'id' => $dto->id,
            'session_record_id' => $dto->sessionRecordId,
            'revision_no' => $dto->revisionNo,
            'payload_json_snapshot' => $dto->payloadSnapshot,
            'changed_fields_json' => $dto->changedFields,
            'change_reason' => $dto->changeReason,
            'changed_by_user_id' => $dto->changedByUserId,
            'changed_at' => $dto->changedAt,
            'created_at' => $dto->createdAt,
            'changed_at_local' => $this->toLocalTime($dto->changedAt, $timezone),
            'created_at_local' => $this->toLocalTime($dto->createdAt, $timezone),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function validateRecordPayload(array $payload, bool $isCreate): void
    {
        $errors = [];

        if ($isCreate) {
            foreach (['booking_id', 'client_id', 'template_id', 'template_version_id'] as $field) {
                $value = (int) ($payload[$field] ?? 0);
                if ($value <= 0) {
                    $errors[$field][] = $field . ' must be a positive integer';
                }
            }
        }

        if (array_key_exists('status', $payload) && (string) $payload['status'] !== 'draft') {
            $errors['status'][] = 'status must be draft in S-005';
        }

        if ($isCreate && !array_key_exists('payload_json', $payload)) {
            $errors['payload_json'][] = 'payload_json is required';
        }

        if (array_key_exists('payload_json', $payload) && $payload['payload_json'] !== null && !is_array($payload['payload_json'])) {
            $errors['payload_json'][] = 'payload_json must be an object-like array';
        }

        if (!$isCreate) {
            $allowed = ['payload_json', 'change_reason'];
            foreach (array_keys($payload) as $key) {
                if (!in_array((string) $key, $allowed, true)) {
                    $errors[(string) $key][] = 'field is not allowed in draft update';
                }
            }

            if (!array_key_exists('payload_json', $payload)) {
                $errors['payload_json'][] = 'payload_json is required';
            }
        }

        if ($isCreate && array_key_exists('encrypted_payload_json', $payload)) {
            $errors['encrypted_payload_json'][] = 'encrypted_payload_json is managed by server (Variant B)';
        }

        if ($errors !== []) {
            throw new ValidationHttpException($errors);
        }
    }

    /** @param array<string, mixed> $auditContext */
    private function writeAuditFromService(string $action, string $resourceType, string $resourceId, array $auditContext): void
    {
        $actor = isset($auditContext['actor_user_id']) ? (int) $auditContext['actor_user_id'] : null;
        $this->writeAudit($actor, $action, $resourceType, $resourceId, $auditContext);
    }

    /** @param array<string, mixed> $auditContext */
    private function writeAudit(?int $actorUserId, string $action, string $resourceType, string $resourceId, array $auditContext, $pdo = null): void
    {
        try {
            $this->validateAuditContext($action, $auditContext);

            $this->audits->create([
                'actor_user_id' => $actorUserId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'field_scope' => $auditContext['field_scope'] ?? null,
                'purpose_code' => $auditContext['purpose_code'] ?? null,
                'ip_address' => $auditContext['ip_address'] ?? null,
                'user_agent' => $auditContext['user_agent'] ?? null,
            ], $pdo);
        } catch (Throwable) {
            // S-011 policy: audit failures must not block business operations.
            return;
        }
    }

    /** @param array<string, mixed> $auditContext */
    private function validateAuditContext(string $action, array $auditContext): void
    {
        $allowed = ['view', 'create', 'update', 'export', 'delete'];
        if (!in_array($action, $allowed, true)) {
            throw new HttpException('Unsupported audit action: ' . $action, 500);
        }

        if (in_array($action, ['view', 'export'], true)) {
            $purposeCode = trim((string) ($auditContext['purpose_code'] ?? ''));
            if ($purposeCode === '') {
                throw new ValidationHttpException([
                    'purpose_code' => ['purpose_code is required for view/export operations'],
                ]);
            }
        }
    }

    private function resolveTimezone(?string $timezone): string
    {
        $candidate = trim((string) $timezone);
        if ($candidate === '') {
            $candidate = (string) config('app.timezone', 'UTC');
        }

        try {
            new DateTimeZone($candidate);
            return $candidate;
        } catch (\Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }

    private function toLocalTime(?string $datetime, string $timezone): ?string
    {
        if ($datetime === null || trim($datetime) === '') {
            return null;
        }

        $sourceTz = new DateTimeZone((string) config('app.timezone', 'UTC'));
        $targetTz = new DateTimeZone($timezone);

        $value = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime, $sourceTz);
        if ($value === false) {
            return $datetime;
        }

        return $value->setTimezone($targetTz)->format('Y-m-d H:i:s');
    }

    /**
     * @return array{booking: array<string, mixed>, client: array<string, mixed>, template: array<string, mixed>, template_version: array<string, mixed>}
     */
    private function ensureRecordContext(int $bookingId, int $clientId, int $templateId, int $templateVersionId, bool $requireActiveTemplate): array
    {
        $booking = db('bookings')
            ->where('id', $bookingId)
            ->first();
        if (!is_array($booking)) {
            throw new ValidationHttpException(['booking_id' => ['booking_id does not exist']]);
        }

        $client = db('clients')
            ->where('id', $clientId)
            ->first();
        if (!is_array($client)) {
            throw new ValidationHttpException(['client_id' => ['client_id does not exist']]);
        }

        $template = $this->templates->findById($templateId, false);
        if ($template === null) {
            throw new ValidationHttpException(['template_id' => ['template_id does not exist']]);
        }

        if ($requireActiveTemplate && !(bool) ($template['is_active'] ?? false)) {
            throw new ValidationHttpException(['template_id' => ['template is inactive and cannot be used for new records']]);
        }

        $version = $this->versions->findById($templateVersionId);
        if ($version === null || (int) ($version['template_id'] ?? 0) !== $templateId) {
            throw new ValidationHttpException([
                'template_version_id' => ['template_version_id does not belong to the provided template_id'],
            ]);
        }

        return [
            'booking' => $booking,
            'client' => $client,
            'template' => $template,
            'template_version' => $version,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $next
     * @return array<int, string>
     */
    private function resolveChangedFields(array $current, array $next): array
    {
        $keys = array_unique(array_merge(array_keys($current), array_keys($next)));
        $changed = [];

        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }

            $before = $current[$key] ?? null;
            $after = $next[$key] ?? null;
            if ($before !== $after) {
                $changed[] = $key;
            }
        }

        return array_values($changed);
    }

    /**
     * @param array<string, mixed> $versionRow
     * @return array<int, mixed>
     */
    private function extractVersionSchema(array $versionRow): array
    {
        $raw = $versionRow['schema_json'] ?? [];
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, mixed> $schema
     * @return array<string, bool>
     */
    private function dateFieldMapFromSchema(array $schema): array
    {
        $fields = $this->payloadValidator->flattenSchemaFields($schema);
        $dateMap = [];
        foreach ($fields as $fieldKey => $field) {
            if ((string) ($field['type'] ?? '') === 'date') {
                $dateMap[$fieldKey] = true;
            }
        }

        return $dateMap;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, bool> $dateFieldMap
     * @return array<string, mixed>
     */
    private function denormalizePayloadDates(array $payload, array $dateFieldMap, string $timezone): array
    {
        foreach ($dateFieldMap as $fieldKey => $isDate) {
            if (!$isDate || !array_key_exists($fieldKey, $payload)) {
                continue;
            }

            $raw = $payload[$fieldKey];
            if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && ctype_digit(trim($raw)))) {
                continue;
            }

            $timestamp = (int) $raw;
            $payload[$fieldKey] = $this->formatUnixToLocalString($timestamp, $timezone);
        }

        return $payload;
    }

    private function formatUnixToLocalString(int $timestamp, string $timezone): string
    {
        $tz = new DateTimeZone($timezone);
        $value = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);

        return $value->format('d.m.Y H:i');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $schema
     * @return array<string, mixed>
     */
    private function encryptPayload(array $payload, array $schema): array
    {
        try {
            return $this->encryption->encryptSensitiveFields($payload, $schema);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new HttpException('Encryption failed: ' . $e->getMessage(), 500, 'ENCRYPTION_ERROR');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $schema
     * @return array<string, mixed>
     */
    private function decryptPayload(array $payload, array $schema): array
    {
        try {
            return $this->encryption->decryptSensitiveFields($payload, $schema);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new HttpException('Decryption failed: ' . $e->getMessage(), 500, 'DECRYPTION_ERROR');
        }
    }

    /** @param array<string, mixed> $row */
    private function resolveCurrentPayload(array $row, array $schema): array
    {
        $encryptedPayload = is_array($row['encrypted_payload_json'] ?? null)
            ? $row['encrypted_payload_json']
            : $this->decodeJsonObject($row['encrypted_payload_json'] ?? null);

        if ($encryptedPayload !== []) {
            return $this->decryptPayload($encryptedPayload, $schema);
        }

        $legacyPayload = is_array($row['payload_json'] ?? null)
            ? $row['payload_json']
            : $this->decodeJsonObject($row['payload_json'] ?? null);

        if ($legacyPayload === []) {
            return [];
        }

        if ($this->looksEncryptedPayload($legacyPayload)) {
            return $this->decryptPayload($legacyPayload, $schema);
        }

        return $legacyPayload;
    }

    /** @param array<string, mixed> $payload */
    private function looksEncryptedPayload(array $payload): bool
    {
        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            if (isset($value['kv'], $value['iv'], $value['tag'], $value['ct'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $incomingPayload
     * @param array<int, mixed> $schema
     */
    private function assertWritableFields(array $incomingPayload, array $schema, string $recordStatus): void
    {
        if ($incomingPayload === []) {
            return;
        }

        $fields = $this->payloadValidator->flattenSchemaFields($schema);
        $blocked = [];

        foreach ($incomingPayload as $fieldKey => $_value) {
            if (!is_string($fieldKey) || !isset($fields[$fieldKey])) {
                continue;
            }

            if (!$this->isFieldAllowedForOperation($fields[$fieldKey], 'write', $recordStatus)) {
                $blocked[] = $fieldKey;
            }
        }

        if ($blocked !== []) {
            throw new HttpException(
                'Write access denied for fields: ' . implode(', ', $blocked),
                403,
                'FIELD_WRITE_FORBIDDEN'
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $schema
     * @return array<string, mixed>
     */
    /** @param array<string, mixed> $payload */
    /** @param array<string, mixed> $schema */
    public function filterReadableFields(array $payload, array $schema, string $recordStatus): array
    {
        if ($payload === []) {
            return $payload;
        }

        $fields = $this->payloadValidator->flattenSchemaFields($schema);
        $filtered = [];

        foreach ($payload as $fieldKey => $value) {
            if (!is_string($fieldKey)) {
                continue;
            }

            if (!isset($fields[$fieldKey])) {
                $filtered[$fieldKey] = $value;
                continue;
            }

            if ($this->isFieldAllowedForOperation($fields[$fieldKey], 'read', $recordStatus)) {
                $filtered[$fieldKey] = $value;
            }
        }

        return $filtered;
    }

    /** @param array<string, mixed> $field */
    private function isFieldAllowedForOperation(array $field, string $operation, string $recordStatus): bool
    {
        $rules = $field['visibility_rules'] ?? null;
        if ($rules === null) {
            return true;
        }

        if (is_bool($rules)) {
            return $rules;
        }

        if (!is_array($rules)) {
            return true;
        }

        $operationRules = $rules[$operation] ?? $rules;
        return $this->evaluateVisibilityRules($operationRules, $recordStatus);
    }

    private function evaluateVisibilityRules(mixed $rules, string $recordStatus): bool
    {
        if ($rules === null) {
            return true;
        }

        if (is_bool($rules)) {
            return $rules;
        }

        if (!is_array($rules)) {
            return true;
        }

        if (array_key_exists('enabled', $rules) && $rules['enabled'] === false) {
            return false;
        }

        $allowedStatuses = $rules['allowed_statuses'] ?? $rules['statuses'] ?? null;
        if (is_array($allowedStatuses) && $allowedStatuses !== []) {
            $allowed = array_map(static fn (mixed $s): string => strtolower(trim((string) $s)), $allowedStatuses);
            if (!in_array(strtolower($recordStatus), $allowed, true)) {
                return false;
            }
        }

        $deniedStatuses = $rules['denied_statuses'] ?? $rules['forbidden_statuses'] ?? null;
        if (is_array($deniedStatuses) && $deniedStatuses !== []) {
            $denied = array_map(static fn (mixed $s): string => strtolower(trim((string) $s)), $deniedStatuses);
            if (in_array(strtolower($recordStatus), $denied, true)) {
                return false;
            }
        }

        $requiredPermissions = $rules['required_permissions'] ?? null;
        if (is_array($requiredPermissions) && $requiredPermissions !== []) {
            $userPermissions = [self::USE_FORMS_PERMISSION];
            foreach ($requiredPermissions as $permission) {
                $slug = trim((string) $permission);
                if ($slug !== '' && !in_array($slug, $userPermissions, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}


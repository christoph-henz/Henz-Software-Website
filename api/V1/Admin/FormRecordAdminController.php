<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\BaseApiController;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Exceptions\ValidationHttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\EncryptionService;
use App\Services\SessionAttachmentService;
use App\Services\SessionRecordService;
use App\Core\Support\PermissionBits;

final class FormRecordAdminController extends BaseApiController
{
    private const USE_FORM_TEMPLATES_FOR_CLIENTS_BIT = 32768;
    private const MANAGE_FORM_TEMPLATES_BIT = 16384;
    private const MANAGE_CLIENTS_BIT = 16;
    private const DEFAULT_MAX_FILE_SIZE_MB = 5;
    private const ATTACHMENT_STORAGE_PATH = 'storage/media/secure/form-appendum';
    private const ATTACHMENT_CHUNK_TEMP_PATH = 'storage/media/secure/form-appendum/_chunks';
    private const ATTACHMENT_CHUNK_SIZE_BYTES = 500 * 1024;
    private const ATTACHMENT_DOWNLOAD_TTL_SECONDS = 900;
    private const ENCRYPTED_ATTACHMENT_PREFIX = "GBATT1\n";
    private const ENCRYPTED_ATTACHMENT_FIELD = 'bin';

    /** @var array<string, string> */
    private const ALLOWED_ATTACHMENT_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    public function index(Request $request): Response
    {
        if (!$this->canAccessRecordListAndAttachments($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        ['sort' => $sort, 'direction' => $direction] = $this->resolveListSorting($request);
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $errors = [];
        if (!in_array($sort, ['created_at', 'updated_at', 'status', 'client', 'booking_scheduled_at'], true)) {
            $errors['sort'][] = 'sort must be one of created_at, updated_at, status, client, booking_scheduled_at';
        }

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $errors['direction'][] = 'direction must be asc or desc';
        }

        if ($page <= 0) {
            $errors['page'][] = 'page must be a positive integer';
        }

        if ($perPage <= 0 || $perPage > 100) {
            $errors['per_page'][] = 'per_page must be between 1 and 100';
        }

        $status = strtolower(trim((string) $request->query('status', '')));
        if ($status !== '' && !in_array($status, ['draft', 'final'], true)) {
            $errors['status'][] = 'status must be draft or final';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        $filters = [
            'template_id' => (int) $request->query('template_id', 0),
            'client_id' => (int) $request->query('client_id', 0),
            'booking_id' => (int) $request->query('booking_id', 0),
            'status' => $status,
            'q' => trim((string) $request->query('q', '')),
        ];

        try {
            $result = app(SessionRecordService::class)->listEnriched(
                $filters,
                $sort,
                strtoupper($direction),
                $page,
                $perPage
            );
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'data' => $result['data'],
            'pagination' => $result['meta'],
        ]);
    }

    public function show(Request $request): Response
    {
        if (!$this->canUseTemplatesForClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['id must be a positive integer'],
            ]);
        }

        $includeRevisions = $this->readBoolQuery($request, 'include_revisions', false);
        $auditPurposeCode = trim((string) $request->query('purpose_code', 'SESSION_RECORD_DETAIL_VIEW'));

        try {
            $record = app(SessionRecordService::class)->getEnrichedRecord(
                $id,
                [
                    'actor_user_id' => $this->actorUserId($request),
                    'purpose_code' => $auditPurposeCode,
                    'ip_address' => (string) ($request->header('X-Forwarded-For', '') ?: ($_SERVER['REMOTE_ADDR'] ?? '')),
                    'user_agent' => (string) $request->header('User-Agent', ''),
                ],
                $includeRevisions,
                true
            );
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'record' => $record,
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->canUseTemplatesForClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $payload = $request->all();
        $payload['status'] = 'draft';

        try {
            $record = app(SessionRecordService::class)->createRecord(
                $payload,
                $this->actorUserId($request),
                $this->buildWriteAuditContext($request, 'CREATED')
            );
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'record' => $record,
        ], 201);
    }

    public function update(Request $request): Response
    {
        if (!$this->canUseTemplatesForClients($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['id must be a positive integer'],
            ]);
        }

        try {
            $record = app(SessionRecordService::class)->updateRecord(
                $id,
                $request->all(),
                $this->actorUserId($request),
                $this->buildWriteAuditContext($request, 'UPDATED')
            );
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'record' => $record,
        ]);
    }

    public function finalize(Request $request): Response
    {
        if (!$this->canFinalizeRecords($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $recordId = (int) ($request->input('record_id', $request->input('id', 0)));
        if ($recordId <= 0) {
            return $this->fail('Validation failed', 422, [
                'record_id' => ['record_id must be a positive integer'],
            ]);
        }

        $changeReason = trim((string) $request->input('change_reason', 'Finalized'));

        try {
            $result = app(SessionRecordService::class)->finalizeRecord(
                $recordId,
                $this->actorUserId($request),
                $changeReason,
                $this->buildWriteAuditContext($request, 'FINALIZED')
            );
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'record' => $result['record'],
            'no_op' => (bool) ($result['no_op'] ?? false),
        ]);
    }

    public function amendFinal(Request $request): Response
    {
        if (!$this->canFinalizeRecords($request)) {
            return $this->fail('Forbidden', 403, [
                'permission' => ['insufficient_role'],
            ]);
        }

        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return $this->fail('Validation failed', 422, [
                'id' => ['id must be a positive integer'],
            ]);
        }

        $payload = $request->all();
        $patch = is_array($payload['payload_json'] ?? null) ? $payload['payload_json'] : [];
        $changeReason = trim((string) ($payload['change_reason'] ?? ''));

        try {
            $record = app(SessionRecordService::class)->amendFinalRecord(
                $id,
                $patch,
                $changeReason,
                $this->actorUserId($request),
                $this->buildWriteAuditContext($request, 'AMENDED_FINAL')
            );
        } catch (NotFoundHttpException $exception) {
            return $this->fail($exception->getMessage(), 404);
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->fail($exception->getMessage(), $exception->statusCode());
        }

        return $this->ok([
            'record' => $record,
        ]);
    }

    public function listAttachments(Request $request): Response
    {
        if (!$this->canAccessRecordListAndAttachments($request)) {
            return $this->mappedFail('Forbidden', 403, 'permission', 'insufficient_role');
        }

        $recordId = (int) $request->attribute('id', 0);
        if ($recordId <= 0) {
            return $this->mappedFail('Validation failed', 422, 'id', 'invalid_session_record_id');
        }

        $purposeCode = trim((string) $request->query('purpose_code', 'ATTACHMENT_METADATA_VIEW'));

        try {
            $items = app(SessionAttachmentService::class)->listBySessionRecord($recordId, [
                'actor_user_id' => $this->actorUserId($request),
                'purpose_code' => $purposeCode,
                'ip_address' => (string) ($request->header('X-Forwarded-For', '') ?: ($_SERVER['REMOTE_ADDR'] ?? '')),
                'user_agent' => (string) $request->header('User-Agent', ''),
            ]);
        } catch (NotFoundHttpException $exception) {
            return $this->mappedFail($exception->getMessage(), 404, 'record', 'session_record_not_found');
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        }

        return $this->ok([
            'attachments' => $items,
        ]);
    }

    public function uploadAttachment(Request $request): Response
    {
        if (!$this->canAccessRecordListAndAttachments($request)) {
            return $this->mappedFail('Forbidden', 403, 'permission', 'insufficient_role');
        }

        $recordId = (int) $request->attribute('id', 0);
        if ($recordId <= 0) {
            return $this->mappedFail('Validation failed', 422, 'id', 'invalid_session_record_id');
        }

        $file = $request->file('file');
        if (!is_array($file)) {
            return $this->mappedFail('No file uploaded', 422, 'file', 'attachment_missing_file');
        }

        $tmpPath = trim((string) ($file['tmp_name'] ?? ''));
        $originalFilename = trim((string) ($file['name'] ?? ''));
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($file['size'] ?? 0);

        if ($uploadError !== UPLOAD_ERR_OK || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
            $uploadCode = $this->resolveUploadErrorCode($uploadError);
            return $this->mappedFail('Upload failed: ' . $uploadCode, 422, 'file', $uploadCode);
        }

        $maxBytes = $this->effectiveMaxFileSizeBytes();
        if ($size <= 0 || $size > $maxBytes) {
            return $this->mappedFail(
                'File is too large. Allowed: ' . $this->formatBytesLabel($maxBytes) . '.',
                422,
                'file',
                'attachment_file_too_large'
            );
        }

        $detectedMime = $this->detectMimeType($tmpPath, (string) ($file['type'] ?? ''));
        if (!isset(self::ALLOWED_ATTACHMENT_MIME_TYPES[$detectedMime])) {
            return $this->mappedFail('Unsupported attachment type', 422, 'file', 'attachment_invalid_mime_type');
        }

        $checksumSha256 = hash_file('sha256', $tmpPath);
        if (!is_string($checksumSha256) || !preg_match('/^[a-f0-9]{64}$/', $checksumSha256)) {
            return $this->mappedFail('Attachment checksum validation failed', 500, 'file', 'attachment_checksum_failed');
        }

        $attachmentService = app(SessionAttachmentService::class);

        try {
            $attachmentService->ensureRecordExists($recordId);
            $existing = $attachmentService->findByChecksum($checksumSha256);
            if (is_array($existing)) {
                $attachmentService->assertDuplicateFitsRecord($existing, $recordId);

                return $this->ok([
                    'attachment' => $existing,
                    'deduplicated' => true,
                ]);
            }
        } catch (NotFoundHttpException $exception) {
            return $this->mappedFail($exception->getMessage(), 404, 'record', 'session_record_not_found');
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->mappedHttpException($exception, 'checksum_sha256');
        }

        $relativeDirectory = date('Y/m');
        $storageDirectory = base_path(self::ATTACHMENT_STORAGE_PATH . '/' . $relativeDirectory);
        if (!$this->ensureStorageDirectory($storageDirectory)) {
            return $this->mappedFail('Attachment storage is not writable', 500, 'storage', 'attachment_storage_unavailable');
        }

        $extension = self::ALLOWED_ATTACHMENT_MIME_TYPES[$detectedMime];
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativeStoragePath = $relativeDirectory . '/' . $filename;
        $absoluteStoragePath = base_path(self::ATTACHMENT_STORAGE_PATH . '/' . $relativeStoragePath);

        if (!move_uploaded_file($tmpPath, $absoluteStoragePath)) {
            return $this->mappedFail('Attachment could not be persisted', 500, 'storage', 'attachment_storage_write_failed');
        }

        try {
            $this->encryptAttachmentFileInPlace($absoluteStoragePath);
        } catch (HttpException $exception) {
            @unlink($absoluteStoragePath);
            return $this->mappedHttpException($exception, 'attachment');
        }

        try {
            $attachment = $attachmentService->createAttachment([
                'session_record_id' => $recordId,
                'storage_path' => $relativeStoragePath,
                'original_filename' => $originalFilename !== '' ? $originalFilename : basename($filename),
                'checksum_sha256' => $checksumSha256,
            ], $this->actorUserId($request), $this->buildWriteAuditContext($request, 'ATTACHMENT_UPLOAD'));
        } catch (NotFoundHttpException $exception) {
            @unlink($absoluteStoragePath);
            return $this->mappedFail($exception->getMessage(), 404, 'record', 'session_record_not_found');
        } catch (ValidationHttpException $exception) {
            @unlink($absoluteStoragePath);
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            @unlink($absoluteStoragePath);
            return $this->mappedHttpException($exception, 'attachment');
        }

        return $this->ok([
            'attachment' => $attachment,
            'deduplicated' => false,
        ], 201);
    }

    public function issueAttachmentDownloadToken(Request $request): Response
    {
        if (!$this->canAccessRecordListAndAttachments($request)) {
            return $this->mappedFail('Forbidden', 403, 'permission', 'insufficient_role');
        }

        $recordId = (int) $request->attribute('id', 0);
        $attachmentId = (int) $request->attribute('attachment_id', 0);
        if ($recordId <= 0 || $attachmentId <= 0) {
            return $this->mappedFail('Validation failed', 422, 'id', 'invalid_attachment_reference');
        }

        try {
            $attachment = app(SessionAttachmentService::class)->getAttachment($recordId, $attachmentId);
        } catch (NotFoundHttpException $exception) {
            return $this->mappedFail($exception->getMessage(), 404, 'attachment', 'session_attachment_not_found');
        }

        try {
            $expiresAt = time() + $this->downloadTokenTtlSeconds();
            $token = $this->buildAttachmentDownloadToken((int) ($attachment['id'] ?? 0), $recordId, $expiresAt);
        } catch (HttpException $exception) {
            return $this->mappedHttpException($exception, 'token');
        }

        return $this->ok([
            'token' => $token,
            'expires_at' => gmdate('c', $expiresAt),
        ]);
    }

    public function attachmentChunkInit(Request $request): Response
    {
        if (!$this->canAccessRecordListAndAttachments($request)) {
            return $this->mappedFail('Forbidden', 403, 'permission', 'insufficient_role');
        }

        $recordId = (int) $request->attribute('id', 0);
        if ($recordId <= 0) {
            return $this->mappedFail('Validation failed', 422, 'id', 'invalid_session_record_id');
        }

        $originalFilename = trim((string) $request->input('filename', ''));
        $mimeType = trim((string) $request->input('mime_type', ''));
        $totalSize = (int) $request->input('total_size', 0);

        if ($originalFilename === '' || $mimeType === '' || $totalSize <= 0) {
            return $this->mappedFail('Validation failed', 422, 'file', 'attachment_chunk_init_invalid_payload');
        }

        if (!isset(self::ALLOWED_ATTACHMENT_MIME_TYPES[$mimeType])) {
            return $this->mappedFail('Unsupported attachment type', 422, 'file', 'attachment_invalid_mime_type');
        }

        $maxBytes = $this->effectiveMaxFileSizeBytes();
        if ($totalSize > $maxBytes) {
            return $this->mappedFail(
                'File is too large. Allowed: ' . $this->formatBytesLabel($maxBytes) . '.',
                422,
                'file',
                'attachment_file_too_large'
            );
        }

        $attachmentService = app(SessionAttachmentService::class);
        try {
            $attachmentService->ensureRecordExists($recordId);
        } catch (NotFoundHttpException $exception) {
            return $this->mappedFail($exception->getMessage(), 404, 'record', 'session_record_not_found');
        } catch (ValidationHttpException $exception) {
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        }

        $chunksDirectory = base_path(self::ATTACHMENT_CHUNK_TEMP_PATH);
        if (!$this->ensureStorageDirectory($chunksDirectory)) {
            return $this->mappedFail('Attachment storage is not writable', 500, 'storage', 'attachment_storage_unavailable');
        }

        $uploadId = bin2hex(random_bytes(16));
        $chunkFilePath = $this->chunkFilePath($uploadId);
        $metaFilePath = $this->chunkMetaPath($uploadId);

        if (file_put_contents($chunkFilePath, '') === false) {
            return $this->mappedFail('Upload session could not be created', 500, 'file', 'attachment_chunk_session_create_failed');
        }

        $meta = [
            'upload_id' => $uploadId,
            'record_id' => $recordId,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'total_size' => $totalSize,
            'received_size' => 0,
            'created_at' => time(),
        ];

        if (!$this->writeChunkMeta($metaFilePath, $meta)) {
            @unlink($chunkFilePath);
            return $this->mappedFail('Upload session could not be persisted', 500, 'file', 'attachment_chunk_session_persist_failed');
        }

        return $this->ok([
            'upload_id' => $uploadId,
            'chunk_size_bytes' => self::ATTACHMENT_CHUNK_SIZE_BYTES,
            'max_file_size_bytes' => $maxBytes,
        ], 201);
    }

    public function attachmentChunkAppend(Request $request): Response
    {
        if (!$this->canAccessRecordListAndAttachments($request)) {
            return $this->mappedFail('Forbidden', 403, 'permission', 'insufficient_role');
        }

        $recordId = (int) $request->attribute('id', 0);
        if ($recordId <= 0) {
            return $this->mappedFail('Validation failed', 422, 'id', 'invalid_session_record_id');
        }

        $uploadId = trim((string) $request->attribute('upload_id', ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            return $this->mappedFail('Validation failed', 422, 'upload_id', 'attachment_chunk_invalid_upload_id');
        }

        $metaFilePath = $this->chunkMetaPath($uploadId);
        $chunkFilePath = $this->chunkFilePath($uploadId);
        $meta = $this->readChunkMeta($metaFilePath);
        if ($meta === null || !is_file($chunkFilePath)) {
            return $this->mappedFail('Upload session not found', 404, 'upload_id', 'attachment_chunk_session_not_found');
        }

        if ((int) ($meta['record_id'] ?? 0) !== $recordId) {
            return $this->mappedFail('Upload session does not match record', 403, 'upload_id', 'attachment_chunk_scope_invalid');
        }

        $rawChunk = $request->rawBody();
        $chunkSize = strlen($rawChunk);
        if ($chunkSize <= 0) {
            return $this->mappedFail('Empty chunk payload', 422, 'chunk', 'attachment_chunk_empty');
        }

        if ($chunkSize > self::ATTACHMENT_CHUNK_SIZE_BYTES) {
            return $this->mappedFail('Chunk too large', 422, 'chunk', 'attachment_chunk_too_large');
        }

        $nextReceived = (int) ($meta['received_size'] ?? 0) + $chunkSize;
        $totalSize = (int) ($meta['total_size'] ?? 0);
        if ($nextReceived > $totalSize) {
            return $this->mappedFail('Chunk exceeds declared file size', 422, 'chunk', 'attachment_chunk_exceeds_total_size');
        }

        if (file_put_contents($chunkFilePath, $rawChunk, FILE_APPEND) === false) {
            return $this->mappedFail('Chunk write failed', 500, 'chunk', 'attachment_chunk_write_failed');
        }

        $meta['received_size'] = $nextReceived;
        if (!$this->writeChunkMeta($metaFilePath, $meta)) {
            return $this->mappedFail('Chunk state update failed', 500, 'chunk', 'attachment_chunk_state_update_failed');
        }

        return $this->ok([
            'upload_id' => $uploadId,
            'received_size' => $nextReceived,
            'total_size' => $totalSize,
            'is_complete' => $nextReceived === $totalSize,
        ]);
    }

    public function attachmentChunkFinish(Request $request): Response
    {
        if (!$this->canAccessRecordListAndAttachments($request)) {
            return $this->mappedFail('Forbidden', 403, 'permission', 'insufficient_role');
        }

        $recordId = (int) $request->attribute('id', 0);
        if ($recordId <= 0) {
            return $this->mappedFail('Validation failed', 422, 'id', 'invalid_session_record_id');
        }

        $uploadId = trim((string) $request->attribute('upload_id', ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            return $this->mappedFail('Validation failed', 422, 'upload_id', 'attachment_chunk_invalid_upload_id');
        }

        $metaFilePath = $this->chunkMetaPath($uploadId);
        $chunkFilePath = $this->chunkFilePath($uploadId);
        $meta = $this->readChunkMeta($metaFilePath);
        if ($meta === null || !is_file($chunkFilePath)) {
            return $this->mappedFail('Upload session not found', 404, 'upload_id', 'attachment_chunk_session_not_found');
        }

        if ((int) ($meta['record_id'] ?? 0) !== $recordId) {
            return $this->mappedFail('Upload session does not match record', 403, 'upload_id', 'attachment_chunk_scope_invalid');
        }

        $declaredTotal = (int) ($meta['total_size'] ?? 0);
        $receivedTotal = (int) ($meta['received_size'] ?? 0);
        $actualSize = (int) (filesize($chunkFilePath) ?: 0);
        if ($declaredTotal <= 0 || $receivedTotal !== $declaredTotal || $actualSize !== $declaredTotal) {
            return $this->mappedFail('Upload is not complete', 422, 'upload_id', 'attachment_chunk_incomplete');
        }

        $detectedMime = $this->detectMimeType($chunkFilePath, (string) ($meta['mime_type'] ?? ''));
        if (!isset(self::ALLOWED_ATTACHMENT_MIME_TYPES[$detectedMime])) {
            $this->cleanupChunkUpload($chunkFilePath, $metaFilePath);
            return $this->mappedFail('Unsupported attachment type', 422, 'file', 'attachment_invalid_mime_type');
        }

        $checksumSha256 = hash_file('sha256', $chunkFilePath);
        if (!is_string($checksumSha256) || !preg_match('/^[a-f0-9]{64}$/', $checksumSha256)) {
            $this->cleanupChunkUpload($chunkFilePath, $metaFilePath);
            return $this->mappedFail('Attachment checksum validation failed', 500, 'file', 'attachment_checksum_failed');
        }

        $attachmentService = app(SessionAttachmentService::class);
        try {
            $attachmentService->ensureRecordExists($recordId);
            $existing = $attachmentService->findByChecksum($checksumSha256);
            if (is_array($existing)) {
                $attachmentService->assertDuplicateFitsRecord($existing, $recordId);
                $this->cleanupChunkUpload($chunkFilePath, $metaFilePath);

                return $this->ok([
                    'attachment' => $existing,
                    'deduplicated' => true,
                ]);
            }
        } catch (NotFoundHttpException $exception) {
            $this->cleanupChunkUpload($chunkFilePath, $metaFilePath);
            return $this->mappedFail($exception->getMessage(), 404, 'record', 'session_record_not_found');
        } catch (ValidationHttpException $exception) {
            $this->cleanupChunkUpload($chunkFilePath, $metaFilePath);
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            $this->cleanupChunkUpload($chunkFilePath, $metaFilePath);
            return $this->mappedHttpException($exception, 'checksum_sha256');
        }

        $relativeDirectory = date('Y/m');
        $storageDirectory = base_path(self::ATTACHMENT_STORAGE_PATH . '/' . $relativeDirectory);
        if (!$this->ensureStorageDirectory($storageDirectory)) {
            $this->cleanupChunkUpload($chunkFilePath, $metaFilePath);
            return $this->mappedFail('Attachment storage is not writable', 500, 'storage', 'attachment_storage_unavailable');
        }

        $extension = self::ALLOWED_ATTACHMENT_MIME_TYPES[$detectedMime];
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativeStoragePath = $relativeDirectory . '/' . $filename;
        $absoluteStoragePath = base_path(self::ATTACHMENT_STORAGE_PATH . '/' . $relativeStoragePath);
        if (!rename($chunkFilePath, $absoluteStoragePath)) {
            return $this->mappedFail('Attachment could not be persisted', 500, 'storage', 'attachment_storage_write_failed');
        }

        try {
            $this->encryptAttachmentFileInPlace($absoluteStoragePath);
        } catch (HttpException $exception) {
            @unlink($absoluteStoragePath);
            @unlink($metaFilePath);
            return $this->mappedHttpException($exception, 'attachment');
        }

        try {
            $attachment = $attachmentService->createAttachment([
                'session_record_id' => $recordId,
                'storage_path' => $relativeStoragePath,
                'original_filename' => trim((string) ($meta['original_filename'] ?? '')) !== ''
                    ? (string) $meta['original_filename']
                    : basename($filename),
                'checksum_sha256' => $checksumSha256,
            ], $this->actorUserId($request), $this->buildWriteAuditContext($request, 'ATTACHMENT_UPLOAD'));
        } catch (NotFoundHttpException $exception) {
            @unlink($absoluteStoragePath);
            @unlink($metaFilePath);
            return $this->mappedFail($exception->getMessage(), 404, 'record', 'session_record_not_found');
        } catch (ValidationHttpException $exception) {
            @unlink($absoluteStoragePath);
            @unlink($metaFilePath);
            return $this->fail($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            @unlink($absoluteStoragePath);
            @unlink($metaFilePath);
            return $this->mappedHttpException($exception, 'attachment');
        }

        @unlink($metaFilePath);

        return $this->ok([
            'attachment' => $attachment,
            'deduplicated' => false,
        ], 201);
    }

    public function downloadAttachment(Request $request): Response
    {
        if (!$this->canAccessRecordListAndAttachments($request)) {
            return $this->mappedFail('Forbidden', 403, 'permission', 'insufficient_role');
        }

        $recordId = (int) $request->attribute('id', 0);
        $attachmentId = (int) $request->attribute('attachment_id', 0);
        $token = trim((string) $request->query('token', ''));

        if ($recordId <= 0 || $attachmentId <= 0) {
            return $this->mappedFail('Validation failed', 422, 'id', 'invalid_attachment_reference');
        }

        if ($token === '') {
            return $this->mappedFail('Download token is required', 422, 'token', 'attachment_token_missing');
        }

        $tokenPayload = $this->parseAttachmentDownloadToken($token);
        if ($tokenPayload === null) {
            return $this->mappedFail('Download token is invalid', 422, 'token', 'attachment_token_invalid');
        }

        if ((int) ($tokenPayload['aid'] ?? 0) !== $attachmentId || (int) ($tokenPayload['rid'] ?? 0) !== $recordId) {
            return $this->mappedFail('Download token does not match attachment', 403, 'token', 'attachment_token_scope_invalid');
        }

        $expiresAt = (int) ($tokenPayload['exp'] ?? 0);
        if ($expiresAt <= time()) {
            return $this->mappedFail('Download token expired', 403, 'token', 'attachment_token_expired');
        }

        try {
            $service = app(SessionAttachmentService::class);
            $attachment = $service->getAttachment($recordId, $attachmentId);
        } catch (NotFoundHttpException $exception) {
            return $this->mappedFail($exception->getMessage(), 404, 'attachment', 'session_attachment_not_found');
        }

        $relativeStoragePath = ltrim((string) ($attachment['storage_path'] ?? ''), '/');
        $absoluteStoragePath = base_path(self::ATTACHMENT_STORAGE_PATH . '/' . $relativeStoragePath);
        if (!is_file($absoluteStoragePath) || !is_readable($absoluteStoragePath)) {
            return $this->mappedFail('Attachment file not found', 404, 'attachment', 'attachment_file_missing');
        }

        try {
            $content = $this->readAttachmentPlainContent($absoluteStoragePath);
        } catch (HttpException $exception) {
            return $this->mappedHttpException($exception, 'attachment');
        }

        $mimeFallback = $this->guessMimeTypeFromFilename((string) ($attachment['original_filename'] ?? ''));
        $mimeType = $this->detectMimeTypeFromContent($content, $mimeFallback);
        $downloadName = $this->safeDownloadFilename((string) ($attachment['original_filename'] ?? 'attachment.bin'));
        $requestedDisposition = strtolower(trim((string) $request->query('disposition', 'attachment')));
        $dispositionType = $requestedDisposition === 'inline' ? 'inline' : 'attachment';
        $disposition = $dispositionType . '; filename="' . $downloadName . '"';

        app(SessionAttachmentService::class)->auditDownload(
            $attachmentId,
            $this->actorUserId($request),
            $this->buildWriteAuditContext($request, 'ATTACHMENT_DOWNLOAD')
        );

        return new Response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function previewAttachment(Request $request): Response
    {
        if (!$this->canAccessRecordListAndAttachments($request)) {
            return $this->mappedFail('Forbidden', 403, 'permission', 'insufficient_role');
        }

        $recordId = (int) $request->attribute('id', 0);
        $attachmentId = (int) $request->attribute('attachment_id', 0);
        $token = trim((string) $request->query('token', ''));

        if ($recordId <= 0 || $attachmentId <= 0) {
            return $this->mappedFail('Validation failed', 422, 'id', 'invalid_attachment_reference');
        }

        if ($token === '') {
            return $this->mappedFail('Download token is required', 422, 'token', 'attachment_token_missing');
        }

        $tokenPayload = $this->parseAttachmentDownloadToken($token);
        if ($tokenPayload === null) {
            return $this->mappedFail('Download token is invalid', 422, 'token', 'attachment_token_invalid');
        }

        if ((int) ($tokenPayload['aid'] ?? 0) !== $attachmentId || (int) ($tokenPayload['rid'] ?? 0) !== $recordId) {
            return $this->mappedFail('Download token does not match attachment', 403, 'token', 'attachment_token_scope_invalid');
        }

        $expiresAt = (int) ($tokenPayload['exp'] ?? 0);
        if ($expiresAt <= time()) {
            return $this->mappedFail('Download token expired', 403, 'token', 'attachment_token_expired');
        }

        try {
            $service = app(SessionAttachmentService::class);
            $attachment = $service->getAttachment($recordId, $attachmentId);
        } catch (NotFoundHttpException $exception) {
            return $this->mappedFail($exception->getMessage(), 404, 'attachment', 'session_attachment_not_found');
        }

        $relativeStoragePath = ltrim((string) ($attachment['storage_path'] ?? ''), '/');
        $absoluteStoragePath = base_path(self::ATTACHMENT_STORAGE_PATH . '/' . $relativeStoragePath);
        if (!is_file($absoluteStoragePath) || !is_readable($absoluteStoragePath)) {
            return $this->mappedFail('Attachment file not found', 404, 'attachment', 'attachment_file_missing');
        }

        try {
            $content = $this->readAttachmentPlainContent($absoluteStoragePath);
        } catch (HttpException $exception) {
            return $this->mappedHttpException($exception, 'attachment');
        }

        $mimeFallback = $this->guessMimeTypeFromFilename((string) ($attachment['original_filename'] ?? ''));
        $mimeType = $this->detectMimeTypeFromContent($content, $mimeFallback);

        app(SessionAttachmentService::class)->auditDownload(
            $attachmentId,
            $this->actorUserId($request),
            $this->buildWriteAuditContext($request, 'ATTACHMENT_PREVIEW')
        );

        return new Response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function canUseTemplatesForClients(Request $request): bool
    {
        $adminUser = $this->adminUser($request);
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $requiredMask = PermissionBits::resolve('use_form_templates_for_clients', self::USE_FORM_TEMPLATES_FOR_CLIENTS_BIT);

        return ($roleMask & $requiredMask) !== 0;
    }

    private function canManageClients(Request $request): bool
    {
        $adminUser = $this->adminUser($request);
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $requiredMask = PermissionBits::resolve('manage_clients', self::MANAGE_CLIENTS_BIT);

        return ($roleMask & $requiredMask) !== 0;
    }

    private function canAccessRecordListAndAttachments(Request $request): bool
    {
        return $this->canUseTemplatesForClients($request) || $this->canManageClients($request);
    }

    private function canFinalizeRecords(Request $request): bool
    {
        $adminUser = $this->adminUser($request);
        $roleMask = (int) ($adminUser['role_mask'] ?? 0);
        $useMask = PermissionBits::resolve('use_form_templates_for_clients', self::USE_FORM_TEMPLATES_FOR_CLIENTS_BIT);
        $manageMask = PermissionBits::resolve('manage_form_templates', self::MANAGE_FORM_TEMPLATES_BIT);

        return (($roleMask & $useMask) !== 0) || (($roleMask & $manageMask) !== 0);
    }

    /** @return array<string, mixed> */
    private function adminUser(Request $request): array
    {
        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');

        return is_array($session[$sessionKey] ?? null) ? $session[$sessionKey] : [];
    }

    private function actorUserId(Request $request): int
    {
        $adminUser = $this->adminUser($request);
        return (int) ($adminUser['id'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function buildWriteAuditContext(Request $request, string $purposeCode): array
    {
        return [
            'purpose_code' => $purposeCode,
            'ip_address' => (string) ($request->header('X-Forwarded-For', '') ?: ($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_agent' => (string) $request->header('User-Agent', ''),
        ];
    }

    /** @return array{sort: string, direction: string} */
    private function resolveListSorting(Request $request): array
    {
        return [
            'sort' => strtolower(trim((string) $request->query('sort', 'created_at'))),
            'direction' => strtolower(trim((string) $request->query('direction', 'desc'))),
        ];
    }

    private function readBoolQuery(Request $request, string $key, bool $default): bool
    {
        $value = $request->query($key, $default ? '1' : '0');
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function mappedFail(string $message, int $status, string $field, string $code): Response
    {
        return $this->fail($message, $status, [
            $field => [$code],
        ]);
    }

    private function mappedHttpException(HttpException $exception, string $field): Response
    {
        $errorCode = trim((string) ($exception->errorCode() ?? ''));
        if ($errorCode === '') {
            $errorCode = 'attachment_error';
        }

        return $this->fail($exception->getMessage(), $exception->statusCode(), [
            $field => [strtolower($errorCode)],
        ]);
    }

    private function resolveUploadErrorCode(int $uploadError): string
    {
        return match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'attachment_file_too_large',
            UPLOAD_ERR_PARTIAL => 'attachment_upload_partial',
            UPLOAD_ERR_NO_FILE => 'attachment_missing_file',
            UPLOAD_ERR_NO_TMP_DIR => 'attachment_tmp_dir_missing',
            UPLOAD_ERR_CANT_WRITE => 'attachment_tmp_write_failed',
            UPLOAD_ERR_EXTENSION => 'attachment_upload_blocked_by_extension',
            default => 'attachment_upload_failed',
        };
    }

    private function ensureStorageDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        return is_writable($directory);
    }

    private function chunkFilePath(string $uploadId): string
    {
        return base_path(self::ATTACHMENT_CHUNK_TEMP_PATH . '/' . $uploadId . '.part');
    }

    private function chunkMetaPath(string $uploadId): string
    {
        return base_path(self::ATTACHMENT_CHUNK_TEMP_PATH . '/' . $uploadId . '.json');
    }

    /** @return array<string, mixed>|null */
    private function readChunkMeta(string $metaFilePath): ?array
    {
        if (!is_file($metaFilePath)) {
            return null;
        }

        $raw = file_get_contents($metaFilePath);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $meta */
    private function writeChunkMeta(string $metaFilePath, array $meta): bool
    {
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        return file_put_contents($metaFilePath, $json, LOCK_EX) !== false;
    }

    private function cleanupChunkUpload(string $chunkFilePath, string $metaFilePath): void
    {
        if (is_file($chunkFilePath)) {
            @unlink($chunkFilePath);
        }
        if (is_file($metaFilePath)) {
            @unlink($metaFilePath);
        }
    }

    private function effectiveMaxFileSizeBytes(): int
    {
        return $this->readMediaMaxFileSizeBytes();
    }

    private function readMediaMaxFileSizeBytes(): int
    {
        try {
            $row = db('settings')
                ->where('`key`', 'media_max_file_size')
                ->select(['value'])
                ->first();
        } catch (\Throwable) {
            return self::DEFAULT_MAX_FILE_SIZE_MB * 1024 * 1024;
        }

        $value = (string) ($row['value'] ?? '');
        if (!is_numeric($value)) {
            return self::DEFAULT_MAX_FILE_SIZE_MB * 1024 * 1024;
        }

        $parsed = (int) $value;
        if ($parsed < 1) {
            return self::DEFAULT_MAX_FILE_SIZE_MB * 1024 * 1024;
        }

        return min($parsed, 5120) * 1024 * 1024;
    }

    private function formatBytesLabel(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 2, '.', ''), '0'), '.') . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024), 2, '.', ''), '0'), '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 2, '.', ''), '0'), '.') . ' KB';
        }

        return $bytes . ' B';
    }

    private function detectMimeType(string $absolutePath, string $fallback = ''): string
    {
        $fallback = trim($fallback);

        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($absolutePath);
            if (is_string($detected) && trim($detected) !== '') {
                return trim($detected);
            }
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = @finfo_file($finfo, $absolutePath);
                @finfo_close($finfo);
                if (is_string($detected) && trim($detected) !== '') {
                    return trim($detected);
                }
            }
        }

        return $fallback;
    }

    private function detectMimeTypeFromContent(string $content, string $fallback = 'application/octet-stream'): string
    {
        $fallback = trim($fallback);
        if ($fallback === '') {
            $fallback = 'application/octet-stream';
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = @finfo_buffer($finfo, $content);
                @finfo_close($finfo);
                if (is_string($detected) && trim($detected) !== '') {
                    return trim($detected);
                }
            }
        }

        return $fallback;
    }

    private function guessMimeTypeFromFilename(string $filename): string
    {
        $name = strtolower(trim($filename));
        if ($name === '') {
            return 'application/octet-stream';
        }

        return match (pathinfo($name, PATHINFO_EXTENSION)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/octet-stream',
        };
    }

    private function encryptAttachmentFileInPlace(string $absolutePath): void
    {
        $content = @file_get_contents($absolutePath);
        if (!is_string($content)) {
            throw new HttpException('Attachment file could not be read', 500, 'ATTACHMENT_FILE_READ_FAILED');
        }

        if ($this->isEncryptedAttachmentBlob($content)) {
            return;
        }

        try {
            $payload = app(EncryptionService::class)->encryptSensitiveFields([
                self::ENCRYPTED_ATTACHMENT_FIELD => base64_encode($content),
            ], []);
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            throw new HttpException('Attachment encryption failed', 500, 'ATTACHMENT_ENCRYPTION_FAILED');
        }

        if (!is_string($json) || $json === '') {
            throw new HttpException('Attachment encryption payload invalid', 500, 'ATTACHMENT_ENCRYPTION_FAILED');
        }

        $serialized = self::ENCRYPTED_ATTACHMENT_PREFIX . $json;
        if (@file_put_contents($absolutePath, $serialized, LOCK_EX) === false) {
            throw new HttpException('Attachment could not be persisted', 500, 'ATTACHMENT_STORAGE_WRITE_FAILED');
        }
    }

    private function readAttachmentPlainContent(string $absolutePath): string
    {
        $content = @file_get_contents($absolutePath);
        if (!is_string($content)) {
            throw new HttpException('Attachment file could not be read', 500, 'ATTACHMENT_FILE_READ_FAILED');
        }

        if (!$this->isEncryptedAttachmentBlob($content)) {
            return $content;
        }

        $json = substr($content, strlen(self::ENCRYPTED_ATTACHMENT_PREFIX));
        if (!is_string($json) || trim($json) === '') {
            throw new HttpException('Encrypted attachment payload invalid', 500, 'ATTACHMENT_DECRYPTION_FAILED');
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new HttpException('Encrypted attachment payload invalid', 500, 'ATTACHMENT_DECRYPTION_FAILED');
        }

        if (!is_array($payload)) {
            throw new HttpException('Encrypted attachment payload invalid', 500, 'ATTACHMENT_DECRYPTION_FAILED');
        }

        try {
            $decrypted = app(EncryptionService::class)->decryptSensitiveFields($payload, []);
        } catch (\Throwable) {
            throw new HttpException('Attachment decryption failed', 500, 'ATTACHMENT_DECRYPTION_FAILED');
        }

        $encoded = $decrypted[self::ENCRYPTED_ATTACHMENT_FIELD] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new HttpException('Attachment decryption failed', 500, 'ATTACHMENT_DECRYPTION_FAILED');
        }

        $plain = base64_decode($encoded, true);
        if (!is_string($plain)) {
            throw new HttpException('Attachment decryption failed', 500, 'ATTACHMENT_DECRYPTION_FAILED');
        }

        return $plain;
    }

    private function isEncryptedAttachmentBlob(string $content): bool
    {
        return str_starts_with($content, self::ENCRYPTED_ATTACHMENT_PREFIX);
    }

    private function downloadTokenTtlSeconds(): int
    {
        $raw = (string) env('SESSION_ATTACHMENT_DOWNLOAD_TOKEN_TTL_SECONDS', (string) self::ATTACHMENT_DOWNLOAD_TTL_SECONDS);
        if (!is_numeric($raw)) {
            return self::ATTACHMENT_DOWNLOAD_TTL_SECONDS;
        }

        $ttl = (int) $raw;
        return max(60, min(3600, $ttl));
    }

    private function attachmentDownloadTokenSecret(): ?string
    {
        $raw = trim((string) env('SESSION_ATTACHMENT_DOWNLOAD_TOKEN_SECRET', ''));
        if ($raw !== '') {
            return $raw;
        }

        $fallback = trim((string) env('SESSION_RECORD_ENCRYPTION_KEY', ''));
        if ($fallback === '') {
            return null;
        }

        $decoded = base64_decode($fallback, true);
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        return $fallback;
    }

    private function buildAttachmentDownloadToken(int $attachmentId, int $recordId, int $expiresAt): string
    {
        $secret = $this->attachmentDownloadTokenSecret();
        if ($secret === null || $secret === '') {
            throw new HttpException('Attachment token secret is not configured', 500, 'ATTACHMENT_TOKEN_SECRET_MISSING');
        }

        $payload = [
            'aid' => $attachmentId,
            'rid' => $recordId,
            'exp' => $expiresAt,
        ];

        $encodedPayload = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $encodedPayload, $secret, true);

        return $encodedPayload . '.' . $this->base64UrlEncode($signature);
    }

    /** @return array<string, mixed>|null */
    private function parseAttachmentDownloadToken(string $token): ?array
    {
        $secret = $this->attachmentDownloadTokenSecret();
        if ($secret === null || $secret === '') {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $encodedSignature] = $parts;
        $payloadJson = $this->base64UrlDecode($encodedPayload);
        $providedSignature = $this->base64UrlDecode($encodedSignature);

        if ($payloadJson === null || $providedSignature === null) {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $secret, true);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            return null;
        }

        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $input): ?string
    {
        $padded = strtr($input, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);
        return is_string($decoded) ? $decoded : null;
    }

    private function safeDownloadFilename(string $filename): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        $name = is_string($name) ? trim($name, '._ ') : '';

        if ($name === '') {
            return 'attachment.bin';
        }

        return $name;
    }
}
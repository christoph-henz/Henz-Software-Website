<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Exceptions\ValidationHttpException;
use App\Repositories\DsarExportJobRepository;
use App\Repositories\FormAuditRepository;
use App\Repositories\FormAttachmentRepository;
use App\Repositories\FormRecordRepository;
use App\Repositories\FormRevisionRepository;
use App\Repositories\FormTemplateRepository;
use App\Repositories\FormTemplateVersionRepository;
use DateTimeImmutable;
use DateTimeZone;
use ZipArchive;

final class FormDsarExportService
{
    public function __construct(
        private readonly Database $db,
        private readonly DsarExportJobRepository $jobs,
        private readonly FormRecordRepository $records,
        private readonly FormRevisionRepository $revisions,
        private readonly FormAttachmentRepository $attachments,
        private readonly FormAuditRepository $audits,
        private readonly FormTemplateRepository $templates,
        private readonly FormTemplateVersionRepository $templateVersions,
        private readonly FormRecordService $recordService,
    ) {
    }

    /**
     * Initiate and execute DSAR export for a client.
     * Synchronously generates ZIP and updates job status.
     * 
     * @param array<string, mixed> $filters export filters (date_from, date_to, status, template_id)
     * @param array<string, mixed> $auditContext audit context
     * @return array<string, mixed> export job response
     */
    public function exportData(int $clientId, array $filters, int $actorUserId, array $auditContext = []): array
    {
        // Validate client exists.
        $client = db('clients')->where('id', $clientId)->first();
        if ($client === null) {
            throw new NotFoundHttpException('Client not found');
        }

        // Check no concurrent export is running
        $latestJob = $this->jobs->findLatestByClientId($clientId);
        if ($latestJob !== null && (string) $latestJob['status'] === 'pending') {
            throw new HttpException('DSAR export already in progress for this client', 409, 'EXPORT_IN_PROGRESS');
        }

        // Validate filters
        $this->validateFilters($filters);

        // Create job record
        $jobId = $this->jobs->create([
            'client_id' => $clientId,
            'created_by_user_id' => $actorUserId,
            'status' => 'pending',
            'export_params_json' => json_encode($filters, JSON_THROW_ON_ERROR),
            'created_at' => $this->nowUtc(),
        ]);

        try {
            // Aggregate data
            $recordsData = $this->aggregateRecords($clientId, $filters);
            $revisionsData = $this->aggregateRevisions($clientId, $filters);
            $attachmentsData = $this->aggregateAttachments($clientId, $filters);
            $auditData = $this->aggregateAuditEntries($clientId, $filters);

            // Generate manifest
            $manifest = $this->generateManifest(
                (string) $jobId,
                (string) $clientId,
                $filters,
                $recordsData,
                $revisionsData,
                $attachmentsData,
                $auditData
            );

            // Serialize to ZIP
            $zipPath = $this->serializeZip(
                (string) $jobId,
                $manifest,
                $recordsData,
                $revisionsData,
                $attachmentsData,
                $auditData
            );

            $fileSize = filesize($zipPath);
            if ($fileSize === false) {
                throw new HttpException('Failed to determine ZIP file size', 500);
            }

            $checksumSha256 = hash_file('sha256', $zipPath);
            if ($checksumSha256 === false) {
                throw new HttpException('Failed to calculate ZIP checksum', 500);
            }

            // Create download token (24 bytes random, hex encoded)
            $token = bin2hex(random_bytes(24));
            $tokenSha256 = hash('sha256', $token);
            $expiresAt = $this->addHours(new \DateTime('now', new \DateTimeZone('UTC')), 12);

            // Update job status
            $this->jobs->updateById($jobId, [
                'status' => 'completed',
                'file_path' => $zipPath,
                'file_size' => $fileSize,
                'file_checksum_sha256' => $checksumSha256,
                'download_token_sha256' => $tokenSha256,
                'download_token_expires_at' => $this->toSqlUtc($expiresAt),
                'completed_at' => $this->nowUtc(),
            ]);

            // Write audit log
            $this->audits->create([
                'actor_user_id' => $actorUserId,
                'action' => 'export',
                'resource_type' => 'session_records',
                'resource_id' => (string) $clientId,
                'field_scope' => null,
                'purpose_code' => 'DSAR_EXPORT',
                'ip_address' => $auditContext['ip_address'] ?? null,
                'user_agent' => $auditContext['user_agent'] ?? null,
                'created_at' => $this->nowUtc(),
            ]);

            return [
                'job_id' => $jobId,
                'status' => 'completed',
                'download_token' => $token,
                'download_url' => '/admin/dsar/exports/' . urlencode((string) $jobId) . '/download',
                'expires_at' => $expiresAt->format('c'),
                'file_size' => $fileSize,
            ];
        } catch (\Exception $e) {
            // Mark job as failed
            $this->jobs->updateById($jobId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => $this->nowUtc(),
            ]);

            throw $e;
        }
    }

    /**
     * Aggregate session records for export, filtered and with visibility rules applied.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function aggregateRecords(int $clientId, array $filters): array
    {
        $queryFilters = [
            'client_id' => $clientId,
        ];

        if (isset($filters['template_id']) && $filters['template_id'] !== null) {
            $queryFilters['template_id'] = (int) $filters['template_id'];
        }

        if (isset($filters['status']) && $filters['status'] !== 'both') {
            $queryFilters['status'] = (string) $filters['status'];
        }

        // List records (all pages, no pagination for export)
        $result = $this->records->listEnriched($queryFilters, 'created_at', 'DESC', 1, 10000);
        $records = [];

        foreach ($result['data'] as $row) {
            $templateVersionId = (int) $row['template_version_id'];
            $templateVersion = $this->templateVersions->findById($templateVersionId);
            if ($templateVersion === null) {
                continue;
            }

            $schema = isset($templateVersion['schema_json'])
                ? json_decode($templateVersion['schema_json'], true)
                : [];

            // Filter payload by visibility rules (read-side filtering)
            $payload = isset($row['payload_json']) ? json_decode($row['payload_json'], true) : [];
            if (is_array($payload) && is_array($schema)) {
                // Use FormRecordService's filterReadableFields logic
                $payload = $this->recordService->filterReadableFields($payload, $schema, (string) $row['status']);
            }

            $records[] = [
                'id' => $row['id'],
                'client_id' => (int) $row['client_id'],
                'template_id' => (int) $row['template_id'],
                'template_name' => $row['template_name'],
                'status' => $row['status'],
                'payload' => $payload,
                'created_at' => $this->toIsoUtc(isset($row['created_at']) ? (string) $row['created_at'] : null),
                'updated_at' => $this->toIsoUtc(isset($row['updated_at']) ? (string) $row['updated_at'] : null),
                'finalized_at' => $this->toIsoUtc(isset($row['finalized_at']) ? (string) $row['finalized_at'] : null),
            ];
        }

        // Filter by date range if provided
        if (isset($filters['date_from']) && $filters['date_from']) {
            $dateFrom = \DateTime::createFromFormat('Y-m-d', $filters['date_from'], new \DateTimeZone('UTC'));
            if ($dateFrom) {
                $records = array_filter($records, fn (array $r) => $r['created_at'] >= $dateFrom->format('Y-m-d'));
            }
        }

        if (isset($filters['date_to']) && $filters['date_to']) {
            $dateTo = \DateTime::createFromFormat('Y-m-d', $filters['date_to'], new \DateTimeZone('UTC'));
            if ($dateTo) {
                $dateTo->modify('+1 day');
                $records = array_filter($records, fn (array $r) => $r['created_at'] < $dateTo->format('Y-m-d'));
            }
        }

        return array_values($records);
    }

    /**
     * Aggregate revisions for all records in export scope.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function aggregateRevisions(int $clientId, array $filters): array
    {
        // Get all record IDs in scope
        $queryFilters = ['client_id' => $clientId];
        if (isset($filters['template_id']) && $filters['template_id'] !== null) {
            $queryFilters['template_id'] = (int) $filters['template_id'];
        }
        if (isset($filters['status']) && $filters['status'] !== 'both') {
            $queryFilters['status'] = (string) $filters['status'];
        }

        $result = $this->records->listEnriched($queryFilters, 'created_at', 'DESC', 1, 10000);
        $recordIds = array_map(fn (array $r) => (int) $r['id'], $result['data']);

        $revisions = [];
        foreach ($recordIds as $recordId) {
            $recordRevisions = $this->revisions->listByRecordId($recordId);
            foreach ($recordRevisions as $rev) {
                $revisions[] = [
                    'id' => $rev['id'],
                    'session_record_id' => (int) $rev['session_record_id'],
                    'revision_no' => (int) $rev['revision_no'],
                    'payload_snapshot' => isset($rev['payload_json_snapshot']) ? json_decode($rev['payload_json_snapshot'], true) : null,
                    'change_reason' => $rev['change_reason'],
                    'changed_by_user_id' => $rev['changed_by_user_id'],
                    'changed_at' => $this->toIsoUtc(isset($rev['changed_at']) ? (string) $rev['changed_at'] : null),
                ];
            }
        }

        return $revisions;
    }

    /**
     * Aggregate attachment metadata (not file contents).
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function aggregateAttachments(int $clientId, array $filters): array
    {
        // Get all record IDs in scope
        $queryFilters = ['client_id' => $clientId];
        if (isset($filters['template_id']) && $filters['template_id'] !== null) {
            $queryFilters['template_id'] = (int) $filters['template_id'];
        }
        if (isset($filters['status']) && $filters['status'] !== 'both') {
            $queryFilters['status'] = (string) $filters['status'];
        }

        $result = $this->records->listEnriched($queryFilters, 'created_at', 'DESC', 1, 10000);
        $recordIds = array_map(fn (array $r) => (int) $r['id'], $result['data']);

        $attachmentsList = [];
        foreach ($recordIds as $recordId) {
            $recordAttachments = $this->attachments->listByFormRecordId($recordId);
            foreach ($recordAttachments as $att) {
                $attachmentsList[] = [
                    'id' => $att['id'],
                    'session_record_id' => (int) $att['session_record_id'],
                    'storage_path' => (string) ($att['storage_path'] ?? ''),
                    'original_filename' => $att['original_filename'],
                    'mime_type' => $att['mime_type'] ?? 'application/octet-stream',
                    'file_size' => $att['file_size'] ?? 0,
                    'checksum_sha256' => $att['checksum_sha256'],
                    'uploaded_at' => $this->toIsoUtc(isset($att['uploaded_at']) ? (string) $att['uploaded_at'] : null),
                ];
            }
        }

        return $attachmentsList;
    }

    /**
     * Aggregate audit entries for this client.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function aggregateAuditEntries(int $clientId, array $filters): array
    {
        $queryFilters = ['client_id' => $clientId];
        if (isset($filters['template_id']) && $filters['template_id'] !== null) {
            $queryFilters['template_id'] = (int) $filters['template_id'];
        }
        if (isset($filters['status']) && $filters['status'] !== 'both') {
            $queryFilters['status'] = (string) $filters['status'];
        }

        $result = $this->records->listEnriched($queryFilters, 'created_at', 'DESC', 1, 10000);
        $recordIds = array_values(array_unique(array_map(fn (array $r): int => (int) $r['id'], $result['data'])));
        if ($recordIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($recordIds), '?'));
        $sql = 'SELECT * FROM data_access_audit
                WHERE resource_type = ? AND resource_id IN (' . $placeholders . ')
                ORDER BY created_at DESC';

        $stmt = $this->db->connection()->prepare($sql);
        if ($stmt === false) {
            throw new HttpException('Failed to prepare audit query', 500);
        }

        $params = array_merge(['session_record'], array_map(static fn (int $id): string => (string) $id, $recordIds));
        $stmt->execute($params);

        $rows = $stmt->fetchAll();
        $auditEntries = [];

        foreach ($rows as $row) {
            $auditEntries[] = [
                'id' => $row['id'],
                'actor_user_id' => $row['actor_user_id'],
                'action' => $row['action'],
                'resource_type' => $row['resource_type'],
                'resource_id' => $row['resource_id'],
                'purpose_code' => $row['purpose_code'],
                'ip_address' => $row['ip_address'],
                'user_agent' => $row['user_agent'],
                'created_at' => $this->toIsoUtc(isset($row['created_at']) ? (string) $row['created_at'] : null),
            ];
        }

        return $auditEntries;
    }

    /**
     * Generate manifest.json content.
     *
     * @param array<int, array<string, mixed>> $recordsData
     * @param array<int, array<string, mixed>> $revisionsData
     * @param array<int, array<string, mixed>> $attachmentsData
     * @param array<int, array<string, mixed>> $auditData
     * @return array<string, mixed>
     */
    private function generateManifest(
        string $jobId,
        string $clientId,
        array $filters,
        array $recordsData,
        array $revisionsData,
        array $attachmentsData,
        array $auditData
    ): array {
        return [
            'export_id' => $jobId,
            'client_id' => $clientId,
            'created_at' => $this->nowUtc(),
            'filters' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'status' => $filters['status'] ?? 'both',
                'template_id' => $filters['template_id'] ?? null,
            ],
            'contents' => [
                'records' => [
                    'file' => 'records.json',
                    'count' => count($recordsData),
                    'checksum' => '',  // Will be filled after JSON generation
                ],
                'revisions' => [
                    'file' => 'revisions.json',
                    'count' => count($revisionsData),
                    'checksum' => '',
                ],
                'attachments' => [
                    'file' => 'attachments.json',
                    'count' => count($attachmentsData),
                    'checksum' => '',
                ],
                'audit' => [
                    'file' => 'audit.json',
                    'count' => count($auditData),
                    'checksum' => '',
                ],
            ],
            'version' => '1.0',
        ];
    }

    /**
     * Serialize data to ZIP archive with manifest, JSON files, and attachment files.
     *
     * @param array<int, array<string, mixed>> $recordsData
     * @param array<int, array<string, mixed>> $revisionsData
     * @param array<int, array<string, mixed>> $attachmentsData
     * @param array<int, array<string, mixed>> $auditData
     * @return string path to ZIP file
     */
    private function serializeZip(
        string $jobId,
        array $manifest,
        array $recordsData,
        array $revisionsData,
        array $attachmentsData,
        array $auditData
    ): string {
        $exportsDir = base_path('storage/exports');
        if (!is_dir($exportsDir)) {
            mkdir($exportsDir, 0750, true);
        }

        $zipPath = $exportsDir . '/' . $jobId . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new HttpException('Failed to create ZIP archive', 500);
        }

        try {
            // Generate JSON files with checksums
            $recordsJson = json_encode($recordsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $revisionsJson = json_encode($revisionsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $attachmentsJson = json_encode($attachmentsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $auditJson = json_encode($auditData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if ($recordsJson === false || $revisionsJson === false || $attachmentsJson === false || $auditJson === false) {
                throw new HttpException('Failed to encode export data to JSON', 500);
            }

            // Update manifest with checksums
            $manifest['contents']['records']['checksum'] = 'sha256:' . hash('sha256', $recordsJson);
            $manifest['contents']['revisions']['checksum'] = 'sha256:' . hash('sha256', $revisionsJson);
            $manifest['contents']['attachments']['checksum'] = 'sha256:' . hash('sha256', $attachmentsJson);
            $manifest['contents']['audit']['checksum'] = 'sha256:' . hash('sha256', $auditJson);

            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($manifestJson === false) {
                throw new HttpException('Failed to encode manifest to JSON', 500);
            }

            // Add JSON files to ZIP
            $zip->addFromString('manifest.json', $manifestJson);
            $zip->addFromString('records.json', $recordsJson);
            $zip->addFromString('revisions.json', $revisionsJson);
            $zip->addFromString('attachments.json', $attachmentsJson);
            $zip->addFromString('audit.json', $auditJson);

            // Add attachment files from storage
            foreach ($attachmentsData as $att) {
                $storagePath = (string) $att['storage_path'];
                $fullPath = base_path($storagePath);

                if (file_exists($fullPath) && is_readable($fullPath)) {
                    $zipEntryPath = 'attachments/' . $att['checksum_sha256'];
                    $zip->addFile($fullPath, $zipEntryPath);
                } else {
                    // Attachment file missing – abort entire export
                    throw new HttpException('Attachment file not accessible: ' . $storagePath, 500);
                }
            }

            $zip->close();

            // Validate ZIP was created
            if (!file_exists($zipPath) || filesize($zipPath) === 0) {
                throw new HttpException('ZIP archive creation failed or is empty', 500);
            }

            // Check size limit
            $maxSizeMb = $this->resolveMaxExportSizeMb();
            $maxSizeBytes = $maxSizeMb * 1024 * 1024;
            if (filesize($zipPath) > $maxSizeBytes) {
                unlink($zipPath);
                throw new HttpException('Export size exceeds limit (' . $maxSizeMb . 'MB)', 413);
            }

            return $zipPath;
        } catch (\Exception $e) {
            $zip->close();
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
            throw $e;
        }
    }

    /**
     * Validate export filters.
     *
     * @param array<string, mixed> $filters
     */
    private function validateFilters(array $filters): void
    {
        if (isset($filters['date_from']) && $filters['date_from']) {
            if (\DateTime::createFromFormat('Y-m-d', $filters['date_from']) === false) {
                throw new ValidationHttpException(['date_from' => ['Invalid date format (expected Y-m-d)']]);
            }
        }

        if (isset($filters['date_to']) && $filters['date_to']) {
            if (\DateTime::createFromFormat('Y-m-d', $filters['date_to']) === false) {
                throw new ValidationHttpException(['date_to' => ['Invalid date format (expected Y-m-d)']]);
            }
        }

        if (isset($filters['status']) && !in_array($filters['status'], ['draft', 'final', 'both'], true)) {
            throw new ValidationHttpException(['status' => ['Invalid status (must be draft, final, or both)']]);
        }

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $from = \DateTime::createFromFormat('Y-m-d', (string) $filters['date_from']);
            $to = \DateTime::createFromFormat('Y-m-d', (string) $filters['date_to']);
            if ($from !== false && $to !== false && $from > $to) {
                throw new ValidationHttpException(['date_range' => ['date_from must be less than or equal to date_to']]);
            }
        }

        if (array_key_exists('template_id', $filters) && $filters['template_id'] !== null && (int) $filters['template_id'] > 0) {
            $template = $this->templates->findById((int) $filters['template_id']);
            if ($template === null) {
                throw new ValidationHttpException(['template_id' => ['template_id does not exist']]);
            }
        }
    }

    private function nowUtc(): string
    {
        return $this->toSqlUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** @param \DateTime $dt */
    private function addHours(\DateTime $dt, int $hours): \DateTime
    {
        $interval = \DateInterval::createFromDateString($hours . ' hours');
        return $dt->add($interval);
    }

    private function toSqlUtc(DateTimeImmutable|\DateTime $value): string
    {
        $immutable = $value instanceof DateTimeImmutable ? $value : DateTimeImmutable::createFromMutable($value);
        return $immutable->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function toIsoUtc(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $date->setTimezone(new DateTimeZone('UTC'))->format('c');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveMaxExportSizeMb(): int
    {
        $row = db('settings')->where('key', 'max_dsar_export_size_mb')->first();
        if (!is_array($row)) {
            return 500;
        }

        $value = isset($row['value']) ? (string) $row['value'] : '';
        $size = (int) trim($value);
        return $size > 0 ? $size : 500;
    }
}

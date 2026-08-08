<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FormAttachmentRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'session_attachments';
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?PDO $pdo = null): int
    {
        return $this->run('session_attachments.create', function () use ($data, $pdo): int {
            $connection = $pdo ?? $this->pdo();
            $stmt = $connection->prepare(
                'INSERT INTO session_attachments (session_record_id, storage_path, original_filename, checksum_sha256, uploaded_at)
                 VALUES (:session_record_id, :storage_path, :original_filename, :checksum_sha256, :uploaded_at)'
            );

            $stmt->execute([
                'session_record_id' => (int) $data['session_record_id'],
                'storage_path' => (string) $data['storage_path'],
                'original_filename' => (string) $data['original_filename'],
                'checksum_sha256' => (string) $data['checksum_sha256'],
                'uploaded_at' => $data['uploaded_at'] ?? date('Y-m-d H:i:s'),
            ]);

            return (int) $connection->lastInsertId();
        });
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->run('session_attachments.findById', fn (): ?array => $this->query()->where('id', $id)->first());
    }

    /** @return array<string, mixed>|null */
    public function findByChecksum(string $checksumSha256): ?array
    {
        return $this->run('session_attachments.findByChecksum', fn (): ?array => $this->query()
            ->where('checksum_sha256', strtolower(trim($checksumSha256)))
            ->first());
    }

    /** @return array<string, mixed>|null */
    public function findByIdForRecord(int $id, int $sessionRecordId): ?array
    {
        return $this->run('session_attachments.findByIdForRecord', fn (): ?array => $this->query()
            ->where('id', $id)
            ->where('session_record_id', $sessionRecordId)
            ->first());
    }

    /** @return array<int, array<string, mixed>> */
    public function listByFormRecordId(int $sessionRecordId): array
    {
        return $this->run('session_attachments.listByFormRecordId', fn (): array => $this->query()
            ->where('session_record_id', $sessionRecordId)
            ->orderBy('uploaded_at', 'DESC')
            ->get());
    }
}

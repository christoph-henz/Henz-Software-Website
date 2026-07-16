<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FormRevisionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'session_record_revisions';
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->run('session_record_revisions.findById', fn (): ?array => $this->query()->where('id', $id)->first());
    }

    /** @return array<int, array<string, mixed>> */
    public function listByRecordId(int $sessionRecordId): array
    {
        return $this->run('session_record_revisions.listByRecordId', fn (): array => $this->query()
            ->where('session_record_id', $sessionRecordId)
            ->orderBy('revision_no', 'DESC')
            ->get());
    }

    public function nextRevisionNo(int $sessionRecordId, ?PDO $pdo = null): int
    {
        return $this->run('session_record_revisions.nextRevisionNo', function () use ($sessionRecordId, $pdo): int {
            $connection = $pdo ?? $this->pdo();
            $stmt = $connection->prepare(
                'SELECT COALESCE(MAX(revision_no), 0) + 1
                 FROM session_record_revisions
                 WHERE session_record_id = :session_record_id
                 FOR UPDATE'
            );
            $stmt->execute(['session_record_id' => $sessionRecordId]);
            $value = $stmt->fetchColumn();
            return max(1, (int) ($value !== false ? $value : 1));
        });
    }

    /** @param array<string, mixed>|null $payloadSnapshot */
    /** @param array<string, mixed>|null $changedFields */
    public function createRevision(
        int $sessionRecordId,
        int $revisionNo,
        ?array $payloadSnapshot,
        ?array $changedFields,
        string $changeReason,
        ?int $changedByUserId,
        ?PDO $pdo = null
    ): int {
        return $this->run('session_record_revisions.createRevision', function () use (
            $sessionRecordId,
            $revisionNo,
            $payloadSnapshot,
            $changedFields,
            $changeReason,
            $changedByUserId,
            $pdo
        ): int {
            $connection = $pdo ?? $this->pdo();
            $stmt = $connection->prepare(
                'INSERT INTO session_record_revisions
                (session_record_id, revision_no, payload_json_snapshot, changed_fields_json, change_reason, changed_by_user_id, changed_at, created_at)
                VALUES
                (:session_record_id, :revision_no, :payload_json_snapshot, :changed_fields_json, :change_reason, :changed_by_user_id, :changed_at, :created_at)'
            );

            $now = date('Y-m-d H:i:s');
            $stmt->execute([
                'session_record_id' => $sessionRecordId,
                'revision_no' => $revisionNo,
                'payload_json_snapshot' => $payloadSnapshot !== null
                    ? json_encode($payloadSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'changed_fields_json' => $changedFields !== null
                    ? json_encode($changedFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'change_reason' => $changeReason,
                'changed_by_user_id' => $changedByUserId,
                'changed_at' => $now,
                'created_at' => $now,
            ]);

            return (int) $connection->lastInsertId();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateRevisionById(int $id, array $data, ?PDO $pdo = null): int
    {
        return $this->run('session_record_revisions.updateRevisionById', function () use ($id, $data, $pdo): int {
            $connection = $pdo ?? $this->pdo();
            $set = [];
            $bindings = ['id' => $id];

            if (array_key_exists('payload_json_snapshot', $data)) {
                $set[] = 'payload_json_snapshot = :payload_json_snapshot';
                $bindings['payload_json_snapshot'] = $data['payload_json_snapshot'] !== null
                    ? json_encode($data['payload_json_snapshot'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null;
            }

            if (array_key_exists('changed_fields_json', $data)) {
                $set[] = 'changed_fields_json = :changed_fields_json';
                $bindings['changed_fields_json'] = $data['changed_fields_json'] !== null
                    ? json_encode($data['changed_fields_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null;
            }

            if (array_key_exists('change_reason', $data)) {
                $set[] = 'change_reason = :change_reason';
                $bindings['change_reason'] = (string) $data['change_reason'];
            }

            if (array_key_exists('changed_by_user_id', $data)) {
                $set[] = 'changed_by_user_id = :changed_by_user_id';
                $bindings['changed_by_user_id'] = $data['changed_by_user_id'];
            }

            if ($set === []) {
                return 0;
            }

            $set[] = 'changed_at = :changed_at';
            $bindings['changed_at'] = date('Y-m-d H:i:s');

            $sql = 'UPDATE session_record_revisions SET ' . implode(', ', $set) . ' WHERE id = :id';
            $stmt = $connection->prepare($sql);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        });
    }
}

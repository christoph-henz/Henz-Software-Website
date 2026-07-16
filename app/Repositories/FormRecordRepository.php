<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FormRecordRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'session_records';
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?PDO $pdo = null): int
    {
        return $this->run('session_records.create', function () use ($data, $pdo): int {
            $connection = $pdo ?? $this->pdo();
            $stmt = $connection->prepare(
                'INSERT INTO session_records
                (booking_id, client_id, template_id, template_version_id, status, payload_json, encrypted_payload_json, created_by_user_id, updated_by_user_id, finalized_at, created_at, updated_at)
                VALUES
                (:booking_id, :client_id, :template_id, :template_version_id, :status, :payload_json, :encrypted_payload_json, :created_by_user_id, :updated_by_user_id, :finalized_at, :created_at, :updated_at)'
            );

            $now = date('Y-m-d H:i:s');
            $payloadJson = array_key_exists('payload_json', $data)
                ? json_encode($data['payload_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
            $encryptedJson = array_key_exists('encrypted_payload_json', $data)
                ? json_encode($data['encrypted_payload_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            $stmt->execute([
                'booking_id' => (int) $data['booking_id'],
                'client_id' => (int) $data['client_id'],
                'template_id' => (int) $data['template_id'],
                'template_version_id' => (int) $data['template_version_id'],
                'status' => (string) ($data['status'] ?? 'draft'),
                'payload_json' => $payloadJson,
                'encrypted_payload_json' => $encryptedJson,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'updated_by_user_id' => $data['updated_by_user_id'] ?? null,
                'finalized_at' => $data['finalized_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) $connection->lastInsertId();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateById(int $id, array $data, ?PDO $pdo = null): int
    {
        return $this->run('session_records.updateById', function () use ($id, $data, $pdo): int {
            $connection = $pdo ?? $this->pdo();
            $set = [];
            $bindings = ['id' => $id, 'updated_at' => date('Y-m-d H:i:s')];

            $this->addUpdateField($set, $bindings, 'status', $data);
            $this->addUpdateJsonField($set, $bindings, 'payload_json', $data);
            $this->addUpdateJsonField($set, $bindings, 'encrypted_payload_json', $data);
            $this->addUpdateField($set, $bindings, 'template_id', $data);
            $this->addUpdateField($set, $bindings, 'template_version_id', $data);
            $this->addUpdateField($set, $bindings, 'updated_by_user_id', $data);
            $this->addUpdateField($set, $bindings, 'finalized_at', $data);

            if ($set === []) {
                return 0;
            }

            $set[] = 'updated_at = :updated_at';

            $sql = 'UPDATE session_records SET ' . implode(', ', $set) . ' WHERE id = :id AND deleted_at IS NULL';
            $stmt = $connection->prepare($sql);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        });
    }

    public function softDeleteById(int $id, int $actorUserId, ?PDO $pdo = null): int
    {
        return $this->run('session_records.softDeleteById', function () use ($id, $actorUserId, $pdo): int {
            $connection = $pdo ?? $this->pdo();
            $stmt = $connection->prepare(
                'UPDATE session_records
                 SET deleted_at = :deleted_at, updated_by_user_id = :updated_by_user_id, updated_at = :updated_at
                 WHERE id = :id AND deleted_at IS NULL'
            );

            $now = date('Y-m-d H:i:s');
            $stmt->execute([
                'deleted_at' => $now,
                'updated_by_user_id' => $actorUserId,
                'updated_at' => $now,
                'id' => $id,
            ]);

            return $stmt->rowCount();
        });
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id, bool $includeDeleted = false): ?array
    {
        return $this->run('session_records.findById', function () use ($id, $includeDeleted): ?array {
            $sql = 'SELECT * FROM session_records WHERE id = :id';
            if (!$includeDeleted) {
                $sql .= ' AND deleted_at IS NULL';
            }
            $sql .= ' LIMIT 1';

            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            return is_array($row) ? $row : null;
        });
    }

    /** @return array<string, mixed>|null */
    public function findEnrichedById(int $id, bool $includeDeleted = false): ?array
    {
        return $this->run('session_records.findEnrichedById', function () use ($id, $includeDeleted): ?array {
            $sql = 'SELECT sr.*, st.template_key, st.name AS template_name, st.description AS template_description, st.is_active AS template_is_active,
                           stv.version_no AS template_version_no, stv.schema_json AS schema_json,
                           c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email, c.timezone AS client_timezone,
                           b.scheduled_at AS booking_scheduled_at, b.status AS booking_status
                    FROM session_records sr
                    INNER JOIN form_templates st ON st.id = sr.template_id
                    INNER JOIN form_template_versions stv ON stv.id = sr.template_version_id
                    INNER JOIN clients c ON c.id = sr.client_id
                    INNER JOIN bookings b ON b.id = sr.booking_id
                    WHERE sr.id = :id';
            if (!$includeDeleted) {
                $sql .= ' AND sr.deleted_at IS NULL AND st.deleted_at IS NULL';
            }
            $sql .= ' LIMIT 1';

            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            return is_array($row) ? $row : null;
        });
    }

    /** @param array<string, mixed> $filters */
    /** @return array{data: array<int, array<string, mixed>>, total: int} */
    public function listEnriched(array $filters = [], string $sort = 'created_at', string $direction = 'DESC', int $page = 1, int $perPage = 20): array
    {
        return $this->run('session_records.listEnriched', function () use ($filters, $sort, $direction, $page, $perPage): array {
            $page = max(1, $page);
            $perPage = max(1, min(100, $perPage));
            $offset = ($page - 1) * $perPage;

            $bindings = [];
            $whereSql = $this->buildFilterSql($filters, $bindings);
            $orderSql = $this->buildOrderSql($sort, $direction);

                 $sql = 'SELECT sr.*, st.template_key, st.name AS template_name, st.description AS template_description, st.is_active AS template_is_active,
                          stv.version_no AS template_version_no, stv.schema_json AS schema_json,
                           c.first_name AS client_first_name, c.last_name AS client_last_name, c.email AS client_email, c.timezone AS client_timezone,
                           b.scheduled_at AS booking_scheduled_at, b.status AS booking_status
                    FROM session_records sr
                    INNER JOIN form_templates st ON st.id = sr.template_id
                    INNER JOIN form_template_versions stv ON stv.id = sr.template_version_id
                    INNER JOIN clients c ON c.id = sr.client_id
                    INNER JOIN bookings b ON b.id = sr.booking_id
                    ' . $whereSql . '
                    ORDER BY ' . $orderSql . '
                    LIMIT :limit OFFSET :offset';

            $stmt = $this->pdo()->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $countSql = 'SELECT COUNT(sr.id)
                         FROM session_records sr
                         INNER JOIN clients c ON c.id = sr.client_id
                         INNER JOIN bookings b ON b.id = sr.booking_id
                         INNER JOIN form_templates st ON st.id = sr.template_id
                         ' . $whereSql;

            $countStmt = $this->pdo()->prepare($countSql);
            foreach ($bindings as $key => $value) {
                $countStmt->bindValue(':' . $key, $value);
            }
            $countStmt->execute();
            $total = (int) $countStmt->fetchColumn();

            return [
                'data' => is_array($rows) ? $rows : [],
                'total' => $total,
            ];
        });
    }

    /** @param array<string, mixed> $filters */
    /** @param array<string, mixed> $bindings */
    private function buildFilterSql(array $filters, array &$bindings): string
    {
        $where = [];

        $includeDeleted = !empty($filters['include_deleted']);
        if (!$includeDeleted) {
            $where[] = 'sr.deleted_at IS NULL';
            $where[] = 'st.deleted_at IS NULL';
        }

        if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
            $where[] = 'sr.status = :status';
            $bindings['status'] = (string) $filters['status'];
        }

        if (!empty($filters['template_id'])) {
            $where[] = 'sr.template_id = :template_id';
            $bindings['template_id'] = (int) $filters['template_id'];
        }

        if (!empty($filters['client_id'])) {
            $where[] = 'sr.client_id = :client_id';
            $bindings['client_id'] = (int) $filters['client_id'];
        }

        if (!empty($filters['booking_id'])) {
            $where[] = 'sr.booking_id = :booking_id';
            $bindings['booking_id'] = (int) $filters['booking_id'];
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $where[] = '(c.first_name LIKE :q OR c.last_name LIKE :q OR c.email LIKE :q OR st.template_key LIKE :q)';
            $bindings['q'] = '%' . trim((string) $filters['q']) . '%';
        }

        return $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));
    }

    private function buildOrderSql(string $sort, string $direction): string
    {
        $sortMap = [
            'created_at' => 'sr.created_at',
            'updated_at' => 'sr.updated_at',
            'status' => 'sr.status',
            'client' => 'c.last_name',
            'booking_scheduled_at' => 'b.scheduled_at',
        ];

        $column = $sortMap[$sort] ?? 'sr.created_at';
        $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        return $column . ' ' . $dir;
    }

    /** @param array<int, string> $set */
    /** @param array<string, mixed> $bindings */
    /** @param array<string, mixed> $data */
    private function addUpdateField(array &$set, array &$bindings, string $field, array $data): void
    {
        if (!array_key_exists($field, $data)) {
            return;
        }

        $set[] = $field . ' = :' . $field;
        $bindings[$field] = $data[$field];
    }

    /** @param array<int, string> $set */
    /** @param array<string, mixed> $bindings */
    /** @param array<string, mixed> $data */
    private function addUpdateJsonField(array &$set, array &$bindings, string $field, array $data): void
    {
        if (!array_key_exists($field, $data)) {
            return;
        }

        $set[] = $field . ' = :' . $field;
        $bindings[$field] = $data[$field] !== null
            ? json_encode($data[$field], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
    }
}

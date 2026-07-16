<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FormAuditRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'data_access_audit';
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?PDO $pdo = null): int
    {
        return $this->run('data_access_audit.create', function () use ($data, $pdo): int {
            $connection = $pdo ?? $this->pdo();
            $stmt = $connection->prepare(
                'INSERT INTO data_access_audit
                (actor_user_id, action, resource_type, resource_id, field_scope, purpose_code, ip_address, user_agent, created_at)
                VALUES
                (:actor_user_id, :action, :resource_type, :resource_id, :field_scope, :purpose_code, :ip_address, :user_agent, :created_at)'
            );

            $stmt->execute([
                'actor_user_id' => $data['actor_user_id'] ?? null,
                'action' => (string) $data['action'],
                'resource_type' => (string) $data['resource_type'],
                'resource_id' => (string) $data['resource_id'],
                'field_scope' => $data['field_scope'] ?? null,
                'purpose_code' => $data['purpose_code'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return (int) $connection->lastInsertId();
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function listForResource(string $resourceType, string $resourceId): array
    {
        return $this->run('data_access_audit.listForResource', fn (): array => $this->query()
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->orderBy('created_at', 'DESC')
            ->get());
    }
}

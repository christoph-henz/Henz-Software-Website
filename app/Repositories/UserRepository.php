<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'users';
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->query()->select(['id', 'name', 'email', 'created_at'])->get();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->query()->where('id', $id)->first();
    }

    /** @param array{name: string, email: string} $data */
    public function create(array $data): int
    {
        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        return $this->query()->insert($payload);
    }
}

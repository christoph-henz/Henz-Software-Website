<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

final class UserService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->users->all();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->users->findById($id);
    }

    /** @param array{name: string, email: string} $data */
    public function create(array $data): array
    {
        $id = $this->users->create($data);
        return $this->users->findById($id) ?? ['id' => $id] + $data;
    }
}

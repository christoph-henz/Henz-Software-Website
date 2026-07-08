<?php

declare(strict_types=1);

namespace App\Core\Database;

use Closure;
use PDO;
use Throwable;

final class Database
{
    public function __construct(private readonly ConnectionManager $manager)
    {
    }

    public function connection(?string $name = null): PDO
    {
        return $this->manager->connection($name);
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this->connection(), $table);
    }

    /** @param Closure(PDO): mixed $callback */
    public function transaction(Closure $callback, ?string $connection = null): mixed
    {
        $pdo = $this->connection($connection);
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }
}

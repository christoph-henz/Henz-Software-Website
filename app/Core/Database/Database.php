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

    /**
     * @param string|array<int, string>|null $connection
     */
    public function table(string $table, string|array|null $connection = null): QueryBuilder|MultiConnectionQueryBuilder
    {
        if (is_array($connection)) {
            return new MultiConnectionQueryBuilder($this, $table, $connection);
        }

        return new QueryBuilder($this->connection($connection), $table);
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

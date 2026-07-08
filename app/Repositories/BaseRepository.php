<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\QueryBuilder;
use App\Core\Exceptions\HttpException;
use App\Core\Database\Database;
use PDO;
use Throwable;

abstract class BaseRepository
{
    public function __construct(protected readonly Database $db)
    {
    }

    abstract protected function table(): string;

    protected function query(): QueryBuilder
    {
        return $this->db->table($this->table());
    }

    /**
     * Fallback method to get the PDO instance for advanced queries.
     * @return PDO
     */
    protected function pdo(): PDO
    {
        return $this->db->connection();
    }

    /** @template T */
    protected function run(string $operation, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new HttpException(sprintf('%s failed: %s', $operation, $exception->getMessage()), 500);
        }
    }
}

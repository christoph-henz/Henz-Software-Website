<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use PDOException;
use RuntimeException;

final class ConnectionManager
{
    /** @var array<string, PDO> */
    private array $connections = [];

    public function connection(?string $name = null): PDO
    {
        $connectionName = $name ?? (string) config('database.default', 'mysql');

        if (isset($this->connections[$connectionName])) {
            return $this->connections[$connectionName];
        }

        $config = config('database.connections.' . $connectionName);
        if (!is_array($config)) {
            throw new RuntimeException(sprintf('Database connection [%s] not configured', $connectionName));
        }

        $driver = $config['driver'] ?? 'mysql';
        $dsn = $this->buildDsn($driver, $config);

        try {
            $pdo = new PDO(
                $dsn,
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
        }

        $this->connections[$connectionName] = $pdo;
        return $pdo;
    }

    /** @param array<string, mixed> $config */
    private function buildDsn(string $driver, array $config): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                $driver === 'mariadb' ? 'mysql' : $driver,
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 3306,
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 5432,
                $config['database'] ?? ''
            ),
            'sqlite' => 'sqlite:' . ($config['database'] ?? ':memory:'),
            default => throw new RuntimeException(sprintf('Unsupported driver: %s', $driver)),
        };
    }
}

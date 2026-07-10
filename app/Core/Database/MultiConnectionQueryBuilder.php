<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use PDOStatement;
use RuntimeException;

final class MultiConnectionQueryBuilder
{
    /** @var array<int, string> */
    private array $columns = ['*'];

    /** @var array<int, string> */
    private array $joins = [];

    /** @var array<int, string> */
    private array $where = [];

    /** @var array<int, array{column: string, direction: 'ASC'|'DESC'}> */
    private array $orderBy = [];

    /** @var array<string, mixed> */
    private array $bindings = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var array<int, string> */
    private array $connections;

    /** @param array<int, string> $connections */
    public function __construct(
        private readonly Database $database,
        private readonly string $table,
        array $connections
    ) {
        $normalizedConnections = [];
        foreach ($connections as $connection) {
            if (is_string($connection) && trim($connection) !== '') {
                $normalizedConnections[] = trim($connection);
            }
        }

        if ($normalizedConnections === []) {
            throw new RuntimeException('At least one database connection is required for multi-database queries.');
        }

        $this->connections = array_values(array_unique($normalizedConnections));
    }

    /** @param array<int, string> $columns */
    public function select(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    public function where(string $column, mixed $value, string $operator = '='): self
    {
        $normalizedOperator = strtoupper(trim($operator));
        if (($normalizedOperator === 'IS' || $normalizedOperator === 'IS NOT') && $value === null) {
            $this->where[] = sprintf('%s %s NULL', $column, $normalizedOperator);
            return $this;
        }

        $bindingKey = 'w_' . count($this->bindings);
        $this->where[] = sprintf('%s %s :%s', $column, $operator, $bindingKey);
        $this->bindings[$bindingKey] = $value;
        return $this;
    }

    /** @param array<string, mixed> $bindings */
    public function whereRaw(string $expression, array $bindings = []): self
    {
        foreach ($bindings as $key => $value) {
            $bindingKey = 'wr_' . count($this->bindings) . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $key);
            $expression = str_replace(':' . $key, ':' . $bindingKey, $expression);
            $this->bindings[$bindingKey] = $value;
        }

        $this->where[] = $expression;
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->joins[] = sprintf('%s JOIN %s ON %s %s %s', strtoupper($type), $table, $first, $operator, $second);
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $normalizedDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy[] = ['column' => $column, 'direction' => $normalizedDirection];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $rows = $this->limit(1)->get();
        return $rows[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function get(): array
    {
        $rows = [];

        foreach ($this->connections as $connection) {
            $stmt = $this->executeSelect($this->database->connection($connection));
            $fetched = $stmt->fetchAll();
            if (is_array($fetched)) {
                $rows = array_merge($rows, $fetched);
            }
        }

        if ($this->orderBy !== []) {
            $rows = $this->sortRows($rows);
        }

        if ($this->offset !== null) {
            $rows = array_slice($rows, $this->offset);
        }

        if ($this->limit !== null) {
            $rows = array_slice($rows, 0, $this->limit);
        }

        return $rows;
    }

    public function count(string $column = '*'): int
    {
        $count = 0;

        foreach ($this->connections as $connection) {
            $count += $this->countOnConnection($this->database->connection($connection), $column);
        }

        return $count;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        throw new RuntimeException('Insert across multiple databases is not supported with a combined query.');
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): int
    {
        throw new RuntimeException('Update across multiple databases is not supported with a combined query.');
    }

    public function delete(): int
    {
        throw new RuntimeException('Delete across multiple databases is not supported with a combined query.');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            foreach ($this->orderBy as $order) {
                $column = $this->normalizeColumnName($order['column']);
                $direction = $order['direction'];

                $leftValue = $left[$column] ?? null;
                $rightValue = $right[$column] ?? null;

                $comparison = $leftValue <=> $rightValue;
                if ($comparison !== 0) {
                    return $direction === 'DESC' ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }

    private function normalizeColumnName(string $column): string
    {
        $trimmed = preg_replace('/\s+(ASC|DESC)$/i', '', trim($column));
        $trimmed = is_string($trimmed) ? $trimmed : trim($column);
        if (str_contains($trimmed, '.')) {
            $parts = explode('.', $trimmed);
            return trim((string) end($parts));
        }

        return $trimmed;
    }

    private function executeSelect(PDO $pdo): PDOStatement
    {
        $sql = sprintf('SELECT %s FROM %s', implode(', ', $this->columns), $this->table);

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->bindings);

        return $stmt;
    }

    private function countOnConnection(PDO $pdo, string $column): int
    {
        $sql = sprintf('SELECT COUNT(%s) AS aggregate FROM %s', $column, $this->table);

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->bindings);

        $value = $stmt->fetchColumn();
        return (int) ($value !== false ? $value : 0);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use PDOStatement;

final class QueryBuilder
{
    /** @var array<int, string> */
    private array $columns = ['*'];

    /** @var array<int, string> */
    private array $joins = [];

    /** @var array<int, string> */
    private array $where = [];

    /** @var array<int, string> */
    private array $orderBy = [];

    /** @var array<string, mixed> */
    private array $bindings = [];

    private ?int $limit = null;

    private ?int $offset = null;

    public function __construct(private readonly PDO $pdo, private readonly string $table)
    {
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
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy[] = sprintf('%s %s', $column, $direction);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
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
        $this->limit(1);
        $stmt = $this->executeSelect();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function get(): array
    {
        $stmt = $this->executeSelect();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function count(string $column = '*'): int
    {
        $sql = sprintf('SELECT COUNT(%s) AS aggregate FROM %s', $column, $this->table);

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        $value = $stmt->fetchColumn();
        return (int) ($value !== false ? $value : 0);
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): int
    {
        $setParts = [];
        foreach ($data as $column => $value) {
            $bindingKey = 'u_' . count($this->bindings) . '_' . $column;
            $setParts[] = sprintf('%s = :%s', $column, $bindingKey);
            $this->bindings[$bindingKey] = $value;
        }

        $sql = sprintf('UPDATE %s SET %s', $this->table, implode(', ', $setParts));

        if ($this->where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        return $stmt->rowCount();
    }

    public function delete(): int
    {
        $sql = sprintf('DELETE FROM %s', $this->table);

        if ($this->where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        return $stmt->rowCount();
    }

    private function executeSelect(): PDOStatement
    {
        $sql = sprintf('SELECT %s FROM %s', implode(', ', $this->columns), $this->table);

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        return $stmt;
    }
}

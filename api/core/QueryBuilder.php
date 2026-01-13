<?php
/**
 * Simple Query Builder
 * Provides fluent interface for database queries
 */

class QueryBuilder {
    private $pdo;
    private $table;
    private $wheres = [];
    private $bindings = [];
    private $select = '*';
    private $orderBy = [];
    private $limit = null;
    private $offset = null;
    
    public function __construct(string $table) {
        $this->pdo = db();
        $this->table = $table;
    }
    
    public function select($columns = '*'): self {
        if (is_array($columns)) {
            $this->select = implode(', ', $columns);
        } else {
            $this->select = $columns;
        }
        return $this;
    }
    
    public function where(string $column, $operator, $value = null): self {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $escapedColumn = $this->escapeColumn($column);
        $this->wheres[] = "$escapedColumn $operator ?";
        $this->bindings[] = $value;
        return $this;
    }
    
    private function escapeColumn(string $column): string {
        // Don't escape if already escaped or contains functions/expressions
        if (strpos($column, '`') !== false || strpos($column, '(') !== false || strpos($column, '.') !== false) {
            return $column;
        }
        return "`$column`";
    }
    
    public function whereIn(string $column, array $values): self {
        if (empty($values)) {
            $this->wheres[] = "1 = 0"; // Always false
            return $this;
        }
        
        $escapedColumn = $this->escapeColumn($column);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = "$escapedColumn IN ($placeholders)";
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }
    
    public function whereNull(string $column): self {
        $escapedColumn = $this->escapeColumn($column);
        $this->wheres[] = "$escapedColumn IS NULL";
        return $this;
    }
    
    public function whereNotNull(string $column): self {
        $escapedColumn = $this->escapeColumn($column);
        $this->wheres[] = "$escapedColumn IS NOT NULL";
        return $this;
    }
    
    public function orderBy(string $column, string $direction = 'ASC'): self {
        $escapedColumn = $this->escapeColumn($column);
        $this->orderBy[] = "$escapedColumn $direction";
        return $this;
    }
    
    
    public function limit(int $limit): self {
        $this->limit = $limit;
        return $this;
    }
    
    public function offset(int $offset): self {
        $this->offset = $offset;
        return $this;
    }
    
    public function first(): ?array {
        $this->limit = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }
    
    public function get(): array {
        $sql = "SELECT {$this->select} FROM {$this->table}";
        
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        
        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY " . implode(', ', $this->orderBy);
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->fetchAll();
    }
    
    public function count(): int {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $result = $stmt->fetch();
        return (int) ($result['count'] ?? 0);
    }
    
    public function sum(string $column): float {
        $escapedColumn = $this->escapeColumn($column);
        $sql = "SELECT COALESCE(SUM($escapedColumn), 0) as total FROM {$this->table}";
        
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $result = $stmt->fetch();
        return (float) ($result['total'] ?? 0);
    }
    
    public function insert(array $data): int {
        $columns = implode(', ', array_map(fn($col) => $this->escapeColumn($col), array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        
        return (int) $this->pdo->lastInsertId();
    }
    
    public function update(array $data): int {
        $sets = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $escapedColumn = $this->escapeColumn($column);
            $sets[] = "$escapedColumn = ?";
            $values[] = $value;
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
        
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($values, $this->bindings));
        
        return $stmt->rowCount();
    }
    
    public function delete(): int {
        $sql = "DELETE FROM {$this->table}";
        
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        
        return $stmt->rowCount();
    }
    
    public function exists(): bool {
        return $this->first() !== null;
    }
}

/**
 * Helper function to create a new QueryBuilder instance
 */
function table(string $table): QueryBuilder {
    return new QueryBuilder($table);
}

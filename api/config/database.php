<?php
/**
 * Database Configuration
 */

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Helper function
function env($key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// Database connection singleton
class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $database = env('DB_DATABASE', 'smsportal');
        $username = env('DB_USERNAME', 'root');
        $password = env('DB_PASSWORD', '');
        $charset = env('DB_CHARSET', 'utf8mb4');
        
        $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=$charset";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }
    
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
    
    public static function table(string $table): QueryBuilder {
        return new QueryBuilder($table);
    }
}

// Simple Query Builder
class QueryBuilder {
    private $pdo;
    private $table;
    private $wheres = [];
    private $bindings = [];
    private $orders = [];
    private $limit = null;
    private $offset = null;
    private $selects = ['*'];
    private $joins = [];
    
    public function __construct(string $table) {
        $this->pdo = Database::getInstance();
        $this->table = $table;
    }
    
    public function select(...$columns): self {
        $this->selects = $columns;
        return $this;
    }
    
    public function where(string $column, $operator, $value = null): self {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        $this->wheres[] = "$column $operator ?";
        $this->bindings[] = $value;
        return $this;
    }
    
    public function whereIn(string $column, array $values): self {
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = "$column IN ($placeholders)";
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }
    
    public function orderBy(string $column, string $direction = 'ASC'): self {
        $this->orders[] = "$column $direction";
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
    
    public function join(string $table, string $first, string $operator, string $second): self {
        $this->joins[] = "JOIN $table ON $first $operator $second";
        return $this;
    }
    
    public function leftJoin(string $table, string $first, string $operator, string $second): self {
        $this->joins[] = "LEFT JOIN $table ON $first $operator $second";
        return $this;
    }
    
    private function buildSelect(): string {
        $sql = "SELECT " . implode(', ', $this->selects) . " FROM {$this->table}";
        
        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        
        if (!empty($this->orders)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        
        return $sql;
    }
    
    public function get(): array {
        $stmt = $this->pdo->prepare($this->buildSelect());
        $stmt->execute($this->bindings);
        return $stmt->fetchAll();
    }
    
    public function first(): ?array {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }
    
    public function count(): int {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return (int) $stmt->fetch()['count'];
    }
    
    public function sum(string $column): float {
        $sql = "SELECT COALESCE(SUM($column), 0) as total FROM {$this->table}";
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return (float) $stmt->fetch()['total'];
    }
    
    public function insert(array $data): int {
        $columns = implode(', ', array_keys($data));
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
            $sets[] = "$column = ?";
            $values[] = $value;
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($values, $this->bindings));
        
        return $stmt->rowCount();
    }
    
    public function delete(): int {
        $sql = "DELETE FROM {$this->table}";
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        
        return $stmt->rowCount();
    }
}

// DB helper function
function db(): PDO {
    return Database::getInstance();
}

function table(string $name): QueryBuilder {
    return Database::table($name);
}

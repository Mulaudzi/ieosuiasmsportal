<?php

final class Migration
{
    public static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$stmt->execute([$table]);return (int)$stmt->fetchColumn()>0;
    }
    public static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!self::columnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    public static function addIndex(PDO $pdo, string $table, string $name, string $columns, bool $unique = false): void
    {
        if (!self::indexExists($pdo, $table, $name)) {
            $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
            $pdo->exec("CREATE {$kind} `{$name}` ON `{$table}` ({$columns})");
        }
    }

    public static function assertNoDuplicates(PDO $pdo,string $table,array $columns,string $label):void
    {
        $quoted=implode(',',array_map(fn($column)=>"`{$column}`",$columns));
        $notNull=implode(' AND ',array_map(fn($column)=>"`{$column}` IS NOT NULL",$columns));
        $sql="SELECT COUNT(*) FROM (SELECT {$quoted} FROM `{$table}` WHERE {$notNull} GROUP BY {$quoted} HAVING COUNT(*)>1) duplicate_groups";
        if((int)$pdo->query($sql)->fetchColumn()>0)throw new RuntimeException("Migration preflight failed: duplicate {$label} values must be resolved first");
    }

    public static function modifyColumn(PDO $pdo,string $table,string $column,string $definition):void
    {
        if(self::columnExists($pdo,$table,$column))$pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
    }
}

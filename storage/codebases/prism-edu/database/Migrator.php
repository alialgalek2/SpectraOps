<?php
declare(strict_types=1);

namespace Database;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class Migrator {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $this->pdo = $GLOBALS['pdo'];
        } elseif (class_exists('\\App\\Config\\Database')) {
            $this->pdo = \App\Config\Database::getConnection();
        } else {
            throw new RuntimeException("Database connection is not available for Migrator.");
        }
    }

    /**
     * Non-blocking column addition.
     * First checks information_schema.COLUMNS for existence.
     * If missing, executes: ALTER TABLE ... ADD COLUMN ... ALGORITHM=INPLACE, LOCK=NONE
     * Gracefully falls back to standard ALTER TABLE if ALGORITHM=INPLACE is unsupported.
     */
    public function safeAddColumn(string $table, string $column, string $definition): void {
        // Sanitize table and column identifiers to prevent SQL injection
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new RuntimeException("Invalid table identifier: {$table}");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new RuntimeException("Invalid column identifier: {$column}");
        }

        // 1. Check if column already exists in the current database schema
        $checkStmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = ? 
              AND COLUMN_NAME = ?
        ");
        $checkStmt->execute([$table, $column]);
        $exists = (int)$checkStmt->fetchColumn() > 0;

        if ($exists) {
            // Column already exists; safe idempotent no-op
            return;
        }

        // 2. Attempt non-blocking online DDL
        try {
            $inplaceSql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition} ALGORITHM=INPLACE, LOCK=NONE";
            $this->pdo->exec($inplaceSql);
        } catch (PDOException $e) {
            // Storage engine or DDL specification does not support ALGORITHM=INPLACE or LOCK=NONE
            // Fall back to standard ALTER TABLE
            error_log("[Migrator] Notice: INPLACE DDL unsupported for {$table}.{$column}. Falling back to standard ALTER TABLE. Reason: " . $e->getMessage());
            $fallbackSql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}";
            $this->pdo->exec($fallbackSql);
        }
    }

    /**
     * Paginated row backfill based on the table's Primary Key.
     * Injects microsecond pauses (usleep 20ms) between batches to prevent locking production tables.
     */
    public function backfillDataQuietly(string $table, string $sourceCol, string $targetCol, int $chunkSize = 500): void {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new RuntimeException("Invalid table identifier: {$table}");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $sourceCol)) {
            throw new RuntimeException("Invalid source column identifier: {$sourceCol}");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $targetCol)) {
            throw new RuntimeException("Invalid target column identifier: {$targetCol}");
        }

        // Discover the primary key for deterministic cursor pagination
        $pkStmt = $this->pdo->prepare("
            SELECT COLUMN_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = ? 
              AND CONSTRAINT_NAME = 'PRIMARY' 
            ORDER BY ORDINAL_POSITION 
            LIMIT 1
        ");
        $pkStmt->execute([$table]);
        $pk = $pkStmt->fetchColumn();
        if (!$pk || !preg_match('/^[a-zA-Z0-9_]+$/', (string)$pk)) {
            $pk = 'id';
        }

        $lastId = null;

        while (true) {
            // Fetch chunk of primary keys
            if ($lastId === null) {
                $selectSql = "SELECT `{$pk}` FROM `{$table}` ORDER BY `{$pk}` ASC LIMIT {$chunkSize}";
                $stmt = $this->pdo->query($selectSql);
            } else {
                $selectSql = "SELECT `{$pk}` FROM `{$table}` WHERE `{$pk}` > ? ORDER BY `{$pk}` ASC LIMIT {$chunkSize}";
                $stmt = $this->pdo->prepare($selectSql);
                $stmt->execute([$lastId]);
            }

            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($ids)) {
                break;
            }

            // Update chunk rows quietly
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateSql = "
                UPDATE `{$table}` 
                SET `{$targetCol}` = `{$sourceCol}` 
                WHERE `{$pk}` IN ({$placeholders})
                  AND (`{$targetCol}` IS NULL OR `{$targetCol}` != `{$sourceCol}`)
            ";
            $updStmt = $this->pdo->prepare($updateSql);
            $updStmt->execute($ids);

            $lastId = end($ids);

            if (count($ids) < $chunkSize) {
                break;
            }

            // Microsecond sleep between chunks to yield read/write locks for active transactions
            usleep(20000); // 20ms
        }
    }

    /**
     * Discovers and runs all pending migrations in database/migrations/
     */
    public function runPendingMigrations(?string $migrationsDir = null): array {
        $appRoot = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $dir = $migrationsDir ?: $appRoot . '/database/migrations';

        // Ensure schema tracking table exists
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                batch_version VARCHAR(50) NOT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        if (!is_dir($dir)) {
            return [
                'success'       => true,
                'message'       => 'No migrations directory found.',
                'applied_count' => 0,
                'applied'       => []
            ];
        }

        // Fetch already applied migrations
        $stmt = $this->pdo->query("SELECT migration_name FROM schema_migrations");
        $appliedInDb = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $scanned = scandir($dir) ?: [];
        $files = [];
        foreach ($scanned as $f) {
            if (str_ends_with($f, '.php') && !in_array($f, $appliedInDb, true)) {
                $files[$f] = $dir . DIRECTORY_SEPARATOR . $f;
            }
        }

        if (empty($files)) {
            return [
                'success'       => true,
                'message'       => 'Schema is up to date (0 pending migrations).',
                'applied_count' => 0,
                'applied'       => []
            ];
        }

        ksort($files);
        $appliedList = [];
        $currentVersion = getenv('APP_VERSION') ?: '2.5.0';

        $insStmt = $this->pdo->prepare("INSERT INTO schema_migrations (migration_name, batch_version) VALUES (?, ?)");

        foreach ($files as $name => $filePath) {
            $migrator = $this;
            $pdo = $this->pdo;

            ob_start();
            try {
                require $filePath;
                ob_end_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                throw new RuntimeException("Migration '{$name}' failed: " . $e->getMessage(), 0, $e);
            }

            $insStmt->execute([$name, $currentVersion]);
            $appliedList[] = $name;
        }

        return [
            'success'       => true,
            'applied_count' => count($appliedList),
            'applied'       => $appliedList,
            'message'       => count($appliedList) . ' migration(s) applied successfully.'
        ];
    }
}

// Global class alias for backward compatibility
if (!class_exists('Migrator', false)) {
    class_alias(Migrator::class, 'Migrator');
}

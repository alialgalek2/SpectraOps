<?php
/**
 * Core Database Service (Umbrella Architecture)
 * Centralizes PDO connection management and bridges with App\Config\Database.
 */

require_once dirname(__DIR__) . '/config/Database.php';

class Database {
    /**
     * @var PDO|null
     */
    private static ?PDO $instance = null;

    /**
     * Get global singleton PDO connection
     *
     * @return PDO
     */
    public static function getConnection(): PDO {
        return \App\Config\Database::getConnection();
    }

    /**
     * Compatibility wrapper for legacy getInstance() calls
     *
     * @return Database
     */
    public static function getInstance(): self {
        static $legacyInstance = null;
        if ($legacyInstance === null) {
            $legacyInstance = new self();
        }
        return $legacyInstance;
    }

    /**
     * Compatibility accessor for legacy $db->pdo or getConnection()
     */
    public function __get($name) {
        if ($name === 'pdo') {
            return self::getConnection();
        }
        return null;
    }
}

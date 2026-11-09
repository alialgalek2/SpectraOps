<?php
namespace App\Config;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            self::loadEnvironment();

            $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? constant('DB_HOST') : '127.0.0.1');
            $db   = getenv('DB_NAME') ?: (defined('DB_NAME') ? constant('DB_NAME') : 'school_db');
            $user = getenv('DB_USER') ?: (defined('DB_USER') ? constant('DB_USER') : null);
            $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : (defined('DB_PASS') ? constant('DB_PASS') : null);
            $charset = 'utf8mb4';

            if (empty($user)) {
                error_log("Database Configuration Error: DB_USER is not defined in environment.");
                http_response_code(500);
                die(json_encode(['status' => 500, 'error' => 'Database configuration error: Missing DB_USER']));
            }

            $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // Enforces native DB prepared statements
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, $user, (string)$pass, $options);
            } catch (PDOException $e) {
                // Log detailed error internally; display generic error to client
                error_log("Database Connection Error: " . $e->getMessage());
                http_response_code(500);
                die(json_encode(['status' => 500, 'error' => 'Database service unavailable']));
            }
        }
        return self::$instance;
    }

    private static function loadEnvironment(): void {
        $envPaths = [
            dirname(__DIR__) . '/.env',
            dirname(__DIR__) . '/.env.production'
        ];

        foreach ($envPaths as $path) {
            if (file_exists($path)) {
                $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    if (str_contains($line, '=')) {
                        [$key, $val] = explode('=', $line, 2);
                        $key = trim($key);
                        $val = trim($val, " \t\n\r\0\x0B\"'");
                        if (getenv($key) === false) {
                            putenv("{$key}={$val}");
                            $_ENV[$key] = $val;
                            $_SERVER[$key] = $val;
                        }
                    }
                }
                break;
            }
        }
    }
}

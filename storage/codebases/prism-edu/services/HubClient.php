<?php
namespace App\Services;

use App\Services\TelemetryReporter;

class HubClient {
    private static ?TelemetryReporter $instance = null;

    public static function get(): ?TelemetryReporter {
        if (self::$instance === null) {
            self::loadEnvironment();

            $enabled = filter_var(getenv('SPECTRAOPS_ENABLED') ?: (getenv('TELEMETRY_ENABLED') ?: true), FILTER_VALIDATE_BOOLEAN);
            $hubUrl  = getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ?: null);
            $apiKey  = getenv('SPECTRAOPS_API_KEY') ?: (getenv('TELEMETRY_INGEST_TOKEN') ?: (getenv('FLEET_API_KEY') ?: null));
            $version = getenv('APP_VERSION') ?: '2.5.0';

            if ($enabled && !empty($hubUrl) && !empty($apiKey)) {
                self::$instance = new TelemetryReporter($hubUrl, $apiKey, 'prism-edu', $version);
            }
        }
        return self::$instance;
    }

    public static function reset(): void {
        self::$instance = null;
    }

    private static function loadEnvironment(): void {
        $envPaths = [
            dirname(__DIR__, 2) . '/.env',
            dirname(__DIR__, 2) . '/.env.production',
            dirname(__DIR__) . '/.env',
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

<?php
namespace App\Telemetry;

use App\Services\TelemetryReporter;

class HubClient {
    private static ?TelemetryReporter $instance = null;

    /**
     * Load connection settings from environment variables or a dropped-in spectraops.json bundle.
     */
    public static function loadBundleConfig(?string $bundlePath = null): ?array {
        $candidates = [];
        if ($bundlePath !== null && $bundlePath !== '') {
            $candidates[] = $bundlePath;
        }
        $envPath = getenv('SPECTRAOPS_BUNDLE_PATH');
        if (is_string($envPath) && $envPath !== '') {
            $candidates[] = $envPath;
        }
        $cwd = getcwd();
        if ($cwd !== false) {
            $candidates[] = $cwd . '/spectraops.json';
        }
        $candidates[] = dirname(__DIR__) . '/spectraops.json';
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\');
            $candidates[] = $docRoot . '/spectraops.json';
            $candidates[] = dirname($docRoot) . '/spectraops.json';
        }

        foreach (array_unique($candidates) as $path) {
            if (is_file($path) && is_readable($path)) {
                $raw = file_get_contents($path);
                if ($raw !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded) && !empty($decoded['hub_url']) && !empty($decoded['deployment_key'])) {
                        return $decoded;
                    }
                }
            }
        }
        return null;
    }

    public static function get(?string $bundlePath = null): ?TelemetryReporter {
        if (self::$instance === null) {
            $enabled = filter_var(getenv('SPECTRAOPS_ENABLED') ?: (getenv('TELEMETRY_ENABLED') ?: true), FILTER_VALIDATE_BOOLEAN);
            $hubUrl  = getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ?: '');
            $token   = getenv('SPECTRAOPS_API_KEY') ?: (getenv('TELEMETRY_INGEST_TOKEN') ?: '');
            $version = getenv('APP_VERSION') ?: '1.0.0';

            if ($hubUrl === '' || $token === '') {
                $bundle = self::loadBundleConfig($bundlePath);
                if ($bundle !== null) {
                    $hubUrl = $hubUrl !== '' ? $hubUrl : (string)$bundle['hub_url'];
                    $token  = $token !== '' ? $token : (string)$bundle['deployment_key'];
                }
            }

            if ($enabled && !empty($hubUrl) && !empty($token)) {
                self::$instance = new TelemetryReporter($hubUrl, $token, $version);
            }
        }
        return self::$instance;
    }
}

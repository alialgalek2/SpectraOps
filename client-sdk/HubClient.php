<?php
namespace App\Telemetry;

use App\Services\TelemetryReporter;

class HubClient {
    private static ?TelemetryReporter $instance = null;

    public static function get(): ?TelemetryReporter {
        if (self::$instance === null) {
            $enabled = filter_var(getenv('SPECTRAOPS_ENABLED') ?: (getenv('TELEMETRY_ENABLED') ?: true), FILTER_VALIDATE_BOOLEAN);
            $hubUrl  = getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ?: '');
            $token   = getenv('SPECTRAOPS_API_KEY') ?: (getenv('TELEMETRY_INGEST_TOKEN') ?: '');
            $version = getenv('APP_VERSION') ?: '1.0.0';

            if ($enabled && !empty($hubUrl) && !empty($token)) {
                self::$instance = new TelemetryReporter($hubUrl, $token, $version);
            }
        }
        return self::$instance;
    }
}

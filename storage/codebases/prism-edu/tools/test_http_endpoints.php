<?php
declare(strict_types=1);

/**
 * Helper to test HTTP endpoint response status
 * @param string $url
 * @return int
 */
function test_endpoint(string $url): int {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode;
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'HEAD',
            'timeout'       => 5,
            'ignore_errors' => true
        ]
    ]);

    $headers = @get_headers($url, false, $context);
    if ($headers && isset($headers[0])) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/i', $headers[0], $matches)) {
            return (int)$matches[1];
        }
    }

    return 0;
}

<?php
declare(strict_types=1);

namespace App\Services;

use ZipArchive;
use RuntimeException;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

class GeminiHotfixAgent {
    private string $apiKey;
    private string $model;
    private string $apiEndpoint;
    private string $codebasesRoot;
    private string $updatesOutputDir;

    public function __construct(string $apiKey, string $codebasesRoot, string $updatesOutputDir, string $model = '') {
        $this->apiKey = $apiKey;
        $this->model = $model !== '' ? $model : (getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash');
        $this->apiEndpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . $this->apiKey;
        $this->codebasesRoot = rtrim($codebasesRoot, DIRECTORY_SEPARATOR);
        $this->updatesOutputDir = rtrim($updatesOutputDir, DIRECTORY_SEPARATOR);

        if (!is_dir($this->updatesOutputDir)) {
            mkdir($this->updatesOutputDir, 0755, true);
        }
    }

    public function generateFix(string $productSlug, string $errorMessage, string $filePath, ?string $stackTrace, string $currentVersion): array {
        $relativeFilePath = ltrim($filePath, '/\\');
        $productDir = $this->codebasesRoot . DIRECTORY_SEPARATOR . $productSlug;
        $absoluteFilePath = $productDir . DIRECTORY_SEPARATOR . $relativeFilePath;

        $sourceCode = "";
        if (file_exists($absoluteFilePath) && is_readable($absoluteFilePath)) {
            $sourceCode = file_get_contents($absoluteFilePath);
        } else {
            // First attempt: glob search for basename
            $baseName = basename($relativeFilePath);
            $foundFiles = glob($productDir . DIRECTORY_SEPARATOR . "**" . DIRECTORY_SEPARATOR . $baseName);
            if (!empty($foundFiles)) {
                $absoluteFilePath = $foundFiles[0];
                $relativeFilePath = substr($absoluteFilePath, strlen($productDir . DIRECTORY_SEPARATOR));
                $sourceCode = file_get_contents($absoluteFilePath);
            }

            // Fallback: Recursive directory search (needed when PHP glob doesn't support recursive ** on Windows)
            if (empty($sourceCode) && is_dir($productDir)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($productDir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $item) {
                    if ($item->isFile() && $item->getFilename() === $baseName) {
                        $absoluteFilePath = $item->getPathname();
                        $relativeFilePath = ltrim(substr($absoluteFilePath, strlen($productDir)), '/\\');
                        $sourceCode = file_get_contents($absoluteFilePath);
                        break;
                    }
                }
            }
        }

        $isSynthesizedFromScratch = false;
        if (empty($sourceCode)) {
            $isSynthesizedFromScratch = true;
            $sourceCode = "// [File '{$relativeFilePath}' was not found in baseline codebase - Synthesized by Gemini]\n";
            $prompt = "You are an expert PHP and systems engineer. A production runtime incident occurred in an application.\n\n"
                    . "PRODUCT: {$productSlug}\n"
                    . "ERROR: {$errorMessage}\n"
                    . "STACK TRACE:\n{$stackTrace}\n"
                    . "TARGET FILE PATH: {$relativeFilePath}\n\n"
                    . "CONTEXT: This source file ({$relativeFilePath}) was not found in the baseline repository. You must synthesize a complete, robust, production-ready PHP implementation from scratch to resolve this error and fulfill the application requirements.\n\n"
                    . "REQUIREMENTS:\n"
                    . "1. Identify the root cause and why this error occurred.\n"
                    . "2. Synthesize a complete, robust, production-ready PHP implementation for {$relativeFilePath} that resolves the incident and provides safe fallbacks/handling.\n"
                    . "3. Provide the COMPLETE PHP code for the entire file ({$relativeFilePath}) without placeholders.\n"
                    . "4. Ensure backward compatibility and zero syntax errors.";
        } else {
            $prompt = "You are an expert PHP and systems engineer. Fix the following runtime issue in a production system.\n\n"
                    . "PRODUCT: {$productSlug}\n"
                    . "ERROR: {$errorMessage}\n"
                    . "STACK TRACE:\n{$stackTrace}\n"
                    . "FILE PATH: {$relativeFilePath}\n\n"
                    . "SOURCE CODE:\n```php\n{$sourceCode}\n```\n\n"
                    . "REQUIREMENTS:\n"
                    . "1. Identify the root cause.\n"
                    . "2. Provide a concise explanation of what broke and how to fix it.\n"
                    . "3. Provide the COMPLETE, corrected PHP code for the entire file without placeholders.\n"
                    . "4. Ensure backward compatibility and zero breaking changes.";
        }

        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'root_cause' => ['type' => 'STRING'],
                        'fix_summary' => ['type' => 'STRING'],
                        'suggested_version' => ['type' => 'STRING'],
                        'fixed_file_path' => ['type' => 'STRING'],
                        'corrected_code' => ['type' => 'STRING']
                    ],
                    'required' => ['root_cause', 'fix_summary', 'suggested_version', 'fixed_file_path', 'corrected_code']
                ],
                'temperature' => 0.2
            ]
        ];

        // Optimize latency and avoid 503 queues on Gemini 3.x models
        if (str_contains($this->model, 'gemini-3.')) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'LOW'];
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30
        ];

        // Resolve CA bundle for Windows / XAMPP environments
        $caBundle = ini_get('curl.cainfo');
        if (!$caBundle || !file_exists($caBundle)) {
            $knownBundles = [
                'C:/xampp/apache/bin/curl-ca-bundle.crt',
                'C:/xampp/php/extras/ssl/cacert.pem',
                'C:/xampp/php/cacert.pem'
            ];
            $bundleFound = false;
            foreach ($knownBundles as $candidate) {
                if (file_exists($candidate)) {
                    $curlOptions[CURLOPT_CAINFO] = $candidate;
                    $bundleFound = true;
                    break;
                }
            }
            if (!$bundleFound && (getenv('APP_ENV') === 'local' || DIRECTORY_SEPARATOR === '\\')) {
                $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            }
        }

        $maxRetries = 2;
        $attempt = 0;
        $response = null;
        $httpCode = 0;
        $curlErr = '';
        $modelUsed = $this->model;

        while ($attempt < $maxRetries) {
            $attempt++;
            $ch = curl_init($this->apiEndpoint);
            curl_setopt_array($ch, $curlOptions);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                break;
            }

            // If 503 (high demand) or 429 (rate limit), back off briefly and retry once
            if (($httpCode === 503 || $httpCode === 429) && $attempt < $maxRetries) {
                sleep(1);
                continue;
            }

            break;
        }

        if ($httpCode !== 200 || !$response) {
            // High-demand resilience: if primary model is unavailable (503 / timeout / error),
            // seamlessly failover through stable fast companions
            $fallbackChain = ['gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite'];
            
            foreach ($fallbackChain as $companion) {
                if ($companion === $this->model) {
                    continue;
                }

                $fallbackEndpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$companion}:generateContent?key=" . $this->apiKey;
                $chFallback = curl_init($fallbackEndpoint);
                curl_setopt_array($chFallback, $curlOptions);
                $fallbackRes = curl_exec($chFallback);
                $fallbackCode = curl_getinfo($chFallback, CURLINFO_HTTP_CODE);
                $fallbackErr = curl_error($chFallback);
                curl_close($chFallback);

                if ($fallbackCode === 200 && $fallbackRes) {
                    $response = $fallbackRes;
                    $httpCode = 200;
                    $modelUsed = $companion;
                    break;
                }
            }

            if ($httpCode !== 200 || !$response) {
                throw new RuntimeException("Gemini API call failed (HTTP {$httpCode}): " . ($curlErr !== '' ? "cURL Error: {$curlErr}" : (string)$response));
            }
        }

        $resJson = json_decode((string)$response, true);
        $rawContent = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $parsedFix = json_decode((string)$rawContent, true);

        if (!is_array($parsedFix) || empty($parsedFix['corrected_code'])) {
            throw new RuntimeException("Gemini returned invalid or unparseable structured response.");
        }

        $correctedCode = $parsedFix['corrected_code'];
        $cleanRelPath = str_replace('\\', '/', ltrim($parsedFix['fixed_file_path'] ?: $relativeFilePath, '/\\'));

        // Calculate next patch version (e.g. 2.5.0 -> 2.5.1)
        $semverParts = explode('.', $currentVersion);
        if (count($semverParts) === 3) {
            $semverParts[2] = (string)((int)$semverParts[2] + 1);
            $newVersion = implode('.', $semverParts);
        } else {
            $newVersion = $parsedFix['suggested_version'] ?: ($currentVersion . '-patch');
        }

        $zipFilename = "ai_patch_{$productSlug}_v{$newVersion}_" . time() . ".zip";
        $zipPath = $this->updatesOutputDir . DIRECTORY_SEPARATOR . $zipFilename;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Failed to initialize zip archive at: {$zipPath}");
        }
        $zip->addFromString($cleanRelPath, $correctedCode);
        $zip->close();

        return [
            'root_cause'       => $parsedFix['root_cause'] ?? '',
            'fix_summary'      => $parsedFix['fix_summary'] ?? '',
            'explanation'      => $parsedFix['fix_summary'] ?? ($parsedFix['root_cause'] ?? ''),
            'new_version'      => $newVersion,
            'fixed_file_path'  => $cleanRelPath,
            'target_file'      => $cleanRelPath,
            'original_code'    => $sourceCode,
            'corrected_code'   => $correctedCode,
            'patched_content'  => $correctedCode,
            'semver_bump'      => 'patch',
            'package_filename' => $zipFilename,
            'package_checksum' => hash_file('sha256', $zipPath),
            'model_used'       => $modelUsed
        ];
    }
}

// Namespace alias for cross-namespace compatibility
if (!class_exists(\FleetHub\Services\GeminiHotfixAgent::class, false)) {
    class_alias(GeminiHotfixAgent::class, \FleetHub\Services\GeminiHotfixAgent::class);
}

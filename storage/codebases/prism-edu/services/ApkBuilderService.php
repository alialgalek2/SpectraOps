<?php
namespace App\Services;

use ZipArchive;

/**
 * On-Server APK Dynamic Packaging & Signing Service
 * Injects dynamic tenant configuration into the pre-compiled template APK,
 * aligns boundaries, signs with the institutional keystore, and publishes to downloads.
 */
class ApkBuilderService {
    private string $templateApkPath;
    private string $outputApkPath;
    private string $publicAltApkPath;
    private string $keystorePath;
    private string $keystorePass;
    private string $keyAlias;

    public function __construct(
        ?string $templateApkPath = null,
        ?string $outputApkPath = null,
        ?string $keystorePath = null,
        ?string $keystorePass = null,
        ?string $keyAlias = null
    ) {
        $baseDir = dirname(__DIR__);
        $this->templateApkPath  = $templateApkPath  ?: $baseDir . '/storage/app_template/template-unsigned.apk';
        $this->outputApkPath    = $outputApkPath    ?: $baseDir . '/public/downloads/PrismEDU-App.apk';
        $this->publicAltApkPath = $baseDir . '/downloads/PrismEDU-App.apk';
        $this->keystorePath     = $keystorePath     ?: $baseDir . '/storage/keys/prism_release.keystore';
        $this->keystorePass     = $keystorePass     ?: (string) getenv('APK_KEYSTORE_PASS');
        $this->keyAlias         = $keyAlias         ?: 'prismkey';
    }

    public function getOutputApkPath(): string {
        return $this->outputApkPath;
    }

    /**
     * Resolves the executable path for Android build tools (apksigner / zipalign / keytool).
     */
    public function resolveTool(string $toolName): ?string {
        // 1. Check if tool is in PATH directly
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $checkCmd = $isWindows ? "where $toolName 2>nul" : "which $toolName 2>/dev/null";
        $pathOut = @shell_exec($checkCmd);
        if (!empty($pathOut)) {
            $lines = explode("\n", trim($pathOut));
            if (!empty($lines[0]) && file_exists(trim($lines[0]))) {
                return trim($lines[0]);
            }
        }

        // 2. Scan standard Android SDK locations on Windows / Linux
        if ($isWindows) {
            $candidates = [];
            $localApp = getenv('LOCALAPPDATA');
            if ($localApp) {
                $candidates[] = "$localApp\\Android\\Sdk\\build-tools";
            }
            $androidHome = getenv('ANDROID_HOME') ?: getenv('ANDROID_SDK_ROOT');
            if ($androidHome) {
                $candidates[] = "$androidHome\\build-tools";
            }

            foreach ($candidates as $baseDir) {
                if (is_dir($baseDir)) {
                    $versions = scandir($baseDir, SCANDIR_SORT_DESCENDING);
                    foreach ($versions as $ver) {
                        if ($ver === '.' || $ver === '..') continue;
                        $ext = ($toolName === 'apksigner') ? '.bat' : '.exe';
                        $full = "$baseDir\\$ver\\$toolName$ext";
                        if (file_exists($full)) {
                            return $full;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Ensures that the signing keystore is present; generates a self-signed institutional keystore if missing.
     */
    public function ensureKeystoreExists(): bool {
        // Never create a production signing identity with a source-code fallback password.
        if ($this->keystorePass === '') {
            return false;
        }
        if (file_exists($this->keystorePath)) {
            return true;
        }

        $dir = dirname($this->keystorePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $keytool = $this->resolveTool('keytool');
        if (!$keytool) {
            return false;
        }

        $cmd = sprintf(
            '%s -genkeypair -v -keystore %s -alias %s -keyalg RSA -keysize 2048 -validity 10000 -storepass %s -keypass %s -dname "CN=Prism EDU, OU=Mobile Portal, O=Institutional System, L=Global, ST=State, C=US" 2>&1',
            escapeshellarg($keytool),
            escapeshellarg($this->keystorePath),
            escapeshellarg($this->keyAlias),
            escapeshellarg($this->keystorePass),
            escapeshellarg($this->keystorePass)
        );

        @exec($cmd, $out, $code);
        return ($code === 0 && file_exists($this->keystorePath));
    }

    /**
     * Injects custom tenant configuration and branding into the template APK, aligns, and signs it.
     */
    public function generateCustomApk(string $serverDomain, string $schoolName): array {
        if ($this->keystorePass === '') {
            return [
                'success' => false,
                'message' => 'APK signing is not configured. Set APK_KEYSTORE_PASS in the server environment.'
            ];
        }
        if (!file_exists($this->templateApkPath)) {
            return [
                'success' => false,
                'message' => 'Base template APK missing at: ' . $this->templateApkPath
            ];
        }

        $this->ensureKeystoreExists();

        $tempApk = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prism_build_' . uniqid() . '.apk';
        if (!copy($this->templateApkPath, $tempApk)) {
            return [
                'success' => false,
                'message' => 'Failed to initialize temporary APK workspace.'
            ];
        }

        // 1. Inject Dynamic JSON Configuration into assets/app_config.json
        $configData = json_encode([
            'server_url'   => $serverDomain,
            'school_name'  => $schoolName,
            'app_version'  => '2.5.0',
            'generated_at' => date('c')
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $zipModified = false;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tempApk) === true) {
                // Strip any previous META-INF signature files so apksigner can apply fresh v2/v3 signature
                for ($i = $zip->numFiles - 1; $i >= 0; $i--) {
                    $entry = $zip->getNameIndex($i);
                    if (preg_match('#^META-INF/.*\.(SF|RSA|DSA|EC)$#i', $entry)) {
                        $zip->deleteIndex($i);
                    }
                }
                $zip->addFromString('assets/app_config.json', $configData);
                $zip->close();
                $zipModified = true;
            }
        } else {
            // CLI Fallback when web server process does not have php-zip loaded
            $tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prism_zip_' . uniqid() . '.php';
            $tempCfg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prism_cfg_' . uniqid() . '.json';
            file_put_contents($tempCfg, $configData);
            $scriptCode = '<?php $z = new ZipArchive(); if ($z->open($argv[1]) === true) { for ($i = $z->numFiles - 1; $i >= 0; $i--) { $e = $z->getNameIndex($i); if (preg_match(\'#^META-INF/.*\\.(SF|RSA|DSA|EC)$#i\', $e)) { $z->deleteIndex($i); } } $z->addFromString(\'assets/app_config.json\', file_get_contents($argv[2])); $z->close(); echo "OK"; }';
            file_put_contents($tempScript, $scriptCode);
            $cliCmd = sprintf('php %s %s %s 2>&1', escapeshellarg($tempScript), escapeshellarg($tempApk), escapeshellarg($tempCfg));
            $cliOut = @shell_exec($cliCmd);
            @unlink($tempScript);
            @unlink($tempCfg);
            if (trim((string)$cliOut) === 'OK') {
                $zipModified = true;
            }
        }

        if (!$zipModified) {
            @unlink($tempApk);
            return [
                'success' => false,
                'message' => 'Failed to modify APK archive assets.'
            ];
        }

        // 2. Align the APK using zipalign (4-byte boundary)
        $zipalign = $this->resolveTool('zipalign');
        $alignedApk = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aligned_' . uniqid() . '.apk';
        $targetToSign = $tempApk;

        if ($zipalign) {
            $alignCmd = sprintf(
                '%s -p -f -v 4 %s %s 2>&1',
                escapeshellarg($zipalign),
                escapeshellarg($tempApk),
                escapeshellarg($alignedApk)
            );
            @exec($alignCmd, $alignOut, $alignCode);
            if ($alignCode === 0 && file_exists($alignedApk)) {
                $targetToSign = $alignedApk;
            }
        }

        // Ensure target download directories exist
        if (!is_dir(dirname($this->outputApkPath))) {
            mkdir(dirname($this->outputApkPath), 0755, true);
        }
        if (!is_dir(dirname($this->publicAltApkPath))) {
            mkdir(dirname($this->publicAltApkPath), 0755, true);
        }

        // 3. Sign the APK using apksigner
        $apksigner = $this->resolveTool('apksigner');
        $signedSuccessfully = false;
        $signErrors = [];

        if ($apksigner && file_exists($this->keystorePath)) {
            $signCommand = sprintf(
                '%s sign --ks %s --ks-pass pass:%s --ks-key-alias %s --out %s %s 2>&1',
                escapeshellarg($apksigner),
                escapeshellarg($this->keystorePath),
                escapeshellarg($this->keystorePass),
                escapeshellarg($this->keyAlias),
                escapeshellarg($this->outputApkPath),
                escapeshellarg($targetToSign)
            );
            @exec($signCommand, $signOutput, $signCode);
            if ($signCode === 0 && file_exists($this->outputApkPath)) {
                $signedSuccessfully = true;
            } else {
                $signErrors = $signOutput;
            }
        } else {
            $signErrors[] = 'Android signing tools or the configured signing key are unavailable.';
        }

        // Mirror to secondary downloads location so both /downloads/ and /public/downloads/ work directly
        if ($signedSuccessfully && file_exists($this->outputApkPath)) {
            @copy($this->outputApkPath, $this->publicAltApkPath);
        }

        // Cleanup temporary artifacts
        @unlink($tempApk);
        @unlink($alignedApk);

        $baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
        $downloadUrl = $baseUrl . '/downloads/PrismEDU-App.apk';

        if ($signedSuccessfully && file_exists($this->outputApkPath)) {
            return [
                'success'      => true,
                'download_url' => $downloadUrl,
                'file_size'    => filesize($this->outputApkPath),
                'school_name'  => $schoolName,
                'server_url'   => $serverDomain,
                'generated_at' => date('c')
            ];
        }

        return [
            'success' => false,
            'message' => 'APK generation failed: ' . implode("\n", $signErrors)
        ];
    }

    /**
     * Discovers the primary local LAN IPv4 address of this host PC.
     * Essential for local testing so mobile devices on Wi-Fi connect via the PC's LAN IP
     * instead of failing against 'localhost' or '127.0.0.1'.
     */
    public static function getLocalLanIp(): ?string {
        // 1. Explicit override via environment or config
        $envIp = getenv('SERVER_LAN_IP') ?: getenv('HOST_IP');
        if (!empty($envIp) && filter_var($envIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $envIp;
        }

        // 2. High-speed socket route introspection (connectionless UDP query to determine routing interface)
        $sock = @stream_socket_client("udp://8.8.8.8:53", $errno, $errstr, 1);
        if ($sock) {
            $name = stream_socket_get_name($sock, false);
            fclose($sock);
            if ($name) {
                $ip = parse_url('udp://' . $name, PHP_URL_HOST);
                if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')) {
                    return $ip;
                }
            }
        }

        // 3. Fallback: query active Windows routing table for default gateway interface
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $routeOut = @shell_exec('route print 0.0.0.0 2>nul');
            if ($routeOut && preg_match('/0\.0\.0\.0\s+0\.0\.0\.0\s+[0-9\.]+\s+([0-9\.]+)/', $routeOut, $m)) {
                if (!empty($m[1]) && !str_starts_with($m[1], '127.') && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $m[1];
                }
            }
        }

        // 4. Fallback: Hostname resolution
        $hostIp = gethostbyname(gethostname());
        if ($hostIp && !str_starts_with($hostIp, '127.') && filter_var($hostIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $hostIp;
        }

        return null;
    }

    /**
     * Resolves the canonical base URL of the currently running system instance.
     * Takes into account protocol (https/http), host header, port, and BASE_URL path.
     * When accessed via localhost/127.0.0.1, automatically substitutes the PC's LAN IP
     * so mobile devices on the network can access the system properly.
     */
    public static function detectSystemUrl(): string {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $scheme = $isHttps ? 'https' : 'http';

        $rawHost = $_SERVER['HTTP_HOST'] ?? '';
        if (empty($rawHost)) {
            $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $port = isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 80;
            $rawHost = ($port === 80 || $port === 443) ? $serverName : "$serverName:$port";
        }

        // Deconstruct hostname and port
        $colonPos = strpos($rawHost, ':');
        if ($colonPos !== false) {
            $hostname = substr($rawHost, 0, $colonPos);
            $port = substr($rawHost, $colonPos + 1);
        } else {
            $hostname = $rawHost;
            $port = isset($_SERVER['SERVER_PORT']) ? (string)$_SERVER['SERVER_PORT'] : ($isHttps ? '443' : '80');
        }

        // If the request was made to localhost or loopback (127.0.0.1, ::1),
        // replace the loopback address with the PC's actual LAN IP address so mobile devices can access the server!
        $lowerHost = strtolower($hostname);
        if (in_array($lowerHost, ['localhost', '127.0.0.1', '::1', ''])) {
            $lanIp = self::getLocalLanIp();
            if (!empty($lanIp)) {
                $hostname = $lanIp;
            }
        }

        $formattedPort = ($port === '80' || $port === '443' || empty($port)) ? '' : ":$port";
        $finalHost = $hostname . $formattedPort;

        $basePath = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '/school_system';
        return "$scheme://$finalHost$basePath";
    }

    /**
     * Reads and parses assets/app_config.json from an APK file.
     */
    public function getApkConfig(?string $apkPath = null): ?array {
        $path = $apkPath ?: $this->outputApkPath;
        if (!file_exists($path)) {
            if ($path === $this->outputApkPath && file_exists($this->publicAltApkPath)) {
                $path = $this->publicAltApkPath;
            } else {
                return null;
            }
        }

        $content = null;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $content = $zip->getFromName('assets/app_config.json');
                $zip->close();
            }
        } else {
            // CLI Fallback when web server process does not have php-zip loaded
            $cmd = sprintf('php -r "$z = new ZipArchive(); if ($z->open($argv[1]) === true) { echo $z->getFromName(\'assets/app_config.json\'); $z->close(); }" %s 2>nul', escapeshellarg($path));
            $content = @shell_exec($cmd);
        }

        if (empty($content)) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Ensures that the output APK exists and is configured for the given server domain and school name.
     * If already matched, returns instantly without rebuilding.
     * If missing or mismatching, rebuilds, aligns, and signs automatically.
     */
    public function ensureApkMatches(string $serverDomain, string $schoolName): array {
        $serverDomain = rtrim($serverDomain, '/');
        $currentConfig = $this->getApkConfig($this->outputApkPath);

        if ($currentConfig !== null && file_exists($this->outputApkPath)) {
            $existingUrl = rtrim($currentConfig['server_url'] ?? '', '/');
            $existingSchool = trim($currentConfig['school_name'] ?? '');

            if ($existingUrl === $serverDomain && (empty($schoolName) || $existingSchool === $schoolName)) {
                $baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
                return [
                    'success'         => true,
                    'already_matched' => true,
                    'download_url'    => $baseUrl . '/downloads/PrismEDU-App.apk',
                    'file_size'       => filesize($this->outputApkPath),
                    'school_name'     => $existingSchool,
                    'server_url'      => $existingUrl,
                    'generated_at'    => $currentConfig['generated_at'] ?? date('c')
                ];
            }
        }

        // Configuration missing or does not match: re-generate and sign
        return $this->generateCustomApk($serverDomain, $schoolName);
    }
}

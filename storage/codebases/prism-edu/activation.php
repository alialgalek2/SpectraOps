<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$licenseLockFile = __DIR__ . '/storage/license.lock';
if (!is_dir(dirname($licenseLockFile))) {
    @mkdir(dirname($licenseLockFile), 0755, true);
}

// If already activated, proceed directly to login
if (file_exists($licenseLockFile)) {
    $existingLock = json_decode((string)file_get_contents($licenseLockFile), true);
    if (!empty($existingLock['license_key']) && !empty($existingLock['lease_token'])) {
        $base = (isset($_SERVER['SCRIPT_NAME']) && str_contains($_SERVER['SCRIPT_NAME'], '/school_system')) ? '/school_system' : '';
        header('Location: ' . ($base ?: '') . '/login.php');
        exit;
    }
}

// Load environment variables if not yet populated
if (getenv('SPECTRAOPS_HUB_URL') === false) {
    $envPaths = [__DIR__ . '/.env', __DIR__ . '/.env.production'];
    foreach ($envPaths as $p) {
        if (file_exists($p)) {
            $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (str_contains($line, '=')) {
                    [$k, $v] = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v, " \t\n\r\0\x0B\"'");
                    if (getenv($k) === false) {
                        putenv("{$k}={$v}");
                        $_ENV[$k] = $v;
                        $_SERVER[$k] = $v;
                    }
                }
            }
            break;
        }
    }
}

$hubUrl = getenv('SPECTRAOPS_HUB_URL') ?: 'https://spectraops.yourcentraldomain.com';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['license_key'])) {
    $licenseKey = strtoupper(trim($_POST['license_key']));
    $hostDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hwSig      = hash('sha256', php_uname() . ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1'));

    $targetUrl = rtrim($hubUrl, '/') . '/api/v1/license/activate.php';
    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'license_key' => $licenseKey,
            'domain'      => $hostDomain,
            'hw_sig'      => $hwSig,
            'version'     => getenv('APP_VERSION') ?: '2.5.0'
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $data = json_decode((string)$res, true);

    if ($httpCode === 200 && !empty($data['success'])) {
        // Save local lock
        file_put_contents($licenseLockFile, json_encode([
            'license_key'  => $licenseKey,
            'client_name'  => $data['client_name'] ?? 'Authorized Client',
            'product'      => $data['product'] ?? 'Prism EDU',
            'lease_token'  => $data['lease_token'] ?? '',
            'activated_at' => date('c')
        ], JSON_PRETTY_PRINT));

        // Inject the License Key as the API Key in client .env
        $envPath = __DIR__ . '/.env';
        if (file_exists($envPath)) {
            $env = file_get_contents($envPath);
            if (preg_match('/^SPECTRAOPS_API_KEY=.*/m', $env)) {
                $env = preg_replace('/^SPECTRAOPS_API_KEY=.*/m', 'SPECTRAOPS_API_KEY="' . $licenseKey . '"', $env);
            } else {
                $env .= "\nSPECTRAOPS_API_KEY=\"{$licenseKey}\"\n";
            }
            file_put_contents($envPath, $env);
        }

        $base = '';
        if (isset($_SERVER['SCRIPT_NAME']) && str_contains($_SERVER['SCRIPT_NAME'], '/school_system')) {
            $base = '/school_system';
        }
        $loginUrl = ($base ? rtrim($base, '/') : '') . '/login.php';

        header('Location: ' . $loginUrl);
        exit;
    } else {
        $error = $data['error'] ?? ($data['message'] ?? 'Connection to SpectraOps Licensing Authority failed.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Activate Installation - Prism EDU</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #091830; color: #eefdff; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .box { background: #133f83; padding: 36px; border-radius: 14px; width: 440px; border: 1px solid rgba(13,192,231,0.3); box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        input[type="text"] { width: 100%; padding: 12px; margin: 14px 0 20px; border-radius: 8px; border: 1px solid #2c88c1; background: #091830; color: #bef6fd; font-family: monospace; font-size: 1.1rem; box-sizing: border-box; text-align: center; text-transform: uppercase; }
        button { width: 100%; background: #0dc0e7; color: #091830; font-weight: 800; border: none; padding: 12px; border-radius: 8px; cursor: pointer; font-size: 0.95rem; }
        button:hover { background: #1cd4fc; }
        .err { background: rgba(239,68,68,0.2); border: 1px solid #ef4444; color: #fca5a5; padding: 10px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 12px; }
        code { background: rgba(0,0,0,0.25); padding: 2px 6px; border-radius: 4px; color: #bef6fd; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="margin-top:0; color:#bef6fd;">Software Activation</h2>
        <p style="font-size:0.88rem; color:#92c6de;">Binding to instance domain: <code><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') ?></code></p>
        <?php if (!empty($error)): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST">
            <input type="text" name="license_key" placeholder="SPEC-XXXX-XXXX-XXXX" required autofocus>
            <button type="submit">Verify &amp; Activate System</button>
        </form>
    </div>
</body>
</html>

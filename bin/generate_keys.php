<?php
declare(strict_types=1);

$cnfCandidates = [
    'C:/xampp/php/extras/ssl/openssl.cnf',
    'C:/xampp/apache/conf/openssl.cnf',
    getenv('OPENSSL_CONF') ?: ''
];

$cnf = null;
foreach ($cnfCandidates as $c) {
    if ($c && file_exists($c)) {
        $cnf = $c;
        break;
    }
}

$config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
if ($cnf) {
    $config['config'] = $cnf;
    putenv("OPENSSL_CONF={$cnf}");
}

$res = openssl_pkey_new($config);
if (!$res) {
    echo "ERROR: " . openssl_error_string() . "\n";
    exit(1);
}

openssl_pkey_export($res, $privateKey, null, $config);
$publicKey = openssl_pkey_get_details($res)['key'];

$keysDir = __DIR__ . '/../keys';
if (!is_dir($keysDir)) {
    mkdir($keysDir, 0700, true);
}

file_put_contents($keysDir . '/release_private.pem', $privateKey);
file_put_contents($keysDir . '/release_public.pem', $publicKey);

echo "SUCCESS: Keys generated in {$keysDir}\n";
echo "Public Key:\n" . $publicKey . "\n";

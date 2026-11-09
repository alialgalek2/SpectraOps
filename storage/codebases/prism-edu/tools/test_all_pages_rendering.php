<?php
/**
 * Isolated Subprocess Rendering & Error Audit Runner
 * Executes each page in an isolated CLI subprocess with mock session & error tracking.
 */

$scanDirs = [
    'Root'           => __DIR__ . '/..',
    'Administration' => __DIR__ . '/../modules/Administration',
    'Grading'        => __DIR__ . '/../modules/Grading',
    'LMS'            => __DIR__ . '/../modules/LMS',
    'Finance'        => __DIR__ . '/../modules/Finance',
    'HR'             => __DIR__ . '/../modules/HR'
];

$filesToTest = [];

foreach ($scanDirs as $section => $dir) {
    if (!is_dir($dir)) continue;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || is_dir($dir . '/' . $item)) continue;
        if (substr($item, -4) !== '.php') continue;
        if (in_array($item, ['init.php', 'install.php', 'setup.php', 'cli.php', 'seed.php', 'test.php', 'composer.php'])) continue;
        
        $relPath = ($section === 'Root') ? $item : "modules/$section/$item";
        $filesToTest[$relPath] = realpath($dir . '/' . $item);
    }
}

echo "======================================================================\n";
echo "AUDITING RENDERING ON " . count($filesToTest) . " PAGES IN ISOLATED PROCESSES\n";
echo "======================================================================\n\n";

$passCount = 0;
$warnCount = 0;
$errCount = 0;
$issues = [];

foreach ($filesToTest as $relPath => $filePath) {
    // Generate a runner snippet
    $escapedFile = addslashes($filePath);
    $script = "<?php
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        require_once '" . addslashes(__DIR__ . '/../core/init.php') . "';
        \$_SESSION['user_id'] = 1;
        \$_SESSION['username'] = 'admin';
        \$_SESSION['role'] = 'admin';
        \$_SESSION['full_name'] = 'System Administrator';
        \$_SESSION['is_android_app'] = false;
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        \$_SERVER['SCRIPT_NAME'] = '/school_system/" . addslashes($relPath) . "';
        \$_SERVER['SCRIPT_FILENAME'] = '{$escapedFile}';
        \$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
        \$_GET = [];
        \$_POST = [];
        \$GLOBALS['app_sidebar_rendered'] = false;

        ob_start();
        try {
            include '{$escapedFile}';
            \$out = ob_get_clean();
            echo '___RENDER_OK___' . strlen(\$out);
        } catch (\\Throwable \$e) {
            ob_end_clean();
            echo '___RENDER_EXCEPTION___' . \$e->getMessage() . ' in ' . \$e->getFile() . ':' . \$e->getLine();
            exit(2);
        }
    ";

    $descriptor = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $process = proc_open("php", $descriptor, $pipes, dirname(__DIR__));
    if (is_resource($process)) {
        fwrite($pipes[0], $script);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        // Check for fatal errors or exceptions
        if (strpos($stdout, '___RENDER_EXCEPTION___') !== false || $returnCode === 2) {
            echo "  [EXCEPTION] $relPath\n";
            $errText = trim(str_replace('___RENDER_EXCEPTION___', '', $stdout));
            echo "      $errText\n";
            $errCount++;
            $issues[$relPath] = ['type' => 'exception', 'detail' => $errText];
        } elseif (preg_match('/(Fatal error|Parse error):(.*)/i', $stderr . $stdout, $m)) {
            echo "  [FATAL ERROR] $relPath\n";
            $errText = trim($m[0]);
            echo "      $errText\n";
            $errCount++;
            $issues[$relPath] = ['type' => 'fatal', 'detail' => $errText];
        } elseif (preg_match_all('/(Warning|Notice|Deprecated):(.*)/i', $stderr . $stdout, $matches)) {
            echo "  [WARNING/NOTICE] $relPath (" . count($matches[0]) . " notices)\n";
            foreach ($matches[0] as $noticeLine) {
                echo "      " . trim($noticeLine) . "\n";
            }
            $warnCount++;
            $issues[$relPath] = ['type' => 'warning', 'detail' => $matches[0]];
        } else {
            echo "  [PASS] $relPath\n";
            $passCount++;
        }
    }
}

echo "\n======================================================================\n";
echo "AUDIT SUMMARY: $passCount Clean, $warnCount Warnings/Notices, $errCount Fatal/Exceptions\n";
echo "======================================================================\n";

if (!empty($issues)) {
    echo "\nPAGES REQUIRING ATTENTION (" . count($issues) . "):\n";
    foreach ($issues as $page => $data) {
        echo "  - $page: " . (is_array($data['detail']) ? implode('; ', $data['detail']) : $data['detail']) . "\n";
    }
}

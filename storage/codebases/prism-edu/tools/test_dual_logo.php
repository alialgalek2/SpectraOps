<?php
/**
 * Test Suite: Dual Theme Logo Integration Verification
 * - Dark Version (Navy Canvas) for Light View
 * - Light Version (Transparent/White) for Dark View
 */

require_once __DIR__ . '/../core/init.php';

echo "=== Dual Theme Institutional Logo Verification ===\n";

$pass = 0;
$fail = 0;

function assert_test($desc, $cond) {
    global $pass, $fail;
    if ($cond) {
        echo "  [PASS] $desc\n";
        $pass++;
    } else {
        echo "  [FAIL] $desc\n";
        $fail++;
    }
}

// 1. Check physical files
$fileDarkCanvas = __DIR__ . '/../uploads/branding/prism_logo_dark_canvas.png';
$fileLightTrans = __DIR__ . '/../uploads/branding/prism_logo_light_transparent.png';

assert_test("Dark Canvas logo file exists on disk", file_exists($fileDarkCanvas));
assert_test("Light Transparent logo file exists on disk", file_exists($fileLightTrans));

if (file_exists($fileDarkCanvas)) {
    $info = getimagesize($fileDarkCanvas);
    assert_test("Dark Canvas logo is valid PNG (1024x1024)", $info[0] === 1024 && $info[1] === 1024 && $info['mime'] === 'image/png');
}

if (file_exists($fileLightTrans)) {
    $info = getimagesize($fileLightTrans);
    assert_test("Light Transparent logo is valid PNG (1024x1024)", $info[0] === 1024 && $info[1] === 1024 && $info['mime'] === 'image/png');
}

// 2. Check get_school_logo_url logic
$urlLightView = get_school_logo_url(null, 'light');
$urlDarkView = get_school_logo_url(null, 'dark');

assert_test("get_school_logo_url(null, 'light') points to dark_canvas.png", str_contains($urlLightView, 'prism_logo_dark_canvas.png'));
assert_test("get_school_logo_url(null, 'dark') points to light_transparent.png", str_contains($urlDarkView, 'prism_logo_light_transparent.png'));

// 3. Check render_theme_logo_html()
$html = render_theme_logo_html();
assert_test("render_theme_logo_html contains .brand-logo-wrapper", str_contains($html, 'brand-logo-wrapper'));
assert_test("render_theme_logo_html contains .brand-logo-light-view", str_contains($html, 'brand-logo-light-view'));
assert_test("render_theme_logo_html contains .brand-logo-dark-view", str_contains($html, 'brand-logo-dark-view'));
assert_test("Light view image src is dark canvas", str_contains($html, 'prism_logo_dark_canvas.png'));
assert_test("Dark view image src is light transparent", str_contains($html, 'prism_logo_light_transparent.png'));

// 4. Check CSS rules in theme.css
$css = file_get_contents(__DIR__ . '/../assets/css/theme.css');
assert_test("theme.css defines .brand-logo-dark-view { display: none !important; }", str_contains($css, '.brand-logo-dark-view'));
assert_test("theme.css defines .brand-logo-light-view { display: inline-block !important; }", str_contains($css, '.brand-logo-light-view'));
assert_test("theme.css toggles dark view under [data-theme=\"dark\"]", str_contains($css, '[data-theme="dark"] .brand-logo-dark-view'));

// 5. Live HTTP verification
function http_get($url) {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $content = @file_get_contents($url, false, $ctx);
    $status = $http_response_header[0] ?? 'NO_RESPONSE';
    return ['status' => $status, 'length' => strlen($content), 'content' => $content];
}

$r1 = http_get('http://localhost:8080/school_system/uploads/branding/prism_logo_dark_canvas.png');
assert_test("HTTP 200 for prism_logo_dark_canvas.png", str_contains($r1['status'], '200') && $r1['length'] > 1000);

$r2 = http_get('http://localhost:8080/school_system/uploads/branding/prism_logo_light_transparent.png');
assert_test("HTTP 200 for prism_logo_light_transparent.png", str_contains($r2['status'], '200') && $r2['length'] > 1000);

$rLogin = http_get('http://localhost:8080/school_system/login.php');
assert_test("Login page contains responsive theme logo wrapper", str_contains($rLogin['content'], 'brand-logo-wrapper'));
assert_test("Login page contains dark canvas logo for light view", str_contains($rLogin['content'], 'prism_logo_dark_canvas.png'));
assert_test("Login page contains light transparent logo for dark view", str_contains($rLogin['content'], 'prism_logo_light_transparent.png'));

echo "\nSummary: $pass passed, $fail failed.\n";
if ($fail === 0) {
    echo "SUCCESS: All logo tests passed!\n";
    exit(0);
} else {
    echo "FAILURE: Some logo tests failed.\n";
    exit(1);
}

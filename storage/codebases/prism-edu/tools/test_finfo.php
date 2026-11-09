<?php
echo "Extension loaded: " . (extension_loaded('fileinfo') ? 'YES' : 'NO') . "\n";
try {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    echo "Finfo created\n";
    $mime = $finfo->file(__FILE__);
    echo "Mime: $mime\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

<?php
namespace App\Controllers;

class UploadController {
    public static function handleLogoUpload(array $file): array {
        $allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml'];
        $maxSizeBytes = 2 * 1024 * 1024; // 2 MB

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Upload error occurred.'];
        }

        if (($file['size'] ?? 0) > $maxSizeBytes) {
            return ['success' => false, 'message' => 'File exceeds 2MB limit.'];
        }

        if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'Uploaded temporary file not found.'];
        }

        // Validate real MIME type safely across all environments
        $mimeType = null;
        if (class_exists('\finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($file['tmp_name']);
        } else {
            $imgInfo = @getimagesize($file['tmp_name']);
            if ($imgInfo && !empty($imgInfo['mime'])) {
                $mimeType = $imgInfo['mime'];
            } else {
                $head = @file_get_contents($file['tmp_name'], false, null, 0, 512);
                if ($head !== false && (str_contains($head, '<svg') || (str_contains($head, '<?xml') && str_contains($head, 'svg')))) {
                    $mimeType = 'image/svg+xml';
                }
            }
        }

        if (empty($mimeType) || !in_array($mimeType, $allowedMimes, true)) {
            return ['success' => false, 'message' => 'Invalid file format. Only PNG, JPEG, and SVG allowed.'];
        }

        // Sanitize extension and assign unguessable filename
        $ext = match($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/svg+xml' => 'svg',
            default => 'bin'
        };

        $safeName = 'prism_logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $uploadDir = dirname(__DIR__) . '/storage/uploads/branding/';
        $publicDir = dirname(__DIR__) . '/uploads/branding/';

        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        if (!is_dir($publicDir)) {
            @mkdir($publicDir, 0755, true);
        }

        $destination = $uploadDir . $safeName;
        $publicDestination = $publicDir . $safeName;

        // Support both real uploaded file and local test file moves
        $saved = false;
        if (is_uploaded_file($file['tmp_name'])) {
            $saved = move_uploaded_file($file['tmp_name'], $destination);
        } else {
            $saved = copy($file['tmp_name'], $destination);
        }

        if ($saved) {
            if ($mimeType === 'image/svg+xml') {
                self::sanitizeSvgFile($destination);
            }
            @copy($destination, $publicDestination);
            return [
                'success'  => true,
                'fileName' => $safeName,
                'path'     => '/storage/uploads/branding/' . $safeName,
                'url'      => '/school_system/uploads/branding/' . $safeName
            ];
        }

        return ['success' => false, 'message' => 'Failed to save file.'];
    }

    /**
     * Sanitizes SVG file by removing script tags, event handlers, and javascript: links
     */
    private static function sanitizeSvgFile(string $filePath): void {
        if (!file_exists($filePath)) {
            return;
        }
        $svg = file_get_contents($filePath);
        if ($svg === false) {
            return;
        }

        // 1. Remove <script>...</script> blocks and self-closing <script/>
        $svg = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $svg);
        $svg = preg_replace('/<script\b[^>]*\/>/is', '', $svg);

        // 2. Remove <foreignObject>...</foreignObject> blocks
        $svg = preg_replace('/<foreignObject\b[^>]*>(.*?)<\/foreignObject>/is', '', $svg);

        // 3. Remove inline event handlers (onload, onclick, onerror, etc.)
        $svg = preg_replace('/\s+on[a-zA-Z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $svg);

        // 4. Neutralize javascript: URIs in xlink:href or href
        $svg = preg_replace('/(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/is', '$1="#"', $svg);

        file_put_contents($filePath, $svg);
    }
}

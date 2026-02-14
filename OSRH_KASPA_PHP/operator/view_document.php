<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles.php';

require_login();

// Only operators can view documents
if (!user_has_role('operator') && !user_has_role('admin')) {
    redirect('error.php?code=403');
}

$fileParam = trim((string)array_get($_GET, 'file', ''));

if ($fileParam === '') {
    error_log("view_document.php: No filename provided");
    redirect('error.php?code=404');
}

// Check if it's a JSON array (multiple files) - if so, get the first file or specific index
$filename = $fileParam;
$fileIndex = (int)array_get($_GET, 'index', 0);

// Try to decode as JSON (for multiple file uploads)
$decoded = json_decode($fileParam, true);
if (is_array($decoded) && !empty($decoded)) {
    // It's a JSON array of filenames
    if (isset($decoded[$fileIndex])) {
        $filename = $decoded[$fileIndex];
    } else {
        $filename = $decoded[0]; // Default to first file
    }
}

// Security: prevent directory traversal
if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
    error_log("view_document.php: Invalid filename (directory traversal attempt): $filename");
    redirect('error.php?code=403');
}

// Try multiple possible upload directories
$possibleDirs = [
    __DIR__ . '/../uploads/driver/documents/',
    __DIR__ . '/../uploads/carshare/documents/',
    __DIR__ . '/../uploads/',
    sys_get_temp_dir() . '/osrh_uploads/',
    __DIR__ . '/../../uploads/',
    '/tmp/osrh_uploads/',
];

$filePath = null;
foreach ($possibleDirs as $dir) {
    $testPath = $dir . $filename;
    if (file_exists($testPath)) {
        $filePath = $testPath;
        break;
    }
}

if ($filePath === null) {
    error_log("view_document.php: File not found in any directory: $filename");
    error_log("view_document.php: Searched in: " . implode(', ', $possibleDirs));
    redirect('error.php?code=404');
}

// Determine content type
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$contentType = 'application/octet-stream';

switch ($ext) {
    case 'pdf':
        $contentType = 'application/pdf';
        break;
    case 'jpg':
    case 'jpeg':
        $contentType = 'image/jpeg';
        break;
    case 'png':
        $contentType = 'image/png';
        break;
}

// Send file
header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . basename($filename) . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;

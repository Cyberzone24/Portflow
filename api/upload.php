<?php
// Set JSON header first, before any includes
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Start output buffer to catch any errors
ob_start();

// Error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_end_clean();
    http_response_code(500);
    die(json_encode(['error' => 'Server error: ' . $errstr]));
});

// Suppress session output
// Include session after header is set
@include_once __DIR__ . '/../includes/core/session.php';

// Clean any accidental output
$output = ob_get_clean();
if (!empty(trim($output))) {
    // There was unexpected output from includes, log it but continue
    error_log('Unexpected output in upload.php: ' . substr($output, 0, 200));
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method Not Allowed']));
}

// Check if file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    die(json_encode(['error' => 'No file uploaded or upload error']));
}

$file = $_FILES['file'];
$reference_table = $_POST['reference_table'] ?? '';
$reference_uuid = $_POST['reference_uuid'] ?? '';
$description = $_POST['description'] ?? '';

// Validate inputs
if (!$reference_table || !$reference_uuid) {
    http_response_code(400);
    die(json_encode(['error' => 'Missing reference_table or reference_uuid']));
}

// Validate UUID format
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference_uuid)) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid UUID format']));
}

// Sanitize table name (alphanumeric and underscore only)
if (!preg_match('/^[a-z0-9_]+$/i', $reference_table)) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid table name']));
}

// Create attachment directory structure
$base_dir = __DIR__ . '/../data/attachments';
$ref_dir = $base_dir . '/' . $reference_table . '/' . $reference_uuid;

if (!is_dir($ref_dir)) {
    if (!mkdir($ref_dir, 0755, true)) {
        http_response_code(500);
        die(json_encode(['error' => 'Failed to create attachment directory']));
    }
}

// Validate file
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$file_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($file_type, $allowed_types)) {
    http_response_code(400);
    die(json_encode(['error' => 'File type not allowed: ' . $file_type]));
}

// Limit file size (50 MB)
$max_size = 50 * 1024 * 1024;
if ($file['size'] > $max_size) {
    http_response_code(400);
    die(json_encode(['error' => 'File size exceeds 50 MB limit']));
}

// Generate safe filename
$original_name = basename($file['name']);
$ext = pathinfo($original_name, PATHINFO_EXTENSION);
$safe_filename = bin2hex(random_bytes(16)) . '.' . $ext;
$target_path = $ref_dir . '/' . $safe_filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    http_response_code(500);
    die(json_encode(['error' => 'Failed to save uploaded file']));
}

// Generate file URL
$file_url = '/data/attachments/' . $reference_table . '/' . $reference_uuid . '/' . $safe_filename;

http_response_code(200);
echo json_encode([
    'success' => true,
    'file_url' => $file_url,
    'file_name' => $original_name,
    'description' => $description
]);
?>

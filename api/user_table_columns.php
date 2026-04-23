<?php
/**
 * POST /api/user_table_columns
 *
 * Persist the column visibility / order preference of the calling user
 * for a single table view. The selection is stored under
 *   $userSettings['tables'][<table>] = ['col_a','col_b',...]
 *
 * Body (JSON):
 *   { "table": "device_details",
 *     "columns": ["device_metadata_caption","device_type", ...] }
 *
 * If "columns" is null or an empty array the per-table preference is
 * cleared (the table falls back to the default).
 */

@include_once __DIR__ . '/../includes/core/session.php';
require_once __DIR__ . '/../includes/core/db_adapter.php';
require_once __DIR__ . '/../includes/core/table_columns.php';

header('Content-Type: application/json');

if (empty($_SESSION['uuid'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    return;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '[]', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    return;
}

$table   = isset($body['table'])   ? (string)$body['table']  : '';
$columns = $body['columns'] ?? null;

if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid table']);
    return;
}

if ($columns !== null && !is_array($columns)) {
    http_response_code(400);
    echo json_encode(['error' => '"columns" must be an array or null']);
    return;
}

// Resolve the table config (columns + blocklist) from the lang nav file.
// We reuse the same mechanism the front end uses (lang.php?nav).
// IMPORTANT: lang.php echoes the nav JSON when $_GET['nav'] is set, so we
// have to swallow that output — otherwise it would be concatenated in front
// of our own JSON response and break JSON.parse on the client.
$_GET['nav'] = '1';
ob_start();
include_once __DIR__ . '/../includes/lang.php';
ob_end_clean();
if (!isset($nav) || !is_array($nav) || !isset($nav[$table]) || !is_array($nav[$table])) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown table: ' . $table]);
    return;
}
$tableConfig = $nav[$table];
$columnsCfg  = (isset($tableConfig['columns']) && is_array($tableConfig['columns'])) ? $tableConfig['columns'] : [];

// Validate / sanitise the requested column list.
$normalized = null;
if (is_array($columns) && count($columns) > 0) {
    $unique = [];
    foreach ($columns as $c) {
        if (!is_string($c) || $c === '') continue;
        if (!isset($columnsCfg[$c])) continue; // unknown column
        $unique[$c] = true;
    }
    $picked = array_keys($unique);
    $picked = \Portflow\Core\TableColumns::filterStored($picked, $columnsCfg, $tableConfig);
    if (!empty($picked)) {
        $normalized = $picked;
    }
}

// Load current user settings (stored as JSON string in $_SESSION).
$rawSettings = $_SESSION['settings'] ?? '';
if (is_array($rawSettings)) {
    $settings = $rawSettings;
} elseif (is_string($rawSettings) && $rawSettings !== '') {
    $settings = json_decode($rawSettings, true);
    if (!is_array($settings)) $settings = [];
} else {
    $settings = [];
}
if (!isset($settings['tables']) || !is_array($settings['tables'])) {
    $settings['tables'] = [];
}

if ($normalized === null) {
    unset($settings['tables'][$table]);
} else {
    $settings['tables'][$table] = $normalized;
}

try {
    $db = new \Portflow\Core\DatabaseAdapter();
    $encoded = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        throw new \RuntimeException('Failed to encode settings');
    }
    $_SESSION['settings'] = $encoded;
    $db->db_query(
        "UPDATE users SET settings = :settings, changed = NOW() WHERE uuid = :uuid",
        ['settings' => $encoded, 'uuid' => (string)$_SESSION['uuid']]
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Persist failed: ' . $e->getMessage()]);
    return;
}

echo json_encode([
    'ok'      => true,
    'table'   => $table,
    'columns' => $normalized,
]);

<?php
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Portflow');
}

include_once __DIR__ . '/../includes/core/system_state.php';
portflow_enforce_maintenance_mode('json', ['include_state' => true]);

@include_once __DIR__ . '/../includes/core/session.php';
require_once __DIR__ . '/../includes/core/db_adapter.php';
require_once __DIR__ . '/../includes/core/portview_chains.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['uuid'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    return;
}

try {
    $db = new \Portflow\Core\DatabaseAdapter();
    $chains = \Portflow\Core\PortviewChains::fetch($db);
    echo json_encode([
        'pageInfo' => [
            'totalResults' => count($chains),
            'resultsPerPage' => count($chains),
            'currentPage' => 1,
            'totalPages' => 1,
        ],
        'items' => $chains,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'portview_chains failed', 'detail' => $e->getMessage()]);
}
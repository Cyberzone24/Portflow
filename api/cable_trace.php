<?php
/**
 * /api/cable_trace?from=<uuid>&kind=device_port|connection|cable|device&direction=both|forward|backward&max_hops=16
 *
 * Thin HTTP wrapper around \Portflow\Core\CableTrace. Authentication is
 * handled by the pre-session Basic-Auth handshake in api/index.php (which
 * delegates to Auth::apiSignin) and/or the regular web session, so any
 * authenticated user can request a trace for any visible object.
 */

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Portflow');
}
include_once __DIR__ . '/../includes/core/system_state.php';
portflow_enforce_maintenance_mode('json', ['include_state' => true]);
@include_once __DIR__ . '/../includes/core/session.php';
require_once __DIR__ . '/../includes/core/cable_trace.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['uuid'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    return;
}

$from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$kind = isset($_GET['kind']) ? trim((string)$_GET['kind']) : 'device_port';
$direction = isset($_GET['direction']) ? trim((string)$_GET['direction']) : 'both';
$maxHops = isset($_GET['max_hops']) ? (int)$_GET['max_hops'] : \Portflow\Core\CableTrace::DEFAULT_MAX_HOPS;
$preferExpected = !isset($_GET['prefer_expected'])
    || in_array(strtolower((string)$_GET['prefer_expected']), ['1','true','yes','on'], true);

if ($from === '') {
    http_response_code(400);
    echo json_encode(['error' => "missing 'from' parameter"]);
    return;
}

try {
    $tracer = new \Portflow\Core\CableTrace();
    $result = $tracer->trace($kind, $from, [
        'direction'       => $direction,
        'max_hops'        => $maxHops,
        'prefer_expected' => $preferExpected,
    ]);
    if (!empty($result['error'])) {
        http_response_code(400);
    }
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'trace failed', 'detail' => $e->getMessage()]);
}

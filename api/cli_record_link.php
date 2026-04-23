<?php
/**
 * Portflow CLI endpoint: POST /api/cli/record_link
 *
 * Records a freshly discovered Patchpanel <-> Switch link based on LLDP info
 * captured by the on-site CLI tool. Optionally also stores the "normally
 * connected" end device for the matching net outlet.
 *
 * Auth: HTTP Basic (validated against users table). All operations run as
 * the authenticated user and respect their existing API ACLs.
 *
 * Request body (application/json):
 * {
 *   "outlet_caption":  "1.OG-12-A",          // patchpanel/net-outlet port caption
 *   "lldp": {
 *     "sys_name":      "h9441as0h",          // optional
 *     "mgmt_address":  "10.0.4.21",          // optional
 *     "chassis_id":    "64:c3:94:41:bf:31",  // optional, MAC preferred
 *     "port_id":       "MultiGE1/0/12",      // optional
 *     "port_desc":     "1/1/12",             // optional, often the alias
 *     "captured_at":   "2026-04-23T10:11:12Z"
 *   },
 *   "expected_device": {                     // optional
 *     "caption":       "PC-OFFICE-12",
 *     "type":          "computer",
 *     "mac":           "aa:bb:cc:dd:ee:ff"
 *   },
 *   "comment":         "free-form note for the journal"
 * }
 *
 * Response:
 *   200 OK  -> {"ok": true, "summary": "...", "ids": {...}}
 *   401     -> auth failure
 *   404     -> outlet/switch/port could not be matched
 *   409     -> existing link conflicts with new data (and "force": true was not set)
 *   422     -> input invalid
 */

declare(strict_types=1);

namespace Portflow\Core;

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Portflow');
}
@include_once __DIR__ . '/../includes/core/session.php';
include_once __DIR__ . '/../includes/core/db_adapter.php';
include_once __DIR__ . '/../includes/core/logger.php';

$db     = new DatabaseAdapter();
$logger = new Logger();

// ---- 1. Authentication -----------------------------------------------------
// When this file is require()'d from api/index.php the pre-session Basic-Auth
// handshake there has already validated the credentials via Auth::apiSignin()
// and populated $_SESSION['uuid']. If we get here without a session (e.g.
// somebody hit /api/cli_record_link.php directly), parse Basic creds and run
// the same Auth::apiSignin() ourselves so we honour all configured providers
// (local, LDAP, …).
if (empty($_SESSION['uuid'])) {
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW']   ?? null;
    if ($user === null || $pass === null) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('apache_request_headers')
                ? (apache_request_headers()['Authorization'] ?? '')
                : '');
        if (is_string($hdr) && stripos($hdr, 'Basic ') === 0) {
            $decoded = base64_decode(substr($hdr, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
            }
        }
    }
    if (!is_string($user) || !is_string($pass) || $user === '' || $pass === '') {
        header('WWW-Authenticate: Basic realm="Portflow CLI"');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    include_once __DIR__ . '/../includes/core/auth.php';
    $auth = new \Portflow\Core\Auth();
    if (!$auth->apiSignin($user, $pass)) {
        header('WWW-Authenticate: Basic realm="Portflow CLI"');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}
$userUuid = (string)$_SESSION['uuid'];

// ---- 2. Parse + validate payload -------------------------------------------
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}
$outletCaption = trim((string)($body['outlet_caption'] ?? ''));
if ($outletCaption === '') {
    http_response_code(422);
    echo json_encode(['error' => 'outlet_caption required']);
    exit;
}
$lldp = is_array($body['lldp'] ?? null) ? $body['lldp'] : [];
$sysName  = trim((string)($lldp['sys_name']     ?? ''));
$mgmtAddr = trim((string)($lldp['mgmt_address'] ?? ''));
$chassis  = strtolower(trim((string)($lldp['chassis_id'] ?? '')));
$portId   = trim((string)($lldp['port_id']   ?? ''));
$portDesc = trim((string)($lldp['port_desc'] ?? ''));
$force    = !empty($body['force']);

if ($sysName === '' && $mgmtAddr === '' && $chassis === '') {
    http_response_code(422);
    echo json_encode(['error' => 'lldp.sys_name, lldp.mgmt_address or lldp.chassis_id required']);
    exit;
}
if ($portId === '' && $portDesc === '') {
    http_response_code(422);
    echo json_encode(['error' => 'lldp.port_id or lldp.port_desc required']);
    exit;
}

// ---- 3. Resolve patchpanel-side port via outlet caption --------------------
// Captions match between net_outlet and patchpanel ports. We look for a
// patchpanel-typed device_port with the same caption. If only the net_outlet
// match is found we walk the existing patchpanel<->net_outlet connection.
$ppRows = $db->db_query(
    "SELECT dp.uuid AS port_uuid, d.uuid AS device_uuid, d.type AS device_type, m.caption
       FROM device_port dp
       JOIN device d  ON d.uuid = dp.device
       JOIN metadata m ON m.uuid = dp.metadata
      WHERE LOWER(m.caption) = LOWER(:cap)",
    ['cap' => $outletCaption]
);
if (!is_array($ppRows) || empty($ppRows)) {
    http_response_code(404);
    echo json_encode(['error' => 'No port found for outlet_caption', 'outlet_caption' => $outletCaption]);
    exit;
}
// Prefer patchpanel directly; otherwise resolve net_outlet -> patchpanel via connection.
$patchPort = null;
$outletPort = null;
foreach ($ppRows as $r) {
    $type = strtolower((string)($r['device_type'] ?? ''));
    if ($type === 'patchpanel' && $patchPort === null) {
        $patchPort = $r;
    } elseif (($type === 'net_outlet' || $type === 'outlet') && $outletPort === null) {
        $outletPort = $r;
    }
}
if ($patchPort === null && $outletPort !== null) {
    $hop = $db->db_query(
        "SELECT c.uuid, c.device_port_source, c.device_port_destination,
                ds.type AS src_type, dd.type AS dst_type
           FROM connection c
           LEFT JOIN device_port dps ON dps.uuid = c.device_port_source
           LEFT JOIN device       ds ON ds.uuid = dps.device
           LEFT JOIN device_port dpd ON dpd.uuid = c.device_port_destination
           LEFT JOIN device       dd ON dd.uuid = dpd.device
          WHERE c.device_port_source = :p OR c.device_port_destination = :p",
        ['p' => $outletPort['port_uuid']]
    );
    if (is_array($hop)) {
        foreach ($hop as $h) {
            $candidates = [];
            if ((string)$h['src_type'] === 'patchpanel') {
                $candidates[] = ['port_uuid' => $h['device_port_source']];
            }
            if ((string)$h['dst_type'] === 'patchpanel') {
                $candidates[] = ['port_uuid' => $h['device_port_destination']];
            }
            if (!empty($candidates)) {
                $patchPort = $candidates[0];
                break;
            }
        }
    }
}
if ($patchPort === null) {
    http_response_code(404);
    echo json_encode([
        'error'          => 'No patchpanel port resolvable from outlet_caption',
        'outlet_caption' => $outletCaption,
        'matches'        => $ppRows,
    ]);
    exit;
}
$patchPortUuid = (string)$patchPort['port_uuid'];

// ---- 4. Resolve switch device ---------------------------------------------
$switch = null;
// Try by sys_name (caption).
if ($sysName !== '') {
    $r = $db->db_query(
        "SELECT d.uuid, m.caption
           FROM device d JOIN metadata m ON m.uuid = d.metadata
          WHERE d.type = 'switch' AND LOWER(m.caption) = LOWER(:n) LIMIT 1",
        ['n' => $sysName]
    );
    if (is_array($r) && !empty($r)) { $switch = $r[0]; }
}
// Try by mgmt IP.
if ($switch === null && $mgmtAddr !== '') {
    $r = $db->db_query(
        "SELECT d.uuid, m.caption
           FROM device d
           JOIN metadata m ON m.uuid = d.metadata
           JOIN device_port dp ON dp.device = d.uuid
           JOIN device_port_ip ipx ON ipx.uuid = dp.device_port_ip
          WHERE d.type = 'switch' AND host(ipx.ip) = :ip LIMIT 1",
        ['ip' => $mgmtAddr]
    );
    if (is_array($r) && !empty($r)) { $switch = $r[0]; }
}
// Try by chassis MAC.
if ($switch === null && $chassis !== '') {
    $macNorm = strtolower(preg_replace('/[^0-9a-f]/i', '', $chassis));
    if (strlen($macNorm) === 12) {
        $macFmt = implode(':', str_split($macNorm, 2));
        $r = $db->db_query(
            "SELECT d.uuid, m.caption
               FROM device d
               JOIN metadata m ON m.uuid = d.metadata
               JOIN device_port dp ON dp.device = d.uuid
              WHERE d.type = 'switch' AND LOWER(dp.mac_address) = :mac LIMIT 1",
            ['mac' => $macFmt]
        );
        if (is_array($r) && !empty($r)) { $switch = $r[0]; }
    }
}
if ($switch === null) {
    http_response_code(404);
    echo json_encode([
        'error' => 'Switch could not be resolved from LLDP info (matching by sys_name/mgmt_address/chassis_id failed)',
        'lldp'  => $lldp,
    ]);
    exit;
}
$switchUuid = (string)$switch['uuid'];

// Stack handling: switches in the same stack share device.item_group but
// have one row per unit (caption suffix "- U<N>"). LLDP returns the *stack*
// chassis info, while the port lives on the unit-N device. Build the full
// list of stack-member device UUIDs so port lookup can search all of them.
$stackUuids = [$switchUuid];
$stackByUnit = []; // unit-number => device-uuid (for prioritising)
$grpRows = $db->db_query(
    "SELECT d.uuid, m.caption, d.item_group
       FROM device d
       JOIN metadata m ON m.uuid = d.metadata
      WHERE d.type = 'switch'
        AND d.item_group IS NOT NULL
        AND d.item_group = (SELECT item_group FROM device WHERE uuid = :u)",
    ['u' => $switchUuid]
);
if (is_array($grpRows)) {
    foreach ($grpRows as $g) {
        if (!in_array($g['uuid'], $stackUuids, true)) {
            $stackUuids[] = $g['uuid'];
        }
        if (preg_match('/-\s*U(\d+)/i', (string)$g['caption'], $mm)) {
            $stackByUnit[(int)$mm[1]] = $g['uuid'];
        }
    }
}

// ---- 5. Resolve switch port -----------------------------------------------
// Try a few caption normalisations so we hit both "MultiGE1/0/12" and "1/1/12".
$candidates = array_values(array_unique(array_filter([
    $portId,
    $portDesc,
    preg_replace('/^[A-Za-z]+/', '', $portId),  // strip vendor prefix
])));

// Order stack member UUIDs: prefer the unit whose number matches the port's
// leading slot (e.g. "MultiGE7/0/18" -> unit 7), then the originally matched
// switch, then the rest. This handles vendor stacks where the LLDP chassis
// is the stack master but the port lives on a different physical unit.
$searchUuids = [];
foreach ($candidates as $cand) {
    if (preg_match('/(\d+)\s*\/\s*\d+\s*\/\s*\d+/', $cand, $sm)) {
        $unit = (int)$sm[1];
        if (isset($stackByUnit[$unit]) && !in_array($stackByUnit[$unit], $searchUuids, true)) {
            $searchUuids[] = $stackByUnit[$unit];
        }
    }
}
foreach ($stackUuids as $u) {
    if (!in_array($u, $searchUuids, true)) { $searchUuids[] = $u; }
}

$switchPort = null;
$matchedDeviceUuid = null;
// Pass 1: exact caption match across the (ordered) stack.
foreach ($candidates as $cand) {
    foreach ($searchUuids as $u) {
        $r = $db->db_query(
            "SELECT dp.uuid, m.caption
               FROM device_port dp
               JOIN metadata m ON m.uuid = dp.metadata
              WHERE dp.device = :d AND LOWER(m.caption) = LOWER(:c) LIMIT 1",
            ['d' => $u, 'c' => $cand]
        );
        if (is_array($r) && !empty($r)) {
            $switchPort = $r[0];
            $matchedDeviceUuid = $u;
            break 2;
        }
    }
}
// Pass 2: substring (LIKE) — only if it produces exactly one hit on a single device.
if ($switchPort === null) {
    foreach ($candidates as $cand) {
        foreach ($searchUuids as $u) {
            $r = $db->db_query(
                "SELECT dp.uuid, m.caption
                   FROM device_port dp
                   JOIN metadata m ON m.uuid = dp.metadata
                  WHERE dp.device = :d AND LOWER(m.caption) LIKE LOWER(:c) LIMIT 2",
                ['d' => $u, 'c' => '%' . $cand . '%']
            );
            if (is_array($r) && count($r) === 1) {
                $switchPort = $r[0];
                $matchedDeviceUuid = $u;
                break 2;
            }
        }
    }
}
if ($switchPort === null) {
    http_response_code(404);
    echo json_encode([
        'error'        => 'Switch port could not be resolved',
        'switch_uuid'  => $switchUuid,
        'searched_devices' => $searchUuids,
        'tried'        => $candidates,
    ]);
    exit;
}
// If we matched on a different stack member, surface that in the response.
if ($matchedDeviceUuid !== null && $matchedDeviceUuid !== $switchUuid) {
    $switchUuid = $matchedDeviceUuid;
}
$switchPortUuid = (string)$switchPort['uuid'];

// ---- 6. Upsert connection patchpanel <-> switch ---------------------------
// Idempotent: a connection in either direction between these two ports counts as a hit.
$existing = $db->db_query(
    "SELECT uuid, device_port_source, device_port_destination
       FROM connection
      WHERE (device_port_source = :a AND device_port_destination = :b)
         OR (device_port_source = :b AND device_port_destination = :a)
      LIMIT 1",
    ['a' => $patchPortUuid, 'b' => $switchPortUuid]
);
$linkUuid     = null;
$linkAction   = 'unchanged';
$conflictWith = null;

if (is_array($existing) && !empty($existing)) {
    $linkUuid   = (string)$existing[0]['uuid'];
    $linkAction = 'matched';
} else {
    // Conflict detection: either side already wired to a different counterpart.
    $clash = $db->db_query(
        "SELECT uuid, device_port_source, device_port_destination
           FROM connection
          WHERE device_port_source IN (:a,:b) OR device_port_destination IN (:a,:b)",
        ['a' => $patchPortUuid, 'b' => $switchPortUuid]
    );
    if (is_array($clash) && !empty($clash) && !$force) {
        http_response_code(409);
        echo json_encode([
            'error' => 'Existing connection conflicts with new link. Set "force": true to overwrite.',
            'conflicts' => $clash,
        ]);
        exit;
    }
    if ($force && is_array($clash) && !empty($clash)) {
        foreach ($clash as $c) {
            $db->db_query('DELETE FROM connection WHERE uuid = :u', ['u' => $c['uuid']]);
        }
        $conflictWith = array_column($clash, 'uuid');
    }
    // Insert metadata row first (cable label = "<patchpanel> -> <switch>").
    $cap = sprintf('CLI: %s ↔ %s', $patchPort['caption'] ?? '?', $switchPort['caption'] ?? '?');
    $mdRows = $db->db_query(
        "INSERT INTO metadata (caption, users) VALUES (:c, :u) RETURNING uuid",
        ['c' => $cap, 'u' => $userUuid]
    );
    $mdUuid = is_array($mdRows) && !empty($mdRows) ? (string)$mdRows[0]['uuid'] : null;
    $insRows = $db->db_query(
        "INSERT INTO connection (metadata, device_port_source, device_port_destination, type)
              VALUES (:m, :s, :d, :t) RETURNING uuid",
        ['m' => $mdUuid, 's' => $patchPortUuid, 'd' => $switchPortUuid, 't' => 'Cat 6a']
    );
    $linkUuid   = is_array($insRows) && !empty($insRows) ? (string)$insRows[0]['uuid'] : null;
    $linkAction = 'created';
}

// ---- 7. Optional expected end-device --------------------------------------
$deviceResult = null;
if (is_array($body['expected_device'] ?? null)) {
    $ed = $body['expected_device'];
    $devCap  = trim((string)($ed['caption'] ?? ''));
    $devType = trim((string)($ed['type']    ?? 'computer'));
    $devMac  = strtolower(trim((string)($ed['mac'] ?? '')));
    if ($devCap !== '') {
        // Locate or create the device.
        $r = $db->db_query(
            "SELECT d.uuid FROM device d JOIN metadata m ON m.uuid = d.metadata
              WHERE LOWER(m.caption) = LOWER(:c) LIMIT 1",
            ['c' => $devCap]
        );
        $devUuid = (is_array($r) && !empty($r)) ? (string)$r[0]['uuid'] : null;
        $devCreated = false;
        if ($devUuid === null) {
            $mdRows = $db->db_query(
                "INSERT INTO metadata (caption, users) VALUES (:c, :u) RETURNING uuid",
                ['c' => $devCap, 'u' => $userUuid]
            );
            $devMdUuid = is_array($mdRows) && !empty($mdRows) ? (string)$mdRows[0]['uuid'] : null;
            $dRows = $db->db_query(
                "INSERT INTO device (metadata, type) VALUES (:m, :t) RETURNING uuid",
                ['m' => $devMdUuid, 't' => $devType]
            );
            $devUuid = is_array($dRows) && !empty($dRows) ? (string)$dRows[0]['uuid'] : null;
            $devCreated = true;
        }

        // Ensure a single port on the device.
        $portRows = $db->db_query(
            "SELECT dp.uuid FROM device_port dp WHERE dp.device = :d LIMIT 1",
            ['d' => $devUuid]
        );
        $devPortUuid = (is_array($portRows) && !empty($portRows)) ? (string)$portRows[0]['uuid'] : null;
        if ($devPortUuid === null) {
            $portMd = $db->db_query(
                "INSERT INTO metadata (caption, users) VALUES (:c, :u) RETURNING uuid",
                ['c' => 'eth0', 'u' => $userUuid]
            );
            $portMdUuid = is_array($portMd) && !empty($portMd) ? (string)$portMd[0]['uuid'] : null;
            $insPort = $db->db_query(
                "INSERT INTO device_port (device, metadata, mac_address) VALUES (:d, :m, :mac) RETURNING uuid",
                ['d' => $devUuid, 'm' => $portMdUuid, 'mac' => ($devMac !== '' ? $devMac : null)]
            );
            $devPortUuid = is_array($insPort) && !empty($insPort) ? (string)$insPort[0]['uuid'] : null;
        } elseif ($devMac !== '') {
            $db->db_query(
                "UPDATE device_port SET mac_address = :m WHERE uuid = :u AND (mac_address IS NULL OR mac_address = '')",
                ['m' => $devMac, 'u' => $devPortUuid]
            );
        }

        // Find the net_outlet port for this caption (if any) and wire the
        // EXPECTED connection to it. Falls back to the patchpanel port.
        $expectedSourcePort = $outletPort['port_uuid'] ?? $patchPortUuid;
        $expEx = $db->db_query(
            "SELECT uuid FROM connection
              WHERE (expected_device_port_source = :s AND expected_device_port_destination = :d)
                 OR (expected_device_port_source = :d AND expected_device_port_destination = :s)
              LIMIT 1",
            ['s' => $expectedSourcePort, 'd' => $devPortUuid]
        );
        $expAction = 'matched';
        $expConnUuid = null;
        if (is_array($expEx) && !empty($expEx)) {
            $expConnUuid = (string)$expEx[0]['uuid'];
        } else {
            $cap = sprintf('Expected: %s ↔ %s', $outletCaption, $devCap);
            $mdRows = $db->db_query(
                "INSERT INTO metadata (caption, users) VALUES (:c, :u) RETURNING uuid",
                ['c' => $cap, 'u' => $userUuid]
            );
            $mdUuid = is_array($mdRows) && !empty($mdRows) ? (string)$mdRows[0]['uuid'] : null;
            $ins = $db->db_query(
                "INSERT INTO connection (metadata, expected_device_port_source, expected_device_port_destination, type)
                 VALUES (:m, :s, :d, :t) RETURNING uuid",
                ['m' => $mdUuid, 's' => $expectedSourcePort, 'd' => $devPortUuid, 't' => 'Cat 6a']
            );
            $expConnUuid = is_array($ins) && !empty($ins) ? (string)$ins[0]['uuid'] : null;
            $expAction = 'created';
        }
        $deviceResult = [
            'device_uuid'      => $devUuid,
            'device_created'   => $devCreated,
            'device_port_uuid' => $devPortUuid,
            'expected_connection_uuid'   => $expConnUuid,
            'expected_connection_action' => $expAction,
        ];
    }
}

// ---- 8. Journal entry ------------------------------------------------------
$comment = trim((string)($body['comment'] ?? ''));
$journalText = sprintf(
    'CLI record_link by %s: %s ↔ %s (%s)%s',
    $user,
    $patchPort['caption'] ?? '?',
    $switchPort['caption'] ?? '?',
    $linkAction,
    $comment !== '' ? ' — ' . $comment : ''
);
try {
    $jmd = $db->db_query(
        "INSERT INTO metadata (caption, description, users) VALUES (:c, :d, :u) RETURNING uuid",
        ['c' => 'cli_record_link', 'd' => $journalText, 'u' => $userUuid]
    );
    $jmdUuid = is_array($jmd) && !empty($jmd) ? (string)$jmd[0]['uuid'] : null;
    if ($jmdUuid !== null && $linkUuid !== null) {
        $db->db_query(
            "INSERT INTO journal (metadata, reference_table, reference_uuid)
             VALUES (:m, 'connection', :r)",
            ['m' => $jmdUuid, 'r' => $linkUuid]
        );
    }
} catch (\Throwable $e) {
    // Journal is best-effort.
    $logger->log('cli_record_link journal insert failed: ' . $e->getMessage(), 2);
}

// ---- 9. Response -----------------------------------------------------------
echo json_encode([
    'ok'      => true,
    'summary' => $journalText,
    'link'    => [
        'action'             => $linkAction,
        'connection_uuid'    => $linkUuid,
        'patchpanel_port'    => ['uuid' => $patchPortUuid, 'caption' => $patchPort['caption'] ?? null],
        'switch_port'        => ['uuid' => $switchPortUuid, 'caption' => $switchPort['caption'] ?? null],
        'switch_uuid'        => $switchUuid,
        'switch_caption'     => $switch['caption'] ?? null,
        'replaced_conflicts' => $conflictWith,
    ],
    'expected_device' => $deviceResult,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
namespace Portflow\Core;

if (!defined('APP_NAME')) { die('Direct access not allowed.'); }

require_once __DIR__ . '/db_adapter.php';

/**
 * Cable trace utility.
 *
 * Walks the connection graph from a given starting point (a switch port,
 * a connection, a cable or a device) outward through patch panels,
 * couplings and net outlets until a non-passthrough endpoint is reached
 * (or a configurable depth/cycle limit hits).
 *
 * The algorithm is deliberately schema-driven so it can be reused from
 * any caller (HTTP API, CLI, future MCP tool, etc.). It does not emit
 * output and never touches sessions or HTTP responses.
 *
 * Patch-panel model in this database (see api/cli_record_link.php):
 *   - A patch-panel slot is a single device_port row whose device.type
 *     is "patchpanel". The slot has TWO connection rows attached: one
 *     toward the switch, one toward the wall outlet / end device.
 *   - A wall outlet is a device with type in {net_outlet, outlet} and
 *     follows the same dual-connection pattern.
 *   - Generic couplers/keystones are flagged via device_port.coupling.
 *
 * Tracing therefore "crosses" any port that is either coupling=true OR
 * sits on a passthrough device, by jumping to the OTHER connection row
 * that touches that exact same port.
 */
class CableTrace
{
    /** Devices that are inherently passthrough (no terminal endpoint). */
    public const PASSTHROUGH_DEVICE_TYPES = ['patchpanel', 'net_outlet', 'outlet', 'coupler'];

    /** Maximum number of hops we walk in a single direction. */
    public const DEFAULT_MAX_HOPS = 16;

    private DatabaseAdapter $db;

    public function __construct(?DatabaseAdapter $db = null)
    {
        $this->db = $db ?? new DatabaseAdapter();
    }

    /**
     * Run a trace.
     *
     * @param string $kind  One of: 'device_port', 'connection', 'cable',
     *                      'device'. ('cable' is an alias for 'connection'.)
     * @param string $uuid  UUID of the starting object.
     * @param array  $opts  Options:
     *                        - max_hops (int)    : cap per direction (default 16)
     *                        - direction (string): 'both' (default) | 'forward' | 'backward'
     *                                              (only meaningful for 'device_port' starts;
     *                                              connection starts always trace both ends)
     *                        - prefer_expected (bool): if actual port endpoints are NULL,
     *                                              fall back to the expected_* columns (default true)
     */
    public function trace(string $kind, string $uuid, array $opts = []): array
    {
        $kind = strtolower($kind);
        if ($kind === 'cable') { $kind = 'connection'; }

        $maxHops = max(1, (int)($opts['max_hops'] ?? self::DEFAULT_MAX_HOPS));
        $direction = strtolower((string)($opts['direction'] ?? 'both'));
        $preferExpected = $opts['prefer_expected'] ?? true;

        if ($uuid === '' || !preg_match('/^[0-9a-f-]{32,36}$/i', $uuid)) {
            return $this->errorResult('invalid uuid');
        }

        switch ($kind) {
            case 'device_port':
                return $this->traceFromPort($uuid, $direction, $maxHops, (bool)$preferExpected);
            case 'connection':
                return $this->traceFromConnection($uuid, $maxHops, (bool)$preferExpected);
            case 'device':
                return $this->traceFromDevice($uuid, $maxHops, (bool)$preferExpected);
            default:
                return $this->errorResult("unsupported kind '$kind'");
        }
    }

    // ------------------------------------------------------------------
    // Entry-point dispatchers
    // ------------------------------------------------------------------

    private function traceFromPort(string $portUuid, string $direction, int $maxHops, bool $preferExpected): array
    {
        $startPort = $this->loadPort($portUuid);
        if ($startPort === null) {
            return $this->errorResult('start port not found');
        }
        $startNode = $this->portToNode($startPort);

        $branches = [];
        $connections = $this->loadConnectionsForPort($portUuid, $preferExpected);
        if (empty($connections)) {
            return [
                'kind'     => 'device_port',
                'start'    => $startNode,
                'branches' => [],
                'notes'    => ['Port hat keine Verbindungen'],
            ];
        }

        // For a switch port we simply follow each connection outward; the
        // direction filter only applies if the caller explicitly asked.
        foreach ($connections as $conn) {
            $otherPortUuid = $this->otherEnd($conn, $portUuid, $preferExpected);
            if ($otherPortUuid === null) { continue; }

            // direction filter: forward = follow connections where this port is source,
            // backward = where this port is destination. Default 'both' = no filter.
            if ($direction === 'forward'  && (string)$this->resolveSource($conn, $preferExpected) !== $portUuid) { continue; }
            if ($direction === 'backward' && (string)$this->resolveDestination($conn, $preferExpected) !== $portUuid) { continue; }

            $branches[] = $this->walk($portUuid, $conn, $otherPortUuid, $maxHops, $preferExpected);
        }

        return [
            'kind'     => 'device_port',
            'start'    => $startNode,
            'branches' => $branches,
        ];
    }

    private function traceFromConnection(string $connUuid, int $maxHops, bool $preferExpected): array
    {
        $conn = $this->loadConnection($connUuid);
        if ($conn === null) {
            return $this->errorResult('connection not found');
        }
        $srcUuid = $this->resolveSource($conn, $preferExpected);
        $dstUuid = $this->resolveDestination($conn, $preferExpected);

        $branches = [];
        if ($srcUuid !== null) {
            // walk OUTWARD from the source side: pretend we entered the
            // source port via this connection and continue past it.
            $branches[] = $this->walk($dstUuid ?? '', $conn, $srcUuid, $maxHops, $preferExpected, /*reversed*/ true);
        }
        if ($dstUuid !== null) {
            $branches[] = $this->walk($srcUuid ?? '', $conn, $dstUuid, $maxHops, $preferExpected, /*reversed*/ true);
        }

        $startNode = [
            'type'        => 'connection',
            'uuid'        => $connUuid,
            'cable_name'  => $conn['cable_name'] ?? null,
            'cable_type'  => $conn['type']       ?? null,
            'length'      => isset($conn['length']) ? (float)$conn['length'] : null,
            'speed'       => isset($conn['speed']) ? (float)$conn['speed']   : null,
            'caption'     => $conn['cable_name'] ?? ($conn['caption'] ?? null),
        ];

        return [
            'kind'     => 'connection',
            'start'    => $startNode,
            'branches' => $branches,
        ];
    }

    private function traceFromDevice(string $deviceUuid, int $maxHops, bool $preferExpected): array
    {
        // Trace from every connected port of the device. Useful for
        // "show me where each port of this switch leads".
        $rows = $this->db->db_query(
            "SELECT dp.uuid AS port_uuid
               FROM device_port dp
               JOIN connection c ON c.device_port_source = dp.uuid OR c.device_port_destination = dp.uuid
              WHERE dp.device = :d
              GROUP BY dp.uuid",
            ['d' => $deviceUuid]
        );
        $portTraces = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $portTraces[] = $this->traceFromPort((string)$r['port_uuid'], 'both', $maxHops, $preferExpected);
            }
        }
        return [
            'kind'        => 'device',
            'device_uuid' => $deviceUuid,
            'ports'       => $portTraces,
        ];
    }

    // ------------------------------------------------------------------
    // The actual graph walker
    // ------------------------------------------------------------------

    /**
     * Walk outward from $arrivedAtPort, having entered it via $viaConn
     * (which we don't traverse again). We came FROM $cameFromPort.
     *
     * Returns a list of "hops": each hop is one cable + the port we land on.
     */
    private function walk(string $cameFromPort, array $viaConn, string $arrivedAtPort, int $maxHops, bool $preferExpected, bool $reversed = false): array
    {
        $hops = [];
        $visitedConns = [(string)$viaConn['uuid'] => true];
        $visitedPorts = [];

        // Seed the chain with the first cable hop.
        $arrivedPortRow = $this->loadPort($arrivedAtPort);
        $hops[] = [
            'cable' => $this->connectionToEdge($viaConn),
            'port'  => $arrivedPortRow ? $this->portToNode($arrivedPortRow) : ['type' => 'port', 'uuid' => $arrivedAtPort, 'unresolved' => true],
        ];

        $current = $arrivedPortRow;
        $currentUuid = $arrivedAtPort;

        for ($i = 0; $i < $maxHops; $i++) {
            if ($current === null) { break; }
            if (isset($visitedPorts[$currentUuid])) {
                $hops[count($hops) - 1]['port']['truncated_reason'] = 'cycle';
                break;
            }
            $visitedPorts[$currentUuid] = true;

            // Endpoint? -> stop.
            if (!$this->isPassthroughPort($current)) {
                $hops[count($hops) - 1]['port']['endpoint'] = true;
                break;
            }

            // Find the OTHER connection touching this port (not the one we came in on).
            $next = null;
            $candidates = $this->loadConnectionsForPort($currentUuid, $preferExpected);
            foreach ($candidates as $c) {
                if (isset($visitedConns[(string)$c['uuid']])) { continue; }
                $next = $c;
                break;
            }
            if ($next === null) {
                $hops[count($hops) - 1]['port']['truncated_reason'] = 'dead_end';
                break;
            }
            $visitedConns[(string)$next['uuid']] = true;

            $otherEnd = $this->otherEnd($next, $currentUuid, $preferExpected);
            if ($otherEnd === null) {
                $hops[count($hops) - 1]['port']['truncated_reason'] = 'dangling_cable';
                break;
            }

            $otherRow = $this->loadPort($otherEnd);
            $hops[] = [
                'cable' => $this->connectionToEdge($next),
                'port'  => $otherRow ? $this->portToNode($otherRow) : ['type' => 'port', 'uuid' => $otherEnd, 'unresolved' => true],
            ];

            $cameFromPort = $currentUuid;
            $currentUuid  = $otherEnd;
            $current      = $otherRow;
        }

        if (count($hops) >= $maxHops) {
            $hops[count($hops) - 1]['port']['truncated_reason'] ??= 'max_hops';
        }

        return $hops;
    }

    private function isPassthroughPort(array $port): bool
    {
        if (!empty($port['coupling'])) { return true; }
        $deviceType = strtolower((string)($port['device_type'] ?? ''));
        return in_array($deviceType, self::PASSTHROUGH_DEVICE_TYPES, true);
    }

    // ------------------------------------------------------------------
    // Loaders
    // ------------------------------------------------------------------

    private function loadPort(string $portUuid): ?array
    {
        $rows = $this->db->db_query(
            "SELECT dp.uuid,
                    dp.coupling,
                    dp.mac_address,
                    dp.poe,
                    dp.speed,
                    dp.expected_speed,
                    dp.type AS port_type,
                    dp.size AS port_size,
                    dp.position AS port_position,
                    dp.metadata AS port_metadata_uuid,
                    mp.caption AS port_caption,
                    mp.status  AS port_status,
                    mp.tags    AS port_tags,
                    d.uuid       AS device_uuid,
                    d.type       AS device_type,
                    d.manufacturer,
                    d.model,
                    d.serial,
                    md.caption   AS device_caption,
                    l.uuid       AS location_uuid,
                    ml.caption   AS location_caption,
                    dpi.ip       AS port_ip,
                    dpi.hostname AS port_hostname,
                    dpsn.if_alias        AS snmp_if_alias,
                    dpsn.if_name         AS snmp_if_name,
                    dpsn.if_admin_status AS snmp_admin_status,
                    dpsn.if_oper_status  AS snmp_oper_status,
                    dpsn.last_seen_active AS snmp_last_seen_active,
                    dpsn.updated         AS snmp_updated
               FROM device_port dp
               LEFT JOIN metadata mp ON mp.uuid = dp.metadata
               LEFT JOIN device   d  ON d.uuid  = dp.device
               LEFT JOIN metadata md ON md.uuid = d.metadata
               LEFT JOIN location l  ON l.uuid  = d.location
               LEFT JOIN metadata ml ON ml.uuid = l.metadata
               LEFT JOIN device_port_ip dpi ON dpi.uuid = dp.device_port_ip
               LEFT JOIN device_port_snmp_state dpsn ON dpsn.device_port = dp.uuid
              WHERE dp.uuid = :u
              LIMIT 1",
            ['u' => $portUuid]
        );
        return is_array($rows) && !empty($rows) ? $rows[0] : null;
    }

    private function loadConnection(string $connUuid): ?array
    {
        $rows = $this->db->db_query(
            "SELECT c.uuid,
                    c.device_port_source,
                    c.device_port_destination,
                    c.expected_device_port_source,
                    c.expected_device_port_destination,
                    c.cable_name,
                    c.type,
                    c.length,
                    c.speed,
                    c.crossover,
                    mc.caption AS caption
               FROM connection c
               LEFT JOIN metadata mc ON mc.uuid = c.metadata
              WHERE c.uuid = :u
              LIMIT 1",
            ['u' => $connUuid]
        );
        return is_array($rows) && !empty($rows) ? $rows[0] : null;
    }

    /**
     * All connections that have $portUuid as either source or destination.
     */
    private function loadConnectionsForPort(string $portUuid, bool $preferExpected): array
    {
        $sql = "SELECT c.uuid,
                       c.device_port_source,
                       c.device_port_destination,
                       c.expected_device_port_source,
                       c.expected_device_port_destination,
                       c.cable_name,
                       c.type,
                       c.length,
                       c.speed,
                       c.crossover,
                       mc.caption AS caption
                  FROM connection c
                  LEFT JOIN metadata mc ON mc.uuid = c.metadata
                 WHERE c.device_port_source      = :p
                    OR c.device_port_destination = :p";
        $params = ['p' => $portUuid];
        if ($preferExpected) {
            // Also include connections whose actual ports are NULL but expected_* match.
            $sql .= " OR c.expected_device_port_source = :p
                      OR c.expected_device_port_destination = :p";
        }
        $rows = $this->db->db_query($sql, $params);
        return is_array($rows) ? $rows : [];
    }

    // ------------------------------------------------------------------
    // Connection helpers
    // ------------------------------------------------------------------

    private function resolveSource(array $conn, bool $preferExpected): ?string
    {
        $v = $conn['device_port_source'] ?? null;
        if ($v) { return (string)$v; }
        if ($preferExpected) {
            $v = $conn['expected_device_port_source'] ?? null;
            if ($v) { return (string)$v; }
        }
        return null;
    }

    private function resolveDestination(array $conn, bool $preferExpected): ?string
    {
        $v = $conn['device_port_destination'] ?? null;
        if ($v) { return (string)$v; }
        if ($preferExpected) {
            $v = $conn['expected_device_port_destination'] ?? null;
            if ($v) { return (string)$v; }
        }
        return null;
    }

    private function otherEnd(array $conn, string $thisPortUuid, bool $preferExpected): ?string
    {
        $src = $this->resolveSource($conn, $preferExpected);
        $dst = $this->resolveDestination($conn, $preferExpected);
        if ($src === $thisPortUuid) { return $dst; }
        if ($dst === $thisPortUuid) { return $src; }
        // Fallback for cases where this port appears only via expected_* on one side
        // but the connection itself is otherwise valid.
        return $dst ?? $src;
    }

    // ------------------------------------------------------------------
    // Shaping output
    // ------------------------------------------------------------------

    private function portToNode(array $row): array
    {
        return [
            'type'             => 'port',
            'uuid'             => (string)($row['uuid'] ?? ''),
            'caption'          => $row['port_caption'] ?? null,
            'status'           => isset($row['port_status']) ? (int)$row['port_status'] : null,
            'tags'             => $row['port_tags'] ?? null,
            'mac_address'      => $row['mac_address'] ?? null,
            'speed'            => isset($row['speed']) ? (float)$row['speed'] : null,
            'expected_speed'   => isset($row['expected_speed']) ? (float)$row['expected_speed'] : null,
            'poe'              => !empty($row['poe']),
            'coupling'         => !empty($row['coupling']),
            'ip'               => $row['port_ip'] ?? null,
            'hostname'         => $row['port_hostname'] ?? null,
            'snmp' => [
                'if_alias'         => $row['snmp_if_alias'] ?? null,
                'if_name'          => $row['snmp_if_name']  ?? null,
                'admin_status'     => isset($row['snmp_admin_status']) ? (int)$row['snmp_admin_status'] : null,
                'oper_status'      => isset($row['snmp_oper_status'])  ? (int)$row['snmp_oper_status']  : null,
                'last_seen_active' => $row['snmp_last_seen_active'] ?? null,
                'updated'          => $row['snmp_updated'] ?? null,
            ],
            'device' => [
                'uuid'         => $row['device_uuid'] ?? null,
                'caption'      => $row['device_caption'] ?? null,
                'type'         => $row['device_type'] ?? null,
                'manufacturer' => $row['manufacturer'] ?? null,
                'model'        => $row['model'] ?? null,
                'serial'       => $row['serial'] ?? null,
            ],
            'location' => [
                'uuid'    => $row['location_uuid'] ?? null,
                'caption' => $row['location_caption'] ?? null,
            ],
        ];
    }

    private function connectionToEdge(array $conn): array
    {
        return [
            'type'       => 'cable',
            'uuid'       => (string)($conn['uuid'] ?? ''),
            'cable_name' => $conn['cable_name'] ?? ($conn['caption'] ?? null),
            'cable_type' => $conn['type'] ?? null,
            'length'     => isset($conn['length']) ? (float)$conn['length'] : null,
            'speed'      => isset($conn['speed'])  ? (float)$conn['speed']  : null,
            'crossover'  => !empty($conn['crossover']),
        ];
    }

    private function errorResult(string $message): array
    {
        return [
            'kind'    => null,
            'start'   => null,
            'branches'=> [],
            'error'   => $message,
        ];
    }
}

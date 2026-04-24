<?php
/**
 * Portflow CLI endpoint: POST /api/cli/record_link
 *
 * Records a patchpanel <-> room outlet link based on the outlet label entered
 * in the CLI. The CLI payload can also carry an optional office end-device so
 * the room side is fully modelled even if sync happens later.
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

function cli_fail(int $status, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['error' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function cli_first_row(mixed $rows): ?array
{
    return is_array($rows) && !empty($rows) && is_array($rows[0]) ? $rows[0] : null;
}

function cli_create_metadata(DatabaseAdapter $db, string $caption, ?string $userUuid, ?string $description = null): ?string
{
    $rows = $db->db_query(
        'INSERT INTO metadata (status, caption, description, users) VALUES (0, :caption, :description, :user) RETURNING uuid',
        ['caption' => $caption, 'description' => $description, 'user' => $userUuid]
    );
    return cli_first_row($rows)['uuid'] ?? null;
}

function cli_activate_metadata(DatabaseAdapter $db, ?string $metadataUuid): void
{
    if (!is_string($metadataUuid) || $metadataUuid === '') {
        return;
    }
    $db->db_query(
        'UPDATE metadata SET status = 0 WHERE uuid = :uuid',
        ['uuid' => $metadataUuid]
    );
}

function cli_unique_strings(array $values): array
{
    $result = [];
    $seen = [];
    foreach ($values as $value) {
        $text = trim((string)$value);
        if ($text === '') {
            continue;
        }
        $key = strtolower($text);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $text;
    }
    return $result;
}

function cli_expand_outlet_ports(string $caption): array
{
    $text = preg_replace('/\s+/', '', trim($caption)) ?? '';
    if ($text === '') {
        return [];
    }
    if (!preg_match('/^(?P<prefix>.*?)(?P<start>\d+)-(?P<end>\d+)$/', $text, $matches)) {
        return [$text];
    }
    $start = (int)$matches['start'];
    $end = (int)$matches['end'];
    $width = max(strlen($matches['start']), strlen($matches['end']));
    $ports = [];
    $step = $end >= $start ? 1 : -1;
    for ($number = $start; ; $number += $step) {
        $ports[] = $matches['prefix'] . str_pad((string)$number, $width, '0', STR_PAD_LEFT);
        if ($number === $end) {
            break;
        }
    }
    return $ports;
}

function cli_infer_paired_outlet(string $caption): ?array
{
    $text = preg_replace('/\s+/', '', trim($caption)) ?? '';
    if ($text === '' || !preg_match('/^(?P<prefix>.*?)(?P<number>\d+)$/', $text, $matches)) {
        return null;
    }
    $number = (int)$matches['number'];
    $first = $number % 2 === 1 ? $number : $number - 1;
    if ($first <= 0) {
        return null;
    }
    $second = $first + 1;
    $width = strlen($matches['number']);
    $firstCaption = $matches['prefix'] . str_pad((string)$first, $width, '0', STR_PAD_LEFT);
    $secondCaption = $matches['prefix'] . str_pad((string)$second, $width, '0', STR_PAD_LEFT);
    return [
        'caption' => $matches['prefix'] . str_pad((string)$first, $width, '0', STR_PAD_LEFT) . '-' . str_pad((string)$second, $width, '0', STR_PAD_LEFT),
        'ports' => [$firstCaption, $secondCaption],
    ];
}

function cli_normalize_outlet(string $outletCaption, string $recordedOutletPort, array $outletPorts): array
{
    $caption = preg_replace('/\s+/', '', trim($outletCaption)) ?? '';
    $recorded = preg_replace('/\s+/', '', trim($recordedOutletPort)) ?? '';
    $ports = cli_unique_strings($outletPorts);
    if ($caption !== '' && empty($ports)) {
        $ports = cli_expand_outlet_ports($caption);
    }
    if ($recorded === '' && !empty($ports)) {
        $recorded = (string)$ports[0];
    }
    if ($recorded === '' && $caption !== '') {
        $recorded = $caption;
    }
    if (empty($ports) && $recorded !== '') {
        $ports[] = $recorded;
    }
    if (count($ports) === 1) {
        $paired = cli_infer_paired_outlet($recorded !== '' ? $recorded : (string)$ports[0]);
        if ($paired !== null) {
            $caption = $paired['caption'];
            $ports = $paired['ports'];
        }
    }
    if ($caption === '') {
        $paired = cli_infer_paired_outlet($recorded !== '' ? $recorded : (string)($ports[0] ?? ''));
        if ($paired !== null) {
            $caption = $paired['caption'];
        } elseif (!empty($ports)) {
            $caption = (string)$ports[0];
        }
    }
    if ($recorded !== '' && !in_array($recorded, $ports, true)) {
        array_unshift($ports, $recorded);
        $ports = cli_unique_strings($ports);
    }
    return ['outlet_caption' => $caption, 'recorded_outlet_port' => $recorded, 'outlet_ports' => $ports];
}

function cli_find_room(DatabaseAdapter $db, string $caption): ?array
{
    $rows = $db->db_query(
        "SELECT l.uuid, l.metadata, m.caption
           FROM location l
           JOIN metadata m ON m.uuid = l.metadata
          WHERE LOWER(m.caption) = LOWER(:caption)
          LIMIT 1",
        ['caption' => $caption]
    );
    return cli_first_row($rows);
}

function cli_load_location(DatabaseAdapter $db, string $locationUuid): ?array
{
    if ($locationUuid === '') {
        return null;
    }
    $rows = $db->db_query(
        "SELECT l.uuid, l.parent_location, l.type, m.caption
           FROM location l
           JOIN metadata m ON m.uuid = l.metadata
          WHERE l.uuid = :uuid
          LIMIT 1",
        ['uuid' => $locationUuid]
    );
    return cli_first_row($rows);
}

function cli_load_location_chain(DatabaseAdapter $db, ?string $locationUuid): array
{
    $chain = [];
    $seen = [];
    $currentUuid = is_string($locationUuid) ? $locationUuid : '';
    $guard = 0;
    while ($currentUuid !== '' && $guard++ < 32 && !isset($seen[$currentUuid])) {
        $seen[$currentUuid] = true;
        $location = cli_load_location($db, $currentUuid);
        if ($location === null) {
            break;
        }
        $chain[] = $location;
        if ((int)round((float)($location['type'] ?? 0)) === 4) {
            break;
        }
        $currentUuid = (string)($location['parent_location'] ?? '');
    }
    return $chain;
}

function cli_get_building_uuid(DatabaseAdapter $db, ?string $locationUuid): ?string
{
    foreach (cli_load_location_chain($db, $locationUuid) as $location) {
        if ((int)round((float)($location['type'] ?? 0)) === 4) {
            return (string)$location['uuid'];
        }
    }
    return null;
}

function cli_is_in_building(DatabaseAdapter $db, ?string $locationUuid, ?string $buildingUuid): bool
{
    if (!is_string($buildingUuid) || $buildingUuid === '') {
        return true;
    }
    return cli_get_building_uuid($db, $locationUuid) === $buildingUuid;
}

function cli_location_match_score(
    DatabaseAdapter $db,
    ?string $referenceLocationUuid,
    ?string $candidateLocationUuid,
    ?string $buildingUuid = null
): ?int {
    $referenceChain = cli_load_location_chain($db, $referenceLocationUuid);
    $candidateChain = cli_load_location_chain($db, $candidateLocationUuid);

    if (empty($candidateChain)) {
        return cli_is_in_building($db, $candidateLocationUuid, $buildingUuid) ? 1000 : null;
    }

    $referenceDepths = [];
    foreach ($referenceChain as $depth => $location) {
        $uuid = (string)($location['uuid'] ?? '');
        if ($uuid !== '') {
            $referenceDepths[$uuid] = $depth;
        }
    }

    foreach ($candidateChain as $candidateDepth => $location) {
        $uuid = (string)($location['uuid'] ?? '');
        if ($uuid !== '' && array_key_exists($uuid, $referenceDepths)) {
            return max($referenceDepths[$uuid], $candidateDepth);
        }
    }

    return cli_is_in_building($db, $candidateLocationUuid, $buildingUuid) ? 1000 : null;
}

function cli_pick_best_location_row(
    DatabaseAdapter $db,
    array $rows,
    string $locationField,
    ?string $referenceLocationUuid,
    ?string $buildingUuid = null,
    ?string $patchPortField = null
): ?array {
    $bestRow = null;
    $bestScore = null;
    $bestHasOutletLink = true;

    foreach ($rows as $row) {
        $score = cli_location_match_score($db, $referenceLocationUuid, $row[$locationField] ?? null, $buildingUuid);
        if ($score === null) {
            continue;
        }

        $hasOutletLink = false;
        if ($patchPortField !== null && !empty($row[$patchPortField])) {
            $hasOutletLink = cli_find_outlet_from_patch($db, (string)$row[$patchPortField]) !== null;
        }

        if ($bestRow === null
            || $score < $bestScore
            || ($score === $bestScore && $bestHasOutletLink && !$hasOutletLink)) {
            $bestRow = $row;
            $bestScore = $score;
            $bestHasOutletLink = $hasOutletLink;
        }
    }

    return $bestRow;
}

function cli_find_patchpanel_port(DatabaseAdapter $db, string $caption, ?string $referenceLocationUuid = null, ?string $buildingUuid = null): ?array
{
    $rows = $db->db_query(
        "SELECT dp.uuid AS port_uuid,
                dp.metadata AS port_metadata_uuid,
                pm.caption AS port_caption,
                d.uuid AS device_uuid,
                d.metadata AS device_metadata_uuid,
                dm.caption AS device_caption,
                d.location AS location_uuid
           FROM device_port dp
           JOIN metadata pm ON pm.uuid = dp.metadata
           JOIN device d ON d.uuid = dp.device
           JOIN metadata dm ON dm.uuid = d.metadata
          WHERE d.type = 'patchpanel'
            AND LOWER(pm.caption) = LOWER(:caption)
          LIMIT 50",
        ['caption' => $caption]
    );
    if (!is_array($rows) || empty($rows)) {
        return null;
    }
    $bestRow = cli_pick_best_location_row($db, $rows, 'location_uuid', $referenceLocationUuid, $buildingUuid, 'port_uuid');
    if ($bestRow !== null) {
        return $bestRow;
    }
    return cli_first_row($rows);
}

function cli_find_outlet_device_by_ports(DatabaseAdapter $db, array $captions, ?string $roomUuid, ?string $buildingUuid = null): ?array
{
    foreach ($captions as $caption) {
        $rows = $db->db_query(
            "SELECT dp.uuid AS port_uuid,
                    dp.metadata AS port_metadata_uuid,
                    pm.caption AS port_caption,
                    d.uuid AS device_uuid,
                    d.metadata AS device_metadata_uuid,
                    dm.caption AS device_caption,
                    d.location AS location_uuid
               FROM device_port dp
               JOIN metadata pm ON pm.uuid = dp.metadata
               JOIN device d ON d.uuid = dp.device
               JOIN metadata dm ON dm.uuid = d.metadata
              WHERE d.type IN ('net_outlet', 'outlet')
                AND LOWER(pm.caption) = LOWER(:caption)
              LIMIT 20",
            ['caption' => $caption]
        );
        if (!is_array($rows) || empty($rows)) {
            continue;
        }
        $bestRow = cli_pick_best_location_row($db, $rows, 'location_uuid', $roomUuid, $buildingUuid);
        if ($bestRow !== null) {
            return $bestRow;
        }
        $first = cli_first_row($rows);
        if ($first !== null) {
            return $first;
        }
    }
    return null;
}

function cli_find_outlet_from_patch(DatabaseAdapter $db, string $patchPortUuid, ?string $roomUuid = null, ?string $buildingUuid = null): ?array
{
    $rows = $db->db_query(
        "SELECT dp.uuid AS port_uuid,
                dp.metadata AS port_metadata_uuid,
                pm.caption AS port_caption,
                d.uuid AS device_uuid,
                d.metadata AS device_metadata_uuid,
                dm.caption AS device_caption,
                d.location AS location_uuid
           FROM connection c
           JOIN device_port dp ON dp.uuid = CASE
                WHEN c.device_port_source = :patch THEN c.device_port_destination
                ELSE c.device_port_source
           END
           JOIN metadata pm ON pm.uuid = dp.metadata
           JOIN device d ON d.uuid = dp.device
           JOIN metadata dm ON dm.uuid = d.metadata
          WHERE (c.device_port_source = :patch OR c.device_port_destination = :patch)
            AND d.type IN ('net_outlet', 'outlet')
                    LIMIT 20",
        ['patch' => $patchPortUuid]
    );
    if (is_array($rows) && !empty($rows)) {
        $bestRow = cli_pick_best_location_row($db, $rows, 'location_uuid', $roomUuid, $buildingUuid);
        if ($bestRow !== null) {
            return $bestRow;
        }
    }
    return cli_first_row($rows);
}

function cli_load_outlet_ports(DatabaseAdapter $db, string $deviceUuid): array
{
    $rows = $db->db_query(
        "SELECT dp.uuid AS port_uuid,
                dp.metadata AS port_metadata_uuid,
                pm.caption AS port_caption
           FROM device_port dp
           JOIN metadata pm ON pm.uuid = dp.metadata
          WHERE dp.device = :device",
        ['device' => $deviceUuid]
    );
    $result = [];
    if (!is_array($rows)) {
        return $result;
    }
    foreach ($rows as $row) {
        $caption = trim((string)($row['port_caption'] ?? ''));
        if ($caption === '') {
            continue;
        }
        $result[strtolower($caption)] = $row;
    }
    return $result;
}

function cli_is_passthrough_type(?string $deviceType): bool
{
    return in_array(strtolower((string)$deviceType), ['patchpanel', 'net_outlet', 'outlet', 'coupler'], true);
}

function cli_load_connections_for_port(DatabaseAdapter $db, string $portUuid): array
{
    $rows = $db->db_query(
        "SELECT c.uuid,
                c.metadata,
                c.device_port_source,
                c.device_port_destination,
                src_d.type AS src_type,
                dst_d.type AS dst_type
           FROM connection c
           LEFT JOIN device_port src_dp ON src_dp.uuid = c.device_port_source
           LEFT JOIN device src_d ON src_d.uuid = src_dp.device
           LEFT JOIN device_port dst_dp ON dst_dp.uuid = c.device_port_destination
           LEFT JOIN device dst_d ON dst_d.uuid = dst_dp.device
          WHERE c.device_port_source = :port OR c.device_port_destination = :port",
        ['port' => $portUuid]
    );
    return is_array($rows) ? $rows : [];
}

function cli_collect_port_conflicts(
    array $connections,
    string $portUuid,
    string $keepPortUuid,
    array $preserveOtherTypes = [],
    bool $preserveNonPassthrough = false
): array {
    $conflicts = [];
    foreach ($connections as $connection) {
        $sourceUuid = (string)($connection['device_port_source'] ?? '');
        $destinationUuid = (string)($connection['device_port_destination'] ?? '');
        if (($sourceUuid === $portUuid && $destinationUuid === $keepPortUuid)
            || ($sourceUuid === $keepPortUuid && $destinationUuid === $portUuid)) {
            continue;
        }

        if ($sourceUuid === $portUuid) {
            $otherType = strtolower((string)($connection['dst_type'] ?? ''));
        } elseif ($destinationUuid === $portUuid) {
            $otherType = strtolower((string)($connection['src_type'] ?? ''));
        } else {
            continue;
        }

        if ($preserveNonPassthrough && $otherType !== '' && !cli_is_passthrough_type($otherType)) {
            continue;
        }
        if (in_array($otherType, $preserveOtherTypes, true)) {
            continue;
        }
        if (!empty($connection['uuid'])) {
            $conflicts[] = (string)$connection['uuid'];
        }
    }
    return $conflicts;
}

function cli_find_patch_outlet_conflicts(DatabaseAdapter $db, string $patchPortUuid, string $outletPortUuid): array
{
    $patchConflicts = cli_collect_port_conflicts(
        cli_load_connections_for_port($db, $patchPortUuid),
        $patchPortUuid,
        $outletPortUuid,
        ['switch'],
        false
    );
    $outletConflicts = cli_collect_port_conflicts(
        cli_load_connections_for_port($db, $outletPortUuid),
        $outletPortUuid,
        $patchPortUuid,
        [],
        true
    );
    return array_values(array_unique(array_merge($patchConflicts, $outletConflicts)));
}

function cli_find_patch_switch_conflicts(DatabaseAdapter $db, string $patchPortUuid, string $switchPortUuid): array
{
    $patchConflicts = cli_collect_port_conflicts(
        cli_load_connections_for_port($db, $patchPortUuid),
        $patchPortUuid,
        $switchPortUuid,
        ['net_outlet', 'outlet', 'coupler'],
        false
    );
    $switchConflicts = cli_collect_port_conflicts(
        cli_load_connections_for_port($db, $switchPortUuid),
        $switchPortUuid,
        $patchPortUuid,
        [],
        false
    );
    return array_values(array_unique(array_merge($patchConflicts, $switchConflicts)));
}

function cli_resolve_switch_link(DatabaseAdapter $db, array $lldp, ?string $buildingUuid = null): ?array
{
    $sysName = trim((string)($lldp['sys_name'] ?? ''));
    $mgmtAddr = trim((string)($lldp['mgmt_address'] ?? ''));
    $chassis = strtolower(trim((string)($lldp['chassis_id'] ?? '')));
    $portId = trim((string)($lldp['port_id'] ?? ''));
    $portDesc = trim((string)($lldp['port_desc'] ?? ''));

    if ($sysName === '' && $mgmtAddr === '' && $chassis === '' && $portId === '' && $portDesc === '') {
        return null;
    }
    if ($sysName === '' && $mgmtAddr === '' && $chassis === '') {
        cli_fail(422, 'lldp.sys_name, lldp.mgmt_address or lldp.chassis_id required');
    }
    if ($portId === '' && $portDesc === '') {
        cli_fail(422, 'lldp.port_id or lldp.port_desc required');
    }

    $switch = null;
    if ($sysName !== '') {
        $rows = $db->db_query(
            "SELECT d.uuid, d.item_group, d.location, m.caption
               FROM device d
               JOIN metadata m ON m.uuid = d.metadata
              WHERE d.type = 'switch' AND LOWER(m.caption) = LOWER(:caption)
              LIMIT 50",
            ['caption' => $sysName]
        );
        if (is_array($rows) && $buildingUuid !== null) {
            foreach ($rows as $row) {
                if (cli_is_in_building($db, $row['location'] ?? null, $buildingUuid)) {
                    $switch = $row;
                    break;
                }
            }
        }
        $switch = $switch ?? cli_first_row($rows);
    }
    if ($switch === null && $mgmtAddr !== '') {
        $rows = $db->db_query(
            "SELECT d.uuid, d.item_group, d.location, m.caption
               FROM device d
               JOIN metadata m ON m.uuid = d.metadata
               JOIN device_port dp ON dp.device = d.uuid
               JOIN device_port_ip dpi ON dpi.uuid = dp.device_port_ip
              WHERE d.type = 'switch' AND host(dpi.ip) = :ip
              LIMIT 50",
            ['ip' => $mgmtAddr]
        );
        if (is_array($rows) && $buildingUuid !== null) {
            foreach ($rows as $row) {
                if (cli_is_in_building($db, $row['location'] ?? null, $buildingUuid)) {
                    $switch = $row;
                    break;
                }
            }
        }
        $switch = $switch ?? cli_first_row($rows);
    }
    if ($switch === null && $chassis !== '') {
        $normalizedMac = strtolower((string)preg_replace('/[^0-9a-f]/i', '', $chassis));
        if (strlen($normalizedMac) === 12) {
            $formattedMac = implode(':', str_split($normalizedMac, 2));
            $rows = $db->db_query(
                "SELECT d.uuid, d.item_group, d.location, m.caption
                   FROM device d
                   JOIN metadata m ON m.uuid = d.metadata
                   JOIN device_port dp ON dp.device = d.uuid
                  WHERE d.type = 'switch' AND LOWER(dp.mac_address) = :mac
                  LIMIT 50",
                ['mac' => $formattedMac]
            );
            if (is_array($rows) && $buildingUuid !== null) {
                foreach ($rows as $row) {
                    if (cli_is_in_building($db, $row['location'] ?? null, $buildingUuid)) {
                        $switch = $row;
                        break;
                    }
                }
            }
            $switch = $switch ?? cli_first_row($rows);
        }
    }
    if ($switch === null) {
        cli_fail(404, 'Switch could not be resolved from LLDP info', ['lldp' => $lldp]);
    }

    $switchUuid = (string)$switch['uuid'];
    $stackUuids = [$switchUuid];
    $stackByUnit = [];
    $groupRows = $db->db_query(
        "SELECT d.uuid, m.caption
           FROM device d
           JOIN metadata m ON m.uuid = d.metadata
          WHERE d.type = 'switch'
            AND d.item_group IS NOT NULL
            AND d.item_group = (SELECT item_group FROM device WHERE uuid = :uuid)",
        ['uuid' => $switchUuid]
    );
    if (is_array($groupRows)) {
        foreach ($groupRows as $groupRow) {
            if (!in_array($groupRow['uuid'], $stackUuids, true)) {
                $stackUuids[] = $groupRow['uuid'];
            }
            if (preg_match('/-\s*U(\d+)/i', (string)($groupRow['caption'] ?? ''), $matches)) {
                $stackByUnit[(int)$matches[1]] = $groupRow['uuid'];
            }
        }
    }

    $candidates = array_values(array_unique(array_filter([
        $portId,
        $portDesc,
        preg_replace('/^[A-Za-z]+/', '', $portId),
    ], static fn($value): bool => trim((string)$value) !== '')));

    $searchUuids = [];
    foreach ($candidates as $candidate) {
        if (preg_match('/(\d+)\s*\/\s*\d+\s*\/\s*\d+/', (string)$candidate, $matches)) {
            $unit = (int)$matches[1];
            if (isset($stackByUnit[$unit]) && !in_array($stackByUnit[$unit], $searchUuids, true)) {
                $searchUuids[] = $stackByUnit[$unit];
            }
        }
    }
    foreach ($stackUuids as $stackUuid) {
        if (!in_array($stackUuid, $searchUuids, true)) {
            $searchUuids[] = $stackUuid;
        }
    }

    $switchPort = null;
    $matchedDeviceUuid = null;
    foreach ($candidates as $candidate) {
        foreach ($searchUuids as $searchUuid) {
            $rows = $db->db_query(
                "SELECT dp.uuid, dp.metadata, m.caption
                   FROM device_port dp
                   JOIN metadata m ON m.uuid = dp.metadata
                  WHERE dp.device = :device AND LOWER(m.caption) = LOWER(:caption)
                  LIMIT 1",
                ['device' => $searchUuid, 'caption' => $candidate]
            );
            $switchPort = cli_first_row($rows);
            if ($switchPort !== null) {
                $matchedDeviceUuid = $searchUuid;
                break 2;
            }
        }
    }
    if ($switchPort === null) {
        foreach ($candidates as $candidate) {
            foreach ($searchUuids as $searchUuid) {
                $rows = $db->db_query(
                    "SELECT dp.uuid, dp.metadata, m.caption
                       FROM device_port dp
                       JOIN metadata m ON m.uuid = dp.metadata
                      WHERE dp.device = :device AND LOWER(m.caption) LIKE LOWER(:caption)
                      LIMIT 2",
                    ['device' => $searchUuid, 'caption' => '%' . $candidate . '%']
                );
                if (is_array($rows) && count($rows) === 1) {
                    $switchPort = $rows[0];
                    $matchedDeviceUuid = $searchUuid;
                    break 2;
                }
            }
        }
    }
    if ($switchPort === null) {
        cli_fail(404, 'Switch port could not be resolved', [
            'switch_uuid' => $switchUuid,
            'searched_devices' => $searchUuids,
            'tried' => $candidates,
        ]);
    }

    if ($matchedDeviceUuid !== null && $matchedDeviceUuid !== $switchUuid) {
        $switchUuid = $matchedDeviceUuid;
        $switchRows = $db->db_query(
            "SELECT d.uuid, m.caption
               FROM device d
               JOIN metadata m ON m.uuid = d.metadata
              WHERE d.uuid = :uuid
              LIMIT 1",
            ['uuid' => $switchUuid]
        );
        $matchedSwitch = cli_first_row($switchRows);
        if ($matchedSwitch !== null) {
            $switch = $matchedSwitch;
        }
    }

    return [
        'switch_uuid' => $switchUuid,
        'switch_caption' => $switch['caption'] ?? null,
        'switch_port_uuid' => (string)$switchPort['uuid'],
        'switch_port_caption' => $switchPort['caption'] ?? null,
        'switch_port_metadata_uuid' => $switchPort['metadata'] ?? null,
    ];
}

function cli_ensure_outlet_device(
    DatabaseAdapter $db,
    string $userUuid,
    ?array $room,
    string $outletCaption,
    array $outletPorts,
    string $patchPortUuid,
    ?string $buildingUuid = null
): array {
    $roomUuid = $room['uuid'] ?? null;
    $device = cli_find_outlet_device_by_ports($db, $outletPorts, $roomUuid, $buildingUuid);
    if ($device === null) {
        $device = cli_find_outlet_from_patch($db, $patchPortUuid, $roomUuid, $buildingUuid);
    }

    $created = false;
    if ($device === null) {
        if ($roomUuid === null) {
            cli_fail(422, 'room required when network outlet does not yet exist');
        }
        $metadataUuid = cli_create_metadata($db, $outletCaption, $userUuid);
        $rows = $db->db_query(
            "INSERT INTO device (metadata, location, type) VALUES (:metadata, :location, 'net_outlet') RETURNING uuid",
            ['metadata' => $metadataUuid, 'location' => $roomUuid]
        );
        $deviceUuid = (string)(cli_first_row($rows)['uuid'] ?? '');
        if ($deviceUuid === '') {
            cli_fail(500, 'Failed to create net_outlet device');
        }
        $device = [
            'device_uuid' => $deviceUuid,
            'device_metadata_uuid' => $metadataUuid,
            'device_caption' => $outletCaption,
            'location_uuid' => $roomUuid,
        ];
        $created = true;
    } else {
        if ($roomUuid !== null && ($device['location_uuid'] ?? null) !== $roomUuid) {
            $db->db_query(
                'UPDATE device SET location = :location WHERE uuid = :uuid',
                ['location' => $roomUuid, 'uuid' => $device['device_uuid']]
            );
            $device['location_uuid'] = $roomUuid;
        }
        cli_activate_metadata($db, $device['device_metadata_uuid'] ?? null);
        if (!empty($device['device_metadata_uuid']) && (string)($device['device_caption'] ?? '') !== $outletCaption) {
            $db->db_query(
                'UPDATE metadata SET caption = :caption, status = 0 WHERE uuid = :uuid',
                ['caption' => $outletCaption, 'uuid' => $device['device_metadata_uuid']]
            );
            $device['device_caption'] = $outletCaption;
        }
    }

    $portMap = cli_load_outlet_ports($db, (string)$device['device_uuid']);
    foreach ($outletPorts as $portCaption) {
        $key = strtolower($portCaption);
        if (isset($portMap[$key])) {
            cli_activate_metadata($db, $portMap[$key]['port_metadata_uuid'] ?? null);
            continue;
        }
        $metadataUuid = cli_create_metadata($db, $portCaption, $userUuid);
        $rows = $db->db_query(
            'INSERT INTO device_port (device, metadata, type) VALUES (:device, :metadata, :type) RETURNING uuid',
            ['device' => $device['device_uuid'], 'metadata' => $metadataUuid, 'type' => 10]
        );
        $portUuid = (string)(cli_first_row($rows)['uuid'] ?? '');
        if ($portUuid === '') {
            cli_fail(500, 'Failed to create network outlet port', ['port' => $portCaption]);
        }
        $portMap[$key] = [
            'port_uuid' => $portUuid,
            'port_metadata_uuid' => $metadataUuid,
            'port_caption' => $portCaption,
        ];
    }

    return ['device' => $device, 'created' => $created, 'ports' => $portMap];
}

function cli_find_physical_connection(DatabaseAdapter $db, string $sourcePortUuid, string $destinationPortUuid): ?array
{
    $rows = $db->db_query(
        "SELECT uuid
           FROM connection
          WHERE (device_port_source = :source AND device_port_destination = :destination)
             OR (device_port_source = :destination AND device_port_destination = :source)
          LIMIT 1",
        ['source' => $sourcePortUuid, 'destination' => $destinationPortUuid]
    );
    return cli_first_row($rows);
}

function cli_find_physical_conflicts(DatabaseAdapter $db, string $sourcePortUuid, string $destinationPortUuid): array
{
    $rows = $db->db_query(
        "SELECT uuid
           FROM connection
          WHERE device_port_source IN (:source, :destination)
             OR device_port_destination IN (:source, :destination)",
        ['source' => $sourcePortUuid, 'destination' => $destinationPortUuid]
    );
    return is_array($rows) ? $rows : [];
}

function cli_find_expected_connection(DatabaseAdapter $db, string $sourcePortUuid, string $destinationPortUuid): ?array
{
    $rows = $db->db_query(
        "SELECT uuid
           FROM connection
          WHERE (expected_device_port_source = :source AND expected_device_port_destination = :destination)
             OR (expected_device_port_source = :destination AND expected_device_port_destination = :source)
          LIMIT 1",
        ['source' => $sourcePortUuid, 'destination' => $destinationPortUuid]
    );
    return cli_first_row($rows);
}

function cli_find_expected_conflicts(DatabaseAdapter $db, string $sourcePortUuid, string $destinationPortUuid): array
{
    $rows = $db->db_query(
        "SELECT uuid
           FROM connection
          WHERE expected_device_port_source IN (:source, :destination)
             OR expected_device_port_destination IN (:source, :destination)",
        ['source' => $sourcePortUuid, 'destination' => $destinationPortUuid]
    );
    return is_array($rows) ? $rows : [];
}

function cli_find_office_connection(DatabaseAdapter $db, string $sourcePortUuid, string $destinationPortUuid): ?array
{
    $rows = $db->db_query(
        "SELECT uuid, metadata
           FROM connection
          WHERE (device_port_source = :source AND device_port_destination = :destination)
             OR (device_port_source = :destination AND device_port_destination = :source)
             OR (expected_device_port_source = :source AND expected_device_port_destination = :destination)
             OR (expected_device_port_source = :destination AND expected_device_port_destination = :source)
          LIMIT 1",
        ['source' => $sourcePortUuid, 'destination' => $destinationPortUuid]
    );
    return cli_first_row($rows);
}

function cli_find_office_conflicts(DatabaseAdapter $db, string $sourcePortUuid, string $destinationPortUuid): array
{
    $rows = $db->db_query(
        "SELECT DISTINCT c.uuid
           FROM connection c
           LEFT JOIN device_port src_dp ON src_dp.uuid = c.device_port_source
           LEFT JOIN device src_d ON src_d.uuid = src_dp.device
           LEFT JOIN device_port dst_dp ON dst_dp.uuid = c.device_port_destination
           LEFT JOIN device dst_d ON dst_d.uuid = dst_dp.device
           LEFT JOIN device_port exp_src_dp ON exp_src_dp.uuid = c.expected_device_port_source
           LEFT JOIN device exp_src_d ON exp_src_d.uuid = exp_src_dp.device
           LEFT JOIN device_port exp_dst_dp ON exp_dst_dp.uuid = c.expected_device_port_destination
           LEFT JOIN device exp_dst_d ON exp_dst_d.uuid = exp_dst_dp.device
          WHERE (
                c.device_port_source IN (:source, :destination)
             OR c.device_port_destination IN (:source, :destination)
             OR c.expected_device_port_source IN (:source, :destination)
             OR c.expected_device_port_destination IN (:source, :destination)
          )
            AND (
                     (src_d.uuid IS NOT NULL AND COALESCE(src_d.type, '') NOT IN ('patchpanel', 'net_outlet', 'outlet', 'coupler'))
                 OR (dst_d.uuid IS NOT NULL AND COALESCE(dst_d.type, '') NOT IN ('patchpanel', 'net_outlet', 'outlet', 'coupler'))
                 OR (exp_src_d.uuid IS NOT NULL AND COALESCE(exp_src_d.type, '') NOT IN ('patchpanel', 'net_outlet', 'outlet', 'coupler'))
                 OR (exp_dst_d.uuid IS NOT NULL AND COALESCE(exp_dst_d.type, '') NOT IN ('patchpanel', 'net_outlet', 'outlet', 'coupler'))
          )",
        ['source' => $sourcePortUuid, 'destination' => $destinationPortUuid]
    );
    return is_array($rows) ? $rows : [];
}

$db = new DatabaseAdapter();
$logger = new Logger();

// ---- 1. Authentication -----------------------------------------------------
if (empty($_SESSION['uuid'])) {
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
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
        cli_fail(401, 'Unauthorized');
    }
    include_once __DIR__ . '/../includes/core/auth.php';
    $auth = new \Portflow\Core\Auth();
    if (!$auth->apiSignin($user, $pass)) {
        header('WWW-Authenticate: Basic realm="Portflow CLI"');
        cli_fail(401, 'Unauthorized');
    }
}
$userUuid = (string)$_SESSION['uuid'];
$actor = (string)($_SERVER['PHP_AUTH_USER'] ?? ($_SESSION['username'] ?? 'cli'));

// ---- 2. Parse + validate payload -------------------------------------------
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    cli_fail(422, 'Invalid JSON body');
}

$roomCaption = trim((string)($body['room'] ?? ''));
$normalizedOutlet = cli_normalize_outlet(
    (string)($body['outlet_caption'] ?? ''),
    (string)($body['recorded_outlet_port'] ?? ''),
    is_array($body['outlet_ports'] ?? null) ? $body['outlet_ports'] : []
);
$outletCaption = $normalizedOutlet['outlet_caption'];
$recordedOutletPort = $normalizedOutlet['recorded_outlet_port'];
$outletPorts = $normalizedOutlet['outlet_ports'];
if ($outletCaption === '') {
    cli_fail(422, 'outlet_caption required');
}
if ($recordedOutletPort === '') {
    cli_fail(422, 'recorded_outlet_port required');
}

$lldp = is_array($body['lldp'] ?? null) ? $body['lldp'] : [];
$overwrite = array_key_exists('force', $body) ? !empty($body['force']) : true;

// ---- 3. Resolve room and patchpanel port -----------------------------------
$room = $roomCaption !== '' ? cli_find_room($db, $roomCaption) : null;
if ($roomCaption !== '' && $room === null) {
    cli_fail(404, 'Room could not be resolved', ['room' => $roomCaption]);
}
$buildingUuid = cli_get_building_uuid($db, $room['uuid'] ?? null);

$patchPort = cli_find_patchpanel_port($db, $recordedOutletPort, $room['uuid'] ?? null, $buildingUuid);
if ($patchPort === null && $outletCaption !== $recordedOutletPort) {
    $patchPort = cli_find_patchpanel_port($db, $outletCaption, $room['uuid'] ?? null, $buildingUuid);
}
if ($patchPort === null) {
    cli_fail(404, 'Patchpanel port could not be resolved from outlet caption', [
        'outlet_caption' => $outletCaption,
        'recorded_outlet_port' => $recordedOutletPort,
        'building_uuid' => $buildingUuid,
    ]);
}
$patchPortUuid = (string)$patchPort['port_uuid'];

// ---- 4. Find or create network outlet --------------------------------------
$outletResult = cli_ensure_outlet_device($db, $userUuid, $room, $outletCaption, $outletPorts, $patchPortUuid, $buildingUuid);
$outletDevice = $outletResult['device'];
$outletPortMap = $outletResult['ports'];
$recordedOutletRow = $outletPortMap[strtolower($recordedOutletPort)] ?? null;
if (!is_array($recordedOutletRow)) {
    cli_fail(500, 'Recorded outlet port missing after outlet upsert', ['recorded_outlet_port' => $recordedOutletPort]);
}
$outletPortUuid = (string)$recordedOutletRow['port_uuid'];

// ---- 5. Optional LLDP-based patchpanel <-> switch connection --------------
$switchLink = cli_resolve_switch_link($db, $lldp, $buildingUuid);
$switchAction = null;
$switchConnectionUuid = null;
$replacedSwitch = [];
if ($switchLink !== null) {
    $existingSwitch = cli_find_physical_connection($db, $patchPortUuid, $switchLink['switch_port_uuid']);
    $switchAction = 'matched';
    if ($existingSwitch !== null) {
        $switchConnectionUuid = (string)$existingSwitch['uuid'];
    } else {
        $conflicts = cli_find_patch_switch_conflicts($db, $patchPortUuid, $switchLink['switch_port_uuid']);
        if (!$overwrite && !empty($conflicts)) {
            cli_fail(409, 'Existing switch connection conflicts with CLI record', ['conflicts' => $conflicts]);
        }
        foreach ($conflicts as $conflictUuid) {
            $db->db_query('DELETE FROM connection WHERE uuid = :uuid', ['uuid' => $conflictUuid]);
            $replacedSwitch[] = $conflictUuid;
        }
        $metadataUuid = cli_create_metadata(
            $db,
            sprintf('CLI switch: %s ↔ %s', $patchPort['port_caption'] ?? $recordedOutletPort, $switchLink['switch_port_caption'] ?? '?'),
            $userUuid
        );
        $rows = $db->db_query(
            'INSERT INTO connection (metadata, device_port_source, device_port_destination, type) VALUES (:metadata, :source, :destination, :type) RETURNING uuid',
            ['metadata' => $metadataUuid, 'source' => $patchPortUuid, 'destination' => $switchLink['switch_port_uuid'], 'type' => 'Cat 6a']
        );
        $switchConnectionUuid = (string)(cli_first_row($rows)['uuid'] ?? '');
        if ($switchConnectionUuid === '') {
            cli_fail(500, 'Failed to create patchpanel to switch connection');
        }
        $switchAction = empty($replacedSwitch) ? 'created' : 'replaced';
    }
    cli_activate_metadata($db, $switchLink['switch_port_metadata_uuid'] ?? null);
    if ($switchConnectionUuid !== null) {
        $connectionRows = $db->db_query('SELECT metadata FROM connection WHERE uuid = :uuid LIMIT 1', ['uuid' => $switchConnectionUuid]);
        cli_activate_metadata($db, cli_first_row($connectionRows)['metadata'] ?? null);
    }
}

// ---- 6. Upsert patchpanel <-> outlet connection ----------------------------
$existingPhysical = cli_find_physical_connection($db, $patchPortUuid, $outletPortUuid);
$physicalAction = 'matched';
$physicalConnectionUuid = null;
$replacedPhysical = [];

if ($existingPhysical !== null) {
    $physicalConnectionUuid = (string)$existingPhysical['uuid'];
} else {
    $conflicts = cli_find_patch_outlet_conflicts($db, $patchPortUuid, $outletPortUuid);
    if (!$overwrite && !empty($conflicts)) {
        cli_fail(409, 'Existing connection conflicts with CLI record', ['conflicts' => $conflicts]);
    }
    foreach ($conflicts as $conflictUuid) {
        $db->db_query('DELETE FROM connection WHERE uuid = :uuid', ['uuid' => $conflictUuid]);
        $replacedPhysical[] = $conflictUuid;
    }
    $metadataUuid = cli_create_metadata(
        $db,
        sprintf('CLI: %s ↔ %s', $patchPort['port_caption'] ?? $recordedOutletPort, $recordedOutletPort),
        $userUuid
    );
    $rows = $db->db_query(
        'INSERT INTO connection (metadata, device_port_source, device_port_destination, type) VALUES (:metadata, :source, :destination, :type) RETURNING uuid',
        ['metadata' => $metadataUuid, 'source' => $patchPortUuid, 'destination' => $outletPortUuid, 'type' => 'Cat 6a']
    );
    $physicalConnectionUuid = (string)(cli_first_row($rows)['uuid'] ?? '');
    if ($physicalConnectionUuid === '') {
        cli_fail(500, 'Failed to create patchpanel to outlet connection');
    }
    $physicalAction = empty($replacedPhysical) ? 'created' : 'replaced';
}
cli_activate_metadata($db, $patchPort['port_metadata_uuid'] ?? null);
cli_activate_metadata($db, $recordedOutletRow['port_metadata_uuid'] ?? null);
if ($physicalConnectionUuid !== null) {
    $connectionRows = $db->db_query('SELECT metadata FROM connection WHERE uuid = :uuid LIMIT 1', ['uuid' => $physicalConnectionUuid]);
    cli_activate_metadata($db, cli_first_row($connectionRows)['metadata'] ?? null);
}

// ---- 7. Optional expected end-device ---------------------------------------
$deviceResult = null;
if (is_array($body['expected_device'] ?? null)) {
    $expected = $body['expected_device'];
    $deviceCaption = trim((string)($expected['caption'] ?? ''));
    $deviceType = trim((string)($expected['type'] ?? 'notebook'));
    $deviceMac = strtolower(trim((string)($expected['mac'] ?? '')));

    if ($deviceCaption !== '') {
        $deviceRows = $db->db_query(
            "SELECT d.uuid, d.metadata, d.location, d.type, m.caption
               FROM device d
               JOIN metadata m ON m.uuid = d.metadata
              WHERE LOWER(m.caption) = LOWER(:caption)
              LIMIT 1",
            ['caption' => $deviceCaption]
        );
        $deviceRow = cli_first_row($deviceRows);
        $deviceCreated = false;

        if ($deviceRow === null) {
            $metadataUuid = cli_create_metadata($db, $deviceCaption, $userUuid);
            $rows = $db->db_query(
                'INSERT INTO device (metadata, location, type) VALUES (:metadata, :location, :type) RETURNING uuid',
                ['metadata' => $metadataUuid, 'location' => $room['uuid'] ?? null, 'type' => $deviceType]
            );
            $deviceUuid = (string)(cli_first_row($rows)['uuid'] ?? '');
            if ($deviceUuid === '') {
                cli_fail(500, 'Failed to create end device');
            }
            $deviceRow = [
                'uuid' => $deviceUuid,
                'metadata' => $metadataUuid,
                'location' => $room['uuid'] ?? null,
                'type' => $deviceType,
                'caption' => $deviceCaption,
            ];
            $deviceCreated = true;
        } else {
            if (($room['uuid'] ?? null) !== null && ($deviceRow['location'] ?? null) !== ($room['uuid'] ?? null)) {
                $db->db_query(
                    'UPDATE device SET location = :location WHERE uuid = :uuid',
                    ['location' => $room['uuid'], 'uuid' => $deviceRow['uuid']]
                );
            }
            if ((string)($deviceRow['type'] ?? '') !== $deviceType && $deviceType !== '') {
                $db->db_query(
                    'UPDATE device SET type = :type WHERE uuid = :uuid',
                    ['type' => $deviceType, 'uuid' => $deviceRow['uuid']]
                );
            }
            cli_activate_metadata($db, $deviceRow['metadata'] ?? null);
            if (!empty($deviceRow['metadata']) && (string)($deviceRow['caption'] ?? '') !== $deviceCaption) {
                $db->db_query(
                    'UPDATE metadata SET caption = :caption, status = 0 WHERE uuid = :uuid',
                    ['caption' => $deviceCaption, 'uuid' => $deviceRow['metadata']]
                );
            }
        }

        $portRows = $db->db_query(
            'SELECT uuid FROM device_port WHERE device = :device ORDER BY uuid LIMIT 1',
            ['device' => $deviceRow['uuid']]
        );
        $portRow = cli_first_row($portRows);
        if ($portRow === null) {
            $portMetadataUuid = cli_create_metadata($db, 'eth0', $userUuid);
            $rows = $db->db_query(
                'INSERT INTO device_port (device, metadata, mac_address, type) VALUES (:device, :metadata, :mac, :type) RETURNING uuid',
                ['device' => $deviceRow['uuid'], 'metadata' => $portMetadataUuid, 'mac' => ($deviceMac !== '' ? $deviceMac : null), 'type' => 10]
            );
            $devicePortUuid = (string)(cli_first_row($rows)['uuid'] ?? '');
            if ($devicePortUuid === '') {
                cli_fail(500, 'Failed to create end device port');
            }
        } else {
            $devicePortUuid = (string)$portRow['uuid'];
            $portMetaRows = $db->db_query('SELECT metadata FROM device_port WHERE uuid = :uuid LIMIT 1', ['uuid' => $devicePortUuid]);
            cli_activate_metadata($db, cli_first_row($portMetaRows)['metadata'] ?? null);
            if ($deviceMac !== '') {
                $db->db_query(
                    'UPDATE device_port SET mac_address = :mac WHERE uuid = :uuid',
                    ['mac' => $deviceMac, 'uuid' => $devicePortUuid]
                );
            }
        }

        $existingExpected = cli_find_office_connection($db, $outletPortUuid, $devicePortUuid);
        $expectedConnectionUuid = null;
        $expectedAction = 'matched';
        $replacedExpected = [];
        if ($existingExpected !== null) {
            $expectedConnectionUuid = (string)$existingExpected['uuid'];
            $db->db_query(
                'UPDATE connection
                    SET device_port_source = :source,
                        device_port_destination = :destination,
                        expected_device_port_source = :source,
                        expected_device_port_destination = :destination
                  WHERE uuid = :uuid',
                ['source' => $outletPortUuid, 'destination' => $devicePortUuid, 'uuid' => $expectedConnectionUuid]
            );
        } else {
            $conflicts = cli_find_office_conflicts($db, $outletPortUuid, $devicePortUuid);
            foreach ($conflicts as $conflict) {
                if (!empty($conflict['uuid'])) {
                    $db->db_query('DELETE FROM connection WHERE uuid = :uuid', ['uuid' => $conflict['uuid']]);
                    $replacedExpected[] = (string)$conflict['uuid'];
                }
            }
            $metadataUuid = cli_create_metadata(
                $db,
                sprintf('CLI office: %s ↔ %s', $recordedOutletPort, $deviceCaption),
                $userUuid
            );
            $rows = $db->db_query(
                'INSERT INTO connection (metadata, device_port_source, device_port_destination, expected_device_port_source, expected_device_port_destination, type) VALUES (:metadata, :source, :destination, :source, :destination, :type) RETURNING uuid',
                ['metadata' => $metadataUuid, 'source' => $outletPortUuid, 'destination' => $devicePortUuid, 'type' => 'Cat 6a']
            );
            $expectedConnectionUuid = (string)(cli_first_row($rows)['uuid'] ?? '');
            if ($expectedConnectionUuid === '') {
                cli_fail(500, 'Failed to create office device connection');
            }
            $expectedAction = empty($replacedExpected) ? 'created' : 'replaced';
        }
        if ($expectedConnectionUuid !== null) {
            $connMetaRows = $db->db_query('SELECT metadata FROM connection WHERE uuid = :uuid LIMIT 1', ['uuid' => $expectedConnectionUuid]);
            cli_activate_metadata($db, cli_first_row($connMetaRows)['metadata'] ?? null);
        }

        $deviceResult = [
            'device_uuid' => $deviceRow['uuid'],
            'device_created' => $deviceCreated,
            'device_port_uuid' => $devicePortUuid,
            'connection_uuid' => $expectedConnectionUuid,
            'connection_action' => $expectedAction,
            'expected_connection_uuid' => $expectedConnectionUuid,
            'expected_connection_action' => $expectedAction,
            'replaced_expected_connections' => $replacedExpected,
        ];
    }
}

// ---- 8. Journal entry ------------------------------------------------------
$journalText = sprintf(
    'CLI room record by %s: %s ↔ %s (%s)%s%s',
    $actor,
    $patchPort['port_caption'] ?? $recordedOutletPort,
    $recordedOutletPort,
    $physicalAction,
    $switchLink !== null ? sprintf(' + switch %s', $switchAction ?? 'matched') : '',
    $deviceResult !== null ? ' + office device' : ''
);
try {
    $metadataUuid = cli_create_metadata($db, 'cli_record_link', $userUuid, $journalText);
    if ($metadataUuid !== null && $physicalConnectionUuid !== null) {
        $db->db_query(
            "INSERT INTO journal (metadata, reference_table, reference_uuid) VALUES (:metadata, 'connection', :reference)",
            ['metadata' => $metadataUuid, 'reference' => $physicalConnectionUuid]
        );
    }
} catch (\Throwable $exception) {
    $logger->log('cli_record_link journal insert failed: ' . $exception->getMessage(), 2);
}

// ---- 9. Response -----------------------------------------------------------
echo json_encode([
    'ok' => true,
    'summary' => $journalText,
    'lldp' => $lldp,
    'room' => $room !== null ? ['uuid' => $room['uuid'], 'caption' => $room['caption']] : null,
    'link' => [
        'action' => $physicalAction,
        'connection_uuid' => $physicalConnectionUuid,
        'patchpanel_port' => ['uuid' => $patchPortUuid, 'caption' => $patchPort['port_caption'] ?? null],
        'outlet_device' => [
            'uuid' => $outletDevice['device_uuid'] ?? null,
            'caption' => $outletDevice['device_caption'] ?? null,
            'created' => (bool)$outletResult['created'],
        ],
        'outlet_port' => ['uuid' => $outletPortUuid, 'caption' => $recordedOutletPort],
        'reserved_outlet_ports' => array_values(array_filter($outletPorts, static fn(string $port): bool => $port !== $recordedOutletPort)),
        'replaced_conflicts' => $replacedPhysical,
    ],
    'switch_link' => $switchLink !== null ? [
        'action' => $switchAction,
        'connection_uuid' => $switchConnectionUuid,
        'switch_uuid' => $switchLink['switch_uuid'],
        'switch_caption' => $switchLink['switch_caption'],
        'switch_port' => ['uuid' => $switchLink['switch_port_uuid'], 'caption' => $switchLink['switch_port_caption']],
        'replaced_conflicts' => $replacedSwitch,
    ] : null,
    'expected_device' => $deviceResult,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

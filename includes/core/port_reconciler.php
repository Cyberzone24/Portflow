<?php

namespace Portflow\Core;

include_once __DIR__ . '/db_adapter.php';
include_once __DIR__ . '/logger.php';
include_once __DIR__ . '/snmp_naming.php';
include_once __DIR__ . '/cable_trace.php';

/**
 * Maps SNMP scan facts onto Portflow's "current" columns and the
 * device_port_snmp_state snapshot table. Never touches "expected_*" columns.
 *
 * MVP scope:
 * - Match SNMP ifName -> device_port via metadata.caption (Portflow caption == SNMP ifName).
 * - Update device_port.speed and device_port.mac_address on the matched port.
 * - Upsert device_port_snmp_state with last counters and "last_seen_active" derived from oper-status / counter delta.
 *
 * Follow-up work still exists around drift views and richer reconciliation,
 * but port matching now supports stack-member selection within item groups.
 */
class PortReconciler
{
    private const ACTIVE_INACTIVITY_DAYS_DEFAULT = 14;

    public function __construct(
        private DatabaseAdapter $db,
        private Logger $logger
    ) {
    }

    /**
     * @param array{
     *   run_uuid:string,
     *   switch_name:string,
     *   device_uuid:?string,
     *   item_group_uuid?:?string,
     *   interfaces:array<int|string,array<string,mixed>>,
     *   vlans?:array<int,string>,
     *   profile?:array<string,mixed>
     * } $facts
     * @return array{findings:int,unknown_interfaces:int,matched:int,matched_details:array,unknown_details:array}
     */
    public function reconcile(array $facts): array
    {
        $deviceUuid = $facts['device_uuid'] ?? null;
        $itemGroupUuid = $facts['item_group_uuid'] ?? null;
        if (($deviceUuid === null || $deviceUuid === '') && ($itemGroupUuid === null || $itemGroupUuid === '')) {
            $this->logger->log('PortReconciler: no device_uuid or item_group_uuid for switch ' . ($facts['switch_name'] ?? '?') . ', skipping', 2);
            return ['findings' => 0, 'unknown_interfaces' => count($facts['interfaces'] ?? []), 'matched' => 0, 'matched_details' => [], 'unknown_details' => []];
        }

        $devicePorts = $this->loadDevicePorts($deviceUuid, $itemGroupUuid);
        $portIndexes = $this->buildPortIndexes($devicePorts);
        $vlanUuidById = $this->loadVlanUuidIndex();
        $profile = is_array($facts['profile'] ?? null) ? $facts['profile'] : [];

        $matched = 0;
        $unknown = 0;
        $findings = 0;
        $matchedDetails = [];
        $unknownDetails = [];
        foreach ($facts['interfaces'] as $iface) {
            $ifName = (string)($iface['if_name'] ?? '');
            if ($ifName === '') {
                continue;
            }

            $caption = SnmpNaming::normalizePortName($ifName, $profile);
            $stackUnit = $this->extractStackUnit($ifName);
            $resolvedIndex = $this->resolvePortIndexForInterface($portIndexes, $deviceUuid, $stackUnit);
            $port = $this->matchPort($resolvedIndex['index'], $caption, (string)($iface['if_alias'] ?? ''));
            if ($port === null && $resolvedIndex['scope'] !== 'all') {
                $port = $this->matchPort($portIndexes['all'], $caption, (string)($iface['if_alias'] ?? ''));
            }
            if ($port === null) {
                $unknown++;
                $findings++;
                $unknownDetails[] = [
                    'if_index' => $iface['if_index'] ?? null,
                    'if_name'  => $ifName,
                    'if_alias' => (string)($iface['if_alias'] ?? ''),
                    'stack_unit' => $stackUnit,
                    'oper'     => $iface['if_oper_status'] ?? null,
                    'admin'    => $iface['if_admin_status'] ?? null,
                ];
                continue;
            }
            $matched++;
            $matchedDetails[] = [
                'device_port_uuid' => $port['uuid'],
                'device_uuid'      => $port['device_uuid'] ?? null,
                'device_caption'   => $port['device_caption'] ?? null,
                'caption'          => $port['caption'] ?? $caption,
                'if_index'         => $iface['if_index'] ?? null,
                'if_name'          => $ifName,
                'if_alias'         => (string)($iface['if_alias'] ?? ''),
                'stack_unit'       => $stackUnit,
                'speed'            => $iface['if_high_speed'] ?? null,
                'mac'              => $this->normalizeMac((string)($iface['if_phys_address'] ?? '')),
                'ip_address'       => (string)($iface['ip_address'] ?? ''),
                'admin'            => $iface['if_admin_status'] ?? null,
                'oper'             => $iface['if_oper_status'] ?? null,
                'pvid'             => $iface['pvid'] ?? null,
            ];

            $this->applyInterfaceFacts($port, $iface);
            $this->applyIpFacts($port, $iface);
            $this->applyMetadataStatus($port, $iface);
            $this->applyPortVlans($port, $iface, $vlanUuidById);
            $this->upsertSnmpState($port['uuid'], (string)$facts['run_uuid'], $iface);
        }

        // Build ifIndex -> port_uuid map from matched details for node tracking.
        $ifIndexToPortUuid = [];
        foreach ($matchedDetails as $md) {
            if (isset($md['if_index'], $md['device_port_uuid']) && $md['if_index'] !== null) {
                $ifIndexToPortUuid[(int)$md['if_index']] = (string)$md['device_port_uuid'];
            }
        }

        $nodesPersisted = 0;
        $nodes = $facts['nodes'] ?? [];
        if (is_array($nodes) && !empty($nodes) && !empty($ifIndexToPortUuid)) {
            $nodesPersisted = $this->applyNodeFacts($nodes, $ifIndexToPortUuid, (string)$facts['run_uuid']);
        }

        $neighborsPersisted = 0;
        $neighbors = $facts['neighbors'] ?? [];
        if (is_array($neighbors) && !empty($neighbors) && !empty($ifIndexToPortUuid)) {
            $neighborsPersisted = $this->applyNeighborFacts($neighbors, $ifIndexToPortUuid, (string)$facts['run_uuid']);
        }

        return [
            'findings' => $findings,
            'unknown_interfaces' => $unknown,
            'matched' => $matched,
            'matched_details' => $matchedDetails,
            'unknown_details' => $unknownDetails,
            'nodes_persisted' => $nodesPersisted,
            'neighbors_persisted' => $neighborsPersisted,
        ];
    }

    /**
    * @return array<int,array<string,mixed>>
     */
    private function loadDevicePorts(?string $deviceUuid, ?string $itemGroupUuid = null): array
    {
        // Resolve effective device list: if item_group given, take all members of that group
        // PLUS the explicit device (if any). Otherwise just the single device.
        $deviceUuids = [];
        if ($itemGroupUuid !== null && $itemGroupUuid !== '') {
            $rows = $this->db->db_query(
                "SELECT uuid FROM device WHERE item_group = :ig",
                ['ig' => $itemGroupUuid]
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $u = trim((string)($r['uuid'] ?? ''));
                    if ($u !== '') {
                        $deviceUuids[$u] = true;
                    }
                }
            }
        }
        if ($deviceUuid !== null && $deviceUuid !== '') {
            $deviceUuids[$deviceUuid] = true;
        }

        if (empty($deviceUuids)) {
            return [];
        }

        // Build IN-list with named params (PDO does not expand arrays).
        $params = [];
        $placeholders = [];
        $i = 0;
        foreach (array_keys($deviceUuids) as $u) {
            $key = 'd' . $i++;
            $placeholders[] = ':' . $key;
            $params[$key] = $u;
        }

        $rows = $this->db->db_query(
            "SELECT dp.uuid, dp.device AS device_uuid, dp.speed, dp.mac_address, dp.metadata AS metadata_uuid, "
            . "pm.caption, dm.caption AS device_caption, dm.tags AS device_tags "
            . "FROM device_port dp "
            . "LEFT JOIN metadata pm ON pm.uuid = dp.metadata "
            . "LEFT JOIN device d ON d.uuid = dp.device "
            . "LEFT JOIN metadata dm ON dm.uuid = d.metadata "
            . "WHERE dp.device IN (" . implode(',', $placeholders) . ")",
            $params
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int,array<string,mixed>> $devicePorts
     * @return array{all:array<string,mixed>,by_device:array<string,array<string,mixed>>,by_unit:array<int,array<string,mixed>>}
     */
    private function buildPortIndexes(array $devicePorts): array
    {
        $byDevice = [];
        $deviceMeta = [];
        foreach ($devicePorts as $row) {
            $deviceUuid = trim((string)($row['device_uuid'] ?? ''));
            if ($deviceUuid !== '') {
                $byDevice[$deviceUuid][] = $row;
                if (!isset($deviceMeta[$deviceUuid])) {
                    $deviceMeta[$deviceUuid] = [
                        'caption' => (string)($row['device_caption'] ?? ''),
                        'tags' => (string)($row['device_tags'] ?? ''),
                    ];
                }
            }
        }

        $byDeviceIndexes = [];
        foreach ($byDevice as $resolvedDeviceUuid => $rows) {
            $byDeviceIndexes[$resolvedDeviceUuid] = $this->buildPortAliasIndex($rows);
        }

        $unitOwners = [];
        foreach ($deviceMeta as $resolvedDeviceUuid => $meta) {
            foreach ($this->extractDeviceUnits((string)($meta['caption'] ?? ''), (string)($meta['tags'] ?? '')) as $unit) {
                if (isset($unitOwners[$unit]) && $unitOwners[$unit] !== $resolvedDeviceUuid) {
                    $unitOwners[$unit] = '';
                    continue;
                }
                $unitOwners[$unit] = $resolvedDeviceUuid;
            }
        }

        $byUnit = [];
        foreach ($unitOwners as $unit => $ownerDeviceUuid) {
            if ($ownerDeviceUuid === '' || !isset($byDeviceIndexes[$ownerDeviceUuid])) {
                continue;
            }
            $byUnit[(int)$unit] = $byDeviceIndexes[$ownerDeviceUuid];
        }

        return [
            'all' => $this->buildPortAliasIndex($devicePorts),
            'by_device' => $byDeviceIndexes,
            'by_unit' => $byUnit,
        ];
    }

    /**
     * @param array{all:array<string,mixed>,by_device:array<string,array<string,mixed>>,by_unit:array<int,array<string,mixed>>} $portIndexes
     * @return array{scope:string,index:array<string,mixed>}
     */
    private function resolvePortIndexForInterface(array $portIndexes, ?string $deviceUuid, ?int $stackUnit): array
    {
        if ($stackUnit !== null && isset($portIndexes['by_unit'][$stackUnit])) {
            return ['scope' => 'unit', 'index' => $portIndexes['by_unit'][$stackUnit]];
        }

        $deviceUuid = trim((string)$deviceUuid);
        if ($deviceUuid !== '' && isset($portIndexes['by_device'][$deviceUuid])) {
            return ['scope' => 'device', 'index' => $portIndexes['by_device'][$deviceUuid]];
        }

        return ['scope' => 'all', 'index' => $portIndexes['all']];
    }

    private function applyInterfaceFacts(array $port, array $iface): void
    {
        $updates = [];
        $params = ['uuid' => $port['uuid']];

        $speed = $iface['if_high_speed'] ?? null;
        if ($speed !== null) {
            $speedString = (string)$speed;
            if ((string)($port['speed'] ?? '') !== $speedString) {
                $updates[] = 'speed = :speed';
                $params['speed'] = $speedString;
            }
        }

        $mac = $this->normalizeMac((string)($iface['if_phys_address'] ?? ''));
        if ($mac !== '' && strcasecmp((string)($port['mac_address'] ?? ''), $mac) !== 0) {
            $updates[] = 'mac_address = :mac_address';
            $params['mac_address'] = $mac;
        }

        if ($updates === []) {
            return;
        }
        $sql = 'UPDATE device_port SET ' . implode(', ', $updates) . ' WHERE uuid = :uuid';
        $this->db->db_query($sql, $params);
    }

    private function applyIpFacts(array $port, array $iface): void
    {
        $ipAddress = trim((string)($iface['ip_address'] ?? ''));
        if ($ipAddress === '' || !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            return;
        }

        $hostname = trim((string)($iface['ip_hostname'] ?? ''));
        $dhcpAddress = array_key_exists('ip_dhcp_address', $iface) ? (bool)$iface['ip_dhcp_address'] : false;
        $devicePortIpUuid = trim((string)($port['device_port_ip'] ?? ''));

        if ($devicePortIpUuid === '') {
            $existing = $this->db->db_query(
                'SELECT device_port_ip FROM device_port WHERE uuid = :uuid LIMIT 1',
                ['uuid' => $port['uuid']]
            );
            $devicePortIpUuid = trim((string)($existing[0]['device_port_ip'] ?? ''));
        }

        if ($devicePortIpUuid === '') {
            $created = $this->db->db_query(
                'INSERT INTO device_port_ip (ip, hostname, dhcp_address) VALUES (:ip, :hostname, :dhcp_address) RETURNING uuid',
                [
                    'ip' => $ipAddress,
                    'hostname' => $hostname !== '' ? $hostname : null,
                    'dhcp_address' => $dhcpAddress,
                ]
            );
            $devicePortIpUuid = trim((string)($created[0]['uuid'] ?? ''));
            if ($devicePortIpUuid !== '') {
                $this->db->db_query(
                    'UPDATE device_port SET device_port_ip = :device_port_ip WHERE uuid = :uuid',
                    ['device_port_ip' => $devicePortIpUuid, 'uuid' => $port['uuid']]
                );
            }
            return;
        }

        $updates = ['ip = :ip', 'dhcp_address = :dhcp_address'];
        $params = [
            'uuid' => $devicePortIpUuid,
            'ip' => $ipAddress,
            'dhcp_address' => $dhcpAddress,
        ];
        if ($hostname !== '') {
            $updates[] = 'hostname = :hostname';
            $params['hostname'] = $hostname;
        }

        $this->db->db_query(
            'UPDATE device_port_ip SET ' . implode(', ', $updates) . ' WHERE uuid = :uuid',
            $params
        );
    }

    /**
     * Map IF-MIB ifAdminStatus / ifOperStatus onto Portflow metadata.status code.
     *  0 = Aktiv, 2 = Deaktiviert (admin down), 4 = Offline (admin up but oper down).
     * Leaves status untouched when oper is unknown to avoid clobbering manual values
     * for ports we couldn't probe.
     */
    private function applyMetadataStatus(array $port, array $iface): void
    {
        $metadataUuid = (string)($port['metadata_uuid'] ?? '');
        if ($metadataUuid === '') {
            return;
        }
        $admin = $iface['if_admin_status'] ?? null;
        $oper  = $iface['if_oper_status'] ?? null;
        if ($oper === null && $admin === null) {
            return;
        }
        if ((int)$admin === 2) {
            $newStatus = 2; // Deaktiviert
        } elseif ((int)$oper === 1) {
            $newStatus = 0; // Aktiv
        } elseif ((int)$oper === 2) {
            $newStatus = 4; // Offline
        } else {
            return;
        }
        $this->db->db_query(
            'UPDATE metadata SET status = :status, changed = CURRENT_TIMESTAMP WHERE uuid = :uuid',
            ['status' => $newStatus, 'uuid' => $metadataUuid]
        );
    }

    /**
     * Reconcile native (untagged) and tagged VLAN membership into device_port_vlan.
     *
     * Inputs (from SnmpScanner):
     *  - $iface['untagged_vlan']         int|null  - native VLAN id, or null on trunk-only ports.
     *  - $iface['tagged_vlans']          int[]     - list of trunked VLAN ids.
     *  - $iface['vlan_membership_known'] bool      - true if Q-BRIDGE-MIB egress walk succeeded.
     *  - $iface['pvid']                  int|null  - legacy PVID (used as fallback only).
     *
     * Behaviour:
     *  - When membership is reliably known we fully sync untagged + tagged rows
     *    (including DELETE of stale rows on trunk-only ports).
     *  - When membership is NOT known we fall back to the previous PVID-only behaviour
     *    (write/refresh untagged row, never touch tagged rows or delete anything).
     *
     * Only the `vlan` and `tagged` columns are touched, never `expected_*`.
     *
     * @param array<int,string> $vlanUuidById vlanId -> vlan.uuid
     */
    private function applyPortVlans(array $port, array $iface, array $vlanUuidById): void
    {
        $membershipKnown = !empty($iface['vlan_membership_known']);

        $untaggedVlan = isset($iface['untagged_vlan']) && $iface['untagged_vlan'] !== null
            ? (int)$iface['untagged_vlan']
            : 0;
        if (!$membershipKnown && $untaggedVlan === 0) {
            // Legacy fallback: use raw PVID as native VLAN.
            $untaggedVlan = isset($iface['pvid']) ? (int)$iface['pvid'] : 0;
        }
        $desiredUntaggedUuid = $untaggedVlan > 0 ? ($vlanUuidById[$untaggedVlan] ?? null) : null;

        // --- untagged row sync ---
        $existingUntagged = $this->db->db_query(
            'SELECT uuid, vlan FROM device_port_vlan WHERE device_port = :dp AND tagged = FALSE',
            ['dp' => $port['uuid']]
        );
        $existingUntagged = is_array($existingUntagged) ? $existingUntagged : [];

        if ($desiredUntaggedUuid === null) {
            // No native VLAN. Only delete stale rows when we trust the source (egress walk OK).
            if ($membershipKnown && !empty($existingUntagged)) {
                $this->db->db_query(
                    'DELETE FROM device_port_vlan WHERE device_port = :dp AND tagged = FALSE',
                    ['dp' => $port['uuid']]
                );
            }
        } else {
            if (!empty($existingUntagged)) {
                $row = $existingUntagged[0];
                if ((string)($row['vlan'] ?? '') !== $desiredUntaggedUuid) {
                    $this->db->db_query(
                        'UPDATE device_port_vlan SET vlan = :vlan WHERE uuid = :uuid',
                        ['vlan' => $desiredUntaggedUuid, 'uuid' => $row['uuid']]
                    );
                }
                for ($i = 1, $n = count($existingUntagged); $i < $n; $i++) {
                    $this->db->db_query(
                        'DELETE FROM device_port_vlan WHERE uuid = :uuid',
                        ['uuid' => $existingUntagged[$i]['uuid']]
                    );
                }
            } else {
                $this->db->db_query(
                    'INSERT INTO device_port_vlan (device_port, vlan, tagged) VALUES (:dp, :vlan, FALSE)',
                    ['dp' => $port['uuid'], 'vlan' => $desiredUntaggedUuid]
                );
            }
        }

        // --- tagged row sync (only when membership is reliably known) ---
        if (!$membershipKnown) {
            return;
        }
        $taggedSource = isset($iface['tagged_vlans']) && is_array($iface['tagged_vlans'])
            ? $iface['tagged_vlans']
            : [];
        $desiredTaggedUuids = [];
        foreach ($taggedSource as $vid) {
            $vid = (int)$vid;
            if ($vid <= 0) {
                continue;
            }
            $u = $vlanUuidById[$vid] ?? null;
            if ($u !== null) {
                $desiredTaggedUuids[$u] = true;
            }
        }

        $existingTagged = $this->db->db_query(
            'SELECT uuid, vlan FROM device_port_vlan WHERE device_port = :dp AND tagged = TRUE',
            ['dp' => $port['uuid']]
        );
        $existingByVlanUuid = [];
        if (is_array($existingTagged)) {
            foreach ($existingTagged as $r) {
                $existingByVlanUuid[(string)$r['vlan']] = (string)$r['uuid'];
            }
        }

        foreach (array_keys($desiredTaggedUuids) as $u) {
            if (!isset($existingByVlanUuid[$u])) {
                $this->db->db_query(
                    'INSERT INTO device_port_vlan (device_port, vlan, tagged) VALUES (:dp, :vlan, TRUE)',
                    ['dp' => $port['uuid'], 'vlan' => $u]
                );
            }
        }
        foreach ($existingByVlanUuid as $vlanUuid => $rowUuid) {
            if (!isset($desiredTaggedUuids[$vlanUuid])) {
                $this->db->db_query(
                    'DELETE FROM device_port_vlan WHERE uuid = :uuid',
                    ['uuid' => $rowUuid]
                );
            }
        }
    }

    /**
     * @return array<int,string> vlan_id -> vlan.uuid (numeric vlan ids only)
     */
    private function loadVlanUuidIndex(): array
    {
        $rows = $this->db->db_query('SELECT uuid, vlan FROM vlan', []);
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $vid = (int)($r['vlan'] ?? 0);
                if ($vid > 0 && !isset($map[$vid])) {
                    $map[$vid] = (string)$r['uuid'];
                }
            }
        }
        return $map;
    }

    private function upsertSnmpState(string $portUuid, string $runUuid, array $iface): void
    {
        $existing = $this->db->db_query(
            'SELECT uuid, last_in_octets, last_out_octets, last_seen_active FROM device_port_snmp_state WHERE device_port = :device_port',
            ['device_port' => $portUuid]
        );

        $newIn = $iface['if_hc_in_octets'] ?? null;
        $newOut = $iface['if_hc_out_octets'] ?? null;
        $operStatus = $iface['if_oper_status'] ?? null;
        $today = date('Y-m-d');

        $isActive = ($operStatus === 1);
        if (!empty($existing)) {
            $prev = $existing[0];
            if ($newIn !== null && (string)$prev['last_in_octets'] !== '' && $this->numericGreater((string)$newIn, (string)$prev['last_in_octets'])) {
                $isActive = true;
            }
            if (!$isActive && $newOut !== null && (string)$prev['last_out_octets'] !== '' && $this->numericGreater((string)$newOut, (string)$prev['last_out_octets'])) {
                $isActive = true;
            }
            $lastSeen = $isActive ? $today : ($prev['last_seen_active'] ?? null);

            $this->db->db_query(
                "UPDATE device_port_snmp_state SET last_scan_run=:run, if_index=:idx, if_name=:name, if_alias=:alias, "
                . "if_admin_status=:admin, if_oper_status=:oper, if_last_change_ticks=:last_change, "
                . "last_in_octets=:in_octets, last_out_octets=:out_octets, last_seen_active=:last_seen, "
                . "updated=CURRENT_TIMESTAMP WHERE uuid=:uuid",
                [
                    'run' => $runUuid !== '' ? $runUuid : null,
                    'idx' => $iface['if_index'] ?? null,
                    'name' => (string)($iface['if_name'] ?? ''),
                    'alias' => (string)($iface['if_alias'] ?? ''),
                    'admin' => $iface['if_admin_status'] ?? null,
                    'oper' => $operStatus,
                    'last_change' => $iface['if_last_change'] ?? null,
                    'in_octets' => $newIn,
                    'out_octets' => $newOut,
                    'last_seen' => $lastSeen,
                    'uuid' => $prev['uuid'],
                ]
            );
            return;
        }

        $lastSeen = $isActive ? $today : null;
        $this->db->db_query(
            "INSERT INTO device_port_snmp_state (device_port, last_scan_run, if_index, if_name, if_alias, "
            . "if_admin_status, if_oper_status, if_last_change_ticks, last_in_octets, last_out_octets, last_seen_active) "
            . "VALUES (:device_port, :run, :idx, :name, :alias, :admin, :oper, :last_change, :in_octets, :out_octets, :last_seen)",
            [
                'device_port' => $portUuid,
                'run' => $runUuid !== '' ? $runUuid : null,
                'idx' => $iface['if_index'] ?? null,
                'name' => (string)($iface['if_name'] ?? ''),
                'alias' => (string)($iface['if_alias'] ?? ''),
                'admin' => $iface['if_admin_status'] ?? null,
                'oper' => $operStatus,
                'last_change' => $iface['if_last_change'] ?? null,
                'in_octets' => $newIn,
                'out_octets' => $newOut,
                'last_seen' => $lastSeen,
            ]
        );
    }

    /**
     * Compare two unsigned-integer-as-string values without requiring ext-bcmath.
     * Falls back to gmp, then to length-then-lexicographic compare for arbitrary precision.
     */
    private function numericGreater(string $a, string $b): bool
    {
        if (function_exists('bccomp')) {
            return \bccomp($a, $b) > 0;
        }
        if (function_exists('gmp_cmp')) {
            return \gmp_cmp($a, $b) > 0;
        }
        $a = ltrim($a, '0'); if ($a === '') { $a = '0'; }
        $b = ltrim($b, '0'); if ($b === '') { $b = '0'; }
        if (strlen($a) !== strlen($b)) {
            return strlen($a) > strlen($b);
        }
        return strcmp($a, $b) > 0;
    }

    /**
    * Build an alias index for matching SNMP ifNames to Portflow port captions.
     * Each caption registers under several normalized keys so common variants match:
     *   - the full caption (lowercased, trimmed)
     *   - a "type-prefix-stripped" form keeping just slot/port digits with slashes (e.g. "0/0/1")
     *   - the trailing port number only (e.g. "1") -- only kept when unique among ports
     *
     * @param array<int,array<string,mixed>> $devicePorts
     * @return array{full:array<string,array<string,mixed>>,slot:array<string,array<string,mixed>|false>,tail:array<string,array<string,mixed>|false>}
     */
    private function buildPortAliasIndex(array $devicePorts): array
    {
        $full = [];
        $slot = [];
        $tail = [];
        foreach ($devicePorts as $row) {
            $caption = (string)($row['caption'] ?? '');
            if (trim($caption) === '') {
                continue;
            }
            $full[strtolower(trim($caption))] = $row;

            $slotKey = $this->stripToSlotForm($caption);
            if ($slotKey !== '') {
                if (array_key_exists($slotKey, $slot)) {
                    $slot[$slotKey] = false; // ambiguous -> disable
                } else {
                    $slot[$slotKey] = $row;
                }
            }

            $tailKey = $this->extractTrailingNumber($caption);
            if ($tailKey !== '') {
                if (array_key_exists($tailKey, $tail)) {
                    $tail[$tailKey] = false;
                } else {
                    $tail[$tailKey] = $row;
                }
            }
        }
        return ['full' => $full, 'slot' => $slot, 'tail' => $tail];
    }

    private function extractStackUnit(string $ifName): ?int
    {
        $ifName = trim($ifName);
        if ($ifName === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z][A-Za-z0-9-]*\s*(\d+)(?:\/\d+){1,}\b/', $ifName, $matches)) {
            return null;
        }

        $unit = (int)($matches[1] ?? 0);
        return $unit > 0 ? $unit : null;
    }

    /**
     * @return array<int>
     */
    private function extractDeviceUnits(string $deviceCaption, string $deviceTags): array
    {
        $units = [];
        $haystacks = [$deviceCaption, $deviceTags];
        $patterns = [
            '/\bunit[-\s]?(\d+)\b/i',
            '/\bmember[-\s]?(\d+)\b/i',
            '/\bstack\s*(\d+)\b/i',
            '/\bstack\s*[:#-]?\s*(\d+)\b/i',
        ];

        foreach ($haystacks as $haystack) {
            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $haystack, $matches)) {
                    continue;
                }
                foreach (($matches[1] ?? []) as $match) {
                    $unit = (int)$match;
                    if ($unit > 0) {
                        $units[$unit] = $unit;
                    }
                }
            }
        }

        return array_values($units);
    }

    private function matchPort(array $portIndex, string $caption, string $ifAlias = ''): ?array
    {
        $candidates = [];
        $candidates[] = strtolower(trim($caption));
        if ($ifAlias !== '') {
            $candidates[] = strtolower(trim($ifAlias));
        }
        // Try full match first.
        foreach ($candidates as $key) {
            if ($key !== '' && isset($portIndex['full'][$key])) {
                return $portIndex['full'][$key];
            }
        }
        // Try slot/port form (strip type prefix).
        foreach ([$caption, $ifAlias] as $src) {
            $slotKey = $this->stripToSlotForm($src);
            if ($slotKey !== '' && isset($portIndex['slot'][$slotKey]) && $portIndex['slot'][$slotKey] !== false) {
                return $portIndex['slot'][$slotKey];
            }
        }
        // Try trailing port number as last resort (only if unambiguous).
        foreach ([$caption, $ifAlias] as $src) {
            $tailKey = $this->extractTrailingNumber($src);
            if ($tailKey !== '' && isset($portIndex['tail'][$tailKey]) && $portIndex['tail'][$tailKey] !== false) {
                return $portIndex['tail'][$tailKey];
            }
        }
        return null;
    }

    /**
     * Extract a slot/port form like "0/0/1" or "1/2" from an interface name,
     * stripping any leading word (e.g. "GigabitEthernet0/0/1" -> "0/0/1",
     * "Gi1/0/24" -> "1/0/24", "10GE2/0/1" -> "2/0/1").
     */
    private function stripToSlotForm(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (preg_match('@(\d+(?:/\d+)+)\b@', $name, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Return the trailing integer of a port name (e.g. "Port 24" -> "24",
     * "GigabitEthernet0/0/1" -> "1"). Empty string if none.
     */
    private function extractTrailingNumber(string $name): string
    {
        if (preg_match('@(\d+)\s*$@', trim($name), $m)) {
            return $m[1];
        }
        return '';
    }

    private function normalizeMac(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // SNMP often returns "Hex-STRING: aa bb cc dd ee ff" or already-formatted "aa:bb:cc:dd:ee:ff".
        if (stripos($raw, 'hex-string:') === 0) {
            $raw = trim(substr($raw, strlen('hex-string:')));
        }
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $raw) ?? '');
        if (strlen($hex) !== 12) {
            return '';
        }
        return implode(':', str_split($hex, 2));
    }

    /**
    * Persist FDB nodes (MAC-Adressen pro Port) mit upsert-Semantik.
    * Insert bei (device_port, mac) erstmals; Update last_seen + vlan + last_scan_run sonst.
     *
    * @param array<int,array{mac:string,vlan:?int,if_index:?int,bridge_port:int,ip?:string,hostname?:string}> $nodes
     * @param array<int,string> $ifIndexToPortUuid
     */
    private function applyNodeFacts(array $nodes, array $ifIndexToPortUuid, string $runUuid): int
    {
        if (empty($ifIndexToPortUuid)) {
            return 0;
        }
        $mirrorTargets = $this->loadConnectedNodeMirrorTargets(array_values($ifIndexToPortUuid));
        $persisted = 0;
        foreach ($nodes as $node) {
            $ifIndex = $node['if_index'] ?? null;
            if ($ifIndex === null || !isset($ifIndexToPortUuid[(int)$ifIndex])) {
                continue;
            }
            $portUuid = $ifIndexToPortUuid[(int)$ifIndex];
            $mac = strtolower((string)($node['mac'] ?? ''));
            if ($mac === '') {
                continue;
            }
            $vlan = isset($node['vlan']) && $node['vlan'] !== null ? (int)$node['vlan'] : null;
            $ipAddress = trim((string)($node['ip'] ?? ''));
            if ($ipAddress !== '' && !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                $ipAddress = '';
            }
            $hostname = trim((string)($node['hostname'] ?? ''));
            $targetPortUuids = [$portUuid];
            if (isset($mirrorTargets[$portUuid]) && $mirrorTargets[$portUuid] !== $portUuid) {
                $targetPortUuids[] = $mirrorTargets[$portUuid];
            }

            foreach (array_values(array_unique($targetPortUuids)) as $targetPortUuid) {
                if ($this->persistNodeObservation($targetPortUuid, $mac, $vlan, $ipAddress, $hostname, $runUuid)) {
                    $persisted++;
                }
            }
        }
        return $persisted;
    }

    /**
     * @param array<int,string> $portUuids
     * @return array<string,string> observed switch port uuid -> connected endpoint port uuid
     */
    private function loadConnectedNodeMirrorTargets(array $portUuids): array
    {
        $portUuids = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $portUuids), static fn(string $value): bool => $value !== ''));
        if (empty($portUuids)) {
            return [];
        }
        $targets = [];
        $tracer = new CableTrace($this->db);
        foreach (array_values(array_unique($portUuids)) as $observedPortUuid) {
            $trace = $tracer->trace('device_port', $observedPortUuid, ['max_hops' => 12]);
            if (!empty($trace['error']) || !is_array($trace['branches'] ?? null)) {
                continue;
            }

            $endpointCandidates = [];
            foreach ($trace['branches'] as $branch) {
                if (!is_array($branch) || empty($branch)) {
                    continue;
                }
                $lastHop = $branch[count($branch) - 1];
                $portNode = is_array($lastHop['port'] ?? null) ? $lastHop['port'] : null;
                if ($portNode === null) {
                    continue;
                }
                $endpointPortUuid = trim((string)($portNode['uuid'] ?? ''));
                if ($endpointPortUuid === '' || $endpointPortUuid === $observedPortUuid) {
                    continue;
                }

                $deviceNode = is_array($portNode['device'] ?? null) ? $portNode['device'] : [];
                $deviceType = trim((string)($deviceNode['type'] ?? ''));
                $deviceCaption = trim((string)($deviceNode['caption'] ?? ''));
                if (in_array(strtolower($deviceType), CableTrace::PASSTHROUGH_DEVICE_TYPES, true)) {
                    continue;
                }
                $snmpNode = is_array($portNode['snmp'] ?? null) ? $portNode['snmp'] : [];
                $hasOwnSnmpState = trim((string)($snmpNode['if_name'] ?? '')) !== '' || trim((string)($snmpNode['updated'] ?? '')) !== '';
                if ($hasOwnSnmpState) {
                    continue;
                }
                if ($this->isLikelyInfrastructureDevice($deviceType, $deviceCaption)) {
                    continue;
                }

                $endpointCandidates[$endpointPortUuid] = $endpointPortUuid;
            }

            if (count($endpointCandidates) !== 1) {
                continue;
            }
            $targets[$observedPortUuid] = array_values($endpointCandidates)[0];
        }

        return $targets;
    }

    private function isLikelyInfrastructureDevice(string $deviceType, string $deviceCaption): bool
    {
        $haystack = strtolower(trim($deviceType . ' ' . $deviceCaption));
        if ($haystack === '') {
            return false;
        }

        return preg_match('/\b(switch|router|firewall|patch\s*panel|patchpanel|uplink|trunk|access\s*point|ap\b|bridge|gateway|distribution|core)\b/i', $haystack) === 1;
    }

    private function persistNodeObservation(string $portUuid, string $mac, ?int $vlan, string $ipAddress, string $hostname, string $runUuid): bool
    {
        try {
            $existing = $this->db->db_query(
                'SELECT uuid FROM device_port_node WHERE device_port = :dp AND mac_address = :mac LIMIT 1',
                ['dp' => $portUuid, 'mac' => $mac]
            );
            if (!empty($existing)) {
                $updates = ['last_seen = CURRENT_TIMESTAMP', 'vlan = COALESCE(:vlan, vlan)', 'last_scan_run = :run'];
                $params = ['vlan' => $vlan, 'run' => $runUuid !== '' ? $runUuid : null, 'uuid' => $existing[0]['uuid']];
                if ($ipAddress !== '') {
                    $updates[] = 'ip = :ip';
                    $params['ip'] = $ipAddress;
                }
                if ($hostname !== '') {
                    $updates[] = 'hostname = :hostname';
                    $params['hostname'] = $hostname;
                }
                $this->db->db_query(
                    'UPDATE device_port_node SET ' . implode(', ', $updates) . ' WHERE uuid = :uuid',
                    $params
                );
            } else {
                $this->db->db_query(
                    'INSERT INTO device_port_node (device_port, mac_address, vlan, ip, hostname, last_scan_run) VALUES (:dp, :mac, :vlan, :ip, :hostname, :run)',
                    [
                        'dp' => $portUuid,
                        'mac' => $mac,
                        'vlan' => $vlan,
                        'ip' => $ipAddress !== '' ? $ipAddress : null,
                        'hostname' => $hostname !== '' ? $hostname : null,
                        'run' => $runUuid !== '' ? $runUuid : null,
                    ]
                );
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->log('PortReconciler: node upsert failed for ' . $mac . ' on port ' . $portUuid . ': ' . $e->getMessage(), 2);
            return false;
        }
    }

    /**
     * Persist LLDP neighbors per local port (upsert by device_port + remote_chassis_id + remote_port_id).
     *
     * @param array<int,array{local_if_index:int,chassis_id:string,port_id:string,port_desc:string,sys_name:string}> $neighbors
     * @param array<int,string> $ifIndexToPortUuid
     */
    private function applyNeighborFacts(array $neighbors, array $ifIndexToPortUuid, string $runUuid): int
    {
        $persisted = 0;
        foreach ($neighbors as $n) {
            $ifIndex = (int)($n['local_if_index'] ?? 0);
            if ($ifIndex <= 0 || !isset($ifIndexToPortUuid[$ifIndex])) {
                continue;
            }
            $portUuid = $ifIndexToPortUuid[$ifIndex];
            $chassisId = (string)($n['chassis_id'] ?? '');
            $portId = (string)($n['port_id'] ?? '');
            if ($chassisId === '' && $portId === '') {
                continue;
            }
            try {
                $existing = $this->db->db_query(
                    'SELECT uuid FROM device_port_neighbor WHERE device_port=:dp AND remote_chassis_id=:c AND remote_port_id=:p LIMIT 1',
                    ['dp' => $portUuid, 'c' => $chassisId, 'p' => $portId]
                );
                if (!empty($existing)) {
                    $this->db->db_query(
                        'UPDATE device_port_neighbor SET last_seen=CURRENT_TIMESTAMP, remote_sys_name=:sn, remote_port_desc=:pd, last_scan_run=:run WHERE uuid=:uuid',
                        ['sn' => (string)($n['sys_name'] ?? ''), 'pd' => (string)($n['port_desc'] ?? ''), 'run' => $runUuid !== '' ? $runUuid : null, 'uuid' => $existing[0]['uuid']]
                    );
                } else {
                    $this->db->db_query(
                        'INSERT INTO device_port_neighbor (device_port, remote_sys_name, remote_chassis_id, remote_port_id, remote_port_desc, discovered_via, last_scan_run) '
                        . 'VALUES (:dp, :sn, :c, :p, :pd, \'lldp\', :run)',
                        ['dp' => $portUuid, 'sn' => (string)($n['sys_name'] ?? ''), 'c' => $chassisId, 'p' => $portId, 'pd' => (string)($n['port_desc'] ?? ''), 'run' => $runUuid !== '' ? $runUuid : null]
                    );
                }
                $persisted++;
            } catch (\Throwable $e) {
                $this->logger->log('PortReconciler: neighbor upsert failed on port ' . $portUuid . ': ' . $e->getMessage(), 2);
            }
        }
        return $persisted;
    }
}

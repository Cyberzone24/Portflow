<?php

namespace Portflow\Core;

include_once __DIR__ . '/db_adapter.php';
include_once __DIR__ . '/logger.php';
include_once __DIR__ . '/snmp_naming.php';

/**
 * Maps SNMP scan facts onto Portflow's "current" columns and the
 * device_port_snmp_state snapshot table. Never touches "expected_*" columns.
 *
 * MVP scope:
 * - Match SNMP ifName -> device_port via metadata.caption (Portflow caption == SNMP ifName).
 * - Update device_port.speed and device_port.mac_address on the matched port.
 * - Upsert device_port_snmp_state with last counters and "last_seen_active" derived from oper-status / counter delta.
 *
 * Out of scope for this MVP (covered in follow-up steps): stack member resolution,
 * VLAN write-back, IP/hostname write-back, drift report generation.
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

        $portsByCaption = $this->loadDevicePorts($deviceUuid, $itemGroupUuid);
        $portIndex = $this->buildPortAliasIndex($portsByCaption);
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
            $port = $this->matchPort($portIndex, $caption, (string)($iface['if_alias'] ?? ''));
            if ($port === null) {
                $unknown++;
                $findings++;
                $unknownDetails[] = [
                    'if_index' => $iface['if_index'] ?? null,
                    'if_name'  => $ifName,
                    'if_alias' => (string)($iface['if_alias'] ?? ''),
                    'oper'     => $iface['if_oper_status'] ?? null,
                    'admin'    => $iface['if_admin_status'] ?? null,
                ];
                continue;
            }
            $matched++;
            $matchedDetails[] = [
                'device_port_uuid' => $port['uuid'],
                'caption'          => $port['caption'] ?? $caption,
                'if_index'         => $iface['if_index'] ?? null,
                'if_name'          => $ifName,
                'if_alias'         => (string)($iface['if_alias'] ?? ''),
                'speed'            => $iface['if_high_speed'] ?? null,
                'mac'              => $this->normalizeMac((string)($iface['if_phys_address'] ?? '')),
                'admin'            => $iface['if_admin_status'] ?? null,
                'oper'             => $iface['if_oper_status'] ?? null,
                'pvid'             => $iface['pvid'] ?? null,
            ];

            $this->applyInterfaceFacts($port, $iface);
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
     * @return array<string,array<string,mixed>> caption-lowercased -> row
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
            "SELECT dp.uuid, dp.speed, dp.mac_address, dp.metadata AS metadata_uuid, m.caption "
            . "FROM device_port dp LEFT JOIN metadata m ON m.uuid = dp.metadata "
            . "WHERE dp.device IN (" . implode(',', $placeholders) . ")",
            $params
        );

        $byCaption = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $caption = trim((string)($row['caption'] ?? ''));
                if ($caption === '') {
                    continue;
                }
                $byCaption[strtolower($caption)] = $row;
            }
        }
        return $byCaption;
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
     * @param array<string,array<string,mixed>> $portsByCaption caption-lower -> row
     * @return array{full:array<string,array<string,mixed>>,slot:array<string,array<string,mixed>|false>,tail:array<string,array<string,mixed>|false>}
     */
    private function buildPortAliasIndex(array $portsByCaption): array
    {
        $full = [];
        $slot = [];
        $tail = [];
        foreach ($portsByCaption as $captionKey => $row) {
            $caption = (string)($row['caption'] ?? $captionKey);
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
     * @param array<int,array{mac:string,vlan:?int,if_index:?int,bridge_port:int}> $nodes
     * @param array<int,string> $ifIndexToPortUuid
     */
    private function applyNodeFacts(array $nodes, array $ifIndexToPortUuid, string $runUuid): int
    {
        if (empty($ifIndexToPortUuid)) {
            return 0;
        }
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

            try {
                $existing = $this->db->db_query(
                    'SELECT uuid FROM device_port_node WHERE device_port = :dp AND mac_address = :mac LIMIT 1',
                    ['dp' => $portUuid, 'mac' => $mac]
                );
                if (!empty($existing)) {
                    $this->db->db_query(
                        'UPDATE device_port_node SET last_seen = CURRENT_TIMESTAMP, vlan = COALESCE(:vlan, vlan), last_scan_run = :run WHERE uuid = :uuid',
                        ['vlan' => $vlan, 'run' => $runUuid !== '' ? $runUuid : null, 'uuid' => $existing[0]['uuid']]
                    );
                } else {
                    $this->db->db_query(
                        'INSERT INTO device_port_node (device_port, mac_address, vlan, last_scan_run) VALUES (:dp, :mac, :vlan, :run)',
                        ['dp' => $portUuid, 'mac' => $mac, 'vlan' => $vlan, 'run' => $runUuid !== '' ? $runUuid : null]
                    );
                }
                $persisted++;
            } catch (\Throwable $e) {
                $this->logger->log('PortReconciler: node upsert failed for ' . $mac . ' on port ' . $portUuid . ': ' . $e->getMessage(), 2);
            }
        }
        return $persisted;
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

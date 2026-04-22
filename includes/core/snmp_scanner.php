<?php

namespace Portflow\Core;

include_once __DIR__ . '/db_adapter.php';
include_once __DIR__ . '/automation_store.php';
include_once __DIR__ . '/logger.php';
include_once __DIR__ . '/snmp_client.php';
include_once __DIR__ . '/snmp_naming.php';
include_once __DIR__ . '/port_reconciler.php';

/**
 * Walks a switch via SNMP and feeds the discovered facts into PortReconciler.
 * Records each run in `snmp_scan_run` and per-port snapshots in `device_port_snmp_state`.
 */
class SnmpScanner
{
    /** Numeric IF-MIB / Q-BRIDGE-MIB OIDs used by the MVP scanner. */
    public const OID_IF_NAME           = '.1.3.6.1.2.1.31.1.1.1.1';
    public const OID_IF_ALIAS          = '.1.3.6.1.2.1.31.1.1.1.18';
    public const OID_IF_HIGH_SPEED     = '.1.3.6.1.2.1.31.1.1.1.15';
    public const OID_IF_ADMIN_STATUS   = '.1.3.6.1.2.1.2.2.1.7';
    public const OID_IF_OPER_STATUS    = '.1.3.6.1.2.1.2.2.1.8';
    public const OID_IF_PHYS_ADDRESS   = '.1.3.6.1.2.1.2.2.1.6';
    public const OID_IF_LAST_CHANGE    = '.1.3.6.1.2.1.2.2.1.9';
    public const OID_IF_HC_IN_OCTETS   = '.1.3.6.1.2.1.31.1.1.1.6';
    public const OID_IF_HC_OUT_OCTETS  = '.1.3.6.1.2.1.31.1.1.1.10';
    public const OID_DOT1Q_PVID        = '.1.3.6.1.2.1.17.7.1.4.5.1.1';
    /** Q-BRIDGE-MIB dot1qVlanStaticName: index = vlan-id, value = name. */
    public const OID_DOT1Q_VLAN_STATIC_NAME = '.1.3.6.1.2.1.17.7.1.4.3.1.1';
    /** Huawei VRP fallback: hwL2VlanDescription (.1.3.6.1.4.1.2011.5.25.42.1.4.1.1.5). */
    public const OID_HUAWEI_VLAN_DESC  = '.1.3.6.1.4.1.2011.5.25.42.1.4.1.1.5';
    /** Huawei legacy alt: hwVlanName. */
    public const OID_HUAWEI_VLAN_NAME  = '.1.3.6.1.4.1.2011.5.25.42.1.4.1.1.4';
    /** BRIDGE-MIB dot1dBasePortIfIndex: bridge port -> ifIndex. */
    public const OID_DOT1D_BASE_PORT_IFINDEX = '.1.3.6.1.2.1.17.1.4.1.2';
    /** Q-BRIDGE-MIB dot1qTpFdbPort: index = vlanId.mac(6 oct) -> bridge port. */
    public const OID_DOT1Q_TP_FDB_PORT = '.1.3.6.1.2.1.17.7.1.2.2.1.2';
    /** BRIDGE-MIB dot1dTpFdbPort: index = mac(6 oct) -> bridge port. */
    public const OID_DOT1D_TP_FDB_PORT = '.1.3.6.1.2.1.17.4.3.1.2';
    /** LLDP-MIB lldpRemTable column OIDs (.1.0.8802.1.1.2.1.4.1.1.X). */
    public const OID_LLDP_REM_CHASSIS_ID = '.1.0.8802.1.1.2.1.4.1.1.5';
    public const OID_LLDP_REM_PORT_ID    = '.1.0.8802.1.1.2.1.4.1.1.7';
    public const OID_LLDP_REM_PORT_DESC  = '.1.0.8802.1.1.2.1.4.1.1.8';
    public const OID_LLDP_REM_SYS_NAME   = '.1.0.8802.1.1.2.1.4.1.1.9';
    /** POWER-ETHERNET-MIB pethPsePortTable columns. */
    public const OID_PETH_ADMIN          = '.1.3.6.1.2.1.105.1.1.1.3';
    public const OID_PETH_DETECTION      = '.1.3.6.1.2.1.105.1.1.1.6';
    public const OID_PETH_POWER_CLASS    = '.1.3.6.1.2.1.105.1.1.1.10';
    public const OID_PETH_MAIN_CONSUMPTION = '.1.3.6.1.2.1.105.1.3.1.1.4';
    /** HUAWEI-POE-MIB hwPoePortTable columns (indexed by ifIndex). */
    public const OID_HW_POE_ENABLE       = '.1.3.6.1.4.1.2011.5.25.195.4.1.1.2';
    public const OID_HW_POE_POWER_STATUS = '.1.3.6.1.4.1.2011.5.25.195.4.1.1.5';
    public const OID_HW_POE_CONSUMING    = '.1.3.6.1.4.1.2011.5.25.195.4.1.1.7';
    public const OID_HW_POE_PD_CLASS     = '.1.3.6.1.4.1.2011.5.25.195.4.1.1.10';
    /** ENTITY-MIB entPhysicalTable columns. */
    public const OID_ENT_DESCR           = '.1.3.6.1.2.1.47.1.1.1.1.2';
    public const OID_ENT_CLASS           = '.1.3.6.1.2.1.47.1.1.1.1.5';
    public const OID_ENT_NAME            = '.1.3.6.1.2.1.47.1.1.1.1.7';
    public const OID_ENT_SERIAL          = '.1.3.6.1.2.1.47.1.1.1.1.11';
    public const OID_ENT_MODEL           = '.1.3.6.1.2.1.47.1.1.1.1.13';

    public function __construct(
        private DatabaseAdapter $db,
        private AutomationStore $store,
        private Logger $logger,
        private ?SnmpClient $client = null
    ) {
        if ($this->client === null) {
            $this->client = new SnmpClient($store, $logger);
        }
    }

    /**
     * Scan one switch and reconcile its port facts.
     *
     * @return array{ok:bool,run_uuid?:string,error?:string,findings:int,interfaces:int,vlans:int,debug?:array}
     */
    public function scanSwitch(string $switchName, string $trigger = 'manual', ?string $userUuid = null): array
    {
        $resolution = $this->client->resolveSwitchConfig($switchName);
        if (!$resolution['ok']) {
            return ['ok' => false, 'error' => $resolution['error'] ?? 'unbekannter SNMP-Konfigurationsfehler', 'findings' => 0, 'interfaces' => 0, 'vlans' => 0];
        }
        $config = $resolution['config'];
        $deviceUuid = $config['device_uuid'] !== '' ? $config['device_uuid'] : null;
        $itemGroupUuid = ($config['item_group_uuid'] ?? '') !== '' ? $config['item_group_uuid'] : null;

        $runUuid = $this->createRun($switchName, $deviceUuid, $trigger, $userUuid);

        try {
            $ifNameMap = $this->walkMap($config, self::OID_IF_NAME);
            if (empty($ifNameMap)) {
                // First (and most important) walk failed -- likely auth failure or unreachable.
                // Abort early instead of running ~18 more walks that will each time out.
                $msg = 'Initialer ifName-Walk lieferte keine Daten (Auth-Fehler oder Switch nicht erreichbar). Abbruch.';
                $this->logger->log('SNMP scan aborted for ' . $switchName . ': ' . $msg, 3);
                $this->finalizeRun($runUuid, 'failed', $msg, 0, 0, 0, ['error' => $msg]);
                return ['ok' => false, 'run_uuid' => $runUuid, 'error' => $msg, 'findings' => 0, 'interfaces' => 0, 'vlans' => 0];
            }
            $ifAliasMap = $this->walkMap($config, self::OID_IF_ALIAS);
            $ifSpeedMap = $this->walkMap($config, self::OID_IF_HIGH_SPEED);
            $ifAdminMap = $this->walkMap($config, self::OID_IF_ADMIN_STATUS);
            $ifOperMap  = $this->walkMap($config, self::OID_IF_OPER_STATUS);
            $ifMacMap   = $this->walkMap($config, self::OID_IF_PHYS_ADDRESS);
            $ifChangeMap = $this->walkMap($config, self::OID_IF_LAST_CHANGE);
            $ifInMap    = $this->walkMap($config, self::OID_IF_HC_IN_OCTETS);
            $ifOutMap   = $this->walkMap($config, self::OID_IF_HC_OUT_OCTETS);
            $pvidMap    = $this->walkMap($config, self::OID_DOT1Q_PVID);

            // VLAN list: try standard Q-BRIDGE-MIB first, fall back to Huawei MIBs.
            $vlanNameMap = $this->walkMap($config, self::OID_DOT1Q_VLAN_STATIC_NAME);
            $vlanSource  = 'Q-BRIDGE-MIB';
            if (empty($vlanNameMap)) {
                $vlanNameMap = $this->walkMap($config, self::OID_HUAWEI_VLAN_DESC);
                $vlanSource  = 'HUAWEI-VLAN-MIB::hwL2VlanDescription';
            }
            if (empty($vlanNameMap)) {
                $vlanNameMap = $this->walkMap($config, self::OID_HUAWEI_VLAN_NAME);
                $vlanSource  = 'HUAWEI-VLAN-MIB::hwVlanName';
            }
            // Add PVIDs as implicit VLAN ids (covers cases where VLAN table is hidden behind a different MIB).
            $vlanIds = [];
            foreach ($vlanNameMap as $vid => $name) {
                $vid = (int)$vid;
                if ($vid > 0) {
                    $vlanIds[$vid] = (string)$name;
                }
            }
            foreach ($pvidMap as $pvid) {
                $pvid = (int)$pvid;
                if ($pvid > 0 && !isset($vlanIds[$pvid])) {
                    $vlanIds[$pvid] = '';
                }
            }
            ksort($vlanIds, SORT_NUMERIC);

            // FDB / Node Tracking: bridge port -> ifIndex, then walk Q-BRIDGE / BRIDGE FDB.
            $bridgePortToIfIndex = $this->walkMap($config, self::OID_DOT1D_BASE_PORT_IFINDEX);
            $nodes = $this->walkFdb($config, $bridgePortToIfIndex);

            // LLDP topology neighbors.
            $neighbors = $this->walkLldp($config);

            // PoE per-port snapshot (POWER-ETHERNET-MIB).
            // pethPsePortTable is indexed by pethPsePortGroupIndex.pethPsePortIndex (two segments).
            $poeAdmin     = $this->walkMapCompound($config, self::OID_PETH_ADMIN, 2);
            $poeDetection = $this->walkMapCompound($config, self::OID_PETH_DETECTION, 2);
            $poeClass     = $this->walkMapCompound($config, self::OID_PETH_POWER_CLASS, 2);
            $poeSource    = 'POWER-ETHERNET-MIB';
            // If standard MIB is empty (common on Huawei), fall back to HUAWEI-POE-MIB (indexed by ifIndex).
            if (empty($poeAdmin)) {
                $hwEnable    = $this->walkMap($config, self::OID_HW_POE_ENABLE);
                $hwStatus    = $this->walkMap($config, self::OID_HW_POE_POWER_STATUS);
                $hwConsuming = $this->walkMap($config, self::OID_HW_POE_CONSUMING);
                $hwClass     = $this->walkMap($config, self::OID_HW_POE_PD_CLASS);
                if (!empty($hwEnable)) {
                    $poeSource = 'HUAWEI-POE-MIB';
                    foreach ($hwEnable as $ifIndex => $enable) {
                        $key = (string)$ifIndex;
                        $poeAdmin[$key]     = (int)$enable; // 1=enable, 2=disable (Huawei)
                        $poeDetection[$key] = isset($hwStatus[$ifIndex]) ? (int)$hwStatus[$ifIndex] : null;
                        $poeClass[$key]     = isset($hwClass[$ifIndex]) ? (int)$hwClass[$ifIndex] : null;
                    }
                }
            }
            $poePorts = [];
            foreach ($poeAdmin as $idx => $admin) {
                $poePorts[] = [
                    'port_idx'  => (string)$idx,
                    'admin'     => (int)$admin,
                    'detection' => isset($poeDetection[$idx]) ? (int)$poeDetection[$idx] : null,
                    'class'     => isset($poeClass[$idx]) ? (int)$poeClass[$idx] : null,
                ];
            }
            $poeMain = $this->walkMap($config, self::OID_PETH_MAIN_CONSUMPTION);

            // ENTITY-MIB physical inventory (modules, SFPs, chassis).
            $entDescr  = $this->walkMap($config, self::OID_ENT_DESCR);
            $entClass  = $this->walkMap($config, self::OID_ENT_CLASS);
            $entName   = $this->walkMap($config, self::OID_ENT_NAME);
            $entSerial = $this->walkMap($config, self::OID_ENT_SERIAL);
            $entModel  = $this->walkMap($config, self::OID_ENT_MODEL);
            $entityItems = [];
            foreach ($entDescr as $idx => $descr) {
                $entityItems[] = [
                    'idx'    => (int)$idx,
                    'class'  => isset($entClass[$idx]) ? (int)$entClass[$idx] : null,
                    'name'   => (string)($entName[$idx] ?? ''),
                    'descr'  => (string)$descr,
                    'serial' => trim((string)($entSerial[$idx] ?? '')),
                    'model'  => trim((string)($entModel[$idx] ?? '')),
                ];
            }

            $interfaces = [];
            foreach ($ifNameMap as $ifIndex => $ifName) {
                $interfaces[$ifIndex] = [
                    'if_index'         => (int)$ifIndex,
                    'if_name'          => (string)$ifName,
                    'if_alias'         => (string)($ifAliasMap[$ifIndex] ?? ''),
                    'if_high_speed'    => isset($ifSpeedMap[$ifIndex]) ? (int)$ifSpeedMap[$ifIndex] : null,
                    'if_admin_status'  => isset($ifAdminMap[$ifIndex]) ? (int)$ifAdminMap[$ifIndex] : null,
                    'if_oper_status'   => isset($ifOperMap[$ifIndex]) ? (int)$ifOperMap[$ifIndex] : null,
                    'if_phys_address'  => trim((string)($ifMacMap[$ifIndex] ?? '')),
                    'if_last_change'   => isset($ifChangeMap[$ifIndex]) ? (int)$ifChangeMap[$ifIndex] : null,
                    'if_hc_in_octets'  => isset($ifInMap[$ifIndex]) ? (string)$ifInMap[$ifIndex] : null,
                    'if_hc_out_octets' => isset($ifOutMap[$ifIndex]) ? (string)$ifOutMap[$ifIndex] : null,
                    'pvid'             => isset($pvidMap[$ifIndex]) ? (int)$pvidMap[$ifIndex] : null,
                ];
            }

            $reconciler = new PortReconciler($this->db, $this->logger);
            $result = $reconciler->reconcile([
                'run_uuid'        => $runUuid,
                'switch_name'     => $switchName,
                'device_uuid'     => $deviceUuid,
                'item_group_uuid' => $itemGroupUuid,
                'interfaces'      => $interfaces,
                'vlans'           => $vlanIds,
                'nodes'           => $nodes,
                'neighbors'       => $neighbors,
            ]);

            $vlanCount = count($vlanIds);
            $details = [
                'switch_name'        => $switchName,
                'device_uuid'        => $deviceUuid,
                'item_group_uuid'    => $itemGroupUuid,
                'vlan_source'        => $vlanSource,
                'vlans'              => array_map(
                    static fn($id, $name) => ['id' => (int)$id, 'name' => (string)$name],
                    array_keys($vlanIds),
                    array_values($vlanIds)
                ),
                'interfaces'         => array_values($interfaces),
                'matched_ports'      => $result['matched_details'] ?? [],
                'unknown_interfaces' => $result['unknown_details'] ?? [],
                'nodes_seen'         => count($nodes),
                'nodes_persisted'    => $result['nodes_persisted'] ?? 0,
                'neighbors'          => $neighbors,
                'neighbors_persisted' => $result['neighbors_persisted'] ?? 0,
                'poe_ports'          => $poePorts,
                'poe_source'         => $poeSource,
                'poe_main_consumption' => array_values($poeMain),
                'entity_inventory'   => $entityItems,
            ];

            $this->finalizeRun($runUuid, 'success', null, count($interfaces), $vlanCount, $result['findings'] ?? 0, $details);

            return [
                'ok' => true,
                'run_uuid' => $runUuid,
                'findings' => (int)($result['findings'] ?? 0),
                'interfaces' => count($interfaces),
                'vlans' => $vlanCount,
            ];
        } catch (\Throwable $e) {
            $this->logger->log('SNMP scan failed for ' . $switchName . ': ' . $e->getMessage(), 3);
            $this->finalizeRun($runUuid, 'failed', $e->getMessage(), 0, 0, 0, ['error' => $e->getMessage()]);
            return ['ok' => false, 'run_uuid' => $runUuid, 'error' => $e->getMessage(), 'findings' => 0, 'interfaces' => 0, 'vlans' => 0];
        }
    }

    /**
     * Walk one OID base and return ifIndex (last numeric component) => value.
     *
     * @return array<int|string,string>
     */
    private function walkMap(array $config, string $baseOid): array
    {
        $result = $this->client->runWalk($config, $baseOid);
        if (!$result['ok']) {
            $this->logger->log(
                sprintf('snmp walk %s failed (%d): %s', $baseOid, $result['exit_code'], implode(' | ', $result['lines'])),
                3
            );
            return [];
        }
        $parsed = SnmpClient::parseWalkLines($result['lines']);
        $map = [];
        foreach ($parsed as $oid => $value) {
            // Last dot-segment is the index for IF-MIB (single-key) tables.
            $lastDot = strrpos($oid, '.');
            if ($lastDot === false) {
                continue;
            }
            $index = substr($oid, $lastDot + 1);
            if ($index === '') {
                continue;
            }
            $map[$index] = $value;
        }
        return $map;
    }

    /**
     * Like walkMap() but keeps the trailing $segments dot-separated as the index.
     * Useful for tables with compound indexes (e.g. pethPsePortTable: group.port).
     *
     * @return array<string,string>
     */
    private function walkMapCompound(array $config, string $baseOid, int $segments): array
    {
        $result = $this->client->runWalk($config, $baseOid);
        if (!$result['ok']) {
            $this->logger->log(
                sprintf('snmp walk %s failed (%d): %s', $baseOid, $result['exit_code'], implode(' | ', $result['lines'])),
                3
            );
            return [];
        }
        $parsed = SnmpClient::parseWalkLines($result['lines']);
        $map = [];
        $baseLen = strlen($baseOid);
        foreach ($parsed as $oid => $value) {
            // Strip the base OID prefix and a leading dot, keep the remainder as compound index.
            if (strncmp($oid, $baseOid, $baseLen) === 0) {
                $rest = substr($oid, $baseLen);
                $rest = ltrim($rest, '.');
            } else {
                // Fallback: take last $segments dot-separated parts.
                $parts = explode('.', $oid);
                $rest = implode('.', array_slice($parts, -$segments));
            }
            if ($rest === '') {
                continue;
            }
            $map[$rest] = $value;
        }
        return $map;
    }

    /**
     * Walk forwarding database (Q-BRIDGE preferred, BRIDGE-MIB fallback) and return node entries.
     *
     * @param array<int|string,string> $bridgePortToIfIndex
     * @return array<int,array{mac:string,vlan:?int,if_index:?int,bridge_port:int}>
     */
    private function walkFdb(array $config, array $bridgePortToIfIndex): array
    {
        $entries = $this->collectFdbEntries($config, self::OID_DOT1Q_TP_FDB_PORT, true);
        if (empty($entries)) {
            $entries = $this->collectFdbEntries($config, self::OID_DOT1D_TP_FDB_PORT, false);
        }

        $nodes = [];
        foreach ($entries as $e) {
            $bridgePort = (int)$e['bridge_port'];
            if ($bridgePort <= 0) {
                continue;
            }
            $ifIndex = isset($bridgePortToIfIndex[$bridgePort]) ? (int)$bridgePortToIfIndex[$bridgePort] : null;
            $nodes[] = [
                'mac'         => $e['mac'],
                'vlan'        => $e['vlan'],
                'bridge_port' => $bridgePort,
                'if_index'    => $ifIndex,
            ];
        }
        return $nodes;
    }

    /**
     * @return array<int,array{mac:string,vlan:?int,bridge_port:int}>
     */
    private function collectFdbEntries(array $config, string $baseOid, bool $hasVlanPrefix): array
    {
        $result = $this->client->runWalk($config, $baseOid);
        if (!$result['ok']) {
            return [];
        }
        $parsed = SnmpClient::parseWalkLines($result['lines']);
        $baseLen = strlen($baseOid);
        $entries = [];
        foreach ($parsed as $oid => $value) {
            if (strpos($oid, $baseOid . '.') !== 0) {
                continue;
            }
            $tail = substr($oid, $baseLen + 1);
            $parts = explode('.', $tail);
            if ($hasVlanPrefix) {
                if (count($parts) !== 7) { continue; }
                $vlan = (int)array_shift($parts);
            } else {
                if (count($parts) !== 6) { continue; }
                $vlan = null;
            }
            $mac = strtolower(implode(':', array_map(static fn($n) => sprintf('%02x', (int)$n), $parts)));
            if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) { continue; }
            $firstOctet = hexdec(substr($mac, 0, 2));
            if (($firstOctet & 0x01) !== 0) { continue; }
            $bridgePort = (int)$value;
            if ($bridgePort <= 0) { continue; }
            $entries[] = ['mac' => $mac, 'vlan' => $vlan, 'bridge_port' => $bridgePort];
        }
        return $entries;
    }

    /**
     * Walk LLDP-MIB::lldpRemTable to get neighbor info per local port.
     * Index format: <timeMark>.<localPortNum>.<remIndex>.
     *
     * @return array<int,array{local_if_index:int,chassis_id:string,port_id:string,port_desc:string,sys_name:string}>
     */
    private function walkLldp(array $config): array
    {
        $sysNameMap   = $this->collectLldpColumn($config, self::OID_LLDP_REM_SYS_NAME);
        $portIdMap    = $this->collectLldpColumn($config, self::OID_LLDP_REM_PORT_ID);
        $portDescMap  = $this->collectLldpColumn($config, self::OID_LLDP_REM_PORT_DESC);
        $chassisMap   = $this->collectLldpColumn($config, self::OID_LLDP_REM_CHASSIS_ID);

        // Union of all index keys (e.g. "0.49.1") seen.
        $allKeys = array_unique(array_merge(
            array_keys($sysNameMap), array_keys($portIdMap), array_keys($portDescMap), array_keys($chassisMap)
        ));

        $neighbors = [];
        foreach ($allKeys as $key) {
            $parts = explode('.', $key);
            if (count($parts) !== 3) { continue; }
            $localPort = (int)$parts[1]; // lldpRemLocalPortNum -- on most modern kit equals ifIndex
            if ($localPort <= 0) { continue; }
            $neighbors[] = [
                'local_if_index' => $localPort,
                'chassis_id'     => trim((string)($chassisMap[$key] ?? '')),
                'port_id'        => trim((string)($portIdMap[$key] ?? '')),
                'port_desc'      => trim((string)($portDescMap[$key] ?? '')),
                'sys_name'       => trim((string)($sysNameMap[$key] ?? '')),
            ];
        }
        return $neighbors;
    }

    /**
     * Walk one LLDP column OID and return composite-index ("timeMark.localPort.remIndex") => value.
     *
     * @return array<string,string>
     */
    private function collectLldpColumn(array $config, string $baseOid): array
    {
        $result = $this->client->runWalk($config, $baseOid);
        if (!$result['ok']) {
            return [];
        }
        $parsed = SnmpClient::parseWalkLines($result['lines']);
        $baseLen = strlen($baseOid);
        $out = [];
        foreach ($parsed as $oid => $value) {
            if (strpos($oid, $baseOid . '.') !== 0) {
                continue;
            }
            $tail = substr($oid, $baseLen + 1);
            $out[$tail] = $value;
        }
        return $out;
    }

    private function createRun(string $switchName, ?string $deviceUuid, string $trigger, ?string $userUuid): string
    {
        // Guard against stale inventory references: ensure device UUID actually exists.
        if ($deviceUuid !== null && $deviceUuid !== '') {
            try {
                $check = $this->db->db_query(
                    "SELECT uuid FROM device WHERE uuid = :uuid LIMIT 1",
                    ['uuid' => $deviceUuid]
                );
                if (!is_array($check) || empty($check)) {
                    $this->logger->log(
                        'snmp_scan_run: device UUID ' . $deviceUuid . ' from inventory not found in device table -- inserting NULL',
                        2
                    );
                    $deviceUuid = null;
                }
            } catch (\Throwable $e) {
                $deviceUuid = null;
            }
        }

        // Guard against stale user UUID too.
        if ($userUuid !== null && $userUuid !== '') {
            try {
                $check = $this->db->db_query(
                    "SELECT uuid FROM users WHERE uuid = :uuid LIMIT 1",
                    ['uuid' => $userUuid]
                );
                if (!is_array($check) || empty($check)) {
                    $userUuid = null;
                }
            } catch (\Throwable $e) {
                $userUuid = null;
            }
        }

        $rows = $this->db->db_query(
            "INSERT INTO snmp_scan_run (switch_name, device, users, trigger, status) "
            . "VALUES (:switch_name, :device, :users, :trigger, 'running') RETURNING uuid",
            [
                'switch_name' => $switchName,
                'device' => $deviceUuid,
                'users' => $userUuid,
                'trigger' => $trigger,
            ]
        );
        return (string)($rows[0]['uuid'] ?? '');
    }

    private function finalizeRun(string $runUuid, string $status, ?string $message, int $interfaces, int $vlans, int $findings, ?array $details = null): void
    {
        if ($runUuid === '') {
            return;
        }
        $this->db->db_query(
            "UPDATE snmp_scan_run SET status=:status, message=:message, finished=CURRENT_TIMESTAMP, "
            . "interfaces_seen=:interfaces, vlans_seen=:vlans, findings_total=:findings, details=:details WHERE uuid=:uuid",
            [
                'status' => $status,
                'message' => $message,
                'interfaces' => $interfaces,
                'vlans' => $vlans,
                'findings' => $findings,
                'details' => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'uuid' => $runUuid,
            ]
        );
    }
}

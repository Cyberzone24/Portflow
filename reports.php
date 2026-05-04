<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

const APP_NAME = 'Portflow';

include_once __DIR__ . '/includes/core/session.php';
if (!in_array(__DIR__ . '/includes/core/session.php', get_included_files(), true)) {
    die('could not verify session');
}

include_once __DIR__ . '/includes/core/db_adapter.php';
include_once __DIR__ . '/includes/core/pending_changes_queue.php';
include_once __DIR__ . '/includes/core/auth.php';

use Portflow\Core\DatabaseAdapter;
use Portflow\Core\PendingChangesQueue;
use Portflow\Core\Auth;

$db_adapter = new DatabaseAdapter();
$auth = new Auth();
$canAutomationWrite = !empty($_SESSION['uuid'])
    && $auth->checkResourceAccess($_SESSION['uuid'], 'automation', 'write');

$automationSettings = [];
$snmpScanConfig = [];
$automationSettingsJson = @file_get_contents(__DIR__ . '/data/automation/settings.json');
if (is_string($automationSettingsJson) && $automationSettingsJson !== '') {
    $decodedAutomationSettings = json_decode($automationSettingsJson, true);
    if (is_array($decodedAutomationSettings)) {
        $automationSettings = $decodedAutomationSettings;
        $snmpScanConfig = is_array($automationSettings['scheduler_config']['snmp_scan'] ?? null)
            ? $automationSettings['scheduler_config']['snmp_scan']
            : [];
    }
}

// --- POST: enqueue corrective change -----------------------------------
$enqueueResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'corrective_enqueue') {
    if (!$canAutomationWrite) {
        $enqueueResult = ['ok' => false, 'message' => 'Keine Berechtigung fuer Automation-Queue-Aktionen.'];
    } elseif (!$auth->csrf_check()) {
        $enqueueResult = ['ok' => false, 'message' => 'CSRF token invalid'];
    } else {
        $switchName = trim((string)($_POST['switch_name'] ?? ''));
        $templateId = trim((string)($_POST['template_id'] ?? ''));
        $ifName = trim((string)($_POST['interface'] ?? ''));
        $extraVlanId = trim((string)($_POST['vlan_id'] ?? ''));
        $userUuid = $_SESSION['uuid'] ?? null;

        $allowedTemplates = ['no_shutdown_port', 'shutdown_port', 'set_port_pvid', 'set_port_description', 'set_trunk_allowed_vlans'];
        if (!in_array($templateId, $allowedTemplates, true)) {
            $enqueueResult = ['ok' => false, 'message' => 'Template nicht erlaubt: ' . htmlspecialchars($templateId)];
        } elseif ($switchName === '' || $ifName === '' || $userUuid === null) {
            $enqueueResult = ['ok' => false, 'message' => 'Switch, Interface oder Benutzer fehlt.'];
        } else {
            // Resolve profile from inventory
            $automationJson = @file_get_contents(__DIR__ . '/data/automation/settings.json');
            $profileId = '';
            $invDecoded = null;
            if (is_string($automationJson) && $automationJson !== '') {
                $invDecoded = json_decode($automationJson, true);
            }
            if (is_array($invDecoded) && isset($invDecoded['switch_inventory_json'])) {
                $invInner = json_decode((string)$invDecoded['switch_inventory_json'], true);
                if (is_array($invInner) && isset($invInner['switches']) && is_array($invInner['switches'])) {
                    foreach ($invInner['switches'] as $sw) {
                        if (is_array($sw) && trim((string)($sw['name'] ?? '')) === $switchName) {
                            $profileId = (string)($sw['profile'] ?? '');
                            break;
                        }
                    }
                }
            }
            if ($profileId === '') {
                $profileId = 'huawei_access_no_commit';
            }

            // Render commands from automation.json
            $autoTplJson = @file_get_contents(__DIR__ . '/includes/core/automation.json');
            $autoTpl = is_string($autoTplJson) ? json_decode($autoTplJson, true) : null;
            $commands = [];
            $variables = ['interface' => $ifName];
            if ($extraVlanId !== '') {
                $variables['vlan_id'] = $extraVlanId;
            }
            foreach (['vlan_id', 'allowed_vlans', 'pvid', 'portflow_id', 'connection_ref', 'patchfield', 'description'] as $variableKey) {
                $variableValue = trim((string)($_POST[$variableKey] ?? ''));
                if ($variableValue !== '') {
                    $variables[$variableKey] = $variableValue;
                }
            }
            if (is_array($autoTpl) && isset($autoTpl['templates'][$templateId]['commands'])) {
                foreach ($autoTpl['templates'][$templateId]['commands'] as $cmd) {
                    $rendered = (string)$cmd;
                    foreach ($variables as $k => $v) {
                        $rendered = str_replace('{{' . $k . '}}', (string)$v, $rendered);
                    }
                    if (preg_match('/{{[^}]+}}/', $rendered)) {
                        continue;
                    }
                    $commands[] = $rendered;
                }
            }

            if (empty($commands)) {
                $enqueueResult = ['ok' => false, 'message' => 'Template ' . htmlspecialchars($templateId) . ' hat keine Kommandos.'];
            } else {
                try {
                    $queue = new PendingChangesQueue($db_adapter);
                    $queueUuid = $queue->addPendingChange((string)$userUuid, $switchName, $profileId, $templateId, $commands, $variables);
                    $enqueueResult = ['ok' => true, 'message' => 'Aenderung in Warteschlange (' . substr($queueUuid, 0, 8) . ').'];
                } catch (\Throwable $e) {
                    $enqueueResult = ['ok' => false, 'message' => 'Fehler: ' . $e->getMessage()];
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_port_config_expected') {
    if (!$canAutomationWrite) {
        $enqueueResult = ['ok' => false, 'message' => 'Keine Berechtigung fuer Report-Aktionen.'];
    } elseif (!$auth->csrf_check()) {
        $enqueueResult = ['ok' => false, 'message' => 'CSRF token invalid'];
    } else {
        $devicePortUuid = trim((string)($_POST['device_port_uuid'] ?? ''));
        if ($devicePortUuid === '') {
            $enqueueResult = ['ok' => false, 'message' => 'Port UUID fehlt.'];
        } else {
            try {
                $db_adapter->db_query(
                    'UPDATE device_port SET expected_speed = speed WHERE uuid = :uuid',
                    ['uuid' => $devicePortUuid]
                );

                $vlanRows = $db_adapter->db_query(
                    'SELECT uuid, vlan, expected_vlan, tagged, expected_tagged
                     FROM device_port_vlan
                     WHERE device_port = :uuid',
                    ['uuid' => $devicePortUuid]
                ) ?: [];

                foreach ($vlanRows as $vlanRow) {
                    $rowUuid = trim((string)($vlanRow['uuid'] ?? ''));
                    if ($rowUuid === '') {
                        continue;
                    }

                    $currentVlanUuid = trim((string)($vlanRow['vlan'] ?? ''));
                    $expectedVlanUuid = trim((string)($vlanRow['expected_vlan'] ?? ''));
                    if ($currentVlanUuid !== '') {
                        $db_adapter->db_query(
                            'UPDATE device_port_vlan
                             SET expected_vlan = :expected_vlan,
                                 expected_tagged = :expected_tagged
                             WHERE uuid = :uuid',
                            [
                                'expected_vlan' => $currentVlanUuid,
                                'expected_tagged' => !empty($vlanRow['tagged']),
                                'uuid' => $rowUuid,
                            ]
                        );
                    } elseif ($expectedVlanUuid !== '') {
                        $db_adapter->db_query(
                            'DELETE FROM device_port_vlan WHERE uuid = :uuid',
                            ['uuid' => $rowUuid]
                        );
                    }
                }

                $enqueueResult = ['ok' => true, 'message' => 'Expected-Werte fuer Speed und VLAN wurden auf den aktuellen Scan-Stand gesetzt.'];
            } catch (\Throwable $e) {
                $enqueueResult = ['ok' => false, 'message' => 'Expected angleichen fehlgeschlagen: ' . $e->getMessage()];
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_port_ip_expected') {
    if (!$canAutomationWrite) {
        $enqueueResult = ['ok' => false, 'message' => 'Keine Berechtigung fuer Report-Aktionen.'];
    } elseif (!$auth->csrf_check()) {
        $enqueueResult = ['ok' => false, 'message' => 'CSRF token invalid'];
    } else {
        $devicePortUuid = trim((string)($_POST['device_port_uuid'] ?? ''));
        if ($devicePortUuid === '') {
            $enqueueResult = ['ok' => false, 'message' => 'Port UUID fehlt.'];
        } else {
            try {
                $rows = $db_adapter->db_query(
                    "SELECT dp.device_port_ip,
                            dpi.ip::text AS current_ip,
                            dpi.hostname AS current_hostname,
                            dpi.dhcp_address AS current_dhcp_address
                     FROM device_port dp
                     LEFT JOIN device_port_ip dpi ON dpi.uuid = dp.device_port_ip
                     WHERE dp.uuid = :uuid
                     LIMIT 1",
                    ['uuid' => $devicePortUuid]
                ) ?: [];

                if (empty($rows) || trim((string)($rows[0]['device_port_ip'] ?? '')) === '') {
                    $enqueueResult = ['ok' => false, 'message' => 'Kein device_port_ip Datensatz fuer diesen Port gefunden.'];
                } else {
                    $devicePortIpUuid = trim((string)$rows[0]['device_port_ip']);
                    $db_adapter->db_query(
                        'UPDATE device_port_ip
                         SET expected_ip = ip,
                             expected_hostname = hostname,
                             expected_dhcp_address = dhcp_address
                         WHERE uuid = :uuid',
                        ['uuid' => $devicePortIpUuid]
                    );
                    $enqueueResult = ['ok' => true, 'message' => 'Expected-Werte fuer Port-IP wurden auf den aktuellen Scan-Stand gesetzt.'];
                }
            } catch (\Throwable $e) {
                $enqueueResult = ['ok' => false, 'message' => 'Expected angleichen fehlgeschlagen: ' . $e->getMessage()];
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_neighbor_expected') {
    if (!$canAutomationWrite) {
        $enqueueResult = ['ok' => false, 'message' => 'Keine Berechtigung fuer Report-Aktionen.'];
    } elseif (!$auth->csrf_check()) {
        $enqueueResult = ['ok' => false, 'message' => 'CSRF token invalid'];
    } else {
        $devicePortUuid = trim((string)($_POST['device_port_uuid'] ?? ''));
        if ($devicePortUuid === '') {
            $enqueueResult = ['ok' => false, 'message' => 'Port UUID fehlt.'];
        } else {
            try {
                $neighborRows = $db_adapter->db_query(
                    "SELECT remote_sys_name, remote_port_id
                     FROM device_port_neighbor
                     WHERE device_port = :uuid
                       AND remote_sys_name IS NOT NULL
                       AND remote_sys_name <> ''
                       AND remote_port_id IS NOT NULL
                       AND remote_port_id <> ''
                     ORDER BY last_seen DESC
                     LIMIT 1",
                    ['uuid' => $devicePortUuid]
                ) ?: [];
                if (empty($neighborRows)) {
                    $enqueueResult = ['ok' => false, 'message' => 'Kein beobachteter LLDP-Nachbar fuer diesen Port gefunden.'];
                } else {
                    $neighbor = $neighborRows[0];
                    $connectionRows = $db_adapter->db_query(
                        "SELECT uuid, device_port_source, device_port_destination
                         FROM connection
                         WHERE device_port_source = :uuid OR device_port_destination = :uuid
                         LIMIT 1",
                        ['uuid' => $devicePortUuid]
                    ) ?: [];
                    if (empty($connectionRows)) {
                        $enqueueResult = ['ok' => false, 'message' => 'Keine bestehende Connection fuer diesen Port gefunden.'];
                    } else {
                        $connection = $connectionRows[0];
                        $remotePortRows = $db_adapter->db_query(
                            "SELECT dp.uuid
                             FROM device_port dp
                             JOIN metadata pm ON pm.uuid = dp.metadata
                             JOIN device d ON d.uuid = dp.device
                             JOIN metadata dm ON dm.uuid = d.metadata
                             WHERE LOWER(dm.caption) = LOWER(:device_caption)
                               AND LOWER(pm.caption) = LOWER(:port_caption)
                             LIMIT 1",
                            [
                                'device_caption' => trim((string)($neighbor['remote_sys_name'] ?? '')),
                                'port_caption' => trim((string)($neighbor['remote_port_id'] ?? '')),
                            ]
                        ) ?: [];
                        if (empty($remotePortRows) || trim((string)($remotePortRows[0]['uuid'] ?? '')) === '') {
                            $enqueueResult = ['ok' => false, 'message' => 'Beobachteter Nachbar konnte nicht auf einen Portflow-Port aufgeloest werden.'];
                        } else {
                            $remotePortUuid = trim((string)$remotePortRows[0]['uuid']);
                            $updateParams = ['uuid' => $connection['uuid']];
                            if ((string)($connection['device_port_source'] ?? '') === $devicePortUuid) {
                                $updateSql = 'UPDATE connection SET expected_device_port_source = device_port_source, expected_device_port_destination = :remote_port WHERE uuid = :uuid';
                                $updateParams['remote_port'] = $remotePortUuid;
                            } elseif ((string)($connection['device_port_destination'] ?? '') === $devicePortUuid) {
                                $updateSql = 'UPDATE connection SET expected_device_port_destination = device_port_destination, expected_device_port_source = :remote_port WHERE uuid = :uuid';
                                $updateParams['remote_port'] = $remotePortUuid;
                            } else {
                                $updateSql = '';
                            }

                            if ($updateSql === '') {
                                $enqueueResult = ['ok' => false, 'message' => 'Connection konnte nicht eindeutig dem lokalen Port zugeordnet werden.'];
                            } else {
                                $db_adapter->db_query($updateSql, $updateParams);
                                $enqueueResult = ['ok' => true, 'message' => 'Expected-Connection wurde auf den beobachteten LLDP-Nachbarn abgeglichen.'];
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $enqueueResult = ['ok' => false, 'message' => 'Neighbor angleichen fehlgeschlagen: ' . $e->getMessage()];
            }
        }
    }
}

include_once __DIR__ . '/includes/header.php';

// Active tab
$tab = $_GET['tab'] ?? 'drift';
if (!in_array($tab, ['drift', 'runs', 'unknown', 'stale', 'nodes', 'topology'], true)) {
    $tab = 'drift';
}

// --- Recent scan runs --------------------------------------------------
$runsRows = [];
try {
    $runsRows = $db_adapter->db_query(
        "SELECT r.uuid, r.switch_name, r.trigger, r.started, r.finished, r.status,
                r.message, r.interfaces_seen, r.vlans_seen, r.findings_total,
                u.username
         FROM snmp_scan_run r
         LEFT JOIN users u ON u.uuid = r.users
         ORDER BY r.started DESC
         LIMIT 50"
    ) ?: [];
} catch (\Throwable $e) {
    $runsRows = [];
}

// --- Optional: single scan-run detail (?tab=runs&run=<uuid>) -----------
$selectedRunUuid = trim((string)($_GET['run'] ?? ''));
if ($selectedRunUuid !== '' && !preg_match('/^[0-9a-f-]{36}$/i', $selectedRunUuid)) {
    $selectedRunUuid = '';
}
$selectedRun = null;
if ($selectedRunUuid !== '') {
    try {
        $detailRows = $db_adapter->db_query(
            "SELECT r.uuid, r.switch_name, r.trigger, r.started, r.finished, r.status, r.message,
                    r.interfaces_seen, r.vlans_seen, r.findings_total, r.details, u.username
             FROM snmp_scan_run r
             LEFT JOIN users u ON u.uuid = r.users
             WHERE r.uuid = :uuid LIMIT 1",
            ['uuid' => $selectedRunUuid]
        );
        if (!empty($detailRows)) {
            $selectedRun = $detailRows[0];
            $rawDetails = $selectedRun['details'] ?? null;
            if (is_string($rawDetails) && $rawDetails !== '') {
                $decoded = json_decode($rawDetails, true);
                $selectedRun['details'] = is_array($decoded) ? $decoded : [];
            } else {
                $selectedRun['details'] = [];
            }
        }
    } catch (\Throwable $e) {
        $selectedRun = null;
    }
}

// --- Drift rows: configured device_port vs SNMP state ------------------
$driftRows = [];
try {
    $driftRows = $db_adapter->db_query(
          "SELECT dp.uuid AS device_port_uuid,
                d.uuid AS device_uuid,
                d.metadata->>'caption' AS device_caption,
                dp.metadata->>'caption' AS port_caption,
                dp.device_port_ip AS device_port_ip_uuid,
                dp.speed AS current_speed,
                dp.expected_speed AS expected_speed,
                dp.mac_address AS current_mac,
                dpi.ip::text AS current_ip,
                dpi.expected_ip::text AS expected_ip,
                dpi.hostname AS current_hostname,
                dpi.expected_hostname AS expected_hostname,
                dpi.dhcp_address AS current_dhcp_address,
                dpi.expected_dhcp_address AS expected_dhcp_address,
                     vlan_current_untagged.vlan_id AS current_pvid,
                     vlan_expected_untagged.vlan_id AS expected_pvid,
                     COALESCE(vlan_current_tagged.vlan_ids, '') AS current_tagged_vlans,
                     COALESCE(vlan_expected_tagged.vlan_ids, '') AS expected_tagged_vlans,
                     COALESCE(vlan_expected_flags.has_expected_vlan, FALSE) AS has_expected_vlan,
                                s.last_scan_run AS state_run_uuid,
                s.if_index, s.if_name, s.if_alias,
                s.if_admin_status, s.if_oper_status,
                s.last_seen_active, s.updated AS state_updated,
                                COALESCE(r.switch_name, latest_inventory_run.switch_name) AS last_run_switch,
                                COALESCE(r.started, latest_inventory_run.started) AS last_run_started,
                                latest_inventory_run.run_uuid AS latest_inventory_run_uuid
            FROM device_port dp
         LEFT JOIN device d ON d.uuid = dp.device
         LEFT JOIN device_port_ip dpi ON dpi.uuid = dp.device_port_ip
            LEFT JOIN device_port_snmp_state s ON s.device_port = dp.uuid
         LEFT JOIN snmp_scan_run r ON r.uuid = s.last_scan_run
                        LEFT JOIN LATERAL (
                                SELECT sr.uuid AS run_uuid, sr.switch_name, sr.started
                                FROM snmp_scan_run sr
                                LEFT JOIN device run_device ON run_device.uuid = sr.device
                                WHERE sr.status IN ('success', 'partial')
                                    AND (
                                        sr.device = d.uuid
                                        OR (
                                                d.item_group IS NOT NULL
                                                AND run_device.item_group = d.item_group
                                        )
                                    )
                                ORDER BY sr.started DESC
                                LIMIT 1
                        ) latest_inventory_run ON TRUE
            LEFT JOIN (
                SELECT dpv.device_port, MIN(v.vlan::int) AS vlan_id
                FROM device_port_vlan dpv
                JOIN vlan v ON v.uuid = dpv.vlan
                WHERE dpv.tagged = FALSE
                GROUP BY dpv.device_port
            ) vlan_current_untagged ON vlan_current_untagged.device_port = dp.uuid
            LEFT JOIN (
                SELECT dpv.device_port, MIN(v.vlan::int) AS vlan_id
                FROM device_port_vlan dpv
                JOIN vlan v ON v.uuid = dpv.expected_vlan
                WHERE dpv.expected_tagged = FALSE
                GROUP BY dpv.device_port
            ) vlan_expected_untagged ON vlan_expected_untagged.device_port = dp.uuid
            LEFT JOIN (
                SELECT dpv.device_port, string_agg(v.vlan::text, ',' ORDER BY v.vlan::int) AS vlan_ids
                FROM device_port_vlan dpv
                JOIN vlan v ON v.uuid = dpv.vlan
                WHERE dpv.tagged = TRUE
                GROUP BY dpv.device_port
            ) vlan_current_tagged ON vlan_current_tagged.device_port = dp.uuid
            LEFT JOIN (
                SELECT dpv.device_port, string_agg(v.vlan::text, ',' ORDER BY v.vlan::int) AS vlan_ids
                FROM device_port_vlan dpv
                JOIN vlan v ON v.uuid = dpv.expected_vlan
                WHERE dpv.expected_tagged = TRUE
                GROUP BY dpv.device_port
            ) vlan_expected_tagged ON vlan_expected_tagged.device_port = dp.uuid
            LEFT JOIN (
                SELECT device_port, bool_or(expected_vlan IS NOT NULL OR expected_tagged = TRUE) AS has_expected_vlan
                FROM device_port_vlan
                GROUP BY device_port
            ) vlan_expected_flags ON vlan_expected_flags.device_port = dp.uuid
            ORDER BY s.updated DESC NULLS LAST, d.metadata->>'caption', dp.metadata->>'caption'
         LIMIT 500"
    ) ?: [];
} catch (\Throwable $e) {
    $driftRows = [];
}

$neighborByPort = [];
$connectionByPort = [];
$knownDeviceCaptions = [];
$driftFlagsByPort = [];
if (!empty($driftRows)) {
    try {
        $knownDeviceRows = $db_adapter->db_query(
            "SELECT m.caption AS device_caption
             FROM device d
             LEFT JOIN metadata m ON m.uuid = d.metadata
             WHERE m.caption IS NOT NULL AND m.caption <> ''"
        ) ?: [];
        foreach ($knownDeviceRows as $knownDeviceRow) {
            $caption = trim((string)($knownDeviceRow['device_caption'] ?? ''));
            if ($caption !== '') {
                $knownDeviceCaptions[strtolower($caption)] = true;
            }
        }
    } catch (\Throwable $e) {
        $knownDeviceCaptions = [];
    }

    $portUuids = [];
    foreach ($driftRows as $driftRow) {
        $portUuid = trim((string)($driftRow['device_port_uuid'] ?? ''));
        if ($portUuid !== '') {
            $portUuids[$portUuid] = true;
        }
    }

    if (!empty($portUuids)) {
        $params = [];
        $placeholders = [];
        $portIndex = 0;
        foreach (array_keys($portUuids) as $portUuid) {
            $key = 'p' . $portIndex++;
            $placeholders[] = ':' . $key;
            $params[$key] = $portUuid;
        }

        try {
            $driftViewRows = $db_adapter->db_query(
                "SELECT device_port_uuid, drift_class
                 FROM device_port_drift
                 WHERE device_port_uuid IN (" . implode(',', $placeholders) . ")
                 ORDER BY device_port_uuid, drift_class",
                $params
            ) ?: [];
            foreach ($driftViewRows as $driftViewRow) {
                $portUuid = trim((string)($driftViewRow['device_port_uuid'] ?? ''));
                $driftClass = trim((string)($driftViewRow['drift_class'] ?? ''));
                if ($portUuid === '' || $driftClass === '') {
                    continue;
                }
                if (!isset($driftFlagsByPort[$portUuid])) {
                    $driftFlagsByPort[$portUuid] = [];
                }
                $driftFlagsByPort[$portUuid][$driftClass] = $driftClass;
            }
        } catch (\Throwable $e) {
            $driftFlagsByPort = [];
        }

        try {
            $neighborRows = $db_adapter->db_query(
                "SELECT n.device_port,
                        n.remote_sys_name,
                        n.remote_port_id,
                        n.remote_port_desc,
                        n.last_seen
                 FROM device_port_neighbor n
                 WHERE n.device_port IN (" . implode(',', $placeholders) . ")
                 ORDER BY n.last_seen DESC",
                $params
            ) ?: [];
            foreach ($neighborRows as $neighborRow) {
                $portUuid = trim((string)($neighborRow['device_port'] ?? ''));
                if ($portUuid === '' || isset($neighborByPort[$portUuid])) {
                    continue;
                }
                $remoteSysName = trim((string)($neighborRow['remote_sys_name'] ?? ''));
                $neighborByPort[$portUuid] = [
                    'remote_sys_name' => $remoteSysName,
                    'remote_port_id' => trim((string)($neighborRow['remote_port_id'] ?? '')),
                    'remote_port_desc' => trim((string)($neighborRow['remote_port_desc'] ?? '')),
                    'last_seen' => trim((string)($neighborRow['last_seen'] ?? '')),
                    'managed' => $remoteSysName !== '' && isset($knownDeviceCaptions[strtolower($remoteSysName)]),
                ];
            }
        } catch (\Throwable $e) {
            $neighborByPort = [];
        }

        try {
            $connectionRows = $db_adapter->db_query(
                "SELECT c.device_port_source,
                        c.device_port_destination,
                        src_dev.caption AS src_device_caption,
                        src_port.caption AS src_port_caption,
                        dst_dev.caption AS dst_device_caption,
                        dst_port.caption AS dst_port_caption
                 FROM connection c
                 LEFT JOIN device_port dp_src ON dp_src.uuid = c.device_port_source
                 LEFT JOIN metadata src_port ON src_port.uuid = dp_src.metadata
                 LEFT JOIN device d_src ON d_src.uuid = dp_src.device
                 LEFT JOIN metadata src_dev ON src_dev.uuid = d_src.metadata
                 LEFT JOIN device_port dp_dst ON dp_dst.uuid = c.device_port_destination
                 LEFT JOIN metadata dst_port ON dst_port.uuid = dp_dst.metadata
                 LEFT JOIN device d_dst ON d_dst.uuid = dp_dst.device
                 LEFT JOIN metadata dst_dev ON dst_dev.uuid = d_dst.metadata
                 WHERE c.device_port_source IN (" . implode(',', $placeholders) . ")
                    OR c.device_port_destination IN (" . implode(',', $placeholders) . ")",
                $params
            ) ?: [];
            foreach ($connectionRows as $connectionRow) {
                $srcUuid = trim((string)($connectionRow['device_port_source'] ?? ''));
                $dstUuid = trim((string)($connectionRow['device_port_destination'] ?? ''));
                if ($srcUuid !== '' && isset($portUuids[$srcUuid]) && !isset($connectionByPort[$srcUuid])) {
                    $connectionByPort[$srcUuid] = [
                        'remote_device_caption' => trim((string)($connectionRow['dst_device_caption'] ?? '')),
                        'remote_port_caption' => trim((string)($connectionRow['dst_port_caption'] ?? '')),
                    ];
                }
                if ($dstUuid !== '' && isset($portUuids[$dstUuid]) && !isset($connectionByPort[$dstUuid])) {
                    $connectionByPort[$dstUuid] = [
                        'remote_device_caption' => trim((string)($connectionRow['src_device_caption'] ?? '')),
                        'remote_port_caption' => trim((string)($connectionRow['src_port_caption'] ?? '')),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $connectionByPort = [];
        }
    }
}

// --- Stale ports (oper down / never seen active in N days) -------------
$staleDays = max(1, (int)($snmpScanConfig['inactivity_days'] ?? 30));
$staleRows = [];
try {
    $staleRows = $db_adapter->db_query(
        "SELECT dp.uuid AS device_port_uuid,
                d.metadata->>'caption' AS device_caption,
                dp.metadata->>'caption' AS port_caption,
                s.if_oper_status, s.last_seen_active, s.updated
         FROM device_port_snmp_state s
         JOIN device_port dp ON dp.uuid = s.device_port
         LEFT JOIN device d ON d.uuid = dp.device
         LEFT JOIN device_port_ip dpi ON dpi.uuid = dp.device_port_ip
         WHERE (
                s.last_seen_active IS NULL
                OR s.last_seen_active < (CURRENT_DATE - INTERVAL '" . (int)$staleDays . " days')
            )
            AND (
                dp.expected_speed IS NOT NULL
                OR TRIM(COALESCE(dpi.expected_ip::text, '')) <> ''
                OR TRIM(COALESCE(dpi.expected_hostname, '')) <> ''
                OR dpi.expected_dhcp_address IS NOT NULL
                OR EXISTS (
                    SELECT 1
                    FROM device_port_vlan dpv
                    WHERE dpv.device_port = dp.uuid
                      AND (dpv.expected_vlan IS NOT NULL OR COALESCE(dpv.expected_tagged, FALSE) = TRUE)
                )
                OR EXISTS (
                    SELECT 1
                    FROM connection c
                    WHERE c.expected_device_port_source = dp.uuid
                       OR c.expected_device_port_destination = dp.uuid
                )
            )
         ORDER BY s.last_seen_active NULLS FIRST
         LIMIT 200"
    ) ?: [];
} catch (\Throwable $e) {
    $staleRows = [];
}

// Helpers
function rep_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function rep_t(string $key, ?string $fallback = null): string {
    global $lang;

    $value = is_array($lang ?? null) ? trim((string)($lang[$key] ?? '')) : '';
    if ($value !== '') {
        return $value;
    }

    return $fallback ?? $key;
}
function rep_dt($v, string $fallback = '-'): string {
    $raw = trim((string)($v ?? ''));
    if ($raw === '') {
        return $fallback;
    }

    try {
        $dt = new DateTimeImmutable($raw);
        return $dt->format('d.m.Y H:i');
    } catch (\Throwable $e) {
        return $raw;
    }
}
function rep_num($v, string $fallback = '-'): string {
    if ($v === null || $v === '') {
        return $fallback;
    }
    if (is_numeric($v)) {
        $floatValue = (float)$v;
        if ((float)(int)$floatValue === $floatValue) {
            return (string)(int)$floatValue;
        }
        return rtrim(rtrim(number_format($floatValue, 2, '.', ''), '0'), '.');
    }
    return trim((string)$v);
}
function rep_csv_ints($v): array {
    $raw = trim((string)($v ?? ''));
    if ($raw === '') {
        return [];
    }
    $values = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || !preg_match('/^\d+$/', $part)) {
            continue;
        }
        $values[(int)$part] = (int)$part;
    }
    $values = array_values($values);
    sort($values, SORT_NUMERIC);
    return $values;
}
function rep_vlan_list_label($v): string {
    $values = is_array($v) ? $v : rep_csv_ints($v);
    return $values === [] ? '-' : implode(',', $values);
}
function rep_vlan_cli_list(string $csv): string {
    $values = rep_csv_ints($csv);
    return $values === [] ? '' : implode(' ', $values);
}
function rep_admin_status_label(?int $v): string {
    return match ($v) { 1 => 'up', 2 => 'down', 3 => 'testing', null => '-', default => (string)$v };
}
function rep_oper_status_label(?int $v): string {
    return match ($v) {
        1 => 'up', 2 => 'down', 3 => 'testing', 4 => 'unknown',
        5 => 'dormant', 6 => 'notPresent', 7 => 'lowerLayerDown',
        null => '-', default => (string)$v
    };
}
function rep_oper_pill(?int $v): string {
    $label = rep_oper_status_label($v);
    $cls = match ($v) {
        1 => 'bg-emerald-100 text-emerald-800',
        2 => 'bg-rose-100 text-rose-800',
        5, 6, 7 => 'bg-amber-100 text-amber-800',
        default => 'bg-slate-100 text-slate-700'
    };
    return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ' . $cls . '">' . rep_h($label) . '</span>';
}
function rep_run_status_pill(string $status): string {
    $cls = match ($status) {
        'ok' => 'bg-emerald-100 text-emerald-800',
        'failed' => 'bg-rose-100 text-rose-800',
        'running' => 'bg-blue-100 text-blue-800',
        default => 'bg-slate-100 text-slate-700'
    };
    return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ' . $cls . '">' . rep_h($status) . '</span>';
}
function rep_soft_pill(string $label, string $tone = 'slate'): string {
    $cls = match ($tone) {
        'blue' => 'bg-blue-100 text-blue-800',
        'emerald' => 'bg-emerald-100 text-emerald-800',
        'amber' => 'bg-amber-100 text-amber-800',
        'rose' => 'bg-rose-100 text-rose-800',
        'cyan' => 'bg-cyan-100 text-cyan-800',
        default => 'bg-slate-100 text-slate-700',
    };
    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ' . $cls . '">' . rep_h($label) . '</span>';
}
function rep_meta_badge(string $label): string {
    return '<span class="inline-flex items-center rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">' . rep_h($label) . '</span>';
}
function rep_flag_label(string $flag): string {
    return match ($flag) {
        'oper-down' => rep_t('snmp_drift_oper_down', 'Oper down'),
        'name-mismatch' => rep_t('snmp_drift_name_mismatch', 'Name'),
        'speed-mismatch' => rep_t('snmp_drift_speed_mismatch', 'Speed'),
        'pvid-mismatch' => rep_t('snmp_drift_pvid_mismatch', 'PVID'),
        'tagged-vlan-mismatch' => rep_t('snmp_drift_tagged_vlan_mismatch', 'Tagged VLANs'),
        'orphaned-port' => rep_t('snmp_drift_orphaned_port', 'Orphaned'),
        'ip-mismatch' => rep_t('snmp_drift_ip_mismatch', 'IP'),
        'ip-missing' => rep_t('snmp_drift_ip_missing', 'Missing IP'),
        'hostname-mismatch' => rep_t('snmp_drift_hostname_mismatch', 'Hostname'),
        'dhcp-mismatch' => rep_t('snmp_drift_dhcp_mismatch', 'DHCP'),
        'neighbor-mismatch' => rep_t('snmp_drift_neighbor_mismatch', 'Neighbor'),
        'unexpected-neighbor' => rep_t('snmp_drift_unexpected_neighbor', 'Unexpected neighbor'),
        'neighbor-missing' => rep_t('snmp_drift_neighbor_missing', 'Missing neighbor'),
        'missing-scan-state' => rep_t('snmp_drift_missing_scan_state', 'No SNMP state'),
        default => $flag,
    };
}
function rep_flag_severity(string $flag): string {
    return match ($flag) {
        'oper-down', 'speed-mismatch', 'pvid-mismatch', 'tagged-vlan-mismatch', 'orphaned-port', 'ip-mismatch', 'ip-missing', 'neighbor-mismatch', 'neighbor-missing', 'missing-scan-state' => 'warn',
        default => 'info',
    };
}
function rep_flag_detail(array $row, string $flag): string {
    return match ($flag) {
        'speed-mismatch' => 'Ist ' . rep_num($row['current_speed'] ?? null) . ' / Soll ' . rep_num($row['expected_speed'] ?? null),
        'pvid-mismatch' => 'Ist ' . rep_num($row['current_pvid'] ?? null) . ' / Soll ' . rep_num($row['expected_pvid'] ?? null),
        'tagged-vlan-mismatch' => 'Ist ' . rep_vlan_list_label($row['current_tagged_vlans'] ?? '') . ' / Soll ' . rep_vlan_list_label($row['expected_tagged_vlans'] ?? ''),
        'orphaned-port' => 'Port ist in Portflow vorhanden, fehlt aber im aktuellsten Scan dieses Switches oder Stacks.',
        'missing-scan-state' => 'Dieser Port hat erwartete Konfiguration, aber keinen aktuellen SNMP-State.',
        default => '',
    };
}
function rep_query_url(array $params): string {
    $clean = [];
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            $filtered = array_values(array_filter($value, static fn($entry): bool => trim((string)$entry) !== ''));
            if ($filtered !== []) {
                $clean[$key] = $filtered;
            }
            continue;
        }
        if ($value === null) {
            continue;
        }
        $value = (string)$value;
        if ($value === '') {
            continue;
        }
        $clean[$key] = $value;
    }
    $query = http_build_query($clean);
    return 'reports.php' . ($query !== '' ? '?' . $query : '');
}
function rep_is_virtual_interface_name(string $name): bool {
    $normalized = strtolower(trim($name));
    if ($normalized === '') {
        return false;
    }

    return preg_match('/^(?:br\d+|switch\d+(?:\.\d+)?|eth\d+\.\d+|bond\d+(?:\.\d+)?|vlan\d+|docker\d+|veth[a-z0-9]+|lo|dummy\d+|gre\d+|gretap\d+|erspan\d+|ip_vti\d+|ip6_vti\d+|sit\d+|ip6tnl\d+|ifb(?:ppp)?\d+|ppp\d+|tun\d+|tap\d+|tailscale\d+|wg\d+)$/', $normalized) === 1;
}

$unknownRows = [];
$unknownVirtualRows = [];
$unknownSwitchCount = 0;
try {
    $latestUnknownRunRows = $db_adapter->db_query(
        "SELECT DISTINCT ON (r.switch_name) r.uuid, r.switch_name, r.started, r.status, r.details
         FROM snmp_scan_run r
         WHERE r.status IN ('success', 'partial')
         ORDER BY r.switch_name, r.started DESC"
    ) ?: [];

    $unknownSwitches = [];
    foreach ($latestUnknownRunRows as $runRow) {
        $rawDetails = $runRow['details'] ?? null;
        if (is_string($rawDetails) && $rawDetails !== '') {
            $decoded = json_decode($rawDetails, true);
            $runDetails = is_array($decoded) ? $decoded : [];
        } elseif (is_array($rawDetails)) {
            $runDetails = $rawDetails;
        } else {
            $runDetails = [];
        }

        $runUnknownRows = is_array($runDetails['unknown_interfaces'] ?? null) ? $runDetails['unknown_interfaces'] : [];
        foreach ($runUnknownRows as $unknownRow) {
            if (!is_array($unknownRow)) {
                continue;
            }
            $switchName = trim((string)($runRow['switch_name'] ?? ''));
            $enrichedRow = [
                'run_uuid' => trim((string)($runRow['uuid'] ?? '')),
                'switch_name' => $switchName,
                'started' => trim((string)($runRow['started'] ?? '')),
                'status' => trim((string)($runRow['status'] ?? '')),
                'if_index' => $unknownRow['if_index'] ?? null,
                'if_name' => trim((string)($unknownRow['if_name'] ?? '')),
                'if_alias' => trim((string)($unknownRow['if_alias'] ?? '')),
                'ip_address' => trim((string)($unknownRow['ip_address'] ?? '')),
                'pvid' => $unknownRow['pvid'] ?? null,
                'stack_unit' => $unknownRow['stack_unit'] ?? null,
                'admin' => $unknownRow['admin'] ?? null,
                'oper' => $unknownRow['oper'] ?? null,
            ];
            if ($switchName !== '') {
                $unknownSwitches[$switchName] = true;
            }
            if (rep_is_virtual_interface_name($enrichedRow['if_name'])) {
                $unknownVirtualRows[] = $enrichedRow;
            } else {
                $unknownRows[] = $enrichedRow;
            }
        }
    }

    $unknownSwitchCount = count($unknownSwitches);

    $unknownSort = static function (array $left, array $right): int {
        $bySwitch = strcasecmp((string)($left['switch_name'] ?? ''), (string)($right['switch_name'] ?? ''));
        if ($bySwitch !== 0) {
            return $bySwitch;
        }
        $byIfIndex = ((int)($left['if_index'] ?? 0)) <=> ((int)($right['if_index'] ?? 0));
        if ($byIfIndex !== 0) {
            return $byIfIndex;
        }
        return strcasecmp((string)($left['if_name'] ?? ''), (string)($right['if_name'] ?? ''));
    };
    usort($unknownRows, $unknownSort);
    usort($unknownVirtualRows, $unknownSort);
} catch (\Throwable $e) {
    $unknownRows = [];
    $unknownVirtualRows = [];
    $unknownSwitchCount = 0;
}

// Pre-compute drift cells: which rows have differences worth flagging
$driftFlagged = [];
foreach ($driftRows as $i => $row) {
    $portUuid = trim((string)($row['device_port_uuid'] ?? ''));
    $flags = array_values($driftFlagsByPort[$portUuid] ?? []);
    $driftFlagged[$i] = $flags;
}
$driftCount = 0;
foreach ($driftFlagged as $f) { if ($f) { $driftCount++; } }
$staleCount = count($staleRows);
$unknownCount = count($unknownRows);
$unknownVirtualCount = count($unknownVirtualRows);
$runsCount = count($runsRows);
$driftOperDownCount = 0;
$driftNeighborIssueCount = 0;
$driftIpIssueCount = 0;
$driftLayer2IssueCount = 0;
foreach ($driftFlagged as $flags) {
    if (in_array('oper-down', $flags, true)) {
        $driftOperDownCount++;
    }
    if (in_array('neighbor-mismatch', $flags, true) || in_array('unexpected-neighbor', $flags, true) || in_array('neighbor-missing', $flags, true)) {
        $driftNeighborIssueCount++;
    }
    if (in_array('ip-mismatch', $flags, true) || in_array('ip-missing', $flags, true) || in_array('hostname-mismatch', $flags, true) || in_array('dhcp-mismatch', $flags, true)) {
        $driftIpIssueCount++;
    }
    if (in_array('speed-mismatch', $flags, true) || in_array('pvid-mismatch', $flags, true) || in_array('tagged-vlan-mismatch', $flags, true) || in_array('orphaned-port', $flags, true) || in_array('missing-scan-state', $flags, true)) {
        $driftLayer2IssueCount++;
    }
}
$switchFilter = trim((string)($_GET['switch'] ?? ''));
$classFilter = trim((string)($_GET['class'] ?? ''));
$severityFilter = trim((string)($_GET['severity'] ?? ''));
$driftStatusFilter = trim((string)($_GET['status'] ?? 'open'));
if (!in_array($driftStatusFilter, ['open', 'ignored', 'ok', 'all'], true)) {
    $driftStatusFilter = 'open';
}
$rawIgnoredPorts = $_GET['ignore'] ?? [];
if (!is_array($rawIgnoredPorts)) {
    $rawIgnoredPorts = [$rawIgnoredPorts];
}
$ignoredPortUuids = [];
foreach ($rawIgnoredPorts as $ignoredPortUuid) {
    $ignoredPortUuid = trim((string)$ignoredPortUuid);
    if ($ignoredPortUuid !== '') {
        $ignoredPortUuids[$ignoredPortUuid] = true;
    }
}
$driftSwitchOptions = [];
$driftClassOptions = [];
$visibleDriftRows = [];
$ignoredDriftCount = 0;
foreach ($driftRows as $i => $row) {
    $flags = $driftFlagged[$i] ?? [];
    $portUuid = trim((string)($row['device_port_uuid'] ?? ''));
    $lastRunSwitch = trim((string)($row['last_run_switch'] ?? ''));
    if ($lastRunSwitch !== '') {
        $driftSwitchOptions[$lastRunSwitch] = $lastRunSwitch;
    }
    foreach ($flags as $flag) {
        $driftClassOptions[$flag] = rep_flag_label($flag);
    }
    $rowSeverities = array_values(array_unique(array_map('rep_flag_severity', $flags)));
    $isIgnored = $portUuid !== '' && isset($ignoredPortUuids[$portUuid]);
    if ($isIgnored && !empty($flags)) {
        $ignoredDriftCount++;
    }

    $matchesSwitch = $switchFilter === '' || strcasecmp($lastRunSwitch, $switchFilter) === 0;
    $matchesClass = $classFilter === '' || in_array($classFilter, $flags, true);
    $matchesSeverity = $severityFilter === '' || in_array($severityFilter, $rowSeverities, true);
    $matchesStatus = match ($driftStatusFilter) {
        'ignored' => !empty($flags) && $isIgnored,
        'ok' => empty($flags),
        'all' => true,
        default => !empty($flags) && !$isIgnored,
    };
    $visibleDriftRows[$i] = $matchesSwitch && $matchesClass && $matchesSeverity && $matchesStatus;
}
natcasesort($driftSwitchOptions);
asort($driftClassOptions, SORT_NATURAL | SORT_FLAG_CASE);
$visibleDriftCount = count(array_filter($visibleDriftRows));
$driftBaseParams = ['tab' => 'drift'];
if ($switchFilter !== '') {
    $driftBaseParams['switch'] = $switchFilter;
}
if ($classFilter !== '') {
    $driftBaseParams['class'] = $classFilter;
}
if ($severityFilter !== '') {
    $driftBaseParams['severity'] = $severityFilter;
}
if ($driftStatusFilter !== 'open') {
    $driftBaseParams['status'] = $driftStatusFilter;
}
if (!empty($ignoredPortUuids)) {
    $driftBaseParams['ignore'] = array_keys($ignoredPortUuids);
}
$latestRunRow = $runsRows[0] ?? null;
$latestRunMeta = '';
if (is_array($latestRunRow)) {
    $latestRunMeta = trim((string)($latestRunRow['switch_name'] ?? ''));
    if (trim((string)($latestRunRow['started'] ?? '')) !== '') {
        $latestRunMeta .= ($latestRunMeta !== '' ? ' · ' : '') . trim((string)($latestRunRow['started'] ?? ''));
    }
}

// --- Nodes (FDB / MAC tracking) ---------------------------------------
$nodeSearch = trim((string)($_GET['mac'] ?? ''));
$nodeRows = [];
$nodeCount = 0;
try {
    $countRow = $db_adapter->db_query("SELECT COUNT(*) AS c FROM device_port_node");
    $nodeCount = (int)($countRow[0]['c'] ?? 0);
} catch (\Throwable $e) {
    $nodeCount = 0;
}
if ($tab === 'nodes') {
    try {
        $where = '';
        $params = [];
        if ($nodeSearch !== '') {
            $where = 'WHERE n.mac_address ILIKE :q OR COALESCE(n.ip, \'\') ILIKE :q OR COALESCE(n.hostname, \'\') ILIKE :q OR dp_cap.caption ILIKE :q OR d_cap.caption ILIKE :q';
            $params['q'] = '%' . $nodeSearch . '%';
        }
        $nodeRows = $db_adapter->db_query(
            "SELECT n.uuid, n.mac_address, n.vlan, n.ip, n.hostname, n.first_seen, n.last_seen,
                    dp.uuid AS device_port_uuid, dp_cap.caption AS port_caption,
                    d.uuid AS device_uuid, d_cap.caption AS device_caption,
                    r.switch_name AS last_run_switch
             FROM device_port_node n
             JOIN device_port dp ON dp.uuid = n.device_port
             LEFT JOIN metadata dp_cap ON dp_cap.uuid = dp.metadata
             LEFT JOIN device d ON d.uuid = dp.device
             LEFT JOIN metadata d_cap ON d_cap.uuid = d.metadata
             LEFT JOIN snmp_scan_run r ON r.uuid = n.last_scan_run
             $where
             ORDER BY n.last_seen DESC
             LIMIT 500",
            $params
        ) ?: [];
    } catch (\Throwable $e) {
        $nodeRows = [];
    }
}

$tabs = [
    'drift' => ['label' => rep_t('snmp_tab_drift', 'Drift') . ' (' . $driftCount . ')', 'icon' => 'alert-triangle'],
    'unknown' => ['label' => rep_t('snmp_tab_unknown_ports', 'Unknown Ports') . ' (' . $unknownCount . ')', 'icon' => 'unlink-2'],
    'stale' => ['label' => rep_t('snmp_tab_stale_ports', 'Stale Ports') . ' (' . $staleCount . ')', 'icon' => 'eye-off'],
    'nodes' => ['label' => rep_t('snmp_tab_nodes', 'Nodes') . ' (' . $nodeCount . ')', 'icon' => 'network'],
    'topology' => ['label' => rep_t('snmp_tab_topology', 'Topology'), 'icon' => 'share-2'],
    'runs' => ['label' => rep_t('snmp_tab_scan_runs', 'Scan Runs') . ' (' . $runsCount . ')', 'icon' => 'history'],
];

// --- Topology / LLDP neighbors ----------------------------------------
$topoRows = [];
$topologyNodes = [];
$topologyEdges = [];
if ($tab === 'topology') {
    try {
        $topoRows = $db_adapter->db_query(
            "SELECT n.uuid, n.remote_sys_name, n.remote_chassis_id, n.remote_port_id, n.remote_port_desc,
                    n.discovered_via, n.first_seen, n.last_seen,
                    dp_cap.caption AS port_caption,
                    d_cap.caption AS device_caption
             FROM device_port_neighbor n
             JOIN device_port dp ON dp.uuid = n.device_port
             LEFT JOIN metadata dp_cap ON dp_cap.uuid = dp.metadata
             LEFT JOIN device d ON d.uuid = dp.device
             LEFT JOIN metadata d_cap ON d_cap.uuid = d.metadata
             ORDER BY d_cap.caption, dp_cap.caption
             LIMIT 1000"
        ) ?: [];
    } catch (\Throwable $e) {
        $topoRows = [];
    }

    $connectionKeys = [];
    try {
        $connectionRows = $db_adapter->db_query(
            "SELECT dsrc_cap.caption AS src_device_caption,
                    psrc_cap.caption AS src_port_caption,
                    ddst_cap.caption AS dst_device_caption,
                    pdst_cap.caption AS dst_port_caption
             FROM connection c
             LEFT JOIN device_port dp_src ON dp_src.uuid = c.device_port_source
             LEFT JOIN metadata psrc_cap ON psrc_cap.uuid = dp_src.metadata
             LEFT JOIN device d_src ON d_src.uuid = dp_src.device
             LEFT JOIN metadata dsrc_cap ON dsrc_cap.uuid = d_src.metadata
             LEFT JOIN device_port dp_dst ON dp_dst.uuid = c.device_port_destination
             LEFT JOIN metadata pdst_cap ON pdst_cap.uuid = dp_dst.metadata
             LEFT JOIN device d_dst ON d_dst.uuid = dp_dst.device
             LEFT JOIN metadata ddst_cap ON ddst_cap.uuid = d_dst.metadata
             WHERE c.device_port_source IS NOT NULL AND c.device_port_destination IS NOT NULL"
        ) ?: [];
        foreach ($connectionRows as $connectionRow) {
            $srcDevice = strtolower(trim((string)($connectionRow['src_device_caption'] ?? '')));
            $srcPort = strtolower(trim((string)($connectionRow['src_port_caption'] ?? '')));
            $dstDevice = strtolower(trim((string)($connectionRow['dst_device_caption'] ?? '')));
            $dstPort = strtolower(trim((string)($connectionRow['dst_port_caption'] ?? '')));
            if ($srcDevice === '' || $srcPort === '' || $dstDevice === '' || $dstPort === '') {
                continue;
            }
            $ends = [$srcDevice . '|' . $srcPort, $dstDevice . '|' . $dstPort];
            sort($ends, SORT_STRING);
            $connectionKeys[implode('||', $ends)] = true;
        }
    } catch (\Throwable $e) {
        $connectionKeys = [];
    }

    $knownDeviceNames = [];
    foreach ($topoRows as $row) {
        $deviceCaption = trim((string)($row['device_caption'] ?? ''));
        if ($deviceCaption === '') {
            continue;
        }
        $knownDeviceNames[strtolower($deviceCaption)] = $deviceCaption;
    }

    foreach ($topoRows as $row) {
        $localDevice = trim((string)($row['device_caption'] ?? ''));
        $localPort = trim((string)($row['port_caption'] ?? ''));
        $remoteSysName = trim((string)($row['remote_sys_name'] ?? ''));
        $remotePort = trim((string)($row['remote_port_id'] ?? ''));
        $lastSeen = trim((string)($row['last_seen'] ?? ''));
        if ($localDevice === '' || $remoteSysName === '') {
            continue;
        }

        $remoteDevice = $knownDeviceNames[strtolower($remoteSysName)] ?? $remoteSysName;
        $localNodeKey = strtolower($localDevice);
        $remoteNodeKey = strtolower($remoteDevice);
        if (!isset($topologyNodes[$localNodeKey])) {
            $topologyNodes[$localNodeKey] = ['label' => $localDevice, 'managed' => true];
        }
        if (!isset($topologyNodes[$remoteNodeKey])) {
            $topologyNodes[$remoteNodeKey] = [
                'label' => $remoteDevice,
                'managed' => isset($knownDeviceNames[$remoteNodeKey]),
            ];
        }

        $edgeNodes = [$localNodeKey, $remoteNodeKey];
        sort($edgeNodes, SORT_STRING);
        $edgeKey = implode('|', $edgeNodes);
        if (!isset($topologyEdges[$edgeKey])) {
            $topologyEdges[$edgeKey] = [
                'left_key' => $edgeNodes[0],
                'right_key' => $edgeNodes[1],
                'left_label' => $topologyNodes[$edgeNodes[0]]['label'],
                'right_label' => $topologyNodes[$edgeNodes[1]]['label'],
                'links' => [],
                'last_seen' => $lastSeen,
                'managed_both' => !empty($topologyNodes[$edgeNodes[0]]['managed']) && !empty($topologyNodes[$edgeNodes[1]]['managed']),
                'mapped_links' => 0,
                'unmapped_links' => 0,
            ];
        }

        $linkLabel = $localPort !== '' || $remotePort !== ''
            ? trim(($localPort !== '' ? $localPort : '?') . ' -> ' . ($remotePort !== '' ? $remotePort : '?'))
            : 'Uplink';
        $isMappedConnection = false;
        if ($localPort !== '' && $remotePort !== '' && !empty($topologyEdges[$edgeKey]['managed_both'])) {
            $candidateEnds = [
                strtolower($localDevice) . '|' . strtolower($localPort),
                strtolower($remoteDevice) . '|' . strtolower($remotePort),
            ];
            sort($candidateEnds, SORT_STRING);
            $isMappedConnection = isset($connectionKeys[implode('||', $candidateEnds)]);
        }
        $topologyEdges[$edgeKey]['links'][$linkLabel] = $isMappedConnection ? 'mapped' : (!empty($topologyEdges[$edgeKey]['managed_both']) ? 'unmapped' : 'external');
        if ($isMappedConnection) {
            $topologyEdges[$edgeKey]['mapped_links']++;
        } elseif (!empty($topologyEdges[$edgeKey]['managed_both'])) {
            $topologyEdges[$edgeKey]['unmapped_links']++;
        }
        if ($lastSeen !== '' && strcmp($lastSeen, (string)($topologyEdges[$edgeKey]['last_seen'] ?? '')) > 0) {
            $topologyEdges[$edgeKey]['last_seen'] = $lastSeen;
        }
    }

    uasort($topologyNodes, static function (array $a, array $b): int {
        return strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
    });
    uasort($topologyEdges, static function (array $a, array $b): int {
        return strcasecmp(
            (string)($a['left_label'] ?? '') . '|' . (string)($a['right_label'] ?? ''),
            (string)($b['left_label'] ?? '') . '|' . (string)($b['right_label'] ?? '')
        );
    });
}
?>

<div class="mx-4 mb-4 mt-0 space-y-4">
    <?php if ($enqueueResult !== null): ?>
        <div class="rounded-2xl border px-4 py-3 text-sm <?php echo $enqueueResult['ok'] ? 'border-emerald-200 text-emerald-800' : 'border-rose-200 text-rose-800'; ?> bg-white">
            <?php echo rep_h($enqueueResult['message'] ?? ''); ?>
        </div>
    <?php endif; ?>
    <div class="rounded-2xl border border-slate-300 bg-white p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-slate-900"><?php echo rep_h(rep_t('snmp_reports_title', 'SNMP Reports')); ?></h1>
                <p class="text-sm text-slate-500"><?php echo rep_h(rep_t('snmp_reports_subtitle', 'Discovery status, drift, and scan history.')); ?></p>
            </div>
            <a href="settings.php?site=scripts&amp;tab=switch" class="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                <i data-lucide="radar" class="h-4 w-4"></i><span><?php echo rep_h(rep_t('snmp_button_inventory', 'Inventory')); ?></span>
            </a>
        </div>

        <nav class="mt-4 flex flex-wrap gap-2">
        <?php foreach ($tabs as $tabKey => $tabMeta): ?>
            <a href="?tab=<?php echo rep_h($tabKey); ?>"
               class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold <?php echo $tab === $tabKey ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'; ?>">
                <i data-lucide="<?php echo rep_h($tabMeta['icon']); ?>" class="h-4 w-4"></i>
                <span><?php echo rep_h($tabMeta['label']); ?></span>
            </a>
        <?php endforeach; ?>
        </nav>
    </div>

    <?php if ($tab === 'drift'): ?>
        <div class="rounded-2xl border border-slate-300 bg-white p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-500">Diese Ansicht priorisiert Abweichungen, die direkt bearbeitet werden können: Link-Nachbarn, IP-/Hostname-Sollwerte, Speed-/VLAN-Drift und Ports mit operativem Down-Status.</p>
                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <?php echo rep_meta_badge('Drift ' . $driftCount); ?>
                    <?php echo rep_meta_badge('Nachbarfälle ' . $driftNeighborIssueCount); ?>
                    <?php echo rep_meta_badge('Oper down ' . $driftOperDownCount); ?>
                    <?php echo rep_meta_badge('IP-/Hostname-Drift ' . $driftIpIssueCount); ?>
                    <?php echo rep_meta_badge('Layer2/State ' . $driftLayer2IssueCount); ?>
                    <?php echo rep_meta_badge('Sichtbar ' . $visibleDriftCount); ?>
                </div>
            </div>
            <form method="get" action="reports.php" class="mb-3 flex flex-wrap items-center gap-2">
                <input type="hidden" name="tab" value="drift">
                <?php foreach (array_keys($ignoredPortUuids) as $ignoredPortUuid): ?>
                    <input type="hidden" name="ignore[]" value="<?php echo rep_h($ignoredPortUuid); ?>">
                <?php endforeach; ?>
                <select name="switch" class="rounded-full border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                    <option value=""><?php echo rep_h(rep_t('snmp_filter_all_switches', 'All switches')); ?></option>
                    <?php foreach ($driftSwitchOptions as $switchOption): ?>
                        <option value="<?php echo rep_h($switchOption); ?>" <?php echo strcasecmp($switchFilter, $switchOption) === 0 ? 'selected' : ''; ?>><?php echo rep_h($switchOption); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="class" class="rounded-full border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                    <option value=""><?php echo rep_h(rep_t('snmp_filter_all_drift_classes', 'All drift classes')); ?></option>
                    <?php foreach ($driftClassOptions as $classOptionValue => $classOptionLabel): ?>
                        <option value="<?php echo rep_h($classOptionValue); ?>" <?php echo $classFilter === $classOptionValue ? 'selected' : ''; ?>><?php echo rep_h($classOptionLabel); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="severity" class="rounded-full border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                    <option value=""><?php echo rep_h(rep_t('snmp_filter_all_severities', 'All severities')); ?></option>
                    <option value="warn" <?php echo $severityFilter === 'warn' ? 'selected' : ''; ?>><?php echo rep_h(rep_t('snmp_severity_warn', 'Warn')); ?></option>
                    <option value="info" <?php echo $severityFilter === 'info' ? 'selected' : ''; ?>><?php echo rep_h(rep_t('snmp_severity_info', 'Info')); ?></option>
                </select>
                <select name="status" class="rounded-full border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                    <option value="open" <?php echo $driftStatusFilter === 'open' ? 'selected' : ''; ?>><?php echo rep_h(rep_t('snmp_status_open', 'Open')); ?></option>
                    <option value="ignored" <?php echo $driftStatusFilter === 'ignored' ? 'selected' : ''; ?>><?php echo rep_h(rep_t('snmp_status_ignored', 'Ignored')); ?></option>
                    <option value="ok" <?php echo $driftStatusFilter === 'ok' ? 'selected' : ''; ?>><?php echo rep_h(rep_t('snmp_status_ok', 'OK')); ?></option>
                    <option value="all" <?php echo $driftStatusFilter === 'all' ? 'selected' : ''; ?>><?php echo rep_h(rep_t('snmp_status_all', 'All')); ?></option>
                </select>
                <button type="submit" class="inline-flex items-center gap-1 rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    <i data-lucide="funnel" class="h-4 w-4"></i><span><?php echo rep_h(rep_t('snmp_button_filter', 'Filter')); ?></span>
                </button>
                <a href="?tab=drift" class="inline-flex items-center rounded-full border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100"><?php echo rep_h(rep_t('snmp_button_reset', 'Reset')); ?></a>
            </form>
            <?php if ($ignoredDriftCount > 0): ?>
                <?php $clearIgnoreParams = $driftBaseParams; unset($clearIgnoreParams['ignore']); ?>
                <?php $ignoredViewParams = $driftBaseParams; $ignoredViewParams['status'] = 'ignored'; ?>
                <div class="mb-3 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <?php echo rep_meta_badge(rep_t('snmp_badge_temp_ignored', 'Temporarily ignored') . ' ' . $ignoredDriftCount); ?>
                    <?php if ($driftStatusFilter !== 'ignored'): ?>
                        <a href="<?php echo rep_h(rep_query_url($ignoredViewParams)); ?>" class="inline-flex items-center rounded-full border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-600 hover:bg-slate-100"><?php echo rep_h(rep_t('snmp_button_show_ignored', 'Show ignored')); ?></a>
                    <?php endif; ?>
                    <a href="<?php echo rep_h(rep_query_url($clearIgnoreParams)); ?>" class="inline-flex items-center rounded-full border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-600 hover:bg-slate-100"><?php echo rep_h(rep_t('snmp_button_reset_ignore', 'Reset ignore')); ?></a>
                </div>
            <?php endif; ?>
            <div class="max-h-[500px] overflow-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="p-2">Status</th>
                            <th class="p-2">Gerät</th>
                            <th class="p-2">Konfig. Port</th>
                            <th class="p-2">SNMP ifName</th>
                            <th class="p-2">Nachbar</th>
                            <th class="p-2">IP / Hostname</th>
                            <th class="p-2">Alias</th>
                            <th class="p-2">Admin</th>
                            <th class="p-2">Oper</th>
                            <th class="p-2">Last seen</th>
                            <th class="p-2">Switch (Run)</th>
                            <th class="p-2">Auffälligkeiten</th>
                            <th class="p-2 text-right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($driftRows as $i => $row):
                        if (empty($visibleDriftRows[$i])) {
                            continue;
                        }
                        $flags = $driftFlagged[$i] ?? [];
                        $hasFlag = !empty($flags);
                        $portUuid = trim((string)($row['device_port_uuid'] ?? ''));
                        $observedNeighbor = $portUuid !== '' ? ($neighborByPort[$portUuid] ?? null) : null;
                        $configuredNeighbor = $portUuid !== '' ? ($connectionByPort[$portUuid] ?? null) : null;
                        $isIgnored = $portUuid !== '' && isset($ignoredPortUuids[$portUuid]);
                        $expectedTaggedCli = rep_vlan_cli_list((string)($row['expected_tagged_vlans'] ?? ''));
                        $toggleIgnoreParams = $driftBaseParams;
                        $toggleIgnore = array_keys($ignoredPortUuids);
                        if ($portUuid !== '') {
                            if ($isIgnored) {
                                $toggleIgnore = array_values(array_filter($toggleIgnore, static fn(string $value): bool => $value !== $portUuid));
                            } else {
                                $toggleIgnore[] = $portUuid;
                            }
                        }
                        if ($toggleIgnore === []) {
                            unset($toggleIgnoreParams['ignore']);
                        } else {
                            $toggleIgnoreParams['ignore'] = array_values(array_unique($toggleIgnore));
                        }
                    ?>
                        <tr class="border-b border-slate-200 hover:bg-slate-50">
                            <td class="p-2">
                                <?php if ($hasFlag): ?>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                        <i data-lucide="alert-triangle" class="h-3 w-3"></i><?php echo rep_h(rep_t('snmp_status_drift', 'Drift')); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">
                                        <i data-lucide="check" class="h-3 w-3"></i>OK
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="p-2"><?php echo rep_h($row['device_caption'] ?? ''); ?></td>
                            <td class="p-2 font-mono text-xs"><?php echo rep_h($row['port_caption'] ?? ''); ?></td>
                            <td class="p-2 font-mono text-xs"><?php echo rep_h($row['if_name'] ?? ''); ?></td>
                            <td class="p-2 text-xs">
                                <div><span class="font-semibold text-slate-700">Ist:</span> <?php echo rep_h($observedNeighbor['remote_sys_name'] ?? '-'); ?><?php if (trim((string)($observedNeighbor['remote_port_id'] ?? '')) !== ''): ?> <span class="text-slate-400">(<?php echo rep_h($observedNeighbor['remote_port_id'] ?? ''); ?>)</span><?php endif; ?></div>
                                <?php if ($configuredNeighbor !== null): ?>
                                    <div class="text-slate-500"><span class="font-semibold">Soll:</span> <?php echo rep_h($configuredNeighbor['remote_device_caption'] ?? '-'); ?><?php if (trim((string)($configuredNeighbor['remote_port_caption'] ?? '')) !== ''): ?> <span class="text-slate-400">(<?php echo rep_h($configuredNeighbor['remote_port_caption'] ?? ''); ?>)</span><?php endif; ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-2 text-xs">
                                <div><span class="font-semibold text-slate-700">Ist:</span> <?php echo rep_h(trim((string)($row['current_ip'] ?? '')) !== '' ? (string)($row['current_ip'] ?? '') : '-'); ?></div>
                                <?php if (trim((string)($row['expected_ip'] ?? '')) !== ''): ?>
                                    <div class="text-slate-500"><span class="font-semibold">Soll:</span> <?php echo rep_h($row['expected_ip'] ?? ''); ?></div>
                                <?php endif; ?>
                                <?php if (trim((string)($row['current_hostname'] ?? '')) !== '' || trim((string)($row['expected_hostname'] ?? '')) !== ''): ?>
                                    <div class="mt-1 text-slate-500">
                                        <span class="font-semibold">Host:</span>
                                        <?php echo rep_h(trim((string)($row['current_hostname'] ?? '')) !== '' ? (string)($row['current_hostname'] ?? '') : '-'); ?>
                                        <?php if (trim((string)($row['expected_hostname'] ?? '')) !== ''): ?>
                                            <span class="text-slate-400"> / Soll: <?php echo rep_h($row['expected_hostname'] ?? ''); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (($row['current_dhcp_address'] ?? null) !== null || ($row['expected_dhcp_address'] ?? null) !== null): ?>
                                    <div class="mt-1 text-slate-500">
                                        <span class="font-semibold">DHCP:</span>
                                        <?php echo rep_h(!empty($row['current_dhcp_address']) ? 'ja' : 'nein'); ?>
                                        <?php if (($row['expected_dhcp_address'] ?? null) !== null): ?>
                                            <span class="text-slate-400"> / Soll: <?php echo rep_h(!empty($row['expected_dhcp_address']) ? 'ja' : 'nein'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="p-2 text-xs text-slate-500"><?php echo rep_h($row['if_alias'] ?? ''); ?></td>
                            <td class="p-2 text-xs"><?php echo rep_h(rep_admin_status_label(isset($row['if_admin_status']) ? (int)$row['if_admin_status'] : null)); ?></td>
                            <td class="p-2"><?php echo rep_oper_pill(isset($row['if_oper_status']) ? (int)$row['if_oper_status'] : null); ?></td>
                            <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['last_seen_active'] ?? null)); ?></td>
                            <td class="p-2 text-xs">
                                <?php echo rep_h($row['last_run_switch'] ?? ''); ?><br>
                                <span class="text-slate-400"><?php echo rep_h(rep_dt($row['last_run_started'] ?? null, '')); ?></span>
                            </td>
                            <td class="p-2 text-xs">
                                <?php foreach ($flags as $f): ?>
                                    <?php $detail = rep_flag_detail($row, $f); ?>
                                    <div class="mb-1">
                                        <span class="mr-1 inline-flex items-center rounded-full <?php echo rep_flag_severity($f) === 'warn' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'; ?> px-2 py-0.5 text-xs font-semibold"><?php echo rep_h(rep_flag_label($f)); ?></span>
                                        <?php if ($detail !== ''): ?>
                                            <div class="mt-0.5 text-[11px] text-slate-500"><?php echo rep_h($detail); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td class="p-2 text-right">
                                <div class="flex justify-end gap-2">
                                    <?php if ($canAutomationWrite && (in_array('speed-mismatch', $flags, true) || in_array('pvid-mismatch', $flags, true) || in_array('tagged-vlan-mismatch', $flags, true))): ?>
                                        <form method="post" action="reports.php?tab=drift" class="inline">
                                            <input type="hidden" name="csrf" value="<?php echo rep_h($auth->csrf()); ?>">
                                            <input type="hidden" name="action" value="accept_port_config_expected">
                                            <input type="hidden" name="device_port_uuid" value="<?php echo rep_h($row['device_port_uuid']); ?>">
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-full bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 text-xs" title="Expected-Speed/VLAN auf aktuellen Scan-Stand setzen" onclick="return confirm('Expected-Werte fuer Speed und VLAN dieses Ports auf den aktuellen Scan-Stand setzen?');">
                                                <i data-lucide="check" class="h-3 w-3"></i><span><?php echo rep_h(rep_t('snmp_button_align_l2', 'Align L2')); ?></span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canAutomationWrite && in_array('neighbor-mismatch', $flags, true) && $observedNeighbor !== null && !empty($observedNeighbor['managed']) && $configuredNeighbor !== null): ?>
                                        <form method="post" action="reports.php?tab=drift" class="inline">
                                            <input type="hidden" name="csrf" value="<?php echo rep_h($auth->csrf()); ?>">
                                            <input type="hidden" name="action" value="accept_neighbor_expected">
                                            <input type="hidden" name="device_port_uuid" value="<?php echo rep_h($row['device_port_uuid']); ?>">
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-full bg-cyan-600 hover:bg-cyan-700 text-white px-3 py-1 text-xs" title="Expected-Link auf beobachteten Nachbarn setzen" onclick="return confirm('Expected-Connection dieses Ports auf den beobachteten LLDP-Nachbarn angleichen?');">
                                                <i data-lucide="git-merge" class="h-3 w-3"></i><span><?php echo rep_h(rep_t('snmp_button_align_neighbor', 'Align neighbor')); ?></span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canAutomationWrite && in_array('pvid-mismatch', $flags, true) && !empty($row['if_name']) && !empty($row['last_run_switch']) && ($row['expected_pvid'] ?? null) !== null): ?>
                                        <form method="post" action="reports.php?tab=drift" class="inline">
                                            <input type="hidden" name="csrf" value="<?php echo rep_h($auth->csrf()); ?>">
                                            <input type="hidden" name="action" value="corrective_enqueue">
                                            <input type="hidden" name="template_id" value="set_port_pvid">
                                            <input type="hidden" name="switch_name" value="<?php echo rep_h($row['last_run_switch']); ?>">
                                            <input type="hidden" name="interface" value="<?php echo rep_h($row['if_name']); ?>">
                                            <input type="hidden" name="vlan_id" value="<?php echo rep_h((string)(int)$row['expected_pvid']); ?>">
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-full bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 text-xs" title="Switch auf erwarteten PVID setzen" onclick="return confirm('PVID fuer <?php echo rep_h($row['if_name']); ?> auf <?php echo rep_h((string)(int)$row['expected_pvid']); ?> setzen?');">
                                                <i data-lucide="wrench" class="h-3 w-3"></i><span><?php echo rep_h(rep_t('snmp_button_fix_pvid', 'Fix PVID')); ?></span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canAutomationWrite && in_array('tagged-vlan-mismatch', $flags, true) && !empty($row['if_name']) && !empty($row['last_run_switch']) && $expectedTaggedCli !== ''): ?>
                                        <form method="post" action="reports.php?tab=drift" class="inline">
                                            <input type="hidden" name="csrf" value="<?php echo rep_h($auth->csrf()); ?>">
                                            <input type="hidden" name="action" value="corrective_enqueue">
                                            <input type="hidden" name="template_id" value="set_trunk_allowed_vlans">
                                            <input type="hidden" name="switch_name" value="<?php echo rep_h($row['last_run_switch']); ?>">
                                            <input type="hidden" name="interface" value="<?php echo rep_h($row['if_name']); ?>">
                                            <input type="hidden" name="allowed_vlans" value="<?php echo rep_h($expectedTaggedCli); ?>">
                                            <?php if (($row['expected_pvid'] ?? null) !== null): ?>
                                                <input type="hidden" name="pvid" value="<?php echo rep_h((string)(int)$row['expected_pvid']); ?>">
                                            <?php endif; ?>
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-full bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 text-xs" title="Switch auf erwartete Tagged-VLAN-Liste setzen" onclick="return confirm('Tagged-VLAN-Liste fuer <?php echo rep_h($row['if_name']); ?> auf <?php echo rep_h(rep_vlan_list_label($row['expected_tagged_vlans'] ?? '')); ?> setzen?');">
                                                <i data-lucide="wrench" class="h-3 w-3"></i><span><?php echo rep_h(rep_t('snmp_button_fix_trunk', 'Fix trunk')); ?></span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canAutomationWrite && (in_array('ip-mismatch', $flags, true) || in_array('ip-missing', $flags, true) || in_array('hostname-mismatch', $flags, true) || in_array('dhcp-mismatch', $flags, true)) && !empty($row['device_port_ip_uuid'])): ?>
                                        <form method="post" action="reports.php?tab=drift" class="inline">
                                            <input type="hidden" name="csrf" value="<?php echo rep_h($auth->csrf()); ?>">
                                            <input type="hidden" name="action" value="accept_port_ip_expected">
                                            <input type="hidden" name="device_port_uuid" value="<?php echo rep_h($row['device_port_uuid']); ?>">
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-full bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 text-xs" title="Expected auf aktuellen IP-Stand setzen" onclick="return confirm('Expected-Werte fuer IP/Hostname/DHCP dieses Ports auf den aktuellen Scan-Stand setzen?');">
                                                <i data-lucide="check" class="h-3 w-3"></i><span><?php echo rep_h(rep_t('snmp_button_align_expected', 'Align expected')); ?></span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canAutomationWrite && in_array('oper-down', $flags, true) && !empty($row['if_name']) && !empty($row['last_run_switch'])): ?>
                                        <form method="post" action="reports.php?tab=drift" class="inline">
                                            <input type="hidden" name="csrf" value="<?php echo rep_h($auth->csrf()); ?>">
                                            <input type="hidden" name="action" value="corrective_enqueue">
                                            <input type="hidden" name="template_id" value="no_shutdown_port">
                                            <input type="hidden" name="switch_name" value="<?php echo rep_h($row['last_run_switch']); ?>">
                                            <input type="hidden" name="interface" value="<?php echo rep_h($row['if_name']); ?>">
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-full bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 text-xs" title="Port aktivieren (in Warteschlange)" onclick="return confirm('Port-Aktivierung fuer <?php echo rep_h($row['if_name']); ?> auf <?php echo rep_h($row['last_run_switch']); ?> in Warteschlange einreihen?');">
                                                <i data-lucide="wrench" class="h-3 w-3"></i><span><?php echo rep_h(rep_t('snmp_button_fix', 'Fix')); ?></span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($portUuid !== ''): ?>
                                        <a href="<?php echo rep_h(rep_query_url($toggleIgnoreParams)); ?>" class="inline-flex items-center gap-1 rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100" title="<?php echo $isIgnored ? 'Ignorierung aufheben' : 'Port temporär ausblenden'; ?>">
                                            <i data-lucide="eye-off" class="h-3 w-3"></i><span><?php echo rep_h($isIgnored ? rep_t('snmp_button_unignore', 'Show') : rep_t('snmp_button_ignore', 'Ignore')); ?></span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($visibleDriftCount === 0): ?>
                        <tr><td colspan="13" class="p-4 text-center text-sm text-slate-500"><?php echo rep_h(rep_t('snmp_empty_drift', 'No SNMP data available yet. Run "Scan now" from the switch inventory.')); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($tab === 'unknown'): ?>
        <div class="space-y-4">
            <div class="rounded-2xl border border-slate-300 bg-white p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-slate-500">Unbekannte SNMP-Interfaces aus dem jeweils neuesten erfolgreichen Scan pro Switch. Virtuelle und System-Interfaces bleiben getrennt von prüfbedürftigen Ports.</p>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <?php echo rep_meta_badge('Switches ' . $unknownSwitchCount); ?>
                        <?php echo rep_meta_badge('Zu prüfen ' . $unknownCount); ?>
                        <?php echo rep_meta_badge('Virtuell/System ' . $unknownVirtualCount); ?>
                    </div>
                </div>
                <div class="max-h-[460px] overflow-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="p-2">Switch</th>
                                <th class="p-2">Run</th>
                                <th class="p-2">ifIndex</th>
                                <th class="p-2">ifName</th>
                                <th class="p-2">Alias</th>
                                <th class="p-2">IP</th>
                                <th class="p-2 text-right">PVID</th>
                                <th class="p-2">Admin</th>
                                <th class="p-2">Oper</th>
                                <th class="p-2 text-right">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($unknownRows as $row): ?>
                            <tr class="border-b border-slate-200 hover:bg-slate-50">
                                <td class="p-2 font-medium"><?php echo rep_h($row['switch_name'] ?? ''); ?></td>
                                <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['started'] ?? null)); ?></td>
                                <td class="p-2 font-mono text-xs"><?php echo (int)($row['if_index'] ?? 0); ?></td>
                                <td class="p-2 font-mono text-xs"><?php echo rep_h($row['if_name'] ?? ''); ?></td>
                                <td class="p-2 text-xs text-slate-500"><?php echo rep_h($row['if_alias'] ?? ''); ?></td>
                                <td class="p-2 font-mono text-xs text-cyan-700"><?php echo rep_h(trim((string)($row['ip_address'] ?? '')) !== '' ? (string)($row['ip_address'] ?? '') : '-'); ?></td>
                                <td class="p-2 text-right text-xs"><?php echo ($row['pvid'] ?? null) !== null ? (int)$row['pvid'] : '-'; ?></td>
                                <td class="p-2 text-xs"><?php echo rep_h(rep_admin_status_label(isset($row['admin']) ? (int)$row['admin'] : null)); ?></td>
                                <td class="p-2"><?php echo rep_oper_pill(isset($row['oper']) ? (int)$row['oper'] : null); ?></td>
                                <td class="p-2 text-right text-xs">
                                    <?php if (trim((string)($row['run_uuid'] ?? '')) !== ''): ?>
                                        <a href="?tab=runs&amp;run=<?php echo rep_h($row['run_uuid']); ?>" class="inline-flex items-center rounded-full border border-slate-300 bg-white px-3 py-1 font-semibold text-slate-600 hover:bg-slate-100">Run</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($unknownRows)): ?>
                            <tr><td colspan="10" class="p-4 text-center text-sm text-slate-500">Keine prüfbedürftigen unbekannten Interfaces im letzten Scan je Switch.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <details class="rounded-2xl border border-slate-300 bg-white overflow-hidden">
                <summary class="cursor-pointer border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">Virtuell / System (<?php echo $unknownVirtualCount; ?>)</summary>
                <div class="max-h-[360px] overflow-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="p-2">Switch</th>
                                <th class="p-2">Run</th>
                                <th class="p-2">ifIndex</th>
                                <th class="p-2">ifName</th>
                                <th class="p-2">Alias</th>
                                <th class="p-2">Oper</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($unknownVirtualRows as $row): ?>
                            <tr class="border-b border-slate-200 hover:bg-slate-50">
                                <td class="p-2 font-medium"><?php echo rep_h($row['switch_name'] ?? ''); ?></td>
                                <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['started'] ?? null)); ?></td>
                                <td class="p-2 font-mono text-xs"><?php echo (int)($row['if_index'] ?? 0); ?></td>
                                <td class="p-2 font-mono text-xs"><?php echo rep_h($row['if_name'] ?? ''); ?></td>
                                <td class="p-2 text-xs text-slate-500"><?php echo rep_h($row['if_alias'] ?? ''); ?></td>
                                <td class="p-2"><?php echo rep_oper_pill(isset($row['oper']) ? (int)$row['oper'] : null); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($unknownVirtualRows)): ?>
                            <tr><td colspan="6" class="p-4 text-center text-sm text-slate-500">Keine virtuellen oder System-Interfaces im letzten Scan je Switch.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>

    <?php elseif ($tab === 'stale'): ?>
        <div class="rounded-2xl border border-slate-300 bg-white p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-500">Ports ohne Aktivität in den letzten <?php echo (int)$staleDays; ?> Tagen oder ohne jemals beobachtete Aktivität.</p>
                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <?php echo rep_meta_badge('Stale ' . $staleCount); ?>
                    <?php echo rep_meta_badge('Schwelle ' . (int)$staleDays . ' Tage'); ?>
                </div>
            </div>
            <div class="max-h-[420px] overflow-auto">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="p-2">Gerät</th>
                        <th class="p-2">Port</th>
                        <th class="p-2">Oper</th>
                        <th class="p-2">Last seen active</th>
                        <th class="p-2">State updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staleRows as $row): ?>
                    <tr class="border-b border-slate-200 hover:bg-slate-50">
                        <td class="p-2"><?php echo rep_h($row['device_caption'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['port_caption'] ?? ''); ?></td>
                        <td class="p-2"><?php echo rep_oper_pill(isset($row['if_oper_status']) ? (int)$row['if_oper_status'] : null); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['last_seen_active'] ?? null)); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['updated'] ?? null)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($staleRows)): ?>
                    <tr><td colspan="5" class="p-4 text-center text-sm text-slate-500">Keine veralteten Ports erkannt.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

    <?php elseif ($tab === 'nodes'): ?>
        <div class="rounded-2xl border border-slate-300 bg-white p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-500">MAC-Adressen und erkannte IPs von Endgeräten, die per FDB und ARP an Switch-Ports beobachtet wurden. Suche in MAC, IP, Hostname, Port-Caption und Geräte-Caption.</p>
                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <?php echo rep_meta_badge('Nodes ' . $nodeCount); ?>
                    <?php echo rep_meta_badge('Treffer ' . count($nodeRows)); ?>
                </div>
            </div>
            <form method="get" action="reports.php" class="mb-3 flex flex-wrap items-center gap-2">
            <input type="hidden" name="tab" value="nodes">
            <input type="text" name="mac" value="<?php echo rep_h($nodeSearch); ?>" placeholder="MAC, Port oder Gerät suchen…" class="w-full min-w-[16rem] flex-1 rounded-full border border-slate-300 px-4 py-2 text-sm md:max-w-md">
            <button type="submit" class="inline-flex items-center gap-1 rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                <i data-lucide="search" class="h-4 w-4"></i><span>Suchen</span>
            </button>
            <?php if ($nodeSearch !== ''): ?>
                <a href="?tab=nodes" class="inline-flex items-center rounded-full border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100">Zurücksetzen</a>
            <?php endif; ?>
            </form>
            <div class="max-h-[500px] overflow-auto">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="p-2">MAC</th>
                        <th class="p-2">IP / Hostname</th>
                        <th class="p-2 text-right">VLAN</th>
                        <th class="p-2">Gerät</th>
                        <th class="p-2">Port</th>
                        <th class="p-2">First seen</th>
                        <th class="p-2">Last seen</th>
                        <th class="p-2">Last run</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($nodeRows as $row): ?>
                    <tr class="border-b border-slate-200 hover:bg-slate-50">
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['mac_address'] ?? ''); ?></td>
                        <td class="p-2 text-xs">
                            <div class="font-mono text-cyan-700"><?php echo rep_h(trim((string)($row['ip'] ?? '')) !== '' ? (string)($row['ip'] ?? '') : '-'); ?></div>
                            <?php if (trim((string)($row['hostname'] ?? '')) !== ''): ?>
                                <div class="text-slate-500"><?php echo rep_h($row['hostname'] ?? ''); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="p-2 text-right text-xs"><?php echo $row['vlan'] !== null ? (int)$row['vlan'] : '-'; ?></td>
                        <td class="p-2"><?php echo rep_h($row['device_caption'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['port_caption'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['first_seen'] ?? null)); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['last_seen'] ?? null)); ?></td>
                        <td class="p-2 text-xs text-slate-500"><?php echo rep_h($row['last_run_switch'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($nodeRows)): ?>
                    <tr><td colspan="8" class="p-4 text-center text-sm text-slate-500"><?php echo $nodeSearch === '' ? 'Noch keine FDB-/ARP-Nodes erfasst. Im nächsten Scan werden MAC-Adressen und, falls vorhanden, Endgeraete-IPs gesammelt.' : 'Keine Treffer.'; ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

    <?php elseif ($tab === 'topology'): ?>
        <div class="space-y-4">
            <div class="rounded-2xl border border-slate-300 bg-white p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-slate-500">LLDP-Nachbarn pro Switch-Port. Quelle: <span class="font-mono text-xs text-slate-700">LLDP-MIB::lldpRemTable</span>.</p>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <?php echo rep_meta_badge('Geräte ' . count($topologyNodes)); ?>
                        <?php echo rep_meta_badge('Uplinks ' . count($topologyEdges)); ?>
                        <?php echo rep_meta_badge('Interne Links ' . count(array_filter($topologyEdges, static fn(array $edge): bool => !empty($edge['managed_both'])))); ?>
                    </div>
                </div>
                <div class="max-h-[360px] overflow-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="p-2">Gerät A</th>
                                <th class="p-2">Gerät B</th>
                                <th class="p-2">Links</th>
                                <th class="p-2">Status</th>
                                <th class="p-2">Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topologyEdges as $edge): ?>
                            <tr class="border-b border-slate-200 hover:bg-slate-50">
                                <td class="p-2 font-medium"><?php echo rep_h($edge['left_label'] ?? ''); ?></td>
                                <td class="p-2 font-medium"><?php echo rep_h($edge['right_label'] ?? ''); ?></td>
                                <td class="p-2 text-xs text-slate-500"><?php echo rep_h(implode(' · ', array_keys((array)($edge['links'] ?? [])))); ?></td>
                                <td class="p-2 text-xs">
                                    <?php if (!empty($edge['managed_both']) && !empty($edge['unmapped_links'])): ?>
                                        <?php echo rep_soft_pill('Portflow-Link fehlt', 'amber'); ?>
                                    <?php elseif (!empty($edge['managed_both'])): ?>
                                        <?php echo rep_soft_pill('Gemappt', 'emerald'); ?>
                                    <?php else: ?>
                                        <?php echo rep_soft_pill('Extern', 'slate'); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2 text-xs"><?php echo rep_h(rep_dt($edge['last_seen'] ?? null)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topologyEdges)): ?>
                            <tr><td colspan="5" class="p-4 text-center text-sm text-slate-500">Noch keine verwertbaren Uplink-Beziehungen erkannt.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-300 bg-white p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-bold text-slate-900">LLDP-Nachbarn</h2>
                    <span class="text-xs text-slate-500"><?php echo count($topoRows); ?> Einträge</span>
                </div>
                <div class="max-h-[500px] overflow-auto">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="p-2">Lokales Gerät</th>
                        <th class="p-2">Lokaler Port</th>
                        <th class="p-2">Nachbar</th>
                        <th class="p-2">Remote Port</th>
                        <th class="p-2">Remote Beschreibung</th>
                        <th class="p-2">Chassis-ID</th>
                        <th class="p-2">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topoRows as $row): ?>
                    <tr class="border-b border-slate-200 hover:bg-slate-50">
                        <td class="p-2"><?php echo rep_h($row['device_caption'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['port_caption'] ?? ''); ?></td>
                        <td class="p-2 font-medium"><?php echo rep_h($row['remote_sys_name'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['remote_port_id'] ?? ''); ?></td>
                        <td class="p-2 text-xs text-slate-500"><?php echo rep_h($row['remote_port_desc'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs text-slate-500"><?php echo rep_h($row['remote_chassis_id'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['last_seen'] ?? null)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($topoRows)): ?>
                    <tr><td colspan="7" class="p-4 text-center text-sm text-slate-500">Noch keine LLDP-Nachbarn erfasst. Im nächsten Scan werden Topologie-Daten gesammelt.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
                </div>
            </div>
        </div>

    <?php else: /* runs */ ?>
        <?php if ($selectedRun !== null): ?>
            <?php
                $det = is_array($selectedRun['details'] ?? null) ? $selectedRun['details'] : [];
                $vlans = is_array($det['vlans'] ?? null) ? $det['vlans'] : [];
                $matched = is_array($det['matched_ports'] ?? null) ? $det['matched_ports'] : [];
                $unknown = is_array($det['unknown_interfaces'] ?? null) ? $det['unknown_interfaces'] : [];
                $actionUnknown = array_values(array_filter($unknown, static fn(array $row): bool => !rep_is_virtual_interface_name((string)($row['if_name'] ?? ''))));
                $virtualUnknown = array_values(array_filter($unknown, static fn(array $row): bool => rep_is_virtual_interface_name((string)($row['if_name'] ?? ''))));
                $ifaces = is_array($det['interfaces'] ?? null) ? $det['interfaces'] : [];
                $interfaceIps = is_array($det['interface_ips'] ?? null) ? $det['interface_ips'] : [];
                $nodeIps = is_array($det['node_ips'] ?? null) ? $det['node_ips'] : [];
                $nodeIpMatchCount = (int)($det['node_ip_match_count'] ?? 0);
                $nodeIpUnmatchedCount = (int)($det['node_ip_unmatched_count'] ?? 0);
                $nodesSeen = (int)($det['nodes_seen'] ?? 0);
                $nodesPersisted = (int)($det['nodes_persisted'] ?? 0);
                $nodeIpSources = is_array($det['node_ip_sources'] ?? null) ? $det['node_ip_sources'] : [];
                $scannerExtensionId = trim((string)($det['scanner_extension'] ?? ''));
                $scannerExtensionDiagnostics = is_array($det['scanner_extension_diagnostics'] ?? null) ? $det['scanner_extension_diagnostics'] : [];
                $nodeIpCollectionDiagnostics = is_array($scannerExtensionDiagnostics['node_ip_collection'] ?? null) ? $scannerExtensionDiagnostics['node_ip_collection'] : [];
                $nodeIpCollectionConnection = is_array($nodeIpCollectionDiagnostics['connection'] ?? null) ? $nodeIpCollectionDiagnostics['connection'] : [];
                $nodeIpCollectionExecution = is_array($nodeIpCollectionDiagnostics['execution'] ?? null) ? $nodeIpCollectionDiagnostics['execution'] : [];
                $mappedIpCount = count(array_filter($matched, static fn(array $row): bool => trim((string)($row['ip_address'] ?? '')) !== ''));
                $unknownIpCount = count(array_filter($unknown, static fn(array $row): bool => trim((string)($row['ip_address'] ?? '')) !== ''));
                $vlanSrc = (string)($det['vlan_source'] ?? '-');
                $nodeIpSourceSummary = '-';
                if (!empty($nodeIpSources)) {
                    ksort($nodeIpSources);
                    $parts = [];
                    foreach ($nodeIpSources as $source => $count) {
                        $parts[] = rep_h((string)$source) . ': ' . (int)$count;
                    }
                    $nodeIpSourceSummary = implode(' · ', $parts);
                }
                $scannerExtensionStatus = trim((string)($nodeIpCollectionDiagnostics['status'] ?? ''));
                $scannerExtensionMode = trim((string)($nodeIpCollectionDiagnostics['mode'] ?? ''));
                $scannerExtensionError = trim((string)($nodeIpCollectionDiagnostics['error'] ?? ''));
                $scannerExtensionPreview = trim((string)($nodeIpCollectionDiagnostics['output_preview'] ?? ($nodeIpCollectionExecution['output_preview'] ?? '')));
                $scannerExtensionCommands = is_array($nodeIpCollectionExecution['commands'] ?? null) ? $nodeIpCollectionExecution['commands'] : [];
                $scannerExtensionCommandSummary = empty($scannerExtensionCommands) ? '-' : implode(' | ', array_map(static fn($command): string => (string)$command, $scannerExtensionCommands));
                $scannerExtensionParsedCount = (int)($nodeIpCollectionDiagnostics['parsed_count'] ?? 0);
                $scannerExtensionExitCode = isset($nodeIpCollectionExecution['exit_code']) ? (string)$nodeIpCollectionExecution['exit_code'] : '-';
                $scannerExtensionHostSet = !empty($nodeIpCollectionConnection['host_set']) ? 'ja' : 'nein';
                $scannerExtensionUserSet = !empty($nodeIpCollectionConnection['username_set']) ? 'ja' : 'nein';
                $scannerExtensionPasswordSet = !empty($nodeIpCollectionConnection['password_set']) ? 'ja' : 'nein';
                $scannerExtensionKeySet = !empty($nodeIpCollectionConnection['private_key_set']) ? 'ja' : 'nein';
                $scannerExtensionSshpassFound = array_key_exists('sshpass_found', $nodeIpCollectionExecution) ? (!empty($nodeIpCollectionExecution['sshpass_found']) ? 'ja' : 'nein') : '-';
                $poeDiagnostics = is_array($scannerExtensionDiagnostics['poe'] ?? null) ? $scannerExtensionDiagnostics['poe'] : [];
                $poeDiagStatus = trim((string)($poeDiagnostics['status'] ?? ''));
                $poeDiagSource = trim((string)($poeDiagnostics['source'] ?? ''));
                $poeDiagEnableCount = isset($poeDiagnostics['enable_count']) ? (int)$poeDiagnostics['enable_count'] : 0;
                $poeDiagStatusCount = isset($poeDiagnostics['status_count']) ? (int)$poeDiagnostics['status_count'] : 0;
                $poeDiagClassCount = isset($poeDiagnostics['class_count']) ? (int)$poeDiagnostics['class_count'] : 0;
                $poeDiagConsumptionCount = isset($poeDiagnostics['consumption_count']) ? (int)$poeDiagnostics['consumption_count'] : 0;
                $poeDiagPortNameCount = isset($poeDiagnostics['port_name_count']) ? (int)$poeDiagnostics['port_name_count'] : 0;
                $poeDiagMainCount = isset($poeDiagnostics['main_consumption_count']) ? (int)$poeDiagnostics['main_consumption_count'] : 0;
                $poeDiagDataIndexCount = isset($poeDiagnostics['data_index_count']) ? (int)$poeDiagnostics['data_index_count'] : 0;
                $poeDiagResolvedPortCount = isset($poeDiagnostics['resolved_port_count']) ? (int)$poeDiagnostics['resolved_port_count'] : 0;
                $poeDiagRootProbeMode = trim((string)($poeDiagnostics['root_probe_mode'] ?? ''));
                $poeDiagRootProbeOidCount = isset($poeDiagnostics['root_probe_oid_count']) ? (int)$poeDiagnostics['root_probe_oid_count'] : 0;
                $poeDiagRootProbeStatus = trim((string)($poeDiagnostics['root_probe_status'] ?? ''));
                $poeDiagRootProbeSampleOid = trim((string)($poeDiagnostics['root_probe_sample_oid'] ?? ''));
                $poeDiagSampleIndices = is_array($poeDiagnostics['sample_indices'] ?? null) ? $poeDiagnostics['sample_indices'] : [];
                $poeDiagSampleSummary = empty($poeDiagSampleIndices) ? '-' : implode(' · ', array_map(static fn($value): string => (string)$value, $poeDiagSampleIndices));
                $poeCliDiagnostics = is_array($scannerExtensionDiagnostics['poe_cli'] ?? null) ? $scannerExtensionDiagnostics['poe_cli'] : [];
                $poeCliStatus = trim((string)($poeCliDiagnostics['status'] ?? ''));
                $poeCliMode = trim((string)($poeCliDiagnostics['mode'] ?? ''));
                $poeCliParsedRowCount = isset($poeCliDiagnostics['parsed_row_count']) ? (int)$poeCliDiagnostics['parsed_row_count'] : 0;
                $poeCliMatched = isset($poeCliDiagnostics['matched_port_count']) ? (int)$poeCliDiagnostics['matched_port_count'] : 0;
                $poeCliUnmatched = isset($poeCliDiagnostics['unmatched_port_count']) ? (int)$poeCliDiagnostics['unmatched_port_count'] : 0;
                $poeCliIfNameMapSize = isset($poeCliDiagnostics['ifname_map_size']) ? (int)$poeCliDiagnostics['ifname_map_size'] : 0;
                $poeCliUnmatchedSample = is_array($poeCliDiagnostics['unmatched_sample'] ?? null) ? $poeCliDiagnostics['unmatched_sample'] : [];
                $poeCliUnmatchedSummary = empty($poeCliUnmatchedSample) ? '-' : implode(' · ', array_map(static fn($value): string => (string)$value, $poeCliUnmatchedSample));
                $poeCliExitCode = isset($poeCliDiagnostics['execution']['exit_code']) ? (string)$poeCliDiagnostics['execution']['exit_code'] : '-';
                $genericPoeDiagnostics = is_array($det['generic_poe_diagnostics'] ?? null) ? $det['generic_poe_diagnostics'] : [];
                $genericPoeStatus = trim((string)($genericPoeDiagnostics['status'] ?? ''));
                $genericPoeSource = trim((string)($genericPoeDiagnostics['source'] ?? ''));
                $genericPoeAdminCount = isset($genericPoeDiagnostics['admin_count']) ? (int)$genericPoeDiagnostics['admin_count'] : 0;
                $genericPoeDetectionCount = isset($genericPoeDiagnostics['detection_count']) ? (int)$genericPoeDiagnostics['detection_count'] : 0;
                $genericPoeClassCount = isset($genericPoeDiagnostics['class_count']) ? (int)$genericPoeDiagnostics['class_count'] : 0;
                $genericPoeMainCount = isset($genericPoeDiagnostics['main_count']) ? (int)$genericPoeDiagnostics['main_count'] : 0;
                $genericPoeSampleIndices = is_array($genericPoeDiagnostics['sample_indices'] ?? null) ? $genericPoeDiagnostics['sample_indices'] : [];
                $genericPoeSampleSummary = empty($genericPoeSampleIndices) ? '-' : implode(' · ', array_map(static fn($value): string => (string)$value, $genericPoeSampleIndices));
                $rNeighbors = is_array($det['neighbors'] ?? null) ? $det['neighbors'] : [];
                $rPoe = is_array($det['poe_ports'] ?? null) ? $det['poe_ports'] : [];
                $rPoeMain = is_array($det['poe_main_consumption'] ?? null) ? $det['poe_main_consumption'] : [];
                $rEntity = is_array($det['entity_inventory'] ?? null) ? $det['entity_inventory'] : [];
            ?>
            <div class="rounded-2xl border border-slate-300 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <a href="?tab=runs" class="inline-flex items-center gap-1 rounded-full border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            <i data-lucide="arrow-left" class="h-4 w-4"></i><span>Zur Übersicht</span>
                        </a>
                        <h2 class="mt-2 text-xl font-bold text-slate-900">Scan-Lauf: <?php echo rep_h($selectedRun['switch_name'] ?? ''); ?></h2>
                        <p class="text-sm text-slate-500">
                            <?php echo rep_h(rep_dt($selectedRun['started'] ?? null)); ?> &rarr; <?php echo rep_h(rep_dt($selectedRun['finished'] ?? null)); ?>
                            &nbsp;·&nbsp; Trigger: <?php echo rep_h($selectedRun['trigger'] ?? ''); ?>
                            &nbsp;·&nbsp; <?php echo rep_run_status_pill((string)($selectedRun['status'] ?? '')); ?>
                        </p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <?php echo rep_meta_badge('Interfaces ' . (int)($selectedRun['interfaces_seen'] ?? 0)); ?>
                    <?php echo rep_meta_badge('VLANs ' . (int)($selectedRun['vlans_seen'] ?? 0)); ?>
                    <?php echo rep_meta_badge('Gemappt ' . count($matched)); ?>
                    <?php echo rep_meta_badge('Zu prüfen ' . count($actionUnknown)); ?>
                    <?php echo rep_meta_badge('Virtuell/System ' . count($virtualUnknown)); ?>
                    <?php echo rep_meta_badge('Interface-IPs ' . count($interfaceIps)); ?>
                    <?php echo rep_meta_badge('Node-IPs ' . count($nodeIps)); ?>
                    <?php echo rep_meta_badge('FDB-Matches ' . $nodeIpMatchCount); ?>
                    <?php echo rep_meta_badge('VLAN-Quelle ' . ($vlanSrc !== '' ? $vlanSrc : '-')); ?>
                </div>
            </div>

            <?php if (!empty($selectedRun['message'])): ?>
                <div class="rounded-2xl border border-rose-200 bg-white px-4 py-3 text-sm text-rose-800">
                    <?php echo rep_h($selectedRun['message']); ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                <div class="space-y-4">
                    <section class="rounded-2xl border border-slate-300 bg-white p-4">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Zugeordnete Ports</h3>
                                <p class="text-xs text-slate-500">Caption-Match, Status, PVID und erkannte IP auf einen Blick.</p>
                            </div>
                            <span class="text-xs text-slate-500"><?php echo count($matched); ?> Ports · <?php echo $mappedIpCount; ?> mit IP</span>
                        </div>
                        <div class="max-h-[500px] overflow-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500"><tr>
                                    <th class="px-3 py-1">Caption</th><th class="px-3 py-1">ifName</th><th class="px-3 py-1">Alias</th>
                                    <th class="px-3 py-1 text-right">Speed</th><th class="px-3 py-1">MAC</th><th class="px-3 py-1">IP</th>
                                    <th class="px-3 py-1 text-right">PVID</th><th class="px-3 py-1">Admin</th><th class="px-3 py-1">Oper</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($matched as $m): ?>
                                    <tr class="border-b border-slate-200 hover:bg-slate-50">
                                        <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($m['caption'] ?? ''); ?></td>
                                        <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($m['if_name'] ?? ''); ?></td>
                                        <td class="px-3 py-1 text-xs text-slate-500"><?php echo rep_h($m['if_alias'] ?? ''); ?></td>
                                        <td class="px-3 py-1 text-right text-xs"><?php echo $m['speed'] !== null ? (int)$m['speed'] : '-'; ?></td>
                                        <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($m['mac'] ?? ''); ?></td>
                                        <td class="px-3 py-1 font-mono text-xs text-cyan-700"><?php echo rep_h($m['ip_address'] ?? ''); ?></td>
                                        <td class="px-3 py-1 text-right text-xs"><?php echo $m['pvid'] !== null ? (int)$m['pvid'] : '-'; ?></td>
                                        <td class="px-3 py-1 text-xs"><?php echo rep_h(rep_admin_status_label(isset($m['admin']) ? (int)$m['admin'] : null)); ?></td>
                                        <td class="px-3 py-1"><?php echo rep_oper_pill(isset($m['oper']) ? (int)$m['oper'] : null); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($matched)): ?>
                                    <tr><td colspan="9" class="px-3 py-3 text-center text-xs text-slate-500">Keine Ports konnten per Caption zugeordnet werden.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-300 bg-white p-4">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Zu prüfen</h3>
                                <p class="text-xs text-slate-500">Unbekannte Interfaces mit operativer Relevanz und möglicher Zuordnung.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <?php echo rep_meta_badge('Zu prüfen ' . count($actionUnknown)); ?>
                                <?php echo rep_meta_badge('Unbekannte IPs ' . $unknownIpCount); ?>
                            </div>
                        </div>
                        <div class="max-h-[420px] overflow-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-1">ifIndex</th><th class="px-3 py-1">ifName</th><th class="px-3 py-1">Alias</th><th class="px-3 py-1">IP</th><th class="px-3 py-1">Oper</th></tr></thead>
                                <tbody>
                                <?php foreach ($actionUnknown as $u): ?>
                                    <tr class="border-b border-slate-200 hover:bg-slate-50">
                                        <td class="px-3 py-1 font-mono"><?php echo (int)($u['if_index'] ?? 0); ?></td>
                                        <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($u['if_name'] ?? ''); ?></td>
                                        <td class="px-3 py-1 text-xs text-slate-500"><?php echo rep_h($u['if_alias'] ?? ''); ?></td>
                                        <td class="px-3 py-1 font-mono text-xs text-cyan-700"><?php echo rep_h($u['ip_address'] ?? ''); ?></td>
                                        <td class="px-3 py-1"><?php echo rep_oper_pill(isset($u['oper']) ? (int)$u['oper'] : null); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($actionUnknown)): ?>
                                    <tr><td colspan="5" class="px-3 py-3 text-center text-xs text-slate-500">Keine prüfbedürftigen unbekannten Interfaces.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <details class="rounded-2xl border border-slate-300 bg-white overflow-hidden">
                        <summary class="cursor-pointer border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">Alle SNMP-Interfaces (<?php echo count($ifaces); ?>)</summary>
                        <div class="max-h-[420px] overflow-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500"><tr>
                                    <th class="px-3 py-1">ifIndex</th><th class="px-3 py-1">ifName</th><th class="px-3 py-1">Alias</th>
                                    <th class="px-3 py-1 text-right">Speed</th><th class="px-3 py-1">Admin</th><th class="px-3 py-1">Oper</th><th class="px-3 py-1 text-right">PVID</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($ifaces as $ifc): ?>
                                    <tr class="border-b border-slate-200 hover:bg-slate-50">
                                        <td class="px-3 py-1 font-mono text-xs"><?php echo (int)($ifc['if_index'] ?? 0); ?></td>
                                        <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($ifc['if_name'] ?? ''); ?></td>
                                        <td class="px-3 py-1 text-xs text-slate-500"><?php echo rep_h($ifc['if_alias'] ?? ''); ?></td>
                                        <td class="px-3 py-1 text-right text-xs"><?php echo $ifc['if_high_speed'] !== null ? (int)$ifc['if_high_speed'] : '-'; ?></td>
                                        <td class="px-3 py-1 text-xs"><?php echo rep_h(rep_admin_status_label(isset($ifc['if_admin_status']) ? (int)$ifc['if_admin_status'] : null)); ?></td>
                                        <td class="px-3 py-1"><?php echo rep_oper_pill(isset($ifc['if_oper_status']) ? (int)$ifc['if_oper_status'] : null); ?></td>
                                        <td class="px-3 py-1 text-right text-xs"><?php echo $ifc['pvid'] !== null ? (int)$ifc['pvid'] : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>

                <div class="space-y-4">
                    <section class="rounded-2xl border border-slate-300 bg-white p-4">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Virtuell / System</h3>
                                <p class="text-xs text-slate-500">Lange virtuelle und System-Interfaces bleiben getrennt und scrollen innerhalb der Card.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <?php echo rep_meta_badge('Virtuell/System ' . count($virtualUnknown)); ?>
                            </div>
                        </div>
                        <div class="max-h-[360px] overflow-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-1">ifIndex</th><th class="px-3 py-1">ifName</th><th class="px-3 py-1">Alias</th><th class="px-3 py-1">Oper</th></tr></thead>
                                <tbody>
                                <?php foreach ($virtualUnknown as $u): ?>
                                    <tr class="border-b border-slate-200 hover:bg-slate-50">
                                        <td class="px-3 py-1 font-mono"><?php echo (int)($u['if_index'] ?? 0); ?></td>
                                        <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($u['if_name'] ?? ''); ?></td>
                                        <td class="px-3 py-1 text-xs text-slate-500"><?php echo rep_h($u['if_alias'] ?? ''); ?></td>
                                        <td class="px-3 py-1"><?php echo rep_oper_pill(isset($u['oper']) ? (int)$u['oper'] : null); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($virtualUnknown)): ?>
                                    <tr><td colspan="4" class="px-3 py-3 text-center text-xs text-slate-500">Keine virtuellen/System-Interfaces vorhanden.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-300 bg-white p-4">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-slate-900">VLANs</h3>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <?php echo rep_meta_badge('VLANs ' . count($vlans)); ?>
                                <?php echo rep_meta_badge('Quelle ' . ($vlanSrc !== '' ? $vlanSrc : '-')); ?>
                            </div>
                        </div>
                        <div class="max-h-[320px] overflow-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-1">ID</th><th class="px-3 py-1">Name</th></tr></thead>
                                <tbody>
                                <?php foreach ($vlans as $v): ?>
                                    <tr class="border-b border-slate-200 hover:bg-slate-50"><td class="px-3 py-1 font-mono"><?php echo (int)($v['id'] ?? 0); ?></td><td class="px-3 py-1 text-xs"><?php echo rep_h($v['name'] ?? ''); ?></td></tr>
                                <?php endforeach; ?>
                                <?php if (empty($vlans)): ?>
                                    <tr><td colspan="2" class="px-3 py-3 text-center text-xs text-slate-500">Keine VLANs erkannt.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-300 bg-white p-4">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Node-IPs und FDB</h3>
                                <p class="text-xs text-slate-500">Quellen und rohe IP-Erkennung aus ARP-/Neighbor-Daten.</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <?php echo rep_meta_badge('Node-IPs ' . count($nodeIps)); ?>
                                <?php echo rep_meta_badge('FDB-Nodes ' . $nodesSeen); ?>
                                <?php echo rep_meta_badge('Persistiert ' . $nodesPersisted); ?>
                            </div>
                        </div>
                        <div class="mb-3 text-xs text-slate-500">Quellen: <?php echo rep_h($nodeIpSourceSummary); ?> · Ohne FDB-Match: <?php echo $nodeIpUnmatchedCount; ?></div>
                        <?php if (!empty($nodeIps)): ?>
                            <div class="max-h-[360px] overflow-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-1">ifIndex</th><th class="px-3 py-1">MAC</th><th class="px-3 py-1">IP</th><th class="px-3 py-1">Hostname</th><th class="px-3 py-1">Quelle</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($nodeIps as $nodeIp): ?>
                                        <tr class="border-b border-slate-200 hover:bg-slate-50">
                                            <td class="px-3 py-1 font-mono text-xs"><?php echo (int)($nodeIp['if_index'] ?? 0); ?></td>
                                            <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($nodeIp['mac'] ?? ''); ?></td>
                                            <td class="px-3 py-1 font-mono text-xs text-cyan-700"><?php echo rep_h($nodeIp['ip'] ?? ''); ?></td>
                                            <td class="px-3 py-1 text-xs text-slate-500"><?php echo rep_h($nodeIp['hostname'] ?? ''); ?></td>
                                            <td class="px-3 py-1 text-xs"><?php echo rep_h($nodeIp['source'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="rounded-2xl border border-slate-300 px-4 py-6 text-center text-sm text-slate-500">Keine rohen Node-IPs für diesen Lauf erkannt.</div>
                        <?php endif; ?>
                    </section>

                    <?php if ($scannerExtensionId !== ''): ?>
                        <details class="rounded-2xl border border-slate-300 bg-white overflow-hidden">
                            <summary class="cursor-pointer border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">Scanner-Extension · <?php echo rep_h($scannerExtensionId); ?></summary>
                            <div class="grid grid-cols-1 gap-2 px-4 py-3 text-sm md:grid-cols-2">
                                <div><span class="font-semibold text-slate-900">Node-IP-Modus:</span> <?php echo rep_h($scannerExtensionMode !== '' ? $scannerExtensionMode : '-'); ?></div>
                                <div><span class="font-semibold text-slate-900">Status:</span> <?php echo rep_h($scannerExtensionStatus !== '' ? $scannerExtensionStatus : '-'); ?></div>
                                <div><span class="font-semibold text-slate-900">PoE-Quelle:</span> <?php echo rep_h($poeDiagSource !== '' ? $poeDiagSource : '-'); ?></div>
                                <div><span class="font-semibold text-slate-900">PoE-Status:</span> <?php echo rep_h($poeDiagStatus !== '' ? $poeDiagStatus : '-'); ?></div>
                                <div><span class="font-semibold text-slate-900">PoE Enable-Einträge:</span> <?php echo $poeDiagEnableCount; ?></div>
                                <div><span class="font-semibold text-slate-900">PoE Status-Einträge:</span> <?php echo $poeDiagStatusCount; ?></div>
                                <div><span class="font-semibold text-slate-900">PoE Klassen-Einträge:</span> <?php echo $poeDiagClassCount; ?></div>
                                <div><span class="font-semibold text-slate-900">PoE Verbrauch-Einträge:</span> <?php echo $poeDiagConsumptionCount; ?></div>
                                <div><span class="font-semibold text-slate-900">PoE Main-Power-Einträge:</span> <?php echo $poeDiagMainCount; ?></div>
                                <div><span class="font-semibold text-slate-900">PoE ausgewertete Ports:</span> <?php echo $poeDiagResolvedPortCount; ?></div>
                                <div><span class="font-semibold text-slate-900">Credential-Mode:</span> <?php echo rep_h((string)($nodeIpCollectionConnection['credential_mode'] ?? '-')); ?></div>
                                <div><span class="font-semibold text-slate-900">SSH Auth:</span> <?php echo rep_h((string)($nodeIpCollectionConnection['auth_method'] ?? '-')); ?></div>
                                <div><span class="font-semibold text-slate-900">Exit-Code:</span> <?php echo rep_h($scannerExtensionExitCode); ?></div>
                                <div><span class="font-semibold text-slate-900">Geparste CLI-IPs:</span> <?php echo $scannerExtensionParsedCount; ?></div>
                                <div class="md:col-span-2"><span class="font-semibold text-slate-900">CLI-Kommandos:</span> <?php echo rep_h($scannerExtensionCommandSummary); ?></div>
                                <div class="md:col-span-2"><span class="font-semibold text-slate-900">PoE Beispiel-Indizes:</span> <?php echo rep_h($poeDiagSampleSummary); ?></div>
                                <?php if ($poeCliMode !== '' || $poeCliStatus !== '' || $poeCliParsedRowCount > 0): ?>
                                    <div class="md:col-span-2 mt-1 border-t border-slate-200 pt-2 text-xs uppercase tracking-wide text-slate-500">PoE CLI-Fallback</div>
                                    <div><span class="font-semibold text-slate-900">CLI-Modus:</span> <?php echo rep_h($poeCliMode !== '' ? $poeCliMode : '-'); ?></div>
                                    <div><span class="font-semibold text-slate-900">CLI-Status:</span> <?php echo rep_h($poeCliStatus !== '' ? $poeCliStatus : '-'); ?></div>
                                    <div><span class="font-semibold text-slate-900">Parsed-Rows:</span> <?php echo $poeCliParsedRowCount; ?></div>
                                    <div><span class="font-semibold text-slate-900">ifName-Map:</span> <?php echo $poeCliIfNameMapSize; ?></div>
                                    <div><span class="font-semibold text-slate-900">Gemappt:</span> <?php echo $poeCliMatched; ?></div>
                                    <div><span class="font-semibold text-slate-900">Ungemappt:</span> <?php echo $poeCliUnmatched; ?></div>
                                    <div><span class="font-semibold text-slate-900">CLI Exit-Code:</span> <?php echo rep_h($poeCliExitCode); ?></div>
                                    <div class="md:col-span-2"><span class="font-semibold text-slate-900">Ungemappte Ports:</span> <?php echo rep_h($poeCliUnmatchedSummary); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($scannerExtensionError !== ''): ?>
                                <div class="border-t border-rose-200 px-4 py-3 text-sm text-rose-800"><?php echo rep_h($scannerExtensionError); ?></div>
                            <?php endif; ?>
                            <?php if ($scannerExtensionPreview !== ''): ?>
                                <div class="border-t border-slate-200 px-4 py-3">
                                    <div class="pb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">CLI-Output Preview</div>
                                    <pre class="whitespace-pre-wrap text-xs leading-5 text-slate-700"><?php echo rep_h($scannerExtensionPreview); ?></pre>
                                </div>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>

                    <details class="rounded-2xl border border-slate-300 bg-white overflow-hidden">
                        <summary class="cursor-pointer border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">Generische PoE-Diagnostik</summary>
                        <div class="grid grid-cols-1 gap-2 px-4 py-3 text-sm md:grid-cols-2">
                            <div><span class="font-semibold text-slate-900">Quelle:</span> <?php echo rep_h($genericPoeSource !== '' ? $genericPoeSource : '-'); ?></div>
                            <div><span class="font-semibold text-slate-900">Status:</span> <?php echo rep_h($genericPoeStatus !== '' ? $genericPoeStatus : '-'); ?></div>
                            <div><span class="font-semibold text-slate-900">Admin-Einträge:</span> <?php echo $genericPoeAdminCount; ?></div>
                            <div><span class="font-semibold text-slate-900">Detection-Einträge:</span> <?php echo $genericPoeDetectionCount; ?></div>
                            <div><span class="font-semibold text-slate-900">Klassen-Einträge:</span> <?php echo $genericPoeClassCount; ?></div>
                            <div><span class="font-semibold text-slate-900">Main-Power-Einträge:</span> <?php echo $genericPoeMainCount; ?></div>
                            <div class="md:col-span-2"><span class="font-semibold text-slate-900">Beispiel-Indizes:</span> <?php echo rep_h($genericPoeSampleSummary); ?></div>
                        </div>
                    </details>

                    <?php if (!empty($rNeighbors)): ?>
                        <details class="rounded-2xl border border-slate-300 bg-white overflow-hidden" open>
                            <summary class="cursor-pointer border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">LLDP-Nachbarn (<?php echo count($rNeighbors); ?>)</summary>
                            <div class="max-h-[320px] overflow-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-1">Lokal ifIndex</th><th class="px-3 py-1">Nachbar</th><th class="px-3 py-1">Remote Port</th><th class="px-3 py-1">Beschreibung</th><th class="px-3 py-1">Chassis</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($rNeighbors as $n): ?>
                                        <tr class="border-b border-slate-200 hover:bg-slate-50">
                                            <td class="px-3 py-1 font-mono text-xs"><?php echo (int)($n['local_if_index'] ?? 0); ?></td>
                                            <td class="px-3 py-1 font-medium"><?php echo rep_h($n['sys_name'] ?? ''); ?></td>
                                            <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($n['port_id'] ?? ''); ?></td>
                                            <td class="px-3 py-1 text-xs text-slate-500"><?php echo rep_h($n['port_desc'] ?? ''); ?></td>
                                            <td class="px-3 py-1 font-mono text-xs text-slate-500"><?php echo rep_h($n['chassis_id'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endif; ?>

                    <?php if (!empty($rPoe) || !empty($rPoeMain)): ?>
                        <details class="rounded-2xl border border-slate-300 bg-white overflow-hidden">
                            <summary class="cursor-pointer border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">PoE (<?php echo count($rPoe); ?> Ports<?php if (!empty($rPoeMain)): ?>, Verbrauch: <?php echo rep_h(implode(' / ', array_map('strval', $rPoeMain))); ?> W<?php endif; ?>)</summary>
                            <div class="max-h-[320px] overflow-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-1">Group.Port</th><th class="px-3 py-1">Admin</th><th class="px-3 py-1">Detection</th><th class="px-3 py-1 text-right">Klasse</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($rPoe as $p): ?>
                                        <tr class="border-b border-slate-200 hover:bg-slate-50">
                                            <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($p['port_idx'] ?? ''); ?><?php if (trim((string)($p['if_name'] ?? '')) !== ''): ?><div class="font-sans text-[11px] text-slate-500"><?php echo rep_h($p['if_name'] ?? ''); ?></div><?php endif; ?></td>
                                            <td class="px-3 py-1 text-xs"><?php echo (int)($p['admin'] ?? 0) === 1 ? 'enabled' : 'disabled'; ?></td>
                                            <td class="px-3 py-1 text-xs"><?php
                                                $det_label = match ((int)($p['detection'] ?? 0)) { 1 => 'disabled', 2 => 'searching', 3 => 'deliveringPower', 4 => 'fault', 5 => 'test', 6 => 'otherFault', default => '-' };
                                                echo rep_h($det_label);
                                            ?></td>
                                            <td class="px-3 py-1 text-right text-xs"><?php echo $p['class'] !== null ? (int)$p['class'] : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endif; ?>

                    <?php if (!empty($rEntity)): ?>
                        <details class="rounded-2xl border border-slate-300 bg-white overflow-hidden">
                            <summary class="cursor-pointer border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-900">Hardware-Inventar (ENTITY-MIB, <?php echo count($rEntity); ?>)</summary>
                            <div class="max-h-[320px] overflow-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-1">Idx</th><th class="px-3 py-1">Klasse</th><th class="px-3 py-1">Name</th><th class="px-3 py-1">Modell</th><th class="px-3 py-1">Serial</th><th class="px-3 py-1">Beschreibung</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($rEntity as $e): ?>
                                        <?php $clsLabel = match ((int)($e['class'] ?? 0)) { 1 => 'other', 2 => 'unknown', 3 => 'chassis', 4 => 'backplane', 5 => 'container', 6 => 'powerSupply', 7 => 'fan', 8 => 'sensor', 9 => 'module', 10 => 'port', 11 => 'stack', 12 => 'cpu', default => (string)($e['class'] ?? '-') }; ?>
                                        <tr class="border-b border-slate-200 hover:bg-slate-50">
                                            <td class="px-3 py-1 font-mono text-xs"><?php echo (int)($e['idx'] ?? 0); ?></td>
                                            <td class="px-3 py-1 text-xs"><?php echo rep_h($clsLabel); ?></td>
                                            <td class="px-3 py-1 text-xs"><?php echo rep_h($e['name'] ?? ''); ?></td>
                                            <td class="px-3 py-1 text-xs"><?php echo rep_h($e['model'] ?? ''); ?></td>
                                            <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($e['serial'] ?? ''); ?></td>
                                            <td class="px-3 py-1 text-xs text-slate-500"><?php echo rep_h($e['descr'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
        <div class="rounded-2xl border border-slate-300 bg-white p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-500">Die Historie zeigt pro Lauf die Kernsignale für Discovery-Qualität. Ein Klick auf eine Zeile öffnet die Detaildiagnostik des jeweiligen Scans.</p>
                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <?php echo rep_meta_badge('Runs ' . $runsCount); ?>
                    <?php if (is_array($latestRunRow)): ?>
                        <?php echo rep_meta_badge('Neuester Status ' . (string)($latestRunRow['status'] ?? '-')); ?>
                        <?php echo rep_meta_badge('Neueste Findings ' . (string)(int)($latestRunRow['findings_total'] ?? 0)); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="max-h-[500px] overflow-auto">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="sticky top-0 z-10 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="p-2">Status</th>
                        <th class="p-2">Switch</th>
                        <th class="p-2">Trigger</th>
                        <th class="p-2">Start</th>
                        <th class="p-2">Ende</th>
                        <th class="p-2 text-right">Interfaces</th>
                        <th class="p-2 text-right">VLANs</th>
                        <th class="p-2 text-right">Findings</th>
                        <th class="p-2">Benutzer</th>
                        <th class="p-2">Meldung</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($runsRows as $row): $rUuid = (string)($row['uuid'] ?? ''); ?>
                    <?php
                        $rowDetails = is_array($row['details'] ?? null) ? $row['details'] : [];
                        $rowMatchedPorts = is_array($rowDetails['matched_ports'] ?? null) ? count($rowDetails['matched_ports']) : 0;
                        $rowNodesPersisted = (int)($rowDetails['nodes_persisted'] ?? 0);
                    ?>
                    <tr class="cursor-pointer border-b border-slate-200 hover:bg-slate-50" onclick="window.location='?tab=runs&amp;run=<?php echo rep_h($rUuid); ?>'">
                        <td class="p-2"><?php echo rep_run_status_pill((string)($row['status'] ?? 'running')); ?></td>
                        <td class="p-2 font-medium text-slate-900"><?php echo rep_h($row['switch_name'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['trigger'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['started'] ?? null)); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h(rep_dt($row['finished'] ?? null)); ?></td>
                        <td class="p-2 text-right"><?php echo (int)($row['interfaces_seen'] ?? 0); ?><div class="text-[10px] text-slate-400">Ports <?php echo $rowMatchedPorts; ?></div></td>
                        <td class="p-2 text-right"><?php echo (int)($row['vlans_seen'] ?? 0); ?></td>
                        <td class="p-2 text-right"><?php echo (int)($row['findings_total'] ?? 0); ?><div class="text-[10px] text-slate-400">Nodes <?php echo $rowNodesPersisted; ?></div></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['username'] ?? ''); ?></td>
                        <td class="p-2 text-xs text-slate-500"><?php echo rep_h(mb_strimwidth((string)($row['message'] ?? ''), 0, 120, '…')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($runsRows)): ?>
                    <tr><td colspan="10" class="p-4 text-center text-sm text-slate-500">Noch keine Scan-Läufe vorhanden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

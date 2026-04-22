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

// --- POST: enqueue corrective change -----------------------------------
$enqueueResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'corrective_enqueue') {
    if (!$auth->csrf_check()) {
        $enqueueResult = ['ok' => false, 'message' => 'CSRF token invalid'];
    } else {
        $switchName = trim((string)($_POST['switch_name'] ?? ''));
        $templateId = trim((string)($_POST['template_id'] ?? ''));
        $ifName = trim((string)($_POST['interface'] ?? ''));
        $extraVlanId = trim((string)($_POST['vlan_id'] ?? ''));
        $userUuid = $_SESSION['uuid'] ?? null;

        $allowedTemplates = ['no_shutdown_port', 'shutdown_port', 'set_port_pvid', 'set_port_description'];
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
            if (is_array($autoTpl) && isset($autoTpl['templates'][$templateId]['commands'])) {
                foreach ($autoTpl['templates'][$templateId]['commands'] as $cmd) {
                    $rendered = (string)$cmd;
                    foreach ($variables as $k => $v) {
                        $rendered = str_replace('{{' . $k . '}}', (string)$v, $rendered);
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
                dp.speed AS configured_speed,
                dp.mac_address AS configured_mac,
                s.if_index, s.if_name, s.if_alias,
                s.if_admin_status, s.if_oper_status,
                s.last_seen_active, s.updated AS state_updated,
                r.switch_name AS last_run_switch,
                r.started AS last_run_started
         FROM device_port_snmp_state s
         JOIN device_port dp ON dp.uuid = s.device_port
         LEFT JOIN device d ON d.uuid = dp.device
         LEFT JOIN snmp_scan_run r ON r.uuid = s.last_scan_run
         ORDER BY s.updated DESC
         LIMIT 500"
    ) ?: [];
} catch (\Throwable $e) {
    $driftRows = [];
}

// --- Stale ports (oper down / never seen active in N days) -------------
$staleDays = 30;
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
         WHERE s.last_seen_active IS NULL
            OR s.last_seen_active < (CURRENT_DATE - INTERVAL '" . (int)$staleDays . " days')
         ORDER BY s.last_seen_active NULLS FIRST
         LIMIT 200"
    ) ?: [];
} catch (\Throwable $e) {
    $staleRows = [];
}

// Helpers
function rep_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
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

// Pre-compute drift cells: which rows have differences worth flagging
$driftFlagged = [];
foreach ($driftRows as $i => $row) {
    $flags = [];
    // Operational down on a port that has a configured caption
    if ((int)($row['if_oper_status'] ?? 0) === 2) { $flags[] = 'oper-down'; }
    // SNMP if_name vs configured port_caption mismatch (case-insensitive)
    $cfg = strtolower(trim((string)($row['port_caption'] ?? '')));
    $snm = strtolower(trim((string)($row['if_name'] ?? '')));
    if ($cfg !== '' && $snm !== '' && $cfg !== $snm) { $flags[] = 'name-mismatch'; }
    $driftFlagged[$i] = $flags;
}
$driftCount = 0;
foreach ($driftFlagged as $f) { if ($f) { $driftCount++; } }
$staleCount = count($staleRows);
$runsCount = count($runsRows);

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
            $where = 'WHERE n.mac_address ILIKE :q OR dp_cap.caption ILIKE :q OR d_cap.caption ILIKE :q';
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
    'drift' => ['label' => 'Drift (' . $driftCount . ')', 'icon' => 'alert-triangle'],
    'stale' => ['label' => 'Stale Ports (' . $staleCount . ')', 'icon' => 'eye-off'],
    'nodes' => ['label' => 'Nodes (' . $nodeCount . ')', 'icon' => 'network'],
    'topology' => ['label' => 'Topology', 'icon' => 'share-2'],
    'runs' => ['label' => 'Scan Runs (' . $runsCount . ')', 'icon' => 'history'],
];

// --- Topology / LLDP neighbors ----------------------------------------
$topoRows = [];
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
}
?>

<div class="mx-4 mb-4 mt-0 rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
    <?php if ($enqueueResult !== null): ?>
        <div class="mb-3 rounded-xl px-4 py-3 text-sm <?php echo $enqueueResult['ok'] ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'; ?>">
            <?php echo rep_h($enqueueResult['message'] ?? ''); ?>
        </div>
    <?php endif; ?>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">SNMP Reports</h1>
            <p class="text-sm text-slate-500">Discovery-Status, Drift und Scan-Historie.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="settings.php?site=scripts&amp;tab=switch" class="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                <i data-lucide="radar" class="h-4 w-4"></i><span>Inventar</span>
            </a>
        </div>
    </div>

    <nav class="mb-4 flex flex-wrap gap-2 border-b border-slate-200">
        <?php foreach ($tabs as $tabKey => $tabMeta): ?>
            <a href="?tab=<?php echo rep_h($tabKey); ?>"
               class="-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold <?php echo $tab === $tabKey ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-600 hover:text-slate-900'; ?>">
                <i data-lucide="<?php echo rep_h($tabMeta['icon']); ?>" class="h-4 w-4"></i>
                <span><?php echo rep_h($tabMeta['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'drift'): ?>
        <div class="overflow-x-auto rounded-xl border border-slate-300 bg-white">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="bg-slate-100 text-slate-900">
                    <tr>
                        <th class="p-2">Status</th>
                        <th class="p-2">Gerät</th>
                        <th class="p-2">Konfig. Port</th>
                        <th class="p-2">SNMP ifName</th>
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
                    $flags = $driftFlagged[$i] ?? [];
                    $hasFlag = !empty($flags);
                ?>
                    <tr class="border-t border-slate-100 <?php echo $hasFlag ? 'bg-amber-50' : ''; ?>">
                        <td class="p-2">
                            <?php if ($hasFlag): ?>
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                    <i data-lucide="alert-triangle" class="h-3 w-3"></i>Drift
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
                        <td class="p-2 text-xs text-slate-500"><?php echo rep_h($row['if_alias'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h(rep_admin_status_label(isset($row['if_admin_status']) ? (int)$row['if_admin_status'] : null)); ?></td>
                        <td class="p-2"><?php echo rep_oper_pill(isset($row['if_oper_status']) ? (int)$row['if_oper_status'] : null); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['last_seen_active'] ?? '-'); ?></td>
                        <td class="p-2 text-xs">
                            <?php echo rep_h($row['last_run_switch'] ?? ''); ?><br>
                            <span class="text-slate-400"><?php echo rep_h($row['last_run_started'] ?? ''); ?></span>
                        </td>
                        <td class="p-2 text-xs">
                            <?php foreach ($flags as $f): ?>
                                <span class="inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-800 mr-1"><?php echo rep_h($f); ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="p-2 text-right">
                            <?php if (in_array('oper-down', $flags, true) && !empty($row['if_name']) && !empty($row['last_run_switch'])): ?>
                                <form method="post" action="reports.php?tab=drift" class="inline">
                                    <input type="hidden" name="csrf" value="<?php echo rep_h($auth->csrf()); ?>">
                                    <input type="hidden" name="action" value="corrective_enqueue">
                                    <input type="hidden" name="template_id" value="no_shutdown_port">
                                    <input type="hidden" name="switch_name" value="<?php echo rep_h($row['last_run_switch']); ?>">
                                    <input type="hidden" name="interface" value="<?php echo rep_h($row['if_name']); ?>">
                                    <button type="submit" class="inline-flex items-center gap-1 rounded-full bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 text-xs" title="Port aktivieren (in Warteschlange)" onclick="return confirm('Port-Aktivierung fuer <?php echo rep_h($row['if_name']); ?> auf <?php echo rep_h($row['last_run_switch']); ?> in Warteschlange einreihen?');">
                                        <i data-lucide="wrench" class="h-3 w-3"></i><span>Fix</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($driftRows)): ?>
                    <tr><td colspan="11" class="p-4 text-center text-sm text-slate-500">Noch keine SNMP-Daten vorhanden. Im Switch-Inventar „Scan jetzt" ausführen.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'stale'): ?>
        <p class="mb-3 text-sm text-slate-500">Ports ohne Aktivität in den letzten <?php echo (int)$staleDays; ?> Tagen (oder noch nie aktiv beobachtet).</p>
        <div class="overflow-x-auto rounded-xl border border-slate-300 bg-white">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="bg-slate-100 text-slate-900">
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
                    <tr class="border-t border-slate-100">
                        <td class="p-2"><?php echo rep_h($row['device_caption'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['port_caption'] ?? ''); ?></td>
                        <td class="p-2"><?php echo rep_oper_pill(isset($row['if_oper_status']) ? (int)$row['if_oper_status'] : null); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['last_seen_active'] ?? '-'); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['updated'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($staleRows)): ?>
                    <tr><td colspan="5" class="p-4 text-center text-sm text-slate-500">Keine veralteten Ports erkannt.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'nodes'): ?>
        <p class="mb-3 text-sm text-slate-500">MAC-Adressen, die per FDB an Switch-Ports beobachtet wurden. Sucht in MAC, Port-Caption und Geräte-Caption.</p>
        <form method="get" action="reports.php" class="mb-3 flex flex-wrap items-center gap-2">
            <input type="hidden" name="tab" value="nodes">
            <input type="text" name="mac" value="<?php echo rep_h($nodeSearch); ?>" placeholder="MAC, Port oder Gerät suchen…" class="w-72 rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
            <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">
                <i data-lucide="search" class="h-4 w-4"></i><span>Suchen</span>
            </button>
            <?php if ($nodeSearch !== ''): ?>
                <a href="?tab=nodes" class="text-xs text-slate-500 hover:underline">zurücksetzen</a>
            <?php endif; ?>
        </form>
        <div class="overflow-x-auto rounded-xl border border-slate-300 bg-white">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="bg-slate-100 text-slate-900">
                    <tr>
                        <th class="p-2">MAC</th>
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
                    <tr class="border-t border-slate-100">
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['mac_address'] ?? ''); ?></td>
                        <td class="p-2 text-right text-xs"><?php echo $row['vlan'] !== null ? (int)$row['vlan'] : '-'; ?></td>
                        <td class="p-2"><?php echo rep_h($row['device_caption'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['port_caption'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['first_seen'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['last_seen'] ?? ''); ?></td>
                        <td class="p-2 text-xs text-slate-500"><?php echo rep_h($row['last_run_switch'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($nodeRows)): ?>
                    <tr><td colspan="7" class="p-4 text-center text-sm text-slate-500"><?php echo $nodeSearch === '' ? 'Noch keine FDB-Nodes erfasst. Im nächsten Scan werden MAC-Adressen pro Port gesammelt.' : 'Keine Treffer.'; ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'topology'): ?>
        <p class="mb-3 text-sm text-slate-500">LLDP-Nachbarn pro Switch-Port. Quelle: <code class="rounded bg-slate-100 px-1">LLDP-MIB::lldpRemTable</code>.</p>
        <div class="overflow-x-auto rounded-xl border border-slate-300 bg-white">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="bg-slate-100 text-slate-900">
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
                    <tr class="border-t border-slate-100">
                        <td class="p-2"><?php echo rep_h($row['device_caption'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['port_caption'] ?? ''); ?></td>
                        <td class="p-2 font-medium"><?php echo rep_h($row['remote_sys_name'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs"><?php echo rep_h($row['remote_port_id'] ?? ''); ?></td>
                        <td class="p-2 text-xs text-slate-500"><?php echo rep_h($row['remote_port_desc'] ?? ''); ?></td>
                        <td class="p-2 font-mono text-xs text-slate-500"><?php echo rep_h($row['remote_chassis_id'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['last_seen'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($topoRows)): ?>
                    <tr><td colspan="7" class="p-4 text-center text-sm text-slate-500">Noch keine LLDP-Nachbarn erfasst. Im nächsten Scan werden Topologie-Daten gesammelt.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php else: /* runs */ ?>
        <?php if ($selectedRun !== null): ?>
            <?php
                $det = is_array($selectedRun['details'] ?? null) ? $selectedRun['details'] : [];
                $vlans = is_array($det['vlans'] ?? null) ? $det['vlans'] : [];
                $matched = is_array($det['matched_ports'] ?? null) ? $det['matched_ports'] : [];
                $unknown = is_array($det['unknown_interfaces'] ?? null) ? $det['unknown_interfaces'] : [];
                $ifaces = is_array($det['interfaces'] ?? null) ? $det['interfaces'] : [];
                $vlanSrc = (string)($det['vlan_source'] ?? '-');
            ?>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <a href="?tab=runs" class="inline-flex items-center gap-1 text-sm text-blue-700 hover:underline">
                        <i data-lucide="arrow-left" class="h-4 w-4"></i><span>Zur Übersicht</span>
                    </a>
                    <h2 class="mt-2 text-xl font-bold text-slate-900">Scan-Lauf: <?php echo rep_h($selectedRun['switch_name'] ?? ''); ?></h2>
                    <p class="text-xs text-slate-500">
                        <?php echo rep_h($selectedRun['started'] ?? ''); ?> &rarr; <?php echo rep_h($selectedRun['finished'] ?? '-'); ?>
                        &nbsp;·&nbsp; Trigger: <?php echo rep_h($selectedRun['trigger'] ?? ''); ?>
                        &nbsp;·&nbsp; <?php echo rep_run_status_pill((string)($selectedRun['status'] ?? '')); ?>
                    </p>
                </div>
                <div class="grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="rounded-lg bg-slate-100 px-3 py-2"><div class="text-lg font-bold text-slate-900"><?php echo (int)($selectedRun['interfaces_seen'] ?? 0); ?></div><div class="text-slate-500">Interfaces</div></div>
                    <div class="rounded-lg bg-slate-100 px-3 py-2"><div class="text-lg font-bold text-slate-900"><?php echo (int)($selectedRun['vlans_seen'] ?? 0); ?></div><div class="text-slate-500">VLANs</div></div>
                    <div class="rounded-lg bg-amber-50 px-3 py-2"><div class="text-lg font-bold text-amber-800"><?php echo (int)($selectedRun['findings_total'] ?? 0); ?></div><div class="text-amber-700">Findings</div></div>
                </div>
            </div>

            <?php if (!empty($selectedRun['message'])): ?>
                <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    <?php echo rep_h($selectedRun['message']); ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <!-- VLANs -->
                <div class="rounded-xl border border-slate-300 bg-white">
                    <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
                        <h3 class="text-sm font-semibold text-slate-900">VLANs (<?php echo count($vlans); ?>)</h3>
                        <span class="text-xs text-slate-500" title="OID-Quelle">Quelle: <?php echo rep_h($vlanSrc); ?></span>
                    </div>
                    <div class="max-h-72 overflow-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-slate-700"><tr><th class="px-3 py-1">ID</th><th class="px-3 py-1">Name</th></tr></thead>
                            <tbody>
                            <?php foreach ($vlans as $v): ?>
                                <tr class="border-t border-slate-100"><td class="px-3 py-1 font-mono"><?php echo (int)($v['id'] ?? 0); ?></td><td class="px-3 py-1 text-xs"><?php echo rep_h($v['name'] ?? ''); ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($vlans)): ?>
                                <tr><td colspan="2" class="px-3 py-3 text-center text-xs text-slate-500">Keine VLANs erkannt.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Unknown -->
                <div class="rounded-xl border border-slate-300 bg-white">
                    <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
                        <h3 class="text-sm font-semibold text-slate-900">Unbekannte SNMP-Interfaces (<?php echo count($unknown); ?>)</h3>
                        <span class="text-xs text-slate-500">kein passender Portflow-Port per Caption</span>
                    </div>
                    <div class="max-h-72 overflow-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-slate-700"><tr><th class="px-3 py-1">ifIndex</th><th class="px-3 py-1">ifName</th><th class="px-3 py-1">Alias</th><th class="px-3 py-1">Oper</th></tr></thead>
                            <tbody>
                            <?php foreach ($unknown as $u): ?>
                                <tr class="border-t border-slate-100">
                                    <td class="px-3 py-1 font-mono"><?php echo (int)($u['if_index'] ?? 0); ?></td>
                                    <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($u['if_name'] ?? ''); ?></td>
                                    <td class="px-3 py-1 text-xs text-slate-500"><?php echo rep_h($u['if_alias'] ?? ''); ?></td>
                                    <td class="px-3 py-1"><?php echo rep_oper_pill(isset($u['oper']) ? (int)$u['oper'] : null); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($unknown)): ?>
                                <tr><td colspan="4" class="px-3 py-3 text-center text-xs text-slate-500">Alle Interfaces zugeordnet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Matched -->
            <div class="mt-3 rounded-xl border border-slate-300 bg-white">
                <div class="border-b border-slate-200 px-3 py-2">
                    <h3 class="text-sm font-semibold text-slate-900">Zugeordnete Ports (<?php echo count($matched); ?>)</h3>
                </div>
                <div class="max-h-96 overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-700"><tr>
                            <th class="px-3 py-1">Caption</th><th class="px-3 py-1">ifName</th><th class="px-3 py-1">Alias</th>
                            <th class="px-3 py-1 text-right">Speed</th><th class="px-3 py-1">MAC</th>
                            <th class="px-3 py-1 text-right">PVID</th><th class="px-3 py-1">Admin</th><th class="px-3 py-1">Oper</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($matched as $m): ?>
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($m['caption'] ?? ''); ?></td>
                                <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($m['if_name'] ?? ''); ?></td>
                                <td class="px-3 py-1 text-xs text-slate-500"><?php echo rep_h($m['if_alias'] ?? ''); ?></td>
                                <td class="px-3 py-1 text-right text-xs"><?php echo $m['speed'] !== null ? (int)$m['speed'] : '-'; ?></td>
                                <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($m['mac'] ?? ''); ?></td>
                                <td class="px-3 py-1 text-right text-xs"><?php echo $m['pvid'] !== null ? (int)$m['pvid'] : '-'; ?></td>
                                <td class="px-3 py-1 text-xs"><?php echo rep_h(rep_admin_status_label(isset($m['admin']) ? (int)$m['admin'] : null)); ?></td>
                                <td class="px-3 py-1"><?php echo rep_oper_pill(isset($m['oper']) ? (int)$m['oper'] : null); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($matched)): ?>
                            <tr><td colspan="8" class="px-3 py-3 text-center text-xs text-slate-500">Keine Ports konnten per Caption zugeordnet werden.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Neighbors / PoE / Entity -->
            <?php
                $rNeighbors = is_array($det['neighbors'] ?? null) ? $det['neighbors'] : [];
                $rPoe = is_array($det['poe_ports'] ?? null) ? $det['poe_ports'] : [];
                $rPoeMain = is_array($det['poe_main_consumption'] ?? null) ? $det['poe_main_consumption'] : [];
                $rEntity = is_array($det['entity_inventory'] ?? null) ? $det['entity_inventory'] : [];
            ?>
            <?php if (!empty($rNeighbors)): ?>
            <details class="mt-3 rounded-xl border border-slate-300 bg-white" open>
                <summary class="cursor-pointer border-b border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">LLDP-Nachbarn (<?php echo count($rNeighbors); ?>)</summary>
                <div class="max-h-72 overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-700"><tr><th class="px-3 py-1">Lokal ifIndex</th><th class="px-3 py-1">Nachbar</th><th class="px-3 py-1">Remote Port</th><th class="px-3 py-1">Beschreibung</th><th class="px-3 py-1">Chassis</th></tr></thead>
                        <tbody>
                        <?php foreach ($rNeighbors as $n): ?>
                            <tr class="border-t border-slate-100">
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
            <details class="mt-3 rounded-xl border border-slate-300 bg-white">
                <summary class="cursor-pointer border-b border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">PoE (<?php echo count($rPoe); ?> Ports<?php if (!empty($rPoeMain)): ?>, Verbrauch: <?php echo rep_h(implode(' / ', array_map('strval', $rPoeMain))); ?> W<?php endif; ?>)</summary>
                <div class="max-h-72 overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-700"><tr><th class="px-3 py-1">Group.Port</th><th class="px-3 py-1">Admin</th><th class="px-3 py-1">Detection</th><th class="px-3 py-1 text-right">Klasse</th></tr></thead>
                        <tbody>
                        <?php foreach ($rPoe as $p): ?>
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-1 font-mono text-xs"><?php echo rep_h($p['port_idx'] ?? ''); ?></td>
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
            <details class="mt-3 rounded-xl border border-slate-300 bg-white">
                <summary class="cursor-pointer border-b border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">Hardware-Inventar (ENTITY-MIB, <?php echo count($rEntity); ?>)</summary>
                <div class="max-h-72 overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-700"><tr><th class="px-3 py-1">Idx</th><th class="px-3 py-1">Klasse</th><th class="px-3 py-1">Name</th><th class="px-3 py-1">Modell</th><th class="px-3 py-1">Serial</th><th class="px-3 py-1">Beschreibung</th></tr></thead>
                        <tbody>
                        <?php foreach ($rEntity as $e): ?>
                            <?php $clsLabel = match ((int)($e['class'] ?? 0)) { 1 => 'other', 2 => 'unknown', 3 => 'chassis', 4 => 'backplane', 5 => 'container', 6 => 'powerSupply', 7 => 'fan', 8 => 'sensor', 9 => 'module', 10 => 'port', 11 => 'stack', 12 => 'cpu', default => (string)($e['class'] ?? '-') }; ?>
                            <tr class="border-t border-slate-100">
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

            <!-- All interfaces (raw SNMP) -->
            <details class="mt-3 rounded-xl border border-slate-300 bg-white">
                <summary class="cursor-pointer border-b border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">Alle SNMP-Interfaces anzeigen (<?php echo count($ifaces); ?>)</summary>
                <div class="max-h-96 overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-700"><tr>
                            <th class="px-3 py-1">ifIndex</th><th class="px-3 py-1">ifName</th><th class="px-3 py-1">Alias</th>
                            <th class="px-3 py-1 text-right">Speed</th><th class="px-3 py-1">Admin</th><th class="px-3 py-1">Oper</th><th class="px-3 py-1 text-right">PVID</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($ifaces as $ifc): ?>
                            <tr class="border-t border-slate-100">
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

        <?php else: ?>
        <div class="overflow-x-auto rounded-xl border border-slate-300 bg-white">
            <table class="w-full text-left text-sm text-slate-700">
                <thead class="bg-slate-100 text-slate-900">
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
                    <tr class="cursor-pointer border-t border-slate-100 hover:bg-blue-50" onclick="window.location='?tab=runs&amp;run=<?php echo rep_h($rUuid); ?>'">
                        <td class="p-2"><?php echo rep_run_status_pill((string)($row['status'] ?? 'running')); ?></td>
                        <td class="p-2 font-medium text-slate-900"><?php echo rep_h($row['switch_name'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['trigger'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['started'] ?? ''); ?></td>
                        <td class="p-2 text-xs"><?php echo rep_h($row['finished'] ?? ''); ?></td>
                        <td class="p-2 text-right"><?php echo (int)($row['interfaces_seen'] ?? 0); ?></td>
                        <td class="p-2 text-right"><?php echo (int)($row['vlans_seen'] ?? 0); ?></td>
                        <td class="p-2 text-right"><?php echo (int)($row['findings_total'] ?? 0); ?></td>
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
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

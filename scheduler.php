<?php
/**
 * Portflow Automation Scheduler CLI
 * 
 * This script is designed to be run by cron (e.g., daily at 18:00).
 * Usage: php /var/www/html/Portflow-DEV/scheduler.php
 * 
 * It executes all pending changes across all switches and users,
 * then logs the result for visibility in the UI.
 */

// Suppress output from includes
ob_start();

const APP_NAME = 'Portflow';

// Includes (without session requirement)
include_once __DIR__ . '/includes/core/db_adapter.php';
include_once __DIR__ . '/includes/core/pending_changes_queue.php';
include_once __DIR__ . '/includes/core/automation_store.php';
include_once __DIR__ . '/includes/core/logger.php';
include_once __DIR__ . '/includes/core/mail.php';
include_once __DIR__ . '/includes/core/notification_center.php';

use Portflow\Core\DatabaseAdapter;
use Portflow\Core\PendingChangesQueue;
use Portflow\Core\AutomationStore;
use Portflow\Core\Logger;
use Portflow\Core\Mail;
use Portflow\Core\NotificationCenter;

ob_end_clean();

$logger = new Logger();

function schedulerResolveSwitchConnection(array $switchData, array $storedSettings): array {
    $credentialMode = trim((string)($switchData['credential_mode'] ?? 'global'));
    if (!in_array($credentialMode, ['global', 'individual'], true)) {
        $credentialMode = 'global';
    }

    $authMethod = trim((string)($storedSettings['ssh_auth_method'] ?? 'password'));
    $username = trim((string)($storedSettings['ssh_username'] ?? ''));
    $password = (string)($storedSettings['ssh_password'] ?? '');
    $privateKey = (string)($storedSettings['ssh_private_key'] ?? '');

    if ($credentialMode === 'individual') {
        $switchAuthMethod = trim((string)($switchData['ssh_auth_method'] ?? 'password'));
        if (in_array($switchAuthMethod, ['password', 'key'], true)) {
            $authMethod = $switchAuthMethod;
        }
        if (trim((string)($switchData['ssh_username'] ?? '')) !== '') {
            $username = trim((string)$switchData['ssh_username']);
        }
        if ((string)($switchData['ssh_password'] ?? '') !== '') {
            $password = (string)$switchData['ssh_password'];
        }
        if ((string)($switchData['ssh_private_key'] ?? '') !== '') {
            $privateKey = (string)$switchData['ssh_private_key'];
        }
    }

    if (!in_array($authMethod, ['password', 'key'], true)) {
        $authMethod = $privateKey !== '' ? 'key' : 'password';
    }

    $switchData['ssh_port'] = $storedSettings['ssh_port'] ?? 22;
    $switchData['ssh_auth_method'] = $authMethod;
    $switchData['ssh_username'] = $username;
    $switchData['ssh_password'] = $password;
    $switchData['ssh_private_key'] = $privateKey;

    return $switchData;
}
$db = new DatabaseAdapter();
$queueManager = new PendingChangesQueue($db);
$automationStore = new AutomationStore();
$mail = new Mail();
$notificationCenter = new NotificationCenter($db, $logger, $mail);
$startTime = microtime(true);

function schedulerGetTaskConfig(array $storedSettings): array {
    $config = is_array($storedSettings['scheduler_config'] ?? null) ? $storedSettings['scheduler_config'] : [];
    $snmpScan = is_array($config['snmp_scan'] ?? null) ? $config['snmp_scan'] : [];
    $snmpScanEnabled = !empty($config['snmp_scan_enabled']) || !empty($snmpScan['enabled']);
    $snmpIntervalMinutes = max(5, (int)($snmpScan['interval_minutes'] ?? 60));
    $snmpInactivityDays = max(1, (int)($snmpScan['inactivity_days'] ?? 14));
    $snmpOidModules = is_array($snmpScan['oid_modules'] ?? null) ? $snmpScan['oid_modules'] : [];

    return [
        'queue_enabled' => !array_key_exists('queue_enabled', $config) || !empty($config['queue_enabled']),
        'notifications_enabled' => !array_key_exists('notifications_enabled', $config) || !empty($config['notifications_enabled']),
        'snmp_scan_enabled' => $snmpScanEnabled,
        'snmp_scan' => [
            'enabled' => $snmpScanEnabled,
            'interval_minutes' => $snmpIntervalMinutes,
            'inactivity_days' => $snmpInactivityDays,
            'oid_modules' => [
                'lldp' => !array_key_exists('lldp', $snmpOidModules) || !empty($snmpOidModules['lldp']),
                'arp' => !array_key_exists('arp', $snmpOidModules) || !empty($snmpOidModules['arp']),
                'poe' => !array_key_exists('poe', $snmpOidModules) || !empty($snmpOidModules['poe']),
                'entity' => !array_key_exists('entity', $snmpOidModules) || !empty($snmpOidModules['entity']),
            ],
        ],
    ];
}

function schedulerShouldRunSnmpScan(array $taskConfig, array $storedSettings): bool {
    if (empty($taskConfig['snmp_scan_enabled'])) {
        return false;
    }

    $intervalMinutes = max(5, (int)($taskConfig['snmp_scan']['interval_minutes'] ?? 60));
    $status = is_array($storedSettings['scheduler_status'] ?? null) ? $storedSettings['scheduler_status'] : [];
    $lastRun = trim((string)($status['last_snmp_scan_run'] ?? ''));
    if ($lastRun === '') {
        return true;
    }

    $lastRunTs = strtotime($lastRun);
    if ($lastRunTs === false) {
        return true;
    }

    return (time() - $lastRunTs) >= ($intervalMinutes * 60);
}

function schedulerRunSnmpScanAll(DatabaseAdapter $db, AutomationStore $automationStore, Logger $logger, float $startTime): int {
    include_once __DIR__ . '/includes/core/snmp_scanner.php';
    $logger->log('Scheduler[snmp-scan]: Starting SNMP scan across all inventory switches', 1);

    $storedSettings = $automationStore->getSettings();
    $invRaw = trim((string)($storedSettings['switch_inventory_json'] ?? ''));
    $invDecoded = $invRaw !== '' ? json_decode($invRaw, true) : null;
    $switchNames = [];
    if (is_array($invDecoded) && isset($invDecoded['switches']) && is_array($invDecoded['switches'])) {
        foreach ($invDecoded['switches'] as $sw) {
            if (is_array($sw)) {
                $name = trim((string)($sw['name'] ?? ''));
                if ($name !== '') {
                    $switchNames[] = $name;
                }
            }
        }
    }
    $switchNames = array_values(array_unique($switchNames));

    if (empty($switchNames)) {
        $logger->log('Scheduler[snmp-scan]: No switches in inventory', 2);
        return 0;
    }

    $scanner = new \Portflow\Core\SnmpScanner($db, $automationStore, $logger);
    $okCount = 0;
    $failCount = 0;
    foreach ($switchNames as $name) {
        try {
            $r = $scanner->scanSwitch($name, 'scheduler', null);
            if (!empty($r['ok'])) {
                $okCount++;
                $logger->log(sprintf('Scheduler[snmp-scan] OK %s ifs=%d findings=%d', $name, (int)($r['interfaces'] ?? 0), (int)($r['findings'] ?? 0)), 1);
            } else {
                $failCount++;
                $logger->log(sprintf('Scheduler[snmp-scan] FAIL %s -- %s', $name, (string)($r['error'] ?? 'unknown')), 2);
            }
        } catch (\Throwable $e) {
            $failCount++;
            $logger->log(sprintf('Scheduler[snmp-scan] EXC %s -- %s', $name, $e->getMessage()), 3);
        }
    }

    $logger->log(sprintf('Scheduler[snmp-scan] done: total=%d ok=%d fail=%d duration=%.2fs',
        count($switchNames), $okCount, $failCount, microtime(true) - $startTime), 1);

    try {
        $automationStore->updateSchedulerStatus([
            'last_snmp_scan_run' => date('Y-m-d H:i:s'),
            'last_snmp_scan_success' => $failCount === 0 ? date('Y-m-d H:i:s') : '',
            'last_snmp_scan_message' => sprintf('SNMP-Scan: total=%d ok=%d fail=%d', count($switchNames), $okCount, $failCount),
        ]);
    } catch (\Throwable $ignored) {
        // Best-effort status tracking for SNMP scan cadence.
    }

    return $failCount === 0 ? 0 : 1;
}

function schedulerProcessNotifications(NotificationCenter $notificationCenter, Logger $logger): array {
    $notificationCenter->enqueueDailySummaryIfDue();
    $deliveryResult = $notificationCenter->processQueue(150);
    $cleanupResult = $notificationCenter->cleanupQueue((int)(defined('NOTIFICATION_QUEUE_RETENTION_DAYS') ? NOTIFICATION_QUEUE_RETENTION_DAYS : 30));

    $logger->log(
        'Scheduler: notifications processed - processed=' . (int)$deliveryResult['processed']
        . ' sent=' . (int)$deliveryResult['sent']
        . ' failed=' . (int)$deliveryResult['failed']
        . ' remaining=' . (int)$deliveryResult['remaining']
        . ' cleaned=' . (int)$cleanupResult['removed'],
        1
    );

    return [
        'delivery' => $deliveryResult,
        'cleanup' => $cleanupResult,
    ];
}

// Dispatch alternative scheduler tasks via first CLI argument.
$schedulerTask = isset($argv[1]) ? trim((string)$argv[1]) : '';

if ($schedulerTask === 'snmp-scan' || $schedulerTask === 'snmp_scan_all') {
    exit(schedulerRunSnmpScanAll($db, $automationStore, $logger, $startTime));
}

try {
    $storedSettings = $automationStore->getSettings();
    $taskConfig = schedulerGetTaskConfig($storedSettings);
    $enabledTasks = [];
    if ($taskConfig['queue_enabled']) {
        $enabledTasks[] = 'queue';
    }
    if ($taskConfig['notifications_enabled']) {
        $enabledTasks[] = 'notifications';
    }
    if ($taskConfig['snmp_scan_enabled'] && schedulerShouldRunSnmpScan($taskConfig, $storedSettings)) {
        $enabledTasks[] = 'snmp-scan';
    } elseif ($taskConfig['snmp_scan_enabled']) {
        $enabledTasks[] = 'snmp-scan-wait';
    }

    if (empty($enabledTasks)) {
        $message = 'Scheduler: No tasks enabled in scheduler configuration';
        $logger->log($message, 2);
        recordSchedulerRun(true, $message, $automationStore);
        exit(0);
    }

    $logger->log('Scheduler: Starting tasks [' . implode(', ', $enabledTasks) . ']', 1);

    if ($taskConfig['snmp_scan_enabled'] && schedulerShouldRunSnmpScan($taskConfig, $storedSettings)) {
        $snmpExitCode = schedulerRunSnmpScanAll($db, $automationStore, $logger, $startTime);
        if ($snmpExitCode !== 0) {
            $logger->log('Scheduler: SNMP scan finished with failures', 2);
        }
    } elseif ($taskConfig['snmp_scan_enabled']) {
        $logger->log(
            'Scheduler: SNMP scan skipped because interval_minutes=' . (int)($taskConfig['snmp_scan']['interval_minutes'] ?? 60) . ' is not due yet',
            1
        );
    }

    if (!$taskConfig['queue_enabled']) {
        $notificationSummary = '';
        if ($taskConfig['notifications_enabled']) {
            $notificationRun = schedulerProcessNotifications($notificationCenter, $logger);
            $deliveryResult = is_array($notificationRun['delivery'] ?? null) ? $notificationRun['delivery'] : [];
            $cleanupResult = is_array($notificationRun['cleanup'] ?? null) ? $notificationRun['cleanup'] : [];
            $notificationSummary = ' Notifications: processed=' . (int)$deliveryResult['processed']
                . ' sent=' . (int)$deliveryResult['sent']
                . ' failed=' . (int)$deliveryResult['failed']
                . ' cleaned=' . (int)$cleanupResult['removed'] . '.';
        }

        $message = 'Scheduler: Queue execution disabled by configuration.' . $notificationSummary;
        recordSchedulerRun(true, $message, $automationStore);
        exit(0);
    }

    // Get all switches from inventory
    $inventoryRaw = trim((string)($storedSettings['switch_inventory_json'] ?? ''));
    if ($inventoryRaw === '') {
        $inventoryRaw = '{"switches": []}';
    }

    $inventoryDecoded = json_decode($inventoryRaw, true);
    $switches = [];
    if (is_array($inventoryDecoded) && isset($inventoryDecoded['switches']) && is_array($inventoryDecoded['switches'])) {
        foreach ($inventoryDecoded['switches'] as $switchEntry) {
            if (!is_array($switchEntry)) {
                continue;
            }
            $name = trim((string)($switchEntry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $switches[$name] = $switchEntry;
        }
    }

    if (empty($switches)) {
        $logger->log('Scheduler: No switches configured in inventory', 2);
        $notificationSummary = '';
        if ($taskConfig['notifications_enabled']) {
            $notificationRun = schedulerProcessNotifications($notificationCenter, $logger);
            $deliveryResult = is_array($notificationRun['delivery'] ?? null) ? $notificationRun['delivery'] : [];
            $cleanupResult = is_array($notificationRun['cleanup'] ?? null) ? $notificationRun['cleanup'] : [];
            $notificationSummary = ' Notifications: processed=' . (int)$deliveryResult['processed']
                . ' sent=' . (int)$deliveryResult['sent']
                . ' failed=' . (int)$deliveryResult['failed']
                . ' cleaned=' . (int)$cleanupResult['removed'] . '.';
        }
        recordSchedulerRun(false, 'No switches in inventory.' . $notificationSummary, $automationStore);
        exit(1);
    }

    // Process each switch
    $totalProcessed = 0;
    $totalSucceeded = 0;
    $totalFailed = 0;
    $results = [];
    $userFailureStats = [];

    // Get all users with pending changes
    $sql = "SELECT DISTINCT users, switch_name FROM pending_changes WHERE status = 'pending' ORDER BY switch_name";
    $userSwitchPairs = $db->db_query($sql);
    if (!is_array($userSwitchPairs)) {
        $userSwitchPairs = [];
    }

    if (empty($userSwitchPairs)) {
        $logger->log('Scheduler: No pending changes to process', 1);
        $notificationSummary = '';
        if ($taskConfig['notifications_enabled']) {
            $notificationRun = schedulerProcessNotifications($notificationCenter, $logger);
            $deliveryResult = is_array($notificationRun['delivery'] ?? null) ? $notificationRun['delivery'] : [];
            $cleanupResult = is_array($notificationRun['cleanup'] ?? null) ? $notificationRun['cleanup'] : [];
            $notificationSummary = ' Notifications: processed=' . (int)$deliveryResult['processed']
                . ' sent=' . (int)$deliveryResult['sent']
                . ' failed=' . (int)$deliveryResult['failed']
                . ' cleaned=' . (int)$cleanupResult['removed'] . '.';
        }
        recordSchedulerRun(true, 'No pending changes to process.' . $notificationSummary, $automationStore);
        exit(0);
    }

    foreach ($userSwitchPairs as $pair) {
        $userUuid = $pair['users'];
        $switchName = $pair['switch_name'];

        if (!isset($switches[$switchName])) {
            $logger->log("Scheduler: Switch '$switchName' not found in inventory", 2);
            $results[] = "FAILED: $switchName - Switch not in inventory";
            $totalFailed++;
            if (!isset($userFailureStats[$userUuid])) {
                $userFailureStats[$userUuid] = ['failed_changes' => 0, 'switches' => []];
            }
            $userFailureStats[$userUuid]['failed_changes']++;
            $userFailureStats[$userUuid]['switches'][$switchName] = true;
            continue;
        }

        $switchData = schedulerResolveSwitchConnection($switches[$switchName], $storedSettings);

        $logger->log("Scheduler: Executing for switch=$switchName user=$userUuid", 1);

        try {
            $executionResult = $queueManager->executeQueueForSwitch(
                $userUuid,
                $switchName,
                $switchData,
                $logger
            );

            $totalProcessed += $executionResult['total'];
            if ($executionResult['ok'] || $executionResult['failed'] === 0) {
                $totalSucceeded += $executionResult['completed'];
                $results[] = "SUCCESS: $switchName ({$executionResult['completed']} completed)";
                $logger->log("Scheduler: $switchName - {$executionResult['completed']} executed", 1);
            } else {
                $totalSucceeded += $executionResult['completed'];
                $totalFailed += $executionResult['failed'];
                $results[] = "PARTIAL: $switchName ({$executionResult['completed']} ok, {$executionResult['failed']} failed)";
                $logger->log("Scheduler: $switchName - {$executionResult['completed']} ok, {$executionResult['failed']} failed", 2);
                if ($executionResult['failed'] > 0) {
                    if (!isset($userFailureStats[$userUuid])) {
                        $userFailureStats[$userUuid] = ['failed_changes' => 0, 'switches' => []];
                    }
                    $userFailureStats[$userUuid]['failed_changes'] += (int)$executionResult['failed'];
                    $userFailureStats[$userUuid]['switches'][$switchName] = true;
                }
            }

            logSchedulerExecutionEvent(
                $db,
                (string)$userUuid,
                (string)$switchName,
                is_array($executionResult['profile_ids'] ?? null) ? $executionResult['profile_ids'] : [],
                is_array($executionResult['template_ids'] ?? null) ? $executionResult['template_ids'] : [],
                (int)($executionResult['command_count'] ?? 0),
                (bool)($executionResult['ok'] ?? false),
                [
                    'failed_changes' => (int)($executionResult['failed'] ?? 0),
                    'completed_changes' => (int)($executionResult['completed'] ?? 0),
                    'total_changes' => (int)($executionResult['total'] ?? 0),
                    'output' => (string)($executionResult['output'] ?? '')
                ]
            );
        } catch (Exception $e) {
            $totalFailed++;
            $results[] = "ERROR: $switchName - " . $e->getMessage();
            $logger->log("Scheduler: Exception for $switchName: " . $e->getMessage(), 3);
            if (!isset($userFailureStats[$userUuid])) {
                $userFailureStats[$userUuid] = ['failed_changes' => 0, 'switches' => []];
            }
            $userFailureStats[$userUuid]['failed_changes']++;
            $userFailureStats[$userUuid]['switches'][$switchName] = true;
        }
    }

    $durationSec = microtime(true) - $startTime;
    $summary = implode("\n", $results);
    $logMessage = "Scheduler: Completed - Processed: $totalProcessed, Succeeded: $totalSucceeded, Failed: $totalFailed, Duration: " . number_format($durationSec, 2) . "s\n$summary";

    // Emit divergence signal only to users with affected failed changes.
    foreach ($userFailureStats as $affectedUserUuid => $stats) {
        $failedForUser = (int)($stats['failed_changes'] ?? 0);
        if ($failedForUser <= 0) {
            continue;
        }

        $switchesForUser = array_keys(is_array($stats['switches'] ?? null) ? $stats['switches'] : []);
        $notificationCenter->enqueueForUsers(
            [$affectedUserUuid],
            'documentation_deviation',
            'progress',
            'Abweichung zwischen Doku und Realitaet',
            'Beim automatisierten Abgleich wurden fehlgeschlagene Changes fuer Ihre Ressourcen erkannt. Bitte pruefen Sie die betroffenen Eintraege.',
            [
                'failed_changes' => $failedForUser,
                'processed_changes' => $totalProcessed,
                'affected_switches' => $switchesForUser,
                'summary' => $summary
            ]
        );
    }

    if ($taskConfig['notifications_enabled']) {
        schedulerProcessNotifications($notificationCenter, $logger);
    } else {
        $logger->log('Scheduler: notification processing disabled by configuration', 1);
    }

    $logger->log($logMessage, $totalFailed === 0 ? 1 : 2);
    recordSchedulerRun($totalFailed === 0, $summary, $automationStore, $totalProcessed, $totalSucceeded, $totalFailed);

    exit($totalFailed === 0 ? 0 : 1);

} catch (Exception $e) {
    $durationSec = microtime(true) - $startTime;
    $errorMessage = "Scheduler: Fatal error: " . $e->getMessage();
    $logger->log($errorMessage, 3);
    recordSchedulerRun(false, $errorMessage, $automationStore);
    exit(2);
}

/**
 * Record scheduler run result.
 */
function recordSchedulerRun(bool $success, string $message, AutomationStore $store, int $processed = 0, int $succeeded = 0, int $failed = 0): void {
    try {
        $store->updateSchedulerStatus([
            'last_run' => date('Y-m-d H:i:s'),
            'last_success' => $success ? date('Y-m-d H:i:s') : null,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'message' => substr($message, 0, 1000)
        ]);
    } catch (Exception $e) {
        // Silently fail
    }
}

function logSchedulerExecutionEvent(
    DatabaseAdapter $db,
    string $userUuid,
    string $switchName,
    array $profileIds,
    array $templateIds,
    int $commandCount,
    bool $ok,
    array $extra = []
): void {
    $payload = array_merge([
        'event' => 'script_execution',
        'mode' => 'scheduler_queue',
        'switch' => $switchName,
        'profile' => count($profileIds) === 1 ? (string)$profileIds[0] : 'mixed',
        'profiles' => array_values(array_filter(array_map('strval', $profileIds), static fn(string $v): bool => trim($v) !== '')),
        'template' => count($templateIds) === 1 ? (string)$templateIds[0] : 'mixed',
        'templates' => array_values(array_filter(array_map('strval', $templateIds), static fn(string $v): bool => trim($v) !== '')),
        'command_count' => max(0, $commandCount),
        'ok' => $ok
    ], $extra);

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        $encoded = '{"event":"script_execution","mode":"scheduler_queue","error":"encoding_failed"}';
    }

    try {
        $db->db_query(
            "INSERT INTO changelog (users, operation, changed_table, changed_row, changed_data)
             VALUES (:users, :operation, :changed_table, gen_random_uuid(), :changed_data)",
            [
                'users' => trim($userUuid) !== '' ? $userUuid : null,
                'operation' => 'INSERT',
                'changed_table' => 'script_execution',
                'changed_data' => $encoded
            ]
        );
    } catch (\Throwable $ignored) {
        // Best-effort history logging for scheduler runs.
    }
}

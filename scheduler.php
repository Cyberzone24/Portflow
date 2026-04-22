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

$logger->log('Scheduler: Starting automated queue execution', 1);

try {
    // Get all switches from inventory
    $storedSettings = $automationStore->getSettings();
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
        recordSchedulerRun(false, 'No switches in inventory', $automationStore);
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
        recordSchedulerRun(true, 'No pending changes to process', $automationStore);
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

    // Trigger daily summary creation based on env time/timezone and always process queue.
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

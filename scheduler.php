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
            continue;
        }

        $switchData = $switches[$switchName];
        $switchData['ssh_port'] = $storedSettings['ssh_port'] ?? 22;
        $switchData['ssh_username'] = $storedSettings['ssh_username'] ?? '';
        $switchData['ssh_password'] = $storedSettings['ssh_password'] ?? '';

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
            }
        } catch (Exception $e) {
            $totalFailed++;
            $results[] = "ERROR: $switchName - " . $e->getMessage();
            $logger->log("Scheduler: Exception for $switchName: " . $e->getMessage(), 3);
        }
    }

    $durationSec = microtime(true) - $startTime;
    $summary = implode("\n", $results);
    $logMessage = "Scheduler: Completed - Processed: $totalProcessed, Succeeded: $totalSucceeded, Failed: $totalFailed, Duration: " . number_format($durationSec, 2) . "s\n$summary";

    // Emit divergence signal if there are failed changes.
    if ($totalFailed > 0) {
        $notificationCenter->enqueueGlobal(
            'documentation_deviation',
            'progress',
            'Abweichung zwischen Doku und Realitaet',
            'Beim automatisierten Abgleich wurden fehlgeschlagene Changes erkannt. Bitte pruefen Sie die betroffenen Eintraege.',
            [
                'failed_changes' => $totalFailed,
                'processed_changes' => $totalProcessed,
                'summary' => $summary
            ]
        );
    }

    // Trigger daily summary creation based on env time/timezone and always process queue.
    $notificationCenter->enqueueDailySummaryIfDue();
    $deliveryResult = $notificationCenter->processQueue(150);
    $logger->log(
        'Scheduler: notifications processed - processed=' . (int)$deliveryResult['processed']
        . ' sent=' . (int)$deliveryResult['sent']
        . ' failed=' . (int)$deliveryResult['failed']
        . ' remaining=' . (int)$deliveryResult['remaining'],
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

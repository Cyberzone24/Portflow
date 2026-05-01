#!/usr/bin/env php
<?php
declare(strict_types=1);

const APP_NAME = 'Portflow';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$envPath = $repoRoot . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "Portflow .env not found at $envPath\n");
    exit(1);
}

require_once $repoRoot . '/includes/core/config.php';
require_once $repoRoot . '/includes/core/db_adapter.php';

use Portflow\Core\DatabaseAdapter;

function schema_smoke_usage(): void {
    $usage = <<<TXT
Usage:
  php cli/scripts/schema_smoke.php [--repair] [--json] [--fail-on-outdated-views]

Options:
  --repair                  Run db_update_schema() before the final status check.
  --json                    Print machine-readable JSON output.
  --fail-on-outdated-views  Return a non-zero exit code when only outdated views remain.
  --help                    Show this help.
TXT;

    fwrite(STDOUT, $usage . PHP_EOL);
}

function schema_smoke_build_message(DatabaseAdapter $db, array $changes, bool $repairPerformed): string {
    if (!$db->hasPendingSchemaChanges($changes)) {
        return $repairPerformed
            ? 'Schema repair completed successfully. No pending schema changes remain.'
            : 'Schema check successful. No pending schema changes found.';
    }

    if ($db->hasBlockingSchemaChanges($changes)) {
        return ($repairPerformed ? 'Schema repair incomplete: ' : 'Blocking schema changes detected: ')
            . $db->summarizePendingSchemaChanges($changes);
    }

    return ($repairPerformed ? 'Schema repair completed, but non-blocking view drift remains: ' : 'Only non-blocking view drift remains: ')
        . $db->summarizePendingSchemaChanges($changes);
}

$options = getopt('', ['repair', 'json', 'fail-on-outdated-views', 'help']);
if (isset($options['help'])) {
    schema_smoke_usage();
    exit(0);
}

try {
    $db = new DatabaseAdapter();
    $beforeChanges = $db->getPendingSchemaChanges();
    $repairPerformed = false;

    if (isset($options['repair']) && $db->hasPendingSchemaChanges($beforeChanges)) {
        $db->db_update_schema();
        $repairPerformed = true;
    }

    $afterChanges = $db->getPendingSchemaChanges();
    $hasBlockingChanges = $db->hasBlockingSchemaChanges($afterChanges);
    $hasOutdatedViews = !empty($afterChanges['outdated_views']);
    $failOnOutdatedViews = isset($options['fail-on-outdated-views']);
    $exitCode = $hasBlockingChanges ? 2 : (($hasOutdatedViews && $failOnOutdatedViews) ? 3 : 0);

    $result = [
        'ok' => $exitCode === 0,
        'repair_requested' => isset($options['repair']),
        'repair_performed' => $repairPerformed,
        'has_blocking_changes' => $hasBlockingChanges,
        'has_outdated_views' => $hasOutdatedViews,
        'before' => $beforeChanges,
        'after' => $afterChanges,
        'message' => schema_smoke_build_message($db, $afterChanges, $repairPerformed),
        'exit_code' => $exitCode,
    ];

    if (isset($options['json'])) {
        fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDOUT, $result['message'] . PHP_EOL);
        fwrite(STDOUT, 'Blocking changes: ' . ($hasBlockingChanges ? 'yes' : 'no') . PHP_EOL);
        fwrite(STDOUT, 'Outdated views: ' . ($hasOutdatedViews ? 'yes' : 'no') . PHP_EOL);
        fwrite(STDOUT, 'After-state: ' . $db->summarizePendingSchemaChanges($afterChanges, 5) . PHP_EOL);
    }

    exit($exitCode);
} catch (\Throwable $e) {
    $message = 'Schema smoke failed: ' . $e->getMessage();
    if (isset($options['json'])) {
        fwrite(STDOUT, json_encode([
            'ok' => false,
            'message' => $message,
            'exit_code' => 1,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDERR, $message . PHP_EOL);
    }
    exit(1);
}
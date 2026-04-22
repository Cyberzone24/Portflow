<?php
namespace Portflow\Core;

if (!defined('APP_NAME')) {
    die('Access denied');
}

class PendingChangesQueue {
    private DatabaseAdapter $db;
    private string $tableName = 'pending_changes';

    public function __construct(DatabaseAdapter $db) {
        $this->db = $db;
    }

    /**
     * Add a pending change to the queue (instead of executing immediately).
     * 
     * @param string $userUuid User UUID who created this change
     * @param string $switchName Switch identifier
     * @param string $profileId Profile identifier
     * @param string $templateId Template identifier
     * @param array $commands Array of SSH commands
     * @param array $variables Template variables used (for reference)
     * @return string UUID of the pending change record
     */
    public function addPendingChange(
        string $userUuid,
        string $switchName,
        string $profileId,
        string $templateId,
        array $commands,
        array $variables = []
    ): string {
        $uuid = bin2hex(random_bytes(16));
        $uuid = substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4) . '-' . substr($uuid, 16, 4) . '-' . substr($uuid, 20);

        $sql = "INSERT INTO {$this->tableName}
            (uuid, users, switch_name, profile_id, template_id, commands, variables, status, created)
            VALUES (:uuid, :users, :switch_name, :profile_id, :template_id, :commands, :variables, 'pending', NOW())";

        $this->db->db_query($sql, [
            'uuid' => $uuid,
            'users' => $userUuid,
            'switch_name' => $switchName,
            'profile_id' => $profileId,
            'template_id' => $templateId,
            'commands' => implode("\n", $commands),
            'variables' => json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);

        return $uuid;
    }

    /**
     * Get all pending changes for a user, optionally filtered by switch.
     * 
     * @param string $userUuid User UUID
     * @param ?string $switchName Optional filter by switch name
     * @return array Array of pending change records
     */
    public function getPendingChanges(string $userUuid, ?string $switchName = null): array {
        $sql = "SELECT * FROM {$this->tableName}
                WHERE users = :users AND status = 'pending'";
        $params = ['users' => $userUuid];

        if ($switchName !== null) {
            $sql .= " AND switch_name = :switch_name";
            $params['switch_name'] = $switchName;
        }

        $sql .= " ORDER BY created ASC";

        $rows = $this->db->db_query($sql, $params);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Get summary of pending changes (count by switch).
     * 
     * @param string $userUuid User UUID
     * @return array Array with switch names as keys and pending change counts as values
     */
    public function getPendingSummary(string $userUuid): array {
        $sql = "SELECT switch_name, COUNT(*) as count 
                FROM {$this->tableName} 
                WHERE users = :users AND status = 'pending' 
                GROUP BY switch_name 
                ORDER BY switch_name ASC";

        $rows = $this->db->db_query($sql, ['users' => $userUuid]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['switch_name']] = (int)($row['count'] ?? 0);
        }

        return $result;
    }

    /**
     * Update a pending change status and optionally store execution result.
     * 
     * @param string $pendingChangeUuid UUID of the pending change
     * @param string $newStatus New status (pending, executing, completed, failed)
     * @param ?string $result Optional execution result/output
     * @return bool Success
     */
    public function updatePendingChange(string $pendingChangeUuid, string $newStatus, ?string $result = null): bool {
        $allowedStatuses = ['pending', 'executing', 'completed', 'failed'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            return false;
        }

        $updateFields = ['status = :status'];
        $params = [
            'status' => $newStatus,
            'uuid' => $pendingChangeUuid
        ];

        if ($result !== null) {
            $updateFields[] = 'result = :result';
            $params['result'] = $result;
        }

        if ($newStatus === 'completed' || $newStatus === 'failed') {
            $updateFields[] = 'executed = NOW()';
        }

        $sql = "UPDATE {$this->tableName} 
                SET " . implode(', ', $updateFields) . " 
                WHERE uuid = :uuid";

        try {
            $this->db->db_query($sql, $params);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete a pending change.
     * 
     * @param string $pendingChangeUuid UUID of the pending change
     * @param string $userUuid User UUID (security check)
     * @return bool Success
     */
    public function deletePendingChange(string $pendingChangeUuid, string $userUuid): bool {
        $sql = "DELETE FROM {$this->tableName} 
                WHERE uuid = :uuid AND users = :users AND status = 'pending'";

        try {
            $this->db->db_query($sql, [
                'uuid' => $pendingChangeUuid,
                'users' => $userUuid
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get a single pending change record.
     * 
     * @param string $pendingChangeUuid UUID of the pending change
     * @param string $userUuid User UUID (security check)
     * @return ?array Pending change record or null if not found
     */
    public function getPendingChange(string $pendingChangeUuid, string $userUuid): ?array {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE uuid = :uuid AND users = :users";

        $rows = $this->db->db_query($sql, [
            'uuid' => $pendingChangeUuid,
            'users' => $userUuid
        ]);

        if (!is_array($rows) || empty($rows)) {
            return null;
        }

        return $rows[0];
    }

    /**
     * Execute all pending changes for a given switch for a user.
     * Returns summary of execution results.
     * 
     * @param string $userUuid User UUID
     * @param string $switchName Switch name
     * @param array $connectionData SSH connection details
     * @param Logger $logger Logger instance
     * @return array Execution summary [total, completed, failed, details]
     */
    public function executeQueueForSwitch(
        string $userUuid,
        string $switchName,
        array $connectionData,
        Logger $logger
    ): array {
        $changes = $this->getPendingChanges($userUuid, $switchName);

        if (empty($changes)) {
            return [
                'ok' => true,
                'total' => 0,
                'completed' => 0,
                'failed' => 0,
                'details' => [],
                'output' => '',
                'command_count' => 0,
                'profile_ids' => [],
                'template_ids' => []
            ];
        }

        // Combine all commands from all pending changes into a single batch
        $allCommands = [];
        $changeUuids = [];
        $profileIds = [];
        $templateIds = [];

        foreach ($changes as $change) {
            $commandList = array_filter(
                array_map('trim', explode("\n", (string)($change['commands'] ?? ''))),
                static fn($c): bool => $c !== ''
            );
            $allCommands = array_merge($allCommands, $commandList);
            $changeUuids[] = $change['uuid'];
            $profileId = trim((string)($change['profile_id'] ?? ''));
            if ($profileId !== '') {
                $profileIds[$profileId] = true;
            }
            $templateId = trim((string)($change['template_id'] ?? ''));
            if ($templateId !== '') {
                $templateIds[$templateId] = true;
            }
        }

        // Mark all as executing
        foreach ($changeUuids as $uuid) {
            $this->updatePendingChange($uuid, 'executing');
        }

        // Execute combined batch
        $executionResult = runAutomationSshCommandsFromQueue(
            $connectionData,
            $allCommands,
            $logger,
            $switchName
        );

        $summary = [
            'ok' => (bool)($executionResult['ok'] ?? false),
            'total' => count($changes),
            'completed' => $executionResult['ok'] ? count($changes) : 0,
            'failed' => $executionResult['ok'] ? 0 : count($changes),
            'details' => [],
            'output' => (string)($executionResult['output'] ?? ''),
            'command_count' => count($allCommands),
            'profile_ids' => array_values(array_keys($profileIds)),
            'template_ids' => array_values(array_keys($templateIds))
        ];

        // Update status for each change
        foreach ($changeUuids as $uuid) {
            $newStatus = $executionResult['ok'] ? 'completed' : 'failed';
            $this->updatePendingChange($uuid, $newStatus, $executionResult['output']);

            $summary['details'][] = [
                'uuid' => $uuid,
                'status' => $newStatus,
                'output' => $executionResult['output']
            ];
        }

        return $summary;
    }
}

/**
 * Execute SSH commands for queued batch (similar to automation.php function).
 * This is extracted to allow reuse from the queue manager.
 * 
 * @param array $connection SSH connection details
 * @param array $commands Commands to execute
 * @param Logger $logger Logger instance
 * @param string $switchName Switch name for logging
 * @return array Result array [ok, output]
 */
function runAutomationSshCommandsFromQueue(
    array $connection,
    array $commands,
    Logger $logger,
    string $switchName
): array {
    $host = trim((string)($connection['mgmt_ip'] ?? ''));
    $port = (int)($connection['ssh_port'] ?? 22);
    $username = trim((string)($connection['ssh_username'] ?? ''));
    $password = (string)($connection['ssh_password'] ?? '');

    if ($host === '' || $username === '') {
        return [
            'ok' => false,
            'output' => 'Queue-Ausfuehrung fehlgeschlagen: Host und Username fehlen.'
        ];
    }

    $sshPath = trim((string)shell_exec('command -v ssh 2>/dev/null'));
    if ($sshPath === '') {
        return [
            'ok' => false,
            'output' => 'Queue-Ausfuehrung fehlgeschlagen: ssh Binary wurde nicht gefunden.'
        ];
    }

    $sshpassPath = trim((string)shell_exec('command -v sshpass 2>/dev/null'));
    $timeoutPath = trim((string)shell_exec('command -v timeout 2>/dev/null'));

    $commandFile = tempnam(sys_get_temp_dir(), 'portflow-queue-');
    if ($commandFile === false) {
        return [
            'ok' => false,
            'output' => 'Queue-Ausfuehrung fehlgeschlagen: Konnte keine temporäre Datei anlegen.'
        ];
    }

    $commandLines = ['screen-length 0 temporary'];
    foreach ($commands as $command) {
        $command = trim((string)$command);
        if ($command === '') {
            continue;
        }
        $commandLines[] = $command;
    }

    $commandLines[] = 'quit';

    file_put_contents($commandFile, implode("\n", $commandLines) . "\n");

    $sshOptions = '-F /dev/null -tt -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8';
    if ($password !== '') {
        $sshOptions .= ' -o PreferredAuthentications=password -o PubkeyAuthentication=no';
    } else {
        $sshOptions .= ' -o BatchMode=yes';
    }

    $target = escapeshellarg($username . '@' . $host);
    $sshCommand = $sshPath . ' ' . $sshOptions . ' -p ' . (int)$port . ' ' . $target . ' < ' . escapeshellarg($commandFile);

    if ($password !== '') {
        if ($sshpassPath === '') {
            return [
                'ok' => false,
                'output' => 'Queue-Ausfuehrung fehlgeschlagen: sshpass wurde nicht gefunden.'
            ];
        }

        $sshCommand = $sshpassPath . ' -p ' . escapeshellarg($password) . ' ' . $sshCommand;
    }

    $fullCommand = $sshCommand;
    if ($timeoutPath !== '') {
        $fullCommand = $timeoutPath . ' 45s ' . $fullCommand;
    }

    $lines = [];
    $exitCode = 1;
    $startedAt = microtime(true);
    exec($fullCommand . ' 2>&1', $lines, $exitCode);
    $durationSec = microtime(true) - $startedAt;
    @unlink($commandFile);

    $maxLines = 120;
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $lines[] = '... output truncated ...';
    }

    $maskedCommand = ($password !== '')
        ? 'sshpass -p ******** ssh ...'
        : trim((string)$fullCommand);

    $reportedCommands = buildQueueCommandStatusLines($commands, $lines, $exitCode);

    $outputText = "Command: " . $maskedCommand . "\n";
    $outputText .= "Exit Code: " . $exitCode . "\n";
    $outputText .= "Duration: " . number_format($durationSec, 2, '.', '') . "s\n\n";
    if (!empty($reportedCommands)) {
        $outputText .= "Command Status (heuristisch):\n" . implode("\n", $reportedCommands) . "\n\n";
    }
    $outputText .= implode("\n", $lines);

    if ($exitCode === 124) {
        $outputText .= "\n\nHinweis: Timeout waehrend oder nach erfolgreicher Konfig-Anwendung.";
    }

    $logger->log(
        'queue-execute switch=' . $switchName
            . ' exit=' . $exitCode
            . ' duration=' . number_format($durationSec, 2, '.', '') . 's',
        $exitCode === 0 ? 1 : 3
    );

    return [
        'ok' => ($exitCode === 0),
        'output' => $outputText
    ];
}

function buildQueueCommandStatusLines(array $commands, array $outputLines, int $exitCode): array {
    $errorPatterns = [
        '/\\berror\\b/i',
        '/\\bfailed\\b/i',
        '/\\binvalid\\b/i',
        '/\\bincomplete\\b/i',
        '/\\bambiguous\\b/i',
        '/\\bunrecognized\\b/i',
        '/\\bdenied\\b/i'
    ];

    $errorLines = [];
    foreach ($outputLines as $line) {
        $lineText = (string)$line;
        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $lineText)) {
                $errorLines[] = strtolower($lineText);
                break;
            }
        }
    }

    $statusLines = [];
    foreach ($commands as $idx => $command) {
        $commandText = trim((string)$command);
        if ($commandText === '') {
            continue;
        }

        $status = 'SENT';
        $normalized = strtolower(preg_replace('/\s+/', ' ', $commandText) ?? '');
        $needle = substr($normalized, 0, 24);
        $firstToken = strtok($normalized, ' ') ?: '';
        $matchedError = false;

        foreach ($errorLines as $errorLine) {
            if ($needle !== '' && strpos($errorLine, $needle) !== false) {
                $matchedError = true;
                break;
            }
            if ($firstToken !== '' && strlen($firstToken) > 2 && strpos($errorLine, $firstToken) !== false) {
                $matchedError = true;
                break;
            }
        }

        if ($matchedError) {
            $status = 'ERR?';
        } elseif ($exitCode === 0 && empty($errorLines)) {
            $status = 'OK';
        } elseif ($exitCode === 0) {
            $status = 'OK?';
        }

        $statusLines[] = str_pad((string)($idx + 1), 3, ' ', STR_PAD_LEFT) . ': [' . $status . '] ' . $commandText;
    }

    return $statusLines;
}

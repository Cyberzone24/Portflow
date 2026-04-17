<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    const APP_NAME = 'Portflow';

    include_once __DIR__ . '/includes/core/session.php';
    if (!in_array(__DIR__ . '/includes/core/session.php', get_included_files())) {
        die('could not verify session');
    }

    include_once __DIR__ . '/includes/header.php';
    include_once __DIR__ . '/includes/core/automation.php';
    include_once __DIR__ . '/includes/core/automation_store.php';
    include_once __DIR__ . '/includes/core/logger.php';
    include_once __DIR__ . '/includes/core/auth.php';
    include_once __DIR__ . '/includes/core/pending_changes_queue.php';

    use Portflow\Core\Automation;
    use Portflow\Core\AutomationStore;
    use Portflow\Core\Logger;
    use Portflow\Core\Auth;
    use Portflow\Core\PendingChangesQueue;

    $automation = new Automation();
    $automationStore = new AutomationStore();
    $logger = new Logger();
    $auth = new Auth();
    $db = new \Portflow\Core\DatabaseAdapter();
    $queueManager = new PendingChangesQueue($db);
    $config = $automation->getConfig();
    $profiles = $automation->getProfiles();
    $templates = $automation->getTemplates();

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

    $profileKeys = array_keys($profiles);
    $templateKeys = array_keys($templates);
    $executionResult = null;

    $selectedSwitch = $_GET['switch'] ?? '';
    $selectedSwitchData = $switches[$selectedSwitch] ?? null;

    $selectedProfile = $_GET['profile'] ?? ($profileKeys[0] ?? '');
    if (isset($selectedSwitchData['profile']) && !isset($_GET['profile'])) {
        $switchProfile = (string)$selectedSwitchData['profile'];
        if (isset($profiles[$switchProfile])) {
            $selectedProfile = $switchProfile;
        }
    }

    $selectedTemplate = $_GET['template'] ?? ($templateKeys[0] ?? '');
    $selectedSaveMode = $_GET['save_mode'] ?? 'immediate';
    $selectedErrorStrategy = $_GET['error_strategy'] ?? 'continue_report';
    $batchInterfacesInput = (string)($_GET['batch_interfaces'] ?? '');
    $pipelineTemplatesInput = (string)($_GET['pipeline_templates'] ?? '');

    if (!isset($profiles[$selectedProfile]) && !empty($profileKeys)) {
        $selectedProfile = $profileKeys[0];
    }

    if (!isset($templates[$selectedTemplate]) && !empty($templateKeys)) {
        $selectedTemplate = $templateKeys[0];
    }

    if (!in_array($selectedSaveMode, ['immediate', 'skip_save'], true)) {
        $selectedSaveMode = 'immediate';
    }

    if (!in_array($selectedErrorStrategy, ['continue_report', 'stop_on_error', 'stop_with_rollback'], true)) {
        $selectedErrorStrategy = 'continue_report';
    }

    $templateDefinition = $templates[$selectedTemplate] ?? [];
    $variableValues = [];
    $activeTab = 'automation';
    $canAutomationWrite = $auth->checkResourceAccess($_SESSION['uuid'], 'automation', 'write');
    $canAutomationExecute = $auth->checkResourceAccess($_SESSION['uuid'], 'automation', 'execute');

    // Handle queue operations
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['queue_action'])) {
        $activeTab = 'queue';
        if (!$canAutomationExecute) {
            $executionResult = [
                'ok' => false,
                'output' => 'Warteschlangen-Operation fehlgeschlagen: Keine Berechtigung.'
            ];
        } else {
            $queueAction = (string)($_POST['queue_action'] ?? '');

            if ($queueAction === 'execute_all' && isset($_POST['execute_all_switch'])) {
                $switchToExecute = (string)($_POST['execute_all_switch']);
                if (isset($switches[$switchToExecute])) {
                    $switchData = $switches[$switchToExecute];
                    $switchData['ssh_port'] = $storedSettings['ssh_port'] ?? 22;
                    $switchData['ssh_username'] = $storedSettings['ssh_username'] ?? '';
                    $switchData['ssh_password'] = $storedSettings['ssh_password'] ?? '';

                    $executionResult = $queueManager->executeQueueForSwitch(
                        $_SESSION['uuid'],
                        $switchToExecute,
                        $switchData,
                        $logger
                    );

                    // Convert array summary to display format
                    $executionResult['ok'] = $executionResult['failed'] === 0;
                    $executionResult['output'] = "Warteschlangen-Ausfuehrung:\n"
                        . "- Gesamt: " . $executionResult['total'] . "\n"
                        . "- Erfolgreich: " . $executionResult['completed'] . "\n"
                        . "- Fehlgeschlagen: " . $executionResult['failed'] . "\n\n";

                    if (!empty($executionResult['details'])) {
                        $executionResult['output'] .= "Details:\n";
                        foreach ($executionResult['details'] as $detail) {
                            $executionResult['output'] .= "- [{$detail['status']}] {$detail['uuid']}\n";
                        }
                        if (isset($executionResult['details'][0]['output'])) {
                            $executionResult['output'] .= "\nAusgabe der SSH-Session:\n" . $executionResult['details'][0]['output'];
                        }
                    }

                    logAutomationExecutionEvent(
                        $db,
                        (string)($_SESSION['uuid'] ?? ''),
                        'queue_execute_all',
                        $switchToExecute,
                        'queue_batch',
                        'queue_batch',
                        (int)($executionResult['total'] ?? 0),
                        !empty($executionResult['ok']),
                        [
                            'completed' => (int)($executionResult['completed'] ?? 0),
                            'failed' => (int)($executionResult['failed'] ?? 0)
                        ]
                    );
                } else {
                    $executionResult = [
                        'ok' => false,
                        'output' => 'Warteschlangen-Ausfuehrung fehlgeschlagen: Ungultiger Switch.'
                    ];
                }
            } elseif ($queueAction === 'execute_one' && isset($_POST['pending_uuid'])) {
                $pendingUuid = (string)($_POST['pending_uuid']);
                $pendingChange = $queueManager->getPendingChange($pendingUuid, $_SESSION['uuid']);

                if (!is_array($pendingChange)) {
                    $executionResult = [
                        'ok' => false,
                        'output' => 'Eintrag nicht gefunden oder keine Berechtigung.'
                    ];
                } else {
                    $switchToExecute = (string)($pendingChange['switch_name'] ?? '');
                    if ($switchToExecute === '' || !isset($switches[$switchToExecute])) {
                        $executionResult = [
                            'ok' => false,
                            'output' => 'Ausfuehrung fehlgeschlagen: Switch des Queue-Eintrags ist nicht gueltig.'
                        ];
                    } else {
                        $switchData = $switches[$switchToExecute];
                        $switchData['ssh_port'] = $storedSettings['ssh_port'] ?? 22;
                        $switchData['ssh_username'] = $storedSettings['ssh_username'] ?? '';
                        $switchData['ssh_password'] = $storedSettings['ssh_password'] ?? '';

                        $commands = array_filter(
                            array_map('trim', explode("\n", (string)($pendingChange['commands'] ?? ''))),
                            static fn($c): bool => $c !== ''
                        );

                        if (empty($commands)) {
                            $executionResult = [
                                'ok' => false,
                                'output' => 'Ausfuehrung fehlgeschlagen: Queue-Eintrag enthaelt keine Befehle.'
                            ];
                        } else {
                            $queueManager->updatePendingChange($pendingUuid, 'executing');
                            $result = runAutomationSshCommands(
                                $switchData,
                                $commands,
                                $logger,
                                $switchToExecute,
                                (string)($pendingChange['profile_id'] ?? ''),
                                (string)($pendingChange['template_id'] ?? '')
                            );

                            $queueManager->updatePendingChange(
                                $pendingUuid,
                                !empty($result['ok']) ? 'completed' : 'failed',
                                (string)($result['output'] ?? '')
                            );

                            $executionResult = [
                                'ok' => !empty($result['ok']),
                                'output' => "Einzel-Eintrag ausgefuehrt: " . $pendingUuid . "\n\n" . (string)($result['output'] ?? '')
                            ];

                            logAutomationExecutionEvent(
                                $db,
                                (string)($_SESSION['uuid'] ?? ''),
                                'queue_execute_one',
                                $switchToExecute,
                                (string)($pendingChange['profile_id'] ?? ''),
                                (string)($pendingChange['template_id'] ?? ''),
                                count($commands),
                                !empty($result['ok']),
                                [
                                    'pending_uuid' => $pendingUuid
                                ]
                            );
                        }
                    }
                }
            } elseif ($queueAction === 'execute_selected' && isset($_POST['pending_uuids']) && is_array($_POST['pending_uuids'])) {
                $pendingUuids = array_values(array_unique(array_filter(array_map(static function($uuid) {
                    return trim((string)$uuid);
                }, $_POST['pending_uuids']), static function($uuid) {
                    return $uuid !== '';
                })));
                $switchToExecute = (string)($_POST['execute_selected_switch'] ?? '');

                if (empty($pendingUuids)) {
                    $executionResult = [
                        'ok' => false,
                        'output' => 'Keine Queue-Eintraege fuer die Ausfuehrung ausgewaehlt.'
                    ];
                } elseif ($switchToExecute === '' || !isset($switches[$switchToExecute])) {
                    $executionResult = [
                        'ok' => false,
                        'output' => 'Ausfuehrung fehlgeschlagen: Ungueltiger Switch fuer Auswahl-Ausfuehrung.'
                    ];
                } else {
                    $switchData = $switches[$switchToExecute];
                    $switchData['ssh_port'] = $storedSettings['ssh_port'] ?? 22;
                    $switchData['ssh_username'] = $storedSettings['ssh_username'] ?? '';
                    $switchData['ssh_password'] = $storedSettings['ssh_password'] ?? '';

                    $executedCount = 0;
                    $failedCount = 0;
                    $resultLines = [];

                    foreach ($pendingUuids as $pendingUuid) {
                        $pendingChange = $queueManager->getPendingChange($pendingUuid, $_SESSION['uuid']);
                        if (!is_array($pendingChange)) {
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] FEHLER: Eintrag nicht gefunden oder keine Berechtigung.';
                            continue;
                        }

                        if ((string)($pendingChange['status'] ?? '') !== 'pending') {
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] FEHLER: Eintrag ist nicht mehr ausstehend.';
                            continue;
                        }

                        $entrySwitch = (string)($pendingChange['switch_name'] ?? '');
                        if ($entrySwitch !== $switchToExecute) {
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] FEHLER: Eintrag gehoert zu einem anderen Switch (' . $entrySwitch . ').';
                            continue;
                        }

                        $commands = array_filter(
                            array_map('trim', explode("\n", (string)($pendingChange['commands'] ?? ''))),
                            static fn($c): bool => $c !== ''
                        );

                        if (empty($commands)) {
                            $queueManager->updatePendingChange($pendingUuid, 'failed', 'Queue-Eintrag enthaelt keine Befehle.');
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] FEHLER: Keine Befehle im Queue-Eintrag.';
                            continue;
                        }

                        $queueManager->updatePendingChange($pendingUuid, 'executing');
                        $result = runAutomationSshCommands(
                            $switchData,
                            $commands,
                            $logger,
                            $switchToExecute,
                            (string)($pendingChange['profile_id'] ?? ''),
                            (string)($pendingChange['template_id'] ?? '')
                        );

                        $ok = !empty($result['ok']);
                        $queueManager->updatePendingChange(
                            $pendingUuid,
                            $ok ? 'completed' : 'failed',
                            (string)($result['output'] ?? '')
                        );

                        if ($ok) {
                            $executedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] OK';
                        } else {
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] FEHLER';
                        }

                        logAutomationExecutionEvent(
                            $db,
                            (string)($_SESSION['uuid'] ?? ''),
                            'queue_execute_selected',
                            $switchToExecute,
                            (string)($pendingChange['profile_id'] ?? ''),
                            (string)($pendingChange['template_id'] ?? ''),
                            count($commands),
                            $ok,
                            [
                                'pending_uuid' => $pendingUuid
                            ]
                        );
                    }

                    $executionResult = [
                        'ok' => ($failedCount === 0),
                        'output' => "Auswahl-Ausfuehrung abgeschlossen:\n"
                            . '- Ausgewaehlt: ' . count($pendingUuids) . "\n"
                            . '- Erfolgreich: ' . $executedCount . "\n"
                            . '- Fehlgeschlagen: ' . $failedCount . "\n\n"
                            . implode("\n", $resultLines)
                    ];
                }
            } elseif ($queueAction === 'delete' && isset($_POST['pending_uuid'])) {
                $pendingUuid = (string)($_POST['pending_uuid']);
                $success = $queueManager->deletePendingChange($pendingUuid, $_SESSION['uuid']);
                $executionResult = [
                    'ok' => $success,
                    'output' => $success ? 'Aenderung aus Warteschlange entfernt.' : 'Fehler beim Loeschen.'
                ];
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute'])) {
        $queueMode = isset($_POST['queue_mode']) && $_POST['queue_mode'] === 'on';
        $requiredPermission = $queueMode ? 'write' : 'execute';
        $hasRequiredPermission = $queueMode ? $canAutomationWrite : $canAutomationExecute;

        if (!$hasRequiredPermission) {
            $logger->log('user denied access to automation ' . $requiredPermission, 2, echoToWeb: true);
            $executionResult = [
                'ok' => false,
                'output' => $queueMode
                    ? 'Warteschlange fehlgeschlagen: Sie haben keine Schreibberechtigung fuer Automatisierungen.'
                    : 'Ausfuehrung fehlgeschlagen: Sie haben keine Execute-Berechtigung fuer Automatisierungen.'
            ];
        } else {
            $selectedSwitch = (string)($_POST['switch'] ?? $selectedSwitch);
            $selectedProfile = (string)($_POST['profile'] ?? $selectedProfile);
            $selectedTemplate = (string)($_POST['template'] ?? $selectedTemplate);
            $selectedSaveMode = (string)($_POST['save_mode'] ?? $selectedSaveMode);
            $selectedErrorStrategy = (string)($_POST['error_strategy'] ?? $selectedErrorStrategy);
            $batchInterfacesInput = (string)($_POST['batch_interfaces'] ?? $batchInterfacesInput);
            $pipelineTemplatesInput = (string)($_POST['pipeline_templates'] ?? $pipelineTemplatesInput);

            if (!in_array($selectedSaveMode, ['immediate', 'skip_save'], true)) {
                $selectedSaveMode = 'immediate';
            }

            if (!in_array($selectedErrorStrategy, ['continue_report', 'stop_on_error', 'stop_with_rollback'], true)) {
                $selectedErrorStrategy = 'continue_report';
            }

            if (isset($switches[$selectedSwitch])) {
                $selectedSwitchData = $switches[$selectedSwitch];
            }

            $templateDefinition = $templates[$selectedTemplate] ?? [];
            foreach (($templateDefinition['variables'] ?? []) as $variable) {
                $name = $variable['name'] ?? '';
                if ($name === '') {
                    continue;
                }
                $variableValues[$name] = $_POST[$name] ?? '';
            }

            $batchInterfaces = parseBatchInterfaceList($batchInterfacesInput);
            $rendered = [
                'commands' => [],
                'warnings' => []
            ];
            $pipelineGroups = [];

            $rendered['commands'] = buildCommandsWithPipeline(
                $automation,
                $templates,
                $selectedTemplate,
                $selectedProfile,
                $variableValues,
                $batchInterfaces,
                $pipelineTemplatesInput,
                $profiles[$selectedProfile] ?? [],
                $rendered['warnings'],
                $pipelineGroups
            );

            $pipelineGroups = applySaveModeToPipelineGroups(
                $pipelineGroups,
                $profiles[$selectedProfile] ?? [],
                $selectedSaveMode
            );
            $rendered['commands'] = flattenPipelineCommands($pipelineGroups);

            $logger->log(
                'automation execute prepared user=' . (string)($_SESSION['uuid'] ?? '')
                . ' switch=' . $selectedSwitch
                . ' profile=' . $selectedProfile
                . ' template=' . $selectedTemplate
                . ' save_mode=' . $selectedSaveMode
                . ' error_strategy=' . $selectedErrorStrategy
                . ' pipeline_groups=' . count($pipelineGroups)
                . ' batch_interfaces=' . count($batchInterfaces)
                . ' command_count=' . count($rendered['commands'] ?? []),
                0
            );

            if ($selectedSaveMode === 'skip_save') {
                $rendered['warnings'][] = 'Save-Befehle wurden fuer diesen Lauf uebersprungen (save/write_config).';
            }

            if (in_array($selectedErrorStrategy, ['stop_on_error', 'stop_with_rollback'], true) && count($pipelineGroups) > 1) {
                $rendered['warnings'][] = 'Error-Strategie aktiv: ' . $selectedErrorStrategy . ' (Template-weise Ausfuehrung mit Abbruch bei erstem Fehler).';
            }

            if ($queueMode) {
                if (!is_array($selectedSwitchData)) {
                    $executionResult = [
                        'ok' => false,
                        'output' => 'Warteschlange: Bitte zuerst einen gueltigen Switch auswaehlen.'
                    ];
                } elseif (empty($rendered['commands'])) {
                    $executionResult = [
                        'ok' => false,
                        'output' => 'Warteschlange: Keine Befehle zum Speichern vorhanden.'
                    ];
                } else {
                    // Add to queue instead of executing immediately
                    $queueUuid = $queueManager->addPendingChange(
                        $_SESSION['uuid'],
                        $selectedSwitch,
                        $selectedProfile,
                        $pipelineTemplatesInput !== '' ? ('pipeline:' . $selectedTemplate) : $selectedTemplate,
                        $rendered['commands'] ?? [],
                        $variableValues
                    );

                    $executionResult = [
                        'ok' => true,
                        'output' => 'Aenderung in Warteschlange eingefuegt.\n\nQueue-UUID: ' . $queueUuid . '\n\nDie Aenderung wird ausgefuehrt, wenn Sie auf dem Tab "Warteschlange" alle ausstehenden Aenderungen ausfuehren.'
                    ];
                    $activeTab = 'queue';
                }
            } elseif (is_array($selectedSwitchData)) {
                // Execute immediately
                $selectedSwitchData['ssh_port'] = $storedSettings['ssh_port'] ?? 22;
                $selectedSwitchData['ssh_username'] = $storedSettings['ssh_username'] ?? '';
                $selectedSwitchData['ssh_password'] = $storedSettings['ssh_password'] ?? '';

                if ($selectedErrorStrategy === 'stop_on_error' && count($pipelineGroups) > 1) {
                    $executionResult = executePipelineStopOnError(
                        $selectedSwitchData,
                        $pipelineGroups,
                        $logger,
                        $selectedSwitch,
                        $selectedProfile
                    );
                } elseif ($selectedErrorStrategy === 'stop_with_rollback' && count($pipelineGroups) > 1) {
                    $executionResult = executePipelineStopWithRollback(
                        $selectedSwitchData,
                        $pipelineGroups,
                        $logger,
                        $selectedSwitch,
                        $selectedProfile
                    );
                } else {
                    $executionResult = runAutomationSshCommands($selectedSwitchData, $rendered['commands'] ?? [], $logger, $selectedSwitch, $selectedProfile, $selectedTemplate);
                }

                logAutomationExecutionEvent(
                    $db,
                    (string)($_SESSION['uuid'] ?? ''),
                    'immediate_execute',
                    $selectedSwitch,
                    $selectedProfile,
                    $selectedTemplate,
                    count($rendered['commands'] ?? []),
                    !empty($executionResult['ok']),
                    [
                        'error_strategy' => $selectedErrorStrategy,
                        'save_mode' => $selectedSaveMode
                    ]
                );

                $logger->log(
                    'automation execute finished switch=' . $selectedSwitch
                    . ' strategy=' . $selectedErrorStrategy
                    . ' ok=' . (!empty($executionResult['ok']) ? '1' : '0'),
                    !empty($executionResult['ok']) ? 1 : 3
                );
            } else {
                $executionResult = [
                    'ok' => false,
                    'output' => 'Ausfuehrung fehlgeschlagen: Kein gueltiger Switch wurde ausgewaehlt.'
                ];
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['execute'])) {
        foreach (($templateDefinition['variables'] ?? []) as $variable) {
            $name = $variable['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $variableValues[$name] = $_GET[$name] ?? '';
        }
    }

    $rendered = [];
    if (!empty($selectedProfile) && !empty($selectedTemplate)) {
        $rendered = [
            'commands' => [],
            'warnings' => []
        ];
        $previewPipelineGroups = [];

        $batchInterfaces = parseBatchInterfaceList($batchInterfacesInput);
        $rendered['commands'] = buildCommandsWithPipeline(
            $automation,
            $templates,
            $selectedTemplate,
            $selectedProfile,
            $variableValues,
            $batchInterfaces,
            $pipelineTemplatesInput,
            $profiles[$selectedProfile] ?? [],
            $rendered['warnings'],
            $previewPipelineGroups
        );

        $previewPipelineGroups = applySaveModeToPipelineGroups(
            $previewPipelineGroups,
            $profiles[$selectedProfile] ?? [],
            $selectedSaveMode
        );
        $rendered['commands'] = flattenPipelineCommands($previewPipelineGroups);

        if ($selectedSaveMode === 'skip_save') {
            $rendered['warnings'][] = 'Preview ohne Save-Befehle (save/write_config).';
        }
        if ($selectedErrorStrategy === 'stop_on_error' && count($previewPipelineGroups) > 1) {
            $rendered['warnings'][] = 'Preview-Hinweis: stop_on_error fuehrt Templates nacheinander aus und bricht bei Fehler ab.';
        }
        if ($selectedErrorStrategy === 'stop_with_rollback' && count($previewPipelineGroups) > 1) {
            $rendered['warnings'][] = 'Preview-Hinweis: stop_with_rollback versucht bei Fehlern bereits ausgefuehrte Templates rueckgaengig zu machen (wenn rollback_commands definiert sind).';
        }
    }

    $executionStateClass = !empty($executionResult['ok'])
        ? 'bg-green-50 border-green-200 text-green-900'
        : 'bg-red-50 border-red-200 text-red-900';

    function runAutomationSshCommands(array $connection, array $commands, Logger $logger, string $switchName, string $profileId, string $templateId): array {
        $host = trim((string)($connection['mgmt_ip'] ?? ''));
        $port = (int)($connection['ssh_port'] ?? 22);
        $username = trim((string)($connection['ssh_username'] ?? ''));
        $password = (string)($connection['ssh_password'] ?? '');

        if ($host === '' || $username === '') {
            return [
                'ok' => false,
                'output' => 'Ausfuehrung fehlgeschlagen: Host und Username fehlen.'
            ];
        }

        $sshPath = trim((string)shell_exec('command -v ssh 2>/dev/null'));
        if ($sshPath === '') {
            return [
                'ok' => false,
                'output' => 'Ausfuehrung fehlgeschlagen: ssh Binary wurde nicht gefunden.'
            ];
        }

        $sshpassPath = trim((string)shell_exec('command -v sshpass 2>/dev/null'));
        $timeoutPath = trim((string)shell_exec('command -v timeout 2>/dev/null'));

        $commandFile = tempnam(sys_get_temp_dir(), 'portflow-automation-');
        if ($commandFile === false) {
            return [
                'ok' => false,
                'output' => 'Ausfuehrung fehlgeschlagen: Konnte keine temporäre Datei anlegen.'
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

        // Explicitly logout to prevent long waits on open VTY sessions.
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
                    'output' => 'Ausfuehrung fehlgeschlagen: sshpass wurde nicht gefunden.'
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

        $reportedCommands = buildCommandStatusLines($commands, $lines, $exitCode);

        $outputText = "Command: " . $maskedCommand . "\n";
        $outputText .= "Exit Code: " . $exitCode . "\n";
        $outputText .= "Duration: " . number_format($durationSec, 2, '.', '') . "s\n\n";
        if (!empty($reportedCommands)) {
            $outputText .= "Command Status (heuristisch):\n" . implode("\n", $reportedCommands) . "\n\n";
        }
        $outputText .= implode("\n", $lines);

        if ($exitCode === 124) {
            $outputText .= "\n\nHinweis: Timeout waehrend oder nach erfolgreicher Konfig-Anwendung. "
                . "Die Session wurde moeglicherweise nicht sauber beendet oder ein Prompt blieb offen.";
        }

        $logger->log(
            'automation execute switch=' . $switchName
                . ' profile=' . $profileId
                . ' template=' . $templateId
                . ' exit=' . $exitCode
                . ' duration=' . number_format($durationSec, 2, '.', '') . 's',
            $exitCode === 0 ? 1 : 3
        );

        return [
            'ok' => ($exitCode === 0),
            'output' => $outputText
        ];
    }

    function buildCommandStatusLines(array $commands, array $outputLines, int $exitCode): array {
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

    function applySaveModeToCommands(array $commands, array $profile, string $saveMode): array {
        if ($saveMode !== 'skip_save') {
            return $commands;
        }

        $saveCommand = strtolower(trim((string)($profile['save'] ?? '')));
        $writeConfigCommand = strtolower(trim((string)($profile['write_config'] ?? '')));

        $filtered = [];
        foreach ($commands as $command) {
            $normalized = strtolower(trim((string)$command));
            if ($normalized === '') {
                continue;
            }

            if ($saveCommand !== '' && $normalized === $saveCommand) {
                continue;
            }

            if ($writeConfigCommand !== '' && $normalized === $writeConfigCommand) {
                continue;
            }

            $filtered[] = $command;
        }

        return $filtered;
    }

    function applySaveModeToPipelineGroups(array $groups, array $profile, string $saveMode): array {
        if ($saveMode !== 'skip_save') {
            return $groups;
        }

        $result = [];
        foreach ($groups as $group) {
            $commands = applySaveModeToCommands((array)($group['commands'] ?? []), $profile, $saveMode);
            $result[] = [
                'template_id' => (string)($group['template_id'] ?? ''),
                'commands' => $commands
            ];
        }

        return $result;
    }

    function flattenPipelineCommands(array $groups): array {
        $commands = [];
        foreach ($groups as $group) {
            foreach ((array)($group['commands'] ?? []) as $command) {
                $trimmed = trim((string)$command);
                if ($trimmed === '') {
                    continue;
                }
                $commands[] = $trimmed;
            }
        }

        return $commands;
    }

    function renderRollbackCommandList(array $rollbackTemplateCommands, array $variables): array {
        $rendered = [];

        foreach ($rollbackTemplateCommands as $command) {
            $template = (string)$command;
            $line = preg_replace_callback('/\{\{([a-zA-Z0-9_.-]+)\}\}/', function ($matches) use ($variables) {
                $key = $matches[1];
                $value = $variables[$key] ?? '';
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                if (is_array($value)) {
                    return implode(', ', $value);
                }
                return (string)$value;
            }, $template);

            $trimmed = trim((string)$line);
            if ($trimmed !== '') {
                $rendered[] = $trimmed;
            }
        }

        return $rendered;
    }

    function buildRollbackCommandsWithProfile(array $rollbackLines, array $profile): array {
        $commands = [];

        $enterConfig = trim((string)($profile['enter_config'] ?? ''));
        $commit = trim((string)($profile['commit'] ?? ''));
        $exitConfig = trim((string)($profile['exit_config'] ?? ''));
        $save = trim((string)($profile['save'] ?? ''));
        $writeConfig = normalizeWriteConfigCommand((string)($profile['write_config'] ?? ''));

        if ($enterConfig !== '') {
            $commands[] = $enterConfig;
        }

        foreach ($rollbackLines as $line) {
            $trimmed = trim((string)$line);
            if ($trimmed !== '') {
                $commands[] = $trimmed;
            }
        }

        if (!empty($profile['supports_commit']) && $commit !== '') {
            $commands[] = $commit;
        }
        if ($exitConfig !== '') {
            $commands[] = $exitConfig;
        }
        if ($save !== '') {
            $commands[] = $save;
        }
        if ($writeConfig !== '') {
            $commands[] = $writeConfig;
        }

        return $commands;
    }

    function executePipelineStopOnError(
        array $connection,
        array $pipelineGroups,
        Logger $logger,
        string $switchName,
        string $profileId
    ): array {
        $reportLines = [];
        $overallOk = true;

        foreach ($pipelineGroups as $index => $group) {
            $templateId = (string)($group['template_id'] ?? ('template_' . ($index + 1)));
            $commands = array_filter(
                array_map('trim', (array)($group['commands'] ?? [])),
                static fn($c): bool => $c !== ''
            );

            if (empty($commands)) {
                $reportLines[] = '[' . $templateId . '] SKIPPED (keine Befehle)';
                continue;
            }

            $result = runAutomationSshCommands($connection, $commands, $logger, $switchName, $profileId, $templateId);
            $logger->log('stop_with_rollback step template=' . $templateId . ' ok=' . (!empty($result['ok']) ? '1' : '0'), !empty($result['ok']) ? 0 : 2);
            $statusText = !empty($result['ok']) ? 'OK' : 'FAILED';
            $reportLines[] = '[' . $templateId . '] ' . $statusText;

            if (isset($result['output'])) {
                $reportLines[] = "--- Output " . $templateId . " ---";
                $reportLines[] = (string)$result['output'];
            }

            if (empty($result['ok'])) {
                $overallOk = false;
                $reportLines[] = 'Abbruch: stop_on_error hat weitere Templates nicht mehr ausgefuehrt.';
                break;
            }
        }

        return [
            'ok' => $overallOk,
            'output' => implode("\n", $reportLines)
        ];
    }

    function executePipelineStopWithRollback(
        array $connection,
        array $pipelineGroups,
        Logger $logger,
        string $switchName,
        string $profileId
    ): array {
        $reportLines = [];
        $executed = [];
        $overallOk = true;

        foreach ($pipelineGroups as $index => $group) {
            $templateId = (string)($group['template_id'] ?? ('template_' . ($index + 1)));
            $commands = array_filter(
                array_map('trim', (array)($group['commands'] ?? [])),
                static fn($c): bool => $c !== ''
            );

            if (empty($commands)) {
                $reportLines[] = '[' . $templateId . '] SKIPPED (keine Befehle)';
                continue;
            }

            $result = runAutomationSshCommands($connection, $commands, $logger, $switchName, $profileId, $templateId);
            $statusText = !empty($result['ok']) ? 'OK' : 'FAILED';
            $reportLines[] = '[' . $templateId . '] ' . $statusText;

            if (isset($result['output'])) {
                $reportLines[] = '--- Output ' . $templateId . ' ---';
                $reportLines[] = (string)$result['output'];
            }

            if (!empty($result['ok'])) {
                $executed[] = [
                    'template_id' => $templateId,
                    'rollback_commands' => (array)($group['rollback_commands'] ?? [])
                ];
                continue;
            }

            $overallOk = false;
            $reportLines[] = 'Abbruch: stop_with_rollback hat weitere Templates nicht mehr ausgefuehrt.';

            if (!empty($executed)) {
                $reportLines[] = 'Rollback gestartet fuer bereits ausgefuehrte Templates (reverse order).';
            }

            for ($r = count($executed) - 1; $r >= 0; $r--) {
                $rollbackTemplateId = (string)($executed[$r]['template_id'] ?? ('template_' . ($r + 1)));
                $rollbackCommands = array_filter(
                    array_map('trim', (array)($executed[$r]['rollback_commands'] ?? [])),
                    static fn($c): bool => $c !== ''
                );

                if (empty($rollbackCommands)) {
                    $reportLines[] = '[ROLLBACK ' . $rollbackTemplateId . '] SKIPPED (keine rollback_commands definiert)';
                    continue;
                }

                $rollbackResult = runAutomationSshCommands(
                    $connection,
                    $rollbackCommands,
                    $logger,
                    $switchName,
                    $profileId,
                    $rollbackTemplateId . '.rollback'
                );
                $logger->log('stop_with_rollback rollback template=' . $rollbackTemplateId . ' ok=' . (!empty($rollbackResult['ok']) ? '1' : '0'), !empty($rollbackResult['ok']) ? 1 : 3);

                $rollbackStatus = !empty($rollbackResult['ok']) ? 'OK' : 'FAILED';
                $reportLines[] = '[ROLLBACK ' . $rollbackTemplateId . '] ' . $rollbackStatus;

                if (isset($rollbackResult['output'])) {
                    $reportLines[] = '--- Output ROLLBACK ' . $rollbackTemplateId . ' ---';
                    $reportLines[] = (string)$rollbackResult['output'];
                }

                if (empty($rollbackResult['ok'])) {
                    $reportLines[] = 'Rollback-Fehler bei Template: ' . $rollbackTemplateId;
                }
            }

            break;
        }

        return [
            'ok' => $overallOk,
            'output' => implode("\n", $reportLines)
        ];
    }

    function parseBatchInterfaceList(string $input): array {
        $lines = preg_split('/\r\n|\r|\n/', $input) ?: [];
        $interfaces = [];

        foreach ($lines as $line) {
            $value = trim((string)$line);
            if ($value === '') {
                continue;
            }
            $interfaces[$value] = true;
        }

        return array_keys($interfaces);
    }

    function parseTemplatePipelineList(string $input): array {
        $lines = preg_split('/\r\n|\r|\n/', $input) ?: [];
        $templateIds = [];

        foreach ($lines as $line) {
            $value = trim((string)$line);
            if ($value === '') {
                continue;
            }
            $templateIds[$value] = true;
        }

        return array_keys($templateIds);
    }

    function normalizeWriteConfigCommand(string $writeConfig): string {
        $value = trim($writeConfig);
        if ($value === '') {
            return '';
        }

        $normalized = strtolower($value);
        if (in_array($normalized, ['yes', 'true', '1'], true)) {
            return 'Y';
        }
        if (in_array($normalized, ['no', 'false', '0'], true)) {
            return 'N';
        }

        return $value;
    }

    function buildBatchCommandsForInterfaces(
        Automation $automation,
        string $templateId,
        string $profileId,
        array $baseVariables,
        array $interfaces,
        array $profile
    ): array {
        $commands = [];

        $enterConfig = trim((string)($profile['enter_config'] ?? ''));
        $commit = trim((string)($profile['commit'] ?? ''));
        $exitConfig = trim((string)($profile['exit_config'] ?? ''));
        $save = trim((string)($profile['save'] ?? ''));
        $writeConfig = normalizeWriteConfigCommand((string)($profile['write_config'] ?? ''));

        if ($enterConfig !== '') {
            $commands[] = $enterConfig;
        }

        foreach ($interfaces as $interfaceValue) {
            $variables = $baseVariables;
            $variables['interface'] = $interfaceValue;

            $rendered = $automation->renderTemplate($templateId, $profileId, $variables);
            foreach ((array)($rendered['commands'] ?? []) as $command) {
                $trimmed = trim((string)$command);
                if ($trimmed === '') {
                    continue;
                }

                if ($enterConfig !== '' && $trimmed === $enterConfig) {
                    continue;
                }
                if (!empty($profile['supports_commit']) && $commit !== '' && $trimmed === $commit) {
                    continue;
                }
                if ($exitConfig !== '' && $trimmed === $exitConfig) {
                    continue;
                }
                if ($save !== '' && $trimmed === $save) {
                    continue;
                }
                if ($writeConfig !== '' && $trimmed === $writeConfig) {
                    continue;
                }

                $commands[] = $trimmed;
            }
        }

        if (!empty($profile['supports_commit']) && $commit !== '') {
            $commands[] = $commit;
        }
        if ($exitConfig !== '') {
            $commands[] = $exitConfig;
        }
        if ($save !== '') {
            $commands[] = $save;
        }
        if ($writeConfig !== '') {
            $commands[] = $writeConfig;
        }

        return $commands;
    }

    function buildCommandsWithPipeline(
        Automation $automation,
        array $templates,
        string $selectedTemplate,
        string $selectedProfile,
        array $variableValues,
        array $batchInterfaces,
        string $pipelineTemplatesInput,
        array $profile,
        array &$warnings,
        array &$pipelineGroups = []
    ): array {
        $commands = [];
        $pipelineTemplates = parseTemplatePipelineList($pipelineTemplatesInput);
        $templateSequence = empty($pipelineTemplates) ? [$selectedTemplate] : $pipelineTemplates;
        $pipelineGroups = [];

        if (!empty($pipelineTemplates)) {
            $warnings[] = 'Pipeline aktiv: ' . count($templateSequence) . ' Templates werden nacheinander ausgefuehrt.';
        }

        foreach ($templateSequence as $templateId) {
            if (!isset($templates[$templateId])) {
                $warnings[] = 'Template in Pipeline nicht gefunden: ' . $templateId;
                continue;
            }

            $currentTemplateDefinition = (array)$templates[$templateId];
            $currentCommands = [];

            if (!empty($batchInterfaces)) {
                $templateVarNames = array_map(
                    static fn($variable): string => (string)($variable['name'] ?? ''),
                    (array)($currentTemplateDefinition['variables'] ?? [])
                );

                if (!in_array('interface', $templateVarNames, true)) {
                    $warnings[] = 'Batch fuer Template "' . $templateId . '" ignoriert: Variable "interface" fehlt, es wird einmalig ausgefuehrt.';
                    $rendered = $automation->renderTemplate($templateId, $selectedProfile, $variableValues);
                    foreach ((array)($rendered['warnings'] ?? []) as $warning) {
                        $warnings[] = '[' . $templateId . '] ' . (string)$warning;
                    }
                    $currentCommands = (array)($rendered['commands'] ?? []);
                } else {
                    $currentCommands = buildBatchCommandsForInterfaces(
                        $automation,
                        $templateId,
                        $selectedProfile,
                        $variableValues,
                        $batchInterfaces,
                        $profile
                    );
                }
            } else {
                $rendered = $automation->renderTemplate($templateId, $selectedProfile, $variableValues);
                foreach ((array)($rendered['warnings'] ?? []) as $warning) {
                    $warnings[] = '[' . $templateId . '] ' . (string)$warning;
                }
                $currentCommands = (array)($rendered['commands'] ?? []);
            }

            foreach ($currentCommands as $command) {
                $trimmed = trim((string)$command);
                if ($trimmed === '') {
                    continue;
                }
                $commands[] = $trimmed;
            }

            $pipelineGroups[] = [
                'template_id' => $templateId,
                'commands' => array_values(array_filter(
                    array_map(static fn($cmd): string => trim((string)$cmd, " \t\n\r\0\x0B"), $currentCommands),
                    static fn($cmd): bool => $cmd !== ''
                )),
                'rollback_commands' => []
            ];

            $rollbackTemplateCommands = (array)($currentTemplateDefinition['rollback_commands'] ?? []);
            if (!empty($rollbackTemplateCommands)) {
                $groupIndex = count($pipelineGroups) - 1;
                $rollbackCommands = [];

                if (!empty($batchInterfaces)) {
                    $templateVarNames = array_map(
                        static fn($variable): string => (string)($variable['name'] ?? ''),
                        (array)($currentTemplateDefinition['variables'] ?? [])
                    );

                    if (in_array('interface', $templateVarNames, true)) {
                        foreach ($batchInterfaces as $interfaceValue) {
                            $rollbackVariables = $variableValues;
                            $rollbackVariables['interface'] = $interfaceValue;
                            $rollbackLines = renderRollbackCommandList($rollbackTemplateCommands, $rollbackVariables);
                            $rollbackCommands = array_merge($rollbackCommands, buildRollbackCommandsWithProfile($rollbackLines, $profile));
                        }
                    } else {
                        $rollbackLines = renderRollbackCommandList($rollbackTemplateCommands, $variableValues);
                        $rollbackCommands = buildRollbackCommandsWithProfile($rollbackLines, $profile);
                    }
                } else {
                    $rollbackLines = renderRollbackCommandList($rollbackTemplateCommands, $variableValues);
                    $rollbackCommands = buildRollbackCommandsWithProfile($rollbackLines, $profile);
                }

                $pipelineGroups[$groupIndex]['rollback_commands'] = array_values(array_filter(
                    array_map(static fn($cmd): string => trim((string)$cmd), $rollbackCommands),
                    static fn($cmd): bool => $cmd !== ''
                ));
            }
        }

        if (!empty($batchInterfaces)) {
            $warnings[] = 'Batch-Modus aktiv: ' . count($batchInterfaces) . ' Interfaces werden verarbeitet.';
        }

        return $commands;
    }

    function automation_escape($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    function logAutomationExecutionEvent(
        \Portflow\Core\DatabaseAdapter $db,
        string $userUuid,
        string $mode,
        string $switchName,
        string $profileId,
        string $templateId,
        int $commandCount,
        bool $ok,
        array $extra = []
    ): void {
        $payload = array_merge([
            'event' => 'script_execution',
            'mode' => $mode,
            'switch' => $switchName,
            'profile' => $profileId,
            'template' => $templateId,
            'command_count' => $commandCount,
            'ok' => $ok
        ], $extra);

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encodedPayload)) {
            $encodedPayload = '{"event":"script_execution","error":"encoding_failed"}';
        }

        try {
            $db->db_query(
                "INSERT INTO changelog (users, operation, changed_table, changed_row, changed_data)
                 VALUES (:users, :operation, :changed_table, gen_random_uuid(), :changed_data)",
                [
                    'users' => $userUuid !== '' ? $userUuid : null,
                    'operation' => 'INSERT',
                    'changed_table' => 'script_execution',
                    'changed_data' => $encodedPayload
                ]
            );
        } catch (\Throwable $ignored) {
            // Best-effort execution trace logging.
        }
    }
?>
<div class="h-full flex overflow-x-clip bg-gray-100 rounded-xl shadow-md m-4 mt-0 p-4">
    <div class="basis-1/5 flex flex-col gap-4 overflow-y-scroll pr-4">
        <div class="bg-white rounded-2xl shadow-md p-4">
            <div class="text-xl font-bold pb-2"><?php echo automation_escape($config['description_convention']['label'] ?? 'Portflow Description Convention'); ?></div>
            <p class="text-sm text-gray-600">Verwende einen festen, maschinenlesbaren Aufbau fuer Switch-Port-Beschreibungen.</p>
            <ul class="mt-3 space-y-2 text-sm text-gray-700 list-disc list-inside">
                <?php foreach (($config['description_convention']['notes'] ?? []) as $note) : ?>
                    <li><?php echo automation_escape($note); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="bg-white rounded-2xl shadow-md p-4">
            <div class="text-lg font-bold pb-2">Profiles</div>
            <div class="space-y-3">
                <?php foreach ($profiles as $profileKey => $profile) : ?>
                    <div class="border rounded-xl p-3 <?php echo $profileKey === $selectedProfile ? 'border-blue-500 bg-blue-50' : 'border-gray-200'; ?>">
                        <div class="font-semibold"><?php echo automation_escape($profile['label'] ?? $profileKey); ?></div>
                        <div class="text-xs text-gray-600 mt-1"><?php echo automation_escape($profile['description'] ?? ''); ?></div>
                        <div class="text-xs mt-2 text-gray-500"><?php echo !empty($profile['supports_commit']) ? 'Commit required' : 'No commit step'; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="basis-4/5 bg-white rounded-2xl shadow-md p-6 overflow-y-scroll">
        <!-- Tabs -->
        <div class="flex gap-4 border-b mb-6">
            <button type="button" class="automation-tab px-4 py-2 font-semibold border-b-2 border-blue-500 text-blue-600" data-tab="automation">
                Automation
            </button>
            <button type="button" class="automation-tab px-4 py-2 font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-700" data-tab="queue">
                Warteschlange
                <?php 
                    $pendingSummary = $queueManager->getPendingSummary($_SESSION['uuid'] ?? '');
                    $totalPending = array_sum($pendingSummary);
                    if ($totalPending > 0) {
                        echo '<span class="ml-2 inline-block bg-red-500 text-white text-xs rounded-full px-2 py-1">' . $totalPending . '</span>';
                    }
                ?>
            </button>
        </div>
        
        <!-- Automation Tab -->
        <div id="automation-content" class="tab-content">
            <div class="flex justify-between items-start gap-6 pb-6">
                <div>
                    <div class="text-2xl font-bold">Automation Preview</div>
                    <div class="text-sm text-gray-600">Konfiguration und Kommandosequenz fuer Huawei Switches.</div>
                </div>
                <div class="text-sm text-gray-500 max-w-xl text-right">
                    Waehle Switch, Profil, Template und Variablen, dann ausfuehren oder zu Warteschlange hinzufuegen.
                </div>
            </div>

        <form id="automation-preview-form" class="grid grid-cols-1 lg:grid-cols-2 gap-6 pb-8" method="GET" action="automation.php">
            <div class="space-y-4 bg-gray-50 rounded-2xl p-4">
                <div>
                    <label class="block text-sm font-semibold mb-2" for="switch">Switch Target</label>
                    <select id="switch" name="switch" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <option value="">Kein Ziel ausgewaehlt</option>
                        <?php foreach ($switches as $switchName => $switchData) : ?>
                            <option value="<?php echo automation_escape($switchName); ?>" <?php echo $switchName === $selectedSwitch ? 'selected' : ''; ?> data-profile="<?php echo automation_escape($switchData['profile'] ?? ''); ?>"><?php echo automation_escape($switchName); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (is_array($selectedSwitchData)) : ?>
                        <div class="text-xs text-gray-600 mt-2">
                            Management IP: <span class="font-semibold"><?php echo automation_escape($selectedSwitchData['mgmt_ip'] ?? ''); ?></span>
                            <?php if (!empty($selectedSwitchData['profile'])) : ?>
                                | Profil: <span class="font-semibold"><?php echo automation_escape($selectedSwitchData['profile']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="profile">Switch Profile</label>
                    <select id="profile" name="profile" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <?php foreach ($profiles as $profileKey => $profile) : ?>
                            <option value="<?php echo automation_escape($profileKey); ?>" <?php echo $profileKey === $selectedProfile ? 'selected' : ''; ?>><?php echo automation_escape($profile['label'] ?? $profileKey); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="template">Script Template</label>
                    <select id="template" name="template" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <?php foreach ($templates as $templateKey => $template) : ?>
                            <option value="<?php echo automation_escape($templateKey); ?>" <?php echo $templateKey === $selectedTemplate ? 'selected' : ''; ?>><?php echo automation_escape($template['label'] ?? $templateKey); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="save_mode">Save Strategy</label>
                    <select id="save_mode" name="save_mode" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <option value="immediate" <?php echo $selectedSaveMode === 'immediate' ? 'selected' : ''; ?>>Sofort speichern</option>
                        <option value="skip_save" <?php echo $selectedSaveMode === 'skip_save' ? 'selected' : ''; ?>>Save in diesem Lauf ueberspringen</option>
                    </select>
                    <div class="text-xs text-gray-600 mt-2">"Ueberspringen" spart Laufzeit und eignet sich fuer Session-/Batch-Aenderungen ohne direktes Save.</div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="error_strategy">Fehlerstrategie</label>
                    <select id="error_strategy" name="error_strategy" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <option value="continue_report" <?php echo $selectedErrorStrategy === 'continue_report' ? 'selected' : ''; ?>>continue_with_report (eine Session)</option>
                        <option value="stop_on_error" <?php echo $selectedErrorStrategy === 'stop_on_error' ? 'selected' : ''; ?>>stop_on_error (Template-weise)</option>
                        <option value="stop_with_rollback" <?php echo $selectedErrorStrategy === 'stop_with_rollback' ? 'selected' : ''; ?>>stop_with_rollback (Template-weise + Rollback)</option>
                    </select>
                    <div class="text-xs text-gray-600 mt-2">stop_on_error bricht bei erstem Fehler ab. stop_with_rollback versucht bereits ausgefuehrte Templates rueckgaengig zu machen (wenn rollback_commands im Template definiert sind).</div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="batch_interfaces">Batch Interfaces (optional, eine Zeile pro Port)</label>
                    <textarea id="batch_interfaces" name="batch_interfaces" rows="4" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white font-mono text-sm" placeholder="MultiGE1/0/1&#10;MultiGE1/0/2&#10;MultiGE1/0/3"><?php echo automation_escape($batchInterfacesInput); ?></textarea>
                    <div class="text-xs text-gray-600 mt-2">Wenn gesetzt, wird das Template fuer alle Interfaces in einer einzigen SSH-Session ausgefuehrt.</div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="pipeline_templates">Template Pipeline (optional, eine Zeile pro Template-ID)</label>
                    <textarea id="pipeline_templates" name="pipeline_templates" rows="4" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white font-mono text-sm" placeholder="port_description&#10;poe_enable&#10;vlan_access"><?php echo automation_escape($pipelineTemplatesInput); ?></textarea>
                    <div class="text-xs text-gray-600 mt-2">Wenn gesetzt, werden mehrere Templates in dieser Reihenfolge in einer Session ausgefuehrt.</div>
                </div>

                <div>
                    <div class="text-sm font-semibold mb-2">Variables</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach (($templateDefinition['variables'] ?? []) as $variable) : ?>
                            <div>
                                <label class="block text-xs uppercase tracking-wide text-gray-500 mb-1" for="<?php echo automation_escape($variable['name']); ?>"><?php echo automation_escape($variable['label'] ?? $variable['name']); ?></label>
                                <input
                                    id="<?php echo automation_escape($variable['name']); ?>"
                                    name="<?php echo automation_escape($variable['name']); ?>"
                                    type="<?php echo automation_escape($variable['type'] ?? 'text'); ?>"
                                    value="<?php echo automation_escape($variableValues[$variable['name']] ?? ''); ?>"
                                    placeholder="<?php echo automation_escape($variable['placeholder'] ?? ''); ?>"
                                    class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white"
                                >
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex justify-end">
                    <div class="flex gap-3">
                        <button type="submit" class="px-5 py-2 rounded-full bg-blue-500 hover:bg-blue-700 text-white font-semibold">Preview</button>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="bg-gray-50 rounded-2xl p-4">
                    <div class="text-sm font-semibold pb-2">Template Description</div>
                    <div class="text-sm text-gray-700"><?php echo automation_escape($templateDefinition['description'] ?? ''); ?></div>
                    <?php if (!empty($rendered['warnings'])) : ?>
                        <div class="mt-3 space-y-2">
                            <?php foreach ($rendered['warnings'] as $warning) : ?>
                                <div class="rounded-xl bg-yellow-100 text-yellow-900 px-3 py-2 text-sm"><?php echo automation_escape($warning); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bg-gray-900 text-gray-100 rounded-2xl p-4 shadow-inner">
                    <div class="text-sm font-semibold pb-3">Rendered Command Sequence</div>
                    <pre class="text-sm whitespace-pre-wrap overflow-x-auto leading-6"><?php echo automation_escape(implode("\n", $rendered['commands'] ?? [])); ?></pre>
                </div>
            </div>
        </form>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
                <div class="text-lg font-bold pb-3">Current Preview Rules</div>
                <ul class="list-disc list-inside space-y-2 text-sm text-gray-700">
                    <li>Core switches use <span class="font-semibold">commit</span> plus <span class="font-semibold">save</span>.</li>
                    <li>Access switches skip commit and only use <span class="font-semibold">save</span>.</li>
                    <li>Port descriptions should start with the fixed Portflow pattern.</li>
                    <li>Variable placeholders are resolved from the selected template and can be reused in every command.</li>
                </ul>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
                <div class="text-lg font-bold pb-3">Next Step</div>
                <p class="text-sm text-gray-700 leading-6">
                    Als naechstes wird diese Vorschau mit einem SSH-Runner verbunden, damit die gleiche Template-Struktur auf Huawei Core- und Access-Switches ausgefuehrt werden kann.
                </p>
            </div>
        </div>

        <div class="mt-6 bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
            <div class="flex items-center justify-between gap-4 pb-4">
                <div>
                    <div class="text-lg font-bold">Execute Automation</div>
                    <div class="text-sm text-gray-600">Fuehrt die gerenderte Kommandosequenz auf dem gewaelten Switch aus oder fuegt zu Warteschlange hinzu.</div>
                </div>
            </div>
            <form id="automation-execute-form" method="POST" action="automation.php" onsubmit="return syncExecutionFormValues();">
                <input type="hidden" name="execute" value="1">
                <input type="hidden" name="switch" value="<?php echo automation_escape($selectedSwitch); ?>">
                <input type="hidden" name="profile" value="<?php echo automation_escape($selectedProfile); ?>">
                <input type="hidden" name="template" value="<?php echo automation_escape($selectedTemplate); ?>">
                <input type="hidden" name="save_mode" value="<?php echo automation_escape($selectedSaveMode); ?>">
                <input type="hidden" name="error_strategy" value="<?php echo automation_escape($selectedErrorStrategy); ?>">
                <input type="hidden" name="batch_interfaces" value="<?php echo automation_escape($batchInterfacesInput); ?>">
                <input type="hidden" name="pipeline_templates" value="<?php echo automation_escape($pipelineTemplatesInput); ?>">
                <?php foreach ($variableValues as $variableName => $variableValue) : ?>
                    <input type="hidden" name="<?php echo automation_escape($variableName); ?>" value="<?php echo automation_escape($variableValue); ?>">
                <?php endforeach; ?>
                <div class="flex justify-end gap-2">
                    <button type="submit" name="queue_mode" value="off" class="px-5 py-2 rounded-full bg-green-500 hover:bg-green-700 text-white font-semibold">Sofort ausfuehren</button>
                    <button type="submit" name="queue_mode" value="on" class="px-5 py-2 rounded-full bg-blue-500 hover:bg-blue-700 text-white font-semibold">Zu Warteschlange hinzufuegen</button>
                </div>
            </form>
            <?php if (is_array($executionResult) && isset($executionResult['output'])) : ?>
                <div class="mt-4 rounded-2xl border p-4 <?php echo $executionStateClass; ?>">
                    <div class="text-sm font-semibold pb-2">Execution Output</div>
                    <pre class="text-xs whitespace-pre-wrap leading-5"><?php echo automation_escape($executionResult['output']); ?></pre>
                </div>
            <?php endif; ?>
        </div>

        </div>
        
        <!-- Queue Tab -->
        <div id="queue-content" class="tab-content hidden">
            <div class="flex justify-between items-start gap-6 pb-6">
                <div>
                    <div class="text-2xl font-bold">Warteschlange</div>
                    <div class="text-sm text-gray-600">Ausstehende Aenderungen verwalten und ausfuehren.</div>
                </div>
            </div>
            
            <?php
                $userPendingSummary = $queueManager->getPendingSummary($_SESSION['uuid'] ?? '');
                if (empty($userPendingSummary)) {
                    echo '<div class="rounded-xl bg-blue-50 border border-blue-200 text-blue-900 px-4 py-3">Keine ausstehenden Aenderungen in der Warteschlange.</div>';
                } else {
                    foreach ($userPendingSummary as $switchName => $count) {
                        $queueGroupId = 'queue-group-' . md5((string)$switchName);
                        echo '<div class="bg-gray-50 rounded-xl p-4 mb-4">';
                        echo '<div class="flex justify-between items-center pb-4">';
                        echo '<div>';
                        echo '<div class="text-lg font-bold">' . automation_escape($switchName) . '</div>';
                        echo '<div class="text-sm text-gray-600">' . $count . ' ausstehende Aenderung' . ($count !== 1 ? 'en' : '') . '</div>';
                        echo '</div>';
                        echo '<div class="flex items-center gap-2">';
                        echo '<form method="POST" action="automation.php" style="display: inline;" id="' . automation_escape($queueGroupId) . '-selected" onsubmit="return ensureQueueSelection(\'' . automation_escape($queueGroupId) . '\');">';
                        echo '<input type="hidden" name="queue_action" value="execute_selected">';
                        echo '<input type="hidden" name="execute_selected_switch" value="' . automation_escape($switchName) . '">';
                        echo '<button type="submit" class="px-4 py-2 rounded-full bg-emerald-500 hover:bg-emerald-700 text-white font-semibold">Auswahl ausfuehren</button>';
                        echo '</form>';
                        echo '<form method="POST" action="automation.php" style="display: inline;">';
                        echo '<input type="hidden" name="queue_action" value="execute_all">';
                        echo '<input type="hidden" name="execute_all_switch" value="' . automation_escape($switchName) . '">';
                        echo '<button type="submit" class="px-4 py-2 rounded-full bg-green-500 hover:bg-green-700 text-white font-semibold">Alle ausfuehren</button>';
                        echo '</form>';
                        echo '</div>';
                        echo '</div>';
                        echo '<div class="pb-3 text-sm text-gray-700 flex items-center gap-2">';
                        echo '<input type="checkbox" id="' . automation_escape($queueGroupId) . '-all" onchange="toggleQueueGroup(\'' . automation_escape($queueGroupId) . '\', this.checked)">';
                        echo '<label for="' . automation_escape($queueGroupId) . '-all">Alle Eintraege dieser Gruppe markieren</label>';
                        echo '</div>';
                        
                        $pendingChanges = $queueManager->getPendingChanges($_SESSION['uuid'] ?? '', $switchName);
                        echo '<div class="space-y-2">';
                        foreach ($pendingChanges as $change) {
                            echo '<div class="bg-white border border-gray-200 rounded-lg p-3 text-sm">';
                            echo '<div class="flex justify-between items-start gap-3">';
                            echo '<div>';
                            echo '<div class="pb-2">';
                            echo '<input type="checkbox" name="pending_uuids[]" value="' . automation_escape($change['uuid']) . '" form="' . automation_escape($queueGroupId) . '-selected" data-queue-group="' . automation_escape($queueGroupId) . '">';
                            echo '<span class="ml-2 text-xs text-gray-600">Markieren fuer Sammelausfuehrung</span>';
                            echo '</div>';
                            echo '<div class="font-semibold">' . automation_escape($change['profile_id']) . ' → ' . automation_escape($change['template_id']) . '</div>';
                            echo '<div class="text-xs text-gray-500 mt-1">' . (new DateTime($change['created']))->format('Y-m-d H:i:s') . '</div>';
                            echo '<div class="text-xs text-gray-600 mt-2 font-mono bg-gray-100 p-2 rounded max-h-40 overflow-y-auto whitespace-pre-wrap">' . automation_escape((string)($change['commands'] ?? '')) . '</div>';
                            echo '</div>';
                            echo '<div class="flex gap-2">';
                            echo '<form method="POST" action="automation.php" style="display: inline;">';
                            echo '<input type="hidden" name="queue_action" value="execute_one">';
                            echo '<input type="hidden" name="pending_uuid" value="' . automation_escape($change['uuid']) . '">';
                            echo '<button type="submit" class="px-2 py-1 rounded bg-green-100 hover:bg-green-200 text-green-700 text-xs font-semibold">Ausfuehren</button>';
                            echo '</form>';
                            echo '<form method="POST" action="automation.php" style="display: inline;">';
                            echo '<input type="hidden" name="queue_action" value="delete">';
                            echo '<input type="hidden" name="pending_uuid" value="' . automation_escape($change['uuid']) . '">';
                            echo '<button type="submit" class="px-2 py-1 rounded bg-red-100 hover:bg-red-200 text-red-700 text-xs font-semibold" onclick="return confirm(\'Wirklich loeschen?\')">Loeschen</button>';
                            echo '</form>';
                            echo '</div>';
                            echo '</div>';
                            echo '</div>';
                        }
                        echo '</div>';
                        echo '</div>';
                    }
                }
            ?>
        </div>

    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    const tabButtons = document.querySelectorAll('.automation-tab');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tabName = this.getAttribute('data-tab');
            showTab(tabName);
        });
    });
    
    function showTab(tabName) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.add('hidden');
        });
        
        // Show selected tab
        const selectedTab = document.getElementById(tabName + '-content');
        if (selectedTab) {
            selectedTab.classList.remove('hidden');
        }
        
        // Update button styles
        tabButtons.forEach(button => {
            if (button.getAttribute('data-tab') === tabName) {
                button.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-700');
                button.classList.add('border-blue-500', 'text-blue-600');
            } else {
                button.classList.remove('border-blue-500', 'text-blue-600');
                button.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-700');
            }
        });
    }

    showTab('<?php echo automation_escape($activeTab); ?>');

    window.syncExecutionFormValues = function() {
        const previewForm = document.getElementById('automation-preview-form');
        const executeForm = document.getElementById('automation-execute-form');

        if (!previewForm || !executeForm) {
            return true;
        }

        const previewFields = previewForm.querySelectorAll('input[name], select[name], textarea[name]');
        previewFields.forEach(function(field) {
            const name = field.getAttribute('name');
            if (!name) {
                return;
            }

            let target = executeForm.elements.namedItem(name);
            if (!target) {
                target = document.createElement('input');
                target.type = 'hidden';
                target.name = name;
                executeForm.appendChild(target);
            }

            target.value = field.value;
        });

        return true;
    };

    window.toggleQueueGroup = function(groupId, checked) {
        const items = document.querySelectorAll('input[data-queue-group="' + groupId + '"]');
        items.forEach(function(item) {
            item.checked = checked;
        });
    };

    window.ensureQueueSelection = function(groupId) {
        const selected = document.querySelectorAll('input[data-queue-group="' + groupId + '"]:checked');
        if (selected.length === 0) {
            alert('Bitte mindestens einen Queue-Eintrag markieren.');
            return false;
        }
        return true;
    };

    // Switch select logic
    const switchSelect = document.getElementById('switch');
    const profileSelect = document.getElementById('profile');
    
    if (!switchSelect || !profileSelect) return;
    
    switchSelect.addEventListener('change', function() {
        const selectedOption = switchSelect.options[switchSelect.selectedIndex];
        const profileValue = selectedOption.getAttribute('data-profile');
        
        if (profileValue && profileValue !== '') {
            // Find and select the profile option
            for (let option of profileSelect.options) {
                if (option.value === profileValue) {
                    profileSelect.value = profileValue;
                    break;
                }
            }
        }
    });
});
</script>

<?php
    include_once 'includes/footer.php';
?>
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

    function mergeAutomationTemplatesWithDb(\Portflow\Core\DatabaseAdapter $db, array $baseTemplates): array {
        $dataPath = __DIR__ . '/data/automation/automation.json';
        if (!file_exists($dataPath)) {
            return $baseTemplates;
        }

        $raw = (string)file_get_contents($dataPath);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $baseTemplates;
        }

        $templates = is_array($decoded['templates'] ?? null) ? $decoded['templates'] : [];
        if (empty($templates)) {
            return $baseTemplates;
        }

        foreach ($templates as $templateId => $template) {
            if (!is_array($template)) {
                continue;
            }

            $id = trim((string)$templateId);
            if ($id === '') {
                continue;
            }

            $baseTemplates[$id] = [
                'label' => (string)($template['label'] ?? $id),
                'description' => (string)($template['description'] ?? ''),
                'supported_profiles' => array_values((array)($template['supported_profiles'] ?? [])),
                'variables' => array_values((array)($template['variables'] ?? [])),
                'commands' => array_values((array)($template['commands'] ?? [])),
                'uses_description_convention' => !empty($template['uses_description_convention'])
            ];
        }

        return $baseTemplates;
    }

    function resolveSwitchConnectionData(array $switchData, array $storedSettings): array {
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

        $switchData['ssh_port'] = (int)($storedSettings['ssh_port'] ?? 22);
        $switchData['ssh_auth_method'] = $authMethod;
        $switchData['ssh_username'] = $username;
        $switchData['ssh_password'] = $password;
        $switchData['ssh_private_key'] = $privateKey;

        return $switchData;
    }

    function automation_t(string $key, array $replacements = []): string {
        global $lang;

        $text = (string)($lang[$key] ?? $key);
        foreach ($replacements as $placeholder => $value) {
            $text = str_replace('{' . $placeholder . '}', (string)$value, $text);
        }

        return $text;
    }

    function automation_count_label(int $count, string $singularKey, string $pluralKey): string {
        return automation_t($count === 1 ? $singularKey : $pluralKey, ['count' => $count]);
    }

    $automation = new Automation();
    $automationStore = new AutomationStore();
    $logger = new Logger();
    $auth = new Auth();
    $db = new \Portflow\Core\DatabaseAdapter();
    $queueManager = new PendingChangesQueue($db);
    $config = $automation->getConfig();
    $profiles = $automation->getProfiles();
    $templates = $automation->getTemplates();
    $templates = mergeAutomationTemplatesWithDb($db, $templates);
    $automation->setTemplates($templates);

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
    $pipelineTemplatesInput = '';

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
    $csrf = $auth->csrf();

    // Handle queue operations
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['queue_action'])) {
        $activeTab = 'queue';
        if (!$auth->csrf_check()) {
            $logger->log('csrf token invalid for automation queue action', 2, echoToWeb: true);
            $executionResult = [
                'ok' => false,
                'output' => automation_t('automation_queue_action_csrf_invalid')
            ];
        } elseif (!$canAutomationExecute) {
            $executionResult = [
                'ok' => false,
                'output' => automation_t('automation_queue_action_no_permission')
            ];
        } else {
            $queueAction = (string)($_POST['queue_action'] ?? '');

            if ($queueAction === 'execute_all' && isset($_POST['execute_all_switch'])) {
                $switchToExecute = (string)($_POST['execute_all_switch']);
                if (isset($switches[$switchToExecute])) {
                    $switchData = resolveSwitchConnectionData($switches[$switchToExecute], $storedSettings);

                    $pendingBefore = $queueManager->getPendingChanges($_SESSION['uuid'], $switchToExecute);
                    $batchCommands = [];
                    foreach ($pendingBefore as $pendingEntry) {
                        $entryCommands = array_filter(
                            array_map('trim', explode("\n", (string)($pendingEntry['commands'] ?? ''))),
                            static fn($c): bool => $c !== ''
                        );
                        $batchCommands = array_merge($batchCommands, $entryCommands);
                    }

                    $executionResult = $queueManager->executeQueueForSwitch(
                        $_SESSION['uuid'],
                        $switchToExecute,
                        $switchData,
                        $logger
                    );

                    // Convert array summary to display format
                    $executionResult['ok'] = $executionResult['failed'] === 0;
                    $executionResult['output'] = automation_t('automation_queue_execution_header') . "\n"
                        . '- ' . automation_t('automation_status_total') . ': ' . $executionResult['total'] . "\n"
                        . '- ' . automation_t('automation_status_successful') . ': ' . $executionResult['completed'] . "\n"
                        . '- ' . automation_t('automation_status_failed') . ': ' . $executionResult['failed'] . "\n\n";

                    if (!empty($executionResult['details'])) {
                        $executionResult['output'] .= automation_t('automation_details_label') . ":\n";
                        foreach ($executionResult['details'] as $detail) {
                            $executionResult['output'] .= "- [{$detail['status']}] {$detail['uuid']}\n";
                        }
                        if (isset($executionResult['details'][0]['output'])) {
                            $executionResult['output'] .= "\n" . automation_t('automation_ssh_session_output_label') . ":\n" . $executionResult['details'][0]['output'];
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
                            'failed' => (int)($executionResult['failed'] ?? 0),
                            'device_uuid' => resolveSwitchDeviceUuid($switchData),
                            'script_content' => buildScriptContentForLog($batchCommands)
                        ]
                    );
                } else {
                    $executionResult = [
                        'ok' => false,
                        'output' => automation_t('automation_queue_execution_invalid_switch')
                    ];
                }
            } elseif ($queueAction === 'execute_one' && isset($_POST['pending_uuid'])) {
                $pendingUuid = (string)($_POST['pending_uuid']);
                $pendingChange = $queueManager->getPendingChange($pendingUuid, $_SESSION['uuid']);

                if (!is_array($pendingChange)) {
                    $executionResult = [
                        'ok' => false,
                        'output' => automation_t('automation_queue_entry_not_found_or_unauthorized')
                    ];
                } else {
                    $switchToExecute = (string)($pendingChange['switch_name'] ?? '');
                    if ($switchToExecute === '' || !isset($switches[$switchToExecute])) {
                        $executionResult = [
                            'ok' => false,
                            'output' => automation_t('automation_queue_entry_switch_invalid')
                        ];
                    } else {
                        $switchData = resolveSwitchConnectionData($switches[$switchToExecute], $storedSettings);

                        $commands = array_filter(
                            array_map('trim', explode("\n", (string)($pendingChange['commands'] ?? ''))),
                            static fn($c): bool => $c !== ''
                        );

                        if (empty($commands)) {
                            $executionResult = [
                                'ok' => false,
                                'output' => automation_t('automation_queue_entry_no_commands')
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
                                'output' => automation_t('automation_queue_single_executed', ['uuid' => $pendingUuid]) . "\n\n" . (string)($result['output'] ?? '')
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
                                    'pending_uuid' => $pendingUuid,
                                    'warning' => !empty($result['warning']),
                                    'device_uuid' => resolveSwitchDeviceUuid($switchData),
                                    'script_content' => buildScriptContentForLog($commands)
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
                        'output' => automation_t('automation_queue_none_selected')
                    ];
                } elseif ($switchToExecute === '' || !isset($switches[$switchToExecute])) {
                    $executionResult = [
                        'ok' => false,
                        'output' => automation_t('automation_queue_selected_invalid_switch')
                    ];
                } else {
                    $switchData = resolveSwitchConnectionData($switches[$switchToExecute], $storedSettings);

                    $executedCount = 0;
                    $failedCount = 0;
                    $resultLines = [];

                    foreach ($pendingUuids as $pendingUuid) {
                        $pendingChange = $queueManager->getPendingChange($pendingUuid, $_SESSION['uuid']);
                        if (!is_array($pendingChange)) {
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] ' . automation_t('automation_status_error_short') . ': ' . automation_t('automation_queue_entry_not_found_or_unauthorized');
                            continue;
                        }

                        if ((string)($pendingChange['status'] ?? '') !== 'pending') {
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] ' . automation_t('automation_status_error_short') . ': ' . automation_t('automation_queue_entry_not_pending');
                            continue;
                        }

                        $entrySwitch = (string)($pendingChange['switch_name'] ?? '');
                        if ($entrySwitch !== $switchToExecute) {
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] ' . automation_t('automation_status_error_short') . ': ' . automation_t('automation_queue_entry_other_switch', ['switch' => $entrySwitch]);
                            continue;
                        }

                        $commands = array_filter(
                            array_map('trim', explode("\n", (string)($pendingChange['commands'] ?? ''))),
                            static fn($c): bool => $c !== ''
                        );

                        if (empty($commands)) {
                            $queueManager->updatePendingChange($pendingUuid, 'failed', automation_t('automation_queue_entry_contains_no_commands'));
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] ' . automation_t('automation_status_error_short') . ': ' . automation_t('automation_queue_entry_contains_no_commands');
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
                            $resultLines[] = '[' . $pendingUuid . '] ' . automation_t('ok');
                        } else {
                            $failedCount++;
                            $resultLines[] = '[' . $pendingUuid . '] ' . automation_t('automation_status_error_short');
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
                                'pending_uuid' => $pendingUuid,
                                'warning' => !empty($result['warning']),
                                'device_uuid' => resolveSwitchDeviceUuid($switchData),
                                'script_content' => buildScriptContentForLog($commands)
                            ]
                        );
                    }

                    $executionResult = [
                        'ok' => ($failedCount === 0),
                        'output' => automation_t('automation_queue_selected_execution_completed') . "\n"
                            . '- ' . automation_t('automation_status_selected') . ': ' . count($pendingUuids) . "\n"
                            . '- ' . automation_t('automation_status_successful') . ': ' . $executedCount . "\n"
                            . '- ' . automation_t('automation_status_failed') . ': ' . $failedCount . "\n\n"
                            . implode("\n", $resultLines)
                    ];
                }
            } elseif ($queueAction === 'delete' && isset($_POST['pending_uuid'])) {
                $pendingUuid = (string)($_POST['pending_uuid']);
                $success = $queueManager->deletePendingChange($pendingUuid, $_SESSION['uuid']);
                $executionResult = [
                    'ok' => $success,
                    'output' => $success ? automation_t('automation_queue_change_removed') : automation_t('automation_queue_delete_error')
                ];
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute'])) {
        $queueMode = isset($_POST['queue_mode']) && $_POST['queue_mode'] === 'on';
        $requiredPermission = $queueMode ? 'write' : 'execute';
        $hasRequiredPermission = $queueMode ? $canAutomationWrite : $canAutomationExecute;

        if (!$auth->csrf_check()) {
            $logger->log('csrf token invalid for automation execute', 2, echoToWeb: true);
            $executionResult = [
                'ok' => false,
                'output' => $queueMode
                    ? automation_t('automation_queue_csrf_invalid')
                    : automation_t('automation_execute_csrf_invalid')
            ];
        } elseif (!$hasRequiredPermission) {
            $logger->log('user denied access to automation ' . $requiredPermission, 2, echoToWeb: true);
            $executionResult = [
                'ok' => false,
                'output' => $queueMode
                    ? automation_t('automation_queue_no_write_permission')
                    : automation_t('automation_execute_no_execute_permission')
            ];
        } else {
            $selectedSwitch = (string)($_POST['switch'] ?? $selectedSwitch);
            $selectedProfile = (string)($_POST['profile'] ?? $selectedProfile);
            $selectedTemplate = (string)($_POST['template'] ?? $selectedTemplate);
            $selectedSaveMode = (string)($_POST['save_mode'] ?? $selectedSaveMode);
            $selectedErrorStrategy = (string)($_POST['error_strategy'] ?? $selectedErrorStrategy);
            $batchInterfacesInput = (string)($_POST['batch_interfaces'] ?? $batchInterfacesInput);
            $pipelineTemplatesInput = '';

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
                $rendered['warnings'][] = automation_t('automation_warning_save_skipped');
            }

            if (in_array($selectedErrorStrategy, ['stop_on_error', 'stop_with_rollback'], true) && count($pipelineGroups) > 1) {
                $rendered['warnings'][] = automation_t('automation_warning_error_strategy_active', ['strategy' => $selectedErrorStrategy]);
            }

            if ($queueMode) {
                if (!is_array($selectedSwitchData)) {
                    $executionResult = [
                        'ok' => false,
                        'output' => automation_t('automation_queue_select_switch_first')
                    ];
                } elseif (empty($rendered['commands'])) {
                    $executionResult = [
                        'ok' => false,
                        'output' => automation_t('automation_queue_no_commands_to_save')
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
                        'output' => automation_t('automation_queue_added', ['uuid' => $queueUuid])
                    ];
                    $activeTab = 'queue';
                }
            } elseif (is_array($selectedSwitchData)) {
                // Execute immediately
                $selectedSwitchData = resolveSwitchConnectionData($selectedSwitchData, $storedSettings);

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
                        'save_mode' => $selectedSaveMode,
                        'warning' => !empty($executionResult['warning']),
                        'device_uuid' => resolveSwitchDeviceUuid($selectedSwitchData),
                        'script_content' => buildScriptContentForLog($rendered['commands'] ?? [])
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
                    'output' => automation_t('automation_execution_no_valid_switch')
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
            $rendered['warnings'][] = automation_t('automation_preview_warning_save_skipped');
        }
        if ($selectedErrorStrategy === 'stop_on_error' && count($previewPipelineGroups) > 1) {
            $rendered['warnings'][] = automation_t('automation_preview_warning_stop_on_error');
        }
        if ($selectedErrorStrategy === 'stop_with_rollback' && count($previewPipelineGroups) > 1) {
            $rendered['warnings'][] = automation_t('automation_preview_warning_stop_with_rollback');
        }
    }

    $executionHasWarnings = !empty($executionResult['warning']);
    if (!$executionHasWarnings && is_array($executionResult) && isset($executionResult['output'])) {
        $executionHasWarnings = stripos((string)$executionResult['output'], automation_t('warning')) !== false;
    }

    if (!empty($executionResult['ok'])) {
        $executionStateClass = 'bg-green-50 border-green-200 text-green-900';
    } elseif ($executionHasWarnings) {
        $executionStateClass = 'bg-amber-50 border-amber-200 text-amber-900';
    } else {
        $executionStateClass = 'bg-red-50 border-red-200 text-red-900';
    }

    function runAutomationSshCommands(array $connection, array $commands, Logger $logger, string $switchName, string $profileId, string $templateId): array {
        $host = trim((string)($connection['mgmt_ip'] ?? ''));
        $port = (int)($connection['ssh_port'] ?? 22);
        $authMethod = trim((string)($connection['ssh_auth_method'] ?? 'password'));
        $username = trim((string)($connection['ssh_username'] ?? ''));
        $password = (string)($connection['ssh_password'] ?? '');
        $privateKey = (string)($connection['ssh_private_key'] ?? '');

        if (!in_array($authMethod, ['password', 'key'], true)) {
            $authMethod = $privateKey !== '' ? 'key' : 'password';
        }

        if ($host === '' || $username === '') {
            return [
                'ok' => false,
                'output' => automation_t('automation_error_missing_host_username')
            ];
        }

        $sshPath = trim((string)shell_exec('command -v ssh 2>/dev/null'));
        if ($sshPath === '') {
            return [
                'ok' => false,
                'output' => automation_t('automation_error_ssh_not_found')
            ];
        }

        $sshpassPath = trim((string)shell_exec('command -v sshpass 2>/dev/null'));
        $timeoutPath = trim((string)shell_exec('command -v timeout 2>/dev/null'));
        $keyFile = null;

        $knownHostsDir = __DIR__ . '/data/automation';
        if (!is_dir($knownHostsDir) && !mkdir($knownHostsDir, 0700, true) && !is_dir($knownHostsDir)) {
            return [
                'ok' => false,
                'output' => automation_t('automation_error_known_hosts_dir')
            ];
        }

        $knownHostsFile = $knownHostsDir . '/known_hosts';
        if (!file_exists($knownHostsFile) && @touch($knownHostsFile) === false) {
            return [
                'ok' => false,
                'output' => automation_t('automation_error_known_hosts_file')
            ];
        }

        @chmod($knownHostsFile, 0600);

        $commandFile = tempnam(sys_get_temp_dir(), 'portflow-automation-');
        if ($commandFile === false) {
            return [
                'ok' => false,
                'output' => automation_t('automation_error_temp_file')
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

        $sshOptions = '-F /dev/null -tt -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . escapeshellarg($knownHostsFile) . ' -o ConnectTimeout=8';
        if ($authMethod === 'password' && $password !== '') {
            $sshOptions .= ' -o PreferredAuthentications=password -o PubkeyAuthentication=no';
        } else {
            $sshOptions .= ' -o BatchMode=yes';
        }

        if ($authMethod === 'key') {
            if (trim($privateKey) === '') {
                @unlink($commandFile);
                return [
                    'ok' => false,
                    'output' => automation_t('automation_error_empty_ssh_key')
                ];
            }

            $keyFile = tempnam(sys_get_temp_dir(), 'portflow-key-');
            if ($keyFile === false) {
                @unlink($commandFile);
                return [
                    'ok' => false,
                    'output' => automation_t('automation_error_temp_key_file')
                ];
            }

            file_put_contents($keyFile, rtrim($privateKey) . "\n");
            @chmod($keyFile, 0600);
            $sshOptions .= ' -o PreferredAuthentications=publickey -o PasswordAuthentication=no -i ' . escapeshellarg($keyFile);
        }

        $target = escapeshellarg($username . '@' . $host);
        $sshCommand = $sshPath . ' ' . $sshOptions . ' -p ' . (int)$port . ' ' . $target . ' < ' . escapeshellarg($commandFile);

        if ($authMethod === 'password' && $password !== '') {
            if ($sshpassPath === '') {
                @unlink($commandFile);
                if ($keyFile !== null) {
                    @unlink($keyFile);
                }
                return [
                    'ok' => false,
                    'output' => automation_t('automation_error_sshpass_not_found')
                ];
            }

            putenv('SSHPASS=' . $password);
            $sshCommand = $sshpassPath . ' -e ' . $sshCommand;
        }

        $fullCommand = $sshCommand;
        if ($timeoutPath !== '') {
            $fullCommand = $timeoutPath . ' 45s ' . $fullCommand;
        }

        $lines = [];
        $exitCode = 1;
        $startedAt = microtime(true);
        exec($fullCommand . ' 2>&1', $lines, $exitCode);
        if ($authMethod === 'password' && $password !== '') {
            putenv('SSHPASS');
        }
        $durationSec = microtime(true) - $startedAt;
        @unlink($commandFile);
        if ($keyFile !== null) {
            @unlink($keyFile);
        }

        $maxLines = 120;
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[] = automation_t('automation_output_truncated');
        }

        $maskedCommand = ($authMethod === 'password' && $password !== '')
            ? 'sshpass -e ssh ...'
            : trim((string)$fullCommand);

        $hasErrorSignals = outputHasStrongErrorSignals($lines);
        $looksLikeSessionTermination = outputLooksLikeSessionTermination($lines);
        $looksLikeCleanDisconnect = outputLooksLikeCleanDisconnect($lines);

        $warning = false;
        $ok = ($exitCode === 0);

        if (!$ok && !$hasErrorSignals && !empty($commands)) {
            if ($exitCode === 255 && $looksLikeCleanDisconnect) {
                $ok = true;
                $warning = false;
            } elseif ($exitCode === 124 || $looksLikeSessionTermination || $exitCode === 255 || $exitCode === 1) {
                $ok = true;
                $warning = true;
            }
        }

        $reportedCommands = buildCommandStatusLines($commands, $lines, $exitCode);

        $outputText = automation_t('automation_output_command_label') . ': ' . $maskedCommand . "\n";
        $outputText .= automation_t('automation_output_exit_code_label') . ': ' . $exitCode . "\n";
        $outputText .= automation_t('automation_output_duration_label') . ': ' . number_format($durationSec, 2, '.', '') . "s\n\n";
        if (!empty($reportedCommands)) {
            $outputText .= automation_t('automation_output_command_status_label') . ":\n" . implode("\n", $reportedCommands) . "\n\n";
        }
        $outputText .= implode("\n", $lines);

        if ($exitCode === 124) {
            $outputText .= "\n\n" . automation_t('automation_output_timeout_note');
        }

        if ($warning) {
            $outputText .= "\n\n" . automation_t('automation_output_warning_assessment', ['exit_code' => $exitCode]);
        }

        $logLevel = $ok ? ($warning ? 2 : 1) : 3;

        $logger->log(
            'automation execute switch=' . $switchName
                . ' profile=' . $profileId
                . ' template=' . $templateId
                . ' exit=' . $exitCode
                . ' duration=' . number_format($durationSec, 2, '.', '') . 's',
            $logLevel
        );

        return [
            'ok' => $ok,
            'warning' => $warning,
            'exit_code' => $exitCode,
            'output' => $outputText
        ];
    }

    function outputHasStrongErrorSignals(array $outputLines): bool {
        $errorPatterns = [
            '/\\berror\\b/i',
            '/\\bfailed\\b/i',
            '/\\binvalid\\b/i',
            '/\\bincomplete\\b/i',
            '/\\bambiguous\\b/i',
            '/\\bunrecognized\\b/i',
            '/\\bdenied\\b/i',
            '/\\bsyntax error\\b/i',
            '/\\bpermission denied\\b/i'
        ];

        foreach ($outputLines as $line) {
            $lineText = (string)$line;
            foreach ($errorPatterns as $pattern) {
                if (preg_match($pattern, $lineText)) {
                    return true;
                }
            }
        }

        return false;
    }

    function outputLooksLikeSessionTermination(array $outputLines): bool {
        $terminationPatterns = [
            '/connection to .* closed/i',
            '/session closed/i',
            '/connection reset/i',
            '/broken pipe/i',
            '/connection closed by remote host/i'
        ];

        foreach ($outputLines as $line) {
            $lineText = (string)$line;
            foreach ($terminationPatterns as $pattern) {
                if (preg_match($pattern, $lineText)) {
                    return true;
                }
            }
        }

        return false;
    }

    function outputLooksLikeCleanDisconnect(array $outputLines): bool {
        $sawQuitCommand = false;
        $sawConnectionClosed = false;
        $sawAbruptSignals = false;

        foreach ($outputLines as $line) {
            $lineText = (string)$line;

            if (preg_match('/>\s*quit\s*$/i', $lineText)) {
                $sawQuitCommand = true;
            }

            if (preg_match('/connection to .* closed\.?/i', $lineText)) {
                $sawConnectionClosed = true;
            }

            if (preg_match('/broken pipe|connection reset|timed out|timeout/i', $lineText)) {
                $sawAbruptSignals = true;
            }
        }

        return $sawQuitCommand && $sawConnectionClosed && !$sawAbruptSignals;
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

            $status = automation_t('automation_command_status_sent');
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
                $status = automation_t('automation_command_status_error_maybe');
            } elseif ($exitCode === 0 && empty($errorLines)) {
                $status = automation_t('ok');
            } elseif ($exitCode === 0) {
                $status = automation_t('automation_command_status_ok_maybe');
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
                $reportLines[] = '[' . $templateId . '] ' . automation_t('automation_command_status_skipped') . ' (' . automation_t('automation_queue_entry_contains_no_commands') . ')';
                continue;
            }

            $result = runAutomationSshCommands($connection, $commands, $logger, $switchName, $profileId, $templateId);
            $logger->log('stop_with_rollback step template=' . $templateId . ' ok=' . (!empty($result['ok']) ? '1' : '0'), !empty($result['ok']) ? 0 : 2);
            $statusText = !empty($result['ok']) ? automation_t('ok') : automation_t('automation_status_failed_short');
            $reportLines[] = '[' . $templateId . '] ' . $statusText;

            if (isset($result['output'])) {
                $reportLines[] = '--- ' . automation_t('automation_report_output_label') . ' ' . $templateId . ' ---';
                $reportLines[] = (string)$result['output'];
            }

            if (empty($result['ok'])) {
                $overallOk = false;
                $reportLines[] = automation_t('automation_report_abort_stop_on_error');
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
                $reportLines[] = '[' . $templateId . '] ' . automation_t('automation_command_status_skipped') . ' (' . automation_t('automation_queue_entry_contains_no_commands') . ')';
                continue;
            }

            $result = runAutomationSshCommands($connection, $commands, $logger, $switchName, $profileId, $templateId);
            $statusText = !empty($result['ok']) ? automation_t('ok') : automation_t('automation_status_failed_short');
            $reportLines[] = '[' . $templateId . '] ' . $statusText;

            if (isset($result['output'])) {
                $reportLines[] = '--- ' . automation_t('automation_report_output_label') . ' ' . $templateId . ' ---';
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
            $reportLines[] = automation_t('automation_report_abort_stop_with_rollback');

            if (!empty($executed)) {
                $reportLines[] = automation_t('automation_report_rollback_started');
            }

            for ($r = count($executed) - 1; $r >= 0; $r--) {
                $rollbackTemplateId = (string)($executed[$r]['template_id'] ?? ('template_' . ($r + 1)));
                $rollbackCommands = array_filter(
                    array_map('trim', (array)($executed[$r]['rollback_commands'] ?? [])),
                    static fn($c): bool => $c !== ''
                );

                if (empty($rollbackCommands)) {
                    $reportLines[] = '[' . automation_t('automation_report_rollback_label') . ' ' . $rollbackTemplateId . '] ' . automation_t('automation_command_status_skipped') . ' (' . automation_t('automation_report_no_rollback_commands') . ')';
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

                $rollbackStatus = !empty($rollbackResult['ok']) ? automation_t('ok') : automation_t('automation_status_failed_short');
                $reportLines[] = '[' . automation_t('automation_report_rollback_label') . ' ' . $rollbackTemplateId . '] ' . $rollbackStatus;

                if (isset($rollbackResult['output'])) {
                    $reportLines[] = '--- ' . automation_t('automation_report_output_label') . ' ' . automation_t('automation_report_rollback_label') . ' ' . $rollbackTemplateId . ' ---';
                    $reportLines[] = (string)$rollbackResult['output'];
                }

                if (empty($rollbackResult['ok'])) {
                    $reportLines[] = automation_t('automation_report_rollback_error', ['template' => $rollbackTemplateId]);
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
            $warnings[] = automation_t('automation_pipeline_active', ['count' => count($templateSequence)]);
        }

        foreach ($templateSequence as $templateId) {
            if (!isset($templates[$templateId])) {
                $warnings[] = automation_t('automation_pipeline_template_missing', ['template' => $templateId]);
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
                    $warnings[] = automation_t('automation_batch_missing_interface_variable', ['template' => $templateId]);
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
            $warnings[] = automation_t('automation_batch_active', ['count' => count($batchInterfaces)]);
        }

        return $commands;
    }

    function automation_escape($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    function buildScriptContentForLog(array $commands, int $maxChars = 8000): string {
        $normalized = array_values(array_filter(array_map(static function ($command): string {
            return trim((string)$command);
        }, $commands), static function (string $command): bool {
            return $command !== '';
        }));

        if (empty($normalized)) {
            return '';
        }

        $joined = implode("\n", $normalized);
        if (mb_strlen($joined) > $maxChars) {
            return mb_substr($joined, 0, $maxChars) . "\n... truncated ...";
        }

        return $joined;
    }

    function resolveSwitchDeviceUuid(array $switchData): string {
        $candidates = [
            $switchData['device_id'] ?? '',
            $switchData['device_uuid'] ?? '',
            $switchData['uuid'] ?? ''
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
<style>
    .automation-shell {
        margin: 0.75rem 1rem 1rem;
        margin-top: 0;
        display: grid;
        gap: 0.95rem;
        grid-template-columns: 1fr;
    }

    .automation-sidebar {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 1rem;
        padding: 0.9rem;
        overflow-y: auto;
        min-height: 0;
    }

    .automation-sidebar-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.6rem;
        margin-bottom: 0.85rem;
    }

    .automation-side-title {
        font-size: 1rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }

    .automation-side-nav {
        display: grid;
        gap: 0.55rem;
    }

    .automation-side-item {
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #1e293b;
        border-radius: 9999px;
        padding: 0.62rem 0.85rem;
        cursor: pointer;
        transition: 140ms ease;
        font-weight: 600;
        white-space: nowrap;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.7rem;
    }

    .automation-side-item-main {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        min-width: 0;
    }

    .automation-side-item-main i[data-lucide],
    .automation-side-chevron i[data-lucide] {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .automation-side-item:hover {
        background: #f1f5f9;
    }

    .automation-side-item-active {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
    }

    .automation-mobile-subnav {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        overflow-x: auto;
        padding-bottom: 0.25rem;
        margin-bottom: 0.85rem;
    }

    .automation-mobile-subnav .automation-side-item {
        flex: 0 0 auto;
    }

    .automation-mobile-subnav .automation-side-chevron {
        display: none;
    }

    .automation-content {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 1rem;
        padding: 1.1rem;
        overflow-y: auto;
        min-height: 0;
        font-size: 1rem;
    }

    .automation-top-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-bottom: 0.8rem;
    }

    .automation-top-btn {
        border-radius: 9999px;
        font-weight: 600;
        padding: 0.5rem 1rem;
        color: #ffffff;
    }

    .automation-top-btn-run {
        background: #22c55e;
    }

    .automation-top-btn-run:hover {
        background: #15803d;
    }

    .automation-top-btn-queue {
        background: #3b82f6;
    }

    .automation-top-btn-queue:hover {
        background: #2563eb;
    }

    .automation-main-card {
        border: 1px solid #cbd5e1;
        border-radius: 1rem;
        background: #f8fafc;
        padding: 0.95rem;
    }

    .automation-main-card-dark {
        border: 1px solid #0f172a;
        border-radius: 1rem;
        background: #0f172a;
        color: #e2e8f0;
        padding: 0.95rem;
    }

    .automation-main-card-result {
        border: 1px solid #cbd5e1;
        border-radius: 1rem;
        background: #f8fafc;
        padding: 0.95rem;
    }

    .automation-loading-box {
        border: 1px solid #cbd5e1;
        border-radius: 1rem;
        background: #f8fafc;
        color: #334155;
        padding: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        font-size: 0.9rem;
    }

    .automation-loading-box.hidden {
        display: none !important;
    }

    .automation-loading-dot {
        width: 1rem;
        height: 1rem;
        border-radius: 9999px;
        border: 2px solid #93c5fd;
        border-top-color: #2563eb;
        animation: automation-spin 0.8s linear infinite;
    }

    @keyframes automation-spin {
        to {
            transform: rotate(360deg);
        }
    }

    .queue-action-btn {
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.875rem;
        padding: 0.45rem 0.9rem;
    }

    .queue-item-btn {
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.8125rem;
        padding: 0.35rem 0.75rem;
    }

    @media (min-width: 1024px) {
        .automation-shell {
            grid-template-columns: minmax(250px, 22rem) minmax(0, 1fr);
            height: calc(100vh - 7.2rem);
            align-items: stretch;
        }

        .automation-mobile-subnav {
            display: none;
        }
    }

    @media (max-width: 1023px) {
        .automation-sidebar {
            display: none;
        }
    }
</style>
<div class="automation-shell">
    <div class="automation-sidebar flex flex-col gap-4">
        <div class="automation-sidebar-head">
            <p class="automation-side-title"><?php echo automation_escape(automation_t('automation_sidebar_title')); ?></p>
        </div>
        <ul class="automation-side-nav">
            <li class="automation-side-item automation-side-item-active automation-tab" data-tab="automation">
                <span class="automation-side-item-main"><i data-lucide="bot"></i><span><?php echo automation_escape(automation_t('automation_tab_automation')); ?></span></span>
                <span class="automation-side-chevron"><i data-lucide="chevron-right"></i></span>
            </li>
            <li class="automation-side-item automation-tab" data-tab="queue">
                <span class="automation-side-item-main"><i data-lucide="inbox"></i><span><?php echo automation_escape(automation_t('automation_tab_queue')); ?></span></span>
                <span class="automation-side-chevron"><i data-lucide="chevron-right"></i></span>
            </li>
        </ul>
        </div>

    <div class="automation-content">
        <ul class="automation-mobile-subnav">
            <li class="automation-side-item automation-side-item-active automation-tab" data-tab="automation"><span class="automation-side-item-main"><i data-lucide="bot"></i><span><?php echo automation_escape(automation_t('automation_tab_automation')); ?></span></span></li>
            <li class="automation-side-item automation-tab" data-tab="queue"><span class="automation-side-item-main"><i data-lucide="inbox"></i><span><?php echo automation_escape(automation_t('automation_tab_queue')); ?></span></span></li>
        </ul>
        
        <!-- Automation Tab -->
        <div id="automation-content" class="tab-content">
            <div class="flex justify-between items-start gap-6 pb-6">
                <div>
                    <div class="text-2xl font-bold"><?php echo automation_escape(automation_t('automation_preview_title')); ?></div>
                    <div class="text-sm text-gray-600"><?php echo automation_escape(automation_t('automation_preview_subtitle')); ?></div>
                </div>
                <div class="text-sm text-gray-500 max-w-xl text-right">
                    <?php echo automation_escape(automation_t('automation_preview_hint')); ?>
                </div>
            </div>

        <div class="automation-top-actions">
            <button type="button" class="automation-top-btn automation-top-btn-run" onclick="triggerAutomationExecution('off')"><?php echo automation_escape(automation_t('automation_run_now')); ?></button>
            <button type="button" class="automation-top-btn automation-top-btn-queue" onclick="triggerAutomationExecution('on')"><?php echo automation_escape(automation_t('automation_add_to_queue')); ?></button>
        </div>

        <form id="automation-preview-form" class="grid grid-cols-1 lg:grid-cols-2 gap-6 pb-8" method="GET" action="automation.php">
            <div class="space-y-4 automation-main-card">
                <div>
                    <label class="block text-sm font-semibold mb-2" for="switch"><?php echo automation_escape(automation_t('automation_switch_target_label')); ?></label>
                    <select id="switch" name="switch" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <option value=""><?php echo automation_escape(automation_t('automation_no_target_selected')); ?></option>
                        <?php foreach ($switches as $switchName => $switchData) : ?>
                            <option value="<?php echo automation_escape($switchName); ?>" <?php echo $switchName === $selectedSwitch ? 'selected' : ''; ?> data-profile="<?php echo automation_escape($switchData['profile'] ?? ''); ?>"><?php echo automation_escape($switchName); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (is_array($selectedSwitchData)) : ?>
                        <div class="text-xs text-gray-600 mt-2">
                            <?php echo automation_escape(automation_t('automation_management_ip_label')); ?>: <span class="font-semibold"><?php echo automation_escape($selectedSwitchData['mgmt_ip'] ?? ''); ?></span>
                            <?php if (!empty($selectedSwitchData['profile'])) : ?>
                                | <?php echo automation_escape(automation_t('profile_label')); ?>: <span class="font-semibold"><?php echo automation_escape($selectedSwitchData['profile']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="profile"><?php echo automation_escape(automation_t('automation_switch_profile_label')); ?></label>
                    <select id="profile" name="profile" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <?php foreach ($profiles as $profileKey => $profile) : ?>
                            <option value="<?php echo automation_escape($profileKey); ?>" <?php echo $profileKey === $selectedProfile ? 'selected' : ''; ?>><?php echo automation_escape($profile['label'] ?? $profileKey); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="template"><?php echo automation_escape(automation_t('automation_script_template_label')); ?></label>
                    <select id="template" name="template" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <?php foreach ($templates as $templateKey => $template) : ?>
                            <option value="<?php echo automation_escape($templateKey); ?>" <?php echo $templateKey === $selectedTemplate ? 'selected' : ''; ?>><?php echo automation_escape($template['label'] ?? $templateKey); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="save_mode"><?php echo automation_escape(automation_t('automation_save_strategy_label')); ?></label>
                    <select id="save_mode" name="save_mode" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <option value="immediate" <?php echo $selectedSaveMode === 'immediate' ? 'selected' : ''; ?>><?php echo automation_escape(automation_t('automation_save_mode_immediate')); ?></option>
                        <option value="skip_save" <?php echo $selectedSaveMode === 'skip_save' ? 'selected' : ''; ?>><?php echo automation_escape(automation_t('automation_save_mode_skip')); ?></option>
                    </select>
                    <div class="text-xs text-gray-600 mt-2"><?php echo automation_escape(automation_t('automation_save_mode_hint')); ?></div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="error_strategy"><?php echo automation_escape(automation_t('automation_error_strategy_label')); ?></label>
                    <select id="error_strategy" name="error_strategy" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white">
                        <option value="continue_report" <?php echo $selectedErrorStrategy === 'continue_report' ? 'selected' : ''; ?>><?php echo automation_escape(automation_t('automation_error_strategy_continue_report')); ?></option>
                        <option value="stop_on_error" <?php echo $selectedErrorStrategy === 'stop_on_error' ? 'selected' : ''; ?>><?php echo automation_escape(automation_t('automation_error_strategy_stop_on_error')); ?></option>
                        <option value="stop_with_rollback" <?php echo $selectedErrorStrategy === 'stop_with_rollback' ? 'selected' : ''; ?>><?php echo automation_escape(automation_t('automation_error_strategy_stop_with_rollback')); ?></option>
                    </select>
                    <div class="text-xs text-gray-600 mt-2"><?php echo automation_escape(automation_t('automation_error_strategy_hint')); ?></div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-2" for="batch_interfaces"><?php echo automation_escape(automation_t('automation_batch_interfaces_label')); ?></label>
                    <textarea id="batch_interfaces" name="batch_interfaces" rows="4" class="w-full rounded-xl border border-gray-300 px-3 py-2 bg-white font-mono text-sm" placeholder="<?php echo automation_escape(automation_t('automation_batch_interfaces_placeholder')); ?>"><?php echo automation_escape($batchInterfacesInput); ?></textarea>
                    <div class="text-xs text-gray-600 mt-2"><?php echo automation_escape(automation_t('automation_batch_interfaces_hint')); ?></div>
                </div>

                <div>
                    <div class="text-sm font-semibold mb-2"><?php echo automation_escape(automation_t('automation_variables_label')); ?></div>
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
                        <button type="submit" class="px-5 py-2 rounded-full bg-blue-500 hover:bg-blue-700 text-white font-semibold"><?php echo automation_escape(automation_t('preview_alt')); ?></button>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="automation-main-card">
                    <div class="text-sm font-semibold pb-2"><?php echo automation_escape(automation_t('automation_template_description_label')); ?></div>
                    <div class="text-sm text-gray-700"><?php echo automation_escape($templateDefinition['description'] ?? ''); ?></div>
                    <?php if (!empty($rendered['warnings'])) : ?>
                        <div class="mt-3 space-y-2">
                            <?php foreach ($rendered['warnings'] as $warning) : ?>
                                <div class="rounded-xl bg-yellow-100 text-yellow-900 px-3 py-2 text-sm"><?php echo automation_escape($warning); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="automation-main-card-dark shadow-inner">
                    <div class="text-sm font-semibold pb-3"><?php echo automation_escape(automation_t('automation_rendered_sequence_label')); ?></div>
                    <pre class="text-sm whitespace-pre-wrap overflow-x-auto leading-6"><?php echo automation_escape(implode("\n", $rendered['commands'] ?? [])); ?></pre>
                </div>

                <div id="automationExecutionLoading" class="automation-loading-box hidden">
                    <span class="automation-loading-dot"></span>
                    <span><?php echo automation_escape(automation_t('automation_loading_execution')); ?></span>
                </div>

                <?php if (is_array($executionResult) && isset($executionResult['output'])) : ?>
                    <div class="automation-main-card-result <?php echo $executionStateClass; ?>">
                        <div class="text-sm font-semibold pb-2"><?php echo automation_escape(automation_t('automation_execution_output_label')); ?></div>
                        <pre class="text-sm whitespace-pre-wrap leading-6"><?php echo automation_escape($executionResult['output']); ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <div class="mt-4 bg-white rounded-2xl border border-gray-200 p-4 shadow-sm hidden">
            <div class="flex items-center justify-between gap-4 pb-4">
                <div>
                    <div class="text-lg font-bold"><?php echo automation_escape(automation_t('automation_execute_title')); ?></div>
                    <div class="text-sm text-gray-600"><?php echo automation_escape(automation_t('automation_execute_subtitle')); ?></div>
                </div>
            </div>
            <form id="automation-execute-form" method="POST" action="automation.php" onsubmit="return syncExecutionFormValues();">
                <input type="hidden" name="execute" value="1">
                <input type="hidden" name="csrf" value="<?php echo automation_escape((string)$csrf); ?>">
                <input type="hidden" name="switch" value="<?php echo automation_escape($selectedSwitch); ?>">
                <input type="hidden" name="profile" value="<?php echo automation_escape($selectedProfile); ?>">
                <input type="hidden" name="template" value="<?php echo automation_escape($selectedTemplate); ?>">
                <input type="hidden" name="save_mode" value="<?php echo automation_escape($selectedSaveMode); ?>">
                <input type="hidden" name="error_strategy" value="<?php echo automation_escape($selectedErrorStrategy); ?>">
                <input type="hidden" name="batch_interfaces" value="<?php echo automation_escape($batchInterfacesInput); ?>">
                <?php foreach ($variableValues as $variableName => $variableValue) : ?>
                    <input type="hidden" name="<?php echo automation_escape($variableName); ?>" value="<?php echo automation_escape($variableValue); ?>">
                <?php endforeach; ?>
                <div class="hidden justify-end gap-2">
                    <button type="submit" name="queue_mode" value="off" class="px-5 py-2 rounded-full bg-green-500 hover:bg-green-700 text-white font-semibold"><?php echo automation_escape(automation_t('automation_run_now')); ?></button>
                    <button type="submit" name="queue_mode" value="on" class="px-5 py-2 rounded-full bg-blue-500 hover:bg-blue-700 text-white font-semibold"><?php echo automation_escape(automation_t('automation_add_to_queue')); ?></button>
                </div>
            </form>
        </div>

        </div>
        
        <!-- Queue Tab -->
        <div id="queue-content" class="tab-content hidden">
            <div class="flex justify-between items-start gap-6 pb-6">
                <div>
                    <div class="text-2xl font-bold"><?php echo automation_escape(automation_t('automation_queue_title')); ?></div>
                    <div class="text-sm text-gray-600"><?php echo automation_escape(automation_t('automation_queue_subtitle')); ?></div>
                </div>
            </div>
            
            <?php
                $userPendingSummary = $queueManager->getPendingSummary($_SESSION['uuid'] ?? '');
                if (empty($userPendingSummary)) {
                    echo '<div class="rounded-xl bg-blue-50 border border-blue-200 text-blue-900 px-4 py-3">' . automation_escape(automation_t('automation_queue_empty')) . '</div>';
                } else {
                    foreach ($userPendingSummary as $switchName => $count) {
                        $queueGroupId = 'queue-group-' . md5((string)$switchName);
                        echo '<div class="bg-gray-50 rounded-xl p-4 mb-4">';
                        echo '<div class="flex justify-between items-center pb-4">';
                        echo '<div>';
                        echo '<div class="text-lg font-bold">' . automation_escape($switchName) . '</div>';
                        echo '<div class="text-sm text-gray-600">' . automation_escape(automation_count_label((int)$count, 'automation_queue_pending_change_singular', 'automation_queue_pending_change_plural')) . '</div>';
                        echo '</div>';
                        echo '<div class="flex items-center gap-2">';
                        echo '<form method="POST" action="automation.php" style="display: inline;" id="' . automation_escape($queueGroupId) . '-selected" onsubmit="return ensureQueueSelection(\'' . automation_escape($queueGroupId) . '\');">';
                        echo '<input type="hidden" name="csrf" value="' . automation_escape((string)$csrf) . '">';
                        echo '<input type="hidden" name="queue_action" value="execute_selected">';
                        echo '<input type="hidden" name="execute_selected_switch" value="' . automation_escape($switchName) . '">';
                        echo '<button type="submit" class="queue-action-btn bg-emerald-500 hover:bg-emerald-700 text-white">' . automation_escape(automation_t('automation_queue_execute_selection')) . '</button>';
                        echo '</form>';
                        echo '<form method="POST" action="automation.php" style="display: inline;">';
                        echo '<input type="hidden" name="csrf" value="' . automation_escape((string)$csrf) . '">';
                        echo '<input type="hidden" name="queue_action" value="execute_all">';
                        echo '<input type="hidden" name="execute_all_switch" value="' . automation_escape($switchName) . '">';
                        echo '<button type="submit" class="queue-action-btn bg-green-500 hover:bg-green-700 text-white">' . automation_escape(automation_t('automation_queue_execute_all')) . '</button>';
                        echo '</form>';
                        echo '</div>';
                        echo '</div>';
                        echo '<div class="pb-3 text-sm text-gray-700 flex items-center gap-2">';
                        echo '<input type="checkbox" id="' . automation_escape($queueGroupId) . '-all" onchange="toggleQueueGroup(\'' . automation_escape($queueGroupId) . '\', this.checked)">';
                        echo '<label for="' . automation_escape($queueGroupId) . '-all">' . automation_escape(automation_t('automation_queue_select_all_group')) . '</label>';
                        echo '</div>';
                        
                        $pendingChanges = $queueManager->getPendingChanges($_SESSION['uuid'] ?? '', $switchName);
                        echo '<div class="space-y-2">';
                        foreach ($pendingChanges as $change) {
                            echo '<div class="bg-white border border-gray-200 rounded-lg p-3 text-sm">';
                            echo '<div class="flex justify-between items-start gap-3">';
                            echo '<div>';
                            echo '<div class="pb-2">';
                            echo '<input type="checkbox" name="pending_uuids[]" value="' . automation_escape($change['uuid']) . '" form="' . automation_escape($queueGroupId) . '-selected" data-queue-group="' . automation_escape($queueGroupId) . '">';
                            echo '<span class="ml-2 text-xs text-gray-600">' . automation_escape(automation_t('automation_queue_mark_for_bulk')) . '</span>';
                            echo '</div>';
                            echo '<div class="font-semibold">' . automation_escape($change['profile_id']) . ' → ' . automation_escape($change['template_id']) . '</div>';
                            echo '<div class="text-xs text-gray-500 mt-1">' . (new DateTime($change['created']))->format('Y-m-d H:i:s') . '</div>';
                            echo '<div class="text-xs text-gray-600 mt-2 font-mono bg-gray-100 p-2 rounded max-h-40 overflow-y-auto whitespace-pre-wrap">' . automation_escape((string)($change['commands'] ?? '')) . '</div>';
                            echo '</div>';
                            echo '<div class="flex gap-2">';
                            echo '<form method="POST" action="automation.php" style="display: inline;">';
                            echo '<input type="hidden" name="csrf" value="' . automation_escape((string)$csrf) . '">';
                            echo '<input type="hidden" name="queue_action" value="execute_one">';
                            echo '<input type="hidden" name="pending_uuid" value="' . automation_escape($change['uuid']) . '">';
                            echo '<button type="submit" class="queue-item-btn bg-green-100 hover:bg-green-200 text-green-700">' . automation_escape(automation_t('automation_queue_execute_one')) . '</button>';
                            echo '</form>';
                            echo '<form method="POST" action="automation.php" style="display: inline;">';
                            echo '<input type="hidden" name="csrf" value="' . automation_escape((string)$csrf) . '">';
                            echo '<input type="hidden" name="queue_action" value="delete">';
                            echo '<input type="hidden" name="pending_uuid" value="' . automation_escape($change['uuid']) . '">';
                            echo '<button type="submit" class="queue-item-btn bg-red-100 hover:bg-red-200 text-red-700" onclick="return confirm(\'' . automation_escape(automation_t('automation_queue_delete_confirm')) . '\')">' . automation_escape(automation_t('automation_queue_delete')) . '</button>';
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
const AUTOMATION_I18N = <?php echo json_encode($lang, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    const tabButtons = document.querySelectorAll('.automation-tab');
    const executionLoading = document.getElementById('automationExecutionLoading');
    const topActionButtons = document.querySelectorAll('.automation-top-btn');
    
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
                button.classList.add('automation-side-item-active');
            } else {
                button.classList.remove('automation-side-item-active');
            }
        });
    }

    showTab('<?php echo automation_escape($activeTab); ?>');

    const previewForm = document.getElementById('automation-preview-form');
    const switchSelect = document.getElementById('switch');
    const profileSelect = document.getElementById('profile');
    const templateSelect = document.getElementById('template');

    function submitPreviewForm() {
        if (!previewForm) {
            return;
        }
        if (typeof previewForm.requestSubmit === 'function') {
            previewForm.requestSubmit();
        } else {
            previewForm.submit();
        }
    }

    if (profileSelect) {
        profileSelect.addEventListener('change', submitPreviewForm);
    }

    if (templateSelect) {
        templateSelect.addEventListener('change', submitPreviewForm);
    }

    if (switchSelect) {
        switchSelect.addEventListener('change', function() {
            const selectedOption = switchSelect.options[switchSelect.selectedIndex];
            const profileValue = selectedOption ? selectedOption.getAttribute('data-profile') : '';

            if (profileSelect && profileValue) {
                for (let option of profileSelect.options) {
                    if (option.value === profileValue) {
                        profileSelect.value = profileValue;
                        break;
                    }
                }
            }

            submitPreviewForm();
        });
    }

    window.triggerAutomationExecution = function(mode) {
        if (!window.syncExecutionFormValues()) {
            return;
        }

        const executeForm = document.getElementById('automation-execute-form');
        if (!executeForm) {
            return;
        }

        let queueInput = executeForm.querySelector('input[name="queue_mode"]');
        if (!queueInput) {
            queueInput = document.createElement('input');
            queueInput.type = 'hidden';
            queueInput.name = 'queue_mode';
            executeForm.appendChild(queueInput);
        }

        queueInput.value = mode === 'on' ? 'on' : 'off';

        if (executionLoading && mode !== 'on') {
            executionLoading.classList.remove('hidden');
            topActionButtons.forEach(function(button) {
                button.disabled = true;
                button.classList.add('opacity-60', 'cursor-not-allowed');
            });
        }

        executeForm.submit();
    };

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
            alert(AUTOMATION_I18N.automation_select_queue_entry_alert);
            return false;
        }
        return true;
    };

});
</script>

<?php
    include_once 'includes/footer.php';
?>
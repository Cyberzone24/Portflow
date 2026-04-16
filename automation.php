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

    use Portflow\Core\Automation;
    use Portflow\Core\AutomationStore;
    use Portflow\Core\Logger;
    use Portflow\Core\Auth;

    $automation = new Automation();
    $automationStore = new AutomationStore();
    $logger = new Logger();
    $auth = new Auth();
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

    if (!isset($profiles[$selectedProfile]) && !empty($profileKeys)) {
        $selectedProfile = $profileKeys[0];
    }

    if (!isset($templates[$selectedTemplate]) && !empty($templateKeys)) {
        $selectedTemplate = $templateKeys[0];
    }

    if (!in_array($selectedSaveMode, ['immediate', 'skip_save'], true)) {
        $selectedSaveMode = 'immediate';
    }

    $templateDefinition = $templates[$selectedTemplate] ?? [];
    $variableValues = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute'])) {
        // Check if user has access to automation resource
        if (!$auth->checkResourceAccess($_SESSION['uuid'], 'automation')) {
            $logger->log('user denied access to automation execute', 2, echoToWeb: true);
            $executionResult = [
                'ok' => false,
                'output' => 'Ausfuehrung fehlgeschlagen: Sie haben keine Berechtigung fuer Automatisierungsfunktionen.'
            ];
        } else {
            $selectedSwitch = (string)($_POST['switch'] ?? $selectedSwitch);
            $selectedProfile = (string)($_POST['profile'] ?? $selectedProfile);
            $selectedTemplate = (string)($_POST['template'] ?? $selectedTemplate);
            $selectedSaveMode = (string)($_POST['save_mode'] ?? $selectedSaveMode);

            if (!in_array($selectedSaveMode, ['immediate', 'skip_save'], true)) {
                $selectedSaveMode = 'immediate';
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

            $rendered = $automation->renderTemplate($selectedTemplate, $selectedProfile, $variableValues);
            $rendered['commands'] = applySaveModeToCommands(
                $rendered['commands'] ?? [],
                $profiles[$selectedProfile] ?? [],
                $selectedSaveMode
            );
            if ($selectedSaveMode === 'skip_save') {
                $rendered['warnings'][] = 'Save-Befehle wurden fuer diesen Lauf uebersprungen (save/write_config).';
            }

            if (is_array($selectedSwitchData)) {
                $selectedSwitchData['ssh_port'] = $storedSettings['ssh_port'] ?? 22;
                $selectedSwitchData['ssh_username'] = $storedSettings['ssh_username'] ?? '';
                $selectedSwitchData['ssh_password'] = $storedSettings['ssh_password'] ?? '';
                $executionResult = runAutomationSshCommands($selectedSwitchData, $rendered['commands'] ?? [], $logger, $selectedSwitch, $selectedProfile, $selectedTemplate);
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
        $rendered = $automation->renderTemplate($selectedTemplate, $selectedProfile, $variableValues);
        $rendered['commands'] = applySaveModeToCommands(
            $rendered['commands'] ?? [],
            $profiles[$selectedProfile] ?? [],
            $selectedSaveMode
        );
        if ($selectedSaveMode === 'skip_save') {
            $rendered['warnings'][] = 'Preview ohne Save-Befehle (save/write_config).';
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
        exec($fullCommand . ' 2>&1', $lines, $exitCode);
        @unlink($commandFile);

        $maxLines = 120;
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[] = '... output truncated ...';
        }

        $maskedCommand = ($password !== '')
            ? 'sshpass -p ******** ssh ...'
            : trim((string)$fullCommand);

        $outputText = "Command: " . $maskedCommand . "\n";
        $outputText .= "Exit Code: " . $exitCode . "\n\n";
        $outputText .= implode("\n", $lines);

        $logger->log(
            'automation execute switch=' . $switchName . ' profile=' . $profileId . ' template=' . $templateId . ' exit=' . $exitCode,
            $exitCode === 0 ? 1 : 3
        );

        return [
            'ok' => ($exitCode === 0),
            'output' => $outputText
        ];
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

    function automation_escape($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
        <div class="flex justify-between items-start gap-6 pb-6">
            <div>
                <div class="text-2xl font-bold">Automation Preview</div>
                <div class="text-sm text-gray-600">Konfiguration und Kommandosequenz fuer Huawei Switches.</div>
            </div>
            <div class="text-sm text-gray-500 max-w-xl text-right">
                Die Ausfuehrung per SSH wird im naechsten Schritt angebunden. Aktuell kannst du Profile, Templates und Variablen pruefen.
            </div>
        </div>

        <form class="grid grid-cols-1 lg:grid-cols-2 gap-6 pb-8" method="GET" action="automation.php">
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
                    <div class="text-sm text-gray-600">Fuehrt die gerenderte Kommandosequenz auf dem gewaelten Switch aus.</div>
                </div>
            </div>
            <form method="POST" action="automation.php">
                <input type="hidden" name="execute" value="1">
                <input type="hidden" name="switch" value="<?php echo automation_escape($selectedSwitch); ?>">
                <input type="hidden" name="profile" value="<?php echo automation_escape($selectedProfile); ?>">
                <input type="hidden" name="template" value="<?php echo automation_escape($selectedTemplate); ?>">
                <input type="hidden" name="save_mode" value="<?php echo automation_escape($selectedSaveMode); ?>">
                <?php foreach ($variableValues as $variableName => $variableValue) : ?>
                    <input type="hidden" name="<?php echo automation_escape($variableName); ?>" value="<?php echo automation_escape($variableValue); ?>">
                <?php endforeach; ?>
                <div class="flex justify-end">
                    <button type="submit" class="px-5 py-2 rounded-full bg-green-500 hover:bg-green-700 text-white font-semibold">Execute</button>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
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
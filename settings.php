<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    const APP_NAME = 'Portflow';

    include_once __DIR__ . '/includes/core/session.php';
    if (!in_array(__DIR__ . '/includes/core/session.php', get_included_files())) {
        die('could not verify session');
    }

    // import auth
    include_once __DIR__ . '/includes/core/auth.php';
    include_once __DIR__ . '/includes/core/logger.php';
    include_once __DIR__ . '/includes/core/automation_store.php';
    include_once __DIR__ . '/includes/core/automation.php';
    include_once __DIR__ . '/includes/core/system_state.php';
    use Portflow\Core\Auth;
    use Portflow\Core\Automation;
    use Portflow\Core\AutomationStore;
    use Portflow\Core\Logger;
    $auth = new Auth();
    $logger = new Logger();

    $automationTestResult = null;
    $automationFormDataOverride = null;

    function normalizeSnmpV3AuthProtocol(string $protocol): string {
        $trimmed = trim($protocol);
        if ($trimmed === '') {
            return '';
        }

        $normalized = strtoupper(str_replace(['-', '_'], '', $trimmed));
        if ($normalized === 'SHA1') {
            $normalized = 'SHA';
        }

        $allowed = ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'];
        if (!in_array($normalized, $allowed, true)) {
            return 'SHA';
        }

        return $normalized;
    }

    function normalizeSnmpV3PrivProtocol(string $protocol): string {
        $trimmed = trim($protocol);
        if ($trimmed === '') {
            return '';
        }

        $normalized = strtoupper(str_replace(['-', '_'], '', $trimmed));
        if ($normalized === 'AES256C' || $normalized === 'AES256CFB') {
            $normalized = 'AES256';
        }

        $allowed = ['DES', 'AES', 'AES128', 'AES192', 'AES256'];
        if (!in_array($normalized, $allowed, true)) {
            return 'AES';
        }

        return $normalized;
    }

    function mapSnmpV3AuthProtocolForCli(string $normalizedProtocol): string {
        $protocol = normalizeSnmpV3AuthProtocol($normalizedProtocol);
        $cliMap = [
            'SHA224' => 'SHA-224',
            'SHA256' => 'SHA-256',
            'SHA384' => 'SHA-384',
            'SHA512' => 'SHA-512'
        ];

        return $cliMap[$protocol] ?? $protocol;
    }

    function mapSnmpV3PrivProtocolForCli(string $normalizedProtocol): string {
        $protocol = normalizeSnmpV3PrivProtocol($normalizedProtocol);
        $cliMap = [
            'AES128' => 'AES',
            'AES192' => 'AES-192',
            'AES256' => 'AES-256'
        ];

        return $cliMap[$protocol] ?? $protocol;
    }

    function ensureAutomationKnownHostsFile(): string {
        $directory = __DIR__ . '/data/automation';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Konnte das Known-Hosts-Verzeichnis nicht anlegen.');
        }

        $path = $directory . '/known_hosts';
        if (!file_exists($path) && @touch($path) === false) {
            throw new RuntimeException('Konnte die Known-Hosts-Datei nicht anlegen.');
        }

        @chmod($path, 0600);
        return $path;
    }

    function isValidAutomationInventoryHost(string $host): bool {
        $host = trim($host);
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $host) === 1;
    }

    function isValidAutomationInventoryName(string $name): bool {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', trim($name)) === 1;
    }

    function isValidAutomationReferenceUuid(string $value): bool {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', trim($value)) === 1;
    }

    function configIsValidSlackWebhookUrl(string $url): bool {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');

        if ($scheme !== 'https') {
            return false;
        }
        if (!in_array($host, ['hooks.slack.com', 'hooks.slack-gov.com'], true)) {
            return false;
        }
        if (!preg_match('#^/services/[A-Za-z0-9/_-]+$#', $path)) {
            return false;
        }
        if (isset($parts['user'], $parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return true;
    }

    function validateAutomationInventoryEntry(array $switchEntry, array $profileDefaults): void {
        $name = trim((string)($switchEntry['name'] ?? ''));
        $host = trim((string)($switchEntry['mgmt_ip'] ?? ''));
        $profile = trim((string)($switchEntry['profile'] ?? ''));
        $credentialMode = trim((string)($switchEntry['credential_mode'] ?? 'global'));
        $authMethod = trim((string)($switchEntry['ssh_auth_method'] ?? 'password'));
        $deviceId = trim((string)($switchEntry['device_id'] ?? ''));
        $itemGroupId = trim((string)($switchEntry['item_group_id'] ?? ''));
        $username = trim((string)($switchEntry['ssh_username'] ?? ''));
        $password = (string)($switchEntry['ssh_password'] ?? '');
        $privateKey = trim((string)($switchEntry['ssh_private_key'] ?? ''));

        if (!isValidAutomationInventoryName($name)) {
            throw new InvalidArgumentException('Switch-Name enthaelt unzulaessige Zeichen oder ist zu lang.');
        }
        if (!isValidAutomationInventoryHost($host)) {
            throw new InvalidArgumentException('Management-IP/Host ist ungueltig.');
        }
        if ($profile === '' || !isset($profileDefaults[$profile])) {
            throw new InvalidArgumentException('Switch-Profil ist ungueltig.');
        }
        if (!in_array($credentialMode, ['global', 'individual'], true)) {
            throw new InvalidArgumentException('Credential-Mode ist ungueltig.');
        }
        if (!in_array($authMethod, ['password', 'key'], true)) {
            throw new InvalidArgumentException('SSH-Auth-Methode ist ungueltig.');
        }
        if ($deviceId !== '' && !isValidAutomationReferenceUuid($deviceId)) {
            throw new InvalidArgumentException('Device-Referenz ist ungueltig.');
        }
        if ($itemGroupId !== '' && !isValidAutomationReferenceUuid($itemGroupId)) {
            throw new InvalidArgumentException('Item-Group-Referenz ist ungueltig.');
        }

        if ($credentialMode === 'individual') {
            if ($username === '') {
                throw new InvalidArgumentException('Individuelle Credentials erfordern einen SSH-Benutzernamen.');
            }
            if ($authMethod === 'password' && $password === '') {
                throw new InvalidArgumentException('Individuelle Passwort-Authentifizierung erfordert ein Passwort.');
            }
            if ($authMethod === 'key' && $privateKey === '') {
                throw new InvalidArgumentException('Individuelle Key-Authentifizierung erfordert einen SSH-Key.');
            }
        }
    }

    function validateAutomationInventoryJson(string $inventoryJson, array $profileDefaults): string {
        $decoded = json_decode($inventoryJson, true);
        if (!is_array($decoded) || !isset($decoded['switches']) || !is_array($decoded['switches'])) {
            throw new InvalidArgumentException('Switch-Inventory JSON ist ungueltig.');
        }

        foreach ($decoded['switches'] as $index => $switchEntry) {
            if (!is_array($switchEntry)) {
                throw new InvalidArgumentException('Switch-Inventory Eintrag #' . ($index + 1) . ' ist ungueltig.');
            }
            validateAutomationInventoryEntry($switchEntry, $profileDefaults);
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    function runAutomationSshTest(array $formData, AutomationStore $store, Logger $logger): array {
        $saved = $store->getSettings();

        $host = trim((string)($formData['ssh_host'] ?? ''));
        $port = (int)($formData['ssh_port'] ?? 22);
        $authMethod = trim((string)($formData['ssh_auth_method'] ?? 'password'));
        $username = trim((string)($formData['ssh_username'] ?? ''));
        $password = (string)($formData['ssh_password'] ?? '');
        $privateKey = trim((string)($formData['ssh_private_key'] ?? ''));

        if ($host === '') {
            $host = trim((string)($saved['ssh_host'] ?? ''));
        }
        if ($username === '') {
            $username = trim((string)($saved['ssh_username'] ?? ''));
        }
        if ($port <= 0 || $port > 65535) {
            $port = (int)($saved['ssh_port'] ?? 22);
        }
        if ($password === '') {
            $password = (string)($saved['ssh_password'] ?? '');
        }
        if ($privateKey === '') {
            $privateKey = (string)($saved['ssh_private_key'] ?? '');
        }
        if (!in_array($authMethod, ['password', 'key'], true)) {
            $authMethod = ((string)($saved['ssh_auth_method'] ?? 'password'));
        }
        if (!in_array($authMethod, ['password', 'key'], true)) {
            $authMethod = $privateKey !== '' ? 'key' : 'password';
        }

        if ($host === '' || $username === '') {
            return [
                'ok' => false,
                'output' => "SSH-Test fehlgeschlagen: Host und Username sind erforderlich."
            ];
        }

        if (!isValidAutomationInventoryHost($host)) {
            return [
                'ok' => false,
                'output' => "SSH-Test fehlgeschlagen: Host enthaelt unzulaessige Zeichen."
            ];
        }

        $sshPath = trim((string)shell_exec('command -v ssh 2>/dev/null'));
        if ($sshPath === '') {
            return [
                'ok' => false,
                'output' => "SSH-Test fehlgeschlagen: ssh Binary wurde nicht gefunden."
            ];
        }

        $timeoutPath = trim((string)shell_exec('command -v timeout 2>/dev/null'));
        $sshpassPath = trim((string)shell_exec('command -v sshpass 2>/dev/null'));
        $keyFile = null;

        try {
            $knownHostsFile = ensureAutomationKnownHostsFile();
        } catch (RuntimeException $e) {
            return [
                'ok' => false,
                'output' => 'SSH-Test fehlgeschlagen: ' . $e->getMessage()
            ];
        }

        $sshOptions = '-F /dev/null -tt -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . escapeshellarg($knownHostsFile) . ' -o ConnectTimeout=8';

        if ($authMethod === 'password' && $password !== '') {
            $sshOptions .= ' -o PreferredAuthentications=password -o PubkeyAuthentication=no';
        } else {
            $sshOptions .= ' -o BatchMode=yes';
        }

        if ($authMethod === 'key') {
            if ($privateKey === '') {
                return [
                    'ok' => false,
                    'output' => "SSH-Test fehlgeschlagen: SSH-Key ist leer."
                ];
            }

            $keyFile = tempnam(sys_get_temp_dir(), 'portflow-ssh-key-test-');
            if ($keyFile === false) {
                return [
                    'ok' => false,
                    'output' => 'SSH-Test fehlgeschlagen: Konnte keine temporaere Key-Datei anlegen.'
                ];
            }
            file_put_contents($keyFile, rtrim($privateKey) . "\n");
            @chmod($keyFile, 0600);
            $sshOptions .= ' -o PreferredAuthentications=publickey -o PasswordAuthentication=no -i ' . escapeshellarg($keyFile);
        }

        $commandFile = tempnam(sys_get_temp_dir(), 'portflow-ssh-test-');
        if ($commandFile === false) {
            return [
                'ok' => false,
                'output' => 'SSH-Test fehlgeschlagen: Konnte keine temporäre Datei anlegen.'
            ];
        }

        file_put_contents($commandFile, "screen-length 0 temporary\ndisplay version\nquit\n");

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
                    'output' => "SSH-Test fehlgeschlagen: Passwortauthentifizierung benoetigt sshpass, ist aber nicht installiert."
                ];
            }

            putenv('SSHPASS=' . $password);
            $sshCommand = $sshpassPath . ' -e ' . $sshCommand;
        }

        $fullCommand = $sshCommand;
        if ($timeoutPath !== '') {
            $fullCommand = $timeoutPath . ' 15s ' . $fullCommand;
        }

        $lines = [];
        $exitCode = 1;
        exec($fullCommand . ' 2>&1', $lines, $exitCode);
        if ($authMethod === 'password' && $password !== '') {
            putenv('SSHPASS');
        }
        @unlink($commandFile);
        if ($keyFile !== null) {
            @unlink($keyFile);
        }

        $maxLines = 60;
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[] = '... output truncated ...';
        }

        $maskedCommand = ($authMethod === 'password' && $password !== '')
            ? 'sshpass -e ssh ...'
            : trim((string)$fullCommand);

        $outputText = "Command: " . $maskedCommand . "\n";
        $outputText .= "Exit Code: " . $exitCode . "\n\n";
        $outputText .= implode("\n", $lines);

        if ($exitCode === 124) {
            $outputText .= "\n\nHinweis: Timeout erreicht. Verbindung wurde nicht rechtzeitig beendet.";
        }

        $logger->log('automation ssh test for ' . $host . ' returned exit code ' . $exitCode, $exitCode === 0 ? 1 : 3);

        return [
            'ok' => ($exitCode === 0),
            'title' => 'SSH Test Output',
            'output' => $outputText
        ];
    }

    function runAutomationSnmpTest(array $formData, AutomationStore $store, Logger $logger): array {
        $saved = $store->getSettings();
        $switchName = trim((string)($formData['snmp_switch_name'] ?? ''));

        $host = trim((string)($formData['snmp_host'] ?? $formData['ssh_host'] ?? ''));
        $version = trim((string)($formData['snmp_version'] ?? ''));
        $community = trim((string)($formData['snmp_community'] ?? ''));
        $mib = trim((string)($formData['snmp_mib'] ?? ''));
        $port = (int)($formData['snmp_port'] ?? 161);
        $timeout = (int)($formData['snmp_timeout'] ?? 2);
        $retries = (int)($formData['snmp_retries'] ?? 1);

        $snmpV3Username = trim((string)($formData['snmp_v3_username'] ?? ''));
        $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol((string)($formData['snmp_v3_auth_protocol'] ?? ''));
        $snmpV3AuthPassphrase = (string)($formData['snmp_v3_auth_passphrase'] ?? '');
        $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol((string)($formData['snmp_v3_priv_protocol'] ?? ''));
        $snmpV3PrivPassphrase = (string)($formData['snmp_v3_priv_passphrase'] ?? '');

        $inventoryRaw = trim((string)($saved['switch_inventory_json'] ?? ''));
        $inventory = decodeJsonObject($inventoryRaw, ['switches' => []]);
        $switches = is_array($inventory['switches'] ?? null) ? $inventory['switches'] : [];
        if ($switchName !== '') {
            foreach ($switches as $switchItem) {
                if (!is_array($switchItem)) {
                    continue;
                }

                if (strcasecmp((string)($switchItem['name'] ?? ''), $switchName) !== 0) {
                    continue;
                }

                if ($host === '') {
                    $host = trim((string)($switchItem['mgmt_ip'] ?? ''));
                }

                $switchSnmp = is_array($switchItem['snmp'] ?? null) ? $switchItem['snmp'] : [];
                $profileDefaults = fetchAutomationProfilesFromFile();
                $profileId = trim((string)($switchItem['profile'] ?? ''));
                $profileSnmp = is_array($profileDefaults[$profileId]['snmp'] ?? null) ? $profileDefaults[$profileId]['snmp'] : [];

                if ($version === '') {
                    $version = trim((string)($switchSnmp['version'] ?? ''));
                }
                if ($version === '') {
                    $version = trim((string)($profileSnmp['version'] ?? '2c'));
                }
                if ($community === '') {
                    $community = trim((string)($switchSnmp['community'] ?? ''));
                }
                if ($community === '') {
                    $community = trim((string)($profileSnmp['community'] ?? ''));
                }
                if ($mib === '') {
                    $mib = trim((string)($switchSnmp['mib'] ?? ''));
                }
                if ($mib === '') {
                    $mib = trim((string)($profileSnmp['default_mib'] ?? ''));
                }
                if ($port <= 0) {
                    $port = (int)($switchSnmp['port'] ?? 161);
                }
                if ($timeout <= 0) {
                    $timeout = (int)($switchSnmp['timeout'] ?? 2);
                }
                if ($retries < 0) {
                    $retries = (int)($switchSnmp['retries'] ?? 1);
                }

                if ($snmpV3Username === '') {
                    $snmpV3Username = trim((string)($switchSnmp['v3_username'] ?? ''));
                }
                if ($snmpV3Username === '') {
                    $snmpV3Username = trim((string)($profileSnmp['v3_username'] ?? ''));
                }
                if ($snmpV3AuthPassphrase === '') {
                    $snmpV3AuthPassphrase = (string)($switchSnmp['v3_auth_passphrase'] ?? '');
                }
                if ($snmpV3AuthPassphrase === '') {
                    $snmpV3AuthPassphrase = (string)($profileSnmp['v3_auth_passphrase'] ?? '');
                }
                if ($snmpV3PrivPassphrase === '') {
                    $snmpV3PrivPassphrase = (string)($switchSnmp['v3_priv_passphrase'] ?? '');
                }
                if ($snmpV3PrivPassphrase === '') {
                    $snmpV3PrivPassphrase = (string)($profileSnmp['v3_priv_passphrase'] ?? '');
                }
                if ($snmpV3AuthProtocol === '') {
                    $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol((string)($switchSnmp['v3_auth_protocol'] ?? ''));
                }
                if ($snmpV3AuthProtocol === '') {
                    $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol((string)($profileSnmp['v3_auth_protocol'] ?? 'SHA'));
                }
                if ($snmpV3PrivProtocol === '') {
                    $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol((string)($switchSnmp['v3_priv_protocol'] ?? ''));
                }
                if ($snmpV3PrivProtocol === '') {
                    $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol((string)($profileSnmp['v3_priv_protocol'] ?? 'AES'));
                }

                // If v2c has no usable community but profile is v3, prefer profile v3 defaults for tests.
                $profileSnmpVersion = trim((string)($profileSnmp['version'] ?? ''));
                if ($version === '2c' && $community === '' && $profileSnmpVersion === '3') {
                    $version = '3';
                }

                break;
            }
        }

        if ($host === '') {
            $host = trim((string)($saved['ssh_host'] ?? ''));
        }

        if ($host === '') {
            return [
                'ok' => false,
                'title' => 'SNMP Test Output',
                'output' => 'SNMP-Test fehlgeschlagen: Host ist erforderlich.'
            ];
        }

        if (!isValidAutomationInventoryHost($host)) {
            return [
                'ok' => false,
                'title' => 'SNMP Test Output',
                'output' => 'SNMP-Test fehlgeschlagen: Host enthaelt unzulaessige Zeichen.'
            ];
        }

        if (!in_array($version, ['2c', '3'], true)) {
            $version = '2c';
        }

        $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol($snmpV3AuthProtocol);
        $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol($snmpV3PrivProtocol);

        $snmpV3AuthProtocolCli = mapSnmpV3AuthProtocolForCli($snmpV3AuthProtocol);
        $snmpV3PrivProtocolCli = mapSnmpV3PrivProtocolForCli($snmpV3PrivProtocol);

        if ($port < 1 || $port > 65535) {
            $port = 161;
        }
        if ($timeout < 1 || $timeout > 30) {
            $timeout = 2;
        }
        if ($retries < 0 || $retries > 10) {
            $retries = 1;
        }

        $snmpgetPath = trim((string)shell_exec('command -v snmpget 2>/dev/null'));
        if ($snmpgetPath === '') {
            return [
                'ok' => false,
                'title' => 'SNMP Test Output',
                'output' => 'SNMP-Test fehlgeschlagen: snmpget Binary wurde nicht gefunden.'
            ];
        }

        $timeoutPath = trim((string)shell_exec('command -v timeout 2>/dev/null'));
        $oid = '.1.3.6.1.2.1.1.5.0';

        $cmdParts = [
            $snmpgetPath,
            '-v',
            escapeshellarg($version),
            '-On',
            '-t',
            escapeshellarg((string)$timeout),
            '-r',
            escapeshellarg((string)$retries)
        ];

        $maskedParts = $cmdParts;

        if ($version === '3') {
            if ($snmpV3Username === '') {
                return [
                    'ok' => false,
                    'title' => 'SNMP Test Output',
                    'output' => 'SNMP-Test fehlgeschlagen: Fuer SNMPv3 ist ein Username erforderlich.'
                ];
            }

            $securityLevel = 'noAuthNoPriv';
            if ($snmpV3AuthPassphrase !== '' && $snmpV3PrivPassphrase !== '') {
                $securityLevel = 'authPriv';
            } elseif ($snmpV3AuthPassphrase !== '') {
                $securityLevel = 'authNoPriv';
            }

            $cmdParts[] = '-l';
            $cmdParts[] = escapeshellarg($securityLevel);
            $cmdParts[] = '-u';
            $cmdParts[] = escapeshellarg($snmpV3Username);

            $maskedParts[] = '-l';
            $maskedParts[] = escapeshellarg($securityLevel);
            $maskedParts[] = '-u';
            $maskedParts[] = escapeshellarg($snmpV3Username);

            if ($snmpV3AuthPassphrase !== '') {
                $cmdParts[] = '-a';
                $cmdParts[] = escapeshellarg($snmpV3AuthProtocolCli);
                $cmdParts[] = '-A';
                $cmdParts[] = escapeshellarg($snmpV3AuthPassphrase);

                $maskedParts[] = '-a';
                $maskedParts[] = escapeshellarg($snmpV3AuthProtocolCli);
                $maskedParts[] = '-A';
                $maskedParts[] = "'********'";
            }

            if ($securityLevel === 'authPriv') {
                $cmdParts[] = '-x';
                $cmdParts[] = escapeshellarg($snmpV3PrivProtocolCli);
                $cmdParts[] = '-X';
                $cmdParts[] = escapeshellarg($snmpV3PrivPassphrase);

                $maskedParts[] = '-x';
                $maskedParts[] = escapeshellarg($snmpV3PrivProtocolCli);
                $maskedParts[] = '-X';
                $maskedParts[] = "'********'";
            }
        } else {
            if ($community === '') {
                return [
                    'ok' => false,
                    'title' => 'SNMP Test Output',
                    'output' => 'SNMP-Test fehlgeschlagen: Fuer SNMPv2c ist eine Community erforderlich.'
                ];
            }

            $cmdParts[] = '-c';
            $cmdParts[] = escapeshellarg($community);

            $maskedParts[] = '-c';
            $maskedParts[] = "'********'";
        }

        $agentTarget = $host . ':' . (string)$port;
        $cmdParts[] = escapeshellarg($agentTarget);
        $cmdParts[] = escapeshellarg($oid);
        $command = implode(' ', $cmdParts);

        $maskedParts[] = escapeshellarg($agentTarget);
        $maskedParts[] = escapeshellarg($oid);
        $maskedCommand = implode(' ', $maskedParts);

        if ($timeoutPath !== '') {
            $command = $timeoutPath . ' 12s ' . $command;
            $maskedCommand = $timeoutPath . ' 12s ' . $maskedCommand;
        }

        $lines = [];
        $exitCode = 1;
        exec($command . ' 2>&1', $lines, $exitCode);

        $maxLines = 60;
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[] = '... output truncated ...';
        }

        $outputText = 'Command: ' . $maskedCommand . "\n";
        $outputText .= 'Exit Code: ' . $exitCode . "\n";
        if ($mib !== '') {
            $outputText .= 'Hinweis: Profil/Switch-MIB fuer diesen Test: ' . $mib . "\n";
        }
        $outputText .= "\n" . implode("\n", $lines);

        if ($exitCode === 124) {
            $outputText .= "\n\nHinweis: Timeout erreicht. SNMP-Ziel hat nicht rechtzeitig geantwortet.";
        }

        $logger->log('automation snmp test for ' . $host . ' returned exit code ' . $exitCode, $exitCode === 0 ? 1 : 3);

        return [
            'ok' => ($exitCode === 0),
            'title' => 'SNMP Test Output',
            'output' => $outputText
        ];
    }

    function decodeJsonObject(string $raw, array $fallback = []): array {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    function loadAutomationStructuredSettings(AutomationStore $store): array {
        $settings = $store->getSettings();

        $scriptsRaw = trim((string)($settings['scripts_json'] ?? ''));
        if ($scriptsRaw === '') {
            $scriptsRaw = '{}';
        }
        $scripts = decodeJsonObject($scriptsRaw, []);

        $inventoryRaw = trim((string)($settings['switch_inventory_json'] ?? ''));
        if ($inventoryRaw === '') {
            $inventoryRaw = '{"switches": []}';
        }
        $inventory = decodeJsonObject($inventoryRaw, ['switches' => []]);
        if (!isset($inventory['switches']) || !is_array($inventory['switches'])) {
            $inventory['switches'] = [];
        }

        return [
            'settings' => $settings,
            'scripts' => $scripts,
            'inventory' => $inventory
        ];
    }

    function getScriptsTabFromRequest(): string {
        $rawTab = trim((string)($_POST['scripts_active_tab'] ?? ($_GET['tab'] ?? 'switch')));
        return in_array($rawTab, ['switch', 'profiles', 'templates', 'history'], true) ? $rawTab : 'switch';
    }

    function getConfigTabFromRequest(): string {
        $rawTab = trim((string)($_GET['tab'] ?? 'system'));
        return in_array($rawTab, ['system', 'updater', 'notifications'], true) ? $rawTab : 'system';
    }

    function decodeJsonArrayString(?string $raw, array $fallback = []): array {
        if (!is_string($raw) || trim($raw) === '') {
            return $fallback;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    function getAutomationTemplateDataFilePath(): string {
        return __DIR__ . '/data/automation/automation.json';
    }

    function readAutomationTemplateDataFile(): array {
        $path = getAutomationTemplateDataFilePath();

        if (!file_exists($path)) {
            $directory = dirname($path);
            if (!is_dir($directory)) {
                @mkdir($directory, 0750, true);
            }

            $basePath = __DIR__ . '/includes/core/automation.json';
            if (file_exists($basePath)) {
                $baseRaw = (string)file_get_contents($basePath);
                if (trim($baseRaw) !== '') {
                    @file_put_contents($path, $baseRaw, LOCK_EX);
                }
            }
        }

        if (!file_exists($path)) {
            return [
                'description_convention' => [],
                'profiles' => [],
                'templates' => []
            ];
        }

        $raw = (string)file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if (!isset($decoded['description_convention']) || !is_array($decoded['description_convention'])) {
            $decoded['description_convention'] = [];
        }
        if (!isset($decoded['profiles']) || !is_array($decoded['profiles'])) {
            $decoded['profiles'] = [];
        }
        if (!isset($decoded['templates']) || !is_array($decoded['templates'])) {
            $decoded['templates'] = [];
        }

        return $decoded;
    }

    function writeAutomationTemplateDataFile(array $data): void {
        $path = getAutomationTemplateDataFilePath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new \RuntimeException('Ablageordner fuer automation.json konnte nicht erstellt werden.');
            }
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('automation.json konnte nicht serialisiert werden.');
        }

        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('automation.json konnte nicht geschrieben werden.');
        }
    }

    function fetchAutomationTemplatesFromDb(\Portflow\Core\DatabaseAdapter $db): array {
        $data = readAutomationTemplateDataFile();
        $templates = is_array($data['templates'] ?? null) ? $data['templates'] : [];
        $normalized = [];

        foreach ($templates as $templateId => $template) {
            if (!is_array($template)) {
                continue;
            }

            $id = trim((string)$templateId);
            if ($id === '') {
                continue;
            }

            $normalized[$id] = [
                'template_id' => $id,
                'label' => (string)($template['label'] ?? $id),
                'description' => (string)($template['description'] ?? ''),
                'supported_profiles' => array_values((array)($template['supported_profiles'] ?? [])),
                'variables' => array_values((array)($template['variables'] ?? [])),
                'commands' => array_values((array)($template['commands'] ?? [])),
                'uses_description_convention' => !empty($template['uses_description_convention']),
                'source' => (string)($template['source'] ?? 'file')
            ];
        }

        ksort($normalized);
        return $normalized;
    }

    function upsertAutomationTemplateInDb(\Portflow\Core\DatabaseAdapter $db, string $templateId, array $template, string $source = 'custom'): void {
        $data = readAutomationTemplateDataFile();
        $templates = is_array($data['templates'] ?? null) ? $data['templates'] : [];

        $templates[$templateId] = [
            'label' => (string)($template['label'] ?? $templateId),
            'description' => (string)($template['description'] ?? ''),
            'supported_profiles' => array_values((array)($template['supported_profiles'] ?? [])),
            'variables' => array_values((array)($template['variables'] ?? [])),
            'commands' => array_values((array)($template['commands'] ?? [])),
            'uses_description_convention' => !empty($template['uses_description_convention']),
            'source' => $source
        ];

        $data['templates'] = $templates;
        writeAutomationTemplateDataFile($data);
    }

    function deactivateAutomationTemplateInDb(\Portflow\Core\DatabaseAdapter $db, string $templateId): bool {
        $data = readAutomationTemplateDataFile();
        $templates = is_array($data['templates'] ?? null) ? $data['templates'] : [];

        if (!isset($templates[$templateId])) {
            return false;
        }

        unset($templates[$templateId]);
        $data['templates'] = $templates;
        writeAutomationTemplateDataFile($data);
        return true;
    }

    function fetchAutomationProfilesFromFile(): array {
        $data = readAutomationTemplateDataFile();
        $profiles = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $normalized = [];

        foreach ($profiles as $profileId => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $id = trim((string)$profileId);
            if ($id === '') {
                continue;
            }

            $snmp = is_array($profile['snmp'] ?? null) ? $profile['snmp'] : [];
            $snmpVersion = (string)($snmp['version'] ?? '2c');
            if (!in_array($snmpVersion, ['2c', '3'], true)) {
                $snmpVersion = '2c';
            }
            $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol((string)($snmp['v3_auth_protocol'] ?? 'SHA'));
            $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol((string)($snmp['v3_priv_protocol'] ?? 'AES'));
            $mibOverrides = array_values(array_filter(array_map('trim', (array)($snmp['mib_overrides'] ?? [])), static function ($value) {
                return $value !== '';
            }));

            $normalized[$id] = [
                'profile_id' => $id,
                'label' => (string)($profile['label'] ?? $id),
                'description' => (string)($profile['description'] ?? ''),
                'supports_commit' => !empty($profile['supports_commit']),
                'enter_config' => (string)($profile['enter_config'] ?? ''),
                'commit' => (string)($profile['commit'] ?? ''),
                'exit_config' => (string)($profile['exit_config'] ?? ''),
                'save' => (string)($profile['save'] ?? ''),
                'write_config' => (string)($profile['write_config'] ?? ''),
                'snmp' => [
                    'enabled' => !empty($snmp['enabled']),
                    'version' => $snmpVersion,
                    'port' => max(1, min(65535, (int)($snmp['port'] ?? 161))),
                    'timeout' => max(1, min(30, (int)($snmp['timeout'] ?? 2))),
                    'retries' => max(0, min(10, (int)($snmp['retries'] ?? 1))),
                    'community' => (string)($snmp['community'] ?? ''),
                    'v3_username' => trim((string)($snmp['v3_username'] ?? '')),
                    'v3_auth_protocol' => $snmpV3AuthProtocol,
                    'v3_auth_passphrase' => (string)($snmp['v3_auth_passphrase'] ?? ''),
                    'v3_priv_protocol' => $snmpV3PrivProtocol,
                    'v3_priv_passphrase' => (string)($snmp['v3_priv_passphrase'] ?? ''),
                    'default_mib' => trim((string)($snmp['default_mib'] ?? '')),
                    'mib_overrides' => $mibOverrides
                ]
            ];
        }

        ksort($normalized);
        return $normalized;
    }

    function upsertAutomationProfileInFile(string $profileId, array $profile): void {
        $data = readAutomationTemplateDataFile();
        $profiles = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];
        $profileSnmp = is_array($profile['snmp'] ?? null) ? $profile['snmp'] : [];
        $snmpVersion = (string)($profileSnmp['version'] ?? '2c');
        if (!in_array($snmpVersion, ['2c', '3'], true)) {
            $snmpVersion = '2c';
        }
        $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol((string)($profileSnmp['v3_auth_protocol'] ?? 'SHA'));
        $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol((string)($profileSnmp['v3_priv_protocol'] ?? 'AES'));

        $profiles[$profileId] = [
            'label' => (string)($profile['label'] ?? $profileId),
            'description' => (string)($profile['description'] ?? ''),
            'supports_commit' => !empty($profile['supports_commit']),
            'enter_config' => (string)($profile['enter_config'] ?? ''),
            'commit' => (string)($profile['commit'] ?? ''),
            'exit_config' => (string)($profile['exit_config'] ?? ''),
            'save' => (string)($profile['save'] ?? ''),
            'write_config' => (string)($profile['write_config'] ?? ''),
            'snmp' => [
                'enabled' => !empty($profileSnmp['enabled'] ?? false),
                'version' => $snmpVersion,
                'port' => max(1, min(65535, (int)($profileSnmp['port'] ?? 161))),
                'timeout' => max(1, min(30, (int)($profileSnmp['timeout'] ?? 2))),
                'retries' => max(0, min(10, (int)($profileSnmp['retries'] ?? 1))),
                'community' => (string)($profileSnmp['community'] ?? ''),
                'v3_username' => trim((string)($profileSnmp['v3_username'] ?? '')),
                'v3_auth_protocol' => $snmpV3AuthProtocol,
                'v3_auth_passphrase' => (string)($profileSnmp['v3_auth_passphrase'] ?? ''),
                'v3_priv_protocol' => $snmpV3PrivProtocol,
                'v3_priv_passphrase' => (string)($profileSnmp['v3_priv_passphrase'] ?? ''),
                'default_mib' => trim((string)($profileSnmp['default_mib'] ?? '')),
                'mib_overrides' => array_values(array_filter(array_map('trim', (array)($profileSnmp['mib_overrides'] ?? [])), static function ($value) {
                    return $value !== '';
                }))
            ]
        ];

        $data['profiles'] = $profiles;
        writeAutomationTemplateDataFile($data);
    }

    function deactivateAutomationProfileInFile(string $profileId): bool {
        $data = readAutomationTemplateDataFile();
        $profiles = is_array($data['profiles'] ?? null) ? $data['profiles'] : [];

        if (!isset($profiles[$profileId])) {
            return false;
        }

        unset($profiles[$profileId]);
        $data['profiles'] = $profiles;
        writeAutomationTemplateDataFile($data);
        return true;
    }

    function migrateLegacyTemplateOverridesToDb(\Portflow\Core\DatabaseAdapter $db, AutomationStore $store, Logger $logger): int {
        $structured = loadAutomationStructuredSettings($store);
        $settings = $structured['settings'];
        $scripts = $structured['scripts'];
        $inventory = $structured['inventory'];

        $legacyTemplates = is_array($scripts['templates'] ?? null) ? $scripts['templates'] : [];
        if (empty($legacyTemplates)) {
            return 0;
        }

        $migrated = 0;
        foreach ($legacyTemplates as $templateId => $templatePayload) {
            $templateId = trim((string)$templateId);
            if ($templateId === '' || !is_array($templatePayload)) {
                continue;
            }

            upsertAutomationTemplateInDb($db, $templateId, $templatePayload, 'legacy_override');
            $migrated++;
        }

        if ($migrated > 0) {
            unset($scripts['templates']);
            $store->saveSettings([
                'ssh_host' => $settings['ssh_host'] ?? '',
                'ssh_port' => $settings['ssh_port'] ?? 22,
                'ssh_auth_method' => $settings['ssh_auth_method'] ?? 'password',
                'ssh_username' => $settings['ssh_username'] ?? '',
                'ssh_password' => $settings['ssh_password'] ?? '',
                'ssh_private_key' => $settings['ssh_private_key'] ?? '',
                'scripts_json' => json_encode($scripts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'switch_inventory_json' => json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ]);
            $logger->log('legacy template overrides migrated to data/automation/automation.json: ' . $migrated, 1);
        }

        return $migrated;
    }

    function getDefaultUserSettings(): array {
        return [
            'language' => 'de-DE',
            'appearance' => [
                'theme' => 'light',
                'font_family' => 'jetbrains',
                'font_size' => 'normal'
            ],
            'notifications' => [
                'level' => 'minimal',
                'channel' => 'mail',
                'slack_webhook_url' => '',
                'telegram_link_token' => '',
                'telegram_link_started_at' => '',
                'telegram_link_confirmed_at' => '',
                'telegram_link_username' => '',
                'telegram_chat_id' => ''
            ]
        ];
    }

    function normalizeAppearanceTheme(string $theme): string {
        $theme = strtolower(trim($theme));

        if ($theme === 'light') {
            return 'light';
        }
        if ($theme === 'dark') {
            return 'dark';
        }

        return in_array($theme, ['light', 'dark', 'contrast'], true) ? $theme : 'light';
    }

    function getNotificationChannelReadiness(): array {
        $slackEnabled = defined('NOTIFICATION_SLACK_ENABLED') && NOTIFICATION_SLACK_ENABLED === true;
        $slackWebhook = defined('NOTIFICATION_SLACK_WEBHOOK_URL') ? trim((string)NOTIFICATION_SLACK_WEBHOOK_URL) : '';

        $telegramEnabled = defined('NOTIFICATION_TELEGRAM_ENABLED') && NOTIFICATION_TELEGRAM_ENABLED === true;
        $telegramBotToken = defined('NOTIFICATION_TELEGRAM_BOT_TOKEN') ? trim((string)NOTIFICATION_TELEGRAM_BOT_TOKEN) : '';
        $telegramChatId = defined('NOTIFICATION_TELEGRAM_CHAT_ID') ? trim((string)NOTIFICATION_TELEGRAM_CHAT_ID) : '';

        return [
            'mail' => true,
            'slack' => $slackEnabled && configIsValidSlackWebhookUrl($slackWebhook),
            'telegram' => $telegramEnabled && $telegramBotToken !== '',
            'slack_enabled' => $slackEnabled,
            'telegram_enabled' => $telegramEnabled,
            'telegram_global_chat_id' => $telegramChatId !== ''
        ];
    }

    function getAvailableNotificationChannels(bool $includeNotReady = false): array {
        $channels = ['mail'];
        $readiness = getNotificationChannelReadiness();

        if (!empty($readiness['slack_enabled']) && ($includeNotReady || !empty($readiness['slack']))) {
            $channels[] = 'slack';
        }
        if (!empty($readiness['telegram_enabled']) && ($includeNotReady || !empty($readiness['telegram']))) {
            $channels[] = 'telegram';
        }

        return $channels;
    }

    function getSessionUserSettings(): array {
        $defaults = getDefaultUserSettings();
        $raw = $_SESSION['settings'] ?? '';

        if (is_array($raw)) {
            $settings = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $settings = is_array($decoded) ? $decoded : [];
        } else {
            $settings = [];
        }

        if (!isset($settings['language']) || !is_string($settings['language']) || $settings['language'] === '') {
            $settings['language'] = $defaults['language'];
        }

        if (!isset($settings['appearance']) || !is_array($settings['appearance'])) {
            $settings['appearance'] = [];
        }

        $settings['appearance']['theme'] = normalizeAppearanceTheme((string)($settings['appearance']['theme'] ?? ''));

        $settings['appearance']['font_family'] = in_array((string)($settings['appearance']['font_family'] ?? ''), ['jetbrains', 'source_sans', 'fira_sans'], true)
            ? (string)$settings['appearance']['font_family']
            : $defaults['appearance']['font_family'];

        $settings['appearance']['font_size'] = in_array((string)($settings['appearance']['font_size'] ?? ''), ['small', 'normal', 'large'], true)
            ? (string)$settings['appearance']['font_size']
            : $defaults['appearance']['font_size'];

        if (!isset($settings['notifications']) || !is_array($settings['notifications'])) {
            $settings['notifications'] = [];
        }

        $settings['notifications']['level'] = in_array((string)($settings['notifications']['level'] ?? ''), ['off', 'minimal', 'progress', 'all'], true)
            ? (string)$settings['notifications']['level']
            : $defaults['notifications']['level'];

        $availableChannels = getAvailableNotificationChannels();
        $settings['notifications']['channel'] = in_array((string)($settings['notifications']['channel'] ?? ''), $availableChannels, true)
            ? (string)$settings['notifications']['channel']
            : $defaults['notifications']['channel'];

        $settings['notifications']['slack_webhook_url'] = configNormalizeEnvValue((string)($settings['notifications']['slack_webhook_url'] ?? ''));
        $settings['notifications']['telegram_link_token'] = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($settings['notifications']['telegram_link_token'] ?? '')));
        $settings['notifications']['telegram_link_started_at'] = configNormalizeEnvValue((string)($settings['notifications']['telegram_link_started_at'] ?? ''));
        $settings['notifications']['telegram_link_confirmed_at'] = configNormalizeEnvValue((string)($settings['notifications']['telegram_link_confirmed_at'] ?? ''));
        $settings['notifications']['telegram_link_username'] = configNormalizeEnvValue((string)($settings['notifications']['telegram_link_username'] ?? ''));
        $settings['notifications']['telegram_chat_id'] = configNormalizeEnvValue((string)($settings['notifications']['telegram_chat_id'] ?? ''));

        return $settings;
    }

    function saveUserSettings(\Portflow\Core\DatabaseAdapter $dbAdapter, array $settings, string $uuid): void {
        $encoded = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            $encoded = json_encode(getDefaultUserSettings(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $_SESSION['settings'] = (string)$encoded;
        $dbAdapter->db_query(
            "UPDATE users SET settings = :settings, changed = NOW() WHERE uuid = :uuid",
            ['settings' => (string)$encoded, 'uuid' => $uuid]
        );
    }

    function setSettingsFeedback(string $section, bool $ok, string $message): void {
        if (!isset($_SESSION['settings_feedback']) || !is_array($_SESSION['settings_feedback'])) {
            $_SESSION['settings_feedback'] = [];
        }

        $_SESSION['settings_feedback'][$section] = [
            'ok' => $ok,
            'message' => $message
        ];
    }

    function getAndClearSettingsFeedback(): array {
        $feedback = $_SESSION['settings_feedback'] ?? [];
        unset($_SESSION['settings_feedback']);
        return is_array($feedback) ? $feedback : [];
    }

    function generateNotificationLinkToken(): string {
        return strtoupper(bin2hex(random_bytes(4)));
    }

    function findTelegramChatByStartToken(string $token): array {
        $normalizedToken = preg_replace('/[^A-Z0-9]/', '', strtoupper($token));
        if ($normalizedToken === '') {
            return ['ok' => false, 'matched' => false, 'error' => 'ungueltiger Telegram-Link-Token'];
        }

        if (!defined('NOTIFICATION_TELEGRAM_ENABLED') || NOTIFICATION_TELEGRAM_ENABLED !== true) {
            return ['ok' => false, 'matched' => false, 'error' => 'Telegram ist nicht aktiviert'];
        }

        $botToken = defined('NOTIFICATION_TELEGRAM_BOT_TOKEN') ? trim((string)NOTIFICATION_TELEGRAM_BOT_TOKEN) : '';
        if ($botToken === '') {
            return ['ok' => false, 'matched' => false, 'error' => 'Telegram Bot-Token fehlt'];
        }

        $url = 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/getUpdates?limit=100&timeout=1';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['ok' => false, 'matched' => false, 'error' => 'Telegram getUpdates fehlgeschlagen'];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['ok']) || $decoded['ok'] !== true || !is_array($decoded['result'] ?? null)) {
            $description = is_array($decoded) ? (string)($decoded['description'] ?? 'ungueltige Telegram-Antwort') : 'ungueltige Telegram-Antwort';
            return ['ok' => false, 'matched' => false, 'error' => $description];
        }

        $updates = array_reverse($decoded['result']);
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }

            $message = is_array($update['message'] ?? null)
                ? $update['message']
                : (is_array($update['edited_message'] ?? null) ? $update['edited_message'] : null);
            if (!is_array($message)) {
                continue;
            }

            $text = trim((string)($message['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $text) ?: [];
            $command = strtolower((string)($parts[0] ?? ''));
            if (!preg_match('/^\/start(?:@[a-z0-9_]+)?$/i', $command)) {
                continue;
            }

            $candidateToken = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($parts[1] ?? '')));
            if ($candidateToken !== $normalizedToken) {
                continue;
            }

            $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
            $chatId = trim((string)($chat['id'] ?? ''));
            if ($chatId === '') {
                continue;
            }

            $from = is_array($message['from'] ?? null) ? $message['from'] : [];
            return [
                'ok' => true,
                'matched' => true,
                'chat_id' => $chatId,
                'telegram_username' => trim((string)($from['username'] ?? '')),
                'chat_type' => trim((string)($chat['type'] ?? ''))
            ];
        }

        return ['ok' => true, 'matched' => false, 'error' => 'Noch kein passendes /start mit Token gefunden'];
    }

    function scriptsUrlWithTab(string $tab): string {
        $safeTab = in_array($tab, ['switch', 'profiles', 'templates', 'history'], true) ? $tab : 'switch';
        return '?site=scripts&tab=' . rawurlencode($safeTab);
    }

    function redirectToScriptsTab(string $tab): void {
        header('Location: ' . scriptsUrlWithTab($tab));
    }

    function logAutomationChange(\Portflow\Core\DatabaseAdapter $dbAdapter, string $operation, string $action, array $payload = []): void {
        $userUuid = (string)($_SESSION['uuid'] ?? '');
        if ($userUuid === '') {
            return;
        }

        $safeOperation = strtoupper(substr($operation, 0, 10));
        if (!in_array($safeOperation, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            $safeOperation = 'UPDATE';
        }

        $safePayload = [
            'action' => $action,
            'payload' => $payload
        ];
        $encoded = json_encode($safePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            $encoded = '{"action":"' . addslashes($action) . '"}';
        }

        try {
            $dbAdapter->db_query(
                "INSERT INTO changelog (users, operation, changed_table, changed_row, changed_data) VALUES (:users, :operation, 'automation_settings', gen_random_uuid(), :changed_data)",
                [
                    'users' => $userUuid,
                    'operation' => $safeOperation,
                    'changed_data' => $encoded
                ]
            );
        } catch (\Throwable $ignored) {
            // Best-effort history logging; never block settings operations.
        }
    }

    function escapeSettingValue(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    function configToBool($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    function configBoolFromPost(string $name): bool {
        return isset($_POST[$name]) && configToBool($_POST[$name]);
    }

    function configEnvBool(bool $value): string {
        return $value ? 'true' : 'false';
    }

    function configNormalizeEnvValue(string $value): string {
        return str_replace(["\r", "\n"], '', trim($value));
    }

    function configRequirePortRange(int $port): bool {
        return $port >= 1 && $port <= 65535;
    }

    function configGetPasswordValue(string $postField, string $fallback): string {
        $raw = (string)($_POST[$postField] ?? '');
        if ($raw === '') {
            return $fallback;
        }
        return configNormalizeEnvValue($raw);
    }

    function configNormalizeMailSecureToUi(string $value): ?string {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }
        if ($normalized === 'tls' || $normalized === 'phpmailer::encryption_starttls') {
            return 'tls';
        }
        if ($normalized === 'ssl' || $normalized === 'phpmailer::encryption_smtps') {
            return 'ssl';
        }
        return null;
    }

    function configMapMailSecureToEnv(string $uiValue): string {
        $normalizedUi = configNormalizeMailSecureToUi($uiValue);
        if ($normalizedUi === 'tls') {
            return 'PHPMailer::ENCRYPTION_STARTTLS';
        }
        if ($normalizedUi === 'ssl') {
            return 'PHPMailer::ENCRYPTION_SMTPS';
        }
        return '';
    }

    function configMapMailSecureToPhpMailer(string $uiValue): string {
        $normalizedUi = configNormalizeMailSecureToUi($uiValue);
        if ($normalizedUi === 'tls') {
            return \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        if ($normalizedUi === 'ssl') {
            return \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        }
        return '';
    }

    function configSetFeedback(string $section, bool $ok, string $message, array $formData = []): void {
        if (!isset($_SESSION['configuration_feedback']) || !is_array($_SESSION['configuration_feedback'])) {
            $_SESSION['configuration_feedback'] = [];
        }
        if (!isset($_SESSION['configuration_form_data']) || !is_array($_SESSION['configuration_form_data'])) {
            $_SESSION['configuration_form_data'] = [];
        }

        $_SESSION['configuration_feedback'][$section] = [
            'ok' => $ok,
            'message' => $message
        ];
        if (!empty($formData)) {
            $_SESSION['configuration_form_data'][$section] = $formData;
        }
    }

    function configReadAndClearFeedback(): array {
        $feedback = $_SESSION['configuration_feedback'] ?? [];
        $formData = $_SESSION['configuration_form_data'] ?? [];
        unset($_SESSION['configuration_feedback'], $_SESSION['configuration_form_data']);
        return [
            'feedback' => is_array($feedback) ? $feedback : [],
            'form_data' => is_array($formData) ? $formData : []
        ];
    }

    function configGitRepoPath(): string {
        return __DIR__;
    }

    function configRunGitCommand(array $arguments, ?int &$exitCode = null): string {
        $command = ['git', '-C', configGitRepoPath()];
        foreach ($arguments as $argument) {
            $command[] = (string)$argument;
        }

        $escaped = array_map('escapeshellarg', $command);
        $output = [];
        exec(implode(' ', $escaped) . ' 2>&1', $output, $commandExitCode);
        $exitCode = $commandExitCode;
        return trim(implode("\n", $output));
    }

    function configUpdateRollbackRef(): string {
        return 'refs/portflow-updater/pre-update';
    }

    function configWriteUpdaterState(array $state): bool {
        return portflow_write_state_file(portflow_updater_state_path(), $state);
    }

    function configReadUpdaterState(): array {
        $state = portflow_get_updater_state();
        return is_array($state) ? $state : [];
    }

    function configClearUpdaterState(): bool {
        return portflow_delete_state_file(portflow_updater_state_path());
    }

    function configGetRollbackCandidate(array $updaterState): array {
        $candidate = $updaterState['rollback_candidate'] ?? [];
        return is_array($candidate) ? $candidate : [];
    }

    function configGetUpdateStatus(bool $refreshRemote = false): array {
        $status = [
            'ok' => false,
            'repo_available' => false,
            'repo_path' => configGitRepoPath(),
            'branch' => '',
            'upstream' => '',
            'current_commit' => '',
            'current_version' => '',
            'remote_commit' => '',
            'remote_version' => '',
            'behind_count' => 0,
            'ahead_count' => 0,
            'updates_available' => false,
            'working_tree_dirty' => false,
            'last_checked_at' => '',
            'message' => '',
        ];

        if (!is_dir(configGitRepoPath() . '/.git')) {
            $status['message'] = 'Kein Git-Repository im Portflow-Verzeichnis gefunden.';
            return $status;
        }

        $insideWorkTree = configRunGitCommand(['rev-parse', '--is-inside-work-tree'], $exitCode);
        if ($exitCode !== 0 || trim($insideWorkTree) !== 'true') {
            $status['message'] = 'Portflow ist kein gueltiges Git-Repository.';
            return $status;
        }

        $status['repo_available'] = true;
        $status['branch'] = configRunGitCommand(['rev-parse', '--abbrev-ref', 'HEAD'], $exitCode);
        $status['current_commit'] = configRunGitCommand(['rev-parse', '--short', 'HEAD'], $exitCode);
        $status['current_version'] = configRunGitCommand(['describe', '--tags', '--always', '--dirty'], $exitCode);
        $statusOutput = configRunGitCommand(['status', '--porcelain'], $exitCode);
        $status['working_tree_dirty'] = trim($statusOutput) !== '';

        $upstream = configRunGitCommand(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], $exitCode);
        if ($exitCode === 0) {
            $status['upstream'] = $upstream;
        }

        if ($refreshRemote && $status['upstream'] !== '') {
            $remoteName = strstr($status['upstream'], '/', true);
            if ($remoteName === false || $remoteName === '') {
                $remoteName = 'origin';
            }

            configRunGitCommand(['fetch', '--quiet', '--tags', $remoteName], $fetchExitCode);
            if ($fetchExitCode !== 0) {
                $status['message'] = 'Git-Fetch fehlgeschlagen. Bitte Netzwerk und Remote pruefen.';
                $status['last_checked_at'] = date('Y-m-d H:i:s');
                return $status;
            }
        }

        if ($status['upstream'] !== '') {
            $counts = configRunGitCommand(['rev-list', '--left-right', '--count', 'HEAD...' . $status['upstream']], $exitCode);
            if ($exitCode === 0 && preg_match('/^(\d+)\s+(\d+)$/', $counts, $matches)) {
                $status['ahead_count'] = (int)$matches[1];
                $status['behind_count'] = (int)$matches[2];
            }

            $status['remote_commit'] = configRunGitCommand(['rev-parse', '--short', $status['upstream']], $exitCode);
            $status['remote_version'] = configRunGitCommand(['describe', '--tags', '--always', $status['upstream']], $exitCode);
            $status['updates_available'] = $status['behind_count'] > 0;

            if ($status['updates_available']) {
                $status['message'] = 'Es sind ' . $status['behind_count'] . ' neue Commits verfuegbar.';
            } elseif ($status['ahead_count'] > 0) {
                $status['message'] = 'Der lokale Stand ist ' . $status['ahead_count'] . ' Commits vor dem Upstream.';
            } else {
                $status['message'] = 'Portflow ist auf dem aktuellen Stand.';
            }
        } else {
            $status['message'] = 'Kein Tracking-Branch konfiguriert. Update-Pruefung nur lokal moeglich.';
        }

        if ($status['working_tree_dirty']) {
            $status['message'] .= ' Es gibt lokale, nicht committete Aenderungen.';
        }

        $status['ok'] = true;
        $status['last_checked_at'] = date('Y-m-d H:i:s');
        return $status;
    }

    function configExecuteUpdate(): array {
        $statusBefore = configGetUpdateStatus(true);
        $existingState = configReadUpdaterState();
        $existingRollbackCandidate = configGetRollbackCandidate($existingState);

        if (!$statusBefore['ok']) {
            return $statusBefore + ['message' => 'Update nicht moeglich: ' . (string)($statusBefore['message'] ?? 'unbekannter Fehler')];
        }

        if (!$statusBefore['repo_available']) {
            return $statusBefore + ['ok' => false, 'message' => 'Update nicht moeglich: kein Git-Repository gefunden.'];
        }

        if ($statusBefore['upstream'] === '') {
            return $statusBefore + ['ok' => false, 'message' => 'Update nicht moeglich: kein Tracking-Branch konfiguriert.'];
        }

        if (!empty($statusBefore['working_tree_dirty'])) {
            return $statusBefore + ['ok' => false, 'message' => 'Update abgebrochen: es gibt lokale, nicht committete Aenderungen.'];
        }

        if ((int)($statusBefore['behind_count'] ?? 0) < 1) {
            return $statusBefore + ['ok' => true, 'message' => 'Kein Update erforderlich. Portflow ist bereits aktuell.'];
        }

        $currentBranch = trim((string)($statusBefore['branch'] ?? ''));
        $upstream = trim((string)($statusBefore['upstream'] ?? ''));
        $previousVersion = trim((string)($statusBefore['current_version'] ?? ''));
        $targetVersion = trim((string)($statusBefore['remote_version'] ?? ''));
        $previousCommit = trim((string)($statusBefore['current_commit'] ?? ''));
        $targetCommit = trim((string)($statusBefore['remote_commit'] ?? ''));
        $rollbackRef = configUpdateRollbackRef();

        $updateState = [
            'status' => 'running',
            'operation' => 'update',
            'started_at' => date('Y-m-d H:i:s'),
            'branch' => $currentBranch,
            'upstream' => $upstream,
            'previous_version' => $previousVersion,
            'previous_commit' => $previousCommit,
            'target_version' => $targetVersion,
            'target_commit' => $targetCommit,
            'rollback_candidate' => $existingRollbackCandidate,
        ];

        if (!portflow_enable_maintenance_mode([
            'message' => 'Ein System-Update wird angewendet. Portflow ist fuer kurze Zeit nicht verfuegbar.',
            'branch' => $currentBranch,
            'target_version' => $targetVersion,
            'target_commit' => $targetCommit,
        ])) {
            return $statusBefore + ['ok' => false, 'message' => 'Update nicht moeglich: Wartungsmodus konnte nicht aktiviert werden.'];
        }

        configWriteUpdaterState($updateState);

        $maintenanceDisableFailed = false;

        try {
            configRunGitCommand(['update-ref', $rollbackRef, 'HEAD'], $exitCode);
            if ($exitCode !== 0) {
                return $statusBefore + ['ok' => false, 'message' => 'Update fehlgeschlagen: der Rollback-Referenzpunkt konnte nicht erstellt werden.'];
            }

            configRunGitCommand(['checkout', $currentBranch], $exitCode);
            if ($exitCode !== 0) {
                return $statusBefore + ['ok' => false, 'message' => 'Update fehlgeschlagen: Branch ' . $currentBranch . ' konnte nicht ausgecheckt werden.'];
            }

            configRunGitCommand(['reset', '--hard', $upstream], $exitCode);
            if ($exitCode !== 0) {
                return $statusBefore + ['ok' => false, 'message' => 'Update fehlgeschlagen: Git-Reset auf ' . $upstream . ' war nicht erfolgreich.'];
            }

            $dbAdapter = new \Portflow\Core\DatabaseAdapter();
            $pendingSchemaChanges = $dbAdapter->getPendingSchemaChanges();
            $schemaUpdateNeeded = !empty($pendingSchemaChanges['missing_tables'])
                || !empty($pendingSchemaChanges['missing_columns'])
                || !empty($pendingSchemaChanges['missing_views'])
                || !empty($pendingSchemaChanges['outdated_views']);

            if ($schemaUpdateNeeded) {
                $dbAdapter->db_update_schema();
            }

            $statusAfter = configGetUpdateStatus(false);
            $statusAfter['ok'] = true;
            $statusAfter['message'] = 'Update erfolgreich: ' . ($previousVersion !== '' ? $previousVersion : 'alter Stand unbekannt') . ' -> ' . ($targetVersion !== '' ? $targetVersion : ($statusAfter['current_version'] ?? 'neuer Stand unbekannt')) . ($schemaUpdateNeeded
                ? '. Datenbankschema wurde aktualisiert.'
                : '. Kein Datenbank-Upgrade erforderlich.');
            $statusAfter['previous_version'] = $previousVersion;
            $statusAfter['previous_commit'] = $previousCommit;
            $statusAfter['target_version'] = $targetVersion;
            $statusAfter['target_commit'] = $targetCommit;
            $statusAfter['schema_update_needed'] = $schemaUpdateNeeded;
            $statusAfter['pending_schema_changes'] = $pendingSchemaChanges;
            configWriteUpdaterState($updateState + [
                'status' => 'success',
                'finished_at' => date('Y-m-d H:i:s'),
                'message' => $statusAfter['message'],
                'schema_update_needed' => $schemaUpdateNeeded,
                'pending_schema_changes' => $pendingSchemaChanges,
                'rollback_candidate' => [
                    'available' => true,
                    'commit' => $previousCommit,
                    'version' => $previousVersion,
                    'from_commit' => $targetCommit,
                    'from_version' => $targetVersion,
                    'recorded_at' => date('Y-m-d H:i:s'),
                ],
            ]);
            return $statusAfter;
        } catch (\Throwable $e) {
            $rollbackResetOutput = configRunGitCommand(['reset', '--hard', $previousCommit], $rollbackExitCode);
            $rollbackSuccessful = ($rollbackExitCode === 0);
            $rolledBackStatus = configGetUpdateStatus(false);
            $rolledBackStatus['ok'] = false;
            $rolledBackStatus['previous_version'] = $previousVersion;
            $rolledBackStatus['previous_commit'] = $previousCommit;
            $rolledBackStatus['target_version'] = $targetVersion;
            $rolledBackStatus['target_commit'] = $targetCommit;
            if ($rollbackSuccessful) {
                $rolledBackStatus['message'] = 'Update fehlgeschlagen: Die Datenbankmigration konnte nicht abgeschlossen werden (' . $e->getMessage() . '). Der Code wurde auf ' . ($previousVersion !== '' ? $previousVersion : $previousCommit) . ' zurueckgesetzt. Bereits ausgefuehrte Datenbankaenderungen muessen ggf. manuell geprueft werden.';
            } else {
                $rolledBackStatus['message'] = 'Update fehlgeschlagen: Die Datenbankmigration konnte nicht abgeschlossen werden (' . $e->getMessage() . ') und der automatische Code-Rollback ist ebenfalls fehlgeschlagen (' . trim($rollbackResetOutput) . '). Bitte System manuell pruefen.';
            }

            configWriteUpdaterState($updateState + [
                'status' => $rollbackSuccessful ? 'rolled_back' : 'failed',
                'finished_at' => date('Y-m-d H:i:s'),
                'message' => $rolledBackStatus['message'],
                'rollback_attempted' => true,
                'rollback_successful' => $rollbackSuccessful,
                'rollback_candidate' => $existingRollbackCandidate,
            ]);
            return $rolledBackStatus;
        } finally {
            configRunGitCommand(['update-ref', '-d', $rollbackRef], $cleanupExitCode);
            if (!portflow_disable_maintenance_mode()) {
                $maintenanceDisableFailed = true;
            }
            if ($maintenanceDisableFailed) {
                configWriteUpdaterState([
                    'status' => 'warning',
                    'operation' => 'update',
                    'finished_at' => date('Y-m-d H:i:s'),
                    'message' => 'Wartungsmodus konnte nach dem Update nicht deaktiviert werden. Bitte maintenance.json manuell pruefen.',
                    'rollback_candidate' => $existingRollbackCandidate,
                ]);
            }
        }
    }

    function configExecuteManualRollback(): array {
        $statusBefore = configGetUpdateStatus(false);
        $updaterState = configReadUpdaterState();
        $rollbackCandidate = configGetRollbackCandidate($updaterState);

        if (!$statusBefore['ok']) {
            return $statusBefore + ['message' => 'Rollback nicht moeglich: ' . (string)($statusBefore['message'] ?? 'unbekannter Fehler')];
        }

        if (empty($rollbackCandidate['available']) || empty($rollbackCandidate['commit'])) {
            return $statusBefore + ['ok' => false, 'message' => 'Rollback nicht moeglich: kein gespeicherter Ruecksetzpunkt verfuegbar.'];
        }

        if (!empty($statusBefore['working_tree_dirty'])) {
            return $statusBefore + ['ok' => false, 'message' => 'Rollback abgebrochen: es gibt lokale, nicht committete Aenderungen.'];
        }

        $targetCommit = trim((string)$rollbackCandidate['commit']);
        $targetVersion = trim((string)($rollbackCandidate['version'] ?? $targetCommit));
        $currentVersion = trim((string)($statusBefore['current_version'] ?? ''));
        $currentCommit = trim((string)($statusBefore['current_commit'] ?? ''));

        configRunGitCommand(['rev-parse', '--verify', $targetCommit . '^{commit}'], $verifyExitCode);
        if ($verifyExitCode !== 0) {
            return $statusBefore + ['ok' => false, 'message' => 'Rollback nicht moeglich: der gespeicherte Commit ' . $targetCommit . ' existiert lokal nicht mehr.'];
        }

        if (!portflow_enable_maintenance_mode([
            'message' => 'Ein manueller System-Rollback wird angewendet. Portflow ist fuer kurze Zeit nicht verfuegbar.',
            'target_version' => $targetVersion,
            'target_commit' => $targetCommit,
        ])) {
            return $statusBefore + ['ok' => false, 'message' => 'Rollback nicht moeglich: Wartungsmodus konnte nicht aktiviert werden.'];
        }

        try {
            configWriteUpdaterState($updaterState + [
                'status' => 'running',
                'operation' => 'manual_rollback',
                'started_at' => date('Y-m-d H:i:s'),
                'message' => 'Manueller Rollback auf ' . $targetVersion . ' wird ausgefuehrt.',
            ]);

            configRunGitCommand(['reset', '--hard', $targetCommit], $rollbackExitCode);
            if ($rollbackExitCode !== 0) {
                return $statusBefore + ['ok' => false, 'message' => 'Rollback fehlgeschlagen: Git-Reset auf ' . $targetCommit . ' war nicht erfolgreich.'];
            }

            $statusAfter = configGetUpdateStatus(false);
            $statusAfter['ok'] = true;
            $statusAfter['message'] = 'Rollback erfolgreich: ' . ($currentVersion !== '' ? $currentVersion : $currentCommit) . ' -> ' . $targetVersion . '. Datenbankaenderungen werden nicht automatisch zurueckgenommen und muessen manuell geprueft werden.';
            $statusAfter['previous_version'] = $currentVersion;
            $statusAfter['previous_commit'] = $currentCommit;
            $statusAfter['target_version'] = $targetVersion;
            $statusAfter['target_commit'] = $targetCommit;
            configWriteUpdaterState($updaterState + [
                'status' => 'manual_rollback_success',
                'operation' => 'manual_rollback',
                'finished_at' => date('Y-m-d H:i:s'),
                'message' => $statusAfter['message'],
                'last_manual_rollback' => [
                    'from_commit' => $currentCommit,
                    'from_version' => $currentVersion,
                    'to_commit' => $targetCommit,
                    'to_version' => $targetVersion,
                    'recorded_at' => date('Y-m-d H:i:s'),
                ],
                'rollback_candidate' => $rollbackCandidate,
            ]);
            return $statusAfter;
        } finally {
            portflow_disable_maintenance_mode();
        }
    }

    function configWriteEnvValues(array $updates): array {
        $envPath = __DIR__ . '/.env';
        if (!file_exists($envPath)) {
            return ['ok' => false, 'message' => '.env wurde nicht gefunden.'];
        }
        if (!is_readable($envPath) || !is_writable($envPath)) {
            return ['ok' => false, 'message' => '.env ist nicht lesbar oder nicht schreibbar.'];
        }

        $content = file_get_contents($envPath);
        if (!is_string($content)) {
            return ['ok' => false, 'message' => '.env konnte nicht gelesen werden.'];
        }

        $lines = preg_split('/\R/', $content);
        if (!is_array($lines)) {
            $lines = [];
        }

        $normalizedUpdates = [];
        foreach ($updates as $key => $value) {
            $normalizedKey = strtoupper(trim((string)$key));
            if ($normalizedKey === '') {
                continue;
            }
            $normalizedUpdates[$normalizedKey] = configNormalizeEnvValue((string)$value);
        }

        if (empty($normalizedUpdates)) {
            return ['ok' => false, 'message' => 'Keine gueltigen Einstellungen zum Speichern uebergeben.'];
        }

        $found = [];
        foreach ($lines as $idx => $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $matches) === 1) {
                $lineKey = strtoupper((string)$matches[1]);
                if (array_key_exists($lineKey, $normalizedUpdates)) {
                    $lines[$idx] = $lineKey . '=' . $normalizedUpdates[$lineKey];
                    $found[$lineKey] = true;
                }
            }
        }

        foreach ($normalizedUpdates as $lineKey => $lineValue) {
            if (!isset($found[$lineKey])) {
                $lines[] = $lineKey . '=' . $lineValue;
            }
        }

        $newContent = implode(PHP_EOL, $lines) . PHP_EOL;
        $tempPath = $envPath . '.tmp';
        $backupPath = $envPath . '.bak.' . date('YmdHis');

        if (@copy($envPath, $backupPath) === false) {
            return ['ok' => false, 'message' => '.env Backup konnte nicht erstellt werden.'];
        }
        @chmod($backupPath, 0600);

        if (file_put_contents($tempPath, $newContent, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Temporare .env Datei konnte nicht geschrieben werden.'];
        }
        @chmod($tempPath, 0600);

        if (!@rename($tempPath, $envPath)) {
            @unlink($tempPath);
            return ['ok' => false, 'message' => '.env konnte nicht atomar ersetzt werden.'];
        }

        @chmod($envPath, 0600);

        return ['ok' => true, 'message' => 'Einstellungen wurden gespeichert.'];
    }

    function configBuildDbFormData(): array {
        return [
            'db_type' => configNormalizeEnvValue((string)($_POST['db_type'] ?? DB_TYPE)),
            'db_server' => configNormalizeEnvValue((string)($_POST['db_server'] ?? DB_SERVER)),
            'db_port' => configNormalizeEnvValue((string)($_POST['db_port'] ?? DB_PORT)),
            'db_name' => configNormalizeEnvValue((string)($_POST['db_name'] ?? DB_NAME)),
            'db_user' => configNormalizeEnvValue((string)($_POST['db_user'] ?? DB_USER))
        ];
    }

    function configBuildLdapFormData(): array {
        return [
            'ldap_enabled' => configBoolFromPost('ldap_enabled') ? '1' : '0',
            'ldap_server' => configNormalizeEnvValue((string)($_POST['ldap_server'] ?? LDAP_SERVER)),
            'ldap_port' => configNormalizeEnvValue((string)($_POST['ldap_port'] ?? LDAP_PORT)),
            'ldap_basedn' => configNormalizeEnvValue((string)($_POST['ldap_basedn'] ?? LDAP_BASEDN)),
            'ldap_userdn' => configNormalizeEnvValue((string)($_POST['ldap_userdn'] ?? LDAP_USERDN)),
            'ldap_filter' => configNormalizeEnvValue((string)($_POST['ldap_filter'] ?? LDAP_FILTER)),
            'ldap_bind' => configBoolFromPost('ldap_bind') ? '1' : '0',
            'ldap_bind_user' => configNormalizeEnvValue((string)($_POST['ldap_bind_user'] ?? LDAP_BIND_USER)),
            'ldap_trust' => configBoolFromPost('ldap_trust') ? '1' : '0'
        ];
    }

    function configBuildMailFormData(): array {
        $mailSecureRaw = configNormalizeEnvValue((string)($_POST['mail_smtpsecure'] ?? MAIL_SMTPSECURE));
        $mailSecureUi = configNormalizeMailSecureToUi($mailSecureRaw);
        if ($mailSecureUi === null) {
            $mailSecureUi = '';
        }

        return [
            'mail_host' => configNormalizeEnvValue((string)($_POST['mail_host'] ?? MAIL_HOST)),
            'mail_user' => configNormalizeEnvValue((string)($_POST['mail_user'] ?? MAIL_USER)),
            'mail_port' => configNormalizeEnvValue((string)($_POST['mail_port'] ?? MAIL_PORT)),
            'mail_smtpauth' => configBoolFromPost('mail_smtpauth') ? '1' : '0',
            'mail_smtpsecure' => $mailSecureUi
        ];
    }

    function configTestDatabase(array $formData): array {
        $dbType = strtolower(trim((string)($formData['db_type'] ?? '')));
        $dbServer = trim((string)($formData['db_server'] ?? ''));
        $dbPort = (int)($formData['db_port'] ?? 0);
        $dbName = trim((string)($formData['db_name'] ?? ''));
        $dbUser = trim((string)($formData['db_user'] ?? ''));
        $dbPassword = (string)($formData['db_password'] ?? '');

        if (!in_array($dbType, ['pgsql', 'mysql'], true)) {
            return ['ok' => false, 'message' => 'Ungueltiger DB-Typ. Erlaubt: pgsql oder mysql.'];
        }
        if ($dbServer === '' || $dbName === '' || $dbUser === '') {
            return ['ok' => false, 'message' => 'DB-Server, DB-Name und DB-User sind Pflichtfelder.'];
        }
        if (!configRequirePortRange($dbPort)) {
            return ['ok' => false, 'message' => 'DB-Port muss zwischen 1 und 65535 liegen.'];
        }
        if (!isValidAutomationInventoryHost($dbServer)) {
            return ['ok' => false, 'message' => 'DB-Server enthaelt ungueltige Zeichen.'];
        }

        try {
            $dsn = $dbType . ':host=' . $dbServer . ';port=' . $dbPort . ';dbname=' . $dbName;
            $pdo = new \PDO($dsn, $dbUser, $dbPassword, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 8
            ]);
            $stmt = $pdo->query('SELECT 1');
            $stmt->fetchColumn();
            return ['ok' => true, 'message' => 'DB-Test erfolgreich. Verbindung hergestellt.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'DB-Test fehlgeschlagen: ' . $e->getMessage()];
        }
    }

    function configGetFileModeString(string $path): string {
        $permissions = @fileperms($path);
        if ($permissions === false) {
            return 'unbekannt';
        }

        return substr(sprintf('%o', $permissions), -4);
    }

    function configGetSecurityProbeBaseUrl(): ?string {
        $requestHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($requestHost !== '') {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
            return ($isHttps ? 'https://' : 'http://') . $requestHost;
        }

        $configuredHost = trim((string)(defined('PORTFLOW_HOSTNAME') ? PORTFLOW_HOSTNAME : ''));
        if ($configuredHost !== '' && preg_match('#^https?://#i', $configuredHost) === 1) {
            return rtrim($configuredHost, '/');
        }

        return null;
    }

    function configProbeProtectedPath(string $path): array {
        $baseUrl = configGetSecurityProbeBaseUrl();
        if ($baseUrl === null) {
            return [
                'path' => $path,
                'status_code' => null,
                'severity' => 'warn',
                'message' => 'Pruefung nicht moeglich: Basis-URL konnte nicht ermittelt werden.'
            ];
        }

        $url = $baseUrl . $path;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 4,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => "User-Agent: Portflow-Security-Check\r\nAccept: */*\r\nConnection: close\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $headers = @get_headers($url, false, $context);
        if (!is_array($headers) || empty($headers[0])) {
            return [
                'path' => $path,
                'status_code' => null,
                'severity' => 'warn',
                'message' => 'Pruefung nicht moeglich: Keine HTTP-Antwort von ' . $url . '.'
            ];
        }

        $statusLine = (string)$headers[0];
        $statusCode = preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1 ? (int)$matches[1] : null;
        if ($statusCode === null) {
            return [
                'path' => $path,
                'status_code' => null,
                'severity' => 'warn',
                'message' => 'Pruefung unklar: HTTP-Status konnte fuer ' . $url . ' nicht gelesen werden.'
            ];
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return [
                'path' => $path,
                'status_code' => $statusCode,
                'severity' => 'critical',
                'message' => 'Pfad antwortet mit HTTP ' . $statusCode . ' und wirkt oeffentlich erreichbar.'
            ];
        }

        if (in_array($statusCode, [401, 403, 404], true)) {
            return [
                'path' => $path,
                'status_code' => $statusCode,
                'severity' => 'ok',
                'message' => 'Pfad ist nicht direkt oeffentlich erreichbar (HTTP ' . $statusCode . ').'
            ];
        }

        if ($statusCode >= 300 && $statusCode < 400) {
            return [
                'path' => $path,
                'status_code' => $statusCode,
                'severity' => 'warn',
                'message' => 'Pfad liefert einen Redirect (HTTP ' . $statusCode . '). Direkte Sperre waere eindeutiger.'
            ];
        }

        return [
            'path' => $path,
            'status_code' => $statusCode,
            'severity' => 'warn',
            'message' => 'Pfad liefert unerwarteten HTTP-Status ' . $statusCode . '. Bitte Webserver-Regeln pruefen.'
        ];
    }

    function getSchedulerCronStatus(string $applicationDir): array {
        $cronPath = '/etc/cron.d/portflow';
        $schedulerPath = rtrim($applicationDir, '/') . '/scheduler.php';

        if (!is_file($cronPath)) {
            return ['ok' => false, 'label' => 'Missing'];
        }

        if (!is_readable($cronPath)) {
            return ['ok' => true, 'label' => 'Present (not readable)'];
        }

        $content = @file_get_contents($cronPath);
        if (!is_string($content) || trim($content) === '') {
            return ['ok' => false, 'label' => 'Present but empty'];
        }

        if (strpos($content, '$php_bin') !== false) {
            return ['ok' => false, 'label' => 'Present but contains unresolved php path'];
        }

        if (strpos($content, $schedulerPath) === false) {
            return ['ok' => false, 'label' => 'Present but points elsewhere'];
        }

        return ['ok' => true, 'label' => 'Installed'];
    }

    function configBuildSystemSecurityCheck(): array {
        $items = [];
        $summary = ['critical' => 0, 'warn' => 0, 'ok' => 0, 'info' => 0];

        $addItem = static function (array $item) use (&$items, &$summary): void {
            $severity = (string)($item['severity'] ?? 'info');
            if (!isset($summary[$severity])) {
                $severity = 'info';
                $item['severity'] = $severity;
            }
            $summary[$severity]++;
            $items[] = $item;
        };

        $envPath = __DIR__ . '/.env';
        if (!is_file($envPath)) {
            $addItem([
                'severity' => 'critical',
                'title' => '.env Datei',
                'message' => '.env wurde nicht gefunden.',
                'fix' => 'Setup erneut abschliessen oder .env aus einem gueltigen Backup wiederherstellen.'
            ]);
        } else {
            $envMode = @fileperms($envPath);
            $envModeString = configGetFileModeString($envPath);
            $envReadableByGroupOrWorld = is_int($envMode) && (($envMode & 0x0024) !== 0);
            $envWritableByGroupOrWorld = is_int($envMode) && (($envMode & 0x0012) !== 0 || ($envMode & 0x0002) === 0x0002);
            $envSeverity = 'ok';
            $envMessage = '.env Rechte sehen plausibel aus (' . $envModeString . ').';
            $envFix = 'Empfohlen sind restriktive Rechte wie 0600.';

            if ($envReadableByGroupOrWorld || $envWritableByGroupOrWorld) {
                $envSeverity = 'critical';
                $envMessage = '.env hat zu offene Rechte (' . $envModeString . ').';
                $envFix = 'Datei auf 0600 begrenzen und Owner des Webserver-Users pruefen.';
            } elseif (!is_readable($envPath) || !is_writable($envPath)) {
                $envSeverity = 'warn';
                $envMessage = '.env ist vorhanden, aber fuer Portflow nicht durchgaengig les- und schreibbar.';
                $envFix = 'Owner und Rechte so setzen, dass der Webserver lesen und Einstellungen sicher schreiben kann.';
            }

            $addItem([
                'severity' => $envSeverity,
                'title' => '.env Rechte',
                'message' => $envMessage,
                'fix' => $envFix
            ]);
        }

        $dataPath = __DIR__ . '/data';
        if (is_dir($dataPath)) {
            $dataMode = @fileperms($dataPath);
            $dataModeString = configGetFileModeString($dataPath);
            if (is_int($dataMode) && (($dataMode & 0x0002) === 0x0002)) {
                $addItem([
                    'severity' => 'warn',
                    'title' => 'data Verzeichnis-Rechte',
                    'message' => 'Das data Verzeichnis ist fuer andere beschreibbar (' . $dataModeString . ').',
                    'fix' => 'Rechte auf einen restriktiveren Modus wie 0750 oder 0770 reduzieren.'
                ]);
            } else {
                $addItem([
                    'severity' => 'ok',
                    'title' => 'data Verzeichnis-Rechte',
                    'message' => 'Das data Verzeichnis ist vorhanden (' . $dataModeString . ').',
                    'fix' => 'Fuer Laufzeitdaten restriktive Rechte beibehalten.'
                ]);
            }
        } else {
            $addItem([
                'severity' => 'warn',
                'title' => 'data Verzeichnis',
                'message' => 'Das Laufzeitverzeichnis data fehlt.',
                'fix' => 'Installer erneut ausfuehren oder die benoetigten Datenverzeichnisse anlegen.'
            ]);
        }

        $serverSoftware = trim((string)($_SERVER['SERVER_SOFTWARE'] ?? 'unbekannt'));
        $serverSoftwareLower = strtolower($serverSoftware);
        $htaccessMode = is_file(__DIR__ . '/.htaccess') ? 'vorhanden' : 'nicht vorhanden';
        if (strpos($serverSoftwareLower, 'apache') !== false) {
            $addItem([
                'severity' => is_file(__DIR__ . '/.htaccess') ? 'ok' : 'warn',
                'title' => '.htaccess / Apache',
                'message' => is_file(__DIR__ . '/.htaccess')
                    ? 'Apache erkannt, Root-.htaccess ist vorhanden.'
                    : 'Apache erkannt, aber Root-.htaccess fehlt.',
                'fix' => 'Sicherstellen, dass AllowOverride aktiv ist und die Apache-Regeln fuer sensible Pfade geladen werden.'
            ]);
        } else {
            $addItem([
                'severity' => 'info',
                'title' => '.htaccess / Webserver',
                'message' => 'Aktiver Webserver: ' . $serverSoftware . '. Root-.htaccess ist ' . $htaccessMode . ' und wird ausserhalb von Apache nicht ausgewertet.',
                'fix' => 'Sensible Pfade zusaetzlich in Lighttpd- oder Nginx-Konfiguration sperren.'
            ]);
        }

        foreach ([
            '/.env' => 'Webzugriff auf .env',
            '/.git/HEAD' => 'Webzugriff auf .git/HEAD',
            '/.htaccess' => 'Webzugriff auf .htaccess',
            '/data/' => 'Webzugriff auf data/'
        ] as $probePath => $title) {
            $probe = configProbeProtectedPath($probePath);
            $addItem([
                'severity' => $probe['severity'],
                'title' => $title,
                'message' => $probe['message'],
                'fix' => 'Direkten Webzugriff serverseitig blockieren. Erwartet sind 403 oder 404 fuer diesen Pfad.'
            ]);
        }

        $schedulerStatus = getSchedulerCronStatus(__DIR__);
        $addItem([
            'severity' => !empty($schedulerStatus['ok']) ? 'ok' : 'warn',
            'title' => 'Scheduler Cronjob',
            'message' => !empty($schedulerStatus['ok'])
                ? 'Cronjob ist vorhanden: ' . (string)($schedulerStatus['label'] ?? 'Installed') . '.'
                : 'Cronjob-Auffaelligkeit: ' . (string)($schedulerStatus['label'] ?? 'Missing') . '.',
            'fix' => 'Installer erneut ausfuehren oder /etc/cron.d/portflow auf den aktuellen scheduler.php Pfad ausrichten.'
        ]);

        return [
            'summary' => $summary,
            'items' => $items,
            'checked_at' => date('Y-m-d H:i:s'),
            'base_url' => configGetSecurityProbeBaseUrl(),
        ];
    }

    function configTestLdap(array $formData): array {
        $ldapServer = trim((string)($formData['ldap_server'] ?? ''));
        $ldapPort = (int)($formData['ldap_port'] ?? 0);
        $ldapBaseDn = trim((string)($formData['ldap_basedn'] ?? ''));
        $ldapBind = configToBool($formData['ldap_bind'] ?? false);
        $ldapBindUser = trim((string)($formData['ldap_bind_user'] ?? ''));
        $ldapBindPassword = (string)($formData['ldap_bind_password'] ?? '');

        if (!extension_loaded('ldap')) {
            return ['ok' => false, 'message' => 'LDAP-Test nicht moeglich: PHP LDAP Modul fehlt.'];
        }
        if ($ldapServer === '' || $ldapBaseDn === '') {
            return ['ok' => false, 'message' => 'LDAP-Server und LDAP-BaseDN sind fuer den Test erforderlich.'];
        }
        if (!configRequirePortRange($ldapPort)) {
            return ['ok' => false, 'message' => 'LDAP-Port muss zwischen 1 und 65535 liegen.'];
        }
        if (!isValidAutomationInventoryHost($ldapServer)) {
            return ['ok' => false, 'message' => 'LDAP-Server enthaelt ungueltige Zeichen.'];
        }
        if ($ldapBind && $ldapBindUser === '') {
            return ['ok' => false, 'message' => 'LDAP Bind User ist erforderlich, wenn LDAP Bind aktiv ist.'];
        }

        $link = @ldap_connect($ldapServer, $ldapPort);
        if ($link === false) {
            return ['ok' => false, 'message' => 'LDAP-Test fehlgeschlagen: Verbindung konnte nicht aufgebaut werden.'];
        }

        @ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($link, LDAP_OPT_REFERRALS, 0);

        $bindOk = false;
        if ($ldapBind) {
            $bindOk = @ldap_bind($link, $ldapBindUser, $ldapBindPassword);
        } else {
            $bindOk = @ldap_bind($link);
        }

        if (!$bindOk) {
            $error = ldap_error($link);
            @ldap_unbind($link);
            return ['ok' => false, 'message' => 'LDAP-Test fehlgeschlagen: Bind nicht erfolgreich (' . $error . ').'];
        }

        $searchResult = @ldap_search($link, $ldapBaseDn, '(objectClass=*)', ['dn'], 0, 1, 5);
        if ($searchResult === false) {
            $error = ldap_error($link);
            @ldap_unbind($link);
            return ['ok' => false, 'message' => 'LDAP-Test fehlgeschlagen: Suche nicht moeglich (' . $error . ').'];
        }

        @ldap_unbind($link);
        return ['ok' => true, 'message' => 'LDAP-Test erfolgreich. Verbindung, Bind und Suchtest sind erfolgreich.'];
    }

    function configTestMail(array $formData): array {
        $mailHost = trim((string)($formData['mail_host'] ?? ''));
        $mailUser = trim((string)($formData['mail_user'] ?? ''));
        $mailPassword = (string)($formData['mail_password'] ?? '');
        $mailPort = (int)($formData['mail_port'] ?? 0);
        $mailAuth = configToBool($formData['mail_smtpauth'] ?? false);
        $mailSecureUi = (string)($formData['mail_smtpsecure'] ?? '');
        $mailSecure = configNormalizeMailSecureToUi($mailSecureUi);

        if ($mailHost === '') {
            return ['ok' => false, 'message' => 'MAIL_HOST ist erforderlich.'];
        }
        if (!isValidAutomationInventoryHost($mailHost)) {
            return ['ok' => false, 'message' => 'MAIL_HOST enthaelt ungueltige Zeichen.'];
        }
        if (!configRequirePortRange($mailPort)) {
            return ['ok' => false, 'message' => 'MAIL_PORT muss zwischen 1 und 65535 liegen.'];
        }
        if ($mailSecure === null) {
            return ['ok' => false, 'message' => 'MAIL_SMTPSECURE darf nur leer, tls oder ssl sein.'];
        }
        if ($mailAuth && ($mailUser === '' || $mailPassword === '')) {
            return ['ok' => false, 'message' => 'MAIL_USER und MAIL_PASSWORD sind bei aktivem SMTPAuth erforderlich.'];
        }

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
            $mailer->Host = $mailHost;
            $mailer->Port = $mailPort;
            $mailer->SMTPAuth = $mailAuth;
            $mailer->SMTPSecure = configMapMailSecureToPhpMailer($mailSecure);
            $mailer->Username = $mailUser;
            $mailer->Password = $mailPassword;
            $mailer->Timeout = 8;

            if (!$mailer->smtpConnect()) {
                $error = $mailer->ErrorInfo;
                $mailer->smtpClose();
                return ['ok' => false, 'message' => 'MAIL-Test fehlgeschlagen: ' . ($error !== '' ? $error : 'SMTP Verbindung nicht moeglich.')];
            }

            $mailer->smtpClose();
            return ['ok' => true, 'message' => 'MAIL-Test erfolgreich. SMTP-Verbindung ist erreichbar.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'MAIL-Test fehlgeschlagen: ' . $e->getMessage()];
        }
    }

    // import db_adapter
    use Portflow\Core\DatabaseAdapter;
    $db_adapter = new DatabaseAdapter();

    // import mail
    use Portflow\Core\Mail;
    $mail = new Mail();

    // import notification center
    use Portflow\Core\NotificationCenter;

    // get user role from db
    $query = "SELECT role.caption AS role FROM users INNER JOIN role ON users.role = role.uuid WHERE users.uuid = :uuid";
    $result = $db_adapter->db_query($query, ['uuid' => $_SESSION['uuid']]);
    $role = $result[0]['role'];

    // handle requests
    $set = $_GET['set'] ?? null;
    $get = $_GET['get'] ?? null;

    // post
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        switch ($set) {
            case 'username':
                $username = $_POST['username'] ?? null;
                $password = $_POST['password'] ?? null;

                if ($auth->csrf_check()) {
                    // check inputs
                    if (mb_strlen($username) > 255 || mb_strlen($username) < 2) {
                        $logger->log('username length not correct', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }
                    if (mb_strlen($password) > 128 || mb_strlen($password) < 8) {
                        $logger->log('password length not correct', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }
                    if (empty($username) || empty($password)) {
                        $logger->log('username or password empty', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }

                    // Prüfen, ob der neue Username bereits vergeben ist
                    $query = "SELECT 1 FROM users WHERE username = :new_username";
                    $taken = $db_adapter->db_query($query, ['new_username' => $username]);
                    if (!empty($taken)) {
                        $logger->log('username already exists', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }

                    // check if user exists and password is correct
                    $query = "SELECT password FROM users WHERE uuid = :uuid";
                    $result = $db_adapter->db_query($query, ['uuid' => $_SESSION['uuid']]);
                    $result = !empty($result) ? $result[0] : null;

                    if (!empty($result)) {
                        if (password_verify($password, $result['password'])) {
                            // update database
                            $query = "UPDATE users SET username = :new_username, changed = NOW() WHERE uuid = :uuid";
                            $result = $db_adapter->db_query($query, ['new_username' => $username, 'uuid' => $_SESSION['uuid']]);
                            $_SESSION['name'] = $username;
                            $logger->log('username updated', 1, echoToWeb: true);
                        } else {
                            $logger->log('password incorrect', 2, echoToWeb: true);
                        }
                    } else {
                        $logger->log('user does not exist', 2, echoToWeb: true);
                    }
                    header('Location: ?site=account');
                }
                break;
            case 'email':
                $email = $_POST['email'] ?? null;
                $password = $_POST['password'] ?? null;

                if ($auth->csrf_check()) {
                    // check inputs
                    if (mb_strlen($email) > 254 || mb_strlen($email) < 3) {
                        $logger->log('email length not correct', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }
                    if (mb_strlen($password) > 128 || mb_strlen($password) < 8) {
                        $logger->log('password length not correct', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }
                    if (empty($email) || empty($password)) {
                        $logger->log('email or password empty', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }
                    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
                        $this->logger->log('email not valid', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }

                    // Prüfen, ob die neue E-Mail bereits vergeben ist
                    $query = "SELECT * FROM users WHERE email = :new_email";
                    $result = $db_adapter->db_query($query, ['new_email' => $email]);
                    if (!empty($result[0])) {
                        $logger->log('email already exists', 2, echoToWeb: true);
                        echo 'taken: ' . print_r($result[0], true) . '<br>';
                        header('Location: ?site=account');
                        die();
                    }

                    // check if user exists and password is correct
                    $query = "SELECT password FROM users WHERE uuid = :uuid";
                    $result = $db_adapter->db_query($query, ['uuid' => $_SESSION['uuid']]);
                    $result = !empty($result) ? $result[0] : null;

                    if (!empty($result)) {
                        if (password_verify($password, $result['password'])) {
                            // generate activation code
                            $activation_code = $auth->random_string(10);

                            // update database
                            $query = "UPDATE users SET email = :new_email, activation_code = :activation_code, changed = NOW() WHERE uuid = :uuid";
                            $result = $db_adapter->db_query($query, ['new_email' => $email, 'activation_code' => $activation_code, 'uuid' => $_SESSION['uuid']]);

                            // send activation mail
                            $activate_link = PORTFLOW_HOSTNAME . '?code=' . $activation_code . '&email=' . $email; 
                            $subject = 'Portflow: Activate your account';
                            $message = 'To activate your account, please click the following link: <a href="' . $activate_link . '">Activate</a>';
                            $mail_to = ['email' => $email, 'username' => $_SESSION['name']];
                            if ($mail->send($mail_to, $subject, $message)) {
                                $logger->log('E-Mail successfully updated. An activation code has been sent to your new e-mail.', 1, echoToWeb: true);
                                header('Location: ' . PORTFLOW_HOSTNAME);
                            } else {
                                $logger->log('E-Mail successfully updated. But an error occured while sending an activation code to your new e-mail.', 3, echoToWeb: true);
                                throw new \Exception('E-Mail successfully updated. But an error occured while sending an activation code to your new e-mail.');
                            }
                            session_destroy();
                            header('Location: ' . PORTFLOW_HOSTNAME);
                        } else {
                            $logger->log('password incorrect', 2, echoToWeb: true);
                        }
                    } else {
                        $logger->log('user does not exist', 2, echoToWeb: true);
                    }
                    header('Location: ?site=account');
                }
                break;
            case 'password':
                $password = $_POST['password'] ?? null;
                $old_password = $_POST['old_password'] ?? null;

                if ($auth->csrf_check()) {
                    // check inputs
                    if (mb_strlen($password) > 128 || mb_strlen($password) < 8) {
                        $logger->log('password length not correct', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }
                    if (mb_strlen($old_password) > 128 || mb_strlen($old_password) < 8) {
                        $logger->log('old password length not correct', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }
                    if (empty($password) || empty($old_password)) {
                        $logger->log('password or old password empty', 2, echoToWeb: true);
                        header('Location: ?site=account');
                        die();
                    }

                    // check if user exists and password is correct
                    $query = "SELECT password FROM users WHERE uuid = :uuid";
                    $result = $db_adapter->db_query($query, ['uuid' => $_SESSION['uuid']]);
                    $result = !empty($result) ? $result[0] : null;

                    if (!empty($result)) {
                        if (password_verify($old_password, $result['password'])) {
                            // update database
                            $query = "UPDATE users SET password = :new_password, changed = NOW() WHERE uuid = :uuid";
                            $result = $db_adapter->db_query($query, ['new_password' => password_hash($password, PASSWORD_DEFAULT), 'uuid' => $_SESSION['uuid']]);
                            $logger->log('password updated', 1, echoToWeb: true);
                        } else {
                            $logger->log('old password incorrect', 2, echoToWeb: true);
                        }
                    } else {
                        $logger->log('user does not exist', 2, echoToWeb: true);
                    }
                    header('Location: ?site=account');
                }
                break;
        
            case 'language':
                $language = $_POST['language'] ?? null;

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for language update', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                // check inputs
                if (mb_strlen($language) !== 5) {
                    $logger->log('language length not correct', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }
                if (empty($language)) {
                    $logger->log('language empty', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                $settings = getSessionUserSettings();
                $settings['language'] = $language;
                saveUserSettings($db_adapter, $settings, (string)$_SESSION['uuid']);

                $logger->log('language updated', 1, echoToWeb: true);
                header('Location: ?site=appearance');
                break;

            case 'appearance_preferences':
                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for appearance update', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                $language = trim((string)($_POST['language'] ?? ''));
                $theme = trim((string)($_POST['theme'] ?? ''));
                $fontFamily = trim((string)($_POST['font_family'] ?? ''));
                $fontSize = trim((string)($_POST['font_size'] ?? ''));

                $allowedLanguages = ['de-DE', 'en-EN', 'en-US'];
                $allowedThemes = ['light', 'dark', 'contrast'];
                $allowedFonts = ['jetbrains', 'source_sans', 'fira_sans'];
                $allowedSizes = ['small', 'normal', 'large'];

                $settings = getSessionUserSettings();

                if (in_array($language, $allowedLanguages, true)) {
                    $settings['language'] = $language;
                }

                $normalizedTheme = normalizeAppearanceTheme($theme);
                $settings['appearance']['theme'] = in_array($normalizedTheme, $allowedThemes, true)
                    ? $normalizedTheme
                    : $settings['appearance']['theme'];

                $settings['appearance']['font_family'] = in_array($fontFamily, $allowedFonts, true)
                    ? $fontFamily
                    : $settings['appearance']['font_family'];

                $settings['appearance']['font_size'] = in_array($fontSize, $allowedSizes, true)
                    ? $fontSize
                    : $settings['appearance']['font_size'];

                saveUserSettings($db_adapter, $settings, (string)$_SESSION['uuid']);

                $logger->log('appearance preferences updated', 1, echoToWeb: true);
                header('Location: ?site=appearance');
                break;
            case 'notification_preferences':
                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification preferences update', 2, echoToWeb: true);
                    header('Location: ?site=notifications');
                    die();
                }

                $level = strtolower(trim((string)($_POST['notification_level'] ?? 'minimal')));
                $channel = strtolower(trim((string)($_POST['notification_channel'] ?? 'mail')));
                $slackWebhook = configNormalizeEnvValue((string)($_POST['notification_slack_webhook_url'] ?? ''));
                $telegramChatId = configNormalizeEnvValue((string)($_POST['notification_telegram_chat_id'] ?? ''));

                $allowedLevels = ['off', 'minimal', 'progress', 'all'];
                $allowedChannels = getAvailableNotificationChannels();
                $fallbackChannel = in_array('mail', $allowedChannels, true)
                    ? 'mail'
                    : (string)($allowedChannels[0] ?? 'mail');

                $settings = getSessionUserSettings();
                $settings['notifications']['level'] = in_array($level, $allowedLevels, true)
                    ? $level
                    : 'minimal';
                $settings['notifications']['channel'] = in_array($channel, $allowedChannels, true)
                    ? $channel
                    : $fallbackChannel;
                if ($slackWebhook !== '' && !configIsValidSlackWebhookUrl($slackWebhook)) {
                    setSettingsFeedback('notifications', false, 'Slack Webhook URL ist ungueltig.');
                    $logger->log('notification preferences update failed: invalid slack webhook', 2, echoToWeb: true);
                    header('Location: ?site=notifications');
                    break;
                }

                if ($settings['notifications']['channel'] === 'slack' && in_array('slack', $allowedChannels, true)) {
                    $settings['notifications']['slack_webhook_url'] = $slackWebhook;
                } elseif (!isset($settings['notifications']['slack_webhook_url'])) {
                    $settings['notifications']['slack_webhook_url'] = '';
                }

                if ($settings['notifications']['channel'] === 'telegram' && in_array('telegram', $allowedChannels, true)) {
                    $settings['notifications']['telegram_chat_id'] = $telegramChatId;
                } elseif (!isset($settings['notifications']['telegram_chat_id'])) {
                    $settings['notifications']['telegram_chat_id'] = '';
                }

                saveUserSettings($db_adapter, $settings, (string)$_SESSION['uuid']);
                setSettingsFeedback('notifications', true, 'Benachrichtigungseinstellungen gespeichert.');
                $logger->log('notification preferences updated', 1, echoToWeb: true);
                header('Location: ?site=notifications');
                break;
            case 'notification_telegram_link_start':
                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for telegram onboarding start', 2, echoToWeb: true);
                    header('Location: ?site=notifications');
                    die();
                }

                $settings = getSessionUserSettings();
                $token = generateNotificationLinkToken();
                $settings['notifications']['telegram_link_token'] = $token;
                $settings['notifications']['telegram_link_started_at'] = gmdate('c');
                $settings['notifications']['telegram_link_confirmed_at'] = '';
                $settings['notifications']['telegram_link_username'] = '';
                saveUserSettings($db_adapter, $settings, (string)$_SESSION['uuid']);
                setSettingsFeedback('notifications', true, 'Telegram-Link gestartet. Sende dem Bot jetzt /start ' . $token . ' und klicke danach auf "Telegram-Verknuepfung pruefen".');
                header('Location: ?site=notifications');
                break;
            case 'notification_telegram_link_refresh':
                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for telegram onboarding refresh', 2, echoToWeb: true);
                    header('Location: ?site=notifications');
                    die();
                }

                $settings = getSessionUserSettings();
                $token = (string)($settings['notifications']['telegram_link_token'] ?? '');
                if ($token === '') {
                    setSettingsFeedback('notifications', false, 'Es ist kein aktiver Telegram-Link vorhanden.');
                    header('Location: ?site=notifications');
                    break;
                }

                $match = findTelegramChatByStartToken($token);
                if (empty($match['ok'])) {
                    setSettingsFeedback('notifications', false, 'Telegram-Verknuepfung fehlgeschlagen: ' . (string)($match['error'] ?? 'unbekannter Fehler'));
                    header('Location: ?site=notifications');
                    break;
                }

                if (empty($match['matched'])) {
                    setSettingsFeedback('notifications', false, (string)($match['error'] ?? 'Noch kein passender Telegram-Start gefunden.'));
                    header('Location: ?site=notifications');
                    break;
                }

                $settings['notifications']['telegram_chat_id'] = (string)($match['chat_id'] ?? '');
                $settings['notifications']['telegram_link_confirmed_at'] = gmdate('c');
                $settings['notifications']['telegram_link_username'] = (string)($match['telegram_username'] ?? '');
                $settings['notifications']['telegram_link_token'] = '';
                $settings['notifications']['telegram_link_started_at'] = '';
                saveUserSettings($db_adapter, $settings, (string)$_SESSION['uuid']);
                setSettingsFeedback('notifications', true, 'Telegram erfolgreich verknuepft. Chat-ID wurde automatisch uebernommen.');
                header('Location: ?site=notifications');
                break;
            case 'notification_telegram_disconnect':
                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for telegram disconnect', 2, echoToWeb: true);
                    header('Location: ?site=notifications');
                    die();
                }

                $settings = getSessionUserSettings();
                $settings['notifications']['telegram_chat_id'] = '';
                $settings['notifications']['telegram_link_token'] = '';
                $settings['notifications']['telegram_link_started_at'] = '';
                $settings['notifications']['telegram_link_confirmed_at'] = '';
                $settings['notifications']['telegram_link_username'] = '';
                saveUserSettings($db_adapter, $settings, (string)$_SESSION['uuid']);
                setSettingsFeedback('notifications', true, 'Telegram-Verknuepfung entfernt.');
                header('Location: ?site=notifications');
                break;
            case 'config_db_test':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for db configuration test', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=system');
                    die();
                }

                $dbFormData = configBuildDbFormData();
                $dbFormData['db_password'] = configGetPasswordValue('db_password', (string)DB_PASSWORD);
                $dbTestResult = configTestDatabase($dbFormData);
                configSetFeedback('db', (bool)$dbTestResult['ok'], (string)$dbTestResult['message'], $dbFormData);
                $logger->log('database configuration test executed', $dbTestResult['ok'] ? 1 : 2, echoToWeb: true);
                header('Location: ?site=configuration&tab=system#cfg-db');
                break;
            case 'config_db_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for db configuration save', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=system');
                    die();
                }

                $dbFormData = configBuildDbFormData();
                $dbFormData['db_password'] = configGetPasswordValue('db_password', (string)DB_PASSWORD);
                $dbValidationResult = configTestDatabase($dbFormData);
                if (!$dbValidationResult['ok']) {
                    configSetFeedback('db', false, (string)$dbValidationResult['message'], $dbFormData);
                    header('Location: ?site=configuration&tab=system#cfg-db');
                    break;
                }

                $dbWriteResult = configWriteEnvValues([
                    'DB_TYPE' => $dbFormData['db_type'],
                    'DB_SERVER' => $dbFormData['db_server'],
                    'DB_PORT' => $dbFormData['db_port'],
                    'DB_NAME' => $dbFormData['db_name'],
                    'DB_USER' => $dbFormData['db_user'],
                    'DB_PASSWORD' => $dbFormData['db_password']
                ]);

                configSetFeedback('db', (bool)$dbWriteResult['ok'], (string)$dbWriteResult['message'], $dbFormData);
                $logger->log('database configuration save executed', $dbWriteResult['ok'] ? 1 : 3, echoToWeb: true);
                if ($dbWriteResult['ok']) {
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_db_save', [
                        'db_type' => $dbFormData['db_type'],
                        'db_server' => $dbFormData['db_server'],
                        'db_port' => $dbFormData['db_port'],
                        'db_name' => $dbFormData['db_name'],
                        'db_user' => $dbFormData['db_user']
                    ]);
                }
                header('Location: ?site=configuration&tab=system#cfg-db');
                break;
            case 'config_ldap_test':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for ldap configuration test', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=system');
                    die();
                }

                $ldapFormData = configBuildLdapFormData();
                $ldapFormData['ldap_bind_password'] = configGetPasswordValue('ldap_bind_password', (string)LDAP_BIND_PASSWORD);
                $ldapTestResult = configTestLdap($ldapFormData);
                configSetFeedback('ldap', (bool)$ldapTestResult['ok'], (string)$ldapTestResult['message'], $ldapFormData);
                $logger->log('ldap configuration test executed', $ldapTestResult['ok'] ? 1 : 2, echoToWeb: true);
                header('Location: ?site=configuration&tab=system#cfg-ldap');
                break;
            case 'config_ldap_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for ldap configuration save', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=system');
                    die();
                }

                $ldapFormData = configBuildLdapFormData();
                $ldapFormData['ldap_bind_password'] = configGetPasswordValue('ldap_bind_password', (string)LDAP_BIND_PASSWORD);

                if (configToBool($ldapFormData['ldap_enabled'] ?? false)) {
                    $ldapValidationResult = configTestLdap($ldapFormData);
                    if (!$ldapValidationResult['ok']) {
                        configSetFeedback('ldap', false, (string)$ldapValidationResult['message'], $ldapFormData);
                        header('Location: ?site=configuration&tab=system#cfg-ldap');
                        break;
                    }
                }

                $ldapWriteResult = configWriteEnvValues([
                    'LDAP_ENABLED' => configEnvBool(configToBool($ldapFormData['ldap_enabled'] ?? false)),
                    'LDAP_SERVER' => $ldapFormData['ldap_server'],
                    'LDAP_PORT' => $ldapFormData['ldap_port'],
                    'LDAP_BASEDN' => $ldapFormData['ldap_basedn'],
                    'LDAP_USERDN' => $ldapFormData['ldap_userdn'],
                    'LDAP_FILTER' => $ldapFormData['ldap_filter'],
                    'LDAP_BIND' => configEnvBool(configToBool($ldapFormData['ldap_bind'] ?? false)),
                    'LDAP_BIND_USER' => $ldapFormData['ldap_bind_user'],
                    'LDAP_BIND_PASSWORD' => $ldapFormData['ldap_bind_password'],
                    'LDAP_TRUST' => configEnvBool(configToBool($ldapFormData['ldap_trust'] ?? false))
                ]);

                configSetFeedback('ldap', (bool)$ldapWriteResult['ok'], (string)$ldapWriteResult['message'], $ldapFormData);
                $logger->log('ldap configuration save executed', $ldapWriteResult['ok'] ? 1 : 3, echoToWeb: true);
                if ($ldapWriteResult['ok']) {
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_ldap_save', [
                        'ldap_enabled' => configToBool($ldapFormData['ldap_enabled'] ?? false),
                        'ldap_server' => $ldapFormData['ldap_server'],
                        'ldap_port' => $ldapFormData['ldap_port'],
                        'ldap_basedn' => $ldapFormData['ldap_basedn'],
                        'ldap_bind' => configToBool($ldapFormData['ldap_bind'] ?? false),
                        'ldap_bind_user' => $ldapFormData['ldap_bind_user'],
                        'ldap_trust' => configToBool($ldapFormData['ldap_trust'] ?? false)
                    ]);
                }
                header('Location: ?site=configuration&tab=system#cfg-ldap');
                break;
            case 'config_mail_test':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for mail configuration test', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=system');
                    die();
                }

                $mailFormData = configBuildMailFormData();
                $mailFormData['mail_password'] = configGetPasswordValue('mail_password', (string)MAIL_PASSWORD);
                $mailTestResult = configTestMail($mailFormData);
                configSetFeedback('mail', (bool)$mailTestResult['ok'], (string)$mailTestResult['message'], $mailFormData);
                $logger->log('mail configuration test executed', $mailTestResult['ok'] ? 1 : 2, echoToWeb: true);
                header('Location: ?site=configuration&tab=system#cfg-mail');
                break;
            case 'config_mail_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for mail configuration save', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=system');
                    die();
                }

                $mailFormData = configBuildMailFormData();
                $mailFormData['mail_password'] = configGetPasswordValue('mail_password', (string)MAIL_PASSWORD);
                $mailValidationResult = configTestMail($mailFormData);
                if (!$mailValidationResult['ok']) {
                    configSetFeedback('mail', false, (string)$mailValidationResult['message'], $mailFormData);
                    header('Location: ?site=configuration&tab=system#cfg-mail');
                    break;
                }

                $mailWriteResult = configWriteEnvValues([
                    'MAIL_HOST' => $mailFormData['mail_host'],
                    'MAIL_USER' => $mailFormData['mail_user'],
                    'MAIL_PASSWORD' => $mailFormData['mail_password'],
                    'MAIL_PORT' => $mailFormData['mail_port'],
                    'MAIL_SMTPAUTH' => configEnvBool(configToBool($mailFormData['mail_smtpauth'] ?? false)),
                    'MAIL_SMTPSECURE' => configMapMailSecureToEnv((string)$mailFormData['mail_smtpsecure'])
                ]);

                configSetFeedback('mail', (bool)$mailWriteResult['ok'], (string)$mailWriteResult['message'], $mailFormData);
                $logger->log('mail configuration save executed', $mailWriteResult['ok'] ? 1 : 3, echoToWeb: true);
                if ($mailWriteResult['ok']) {
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_mail_save', [
                        'mail_host' => $mailFormData['mail_host'],
                        'mail_user' => $mailFormData['mail_user'],
                        'mail_port' => $mailFormData['mail_port'],
                        'mail_smtpauth' => configToBool($mailFormData['mail_smtpauth'] ?? false),
                        'mail_smtpsecure' => $mailFormData['mail_smtpsecure']
                    ]);
                }
                header('Location: ?site=configuration&tab=system#cfg-mail');
                break;
            case 'config_scheduler_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for scheduler configuration save', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=system');
                    die();
                }

                $automationStore = new AutomationStore();
                $automationSettings = $automationStore->getSettings();
                $schedulerConfig = [
                    'queue_enabled' => isset($_POST['scheduler_queue_enabled']) ? '1' : '0',
                    'notifications_enabled' => isset($_POST['scheduler_notifications_enabled']) ? '1' : '0',
                    'snmp_scan_enabled' => isset($_POST['scheduler_snmp_scan_enabled']) ? '1' : '0',
                ];

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => (string)($automationSettings['ssh_host'] ?? ''),
                        'ssh_port' => (int)($automationSettings['ssh_port'] ?? 22),
                        'ssh_auth_method' => (string)($automationSettings['ssh_auth_method'] ?? 'password'),
                        'ssh_username' => (string)($automationSettings['ssh_username'] ?? ''),
                        'ssh_password' => (string)($automationSettings['ssh_password'] ?? ''),
                        'ssh_private_key' => (string)($automationSettings['ssh_private_key'] ?? ''),
                        'scripts_json' => (string)($automationSettings['scripts_json'] ?? '{}'),
                        'switch_inventory_json' => (string)($automationSettings['switch_inventory_json'] ?? '{"switches": []}'),
                        'scheduler_config' => $schedulerConfig,
                    ]);

                    configSetFeedback('scheduler', true, 'Scheduler-Einstellungen wurden gespeichert.', [
                        'queue_enabled' => $schedulerConfig['queue_enabled'],
                        'notifications_enabled' => $schedulerConfig['notifications_enabled'],
                        'snmp_scan_enabled' => $schedulerConfig['snmp_scan_enabled'],
                    ]);
                    $logger->log('scheduler configuration save executed', 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_scheduler_save', [
                        'queue_enabled' => $schedulerConfig['queue_enabled'] === '1',
                        'notifications_enabled' => $schedulerConfig['notifications_enabled'] === '1',
                        'snmp_scan_enabled' => $schedulerConfig['snmp_scan_enabled'] === '1',
                    ]);
                } catch (\Throwable $e) {
                    configSetFeedback('scheduler', false, 'Scheduler-Einstellungen konnten nicht gespeichert werden: ' . $e->getMessage(), [
                        'queue_enabled' => $schedulerConfig['queue_enabled'],
                        'notifications_enabled' => $schedulerConfig['notifications_enabled'],
                        'snmp_scan_enabled' => $schedulerConfig['snmp_scan_enabled'],
                    ]);
                    $logger->log('scheduler configuration save failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=configuration&tab=system#cfg-scheduler');
                break;
            case 'config_notification_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification configuration save', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications');
                    die();
                }

                $notifDailyTime = trim((string)($_POST['notification_daily_time'] ?? '08:00'));
                $notifTimezone  = trim((string)($_POST['notification_timezone'] ?? 'Europe/Berlin'));
                $notifRetentionDays = (int)($_POST['notification_queue_retention_days'] ?? 30);
                $notifSlackEnabled = isset($_POST['notification_slack_enabled']) && configToBool($_POST['notification_slack_enabled']);
                $notifSlackWebhook = configNormalizeEnvValue((string)($_POST['notification_slack_webhook_url'] ?? ''));
                $notifTelegramEnabled = isset($_POST['notification_telegram_enabled']) && configToBool($_POST['notification_telegram_enabled']);
                $notifTelegramBotToken = configNormalizeEnvValue((string)($_POST['notification_telegram_bot_token'] ?? ''));
                $notifTelegramChatId = configNormalizeEnvValue((string)($_POST['notification_telegram_chat_id'] ?? ''));

                // validate time format HH:MM
                if (!preg_match('/^\d{2}:\d{2}$/', $notifDailyTime)) {
                    $notifDailyTime = '08:00';
                }
                // validate timezone
                if (!in_array($notifTimezone, \DateTimeZone::listIdentifiers(), true)) {
                    $notifTimezone = 'Europe/Berlin';
                }
                if ($notifRetentionDays < 1 || $notifRetentionDays > 365) {
                    $notifRetentionDays = 30;
                }
                if ($notifSlackWebhook !== '' && !configIsValidSlackWebhookUrl($notifSlackWebhook)) {
                    configSetFeedback('notification', false, 'Slack Webhook URL ist ungueltig.', [
                        'notification_daily_time' => $notifDailyTime,
                        'notification_timezone'   => $notifTimezone,
                        'notification_queue_retention_days' => (string)$notifRetentionDays,
                        'notification_slack_enabled' => $notifSlackEnabled ? '1' : '0',
                        'notification_slack_webhook_url' => $notifSlackWebhook,
                        'notification_telegram_enabled' => $notifTelegramEnabled ? '1' : '0',
                        'notification_telegram_bot_token' => $notifTelegramBotToken,
                        'notification_telegram_chat_id' => $notifTelegramChatId
                    ]);
                    $logger->log('notification configuration save failed: invalid slack webhook', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications#cfg-notification');
                    break;
                }

                $notifWriteResult = configWriteEnvValues([
                    'NOTIFICATION_DAILY_TIME' => $notifDailyTime,
                    'NOTIFICATION_TIMEZONE'   => $notifTimezone,
                    'NOTIFICATION_QUEUE_RETENTION_DAYS' => (string)$notifRetentionDays,
                    'NOTIFICATION_SLACK_ENABLED' => configEnvBool($notifSlackEnabled),
                    'NOTIFICATION_SLACK_WEBHOOK_URL' => $notifSlackWebhook,
                    'NOTIFICATION_TELEGRAM_ENABLED' => configEnvBool($notifTelegramEnabled),
                    'NOTIFICATION_TELEGRAM_BOT_TOKEN' => $notifTelegramBotToken,
                    'NOTIFICATION_TELEGRAM_CHAT_ID' => $notifTelegramChatId
                ]);

                configSetFeedback('notification', (bool)$notifWriteResult['ok'], (string)$notifWriteResult['message'], [
                    'notification_daily_time' => $notifDailyTime,
                    'notification_timezone'   => $notifTimezone,
                    'notification_queue_retention_days' => (string)$notifRetentionDays,
                    'notification_slack_enabled' => $notifSlackEnabled ? '1' : '0',
                    'notification_slack_webhook_url' => $notifSlackWebhook,
                    'notification_telegram_enabled' => $notifTelegramEnabled ? '1' : '0',
                    'notification_telegram_bot_token' => $notifTelegramBotToken,
                    'notification_telegram_chat_id' => $notifTelegramChatId
                ]);
                $logger->log('notification configuration save executed', $notifWriteResult['ok'] ? 1 : 3, echoToWeb: true);
                if ($notifWriteResult['ok']) {
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_notification_save', [
                        'notification_daily_time' => $notifDailyTime,
                        'notification_timezone'   => $notifTimezone,
                        'notification_queue_retention_days' => $notifRetentionDays,
                        'notification_slack_enabled' => $notifSlackEnabled,
                        'notification_telegram_enabled' => $notifTelegramEnabled
                    ]);
                }
                header('Location: ?site=configuration&tab=notifications#cfg-notification');
                break;
            case 'config_update_check':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for updater check', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=updater');
                    die();
                }

                $updateStatus = configGetUpdateStatus(true);
                configSetFeedback('updater', (bool)$updateStatus['ok'], (string)$updateStatus['message'], $updateStatus);
                $logger->log('system updater check executed', $updateStatus['ok'] ? 1 : 2, echoToWeb: true);
                header('Location: ?site=configuration&tab=updater#cfg-updater');
                break;
            case 'config_update_execute':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for updater execute', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=updater');
                    die();
                }

                $updateResult = configExecuteUpdate();
                configSetFeedback('updater', (bool)$updateResult['ok'], (string)$updateResult['message'], $updateResult);
                $logger->log('system updater execute finished', !empty($updateResult['ok']) ? 1 : 3, echoToWeb: true);
                if (!empty($updateResult['ok'])) {
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_system_update_execute', [
                        'branch' => (string)($updateResult['branch'] ?? ''),
                        'from' => (string)($updateResult['previous_version'] ?? ''),
                        'to' => (string)($updateResult['target_version'] ?? ''),
                        'behind_count' => (int)($updateResult['behind_count'] ?? 0),
                    ]);
                }
                header('Location: ?site=configuration&tab=updater#cfg-updater');
                break;
            case 'config_update_rollback':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for updater rollback', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=updater');
                    die();
                }

                $rollbackResult = configExecuteManualRollback();
                configSetFeedback('updater', (bool)$rollbackResult['ok'], (string)$rollbackResult['message'], $rollbackResult);
                $logger->log('system updater manual rollback finished', !empty($rollbackResult['ok']) ? 1 : 3, echoToWeb: true);
                if (!empty($rollbackResult['ok'])) {
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_system_update_rollback', [
                        'from' => (string)($rollbackResult['previous_version'] ?? ''),
                        'to' => (string)($rollbackResult['target_version'] ?? ''),
                        'target_commit' => (string)($rollbackResult['target_commit'] ?? ''),
                    ]);
                }
                header('Location: ?site=configuration&tab=updater#cfg-updater');
                break;
            case 'config_notification_cleanup_queue':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification queue cleanup', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications');
                    die();
                }

                $retentionDays = (int)($_POST['notification_queue_retention_days'] ?? (defined('NOTIFICATION_QUEUE_RETENTION_DAYS') ? NOTIFICATION_QUEUE_RETENTION_DAYS : 30));
                if ($retentionDays < 0 || $retentionDays > 365) {
                    $retentionDays = 30;
                }

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $cleanup = $notificationCenter->cleanupQueue($retentionDays);
                    $removedEntries = (int)($cleanup['removed'] ?? 0);
                    $effectiveRetention = (int)($cleanup['retention_days'] ?? $retentionDays);
                    if ($removedEntries > 0) {
                        $message = 'Queue bereinigt: removed=' . $removedEntries
                            . ', remaining=' . (int)($cleanup['remaining'] ?? 0)
                            . ', retention_days=' . $effectiveRetention;
                    } else {
                        $message = 'Keine abgeschlossenen Queue-Eintraege zum Bereinigen gefunden. Es werden nur sent/failed Eintraege entfernt, die aelter als ' . $effectiveRetention . ' Tage sind.';
                    }
                    configSetFeedback('notification', true, $message);
                    $logger->log('notification queue cleanup executed: ' . $message, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'DELETE', 'configuration_notification_cleanup_queue', $cleanup);
                } catch (\Throwable $e) {
                    configSetFeedback('notification', false, 'Queue-Cleanup fehlgeschlagen: ' . $e->getMessage());
                    $logger->log('notification queue cleanup failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=configuration&tab=notifications#cfg-notification');
                break;
            case 'config_notification_retry_entry':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification retry', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications');
                    die();
                }

                $queueEntryId = trim((string)($_POST['notification_queue_entry_id'] ?? ''));

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $retry = $notificationCenter->retryQueueEntry($queueEntryId);
                    configSetFeedback('notification', !empty($retry['ok']), (string)($retry['message'] ?? 'Retry ausgefuehrt.'));
                    $logger->log('notification queue retry executed: ' . (string)($retry['message'] ?? 'n/a'), !empty($retry['ok']) ? 1 : 2, echoToWeb: true);
                } catch (\Throwable $e) {
                    configSetFeedback('notification', false, 'Queue-Retry fehlgeschlagen: ' . $e->getMessage());
                    $logger->log('notification queue retry failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=configuration&tab=notifications#cfg-notification');
                break;
            case 'config_notification_delete_entry':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification delete', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications');
                    die();
                }

                $queueEntryId = trim((string)($_POST['notification_queue_entry_id'] ?? ''));

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $deleteResult = $notificationCenter->deleteQueueEntry($queueEntryId);
                    $message = (string)($deleteResult['message'] ?? 'Queue-Eintrag geloescht.');
                    if (!empty($deleteResult['ok'])) {
                        $message = 'Queue-Eintrag geloescht. remaining=' . (int)($deleteResult['remaining'] ?? 0);
                    }
                    configSetFeedback('notification', !empty($deleteResult['ok']), $message);
                    $logger->log('notification queue delete executed: ' . $message, !empty($deleteResult['ok']) ? 1 : 2, echoToWeb: true);
                } catch (\Throwable $e) {
                    configSetFeedback('notification', false, 'Queue-Loeschen fehlgeschlagen: ' . $e->getMessage());
                    $logger->log('notification queue delete failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=configuration&tab=notifications#cfg-notification');
                break;
            case 'config_notification_delete_failed_entries':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification bulk delete failed', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications');
                    die();
                }

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $deleteResult = $notificationCenter->deleteFailedQueueEntries();
                    $message = 'Fehlgeschlagene Benachrichtigungen geloescht: deleted=' . (int)($deleteResult['deleted'] ?? 0)
                        . ', remaining=' . (int)($deleteResult['remaining'] ?? 0);
                    configSetFeedback('notification', true, $message);
                    $logger->log('notification queue bulk delete failed executed: ' . $message, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'DELETE', 'configuration_notification_delete_failed_entries', $deleteResult);
                } catch (\Throwable $e) {
                    configSetFeedback('notification', false, 'Bulk-Loeschen fehlgeschlagen: ' . $e->getMessage());
                    $logger->log('notification queue bulk delete failed failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=configuration&tab=notifications#cfg-notification');
                break;
            case 'config_notification_enqueue_test':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification enqueue test', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications');
                    die();
                }

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $meta = [
                        'source' => 'settings_admin',
                        'triggered_by' => (string)($_SESSION['username'] ?? ''),
                        'triggered_by_uuid' => (string)($_SESSION['uuid'] ?? ''),
                        'triggered_at' => gmdate('c')
                    ];
                    $enqueued = $notificationCenter->enqueueEvent(
                        'admin_test_event',
                        'minimal',
                        'Admin Test-Benachrichtigung',
                        "Dies ist ein manuell ausgeloestes Test-Event aus den Einstellungen.",
                        $meta
                    );

                    configSetFeedback(
                        'notification',
                        true,
                        'Test-Event wurde in die Queue eingestellt. Empfaenger: ' . $enqueued,
                        [
                            'notification_daily_time' => (string)(defined('NOTIFICATION_DAILY_TIME') ? NOTIFICATION_DAILY_TIME : '08:00'),
                            'notification_timezone' => (string)(defined('NOTIFICATION_TIMEZONE') ? NOTIFICATION_TIMEZONE : 'Europe/Berlin'),
                            'notification_slack_enabled' => (defined('NOTIFICATION_SLACK_ENABLED') && NOTIFICATION_SLACK_ENABLED) ? '1' : '0',
                            'notification_slack_webhook_url' => (string)(defined('NOTIFICATION_SLACK_WEBHOOK_URL') ? NOTIFICATION_SLACK_WEBHOOK_URL : ''),
                            'notification_telegram_enabled' => (defined('NOTIFICATION_TELEGRAM_ENABLED') && NOTIFICATION_TELEGRAM_ENABLED) ? '1' : '0',
                            'notification_telegram_bot_token' => (string)(defined('NOTIFICATION_TELEGRAM_BOT_TOKEN') ? NOTIFICATION_TELEGRAM_BOT_TOKEN : ''),
                            'notification_telegram_chat_id' => (string)(defined('NOTIFICATION_TELEGRAM_CHAT_ID') ? NOTIFICATION_TELEGRAM_CHAT_ID : '')
                        ]
                    );
                    $logger->log('notification test event enqueued: recipients=' . $enqueued, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'INSERT', 'configuration_notification_enqueue_test', [
                        'recipients' => $enqueued
                    ]);
                } catch (\Throwable $e) {
                    configSetFeedback('notification', false, 'Test-Event konnte nicht erstellt werden: ' . $e->getMessage());
                    $logger->log('notification test event enqueue failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=configuration&tab=notifications#cfg-notification');
                break;
            case 'config_notification_process_queue':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification queue processing', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications');
                    die();
                }

                $maxEntries = (int)($_POST['notification_process_limit'] ?? 100);
                if ($maxEntries < 1 || $maxEntries > 500) {
                    $maxEntries = 100;
                }
                $ignoreSchedule = !empty($_POST['notification_process_ignore_schedule']);

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $result = $notificationCenter->processQueue($maxEntries, $ignoreSchedule);
                    $message = 'Queue verarbeitet: processed=' . (int)($result['processed'] ?? 0)
                        . ', sent=' . (int)($result['sent'] ?? 0)
                        . ', failed=' . (int)($result['failed'] ?? 0)
                        . ', remaining=' . (int)($result['remaining'] ?? 0);
                    if ($ignoreSchedule) {
                        $message .= ' (Retry-Backoff ignoriert)';
                    }
                    configSetFeedback(
                        'notification',
                        true,
                        $message,
                        [
                            'notification_daily_time' => (string)(defined('NOTIFICATION_DAILY_TIME') ? NOTIFICATION_DAILY_TIME : '08:00'),
                            'notification_timezone' => (string)(defined('NOTIFICATION_TIMEZONE') ? NOTIFICATION_TIMEZONE : 'Europe/Berlin'),
                            'notification_slack_enabled' => (defined('NOTIFICATION_SLACK_ENABLED') && NOTIFICATION_SLACK_ENABLED) ? '1' : '0',
                            'notification_slack_webhook_url' => (string)(defined('NOTIFICATION_SLACK_WEBHOOK_URL') ? NOTIFICATION_SLACK_WEBHOOK_URL : ''),
                            'notification_telegram_enabled' => (defined('NOTIFICATION_TELEGRAM_ENABLED') && NOTIFICATION_TELEGRAM_ENABLED) ? '1' : '0',
                            'notification_telegram_bot_token' => (string)(defined('NOTIFICATION_TELEGRAM_BOT_TOKEN') ? NOTIFICATION_TELEGRAM_BOT_TOKEN : ''),
                            'notification_telegram_chat_id' => (string)(defined('NOTIFICATION_TELEGRAM_CHAT_ID') ? NOTIFICATION_TELEGRAM_CHAT_ID : '')
                        ]
                    );
                    $logger->log('notification queue processed manually: ' . $message, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_notification_process_queue', [
                        'max_entries' => $maxEntries,
                        'result' => $result
                    ]);
                } catch (\Throwable $e) {
                    configSetFeedback('notification', false, 'Queue-Verarbeitung fehlgeschlagen: ' . $e->getMessage());
                    $logger->log('notification queue processing failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=configuration&tab=notifications#cfg-notification');
                break;
            case 'config_notification_test_slack':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for slack notification test', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications');
                    die();
                }

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $result = $notificationCenter->sendChannelTest(
                        'slack',
                        [
                            'email' => (string)($_SESSION['email'] ?? ''),
                            'username' => (string)($_SESSION['username'] ?? 'admin')
                        ],
                        'Slack Testnachricht',
                        'Dies ist eine Slack-Testnachricht aus den Einstellungen.',
                        [
                            'source' => 'settings_admin',
                            'channel' => 'slack',
                            'triggered_by' => (string)($_SESSION['username'] ?? ''),
                            'triggered_at' => gmdate('c')
                        ]
                    );

                    $ok = !empty($result['ok']);
                    $message = $ok
                        ? 'Slack-Testnachricht erfolgreich gesendet.'
                        : 'Slack-Test fehlgeschlagen: ' . (string)($result['error'] ?? 'unbekannter Fehler');

                    configSetFeedback(
                        'notification',
                        $ok,
                        $message,
                        [
                            'notification_daily_time' => (string)(defined('NOTIFICATION_DAILY_TIME') ? NOTIFICATION_DAILY_TIME : '08:00'),
                            'notification_timezone' => (string)(defined('NOTIFICATION_TIMEZONE') ? NOTIFICATION_TIMEZONE : 'Europe/Berlin'),
                            'notification_slack_enabled' => (defined('NOTIFICATION_SLACK_ENABLED') && NOTIFICATION_SLACK_ENABLED) ? '1' : '0',
                            'notification_slack_webhook_url' => (string)(defined('NOTIFICATION_SLACK_WEBHOOK_URL') ? NOTIFICATION_SLACK_WEBHOOK_URL : ''),
                            'notification_telegram_enabled' => (defined('NOTIFICATION_TELEGRAM_ENABLED') && NOTIFICATION_TELEGRAM_ENABLED) ? '1' : '0',
                            'notification_telegram_bot_token' => (string)(defined('NOTIFICATION_TELEGRAM_BOT_TOKEN') ? NOTIFICATION_TELEGRAM_BOT_TOKEN : ''),
                            'notification_telegram_chat_id' => (string)(defined('NOTIFICATION_TELEGRAM_CHAT_ID') ? NOTIFICATION_TELEGRAM_CHAT_ID : '')
                        ]
                    );

                    $logger->log('slack notification test executed', $ok ? 1 : 3, echoToWeb: true);
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_notification_test_slack', [
                        'ok' => $ok,
                        'error' => (string)($result['error'] ?? '')
                    ]);
                } catch (\Throwable $e) {
                    configSetFeedback('notification', false, 'Slack-Test fehlgeschlagen: ' . $e->getMessage());
                    $logger->log('slack notification test failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=configuration&tab=notifications#cfg-notification');
                break;
            case 'config_notification_test_telegram':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for telegram notification test', 2, echoToWeb: true);
                    header('Location: ?site=configuration&tab=notifications');
                    die();
                }

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $result = $notificationCenter->sendChannelTest(
                        'telegram',
                        [
                            'email' => (string)($_SESSION['email'] ?? ''),
                            'username' => (string)($_SESSION['username'] ?? 'admin')
                        ],
                        'Telegram Testnachricht',
                        'Dies ist eine Telegram-Testnachricht aus den Einstellungen.',
                        [
                            'source' => 'settings_admin',
                            'channel' => 'telegram',
                            'triggered_by' => (string)($_SESSION['username'] ?? ''),
                            'triggered_at' => gmdate('c')
                        ]
                    );

                    $ok = !empty($result['ok']);
                    $message = $ok
                        ? 'Telegram-Testnachricht erfolgreich gesendet.'
                        : 'Telegram-Test fehlgeschlagen: ' . (string)($result['error'] ?? 'unbekannter Fehler');

                    configSetFeedback(
                        'notification',
                        $ok,
                        $message,
                        [
                            'notification_daily_time' => (string)(defined('NOTIFICATION_DAILY_TIME') ? NOTIFICATION_DAILY_TIME : '08:00'),
                            'notification_timezone' => (string)(defined('NOTIFICATION_TIMEZONE') ? NOTIFICATION_TIMEZONE : 'Europe/Berlin'),
                            'notification_slack_enabled' => (defined('NOTIFICATION_SLACK_ENABLED') && NOTIFICATION_SLACK_ENABLED) ? '1' : '0',
                            'notification_slack_webhook_url' => (string)(defined('NOTIFICATION_SLACK_WEBHOOK_URL') ? NOTIFICATION_SLACK_WEBHOOK_URL : ''),
                            'notification_telegram_enabled' => (defined('NOTIFICATION_TELEGRAM_ENABLED') && NOTIFICATION_TELEGRAM_ENABLED) ? '1' : '0',
                            'notification_telegram_bot_token' => (string)(defined('NOTIFICATION_TELEGRAM_BOT_TOKEN') ? NOTIFICATION_TELEGRAM_BOT_TOKEN : ''),
                            'notification_telegram_chat_id' => (string)(defined('NOTIFICATION_TELEGRAM_CHAT_ID') ? NOTIFICATION_TELEGRAM_CHAT_ID : '')
                        ]
                    );

                    $logger->log('telegram notification test executed', $ok ? 1 : 3, echoToWeb: true);
                    logAutomationChange($db_adapter, 'UPDATE', 'configuration_notification_test_telegram', [
                        'ok' => $ok,
                        'error' => (string)($result['error'] ?? '')
                    ]);
                } catch (\Throwable $e) {
                    configSetFeedback('notification', false, 'Telegram-Test fehlgeschlagen: ' . $e->getMessage());
                    $logger->log('telegram notification test failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=configuration&tab=notifications#cfg-notification');
                break;
            case 'delete_account':
                $uuid = $_POST['uuid'] ?? null;

                // check if uuid is from user itself
                if ($uuid == $_SESSION['uuid']) {
                    $logger->log('user tried to delete itself', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                // check if user is admin
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                // check inputs
                if (empty($uuid)) {
                    $logger->log('uuid empty', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                // delete account
                $query = "DELETE FROM users WHERE uuid = :uuid";
                $result = $db_adapter->db_query($query, ['uuid' => $uuid]);
                $logger->log('account deleted', 1, echoToWeb: true);
                header('Location: ?site=access');
                break;
            case 'activate_account':
                $uuid = $_POST['uuid'] ?? null;

                // check if user is admin
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                // check inputs
                if (empty($uuid)) {
                    $logger->log('uuid empty', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                // activate account
                $query = "UPDATE users SET activation_code = :activation_code, changed = NOW() WHERE uuid = :uuid";
                $result = $db_adapter->db_query($query, ['activation_code' => 'activated', 'uuid' => $uuid]);
                $logger->log('account activated', 1, echoToWeb: true);
                header('Location: ?site=access');
                break;
            case 'deactivate_account':
                $uuid = $_POST['uuid'] ?? null;

                // check if uuid is from user itself
                if ($uuid == $_SESSION['uuid']) {
                    $logger->log('user tried to deactivate itself', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                // check if user is admin
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                // check inputs
                if (empty($uuid)) {
                    $logger->log('uuid empty', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                // deactivate account
                $query = "UPDATE users SET activation_code = :activation_code, changed = NOW() WHERE uuid = :uuid";
                $result = $db_adapter->db_query($query, ['activation_code' => 'deactivated', 'uuid' => $uuid]);
                $logger->log('account deactivated', 1, echoToWeb: true);
                header('Location: ?site=access');
                break;
            case 'update_account':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for account update', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                $uuid = trim((string)($_POST['uuid'] ?? ''));
                $username = trim((string)($_POST['username'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $roleUuid = trim((string)($_POST['role'] ?? ''));

                if ($uuid === '' || $username === '' || $email === '' || $roleUuid === '') {
                    $logger->log('account update missing required fields', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                if (mb_strlen($username) < 2 || mb_strlen($username) > 255) {
                    $logger->log('account update invalid username length', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $logger->log('account update invalid email', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                $existingRole = $db_adapter->db_query("SELECT uuid FROM role WHERE uuid = :uuid LIMIT 1", ['uuid' => $roleUuid]);
                if (empty($existingRole)) {
                    $logger->log('account update invalid role uuid', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                $existingUsername = $db_adapter->db_query(
                    "SELECT uuid FROM users WHERE username = :username AND uuid <> :uuid LIMIT 1",
                    ['username' => $username, 'uuid' => $uuid]
                );
                if (!empty($existingUsername)) {
                    $logger->log('account update failed: username already exists', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                $existingEmail = $db_adapter->db_query(
                    "SELECT uuid FROM users WHERE email = :email AND uuid <> :uuid LIMIT 1",
                    ['email' => $email, 'uuid' => $uuid]
                );
                if (!empty($existingEmail)) {
                    $logger->log('account update failed: email already exists', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                $db_adapter->db_query(
                    "UPDATE users SET username = :username, email = :email, role = :role, changed = NOW() WHERE uuid = :uuid",
                    [
                        'username' => $username,
                        'email' => $email,
                        'role' => $roleUuid,
                        'uuid' => $uuid
                    ]
                );

                $logger->log('account updated: ' . $uuid, 1, echoToWeb: true);
                header('Location: ?site=access');
                break;
            case 'update_access_right':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for access right update', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                $roleUuid = trim((string)($_POST['role_uuid'] ?? ''));
                $resource = trim((string)($_POST['resource'] ?? ''));
                $accessRight = -1;
                if (isset($_POST['access_right']) && is_numeric($_POST['access_right'])) {
                    $accessRight = (int)$_POST['access_right'];
                } else {
                    $accessRight = 0;
                    if (isset($_POST['access_read'])) {
                        $accessRight += 4;
                    }
                    if (isset($_POST['access_write'])) {
                        $accessRight += 2;
                    }
                    if (isset($_POST['access_execute'])) {
                        $accessRight += 1;
                    }
                }

                if ($roleUuid === '' || $resource === '') {
                    $logger->log('access right update missing role or resource', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                if ($accessRight < 0 || $accessRight > 7) {
                    $logger->log('access right update out of range', 2, echoToWeb: true);
                    header('Location: ?site=access');
                    die();
                }

                $existing = $db_adapter->db_query(
                    "SELECT uuid FROM access WHERE role = :role AND resource = :resource LIMIT 1",
                    ['role' => $roleUuid, 'resource' => $resource]
                );

                if (!empty($existing)) {
                    $db_adapter->db_query(
                        "UPDATE access SET access_right = :access_right WHERE uuid = :uuid",
                        ['access_right' => $accessRight, 'uuid' => $existing[0]['uuid']]
                    );
                } else {
                    $db_adapter->db_query(
                        "INSERT INTO access (role, resource, access_right) VALUES (:role, :resource, :access_right)",
                        ['role' => $roleUuid, 'resource' => $resource, 'access_right' => $accessRight]
                    );
                }

                $logger->log('access right updated for role=' . $roleUuid . ' resource=' . $resource . ' value=' . $accessRight, 1, echoToWeb: true);
                header('Location: ?site=access');
                break;
            case 'automation_inventory_add':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation inventory add', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $name = trim((string)($_POST['switch_name'] ?? ''));
                $mgmtIp = trim((string)($_POST['switch_mgmt_ip'] ?? ''));
                $profile = trim((string)($_POST['switch_profile'] ?? ''));
                $deviceId = trim((string)($_POST['switch_device_id'] ?? ''));
                $itemGroupId = trim((string)($_POST['switch_item_group_id'] ?? ''));
                $credentialMode = trim((string)($_POST['switch_credential_mode'] ?? 'global'));
                $switchAuthMethod = trim((string)($_POST['switch_auth_method'] ?? 'password'));
                $switchUsername = trim((string)($_POST['switch_ssh_username'] ?? ''));
                $switchPassword = (string)($_POST['switch_ssh_password'] ?? '');
                $switchPrivateKey = trim((string)($_POST['switch_ssh_private_key'] ?? ''));
                $snmpEnabled = isset($_POST['switch_snmp_enabled']) && (string)$_POST['switch_snmp_enabled'] === '1';
                $snmpVersion = trim((string)($_POST['switch_snmp_version'] ?? '2c'));
                $snmpCommunity = trim((string)($_POST['switch_snmp_community'] ?? ''));
                $snmpMib = trim((string)($_POST['switch_snmp_mib'] ?? ''));
                $snmpV3Username = trim((string)($_POST['switch_snmp_v3_username'] ?? ''));
                $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol((string)($_POST['switch_snmp_v3_auth_protocol'] ?? 'SHA'));
                $snmpV3AuthPassphrase = (string)($_POST['switch_snmp_v3_auth_passphrase'] ?? '');
                $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol((string)($_POST['switch_snmp_v3_priv_protocol'] ?? 'AES'));
                $snmpV3PrivPassphrase = (string)($_POST['switch_snmp_v3_priv_passphrase'] ?? '');

                if ($name === '' || $mgmtIp === '' || $profile === '') {
                    $logger->log('automation inventory add failed: name, mgmt_ip and profile are required', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                if (!in_array($credentialMode, ['global', 'individual'], true)) {
                    $credentialMode = 'global';
                }
                if (!in_array($switchAuthMethod, ['password', 'key'], true)) {
                    $switchAuthMethod = 'password';
                }
                if ($credentialMode === 'individual' && $switchUsername === '') {
                    $logger->log('automation inventory add failed: individual credentials require username', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }
                if (!in_array($snmpVersion, ['2c', '3'], true)) {
                    $snmpVersion = '2c';
                }
                $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol($snmpV3AuthProtocol);
                $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol($snmpV3PrivProtocol);

                $profileDefaults = fetchAutomationProfilesFromFile();
                try {
                    validateAutomationInventoryEntry([
                        'name' => $name,
                        'mgmt_ip' => $mgmtIp,
                        'profile' => $profile,
                        'credential_mode' => $credentialMode,
                        'ssh_auth_method' => $switchAuthMethod,
                        'device_id' => $deviceId,
                        'item_group_id' => $itemGroupId,
                        'ssh_username' => $switchUsername,
                        'ssh_password' => $switchPassword,
                        'ssh_private_key' => $switchPrivateKey
                    ], $profileDefaults);
                } catch (InvalidArgumentException $e) {
                    $logger->log('automation inventory add failed: ' . $e->getMessage(), 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }
                $profileSnmpDefaults = is_array($profileDefaults[$profile]['snmp'] ?? null) ? $profileDefaults[$profile]['snmp'] : [];

                if ($snmpMib === '') {
                    $snmpMib = trim((string)($profileSnmpDefaults['default_mib'] ?? ''));
                }
                if ($snmpVersion === '3') {
                    if ($snmpV3Username === '') {
                        $snmpV3Username = trim((string)($profileSnmpDefaults['v3_username'] ?? ''));
                    }
                    if ($snmpV3AuthPassphrase === '') {
                        $snmpV3AuthPassphrase = (string)($profileSnmpDefaults['v3_auth_passphrase'] ?? '');
                    }
                    if ($snmpV3PrivPassphrase === '') {
                        $snmpV3PrivPassphrase = (string)($profileSnmpDefaults['v3_priv_passphrase'] ?? '');
                    }
                }

                if ($snmpEnabled && $snmpVersion === '2c' && $snmpCommunity === '') {
                    $logger->log('automation inventory add failed: SNMPv2c requires community', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }
                if ($snmpEnabled && $snmpVersion === '3' && $snmpV3Username === '') {
                    $logger->log('automation inventory add failed: SNMPv3 requires username', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $mgmtIp)) {
                    $logger->log('automation inventory add failed: mgmt_ip contains invalid chars', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $automationStore = new AutomationStore();
                $structured = loadAutomationStructuredSettings($automationStore);
                $settings = $structured['settings'];
                $scripts = $structured['scripts'];
                $inventory = $structured['inventory'];

                foreach ($inventory['switches'] as $switchItem) {
                    if (!is_array($switchItem)) {
                        continue;
                    }
                    if (strcasecmp((string)($switchItem['name'] ?? ''), $name) === 0) {
                        $logger->log('automation inventory add failed: duplicate switch name', 2, echoToWeb: true);
                        redirectToScriptsTab(getScriptsTabFromRequest());
                        die();
                    }
                }

                $newSwitch = [
                    'name' => $name,
                    'mgmt_ip' => $mgmtIp,
                    'profile' => $profile,
                    'credential_mode' => $credentialMode,
                    'ssh_auth_method' => $switchAuthMethod,
                    'snmp' => [
                        'enabled' => $snmpEnabled,
                        'version' => $snmpVersion,
                        'port' => 161,
                        'timeout' => 2,
                        'retries' => 1,
                        'community' => $snmpCommunity,
                        'mib' => $snmpMib,
                        'v3_username' => $snmpV3Username,
                        'v3_auth_protocol' => $snmpV3AuthProtocol,
                        'v3_auth_passphrase' => $snmpV3AuthPassphrase,
                        'v3_priv_protocol' => $snmpV3PrivProtocol,
                        'v3_priv_passphrase' => $snmpV3PrivPassphrase
                    ]
                ];
                if ($deviceId !== '') {
                    $newSwitch['device_id'] = $deviceId;
                }
                if ($itemGroupId !== '') {
                    $newSwitch['item_group_id'] = $itemGroupId;
                }
                if ($credentialMode === 'individual') {
                    $newSwitch['ssh_username'] = $switchUsername;
                    if ($switchPassword !== '') {
                        $newSwitch['ssh_password'] = $switchPassword;
                    }
                    if ($switchPrivateKey !== '') {
                        $newSwitch['ssh_private_key'] = $switchPrivateKey;
                    }
                }

                $inventory['switches'][] = $newSwitch;

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => $settings['ssh_host'] ?? '',
                        'ssh_port' => $settings['ssh_port'] ?? 22,
                        'ssh_auth_method' => $settings['ssh_auth_method'] ?? 'password',
                        'ssh_username' => $settings['ssh_username'] ?? '',
                        'ssh_password' => $settings['ssh_password'] ?? '',
                        'ssh_private_key' => $settings['ssh_private_key'] ?? '',
                        'scripts_json' => json_encode($scripts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        'switch_inventory_json' => json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ]);
                    $logger->log('automation inventory entry added: ' . $name, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'INSERT', 'inventory_add', [
                        'switch_name' => $name,
                        'mgmt_ip' => $mgmtIp,
                        'profile' => $profile
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation inventory add failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_inventory_update':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation inventory update', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $originalName = trim((string)($_POST['original_switch_name'] ?? ''));
                $name = trim((string)($_POST['switch_name'] ?? ''));
                $mgmtIp = trim((string)($_POST['switch_mgmt_ip'] ?? ''));
                $profile = trim((string)($_POST['switch_profile'] ?? ''));
                $deviceId = trim((string)($_POST['switch_device_id'] ?? ''));
                $itemGroupId = trim((string)($_POST['switch_item_group_id'] ?? ''));
                $credentialMode = trim((string)($_POST['switch_credential_mode'] ?? 'global'));
                $switchAuthMethod = trim((string)($_POST['switch_auth_method'] ?? 'password'));
                $switchUsername = trim((string)($_POST['switch_ssh_username'] ?? ''));
                $switchPassword = (string)($_POST['switch_ssh_password'] ?? '');
                $switchPrivateKey = trim((string)($_POST['switch_ssh_private_key'] ?? ''));
                $snmpEnabled = isset($_POST['switch_snmp_enabled']) && (string)$_POST['switch_snmp_enabled'] === '1';
                $snmpVersion = trim((string)($_POST['switch_snmp_version'] ?? '2c'));
                $snmpCommunity = trim((string)($_POST['switch_snmp_community'] ?? ''));
                $snmpMib = trim((string)($_POST['switch_snmp_mib'] ?? ''));
                $snmpV3Username = trim((string)($_POST['switch_snmp_v3_username'] ?? ''));
                $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol((string)($_POST['switch_snmp_v3_auth_protocol'] ?? 'SHA'));
                $snmpV3AuthPassphrase = (string)($_POST['switch_snmp_v3_auth_passphrase'] ?? '');
                $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol((string)($_POST['switch_snmp_v3_priv_protocol'] ?? 'AES'));
                $snmpV3PrivPassphrase = (string)($_POST['switch_snmp_v3_priv_passphrase'] ?? '');

                if ($originalName === '' || $name === '' || $mgmtIp === '' || $profile === '') {
                    $logger->log('automation inventory update failed: required fields missing', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                if (!in_array($credentialMode, ['global', 'individual'], true)) {
                    $credentialMode = 'global';
                }
                if (!in_array($switchAuthMethod, ['password', 'key'], true)) {
                    $switchAuthMethod = 'password';
                }
                if ($credentialMode === 'individual' && $switchUsername === '') {
                    $logger->log('automation inventory update failed: individual credentials require username', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }
                if (!in_array($snmpVersion, ['2c', '3'], true)) {
                    $snmpVersion = '2c';
                }
                $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol($snmpV3AuthProtocol);
                $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol($snmpV3PrivProtocol);

                $profileDefaults = fetchAutomationProfilesFromFile();
                try {
                    validateAutomationInventoryEntry([
                        'name' => $name,
                        'mgmt_ip' => $mgmtIp,
                        'profile' => $profile,
                        'credential_mode' => $credentialMode,
                        'ssh_auth_method' => $switchAuthMethod,
                        'device_id' => $deviceId,
                        'item_group_id' => $itemGroupId,
                        'ssh_username' => $switchUsername,
                        'ssh_password' => $switchPassword,
                        'ssh_private_key' => $switchPrivateKey
                    ], $profileDefaults);
                } catch (InvalidArgumentException $e) {
                    $logger->log('automation inventory update failed: ' . $e->getMessage(), 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }
                $profileSnmpDefaults = is_array($profileDefaults[$profile]['snmp'] ?? null) ? $profileDefaults[$profile]['snmp'] : [];

                if ($snmpMib === '') {
                    $snmpMib = trim((string)($profileSnmpDefaults['default_mib'] ?? ''));
                }
                if ($snmpVersion === '3') {
                    if ($snmpV3Username === '') {
                        $snmpV3Username = trim((string)($profileSnmpDefaults['v3_username'] ?? ''));
                    }
                    if ($snmpV3AuthPassphrase === '') {
                        $snmpV3AuthPassphrase = (string)($profileSnmpDefaults['v3_auth_passphrase'] ?? '');
                    }
                    if ($snmpV3PrivPassphrase === '') {
                        $snmpV3PrivPassphrase = (string)($profileSnmpDefaults['v3_priv_passphrase'] ?? '');
                    }
                }
                $automationStore = new AutomationStore();
                $structured = loadAutomationStructuredSettings($automationStore);
                $settings = $structured['settings'];
                $scripts = $structured['scripts'];
                $inventory = $structured['inventory'];

                $targetIndex = -1;
                foreach ($inventory['switches'] as $index => $switchItem) {
                    if (!is_array($switchItem)) {
                        continue;
                    }
                    $switchName = (string)($switchItem['name'] ?? '');
                    if (strcasecmp($switchName, $originalName) === 0) {
                        $targetIndex = (int)$index;
                        continue;
                    }
                    if (strcasecmp($switchName, $name) === 0) {
                        $logger->log('automation inventory update failed: duplicate switch name', 2, echoToWeb: true);
                        redirectToScriptsTab(getScriptsTabFromRequest());
                        die();
                    }
                }

                if ($targetIndex < 0 || !isset($inventory['switches'][$targetIndex])) {
                    $logger->log('automation inventory update failed: original switch not found', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $existingSwitch = is_array($inventory['switches'][$targetIndex])
                    ? $inventory['switches'][$targetIndex]
                    : [];
                $existingSnmp = is_array($existingSwitch['snmp'] ?? null) ? $existingSwitch['snmp'] : [];

                if ($snmpV3Username === '' && isset($existingSnmp['v3_username'])) {
                    $snmpV3Username = trim((string)$existingSnmp['v3_username']);
                }
                if ($snmpV3AuthPassphrase === '' && isset($existingSnmp['v3_auth_passphrase'])) {
                    $snmpV3AuthPassphrase = (string)$existingSnmp['v3_auth_passphrase'];
                }
                if ($snmpV3PrivPassphrase === '' && isset($existingSnmp['v3_priv_passphrase'])) {
                    $snmpV3PrivPassphrase = (string)$existingSnmp['v3_priv_passphrase'];
                }

                if ($snmpEnabled && $snmpVersion === '2c' && $snmpCommunity === '' && isset($existingSnmp['community'])) {
                    $snmpCommunity = trim((string)$existingSnmp['community']);
                }

                if ($snmpEnabled && $snmpVersion === '2c' && $snmpCommunity === '') {
                    $logger->log('automation inventory update failed: SNMPv2c requires community', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }
                if ($snmpEnabled && $snmpVersion === '3' && $snmpV3Username === '') {
                    $logger->log('automation inventory update failed: SNMPv3 requires username', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $updatedSwitch = [
                    'name' => $name,
                    'mgmt_ip' => $mgmtIp,
                    'profile' => $profile,
                    'credential_mode' => $credentialMode,
                    'ssh_auth_method' => $switchAuthMethod,
                    'snmp' => [
                        'enabled' => $snmpEnabled,
                        'version' => $snmpVersion,
                        'port' => 161,
                        'timeout' => 2,
                        'retries' => 1,
                        'community' => $snmpCommunity,
                        'mib' => $snmpMib,
                        'v3_username' => $snmpV3Username,
                        'v3_auth_protocol' => $snmpV3AuthProtocol,
                        'v3_auth_passphrase' => $snmpV3AuthPassphrase,
                        'v3_priv_protocol' => $snmpV3PrivProtocol,
                        'v3_priv_passphrase' => $snmpV3PrivPassphrase
                    ]
                ];
                if ($deviceId !== '') {
                    $updatedSwitch['device_id'] = $deviceId;
                }
                if ($itemGroupId !== '') {
                    $updatedSwitch['item_group_id'] = $itemGroupId;
                }
                if ($credentialMode === 'individual') {
                    $updatedSwitch['ssh_username'] = $switchUsername;
                    if ($switchPassword !== '') {
                        $updatedSwitch['ssh_password'] = $switchPassword;
                    } elseif (isset($existingSwitch['ssh_password'])) {
                        $updatedSwitch['ssh_password'] = (string)$existingSwitch['ssh_password'];
                    }
                    if ($switchPrivateKey !== '') {
                        $updatedSwitch['ssh_private_key'] = $switchPrivateKey;
                    } elseif (isset($existingSwitch['ssh_private_key'])) {
                        $updatedSwitch['ssh_private_key'] = (string)$existingSwitch['ssh_private_key'];
                    }
                }

                $inventory['switches'][$targetIndex] = $updatedSwitch;

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => $settings['ssh_host'] ?? '',
                        'ssh_port' => $settings['ssh_port'] ?? 22,
                        'ssh_auth_method' => $settings['ssh_auth_method'] ?? 'password',
                        'ssh_username' => $settings['ssh_username'] ?? '',
                        'ssh_password' => $settings['ssh_password'] ?? '',
                        'ssh_private_key' => $settings['ssh_private_key'] ?? '',
                        'scripts_json' => json_encode($scripts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        'switch_inventory_json' => json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ]);
                    $logger->log('automation inventory entry updated: ' . $originalName . ' => ' . $name, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'UPDATE', 'inventory_update', [
                        'from' => $originalName,
                        'to' => $name,
                        'mgmt_ip' => $mgmtIp,
                        'profile' => $profile
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation inventory update failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_inventory_delete':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation inventory delete', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $index = (int)($_POST['inventory_index'] ?? -1);
                if ($index < 0) {
                    $logger->log('automation inventory delete failed: invalid index', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $automationStore = new AutomationStore();
                $structured = loadAutomationStructuredSettings($automationStore);
                $settings = $structured['settings'];
                $scripts = $structured['scripts'];
                $inventory = $structured['inventory'];

                if (!isset($inventory['switches'][$index])) {
                    $logger->log('automation inventory delete failed: index not found', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $removedName = (string)($inventory['switches'][$index]['name'] ?? 'unknown');
                array_splice($inventory['switches'], $index, 1);

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => $settings['ssh_host'] ?? '',
                        'ssh_port' => $settings['ssh_port'] ?? 22,
                        'ssh_auth_method' => $settings['ssh_auth_method'] ?? 'password',
                        'ssh_username' => $settings['ssh_username'] ?? '',
                        'ssh_password' => $settings['ssh_password'] ?? '',
                        'ssh_private_key' => $settings['ssh_private_key'] ?? '',
                        'scripts_json' => json_encode($scripts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        'switch_inventory_json' => json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ]);
                    $logger->log('automation inventory entry deleted: ' . $removedName, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'DELETE', 'inventory_delete', [
                        'switch_name' => $removedName
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation inventory delete failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_template_upsert':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation template upsert', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $templateId = trim((string)($_POST['template_id'] ?? ''));
                $label = trim((string)($_POST['template_label'] ?? ''));
                $description = trim((string)($_POST['template_description'] ?? ''));
                $supportedProfilesRaw = trim((string)($_POST['template_supported_profiles'] ?? ''));
                $commandsRaw = str_replace(["\r\n", "\r"], "\n", (string)($_POST['template_commands'] ?? ''));
                $usesDescriptionConvention = isset($_POST['template_uses_description_convention']) && (string)$_POST['template_uses_description_convention'] === '1';

                if ($templateId === '' || !preg_match('/^[a-zA-Z0-9_.-]+$/', $templateId)) {
                    $logger->log('automation template upsert failed: invalid template id', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                if ($label === '') {
                    $logger->log('automation template upsert failed: label is required', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $supportedProfiles = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $supportedProfilesRaw) ?: []), static function ($value) {
                    return $value !== '';
                }));

                $commands = array_values(array_filter(array_map('trim', explode("\n", $commandsRaw)), static function ($value) {
                    return $value !== '';
                }));

                if (empty($commands)) {
                    $logger->log('automation template upsert failed: at least one command is required', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                try {
                    upsertAutomationTemplateInDb($db_adapter, $templateId, [
                        'label' => $label,
                        'description' => $description,
                        'supported_profiles' => $supportedProfiles,
                        'commands' => $commands,
                        'uses_description_convention' => $usesDescriptionConvention,
                        'variables' => []
                    ], 'custom');
                    $logger->log('automation template upserted in data file: ' . $templateId, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'UPDATE', 'template_upsert', [
                        'template_id' => $templateId,
                        'commands_count' => count($commands)
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation template upsert failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_template_delete':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation template delete', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $templateId = trim((string)($_POST['template_id'] ?? ''));
                if ($templateId === '') {
                    $logger->log('automation template delete failed: template id missing', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                try {
                    $deleted = deactivateAutomationTemplateInDb($db_adapter, $templateId);
                    if (!$deleted) {
                        $logger->log('automation template delete failed: template not found', 2, echoToWeb: true);
                        redirectToScriptsTab(getScriptsTabFromRequest());
                        die();
                    }
                    $logger->log('automation template removed from data file: ' . $templateId, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'DELETE', 'template_delete', [
                        'template_id' => $templateId
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation template delete failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_profile_upsert':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation profile upsert', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $profileId = trim((string)($_POST['profile_id'] ?? ''));
                $label = trim((string)($_POST['profile_label'] ?? ''));
                $description = trim((string)($_POST['profile_description'] ?? ''));
                $enterConfig = trim((string)($_POST['profile_enter_config'] ?? ''));
                $commit = trim((string)($_POST['profile_commit'] ?? ''));
                $exitConfig = trim((string)($_POST['profile_exit_config'] ?? ''));
                $saveCommand = trim((string)($_POST['profile_save'] ?? ''));
                $writeConfig = trim((string)($_POST['profile_write_config'] ?? ''));
                $supportsCommit = isset($_POST['profile_supports_commit']) && (string)($_POST['profile_supports_commit']) === '1';

                $snmpEnabled = isset($_POST['profile_snmp_enabled']) && (string)$_POST['profile_snmp_enabled'] === '1';
                $snmpVersion = trim((string)($_POST['profile_snmp_version'] ?? '2c'));
                $snmpPort = (int)($_POST['profile_snmp_port'] ?? 161);
                $snmpTimeout = (int)($_POST['profile_snmp_timeout'] ?? 2);
                $snmpRetries = (int)($_POST['profile_snmp_retries'] ?? 1);
                $snmpCommunity = trim((string)($_POST['profile_snmp_community'] ?? ''));
                $snmpV3Username = trim((string)($_POST['profile_snmp_v3_username'] ?? ''));
                $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol((string)($_POST['profile_snmp_v3_auth_protocol'] ?? 'SHA'));
                $snmpV3AuthPassphrase = (string)($_POST['profile_snmp_v3_auth_passphrase'] ?? '');
                $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol((string)($_POST['profile_snmp_v3_priv_protocol'] ?? 'AES'));
                $snmpV3PrivPassphrase = (string)($_POST['profile_snmp_v3_priv_passphrase'] ?? '');
                $defaultMib = trim((string)($_POST['profile_snmp_default_mib'] ?? ''));
                $mibOverridesRaw = trim((string)($_POST['profile_snmp_mib_overrides'] ?? ''));

                if ($profileId === '' || !preg_match('/^[a-zA-Z0-9_.-]+$/', $profileId)) {
                    $logger->log('automation profile upsert failed: invalid profile id', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                if ($label === '') {
                    $logger->log('automation profile upsert failed: label is required', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                if (!in_array($snmpVersion, ['2c', '3'], true)) {
                    $snmpVersion = '2c';
                }
                if ($snmpPort < 1 || $snmpPort > 65535) {
                    $snmpPort = 161;
                }
                if ($snmpTimeout < 1 || $snmpTimeout > 30) {
                    $snmpTimeout = 2;
                }
                if ($snmpRetries < 0 || $snmpRetries > 10) {
                    $snmpRetries = 1;
                }
                $snmpV3AuthProtocol = normalizeSnmpV3AuthProtocol($snmpV3AuthProtocol);
                $snmpV3PrivProtocol = normalizeSnmpV3PrivProtocol($snmpV3PrivProtocol);

                if ($snmpVersion === '3' && $snmpV3Username === '') {
                    $logger->log('automation profile upsert failed: SNMPv3 requires username', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $mibOverrides = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $mibOverridesRaw) ?: []), static function ($value) {
                    return $value !== '';
                }));

                try {
                    upsertAutomationProfileInFile($profileId, [
                        'label' => $label,
                        'description' => $description,
                        'supports_commit' => $supportsCommit,
                        'enter_config' => $enterConfig,
                        'commit' => $commit,
                        'exit_config' => $exitConfig,
                        'save' => $saveCommand,
                        'write_config' => $writeConfig,
                        'snmp' => [
                            'enabled' => $snmpEnabled,
                            'version' => $snmpVersion,
                            'port' => $snmpPort,
                            'timeout' => $snmpTimeout,
                            'retries' => $snmpRetries,
                            'community' => $snmpCommunity,
                            'v3_username' => $snmpV3Username,
                            'v3_auth_protocol' => $snmpV3AuthProtocol,
                            'v3_auth_passphrase' => $snmpV3AuthPassphrase,
                            'v3_priv_protocol' => $snmpV3PrivProtocol,
                            'v3_priv_passphrase' => $snmpV3PrivPassphrase,
                            'default_mib' => $defaultMib,
                            'mib_overrides' => $mibOverrides
                        ]
                    ]);
                    $logger->log('automation profile upserted in data file: ' . $profileId, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'UPDATE', 'profile_upsert', [
                        'profile_id' => $profileId,
                        'snmp_default_mib' => $defaultMib,
                        'snmp_mib_overrides' => count($mibOverrides)
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation profile upsert failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_profile_delete':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation profile delete', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $profileId = trim((string)($_POST['profile_id'] ?? ''));
                if ($profileId === '') {
                    $logger->log('automation profile delete failed: profile id missing', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $structured = loadAutomationStructuredSettings(new AutomationStore());
                $inventory = $structured['inventory'];
                $switches = is_array($inventory['switches'] ?? null) ? $inventory['switches'] : [];
                foreach ($switches as $switchItem) {
                    if (!is_array($switchItem)) {
                        continue;
                    }

                    if ((string)($switchItem['profile'] ?? '') === $profileId) {
                        $logger->log('automation profile delete blocked: profile is in use by switch inventory', 2, echoToWeb: true);
                        redirectToScriptsTab(getScriptsTabFromRequest());
                        die();
                    }
                }

                $templates = fetchAutomationTemplatesFromDb($db_adapter);
                foreach ($templates as $templateId => $templateData) {
                    if (!is_array($templateData)) {
                        continue;
                    }

                    $supportedProfiles = is_array($templateData['supported_profiles'] ?? null) ? $templateData['supported_profiles'] : [];
                    if (in_array($profileId, $supportedProfiles, true)) {
                        $logger->log('automation profile delete blocked: profile is referenced by template ' . $templateId, 2, echoToWeb: true);
                        redirectToScriptsTab(getScriptsTabFromRequest());
                        die();
                    }
                }

                try {
                    $deleted = deactivateAutomationProfileInFile($profileId);
                    if (!$deleted) {
                        $logger->log('automation profile delete failed: profile not found', 2, echoToWeb: true);
                        redirectToScriptsTab(getScriptsTabFromRequest());
                        die();
                    }
                    $logger->log('automation profile removed from data file: ' . $profileId, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'DELETE', 'profile_delete', [
                        'profile_id' => $profileId
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation profile delete failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_scripts':
                // check if user is admin
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation settings', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $automationStore = new AutomationStore();

                try {
                    $profileDefaults = fetchAutomationProfilesFromFile();
                    $validatedInventoryJson = validateAutomationInventoryJson((string)($_POST['switch_inventory_json'] ?? '{"switches": []}'), $profileDefaults);
                    $automationStore->saveSettings([
                        'ssh_host' => $_POST['ssh_host'] ?? '',
                        'ssh_port' => $_POST['ssh_port'] ?? 22,
                        'ssh_auth_method' => $_POST['ssh_auth_method'] ?? 'password',
                        'ssh_username' => $_POST['ssh_username'] ?? '',
                        'ssh_password' => $_POST['ssh_password'] ?? '',
                        'ssh_private_key' => $_POST['ssh_private_key'] ?? '',
                        'scripts_json' => $_POST['scripts_json'] ?? '{}',
                        'switch_inventory_json' => $validatedInventoryJson
                    ]);
                    $logger->log('automation settings updated', 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'UPDATE', 'settings_save', [
                        'tab' => getScriptsTabFromRequest()
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation settings update failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }
                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_settings_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation ssh settings', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $automationStore = new AutomationStore();
                $currentSettings = $automationStore->getSettings();

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => $_POST['ssh_host'] ?? '',
                        'ssh_port' => $_POST['ssh_port'] ?? 22,
                        'ssh_auth_method' => $_POST['ssh_auth_method'] ?? 'password',
                        'ssh_username' => $_POST['ssh_username'] ?? '',
                        'ssh_password' => $_POST['ssh_password'] ?? '',
                        'ssh_private_key' => $_POST['ssh_private_key'] ?? '',
                        'scripts_json' => (string)($currentSettings['scripts_json'] ?? '{}'),
                        'switch_inventory_json' => (string)($currentSettings['switch_inventory_json'] ?? '{"switches": []}')
                    ]);
                    $logger->log('automation ssh settings updated', 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'UPDATE', 'settings_save_ssh', [
                        'tab' => getScriptsTabFromRequest()
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation ssh settings update failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }
                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_run_scheduler':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for manual scheduler run', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $schedulerOutput = [];
                $schedulerExitCode = 1;
                exec('php ' . escapeshellarg(__DIR__ . '/scheduler.php') . ' 2>&1', $schedulerOutput, $schedulerExitCode);

                $outputPreview = implode("\n", array_slice($schedulerOutput, 0, 5));
                $logger->log(
                    'manual scheduler run finished: exit=' . $schedulerExitCode
                        . ' lines=' . count($schedulerOutput)
                        . ($outputPreview !== '' ? ' preview=' . $outputPreview : ''),
                    $schedulerExitCode === 0 ? 1 : 2
                );
                $logger->log(
                    $schedulerExitCode === 0
                        ? 'Scheduler wurde manuell ausgefuehrt.'
                        : 'Scheduler-Ausfuehrung fehlgeschlagen. Details stehen im Portflow-Log.',
                    $schedulerExitCode === 0 ? 1 : 2,
                    echoToWeb: true
                );
                logAutomationChange($db_adapter, 'UPDATE', 'manual_scheduler_run', [
                    'exit_code' => $schedulerExitCode,
                    'output_lines' => count($schedulerOutput)
                ]);
                redirectToScriptsTab(getScriptsTabFromRequest());
                break;
            case 'automation_test_ssh':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation ssh test', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $automationStore = new AutomationStore();
                $automationFormDataOverride = [
                    'ssh_host' => $_POST['ssh_host'] ?? '',
                    'ssh_port' => (int)($_POST['ssh_port'] ?? 22),
                    'ssh_auth_method' => $_POST['ssh_auth_method'] ?? 'password',
                    'ssh_username' => $_POST['ssh_username'] ?? '',
                    'ssh_password' => '',
                    'ssh_private_key' => $_POST['ssh_private_key'] ?? '',
                    'scripts_json' => $_POST['scripts_json'] ?? '{}',
                    'switch_inventory_json' => $_POST['switch_inventory_json'] ?? '{"switches": []}'
                ];

                $automationTestResult = runAutomationSshTest($_POST, $automationStore, $logger);

                include_once __DIR__ . '/includes/header.php';
                $site = 'scripts';
                $_GET['tab'] = getScriptsTabFromRequest();
                break;
            case 'automation_test_snmp':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation snmp test', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $automationStore = new AutomationStore();
                $automationFormDataOverride = [
                    'ssh_host' => $_POST['ssh_host'] ?? '',
                    'ssh_port' => (int)($_POST['ssh_port'] ?? 22),
                    'ssh_auth_method' => $_POST['ssh_auth_method'] ?? 'password',
                    'ssh_username' => $_POST['ssh_username'] ?? '',
                    'ssh_password' => '',
                    'ssh_private_key' => $_POST['ssh_private_key'] ?? '',
                    'scripts_json' => $_POST['scripts_json'] ?? '{}',
                    'switch_inventory_json' => $_POST['switch_inventory_json'] ?? '{"switches": []}'
                ];

                $automationTestResult = runAutomationSnmpTest($_POST, $automationStore, $logger);

                include_once __DIR__ . '/includes/header.php';
                $site = 'scripts';
                $_GET['tab'] = getScriptsTabFromRequest();
                break;
            case 'automation_snmp_scan':
            case 'automation_snmp_scan_all':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }
                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for snmp scan', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                include_once __DIR__ . '/includes/core/db_adapter.php';
                include_once __DIR__ . '/includes/core/snmp_scanner.php';

                $automationStore = new AutomationStore();
                $db_adapter = new \Portflow\Core\DatabaseAdapter();
                $scanner = new \Portflow\Core\SnmpScanner($db_adapter, $automationStore, $logger);

                $userUuid = $_SESSION['user']['uuid'] ?? null;
                $targets = [];
                if ($set === 'automation_snmp_scan') {
                    $targets[] = trim((string)($_POST['snmp_switch_name'] ?? ''));
                } else {
                    $savedSettings = $automationStore->getSettings();
                    $inv = json_decode((string)($savedSettings['switch_inventory_json'] ?? '{}'), true);
                    foreach (($inv['switches'] ?? []) as $sw) {
                        if (is_array($sw) && trim((string)($sw['name'] ?? '')) !== '') {
                            $targets[] = trim((string)$sw['name']);
                        }
                    }
                }
                $targets = array_values(array_filter(array_unique($targets), static fn($n) => $n !== ''));

                if (empty($targets)) {
                    $automationTestResult = [
                        'ok' => false,
                        'title' => 'SNMP Scan',
                        'output' => $set === 'automation_snmp_scan'
                            ? 'Kein Switch fuer den manuellen SNMP-Scan uebergeben.'
                            : 'Kein Switch im gespeicherten Inventar gefunden. Bitte das Switch-Inventar zuerst speichern.',
                    ];

                    include_once __DIR__ . '/includes/header.php';
                    $site = 'scripts';
                    $_GET['tab'] = getScriptsTabFromRequest();
                    break;
                }

                $reports = [];
                $okCount = 0;
                $failCount = 0;
                foreach ($targets as $name) {
                    $r = $scanner->scanSwitch($name, $set === 'automation_snmp_scan' ? 'manual' : 'manual_all', $userUuid);
                    if ($r['ok']) {
                        $okCount++;
                        $nodeIpSources = is_array($r['node_ip_sources'] ?? null) ? $r['node_ip_sources'] : [];
                        $sourceSummary = '-';
                        if (!empty($nodeIpSources)) {
                            ksort($nodeIpSources);
                            $parts = [];
                            foreach ($nodeIpSources as $source => $count) {
                                $parts[] = $source . '=' . (int)$count;
                            }
                            $sourceSummary = implode(', ', $parts);
                        }
                        $reports[] = sprintf(
                            'OK   %s -- interfaces=%d findings=%d ips=%d mapped=%d node_ip_sources=%s run=%s',
                            $name,
                            (int)($r['interfaces'] ?? 0),
                            (int)($r['findings'] ?? 0),
                            (int)($r['discovered_ips'] ?? 0),
                            (int)($r['mapped_ips'] ?? 0),
                            $sourceSummary,
                            substr((string)($r['run_uuid'] ?? ''), 0, 8)
                        );
                    } else {
                        $failCount++;
                        $reports[] = sprintf('FAIL %s -- %s', $name, (string)($r['error'] ?? 'unbekannter Fehler'));
                    }
                }

                $automationTestResult = [
                    'ok' => ($failCount === 0),
                    'title' => 'SNMP Scan',
                    'output' => sprintf("Switches: %d  ok=%d  fail=%d\n\n%s", count($targets), $okCount, $failCount, implode("\n", $reports)),
                ];

                include_once __DIR__ . '/includes/header.php';
                $site = 'scripts';
                $_GET['tab'] = getScriptsTabFromRequest();
                break;
            default:
                $logger->log('no set parameter', 2, echoToWeb: true);
                header('Location: ?site=appearance');
                die();
            }
    // get
    } elseif ($_SERVER['REQUEST_METHOD'] == 'GET' && in_array((string)$get, ['details', 'changelog_details'], true)) {
        $uuid = $_GET['uuid'] ?? null;

        // check if user is admin
        if ($role !== 'admin') {
            $logger->log('user is not admin', 2, echoToWeb: true);
            header('Location: ?site=access');
            die();
        }

        // check inputs
        if (empty($uuid)) {
            $logger->log('uuid empty', 2, echoToWeb: true);
            header('Location: ?site=access');
            die();
        }

        if ($get === 'details') {
            $query = "SELECT * FROM users WHERE uuid = :uuid";
            $result = $db_adapter->db_query($query, ['uuid' => $uuid]);
            $result = !empty($result) ? $result[0] : null;
        } else {
            $query = "SELECT c.uuid,
                             c.operation,
                             c.changed_table,
                             c.changed_row,
                             c.changed_data,
                             c.users,
                             u.username,
                             TO_CHAR(c.changed, 'YYYY-MM-DD HH24:MI:SS') AS changed_at
                      FROM changelog c
                      LEFT JOIN users u ON u.uuid = c.users
                      WHERE c.uuid = :uuid
                      LIMIT 1";
            $result = $db_adapter->db_query($query, ['uuid' => $uuid]);
            $result = !empty($result) ? $result[0] : null;
        }

        if (!empty($result)) {
            echo json_encode($result);
        }
        die();
    } else {
        // import header
        include_once __DIR__ . '/includes/header.php';

        // get site
        $site = $_GET['site'] ?? NULL;
        $activeScriptsTab = getScriptsTabFromRequest();
        $activeConfigTab = getConfigTabFromRequest();
    }
    $settingsNavBaseClasses = 'settings-nav-item block rounded-full border px-3 py-2.5 font-semibold whitespace-nowrap transition';
    $settingsNavActiveClasses = 'settings-nav-item-active';
    $settingsNavSubBaseClasses = 'settings-nav-subitem settings-script-tab block rounded-full border px-3 py-2 text-sm font-semibold whitespace-nowrap transition';
    $settingsNavSubActiveClasses = 'settings-nav-subitem-active';
?>
<style>
    .settings-nav-item {
        border: 1px solid var(--pf-border);
        background: var(--pf-surface-alt);
        color: var(--pf-text);
    }

    .settings-nav-item:hover {
        background: var(--pf-hover);
    }

    .settings-nav-item-active {
        background: var(--pf-accent-600);
        border-color: var(--pf-accent-600);
        color: #ffffff;
    }

    .settings-nav-subitem {
        border: 1px solid var(--pf-border);
        background: var(--pf-surface-alt);
        color: var(--pf-text);
    }

    .settings-nav-subitem:hover {
        background: var(--pf-hover);
    }

    .settings-nav-subitem-active {
        background: var(--pf-accent-700);
        border-color: var(--pf-accent-700);
        color: #ffffff;
    }

    .settings-content input[type="text"],
    .settings-content input[type="email"],
    .settings-content input[type="password"],
    .settings-content input[type="number"],
    .settings-content select,
    .settings-content textarea {
        border: 1px solid var(--pf-border);
        border-radius: 9999px;
        background: var(--pf-surface-alt);
        color: var(--pf-text);
    }

    .settings-content textarea {
        border-radius: 1rem;
    }

    .settings-content table {
        border: 1px solid var(--pf-border);
        border-radius: 0.9rem;
        overflow: hidden;
        background: var(--pf-surface-alt);
        width: 100%;
    }

    .settings-content th,
    .settings-content td {
        padding: 0.62rem 0.78rem;
        font-size: 0.875rem;
        line-height: 1.35;
    }

    .settings-content thead {
        background: var(--pf-surface-soft) !important;
    }

    .settings-content thead th {
        color: var(--pf-text);
        font-weight: 700;
    }

    .settings-content button,
    .settings-content input[type="submit"] {
        border-radius: 9999px;
        font-weight: 600;
    }

    .settings-content button:not(.h-10):not(.w-10),
    .settings-content input[type="submit"] {
        padding: 0.48rem 0.95rem;
        font-size: 0.875rem;
        line-height: 1.2;
    }

    .settings-content .text-xl,
    .settings-content .text-2xl {
        color: var(--pf-text);
        font-weight: 700;
    }

    .settings-content label,
    .settings-content summary,
    .settings-content strong {
        color: var(--pf-text);
    }

    .settings-content .text-gray-500,
    .settings-content .text-gray-600,
    .settings-content .text-slate-500 {
        color: var(--pf-muted);
    }

    .settings-content .text-gray-700,
    .settings-content .text-gray-800,
    .settings-content .text-gray-900,
    .settings-content .text-slate-900 {
        color: var(--pf-text);
    }

    .settings-content .text-xs {
        font-size: 0.8rem;
    }

    .settings-content input[type="checkbox"] {
        width: 0.95rem;
        height: 0.95rem;
        accent-color: var(--pf-accent-600);
        cursor: pointer;
    }

    .settings-table-wrap {
        border: 1px solid var(--pf-border);
        border-radius: 0.9rem;
        overflow: hidden;
        background: var(--pf-surface-alt);
    }

    .settings-table-wrap table {
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        margin-bottom: 0 !important;
        color: var(--pf-text) !important;
    }

    .settings-data-row:hover {
        background: var(--pf-hover);
    }
</style>
<div class="mx-4 mb-4 mt-0 grid grid-cols-1 gap-4 lg:h-[calc(100vh-7.2rem)] lg:grid-cols-[minmax(220px,18rem)_minmax(0,1fr)] lg:items-stretch">
    <div class="settings-sidebar flex min-h-0 flex-col gap-6 overflow-y-auto rounded-2xl border p-4" style="background: var(--pf-surface); border-color: var(--pf-border);">  
        <p class="text-base font-semibold text-slate-900"><?php echo $lang['settings']; ?></p>
        <ul class="settings-nav flex items-center gap-2 overflow-x-auto pb-1 lg:grid lg:gap-3 lg:overflow-visible lg:pb-0" id="itam_nav">
            <a class="flex-none lg:flex-auto" href="?site=appearance"><li class="<?php echo $settingsNavBaseClasses . ' ' . (($site == 'appearance' || $site == NULL) ? $settingsNavActiveClasses : '');?>"><?php echo $lang['appearance']; ?></li></a>
            <?php echo ($role !== 'ldap') ? '<a class="flex-none lg:flex-auto" href="?site=account"><li class="' . $settingsNavBaseClasses . ' ' . ($site == 'account' ? $settingsNavActiveClasses : '') . '">' . $lang['account'] . '</li></a>' : ''; ?>
            <a class="flex-none lg:flex-auto" href="?site=notifications"><li class="<?php echo $settingsNavBaseClasses . ' ' . (($site == 'notifications') ? $settingsNavActiveClasses : '');?>"><?php echo $lang['notifications']; ?></li></a>
            <?php echo ($role == 'admin') ? '<a class="flex-none lg:flex-auto" href="?site=configuration"><li class="' . $settingsNavBaseClasses . ' ' . ($site == 'configuration' ? $settingsNavActiveClasses : '') . '">' . $lang['configuration'] . '</li></a>' : ''; ?>
            <?php if ($role == 'admin' && $site == 'configuration') : ?>
                <div class="settings-subnav mt-0 border-l-2 pl-2 lg:-mt-1 lg:grid lg:gap-2" style="border-color: var(--pf-accent-500);">
                    <a href="?site=configuration&tab=system"><li class="<?php echo $settingsNavSubBaseClasses . ' ' . (($activeConfigTab === 'system') ? $settingsNavSubActiveClasses : ''); ?>" data-config-tab="system">System</li></a>
                    <a href="?site=configuration&tab=updater"><li class="<?php echo $settingsNavSubBaseClasses . ' ' . (($activeConfigTab === 'updater') ? $settingsNavSubActiveClasses : ''); ?>" data-config-tab="updater">Updater</li></a>
                    <a href="?site=configuration&tab=notifications"><li class="<?php echo $settingsNavSubBaseClasses . ' ' . (($activeConfigTab === 'notifications') ? $settingsNavSubActiveClasses : ''); ?>" data-config-tab="notifications">Benachrichtigungen</li></a>
                </div>
            <?php endif; ?>
            <?php echo ($role == 'admin') ? '<a class="flex-none lg:flex-auto" href="?site=scripts"><li class="' . $settingsNavBaseClasses . ' ' . ($site == 'scripts' ? $settingsNavActiveClasses : '') . '">' . $lang['scripts'] . '</li></a>' : ''; ?>
            <?php if ($role == 'admin' && $site == 'scripts') : ?>
                <div class="settings-subnav mt-0 border-l-2 pl-2 lg:-mt-1 lg:grid lg:gap-2" style="border-color: var(--pf-accent-500);">
                    <a href="?site=scripts&tab=switch"><li class="<?php echo $settingsNavSubBaseClasses . ' ' . (($activeScriptsTab === 'switch') ? $settingsNavSubActiveClasses : ''); ?>" data-script-tab="switch">Switch/SSH</li></a>
                    <a href="?site=scripts&tab=profiles"><li class="<?php echo $settingsNavSubBaseClasses . ' ' . (($activeScriptsTab === 'profiles') ? $settingsNavSubActiveClasses : ''); ?>" data-script-tab="profiles">Profile</li></a>
                    <a href="?site=scripts&tab=templates"><li class="<?php echo $settingsNavSubBaseClasses . ' ' . (($activeScriptsTab === 'templates') ? $settingsNavSubActiveClasses : ''); ?>" data-script-tab="templates">Templates</li></a>
                    <a href="?site=scripts&tab=history"><li class="<?php echo $settingsNavSubBaseClasses . ' ' . (($activeScriptsTab === 'history') ? $settingsNavSubActiveClasses : ''); ?>" data-script-tab="history">Historie</li></a>
                </div>
            <?php endif; ?>
            <?php echo ($role == 'admin') ? '<a class="flex-none lg:flex-auto" href="?site=access"><li class="' . $settingsNavBaseClasses . ' ' . ($site == 'access' ? $settingsNavActiveClasses : '') . '">' . $lang['access_management'] . '</li></a>' : ''; ?>
            <?php echo ($role == 'admin') ? '<a class="flex-none lg:flex-auto" href="?site=changelog"><li class="' . $settingsNavBaseClasses . ' ' . ($site == 'changelog' ? $settingsNavActiveClasses : '') . '">Changelog</li></a>' : ''; ?>
        </ul>
    </div>
    <div class="settings-content relative min-h-0 overflow-y-auto rounded-2xl border p-4" style="background: var(--pf-surface-alt); border-color: var(--pf-border);">
<?php 
switch ($site) {        
    case 'account':
        // check if user is ldap
        if ($role == 'ldap') {
            $logger->log('user is ldap', 2, echoToWeb: true);
            header('Location: ?site=appearance');
            die();
        }

        $csrf = $auth->csrf();
        echo <<<HTML
            <div class="h-fit w-full p-4">
                <div class="h-fit max-w-lg">
                    <div class="text-xl font-bold pb-6">Username</div>
                    <form action="?set=username" method="post">
                        <div class="pb-6">
                            <label class="block mb-2" for="username">
                                New Username
                            </label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="username" type="text" placeholder="Username" name="username" min="2" max="255">
                        </div>
                        <div class="pb-6">
                            <label class="block mb-2" for="password">
                                Password
                            </label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="Password" name="password" min="8" max="128">
                        </div>
                        <div class="pb-6 flex justify-between items-center">
                            <input type="hidden" name="csrf" value="$csrf">
                            <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Ändern">
                        </div>
                    </form>
                </div>
                <div class="h-fit max-w-lg">
                    <div class="text-xl font-bold py-6">E-Mail</div>
                    <form action="?set=email" method="post">
                        <div class="pb-6">
                            <label class="block mb-2" for="email">
                                New E-Mail
                            </label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="email" type="email" placeholder="E-Mail" name="email" min="3" max="254">
                            </div>
                        <div class="pb-6">
                            <label class="block mb-2" for="password">
                                Password
                            </label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="Password" name="password" min="8" max="128">
                        </div>
                        <div class="pb-6 flex justify-between items-center">
                            <input type="hidden" name="csrf" value="$csrf">
                            <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Ändern">
                        </div>
                    </form>
                </div>
                <div class="h-fit max-w-lg">
                    <div class="text-xl font-bold py-6">Password</div>
                    <form action="?set=password" method="post">
                        <div class="pb-6">
                            <label class="block mb-2" for="password">
                                New Password
                            </label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="Password" name="password" min="8" max="128">
                        </div>
                        <div class="pb-6">
                            <label class="block mb-2" for="password">
                                Old Password
                            </label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="old_password" type="password" placeholder="Password" name="old_password" min="8" max="128">
                        </div>
                        <div class="pb-6 flex justify-between items-center">
                            <input type="hidden" name="csrf" value="$csrf">
                            <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Ändern">
                        </div>
                    </div>
                </form>
            </div>
        HTML;
        break;
    case 'notifications':
        $csrf = $auth->csrf();
        $userSettings = getSessionUserSettings();
        $notificationSettings = is_array($userSettings['notifications'] ?? null) ? $userSettings['notifications'] : [];
        $notificationLevel = (string)($userSettings['notifications']['level'] ?? 'minimal');
        $notificationChannel = (string)($userSettings['notifications']['channel'] ?? 'mail');
        $notificationTelegramChatId = (string)($userSettings['notifications']['telegram_chat_id'] ?? '');
        $channelReadiness = getNotificationChannelReadiness();
        $availableChannels = getAvailableNotificationChannels();
        $slackChannelAvailable = in_array('slack', $availableChannels, true);
        $telegramChannelAvailable = in_array('telegram', $availableChannels, true);
        $mailChannelAvailable = in_array('mail', $availableChannels, true);
        if (!in_array($notificationChannel, $availableChannels, true)) {
            $notificationChannel = $mailChannelAvailable ? 'mail' : (string)($availableChannels[0] ?? 'mail');
        }

        $levelOff = $notificationLevel === 'off' ? 'selected' : '';
        $levelMinimal = $notificationLevel === 'minimal' ? 'selected' : '';
        $levelProgress = $notificationLevel === 'progress' ? 'selected' : '';
        $levelAll = $notificationLevel === 'all' ? 'selected' : '';

        $slackStatus = 'deaktiviert';
        $notificationSlackWebhook = configNormalizeEnvValue((string)($notificationSettings['slack_webhook_url'] ?? ''));
        if (!empty($channelReadiness['slack_enabled'])) {
            if ($notificationSlackWebhook !== '') {
                $slackStatus = 'bereit (persoenlicher Webhook gesetzt)';
            } else {
                $slackStatus = !empty($channelReadiness['slack'])
                    ? 'bereit (globaler Webhook aktiv)'
                    : 'aktiv, aber unvollstaendig konfiguriert';
            }
        }

        $telegramStatus = 'deaktiviert';
        if (!empty($channelReadiness['telegram_enabled'])) {
            if (empty($channelReadiness['telegram'])) {
                $telegramStatus = 'aktiv, aber Bot-Token fehlt';
            } elseif ($notificationTelegramChatId !== '') {
                $telegramStatus = 'bereit (persoenliche Chat-ID gesetzt)';
            } elseif (!empty($channelReadiness['telegram_global_chat_id'])) {
                $telegramStatus = 'bereit (globaler Fallback aktiv)';
            } else {
                $telegramStatus = 'bereit, aber persoenliche Chat-ID empfohlen';
            }
        }

        $slackStatusSafe = escapeSettingValue($slackStatus);
        $telegramStatusSafe = escapeSettingValue($telegramStatus);
        $notificationSlackWebhookSafe = escapeSettingValue($notificationSlackWebhook);
        $notificationTelegramChatIdSafe = escapeSettingValue($notificationTelegramChatId);
        $notificationFeedback = getAndClearSettingsFeedback();
        $notificationFeedbackHtml = '';
        if (isset($notificationFeedback['notifications']) && is_array($notificationFeedback['notifications'])) {
            $feedbackEntry = $notificationFeedback['notifications'];
            $feedbackMessage = escapeSettingValue((string)($feedbackEntry['message'] ?? ''));
            $feedbackClasses = !empty($feedbackEntry['ok'])
                ? 'mb-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 whitespace-pre-wrap'
                : 'mb-4 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900 whitespace-pre-wrap';
            $notificationFeedbackHtml = '<div class="' . $feedbackClasses . '">' . $feedbackMessage . '</div>';
        }
        $telegramLinkToken = escapeSettingValue((string)($notificationSettings['telegram_link_token'] ?? ''));
        $telegramLinkStartedAt = escapeSettingValue((string)($notificationSettings['telegram_link_started_at'] ?? ''));
        $telegramLinkConfirmedAt = escapeSettingValue((string)($notificationSettings['telegram_link_confirmed_at'] ?? ''));
        $telegramLinkUsername = escapeSettingValue((string)($notificationSettings['telegram_link_username'] ?? ''));
        $telegramLinkCommand = $telegramLinkToken !== '' ? '/start ' . $telegramLinkToken : '/start <token>';
        $telegramLinkCommandSafe = escapeSettingValue($telegramLinkCommand);
        $slackFieldHiddenClass = $notificationChannel === 'slack' && $slackChannelAvailable ? '' : ' hidden';
        $telegramFieldHiddenClass = $notificationChannel === 'telegram' && $telegramChannelAvailable ? '' : ' hidden';
        $mailInfoHiddenClass = $notificationChannel === 'mail' ? '' : ' hidden';
        $slackAvailableJs = $slackChannelAvailable ? 'true' : 'false';
        $telegramAvailableJs = $telegramChannelAvailable ? 'true' : 'false';
        $channelOptionsHtml = '';
        foreach ($availableChannels as $channelOption) {
            $selected = $notificationChannel === $channelOption ? 'selected' : '';
            $label = strtoupper($channelOption);
            if ($channelOption === 'mail') {
                $label = 'Mail';
            } elseif ($channelOption === 'slack') {
                $label = 'Slack';
            } elseif ($channelOption === 'telegram') {
                $label = 'Telegram';
            }
            $channelOptionsHtml .= '<option value="' . escapeSettingValue($channelOption) . '" ' . $selected . '>' . escapeSettingValue($label) . '</option>';
        }

        echo <<<HTML
        <div class="h-fit w-full p-4">
            <div class="max-w-3xl">
                <div class="text-xl font-bold pb-2">Benachrichtigungen</div>
                <p class="text-sm text-gray-600 pb-6">Persoenliche Benachrichtigungseinstellungen mit kanalbasiertem Versand. Slack- und Telegram-Felder erscheinen nur, wenn der Kanal global verfuegbar und von dir ausgewaehlt ist.</p>
                {$notificationFeedbackHtml}

                <form action="?set=notification_preferences" method="post" class="space-y-5">
                    <input type="hidden" name="csrf" value="$csrf">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <label class="block mb-2 text-sm font-semibold" for="notification_level">Benachrichtigungslevel</label>
                            <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="notification_level" name="notification_level">
                                <option value="off" $levelOff>Aus</option>
                                <option value="minimal" $levelMinimal>Minimal</option>
                                <option value="progress" $levelProgress>Fortschritt</option>
                                <option value="all" $levelAll>Alles</option>
                            </select>
                            <div class="pt-2 text-xs text-gray-600">Minimal: fehlgeschlagene Logins. Fortschritt: zusaetzlich Tageszusammenfassungen und Abweichungen. Alles: auch erfolgreiche Logins.</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <label class="block mb-2 text-sm font-semibold" for="notification_channel">Kanal</label>
                            <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="notification_channel" name="notification_channel">
                                $channelOptionsHtml
                            </select>
                            <div class="pt-2 text-xs text-gray-600">Es werden nur Kanaele angeboten, die global aktiviert und einsatzbereit sind.</div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 px-4 py-3 text-sm text-gray-700">
                        <div class="font-semibold text-gray-900 pb-2">Kanalstatus</div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                            <div class="rounded-xl bg-slate-50 px-3 py-2">Mail: bereit</div>
                            <div class="rounded-xl bg-slate-50 px-3 py-2">Slack: $slackStatusSafe</div>
                            <div class="rounded-xl bg-slate-50 px-3 py-2">Telegram: $telegramStatusSafe</div>
                        </div>
                    </div>

                    <div id="notification_mail_info" class="rounded-2xl border border-slate-200 px-4 py-4 text-sm text-gray-700{$mailInfoHiddenClass}">
                        Mail wird ueber den global konfigurierten Versand in der System-Konfiguration ausgeliefert. Fuer Mail sind keine zusaetzlichen persoenlichen Felder erforderlich.
                    </div>

                    <div id="notification_slack_fields" class="rounded-2xl border border-slate-200 px-4 py-4 text-sm text-gray-700{$slackFieldHiddenClass}">
                        <label class="block mb-2 text-sm font-semibold" for="notification_slack_webhook_url">Slack Webhook (optional, pro Nutzer/Team)</label>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="notification_slack_webhook_url" type="text" name="notification_slack_webhook_url" value="{$notificationSlackWebhookSafe}" placeholder="https://hooks.slack.com/services/...">
                        <div class="pt-2 text-xs text-gray-600">Wenn gesetzt, werden Slack-Benachrichtigungen ueber deinen persoenlichen oder Team-Webhook gesendet. Ohne eigenen Webhook nutzt Portflow den globalen Slack-Kanal, sofern vorhanden.</div>
                    </div>

                    <div id="notification_telegram_fields" class="rounded-2xl border border-slate-200 px-4 py-4 text-sm text-gray-700 space-y-4{$telegramFieldHiddenClass}">
                        <div>
                            <label class="block mb-2 text-sm font-semibold" for="notification_telegram_chat_id">Telegram Chat-ID (optional, pro Nutzer)</label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="notification_telegram_chat_id" type="text" name="notification_telegram_chat_id" value="{$notificationTelegramChatIdSafe}" placeholder="z.B. 123456789 oder -100...">
                            <div class="pt-2 text-xs text-gray-600">Wenn gesetzt, werden Telegram-Benachrichtigungen an deine persoenliche Chat-ID gesendet. Andernfalls wird die globale Chat-ID verwendet, wenn sie vorhanden ist.</div>
                        </div>

                        <div class="rounded-xl bg-slate-50 px-4 py-4 text-sm text-gray-700 space-y-3">
                            <div class="font-semibold text-gray-900">Telegram-Onboarding</div>
                            <div>Gefuehrter Flow: Link-Code erzeugen, dem Bot <span class="font-mono">{$telegramLinkCommandSafe}</span> schicken, dann Verknuepfung pruefen.</div>
                            <div>Aktiver Link-Code: <span class="font-mono">{$telegramLinkToken}</span></div>
                            <div>Link gestartet: <span class="font-mono">{$telegramLinkStartedAt}</span></div>
                            <div>Letzte erfolgreiche Verknuepfung: <span class="font-mono">{$telegramLinkConfirmedAt}</span></div>
                            <div>Telegram Username: <span class="font-mono">{$telegramLinkUsername}</span></div>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" formaction="?set=notification_telegram_link_start" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline">Link-Code erzeugen</button>
                                <button type="submit" formaction="?set=notification_telegram_link_refresh" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline">Telegram-Verknuepfung pruefen</button>
                                <button type="submit" formaction="?set=notification_telegram_disconnect" class="bg-slate-600 hover:bg-slate-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline">Telegram trennen</button>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 px-4 py-3 text-sm text-gray-700">
                        Versandplanung fuer Tageszusammenfassungen wird ueber <span class="font-mono">NOTIFICATION_DAILY_TIME</span> und <span class="font-mono">NOTIFICATION_TIMEZONE</span> in der .env gesteuert.
                    </div>

                    <div class="pb-2 flex justify-between items-center">
                        <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Benachrichtigungen speichern">
                    </div>
                </form>
            </div>
        </div>
        <script>
            (function() {
                const channelSelect = document.getElementById('notification_channel');
                const mailInfo = document.getElementById('notification_mail_info');
                const slackFields = document.getElementById('notification_slack_fields');
                const telegramFields = document.getElementById('notification_telegram_fields');
                const slackAvailable = {$slackAvailableJs};
                const telegramAvailable = {$telegramAvailableJs};

                function setHiddenState(element, hidden) {
                    if (!element) {
                        return;
                    }
                    element.classList.toggle('hidden', hidden);
                }

                function updateNotificationChannelFields() {
                    if (!channelSelect) {
                        return;
                    }

                    const selectedChannel = channelSelect.value;
                    setHiddenState(mailInfo, selectedChannel !== 'mail');
                    setHiddenState(slackFields, selectedChannel !== 'slack' || !slackAvailable);
                    setHiddenState(telegramFields, selectedChannel !== 'telegram' || !telegramAvailable);
                }

                if (channelSelect) {
                    channelSelect.addEventListener('change', updateNotificationChannelFields);
                }

                updateNotificationChannelFields();
            })();
        </script>
        HTML;
        break;
    case 'configuration':
        // check if user is admin
        if ($role !== 'admin') {
            $logger->log('user is not admin', 2, echoToWeb: true);
            header('Location: ?site=appearance');
            die();
        }

        $csrf = $auth->csrf();
        $cfgState = configReadAndClearFeedback();
        $cfgFeedback = $cfgState['feedback'];
        $cfgFormData = $cfgState['form_data'];

        $dbDefaults = [
            'db_type' => (string)DB_TYPE,
            'db_server' => (string)DB_SERVER,
            'db_port' => (string)DB_PORT,
            'db_name' => (string)DB_NAME,
            'db_user' => (string)DB_USER
        ];
        $dbValues = array_merge($dbDefaults, is_array($cfgFormData['db'] ?? null) ? $cfgFormData['db'] : []);

        $ldapDefaults = [
            'ldap_enabled' => LDAP_ENABLED ? '1' : '0',
            'ldap_server' => (string)LDAP_SERVER,
            'ldap_port' => (string)LDAP_PORT,
            'ldap_basedn' => (string)LDAP_BASEDN,
            'ldap_userdn' => (string)LDAP_USERDN,
            'ldap_filter' => (string)LDAP_FILTER,
            'ldap_bind' => LDAP_BIND ? '1' : '0',
            'ldap_bind_user' => (string)LDAP_BIND_USER,
            'ldap_trust' => LDAP_TRUST ? '1' : '0'
        ];
        $ldapValues = array_merge($ldapDefaults, is_array($cfgFormData['ldap'] ?? null) ? $cfgFormData['ldap'] : []);

        $mailDefaults = [
            'mail_host' => (string)MAIL_HOST,
            'mail_user' => (string)MAIL_USER,
            'mail_port' => (string)MAIL_PORT,
            'mail_smtpauth' => MAIL_SMTPAUTH ? '1' : '0',
            'mail_smtpsecure' => configNormalizeMailSecureToUi((string)MAIL_SMTPSECURE) ?? ''
        ];
        $mailValues = array_merge($mailDefaults, is_array($cfgFormData['mail'] ?? null) ? $cfgFormData['mail'] : []);
        $automationStore = new AutomationStore();
        $automationSettings = $automationStore->getSettings();
        $schedulerDefaultsRaw = is_array($automationSettings['scheduler_config'] ?? null) ? $automationSettings['scheduler_config'] : [];
        $schedulerDefaults = [
            'queue_enabled' => !array_key_exists('queue_enabled', $schedulerDefaultsRaw) || !empty($schedulerDefaultsRaw['queue_enabled']) ? '1' : '0',
            'notifications_enabled' => !array_key_exists('notifications_enabled', $schedulerDefaultsRaw) || !empty($schedulerDefaultsRaw['notifications_enabled']) ? '1' : '0',
            'snmp_scan_enabled' => !empty($schedulerDefaultsRaw['snmp_scan_enabled']) ? '1' : '0',
        ];
        $schedulerValues = array_merge($schedulerDefaults, is_array($cfgFormData['scheduler'] ?? null) ? $cfgFormData['scheduler'] : []);
        $schedulerStatus = is_array($automationSettings['scheduler_status'] ?? null) ? $automationSettings['scheduler_status'] : [];
        $securityCheck = configBuildSystemSecurityCheck();
        $updaterDefaults = configGetUpdateStatus(false);
        $updaterValues = array_merge($updaterDefaults, is_array($cfgFormData['updater'] ?? null) ? $cfgFormData['updater'] : []);
        $updaterStateValues = configReadUpdaterState();
        $rollbackCandidate = configGetRollbackCandidate($updaterStateValues);

        $notificationCfgDefaults = [
            'notification_daily_time' => (string)NOTIFICATION_DAILY_TIME,
            'notification_timezone'   => (string)NOTIFICATION_TIMEZONE,
            'notification_queue_retention_days' => (string)NOTIFICATION_QUEUE_RETENTION_DAYS,
            'notification_slack_enabled' => NOTIFICATION_SLACK_ENABLED ? '1' : '0',
            'notification_slack_webhook_url' => (string)NOTIFICATION_SLACK_WEBHOOK_URL,
            'notification_telegram_enabled' => NOTIFICATION_TELEGRAM_ENABLED ? '1' : '0',
            'notification_telegram_bot_token' => (string)NOTIFICATION_TELEGRAM_BOT_TOKEN,
            'notification_telegram_chat_id' => (string)NOTIFICATION_TELEGRAM_CHAT_ID
        ];
        $notificationCfgValues = array_merge($notificationCfgDefaults, is_array($cfgFormData['notification'] ?? null) ? $cfgFormData['notification'] : []);

        $notificationOverview = [
            'counts' => ['total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0],
            'by_channel' => [],
            'last_sent_by_channel' => [],
            'errors_by_channel' => [],
            'recent_sent' => [],
            'recent_retry_pending' => [],
            'recent_failed' => [],
            'last_sent_at' => '',
            'last_daily_date' => '',
            'last_daily_at' => '',
            'configured_daily_time' => (string)NOTIFICATION_DAILY_TIME,
            'configured_timezone' => (string)NOTIFICATION_TIMEZONE
        ];
        try {
            $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
            $notificationOverview = array_merge($notificationOverview, $notificationCenter->getQueueOverview(8));
        } catch (\Throwable $ignored) {
            // Keep configuration UI available even if overview reading fails.
        }

        $renderFeedback = static function (array $feedback, string $section): string {
            if (!isset($feedback[$section]) || !is_array($feedback[$section])) {
                return '';
            }

            $entry = $feedback[$section];
            $ok = !empty($entry['ok']);
            $message = escapeSettingValue((string)($entry['message'] ?? ''));
            $baseClasses = 'mb-4 rounded-xl border px-4 py-3 text-sm whitespace-pre-wrap';
            $stateClasses = $ok
                ? ' border-emerald-400/70 bg-emerald-500/10 text-emerald-200'
                : ' border-red-400/70 bg-red-500/10 text-red-200';
            return '<div class="' . $baseClasses . $stateClasses . '">' . $message . '</div>';
        };

        echo '<div class="h-fit w-full p-2 space-y-6">';

        echo '<div class="cfg-section-system' . ($activeConfigTab !== 'system' ? ' hidden' : '') . '">';
        echo '<section id="cfg-security">';
        echo '<div class="flex flex-wrap items-start justify-between gap-3 pb-3">';
        echo '<div><div class="text-xl font-bold pb-1">System / Security Check</div><p class="text-sm text-gray-500">Prueft lokale Rechte und testet, ob sensible Pfade ueber den aktiven Webserver wirklich geblockt werden.</p></div>';
        echo '<div class="text-xs text-gray-500">Geprueft: ' . escapeSettingValue((string)($securityCheck['checked_at'] ?? '-')) . '<br>Basis-URL: ' . escapeSettingValue((string)($securityCheck['base_url'] ?? 'nicht ermittelbar')) . '</div>';
        echo '</div>';
        echo '<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">';
        echo '<div class="rounded-lg border border-red-200 p-3"><div class="text-xs text-gray-500">Kritisch</div><div class="text-lg font-semibold text-red-700">' . escapeSettingValue((string)($securityCheck['summary']['critical'] ?? 0)) . '</div></div>';
        echo '<div class="rounded-lg border border-amber-200 p-3"><div class="text-xs text-gray-500">Warnungen</div><div class="text-lg font-semibold text-amber-700">' . escapeSettingValue((string)($securityCheck['summary']['warn'] ?? 0)) . '</div></div>';
        echo '<div class="rounded-lg border border-emerald-200 p-3"><div class="text-xs text-gray-500">OK</div><div class="text-lg font-semibold text-emerald-700">' . escapeSettingValue((string)($securityCheck['summary']['ok'] ?? 0)) . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-xs text-gray-500">Hinweise</div><div class="text-lg font-semibold text-slate-700">' . escapeSettingValue((string)($securityCheck['summary']['info'] ?? 0)) . '</div></div>';
        echo '</div>';
        echo '<div class="space-y-3">';
        foreach ((array)($securityCheck['items'] ?? []) as $securityItem) {
            $severity = (string)($securityItem['severity'] ?? 'info');
            $toneMap = [
                'critical' => 'border-red-300 bg-red-50 text-red-900',
                'warn' => 'border-amber-300 bg-amber-50 text-amber-900',
                'ok' => 'border-emerald-300 bg-emerald-50 text-emerald-900',
                'info' => 'border-slate-300 bg-slate-50 text-slate-900',
            ];
            $labelMap = [
                'critical' => 'Kritisch',
                'warn' => 'Warnung',
                'ok' => 'OK',
                'info' => 'Hinweis',
            ];
            $cardClasses = $toneMap[$severity] ?? $toneMap['info'];
            $severityLabel = $labelMap[$severity] ?? $labelMap['info'];
            echo '<div class="rounded-2xl border px-4 py-4 ' . $cardClasses . '">';
            echo '<div class="flex flex-wrap items-start justify-between gap-3">';
            echo '<div><div class="text-sm font-semibold">' . escapeSettingValue((string)($securityItem['title'] ?? 'Pruefung')) . '</div><div class="pt-1 text-sm whitespace-pre-wrap">' . escapeSettingValue((string)($securityItem['message'] ?? '')) . '</div></div>';
            echo '<span class="rounded-full border border-current px-3 py-1 text-xs font-semibold uppercase tracking-wide">' . escapeSettingValue($severityLabel) . '</span>';
            echo '</div>';
            echo '<div class="pt-3 text-xs opacity-80">Empfohlene Massnahme: ' . escapeSettingValue((string)($securityItem['fix'] ?? '')) . '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</section>';

        echo '<section id="cfg-db">';
        echo '<div class="text-xl font-bold pb-1">Datenbank</div>';
        echo '<p class="text-sm text-gray-500 pb-4">Leeres Passwortfeld bedeutet: bestehendes DB Passwort beibehalten.</p>';
        echo $renderFeedback($cfgFeedback, 'db');
        echo '<form action="?set=config_db_save" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
        echo '<div><label class="block mb-2" for="cfg_db_type">DB Type</label><select id="cfg_db_type" name="db_type" class="w-full py-2 px-3"><option value="pgsql"' . ((string)$dbValues['db_type'] === 'pgsql' ? ' selected' : '') . '>pgsql</option><option value="mysql"' . ((string)$dbValues['db_type'] === 'mysql' ? ' selected' : '') . '>mysql</option></select></div>';
        echo '<div><label class="block mb-2" for="cfg_db_server">DB Server</label><input id="cfg_db_server" name="db_server" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$dbValues['db_server']) . '" required></div>';
        echo '<div><label class="block mb-2" for="cfg_db_port">DB Port</label><input id="cfg_db_port" name="db_port" type="number" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$dbValues['db_port']) . '" required min="1" max="65535"></div>';
        echo '<div><label class="block mb-2" for="cfg_db_name">DB Name</label><input id="cfg_db_name" name="db_name" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$dbValues['db_name']) . '" required></div>';
        echo '<div><label class="block mb-2" for="cfg_db_user">DB User</label><input id="cfg_db_user" name="db_user" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$dbValues['db_user']) . '" required></div>';
        echo '<div><label class="block mb-2" for="cfg_db_password">DB Password</label><input id="cfg_db_password" name="db_password" type="password" class="w-full py-2 px-3" placeholder="(unveraendert lassen)"></div>';
        echo '</div>';
        echo '<div class="pt-4 flex flex-wrap gap-3">';
        echo '<button type="submit" formaction="?set=config_db_test" class="bg-amber-600 hover:bg-amber-700 text-white">Verbindung testen</button>';
        echo '<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white">Speichern</button>';
        echo '</div>';
        echo '</form>';
        echo '</section>';

        echo '<section id="cfg-ldap">';
        echo '<div class="text-xl font-bold pb-1">LDAP</div>';
        echo '<p class="text-sm text-gray-500 pb-4">Leeres Bind-Passwort bedeutet: bestehendes LDAP Bind Passwort beibehalten.</p>';
        echo $renderFeedback($cfgFeedback, 'ldap');
        echo '<form action="?set=config_ldap_save" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
        echo '<div class="md:col-span-2"><label class="inline-flex items-center gap-2"><input type="checkbox" name="ldap_enabled" value="1"' . (configToBool($ldapValues['ldap_enabled'] ?? false) ? ' checked' : '') . '> LDAP aktivieren</label></div>';
        echo '<div><label class="block mb-2" for="cfg_ldap_server">LDAP Server</label><input id="cfg_ldap_server" name="ldap_server" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$ldapValues['ldap_server']) . '"></div>';
        echo '<div><label class="block mb-2" for="cfg_ldap_port">LDAP Port</label><input id="cfg_ldap_port" name="ldap_port" type="number" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$ldapValues['ldap_port']) . '" min="1" max="65535"></div>';
        echo '<div><label class="block mb-2" for="cfg_ldap_basedn">LDAP Base DN</label><input id="cfg_ldap_basedn" name="ldap_basedn" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$ldapValues['ldap_basedn']) . '"></div>';
        echo '<div><label class="block mb-2" for="cfg_ldap_userdn">LDAP User DN</label><input id="cfg_ldap_userdn" name="ldap_userdn" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$ldapValues['ldap_userdn']) . '"></div>';
        echo '<div class="md:col-span-2"><label class="block mb-2" for="cfg_ldap_filter">LDAP Filter</label><input id="cfg_ldap_filter" name="ldap_filter" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$ldapValues['ldap_filter']) . '"></div>';
        echo '<div class="md:col-span-2"><label class="inline-flex items-center gap-2"><input type="checkbox" name="ldap_bind" value="1"' . (configToBool($ldapValues['ldap_bind'] ?? false) ? ' checked' : '') . '> LDAP Bind verwenden</label></div>';
        echo '<div><label class="block mb-2" for="cfg_ldap_bind_user">LDAP Bind User</label><input id="cfg_ldap_bind_user" name="ldap_bind_user" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$ldapValues['ldap_bind_user']) . '"></div>';
        echo '<div><label class="block mb-2" for="cfg_ldap_bind_password">LDAP Bind Password</label><input id="cfg_ldap_bind_password" name="ldap_bind_password" type="password" class="w-full py-2 px-3" placeholder="(unveraendert lassen)"></div>';
        echo '<div class="md:col-span-2"><label class="inline-flex items-center gap-2"><input type="checkbox" name="ldap_trust" value="1"' . (configToBool($ldapValues['ldap_trust'] ?? false) ? ' checked' : '') . '> LDAP Trust aktivieren</label></div>';
        echo '</div>';
        echo '<div class="pt-4 flex flex-wrap gap-3">';
        echo '<button type="submit" formaction="?set=config_ldap_test" class="bg-amber-600 hover:bg-amber-700 text-white">LDAP testen</button>';
        echo '<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white">Speichern</button>';
        echo '</div>';
        echo '</form>';
        echo '</section>';

        echo '<section id="cfg-mail">';
        echo '<div class="text-xl font-bold pb-1">Mail</div>';
        echo '<p class="text-sm text-gray-500 pb-4">Leeres Passwortfeld bedeutet: bestehendes Mail Passwort beibehalten.</p>';
        echo $renderFeedback($cfgFeedback, 'mail');
        echo '<form action="?set=config_mail_save" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
        echo '<div><label class="block mb-2" for="cfg_mail_host">Mail Host</label><input id="cfg_mail_host" name="mail_host" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$mailValues['mail_host']) . '"></div>';
        echo '<div><label class="block mb-2" for="cfg_mail_port">Mail Port</label><input id="cfg_mail_port" name="mail_port" type="number" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$mailValues['mail_port']) . '" min="1" max="65535"></div>';
        echo '<div><label class="block mb-2" for="cfg_mail_user">Mail User</label><input id="cfg_mail_user" name="mail_user" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$mailValues['mail_user']) . '"></div>';
        echo '<div><label class="block mb-2" for="cfg_mail_password">Mail Password</label><input id="cfg_mail_password" name="mail_password" type="password" class="w-full py-2 px-3" placeholder="(unveraendert lassen)"></div>';
        echo '<div><label class="inline-flex items-center gap-2"><input type="checkbox" name="mail_smtpauth" value="1"' . (configToBool($mailValues['mail_smtpauth'] ?? false) ? ' checked' : '') . '> SMTP Auth</label></div>';
        echo '<div><label class="block mb-2" for="cfg_mail_smtpsecure">SMTP Secure</label><select id="cfg_mail_smtpsecure" name="mail_smtpsecure" class="w-full py-2 px-3"><option value=""' . ((string)$mailValues['mail_smtpsecure'] === '' ? ' selected' : '') . '>None</option><option value="tls"' . ((string)$mailValues['mail_smtpsecure'] === 'tls' ? ' selected' : '') . '>TLS</option><option value="ssl"' . ((string)$mailValues['mail_smtpsecure'] === 'ssl' ? ' selected' : '') . '>SSL</option></select></div>';
        echo '</div>';
        echo '<div class="pt-4 flex flex-wrap gap-3">';
        echo '<button type="submit" formaction="?set=config_mail_test" class="bg-amber-600 hover:bg-amber-700 text-white">Mail testen</button>';
        echo '<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white">Speichern</button>';
        echo '</div>';
        echo '</form>';
        echo '</section>';

        echo '<section id="cfg-scheduler">';
        echo '<div class="text-xl font-bold pb-1">Scheduler</div>';
        echo '<p class="text-sm text-gray-500 pb-4">Steuert, welche Aufgaben der periodische Scheduler ausfuehren darf. Der Cronjob selbst wird durch den Installer eingerichtet.</p>';
        echo $renderFeedback($cfgFeedback, 'scheduler');
        echo '<form action="?set=config_scheduler_save" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<div class="grid grid-cols-1 md:grid-cols-3 gap-3">';
        echo '<label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-3"><input type="checkbox" name="scheduler_queue_enabled" value="1"' . (configToBool($schedulerValues['queue_enabled'] ?? false) ? ' checked' : '') . '> <span>Queue-Automation ausfuehren</span></label>';
        echo '<label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-3"><input type="checkbox" name="scheduler_notifications_enabled" value="1"' . (configToBool($schedulerValues['notifications_enabled'] ?? false) ? ' checked' : '') . '> <span>Benachrichtigungen verarbeiten</span></label>';
        echo '<label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-3"><input type="checkbox" name="scheduler_snmp_scan_enabled" value="1"' . (configToBool($schedulerValues['snmp_scan_enabled'] ?? false) ? ' checked' : '') . '> <span>SNMP-Scan fuer alle Switches</span></label>';
        echo '</div>';
        echo '<div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-4">';
        echo '<div class="rounded-xl border border-slate-200 p-3"><div class="text-xs text-gray-500">Letzter Lauf</div><div class="text-sm font-semibold">' . escapeSettingValue((string)($schedulerStatus['last_run'] ?? '-')) . '</div></div>';
        echo '<div class="rounded-xl border border-slate-200 p-3"><div class="text-xs text-gray-500">Letzter Erfolg</div><div class="text-sm font-semibold">' . escapeSettingValue((string)($schedulerStatus['last_success'] ?? '-')) . '</div></div>';
        echo '<div class="rounded-xl border border-slate-200 p-3"><div class="text-xs text-gray-500">Processed / OK</div><div class="text-sm font-semibold">' . escapeSettingValue((string)((int)($schedulerStatus['processed'] ?? 0) . ' / ' . (int)($schedulerStatus['succeeded'] ?? 0))) . '</div></div>';
        echo '<div class="rounded-xl border border-slate-200 p-3"><div class="text-xs text-gray-500">Fehlgeschlagen</div><div class="text-sm font-semibold">' . escapeSettingValue((string)($schedulerStatus['failed'] ?? 0)) . '</div></div>';
        echo '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Letzte Meldung</div>';
        echo '<div class="font-medium whitespace-pre-wrap">' . escapeSettingValue((string)($schedulerStatus['message'] ?? 'Noch keine Scheduler-Ausfuehrung protokolliert.')) . '</div>';
        echo '<div class="pt-4 flex flex-wrap gap-3">';
        echo '<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white">Speichern</button>';
        echo '</div>';
        echo '</form>';
        echo '</section>';
        echo '</div>'; // end cfg-section-system

        echo '<div class="cfg-section-updater' . ($activeConfigTab !== 'updater' ? ' hidden' : '') . '">';
        echo '<section id="cfg-updater">';
        echo '<div class="text-xl font-bold pb-1">System Updater</div>';
        echo '<p class="text-sm text-gray-500 pb-4">Zeigt den aktuellen Git-Stand und prueft, ob im Tracking-Branch neuere Commits verfuegbar sind.</p>';
        echo $renderFeedback($cfgFeedback, 'updater');
        echo '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
        echo '<div class="rounded-xl border border-slate-200 p-4">';
        echo '<div class="text-sm text-gray-500">Repository</div>';
        echo '<div class="text-base font-semibold">' . escapeSettingValue((string)($updaterValues['repo_available'] ? 'Git erkannt' : 'Kein Git-Repository')) . '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Pfad</div>';
        echo '<div class="text-sm font-mono break-all">' . escapeSettingValue((string)($updaterValues['repo_path'] ?? '')) . '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Branch</div>';
        echo '<div class="text-sm font-medium">' . escapeSettingValue((string)($updaterValues['branch'] ?? '-')) . '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Upstream</div>';
        echo '<div class="text-sm font-medium">' . escapeSettingValue((string)($updaterValues['upstream'] ?? '-')) . '</div>';
        echo '</div>';
        echo '<div class="rounded-xl border border-slate-200 p-4">';
        echo '<div class="text-sm text-gray-500">Aktueller Versionsstand</div>';
        echo '<div class="text-base font-semibold">' . escapeSettingValue((string)($updaterValues['current_version'] ?? '-')) . '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Lokaler Commit</div>';
        echo '<div class="text-sm font-mono">' . escapeSettingValue((string)($updaterValues['current_commit'] ?? '-')) . '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Remote-Version</div>';
        echo '<div class="text-sm font-semibold">' . escapeSettingValue((string)($updaterValues['remote_version'] ?? '-')) . '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Remote-Commit</div>';
        echo '<div class="text-sm font-mono">' . escapeSettingValue((string)($updaterValues['remote_commit'] ?? '-')) . '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-4">';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500 text-sm">Update-Status</div><div class="text-lg font-semibold">' . escapeSettingValue(((int)($updaterValues['behind_count'] ?? 0) > 0) ? 'Update verfuegbar' : 'Aktuell') . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500 text-sm">Behind</div><div class="text-lg font-semibold">' . escapeSettingValue((string)($updaterValues['behind_count'] ?? 0)) . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500 text-sm">Ahead</div><div class="text-lg font-semibold">' . escapeSettingValue((string)($updaterValues['ahead_count'] ?? 0)) . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500 text-sm">Worktree</div><div class="text-lg font-semibold">' . escapeSettingValue(!empty($updaterValues['working_tree_dirty']) ? 'Dirty' : 'Clean') . '</div></div>';
        echo '</div>';

        echo '<div class="mt-4 rounded-xl border border-slate-200 p-4">';
        echo '<div class="text-sm text-gray-500">Letzte Pruefung</div>';
        echo '<div class="font-medium">' . escapeSettingValue((string)($updaterValues['last_checked_at'] ?? '-')) . '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Ergebnis</div>';
        echo '<div class="font-medium">' . escapeSettingValue((string)($updaterValues['message'] ?? 'Noch keine Update-Pruefung ausgefuehrt.')) . '</div>';
        echo '<div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500 text-sm">Letzter Laufstatus</div><div class="text-sm font-semibold">' . escapeSettingValue((string)($updaterStateValues['status'] ?? 'unbekannt')) . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500 text-sm">Letzte Aktion</div><div class="text-sm font-semibold">' . escapeSettingValue((string)($updaterStateValues['operation'] ?? 'update-check')) . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500 text-sm">Gestartet</div><div class="text-sm font-medium">' . escapeSettingValue((string)($updaterStateValues['started_at'] ?? '-')) . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500 text-sm">Beendet</div><div class="text-sm font-medium">' . escapeSettingValue((string)($updaterStateValues['finished_at'] ?? '-')) . '</div></div>';
        echo '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Letzter Updater-Status</div>';
        echo '<div class="font-medium">' . escapeSettingValue((string)($updaterStateValues['message'] ?? 'Es liegt noch kein gespeicherter Updater-Status vor.')) . '</div>';
        echo '<div class="mt-3 text-sm text-gray-500">Rollback-Stand</div>';
        echo '<div class="font-medium">' . escapeSettingValue(!empty($rollbackCandidate['available']) ? ((string)($rollbackCandidate['version'] ?? $rollbackCandidate['commit'] ?? '-')) : 'Kein gespeicherter Ruecksetzpunkt') . '</div>';
        echo '<div class="text-xs text-gray-500">Commit: ' . escapeSettingValue((string)($rollbackCandidate['commit'] ?? '-')) . ' | Gespeichert: ' . escapeSettingValue((string)($rollbackCandidate['recorded_at'] ?? '-')) . '</div>';
        echo '<div class="pt-4 flex flex-wrap gap-3">';
        echo '<form action="?set=config_update_check" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white">Nach Updates suchen</button>';
        echo '</form>';
        echo '<form action="?set=config_update_execute" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white"' . ((!empty($updaterValues['working_tree_dirty']) || empty($updaterValues['repo_available']) || empty($updaterValues['upstream']) || (int)($updaterValues['behind_count'] ?? 0) < 1) ? ' disabled title="Vor dem Update bitte erst den Git-Status pruefen."' : '') . '>Update ausfuehren</button>';
        echo '</form>';
        echo '<form action="?set=config_update_rollback" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white"' . ((!empty($updaterValues['working_tree_dirty']) || empty($rollbackCandidate['available']) || empty($rollbackCandidate['commit'])) ? ' disabled title="Es ist kein gespeicherter Ruecksetzpunkt verfuegbar oder der Worktree ist nicht sauber."' : '') . '>Letzten Stand wiederherstellen</button>';
        echo '</form>';
        echo '</div>';
        echo '<div class="mt-3 text-xs text-gray-500">Das Update aktiviert kurzzeitig einen Wartungsmodus, blockiert jetzt auch API-Zugriffe, setzt den lokalen Branch auf den konfigurierten Tracking-Branch zurueck und fuehrt anschliessend die Datenbankmigration aus. Bei einem Fehler wird der Code automatisch auf den vorherigen Commit zurueckgesetzt. Der manuelle Rollback stellt spaeter denselben gespeicherten Code-Stand wieder her. Datenbankaenderungen werden dabei nicht automatisch rueckgaengig gemacht.</div>';
        echo '</div>';
        echo '</section>';
        echo '</div>'; // end cfg-section-updater

        // Notification configuration section
        echo '<div class="cfg-section-notifications' . ($activeConfigTab !== 'notifications' ? ' hidden' : '') . '">';
        $allTimezones = \DateTimeZone::listIdentifiers();
        $currentTz = (string)$notificationCfgValues['notification_timezone'];
        $currentTime = (string)$notificationCfgValues['notification_daily_time'];
        $currentSlackEnabled = configToBool($notificationCfgValues['notification_slack_enabled'] ?? false);
        $currentSlackWebhook = (string)($notificationCfgValues['notification_slack_webhook_url'] ?? '');
        $currentTelegramEnabled = configToBool($notificationCfgValues['notification_telegram_enabled'] ?? false);
        $currentTelegramBotToken = (string)($notificationCfgValues['notification_telegram_bot_token'] ?? '');
        $currentTelegramChatId = (string)($notificationCfgValues['notification_telegram_chat_id'] ?? '');
        $currentRetentionDays = (int)($notificationCfgValues['notification_queue_retention_days'] ?? 30);
        if ($currentRetentionDays < 1 || $currentRetentionDays > 365) {
            $currentRetentionDays = 30;
        }

        echo '<section id="cfg-notification">';
        echo '<div class="text-xl font-bold pb-1">Benachrichtigungen</div>';
        echo '<p class="text-sm text-gray-500 pb-4">Zeitzone und Uhrzeit f&uuml;r den t&auml;glichen Benachrichtigungsversand.</p>';
        echo $renderFeedback($cfgFeedback, 'notification');
        echo '<form action="?set=config_notification_save" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
        echo '<div><label class="block mb-2" for="cfg_notification_daily_time">Versandzeit (HH:MM)</label><input id="cfg_notification_daily_time" name="notification_daily_time" type="time" class="w-full py-2 px-3" value="' . escapeSettingValue($currentTime) . '"></div>';
        echo '<div><label class="block mb-2" for="cfg_notification_timezone">Zeitzone</label><select id="cfg_notification_timezone" name="notification_timezone" class="w-full py-2 px-3">';
        foreach ($allTimezones as $tz) {
            $sel = ($tz === $currentTz) ? ' selected' : '';
            echo '<option value="' . escapeSettingValue($tz) . '"' . $sel . '>' . escapeSettingValue($tz) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label class="block mb-2" for="cfg_notification_retention">Queue-Retention (Tage)</label><input id="cfg_notification_retention" name="notification_queue_retention_days" type="number" min="1" max="365" class="w-full py-2 px-3" value="' . escapeSettingValue((string)$currentRetentionDays) . '"></div>';
        echo '<div class="text-sm text-gray-500 self-end">Sent/Failed Eintraege aelter als die Retention werden beim Scheduler-Lauf bereinigt.</div>';
        echo '<div class="md:col-span-2 mt-2"><label class="inline-flex items-center gap-2"><input type="checkbox" name="notification_slack_enabled" value="1"' . ($currentSlackEnabled ? ' checked' : '') . '> Slack aktivieren</label></div>';
        echo '<div class="md:col-span-2"><label class="block mb-2" for="cfg_notification_slack_webhook_url">Slack Webhook URL</label><input id="cfg_notification_slack_webhook_url" name="notification_slack_webhook_url" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue($currentSlackWebhook) . '" placeholder="https://hooks.slack.com/services/..." ></div>';
        echo '<div class="md:col-span-2 mt-2"><label class="inline-flex items-center gap-2"><input type="checkbox" name="notification_telegram_enabled" value="1"' . ($currentTelegramEnabled ? ' checked' : '') . '> Telegram aktivieren</label></div>';
        echo '<div><label class="block mb-2" for="cfg_notification_telegram_bot_token">Telegram Bot Token</label><input id="cfg_notification_telegram_bot_token" name="notification_telegram_bot_token" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue($currentTelegramBotToken) . '" placeholder="123456:ABC..." ></div>';
        echo '<div><label class="block mb-2" for="cfg_notification_telegram_chat_id">Telegram Chat ID</label><input id="cfg_notification_telegram_chat_id" name="notification_telegram_chat_id" type="text" class="w-full py-2 px-3" value="' . escapeSettingValue($currentTelegramChatId) . '" placeholder="-100... oder 123..." ></div>';
        echo '</div>';
        echo '<div class="pt-4">';
        echo '<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white">Speichern</button>';
        echo '</div>';
        echo '</form>';

        echo '<div class="mt-5 rounded-xl border border-slate-200 p-4">';
        echo '<div class="text-lg font-semibold pb-3">Admin-Uebersicht Queue</div>';
        echo '<div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500">Total</div><div class="text-xl font-semibold">' . escapeSettingValue((string)($notificationOverview['counts']['total'] ?? 0)) . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500">Pending</div><div class="text-xl font-semibold">' . escapeSettingValue((string)($notificationOverview['counts']['pending'] ?? 0)) . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500">Sent</div><div class="text-xl font-semibold">' . escapeSettingValue((string)($notificationOverview['counts']['sent'] ?? 0)) . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500">Failed</div><div class="text-xl font-semibold">' . escapeSettingValue((string)($notificationOverview['counts']['failed'] ?? 0)) . '</div></div>';
        echo '</div>';

        $lastSentAt = escapeSettingValue((string)($notificationOverview['last_sent_at'] ?? ''));
        $lastDailyAt = escapeSettingValue((string)($notificationOverview['last_daily_at'] ?? ''));
        $lastDailyDate = escapeSettingValue((string)($notificationOverview['last_daily_date'] ?? ''));
        if ($lastSentAt === '') {
            $lastSentAt = '-';
        }
        if ($lastDailyAt === '') {
            $lastDailyAt = '-';
        }
        if ($lastDailyDate === '') {
            $lastDailyDate = '-';
        }
        echo '<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 text-sm">';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500">Letzter Send</div><div class="font-medium">' . $lastSentAt . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500">Letzter Daily-Run</div><div class="font-medium">' . $lastDailyAt . '</div></div>';
        echo '<div class="rounded-lg border border-slate-200 p-3"><div class="text-gray-500">Daily-Datum</div><div class="font-medium">' . $lastDailyDate . '</div></div>';
        echo '</div>';

        $byChannel = is_array($notificationOverview['by_channel'] ?? null) ? $notificationOverview['by_channel'] : [];
        if (!empty($byChannel)) {
            echo '<div class="mt-4">';
            echo '<div class="text-sm font-semibold pb-2">Versand nach Kanal</div>';
            echo '<div class="settings-table-wrap max-h-64 overflow-y-auto"><table class="w-full text-sm text-left">';
            echo '<thead class="bg-gray-100 sticky top-0 z-1"><tr class="border-b border-slate-200 text-gray-800"><th class="p-2">Kanal</th><th class="p-2">Total</th><th class="p-2">Pending</th><th class="p-2">Sent</th><th class="p-2">Failed</th></tr></thead><tbody>';
            foreach ($byChannel as $channelName => $row) {
                echo '<tr class="settings-data-row"><td class="p-2 border-b">' . escapeSettingValue((string)$channelName) . '</td><td class="p-2 border-b">' . escapeSettingValue((string)($row['total'] ?? 0)) . '</td><td class="p-2 border-b">' . escapeSettingValue((string)($row['pending'] ?? 0)) . '</td><td class="p-2 border-b">' . escapeSettingValue((string)($row['sent'] ?? 0)) . '</td><td class="p-2 border-b">' . escapeSettingValue((string)($row['failed'] ?? 0)) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '</div>';
        }

        $lastSentByChannel = is_array($notificationOverview['last_sent_by_channel'] ?? null) ? $notificationOverview['last_sent_by_channel'] : [];
        if (!empty($lastSentByChannel)) {
            echo '<div class="mt-4">';
            echo '<div class="text-sm font-semibold pb-2">Letzter erfolgreicher Send je Kanal</div>';
            echo '<div class="settings-table-wrap max-h-64 overflow-y-auto"><table class="w-full text-sm text-left">';
            echo '<thead class="bg-gray-100 sticky top-0 z-1"><tr class="border-b border-slate-200 text-gray-800"><th class="p-2">Kanal</th><th class="p-2">Zeit</th><th class="p-2">Event</th><th class="p-2">Empfaenger</th></tr></thead><tbody>';
            foreach ($lastSentByChannel as $channelName => $row) {
                $recipient = trim((string)($row['recipient_username'] ?? '') . ' <' . (string)($row['recipient_email'] ?? '') . '>');
                if ($recipient === '<>' || $recipient === '') {
                    $recipient = '-';
                }
                echo '<tr class="settings-data-row"><td class="p-2 border-b">' . escapeSettingValue((string)$channelName) . '</td><td class="p-2 border-b">' . escapeSettingValue((string)($row['sent_at'] ?? '-')) . '</td><td class="p-2 border-b">' . escapeSettingValue((string)($row['event_type'] ?? '-')) . '</td><td class="p-2 border-b">' . escapeSettingValue($recipient) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '</div>';
        }

        $errorsByChannel = is_array($notificationOverview['errors_by_channel'] ?? null) ? $notificationOverview['errors_by_channel'] : [];
        if (!empty($errorsByChannel)) {
            echo '<div class="mt-4">';
            echo '<div class="text-sm font-semibold pb-2">Fehlerursachen pro Kanal</div>';
            echo '<div class="settings-table-wrap max-h-64 overflow-y-auto"><table class="w-full text-sm text-left">';
            echo '<thead class="bg-gray-100 sticky top-0 z-1"><tr class="border-b border-slate-200 text-gray-800"><th class="p-2">Kanal</th><th class="p-2">Fehler</th><th class="p-2">Anzahl</th></tr></thead><tbody>';
            foreach ($errorsByChannel as $channelName => $errors) {
                foreach ((array)$errors as $errorMessage => $errorCount) {
                    echo '<tr class="settings-data-row"><td class="p-2 border-b">' . escapeSettingValue((string)$channelName) . '</td><td class="p-2 border-b">' . escapeSettingValue((string)$errorMessage) . '</td><td class="p-2 border-b">' . escapeSettingValue((string)$errorCount) . '</td></tr>';
                }
            }
            echo '</tbody></table></div>';
            echo '</div>';
        }

        echo '<div class="mt-4">';
        echo '<div class="text-sm font-semibold pb-2">Letzte Sends</div>';
        $recentSent = is_array($notificationOverview['recent_sent'] ?? null) ? $notificationOverview['recent_sent'] : [];
        if (!empty($recentSent)) {
            echo '<div class="settings-table-wrap max-h-64 overflow-y-auto"><table class="w-full text-sm text-left">';
            echo '<thead class="bg-gray-100 sticky top-0 z-1"><tr class="border-b border-slate-200 text-gray-800"><th class="p-2">Zeit (UTC)</th><th class="p-2">Event</th><th class="p-2">Titel</th><th class="p-2">Kanal</th><th class="p-2">Empfaenger</th><th class="p-2">Versuche</th></tr></thead><tbody>';
            foreach ($recentSent as $entry) {
                $sentAt = escapeSettingValue((string)($entry['sent_at'] ?? '-'));
                $eventType = escapeSettingValue((string)($entry['event_type'] ?? '-'));
                $title = escapeSettingValue((string)($entry['title'] ?? '-'));
                $channel = escapeSettingValue((string)($entry['channel'] ?? 'mail'));
                $recipientUser = escapeSettingValue((string)($entry['recipient_username'] ?? ''));
                $recipientEmail = escapeSettingValue((string)($entry['recipient_email'] ?? ''));
                $recipient = trim($recipientUser . ' <' . $recipientEmail . '>');
                if ($recipient === '<>' || $recipient === '') {
                    $recipient = '-';
                }
                $attempts = escapeSettingValue((string)($entry['attempts'] ?? 0));
                echo '<tr class="settings-data-row"><td class="p-2 border-b">' . $sentAt . '</td><td class="p-2 border-b">' . $eventType . '</td><td class="p-2 border-b">' . $title . '</td><td class="p-2 border-b">' . $channel . '</td><td class="p-2 border-b">' . $recipient . '</td><td class="p-2 border-b">' . $attempts . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="text-sm text-gray-500">Noch keine versendeten Benachrichtigungen vorhanden.</div>';
        }
        echo '</div>';

        echo '<div class="mt-4">';
        echo '<div class="text-sm font-semibold pb-2">Ausstehende Retries</div>';
        $recentRetryPending = is_array($notificationOverview['recent_retry_pending'] ?? null) ? $notificationOverview['recent_retry_pending'] : [];
        if (!empty($recentRetryPending)) {
            echo '<div class="settings-table-wrap max-h-64 overflow-y-auto"><table class="w-full text-sm text-left">';
            echo '<thead class="bg-gray-100 sticky top-0 z-1"><tr class="border-b border-slate-200 text-gray-800"><th class="p-2">Naechster Versuch</th><th class="p-2">Event</th><th class="p-2">Kanal</th><th class="p-2">Empfaenger</th><th class="p-2">Letzter Fehler</th><th class="p-2">Versuche</th><th class="p-2">Aktion</th></tr></thead><tbody>';
            foreach ($recentRetryPending as $entry) {
                $entryId = escapeSettingValue((string)($entry['id'] ?? ''));
                $nextAttemptAt = escapeSettingValue((string)($entry['next_attempt_at'] ?? $entry['updated_at'] ?? '-'));
                $eventType = escapeSettingValue((string)($entry['event_type'] ?? '-'));
                $channel = escapeSettingValue((string)($entry['channel'] ?? 'mail'));
                $recipientUser = escapeSettingValue((string)($entry['recipient_username'] ?? ''));
                $recipientEmail = escapeSettingValue((string)($entry['recipient_email'] ?? ''));
                $recipient = trim($recipientUser . ' <' . $recipientEmail . '>');
                if ($recipient === '<>' || $recipient === '') {
                    $recipient = '-';
                }
                $error = escapeSettingValue((string)($entry['error'] ?? '-'));
                $attempts = escapeSettingValue((string)($entry['attempts'] ?? 0));
                $deleteAction = '-';
                if ($entryId !== '') {
                    $deleteAction = '<form action="?set=config_notification_delete_entry" method="post" class="m-0"><input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '"><input type="hidden" name="notification_queue_entry_id" value="' . $entryId . '"><button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white">Loeschen</button></form>';
                }
                echo '<tr class="settings-data-row"><td class="p-2 border-b">' . $nextAttemptAt . '</td><td class="p-2 border-b">' . $eventType . '</td><td class="p-2 border-b">' . $channel . '</td><td class="p-2 border-b">' . $recipient . '</td><td class="p-2 border-b">' . $error . '</td><td class="p-2 border-b">' . $attempts . '</td><td class="p-2 border-b">' . $deleteAction . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="text-sm text-gray-500">Keine pending Retries mit Fehlerhistorie vorhanden.</div>';
        }
        echo '</div>';

        echo '<div class="mt-4">';
        echo '<div class="text-sm font-semibold pb-2">Letzte Fehler</div>';
        $recentFailed = is_array($notificationOverview['recent_failed'] ?? null) ? $notificationOverview['recent_failed'] : [];
        if (!empty($recentFailed)) {
            echo '<div class="settings-table-wrap max-h-64 overflow-y-auto"><table class="w-full text-sm text-left">';
            echo '<thead class="bg-gray-100 sticky top-0 z-1"><tr class="border-b border-slate-200 text-gray-800"><th class="p-2">Zeit</th><th class="p-2">Event</th><th class="p-2">Kanal</th><th class="p-2">Empfaenger</th><th class="p-2">Fehler</th><th class="p-2">Versuche</th><th class="p-2">Aktion</th></tr></thead><tbody>';
            foreach ($recentFailed as $entry) {
                $entryId = escapeSettingValue((string)($entry['id'] ?? ''));
                $updatedAt = escapeSettingValue((string)($entry['updated_at'] ?? '-'));
                $eventType = escapeSettingValue((string)($entry['event_type'] ?? '-'));
                $channel = escapeSettingValue((string)($entry['channel'] ?? 'mail'));
                $recipientUser = escapeSettingValue((string)($entry['recipient_username'] ?? ''));
                $recipientEmail = escapeSettingValue((string)($entry['recipient_email'] ?? ''));
                $recipient = trim($recipientUser . ' <' . $recipientEmail . '>');
                if ($recipient === '<>' || $recipient === '') {
                    $recipient = '-';
                }
                $error = escapeSettingValue((string)($entry['error'] ?? '-'));
                $attempts = escapeSettingValue((string)($entry['attempts'] ?? 0));
                $retryAction = '-';
                if ($entryId !== '') {
                    $retryAction = '<div class="flex gap-2"><form action="?set=config_notification_retry_entry" method="post" class="m-0"><input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '"><input type="hidden" name="notification_queue_entry_id" value="' . $entryId . '"><button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white">Retry</button></form><form action="?set=config_notification_delete_entry" method="post" class="m-0"><input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '"><input type="hidden" name="notification_queue_entry_id" value="' . $entryId . '"><button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white">Loeschen</button></form></div>';
                }
                echo '<tr class="settings-data-row"><td class="p-2 border-b">' . $updatedAt . '</td><td class="p-2 border-b">' . $eventType . '</td><td class="p-2 border-b">' . $channel . '</td><td class="p-2 border-b">' . $recipient . '</td><td class="p-2 border-b">' . $error . '</td><td class="p-2 border-b">' . $attempts . '</td><td class="p-2 border-b">' . $retryAction . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="text-sm text-gray-500">Keine fehlgeschlagenen Benachrichtigungen vorhanden.</div>';
        }
        echo '</div>';

        echo '<div class="mt-5 pt-4 border-t border-slate-200">';
        echo '<div class="text-sm font-semibold pb-2">Admin-Aktionen</div>';
        echo '<div class="flex flex-wrap gap-3">';
        echo '<form action="?set=config_notification_enqueue_test" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white">Test-Event enqueuen</button>';
        echo '</form>';
        echo '<form action="?set=config_notification_process_queue" method="post" class="m-0 flex items-center gap-2">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<input name="notification_process_limit" type="number" min="1" max="500" value="100" class="w-24 py-2 px-3" title="Maximal zu verarbeitende Queue-Eintraege">';
        echo '<label class="inline-flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="notification_process_ignore_schedule" value="1"> Retry-Backoff ignorieren</label>';
        echo '<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white">Queue jetzt verarbeiten</button>';
        echo '</form>';
        echo '<form action="?set=config_notification_cleanup_queue" method="post" class="m-0 flex items-center gap-2">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<input name="notification_queue_retention_days" type="number" min="0" max="365" value="' . escapeSettingValue((string)$currentRetentionDays) . '" class="w-24 py-2 px-3" title="Retention in Tagen (0 = sofort alle abgeschlossenen Eintraege entfernen)">';
        echo '<button type="submit" class="bg-slate-600 hover:bg-slate-700 text-white">Queue bereinigen</button>';
        echo '</form>';
        echo '<form action="?set=config_notification_delete_failed_entries" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<button type="submit" class="bg-rose-700 hover:bg-rose-800 text-white">Alle Fehlgeschlagenen loeschen</button>';
        echo '</form>';
        echo '<form action="?set=config_notification_test_slack" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<button type="submit" class="bg-slate-700 hover:bg-slate-800 text-white">Slack Test</button>';
        echo '</form>';
        echo '<form action="?set=config_notification_test_telegram" method="post" class="m-0">';
        echo '<input type="hidden" name="csrf" value="' . escapeSettingValue((string)$csrf) . '">';
        echo '<button type="submit" class="bg-cyan-600 hover:bg-cyan-700 text-white">Telegram Test</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '</div>';

        echo '</section>';
        echo '</div>'; // end cfg-section-notifications

        echo '</div>';
        break;
    case 'access':
        // check if user is admin
        if ($role !== 'admin') {
            $logger->log('user is not admin', 2, echoToWeb: true);
            header('Location: ?site=appearance');
            die();
        }

        echo '<div class="h-fit w-full p-2 space-y-6">';

        $csrf = $auth->csrf();
        $allRoleRows = $db_adapter->db_query("SELECT uuid, caption, description FROM role ORDER BY caption") ?: [];

        $query = "SELECT users.uuid, users.username, users.email, role.caption AS role, users.login_provider, users.ip_address, CASE WHEN users.activation_code = 'activated' THEN 'activated' ELSE 'deactivated' END AS activation_code, users.login_attempts AS login_attempts, TO_CHAR (users.last_login, 'HH24:MI DD.MM.YYYY') AS last_login, TO_CHAR (users.created, 'HH24:MI DD.MM.YYYY') AS created FROM users INNER JOIN role ON users.role = role.uuid";
        $results = $db_adapter->db_query($query);

        if ($results) {
            echo "<div class='text-xl font-bold pb-4'>Accounts</div><div class='settings-table-wrap max-h-96 overflow-y-auto'><table class='w-full text-sm text-left'><thead class='bg-gray-100 sticky top-0 z-1'>";
            echo "<tr class='border-b border-slate-200 text-gray-800'>";
            foreach (array_keys($results[0]) as $header) {
                echo "<th class='p-2'>{$header}</th>";
            }
            echo "<th class='p-2'>Actions</th></tr></thead><tbody>";
            foreach ($results as $row) { 
                $uuid = $row['uuid'];
                $activation_code = $row['activation_code'];

                if ($activation_code == 'activated') {
                    $form_action = 'deactivate_account';
                    $button = "
                            <button class='h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center'>
                                <i data-lucide='x'></i>
                            </button>";
                } else {
                    $form_action = 'activate_account';
                    $button = "
                            <button class='h-10 w-10 rounded-full bg-green-500 hover:bg-green-700 text-white flex items-center justify-center'>
                                <i data-lucide='check'></i>
                            </button>";
                }

                echo "<tr class='settings-data-row'>";
                foreach ($row as $column) {
                    echo "<td class='p-2 border-b'>{$column}</td>";
                }

                echo <<<HTML
                    <td class='p-2 border-b flex flex-row gap-2'>
                        <form action='?set=$form_action' method='post' class='m-0'>
                            <input type='hidden' name='csrf' value='$csrf'>
                            <input type='hidden' name='uuid' value='$uuid'>
                            $button
                        </form>
                        <button class='h-10 w-10 rounded-full bg-amber-500 hover:bg-amber-700 text-white flex items-center justify-center' onclick="openEditPopup('$uuid')" title='Bearbeiten'>
                            <i data-lucide='pencil'></i>
                        </button>
                        <button class='h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center' onclick="openDetailsPopup('$uuid')">
                            <i data-lucide='info'></i>
                        </button>
                        <form action='?set=delete_account' method='post' class='m-0'>
                            <input type='hidden' name='csrf' value='$csrf'>
                            <input type='hidden' name='uuid' value='$uuid'>
                            <button class='h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center'>
                                <i data-lucide='trash'></i>
                            </button>
                        </form>
                    </td>
                HTML;
                echo "</tr>";

                $uuid = NULL;}

            echo "</tbody></table></div>";
        } else {
            echo "No results found.";
        }

        $results = $allRoleRows;

        if ($results) {
            echo "<div class='text-xl font-bold pb-4'>Roles</div><div class='settings-table-wrap max-h-96 overflow-y-auto'><table class='w-full text-sm text-left'><thead class='bg-gray-100 sticky top-0 z-1'>";
            echo "<tr class='border-b border-slate-200 text-gray-800'>";
            foreach (array_keys($results[0]) as $header) {
                echo "<th class='p-2'>{$header}</th>";
            }
            echo "</tr></thead><tbody>";
            foreach ($results as $row) {
                echo "<tr class='settings-data-row'>";
                foreach ($row as $column) {
                    echo "<td class='p-2 border-b'>{$column}</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        } else {
            echo "No results found.";
        }

        $query = "SELECT access.uuid, access.role, role.caption AS role_caption, access.resource, access.access_right FROM access INNER JOIN role ON access.role = role.uuid ORDER BY role.caption, access.resource";
        $results = $db_adapter->db_query($query) ?: [];

    $roleRows = $db_adapter->db_query("SELECT uuid, caption FROM role ORDER BY caption") ?: [];
        foreach ($roleRows as $roleRow) {
            $automationExists = false;
            foreach ($results as $existingAccess) {
                if ((string)$existingAccess['role'] === (string)$roleRow['uuid'] && (string)$existingAccess['resource'] === 'automation') {
                    $automationExists = true;
                    break;
                }
            }

            if (!$automationExists) {
                $results[] = [
                    'uuid' => null,
                    'role' => $roleRow['uuid'],
                    'role_caption' => $roleRow['caption'],
                    'resource' => 'automation',
                    'access_right' => 0
                ];
            }
        }

        usort($results, function ($a, $b) {
            $roleCompare = strcmp((string)($a['role_caption'] ?? ''), (string)($b['role_caption'] ?? ''));
            if ($roleCompare !== 0) {
                return $roleCompare;
            }

            return strcmp((string)($a['resource'] ?? ''), (string)($b['resource'] ?? ''));
        });

        if ($results) {
            echo "<div class='text-xl font-bold pb-4'>Access Rights</div><div class='settings-table-wrap max-h-[32rem] overflow-y-auto'><table class='w-full text-sm text-left'><thead class='bg-gray-100 sticky top-0 z-1'>";
            echo "<tr class='border-b border-slate-200 text-gray-800'>";
            echo "<th class='p-2'>Role</th>";
            echo "<th class='p-2'>Resource</th>";
            echo "<th class='p-2'>Read</th>";
            echo "<th class='p-2'>Write</th>";
            echo "<th class='p-2'>Execute</th>";
            echo "<th class='p-2'>Wert</th>";
            echo "<th class='p-2'>Action</th>";
            echo "</tr></thead><tbody>";
            foreach ($results as $row) {
                $roleUuidEscaped = htmlspecialchars((string)$row['role'], ENT_QUOTES, 'UTF-8');
                $roleCaptionEscaped = htmlspecialchars((string)$row['role_caption'], ENT_QUOTES, 'UTF-8');
                $resourceEscaped = htmlspecialchars((string)$row['resource'], ENT_QUOTES, 'UTF-8');
                $accessRightValue = (int)($row['access_right'] ?? 0);
                $hasRead = ($accessRightValue & 4) === 4 ? 'checked' : '';
                $hasWrite = ($accessRightValue & 2) === 2 ? 'checked' : '';
                $hasExecute = ($accessRightValue & 1) === 1 ? 'checked' : '';

                echo "<tr class='settings-data-row'>";
                echo "<td class='p-2 border-b'>{$roleCaptionEscaped}</td>";
                echo "<td class='p-2 border-b font-mono'>{$resourceEscaped}</td>";
                echo "<form action='?set=update_access_right' method='post' class='m-0'>";
                echo "<input type='hidden' name='csrf' value='{$csrf}'>";
                echo "<input type='hidden' name='role_uuid' value='{$roleUuidEscaped}'>";
                echo "<input type='hidden' name='resource' value='{$resourceEscaped}'>";
                echo "<td class='p-2 border-b text-center'><input type='checkbox' name='access_read' {$hasRead}></td>";
                echo "<td class='p-2 border-b text-center'><input type='checkbox' name='access_write' {$hasWrite}></td>";
                echo "<td class='p-2 border-b text-center'><input type='checkbox' name='access_execute' {$hasExecute}></td>";
                echo "<td class='p-2 border-b font-mono text-gray-700'>{$accessRightValue}</td>";
                echo "<td class='p-2 border-b'>";
                echo "<button class='bg-blue-500 hover:bg-blue-700 text-white' type='submit'>Save</button>";
                echo "</form>";
                echo "</td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        } else {
            echo "No access rights found.";
        }

        echo <<<HTML
            </div>
            <!-- Details Popup -->
            <div id="detailsPopup" class="absolute top-0 left-0 h-full w-full p-4 bg-white rounded-lg z-2 hidden">
                <div class="flex justify-between pb-6">
                    <div class="text-xl font-bold">Details</div>
                    <div class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md">
                        <button type="button" onclick="closeDetailsPopup()" class="text-2xl text-white"><i data-lucide="x"></i></button>
                    </div>
                </div>
                <div id="detailsContent" class="space-y-2"></div>
            </div>
            <div id="editAccountPopup" class="absolute top-0 left-0 h-full w-full p-4 bg-white rounded-lg z-2 hidden overflow-y-auto">
                <div class="flex justify-between pb-6">
                    <div class="text-xl font-bold">Account bearbeiten</div>
                    <div class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md">
                        <button type="button" onclick="closeEditPopup()" class="text-2xl text-white"><i data-lucide="x"></i></button>
                    </div>
                </div>
                <form action="?set=update_account" method="post" class="max-w-xl">
                    <input type="hidden" name="csrf" value="$csrf">
                    <input type="hidden" id="edit_uuid" name="uuid" value="">
                    <div class="pb-4">
                        <label class="block mb-2 text-sm font-semibold" for="edit_username">Username</label>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="edit_username" type="text" name="username" required>
                    </div>
                    <div class="pb-4">
                        <label class="block mb-2 text-sm font-semibold" for="edit_email">E-Mail</label>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="edit_email" type="email" name="email" required>
                    </div>
                    <div class="pb-6">
                        <label class="block mb-2 text-sm font-semibold" for="edit_role">Role</label>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="edit_role" name="role" required>
HTML;

        foreach ($allRoleRows as $roleRow) {
            $roleUuidEsc = htmlspecialchars((string)($roleRow['uuid'] ?? ''), ENT_QUOTES, 'UTF-8');
            $roleCaptionEsc = htmlspecialchars((string)($roleRow['caption'] ?? ''), ENT_QUOTES, 'UTF-8');
            echo "<option value='{$roleUuidEsc}'>{$roleCaptionEsc}</option>";
        }

        echo <<<HTML
                        </select>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="bg-gray-300 hover:bg-gray-400 text-gray-900 font-bold py-2 px-4 rounded-full" type="button" onclick="closeEditPopup()">Abbrechen</button>
                        <button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full" type="submit">Speichern</button>
                    </div>
                </form>
            </div>
            <script>
                function openDetailsPopup(uuid) {
                    ajaxGet('?get=details&uuid=' + uuid, function(response) {
                        let formatted = JSON.stringify(response, null, 2);
                        document.getElementById('detailsContent').innerHTML = '<pre>' + formatted + '</pre>';
                }
                function openEditPopup(uuid) {
                    ajaxGet('?get=details&uuid=' + uuid, function(response) {
                        if (!response) {
                            return;
                        }

                        document.getElementById('edit_uuid').value = response.uuid || '';
                        document.getElementById('edit_username').value = response.username || '';
                        document.getElementById('edit_email').value = response.email || '';
                        document.getElementById('edit_role').value = response.role || '';
                        document.getElementById('editAccountPopup').classList.remove('hidden');
                    });
                }
                function closeEditPopup() {
                    document.getElementById('editAccountPopup').classList.add('hidden');
                }
                function ajaxGet(url, successCallback, errorCallback) {
                    $.ajax({
                        url: url,
                        type: 'GET',
                        dataType: 'json',
                        success: successCallback,
                        error: function(jqXHR) {
                            console.log('Error:', jqXHR.responseText);
                            if (errorCallback) errorCallback(jqXHR);
                        }
                    });
                }
            </script>
        HTML;
        break;
    case 'scripts':
        if ($role !== 'admin') {
            $logger->log('user is not admin', 2, echoToWeb: true);
            header('Location: ?site=appearance');
            die();
        }

        $automationStore = new AutomationStore();
        $automationSettings = $automationStore->getSettings();
        $csrf = $auth->csrf();

        try {
            migrateLegacyTemplateOverridesToDb($db_adapter, $automationStore, $logger);
            $automationSettings = $automationStore->getSettings();
        } catch (\Throwable $ignored) {
            // Ignore migration issues in UI rendering path.
        }

        if (is_array($automationFormDataOverride)) {
            $automationSettings = array_merge($automationSettings, $automationFormDataOverride);
        }

        $sshHost = htmlspecialchars((string)($automationSettings['ssh_host'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sshPort = (int)($automationSettings['ssh_port'] ?? 22);
        $sshAuthMethod = (string)($automationSettings['ssh_auth_method'] ?? 'password');
        $sshUsername = htmlspecialchars((string)($automationSettings['ssh_username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sshPrivateKey = htmlspecialchars((string)($automationSettings['ssh_private_key'] ?? ''), ENT_QUOTES, 'UTF-8');
        $scriptsJson = trim((string)($automationSettings['scripts_json'] ?? ''));
        if ($scriptsJson === '') {
            $scriptsJson = "{}";
        }
        $decodedScriptsConfig = decodeJsonObject($scriptsJson, []);
        $scriptsJsonEscaped = htmlspecialchars($scriptsJson, ENT_QUOTES, 'UTF-8');
        $switchInventoryJson = trim((string)($automationSettings['switch_inventory_json'] ?? ''));
        if ($switchInventoryJson === '') {
            $switchInventoryJson = '{"switches": []}';
        }
        $decodedInventoryConfig = decodeJsonObject($switchInventoryJson, ['switches' => []]);
        if (!isset($decodedInventoryConfig['switches']) || !is_array($decodedInventoryConfig['switches'])) {
            $decodedInventoryConfig['switches'] = [];
        }
        $switchInventoryJsonEscaped = htmlspecialchars($switchInventoryJson, ENT_QUOTES, 'UTF-8');
        $passwordHint = !empty($automationSettings['ssh_password']) ? 'Gespeichert (leer lassen zum Beibehalten)' : 'Noch nicht gesetzt';
        $privateKeyHint = !empty($automationSettings['ssh_private_key']) ? 'Gespeichert (leer lassen zum Beibehalten)' : 'Noch nicht gesetzt';
        $activeScriptsTab = getScriptsTabFromRequest();

        $dbTemplateOverrides = [];
        try {
            $dbTemplateOverrides = fetchAutomationTemplatesFromDb($db_adapter);
        } catch (\Throwable $ignored) {
            $dbTemplateOverrides = [];
        }

        $itamSwitchOptions = [];
        try {
            $itamSwitchOptions = $db_adapter->db_query(
                "SELECT device_uuid, device_metadata_caption, device_location_metadata_caption, device_location
                 FROM device_details
                 WHERE LOWER(COALESCE(device_type, '')) LIKE '%switch%'
                 ORDER BY device_metadata_caption ASC"
            ) ?: [];
        } catch (\Throwable $ignored) {
            $itamSwitchOptions = [];
        }

        if (empty($itamSwitchOptions)) {
            try {
                $itamSwitchOptions = $db_adapter->db_query(
                    "SELECT device_uuid, device_metadata_caption, device_location_metadata_caption, device_location
                     FROM device_details
                     ORDER BY device_metadata_caption ASC
                     LIMIT 300"
                ) ?: [];
            } catch (\Throwable $ignored) {
                $itamSwitchOptions = [];
            }
        }

        // Item-Group options (Stacks): distinct item_group UUIDs across device, with concatenated member captions.
        $itamItemGroupOptions = [];
        try {
            $itamItemGroupOptions = $db_adapter->db_query(
                "SELECT d.item_group AS item_group_uuid,
                        COUNT(*)::int AS member_count,
                        STRING_AGG(COALESCE(m.caption, ''), ', ' ORDER BY m.caption) AS member_captions
                 FROM device d
                 LEFT JOIN metadata m ON m.uuid = d.metadata
                 WHERE d.item_group IS NOT NULL
                 GROUP BY d.item_group
                 HAVING COUNT(*) > 1
                 ORDER BY MIN(m.caption) ASC"
            ) ?: [];
        } catch (\Throwable $ignored) {
            $itamItemGroupOptions = [];
        }

        $historyRows = [];
        try {
            $historyRows = $db_adapter->db_query(
                "SELECT c.operation, c.changed_table, c.changed_data, TO_CHAR(c.changed, 'DD.MM.YYYY HH24:MI:SS') AS changed_at, u.username
                 FROM changelog c
                 LEFT JOIN users u ON u.uuid = c.users
                 WHERE c.changed_table = 'script_execution'
                 ORDER BY c.changed DESC
                 LIMIT 30"
            ) ?: [];
        } catch (\Throwable $ignored) {
            $historyRows = [];
        }

        $historyRowsHtml = '';
        foreach ($historyRows as $historyRow) {
            $changedAtEscaped = htmlspecialchars((string)($historyRow['changed_at'] ?? ''), ENT_QUOTES, 'UTF-8');
            $usernameEscaped = htmlspecialchars((string)($historyRow['username'] ?? 'unknown'), ENT_QUOTES, 'UTF-8');
            $operationEscaped = htmlspecialchars((string)($historyRow['operation'] ?? ''), ENT_QUOTES, 'UTF-8');
            $changedTable = (string)($historyRow['changed_table'] ?? '');

            $actionText = '';
            $payloadText = '';
            $scriptContentText = '';
            $decodedChange = json_decode((string)($historyRow['changed_data'] ?? ''), true);
            if ($changedTable === 'script_execution') {
                if (is_array($decodedChange)) {
                    $mode = (string)($decodedChange['mode'] ?? 'unknown');
                    $switchName = (string)($decodedChange['switch'] ?? '');
                    $profile = (string)($decodedChange['profile'] ?? '');
                    $template = (string)($decodedChange['template'] ?? '');
                    $ok = isset($decodedChange['ok']) ? (bool)$decodedChange['ok'] : false;
                    $warning = isset($decodedChange['warning']) ? (bool)$decodedChange['warning'] : false;
                    $commandCount = (int)($decodedChange['command_count'] ?? 0);
                    $resultLabel = $ok ? ($warning ? 'warning' : 'ok') : 'failed';

                    $actionText = 'script_execution/' . $mode;
                    $payloadText = trim(
                        'switch=' . $switchName
                        . ' | profile=' . $profile
                        . ' | template=' . $template
                        . ' | commands=' . $commandCount
                        . ' | result=' . $resultLabel
                    );

                    $scriptContentText = (string)($decodedChange['script_content'] ?? '');
                }
            } else {
                if (is_array($decodedChange)) {
                    $actionText = (string)($decodedChange['action'] ?? '');
                    $payloadValue = $decodedChange['payload'] ?? null;
                    if (is_array($payloadValue)) {
                        $payloadText = json_encode($payloadValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        $payloadText = is_scalar($payloadValue) ? (string)$payloadValue : '';
                    }
                }
            }

            $actionEscaped = htmlspecialchars($actionText !== '' ? $actionText : '-', ENT_QUOTES, 'UTF-8');
            $payloadEscaped = htmlspecialchars($payloadText !== '' ? mb_substr($payloadText, 0, 280) : '-', ENT_QUOTES, 'UTF-8');

            $scriptContentHtml = '';
            if ($scriptContentText !== '') {
                $scriptContentEscaped = htmlspecialchars($scriptContentText, ENT_QUOTES, 'UTF-8');
                $scriptContentHtml = '<details class="mt-1"><summary class="cursor-pointer text-xs text-gray-700">Skriptinhalt</summary><pre class="mt-2 p-2 rounded bg-gray-50 text-xs font-mono whitespace-pre-wrap">' . $scriptContentEscaped . '</pre></details>';
            }

            $historySearch = strtolower($changedAtEscaped . ' ' . $usernameEscaped . ' ' . $operationEscaped . ' ' . $actionText . ' ' . $payloadText . ' ' . $scriptContentText);
            $historySearchEscaped = htmlspecialchars($historySearch, ENT_QUOTES, 'UTF-8');

            $historyRowsHtml .= <<<HTML
                <tr class="border-b border-gray-100 history-row" data-history-search="{$historySearchEscaped}">
                    <td class="py-2 px-3 text-sm text-gray-800">{$changedAtEscaped}</td>
                    <td class="py-2 px-3 text-sm text-gray-800">{$usernameEscaped}</td>
                    <td class="py-2 px-3 text-sm text-gray-800">{$operationEscaped}</td>
                    <td class="py-2 px-3 text-sm text-gray-800">{$actionEscaped}</td>
                    <td class="py-2 px-3 text-xs font-mono text-gray-600">{$payloadEscaped}{$scriptContentHtml}</td>
                </tr>
            HTML;
        }
        if ($historyRowsHtml === '') {
            $historyRowsHtml = '<tr><td colspan="5" class="py-4 px-3 text-sm text-gray-500">Noch keine Historie verfuegbar.</td></tr>';
        }

        $profileDefinitions = fetchAutomationProfilesFromFile();
        $availableProfiles = array_keys($profileDefinitions);
        if (empty($availableProfiles)) {
            $availableProfiles = ['huawei_core_commit', 'huawei_access_no_commit'];
        }

        $profileOptionsHtml = '';
        foreach ($availableProfiles as $profileId) {
            $profileEscaped = htmlspecialchars((string)$profileId, ENT_QUOTES, 'UTF-8');
            $profileOptionsHtml .= "<option value=\"{$profileEscaped}\">{$profileEscaped}</option>";
        }

        $profileSnmpDefaultsByProfile = [];
        $profileRowsHtml = '';
        foreach ($profileDefinitions as $profileId => $profileDefinition) {
            if (!is_array($profileDefinition)) {
                continue;
            }

            $profileIdEscaped = htmlspecialchars((string)$profileId, ENT_QUOTES, 'UTF-8');
            $profileLabelEscaped = htmlspecialchars((string)($profileDefinition['label'] ?? $profileId), ENT_QUOTES, 'UTF-8');
            $profileDescriptionEscaped = htmlspecialchars((string)($profileDefinition['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $enterConfigEscaped = htmlspecialchars((string)($profileDefinition['enter_config'] ?? ''), ENT_QUOTES, 'UTF-8');
            $commitEscaped = htmlspecialchars((string)($profileDefinition['commit'] ?? ''), ENT_QUOTES, 'UTF-8');
            $exitConfigEscaped = htmlspecialchars((string)($profileDefinition['exit_config'] ?? ''), ENT_QUOTES, 'UTF-8');
            $saveEscaped = htmlspecialchars((string)($profileDefinition['save'] ?? ''), ENT_QUOTES, 'UTF-8');
            $writeConfigEscaped = htmlspecialchars((string)($profileDefinition['write_config'] ?? ''), ENT_QUOTES, 'UTF-8');
            $supportsCommit = !empty($profileDefinition['supports_commit']) ? '1' : '0';

            $snmpConfig = is_array($profileDefinition['snmp'] ?? null) ? $profileDefinition['snmp'] : [];
            $snmpEnabled = !empty($snmpConfig['enabled']) ? '1' : '0';
            $snmpVersion = htmlspecialchars((string)($snmpConfig['version'] ?? '2c'), ENT_QUOTES, 'UTF-8');
            $snmpPort = (int)($snmpConfig['port'] ?? 161);
            $snmpTimeout = (int)($snmpConfig['timeout'] ?? 2);
            $snmpRetries = (int)($snmpConfig['retries'] ?? 1);
            $snmpCommunityEscaped = htmlspecialchars((string)($snmpConfig['community'] ?? ''), ENT_QUOTES, 'UTF-8');
            $snmpV3UsernameEscaped = htmlspecialchars((string)($snmpConfig['v3_username'] ?? ''), ENT_QUOTES, 'UTF-8');
            $snmpV3AuthProtocolEscaped = htmlspecialchars((string)($snmpConfig['v3_auth_protocol'] ?? 'SHA'), ENT_QUOTES, 'UTF-8');
            $snmpV3AuthPassphraseEscaped = htmlspecialchars((string)($snmpConfig['v3_auth_passphrase'] ?? ''), ENT_QUOTES, 'UTF-8');
            $snmpV3PrivProtocolEscaped = htmlspecialchars((string)($snmpConfig['v3_priv_protocol'] ?? 'AES'), ENT_QUOTES, 'UTF-8');
            $snmpV3PrivPassphraseEscaped = htmlspecialchars((string)($snmpConfig['v3_priv_passphrase'] ?? ''), ENT_QUOTES, 'UTF-8');
            $defaultMibEscaped = htmlspecialchars((string)($snmpConfig['default_mib'] ?? ''), ENT_QUOTES, 'UTF-8');
            $mibOverridesList = array_values((array)($snmpConfig['mib_overrides'] ?? []));
            $mibOverridesTextEscaped = htmlspecialchars(implode("\n", array_map('strval', $mibOverridesList)), ENT_QUOTES, 'UTF-8');
            $mibOverridesCount = count($mibOverridesList);

            $profileSnmpDefaultsByProfile[$profileId] = [
                'default_mib' => (string)($snmpConfig['default_mib'] ?? ''),
                'version' => (string)($snmpConfig['version'] ?? '2c'),
                'community' => (string)($snmpConfig['community'] ?? ''),
                'v3_username' => (string)($snmpConfig['v3_username'] ?? ''),
                'v3_auth_protocol' => (string)($snmpConfig['v3_auth_protocol'] ?? 'SHA'),
                'v3_auth_passphrase' => (string)($snmpConfig['v3_auth_passphrase'] ?? ''),
                'v3_priv_protocol' => (string)($snmpConfig['v3_priv_protocol'] ?? 'AES'),
                'v3_priv_passphrase' => (string)($snmpConfig['v3_priv_passphrase'] ?? '')
            ];

            $profileRowsHtml .= <<<HTML
                <tr class="border-b border-gray-100">
                    <td class="py-2 px-3 font-mono text-xs text-gray-900">{$profileIdEscaped}</td>
                    <td class="py-2 px-3 text-sm text-gray-800">{$profileLabelEscaped}</td>
                    <td class="py-2 px-3 text-sm text-gray-700">{$snmpVersion}</td>
                    <td class="py-2 px-3 font-mono text-xs text-gray-700">{$defaultMibEscaped}</td>
                    <td class="py-2 px-3 text-sm text-gray-700">{$mibOverridesCount}</td>
                    <td class="py-2 px-3 text-right whitespace-nowrap">
                        <button
                            type="button"
                            class="settings-icon-btn bg-amber-500 hover:bg-amber-700"
                            title="Bearbeiten"
                            aria-label="Bearbeiten"
                            data-profile-id="{$profileIdEscaped}"
                            data-profile-label="{$profileLabelEscaped}"
                            data-profile-description="{$profileDescriptionEscaped}"
                            data-profile-enter-config="{$enterConfigEscaped}"
                            data-profile-commit="{$commitEscaped}"
                            data-profile-exit-config="{$exitConfigEscaped}"
                            data-profile-save="{$saveEscaped}"
                            data-profile-write-config="{$writeConfigEscaped}"
                            data-profile-supports-commit="{$supportsCommit}"
                            data-profile-snmp-enabled="{$snmpEnabled}"
                            data-profile-snmp-version="{$snmpVersion}"
                            data-profile-snmp-port="{$snmpPort}"
                            data-profile-snmp-timeout="{$snmpTimeout}"
                            data-profile-snmp-retries="{$snmpRetries}"
                            data-profile-snmp-community="{$snmpCommunityEscaped}"
                            data-profile-snmp-v3-username="{$snmpV3UsernameEscaped}"
                            data-profile-snmp-v3-auth-protocol="{$snmpV3AuthProtocolEscaped}"
                            data-profile-snmp-v3-auth-passphrase="{$snmpV3AuthPassphraseEscaped}"
                            data-profile-snmp-v3-priv-protocol="{$snmpV3PrivProtocolEscaped}"
                            data-profile-snmp-v3-priv-passphrase="{$snmpV3PrivPassphraseEscaped}"
                            data-profile-snmp-default-mib="{$defaultMibEscaped}"
                            data-profile-snmp-mib-overrides="{$mibOverridesTextEscaped}"
                            onclick="loadProfileDefinition(this)">
                            <i data-lucide="pencil"></i>
                        </button>
                        <button class="settings-icon-btn bg-red-500 hover:bg-red-700 ml-2" type="button" title="Loeschen" aria-label="Loeschen" onclick="submitProfileDelete('{$profileIdEscaped}')"><i data-lucide="trash-2"></i></button>
                    </td>
                </tr>
            HTML;
        }
        if ($profileRowsHtml === '') {
            $profileRowsHtml = '<tr><td colspan="6" class="py-4 px-3 text-sm text-gray-500">Noch keine Profile vorhanden.</td></tr>';
        }

        $itamDeviceOptionsHtml = '<option value="">(kein ITAM Geraet verknuepft)</option>';
        foreach ($itamSwitchOptions as $itamDeviceRow) {
            $deviceUuid = htmlspecialchars((string)($itamDeviceRow['device_uuid'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($deviceUuid === '') {
                continue;
            }
            $deviceCaption = htmlspecialchars((string)($itamDeviceRow['device_metadata_caption'] ?? ''), ENT_QUOTES, 'UTF-8');
            $locationCaption = htmlspecialchars((string)($itamDeviceRow['device_location_metadata_caption'] ?? ''), ENT_QUOTES, 'UTF-8');
            $label = trim($deviceCaption . ($locationCaption !== '' ? ' | ' . $locationCaption : ''));
            if ($label === '') {
                $label = $deviceUuid;
            }
            $itamDeviceOptionsHtml .= '<option value="' . $deviceUuid . '">' . $label . '</option>';
        }

        $itamItemGroupOptionsHtml = '<option value="">(keine Item Group / kein Stack)</option>';
        foreach ($itamItemGroupOptions as $groupRow) {
            $groupUuid = htmlspecialchars((string)($groupRow['item_group_uuid'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($groupUuid === '') {
                continue;
            }
            $memberCount = (int)($groupRow['member_count'] ?? 0);
            $memberCaptions = (string)($groupRow['member_captions'] ?? '');
            if (mb_strlen($memberCaptions) > 80) {
                $memberCaptions = mb_substr($memberCaptions, 0, 77) . '...';
            }
            $label = htmlspecialchars($memberCount . ' Member: ' . $memberCaptions, ENT_QUOTES, 'UTF-8');
            $itamItemGroupOptionsHtml .= '<option value="' . $groupUuid . '">' . $label . '</option>';
        }

        $inventoryRowsHtml = '';
        foreach ($decodedInventoryConfig['switches'] as $index => $switchItem) {
            if (!is_array($switchItem)) {
                continue;
            }

            $nameEscaped = htmlspecialchars((string)($switchItem['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $mgmtIpEscaped = htmlspecialchars((string)($switchItem['mgmt_ip'] ?? ''), ENT_QUOTES, 'UTF-8');
            $profileEscaped = htmlspecialchars((string)($switchItem['profile'] ?? ''), ENT_QUOTES, 'UTF-8');
            $deviceEscaped = htmlspecialchars((string)($switchItem['device_id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $credentialModeEscaped = htmlspecialchars((string)($switchItem['credential_mode'] ?? 'global'), ENT_QUOTES, 'UTF-8');
            $switchAuthMethodEscaped = htmlspecialchars((string)($switchItem['ssh_auth_method'] ?? 'password'), ENT_QUOTES, 'UTF-8');
            $switchUsernameEscaped = htmlspecialchars((string)($switchItem['ssh_username'] ?? ''), ENT_QUOTES, 'UTF-8');
            $snmpConfig = is_array($switchItem['snmp'] ?? null) ? $switchItem['snmp'] : [];
            $snmpEnabledEscaped = !empty($snmpConfig['enabled']) ? '1' : '0';
            $snmpVersionEscaped = htmlspecialchars((string)($snmpConfig['version'] ?? '2c'), ENT_QUOTES, 'UTF-8');
            $snmpCommunityEscaped = htmlspecialchars((string)($snmpConfig['community'] ?? ''), ENT_QUOTES, 'UTF-8');
            $snmpMibEscaped = htmlspecialchars((string)($snmpConfig['mib'] ?? ''), ENT_QUOTES, 'UTF-8');
            $snmpPortEscaped = htmlspecialchars((string)($snmpConfig['port'] ?? 161), ENT_QUOTES, 'UTF-8');
            $snmpTimeoutEscaped = htmlspecialchars((string)($snmpConfig['timeout'] ?? 2), ENT_QUOTES, 'UTF-8');
            $snmpRetriesEscaped = htmlspecialchars((string)($snmpConfig['retries'] ?? 1), ENT_QUOTES, 'UTF-8');
            $snmpV3UsernameEscaped = htmlspecialchars((string)($snmpConfig['v3_username'] ?? ''), ENT_QUOTES, 'UTF-8');
            $snmpV3AuthProtocolEscaped = htmlspecialchars((string)($snmpConfig['v3_auth_protocol'] ?? 'SHA'), ENT_QUOTES, 'UTF-8');
            $snmpV3AuthPassphraseEscaped = htmlspecialchars((string)($snmpConfig['v3_auth_passphrase'] ?? ''), ENT_QUOTES, 'UTF-8');
            $snmpV3PrivProtocolEscaped = htmlspecialchars((string)($snmpConfig['v3_priv_protocol'] ?? 'AES'), ENT_QUOTES, 'UTF-8');
            $snmpV3PrivPassphraseEscaped = htmlspecialchars((string)($snmpConfig['v3_priv_passphrase'] ?? ''), ENT_QUOTES, 'UTF-8');
            $nameDataEscaped = htmlspecialchars((string)($switchItem['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $mgmtIpDataEscaped = htmlspecialchars((string)($switchItem['mgmt_ip'] ?? ''), ENT_QUOTES, 'UTF-8');
            $profileDataEscaped = htmlspecialchars((string)($switchItem['profile'] ?? ''), ENT_QUOTES, 'UTF-8');
            $deviceDataEscaped = htmlspecialchars((string)($switchItem['device_id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $itemGroupIdEscaped = htmlspecialchars((string)($switchItem['item_group_id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $stackBadge = ($switchItem['item_group_id'] ?? '') !== ''
                ? '<span class="ml-1 inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-800" title="Stack via Item Group">Stack</span>'
                : '';
            $deviceCellEscaped = $deviceEscaped . $stackBadge;
            $indexValue = (int)$index;

            $inventoryRowsHtml .= <<<HTML
                <tr class="border-b border-gray-100">
                    <td class="p-2 border-b font-medium text-gray-900">{$nameEscaped}</td>
                    <td class="p-2 border-b font-mono text-sm text-gray-700">{$mgmtIpEscaped}</td>
                    <td class="p-2 border-b"><span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold">{$profileEscaped}</span></td>
                    <td class="p-2 border-b text-sm text-gray-700">{$deviceCellEscaped}</td>
                    <td class="p-2 border-b text-xs text-gray-700">{$credentialModeEscaped}/{$switchAuthMethodEscaped}</td>
                    <td class="p-2 border-b flex flex-row gap-2">
                        <button class="h-10 w-10 rounded-full bg-emerald-500 hover:bg-emerald-700 text-white flex items-center justify-center" type="button" title="SSH testen" aria-label="SSH testen" data-switch-name="{$nameDataEscaped}" data-switch-mgmt-ip="{$mgmtIpDataEscaped}" data-switch-credential-mode="{$credentialModeEscaped}" data-switch-auth-method="{$switchAuthMethodEscaped}" data-switch-ssh-username="{$switchUsernameEscaped}" onclick="submitInventorySshTest(this)"><i data-lucide="terminal"></i></button>
                        <button class="h-10 w-10 rounded-full bg-cyan-500 hover:bg-cyan-700 text-white flex items-center justify-center" type="button" title="SNMP testen" aria-label="SNMP testen" data-switch-name="{$nameDataEscaped}" data-switch-mgmt-ip="{$mgmtIpDataEscaped}" data-switch-snmp-version="{$snmpVersionEscaped}" data-switch-snmp-community="{$snmpCommunityEscaped}" data-switch-snmp-mib="{$snmpMibEscaped}" data-switch-snmp-port="{$snmpPortEscaped}" data-switch-snmp-timeout="{$snmpTimeoutEscaped}" data-switch-snmp-retries="{$snmpRetriesEscaped}" data-switch-snmp-v3-username="{$snmpV3UsernameEscaped}" data-switch-snmp-v3-auth-protocol="{$snmpV3AuthProtocolEscaped}" data-switch-snmp-v3-priv-protocol="{$snmpV3PrivProtocolEscaped}" onclick="submitInventorySnmpTest(this)"><i data-lucide="activity"></i></button>
                        <button class="h-10 w-10 rounded-full bg-indigo-500 hover:bg-indigo-700 text-white flex items-center justify-center" type="button" title="SNMP-Scan jetzt" aria-label="SNMP-Scan jetzt" data-switch-name="{$nameDataEscaped}" onclick="submitInventorySnmpScan(this)"><i data-lucide="radar"></i></button>
                        <button class="h-10 w-10 rounded-full bg-amber-500 hover:bg-amber-700 text-white flex items-center justify-center" type="button" title="Bearbeiten" aria-label="Bearbeiten" data-switch-name="{$nameDataEscaped}" data-switch-mgmt-ip="{$mgmtIpDataEscaped}" data-switch-profile="{$profileDataEscaped}" data-switch-device-id="{$deviceDataEscaped}" data-switch-item-group-id="{$itemGroupIdEscaped}" data-switch-credential-mode="{$credentialModeEscaped}" data-switch-auth-method="{$switchAuthMethodEscaped}" data-switch-ssh-username="{$switchUsernameEscaped}" data-switch-snmp-enabled="{$snmpEnabledEscaped}" data-switch-snmp-version="{$snmpVersionEscaped}" data-switch-snmp-community="{$snmpCommunityEscaped}" data-switch-snmp-mib="{$snmpMibEscaped}" data-switch-snmp-v3-username="{$snmpV3UsernameEscaped}" data-switch-snmp-v3-auth-protocol="{$snmpV3AuthProtocolEscaped}" data-switch-snmp-v3-priv-protocol="{$snmpV3PrivProtocolEscaped}" onclick="loadInventoryEntry(this)"><i data-lucide="pencil"></i></button>
                        <button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center" type="button" title="Loeschen" aria-label="Loeschen" onclick="submitInventoryDelete({$indexValue})"><i data-lucide="trash-2"></i></button>
                    </td>
                </tr>
            HTML;
        }
        if ($inventoryRowsHtml === '') {
            $inventoryRowsHtml = '<tr><td colspan="6" class="py-4 px-3 text-sm text-gray-500">Noch keine Switch-Eintraege vorhanden.</td></tr>';
        }

        $templateRowsHtml = '';
        foreach ($dbTemplateOverrides as $templateId => $templateOverride) {
            if (!is_array($templateOverride)) {
                continue;
            }

            $templateIdEscaped = htmlspecialchars((string)$templateId, ENT_QUOTES, 'UTF-8');
            $labelEscaped = htmlspecialchars((string)($templateOverride['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $descriptionEscaped = htmlspecialchars((string)($templateOverride['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $profilesList = is_array($templateOverride['supported_profiles'] ?? null) ? $templateOverride['supported_profiles'] : [];
            $profilesText = implode(', ', array_map('strval', $profilesList));
            $profilesEscaped = htmlspecialchars($profilesText, ENT_QUOTES, 'UTF-8');
            $commandsList = is_array($templateOverride['commands'] ?? null) ? $templateOverride['commands'] : [];
            $commandsText = implode("\n", array_map('strval', $commandsList));
            $commandsEscapedForData = htmlspecialchars($commandsText, ENT_QUOTES, 'UTF-8');
            $profilesEscapedForData = htmlspecialchars(implode(',', array_map('strval', $profilesList)), ENT_QUOTES, 'UTF-8');
            $usesConvention = !empty($templateOverride['uses_description_convention']);
            $commandCount = count($commandsList);

            $templateRowsHtml .= <<<HTML
                <tr class="border-b border-gray-100">
                    <td class="py-2 px-3 font-mono text-xs text-gray-900">{$templateIdEscaped}</td>
                    <td class="py-2 px-3 text-sm text-gray-800">{$labelEscaped}</td>
                    <td class="py-2 px-3 text-sm text-gray-700">{$profilesEscaped}</td>
                    <td class="py-2 px-3 text-sm text-gray-700">{$commandCount}</td>
                    <td class="py-2 px-3 text-right whitespace-nowrap">
                        <button
                            type="button"
                            class="settings-icon-btn bg-amber-500 hover:bg-amber-700"
                            title="Bearbeiten"
                            aria-label="Bearbeiten"
                            data-template-id="{$templateIdEscaped}"
                            data-template-label="{$labelEscaped}"
                            data-template-description="{$descriptionEscaped}"
                            data-template-profiles="{$profilesEscapedForData}"
                            data-template-commands="{$commandsEscapedForData}"
                            data-template-uses-convention="{$usesConvention}"
                            onclick="loadTemplateOverride(this)">
                            <i data-lucide="pencil"></i>
                        </button>
                        <button class="settings-icon-btn bg-red-500 hover:bg-red-700 ml-2" type="button" title="Loeschen" aria-label="Loeschen" onclick="submitTemplateDelete('{$templateIdEscaped}')"><i data-lucide="trash-2"></i></button>
                    </td>
                </tr>
            HTML;
        }
        if ($templateRowsHtml === '') {
            $templateRowsHtml = '<tr><td colspan="5" class="py-4 px-3 text-sm text-gray-500">Noch keine Template-Overrides vorhanden.</td></tr>';
        }

        $testOutputHtml = '';
        if (is_array($automationTestResult) && isset($automationTestResult['output'])) {
            $testStateClass = !empty($automationTestResult['ok'])
                ? 'bg-green-50 border-green-200 text-green-900'
                : 'bg-red-50 border-red-200 text-red-900';
            $testOutputEscaped = htmlspecialchars((string)$automationTestResult['output'], ENT_QUOTES, 'UTF-8');
            $testTitleEscaped = htmlspecialchars((string)($automationTestResult['title'] ?? 'Test Output'), ENT_QUOTES, 'UTF-8');
            $testOutputHtml = "<div class=\"rounded-2xl border p-4 {$testStateClass}\"><div class=\"text-sm font-semibold pb-2\">{$testTitleEscaped}</div><pre class=\"text-xs whitespace-pre-wrap leading-5\">{$testOutputEscaped}</pre></div>";
        }

        $globalAuthPasswordSelected = ($sshAuthMethod === 'key') ? '' : 'selected';
        $globalAuthKeySelected = ($sshAuthMethod === 'key') ? 'selected' : '';
        $profileSnmpDefaultsJson = json_encode($profileSnmpDefaultsByProfile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($profileSnmpDefaultsJson)) {
            $profileSnmpDefaultsJson = '{}';
        }

        echo <<<HTML
        <div class="h-fit w-full p-4">
            <div class="text-xl font-bold pb-2">Automation: Secure Settings</div>
            <p class="text-sm text-gray-600 pb-6">SSH-Zugangsdaten und Switch-Inventar werden verschluesselt in <span class="font-semibold">data/automation/settings.json</span> gespeichert. Templates werden in <span class="font-semibold">data/automation/automation.json</span> gepflegt.</p>
            {$testOutputHtml}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pb-4 scripts-section-switch">
                    <div>
                        <label class="block mb-2 text-sm font-semibold" for="ssh_host">SSH Host / Default Switch</label>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="ssh_host" type="text" name="ssh_host" value="$sshHost" placeholder="192.168.1.10">
                    </div>
                    <div>
                        <label class="block mb-2 text-sm font-semibold" for="ssh_port">SSH Port</label>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="ssh_port" type="number" min="1" max="65535" name="ssh_port" value="$sshPort">
                    </div>
                    <div>
                        <label class="block mb-2 text-sm font-semibold" for="ssh_auth_method">Globale SSH Auth Methode</label>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="ssh_auth_method" name="ssh_auth_method">
                            <option value="password" {$globalAuthPasswordSelected}>Passwort</option>
                            <option value="key" {$globalAuthKeySelected}>SSH Key</option>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-2 text-sm font-semibold" for="ssh_username">SSH Username</label>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="ssh_username" type="text" name="ssh_username" value="$sshUsername" placeholder="netadmin">
                    </div>
                    <div>
                        <label class="block mb-2 text-sm font-semibold" for="ssh_password">SSH Password</label>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="ssh_password" type="password" name="ssh_password" placeholder="$passwordHint">
                        <p class="text-xs text-gray-500 mt-2">$passwordHint</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block mb-2 text-sm font-semibold" for="ssh_private_key">SSH Private Key (PEM, optional)</label>
                        <textarea class="appearance-none border rounded-2xl w-full py-3 px-4 leading-tight focus:outline-none focus:shadow-outline font-mono text-xs" id="ssh_private_key" name="ssh_private_key" rows="6" placeholder="$privateKeyHint">$sshPrivateKey</textarea>
                        <p class="text-xs text-gray-500 mt-2">$privateKeyHint</p>
                    </div>
                </div>

                <div class="pb-6 flex justify-end scripts-section-switch">
                    <button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="submitAutomationSettingsSave()">SSH-Zugangsdaten speichern</button>
                </div>
            </div>

                <div class="pb-6 scripts-section-switch">
                    <div class="flex items-center justify-between pb-2">
                        <label class="block text-sm font-semibold">Switch Inventory (Grafische Verwaltung)</label>
                        <button type="button" class="inline-flex items-center gap-2 rounded-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-2" title="Alle Switches scannen" onclick="submitInventorySnmpScanAll()"><i data-lucide="radar" class="h-4 w-4"></i><span>Alle scannen</span></button>
                    </div>
                    <div class="border border-gray-200 rounded-2xl overflow-hidden">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 text-gray-700">
                                <tr>
                                    <th class="py-2 px-3">Name</th>
                                    <th class="py-2 px-3">Mgmt IP</th>
                                    <th class="py-2 px-3">Profil</th>
                                    <th class="py-2 px-3">Device ID</th>
                                    <th class="py-2 px-3">Credentials</th>
                                    <th class="py-2 px-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$inventoryRowsHtml}
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Erforderlich pro Switch: name, mgmt_ip, profile. ITAM-Verknuepfung per Dropdown, Credentials global oder individuell.</p>

                    <input type="hidden" id="switch_original_name" value="">
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-6 gap-3">
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="text" id="switch_name" placeholder="SW-Core-01" required>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="text" id="switch_mgmt_ip" placeholder="10.0.0.10" required>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="switch_profile" required>
                            {$profileOptionsHtml}
                        </select>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="switch_device_id">{$itamDeviceOptionsHtml}</select>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="switch_credential_mode">
                            <option value="global">globale Credentials</option>
                            <option value="individual">individuelle Credentials</option>
                        </select>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="switch_auth_method">
                            <option value="password">Passwort</option>
                            <option value="key">SSH Key</option>
                        </select>
                    </div>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-1 gap-3">
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="switch_item_group_id" title="Item Group fuer Stack-Switches (mehrere Devices = ein logischer Switch)">{$itamItemGroupOptionsHtml}</select>
                    </div>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="text" id="switch_ssh_username" placeholder="individueller SSH Username">
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="password" id="switch_ssh_password" placeholder="individuelles SSH Passwort (optional)">
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="text" id="switch_snmp_community" placeholder="SNMP Community (v2c)">
                    </div>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-5 gap-3">
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="text" id="switch_snmp_v3_username" placeholder="SNMPv3 Username">
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="switch_snmp_v3_auth_protocol">
                            <option value="SHA">Auth SHA</option>
                            <option value="MD5">Auth MD5</option>
                            <option value="SHA224">Auth SHA224</option>
                            <option value="SHA256">Auth SHA256</option>
                            <option value="SHA384">Auth SHA384</option>
                            <option value="SHA512">Auth SHA512</option>
                        </select>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="password" id="switch_snmp_v3_auth_passphrase" placeholder="SNMPv3 Auth Passphrase">
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="switch_snmp_v3_priv_protocol">
                            <option value="AES">Priv AES</option>
                            <option value="AES128">Priv AES128</option>
                            <option value="AES192">Priv AES192</option>
                            <option value="AES256">Priv AES256</option>
                            <option value="DES">Priv DES</option>
                        </select>
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="password" id="switch_snmp_v3_priv_passphrase" placeholder="SNMPv3 Priv Passphrase">
                    </div>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-1 gap-3">
                        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" type="text" id="switch_snmp_mib" placeholder="SNMP MIB (optional, z. B. IF-MIB)">
                    </div>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                        <textarea class="appearance-none border rounded-2xl w-full py-3 px-4 leading-tight focus:outline-none focus:shadow-outline font-mono text-xs" id="switch_ssh_private_key" rows="4" placeholder="individueller SSH Private Key (optional)"></textarea>
                        <div class="flex flex-col gap-3">
                            <label class="inline-flex items-center gap-2"><input type="checkbox" id="switch_snmp_enabled"> SNMP fuer Switch aktivieren</label>
                            <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="switch_snmp_version">
                                <option value="2c">SNMP v2c</option>
                                <option value="3">SNMP v3</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                        <button class="bg-cyan-500 hover:bg-cyan-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="submitCurrentSnmpTest()">SNMP testen</button>
                        <button id="inventory_submit_button" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="submitInventorySave()">Switch hinzufuegen</button>
                        <button class="bg-gray-600 hover:bg-gray-800 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="resetInventoryForm()">Formular leeren</button>
                    </div>
                </div>

                <div class="pb-6 scripts-section-profile">
                    <div class="flex items-center justify-between pb-2">
                        <label class="block text-sm font-semibold">Profile inkl. SNMP/MIB (Grafische Verwaltung)</label>
                    </div>
                    <div class="border border-gray-200 rounded-2xl overflow-hidden">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 text-gray-700">
                                <tr>
                                    <th class="py-2 px-3">Profile ID</th>
                                    <th class="py-2 px-3">Label</th>
                                    <th class="py-2 px-3">SNMP Version</th>
                                    <th class="py-2 px-3">Default MIB</th>
                                    <th class="py-2 px-3">MIB Overrides</th>
                                    <th class="py-2 px-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$profileRowsHtml}
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 space-y-3" id="profile_definition_form">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block mb-1 text-xs font-semibold" for="profile_id">Profile ID</label>
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="profile_id" type="text" placeholder="huawei_core_commit" required>
                            </div>
                            <div>
                                <label class="block mb-1 text-xs font-semibold" for="profile_label">Label</label>
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline text-sm" id="profile_label" type="text" placeholder="Huawei Core" required>
                            </div>
                        </div>
                        <div>
                            <label class="block mb-1 text-xs font-semibold" for="profile_description">Beschreibung</label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline text-sm" id="profile_description" type="text" placeholder="Kurzbeschreibung des Profils">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="profile_enter_config" type="text" placeholder="enter_config">
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="profile_commit" type="text" placeholder="commit">
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="profile_exit_config" type="text" placeholder="exit_config">
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="profile_save" type="text" placeholder="save">
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="profile_write_config" type="text" placeholder="write_config">
                        </div>
                        <div class="flex items-center gap-2">
                            <input id="profile_supports_commit" type="checkbox" value="1">
                            <label for="profile_supports_commit" class="text-xs text-gray-700">supports_commit aktivieren</label>
                        </div>

                        <div class="rounded-2xl border border-gray-200 p-3 space-y-3">
                            <div class="text-xs font-semibold text-gray-700 uppercase tracking-wide">SNMP + MIB Defaults (pro Profil)</div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <label class="inline-flex items-center gap-2"><input type="checkbox" id="profile_snmp_enabled"> SNMP aktiv</label>
                                <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_version">
                                    <option value="2c">SNMP v2c</option>
                                    <option value="3">SNMP v3</option>
                                </select>
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_community" type="text" placeholder="Community (v2c)">
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_v3_username" type="text" placeholder="SNMPv3 Username">
                                <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_v3_auth_protocol">
                                    <option value="SHA">Auth SHA</option>
                                    <option value="MD5">Auth MD5</option>
                                    <option value="SHA224">Auth SHA224</option>
                                    <option value="SHA256">Auth SHA256</option>
                                    <option value="SHA384">Auth SHA384</option>
                                    <option value="SHA512">Auth SHA512</option>
                                </select>
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_v3_auth_passphrase" type="password" placeholder="SNMPv3 Auth Passphrase">
                                <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_v3_priv_protocol">
                                    <option value="AES">Priv AES</option>
                                    <option value="AES128">Priv AES128</option>
                                    <option value="AES192">Priv AES192</option>
                                    <option value="AES256">Priv AES256</option>
                                    <option value="DES">Priv DES</option>
                                </select>
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_v3_priv_passphrase" type="password" placeholder="SNMPv3 Priv Passphrase">
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_port" type="number" min="1" max="65535" value="161" placeholder="Port">
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_timeout" type="number" min="1" max="30" value="2" placeholder="Timeout (s)">
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="profile_snmp_retries" type="number" min="0" max="10" value="1" placeholder="Retries">
                            </div>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="profile_snmp_default_mib" type="text" placeholder="Default MIB, z. B. IF-MIB">
                            <div>
                                <label class="block mb-1 text-xs font-semibold" for="profile_snmp_mib_overrides">MIB Overrides (eine Zeile oder comma-separated)</label>
                                <textarea class="appearance-none border rounded-2xl w-full py-3 px-4 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="profile_snmp_mib_overrides" rows="4" placeholder="HUAWEI-L2IF-MIB&#10;ENTITY-MIB"></textarea>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <button class="bg-gray-600 hover:bg-gray-800 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="resetProfileDefinitionForm()">Formular leeren</button>
                            <button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="submitProfileUpsert()">Profil speichern</button>
                        </div>
                    </div>
                </div>

                <div class="pb-6 scripts-section-template">
                    <div class="flex items-center justify-between pb-2">
                        <label class="block text-sm font-semibold">Automation Templates (Grafische Verwaltung)</label>
                    </div>
                    <div class="border border-gray-200 rounded-2xl overflow-hidden">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 text-gray-700">
                                <tr>
                                    <th class="py-2 px-3">Template ID</th>
                                    <th class="py-2 px-3">Label</th>
                                    <th class="py-2 px-3">Profiles</th>
                                    <th class="py-2 px-3">Commands</th>
                                    <th class="py-2 px-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$templateRowsHtml}
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 space-y-3" id="template_override_form">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block mb-1 text-xs font-semibold" for="template_id">Template ID</label>
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="template_id" type="text" placeholder="my_custom_template" required>
                            </div>
                            <div>
                                <label class="block mb-1 text-xs font-semibold" for="template_label">Label</label>
                                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline text-sm" id="template_label" type="text" placeholder="Mein Template" required>
                            </div>
                        </div>
                        <div>
                            <label class="block mb-1 text-xs font-semibold" for="template_description">Beschreibung</label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline text-sm" id="template_description" type="text" placeholder="Kurze Beschreibung">
                        </div>
                        <div>
                            <label class="block mb-1 text-xs font-semibold" for="template_supported_profiles">Supported Profiles (comma-separated)</label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="template_supported_profiles" type="text" placeholder="huawei_core_commit,huawei_access_no_commit">
                        </div>
                        <div>
                            <label class="block mb-1 text-xs font-semibold" for="template_commands">Commands (eine Zeile = ein Command)</label>
                            <textarea class="appearance-none border rounded-2xl w-full py-3 px-4 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="template_commands" rows="8" placeholder="interface {{interface}}&#10;shutdown&#10;quit" required></textarea>
                        </div>
                        <div class="flex items-center gap-2">
                            <input id="template_uses_description_convention" type="checkbox" value="1">
                            <label for="template_uses_description_convention" class="text-xs text-gray-700">Description Convention verwenden</label>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <button class="bg-gray-600 hover:bg-gray-800 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="resetTemplateOverrideForm()">Formular leeren</button>
                            <button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="submitTemplateUpsert()">Template speichern</button>
                        </div>
                    </div>
                </div>

                <div class="pb-6 scripts-section-history">
                    <div class="flex items-center justify-between pb-2">
                        <label class="block text-sm font-semibold">Aenderungshistorie (letzte 30)</label>
                    </div>
                    <div class="pb-3">
                        <input id="history_filter" type="text" class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline text-sm" placeholder="Historie filtern: Benutzer, Action, Operation, Details..." oninput="filterHistoryRows()">
                    </div>
                    <div class="border border-gray-200 rounded-2xl overflow-hidden">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 text-gray-700">
                                <tr>
                                    <th class="py-2 px-3">Zeit</th>
                                    <th class="py-2 px-3">Benutzer</th>
                                    <th class="py-2 px-3">Operation</th>
                                    <th class="py-2 px-3">Action</th>
                                    <th class="py-2 px-3">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$historyRowsHtml}
                            </tbody>
                        </table>
                    </div>
                </div>

                <form action="?set=automation_scripts" method="post" class="scripts-section-switch">
                    <input type="hidden" name="csrf" value="$csrf">
                    <input type="hidden" class="scripts-active-tab-input" name="scripts_active_tab" value="$activeScriptsTab">

                    <details class="pb-4 scripts-section-switch">
                        <summary class="cursor-pointer text-sm font-semibold text-gray-700">Advanced JSON Bearbeitung</summary>
                        <div class="pt-3 space-y-4">
                            <div>
                                <label class="block mb-2 text-sm font-semibold" for="switch_inventory_json">Switch Inventory (JSON Fallback)</label>
                                <textarea class="appearance-none border rounded-2xl w-full py-3 px-4 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="switch_inventory_json" name="switch_inventory_json" rows="10" placeholder='{"switches":[{"name":"SW-Core-01","mgmt_ip":"10.0.0.10","profile":"huawei_core_commit","device_id":"uuid-from-itam"}]}'>{$switchInventoryJsonEscaped}</textarea>
                            </div>

                            <div>
                                <label class="block mb-2 text-sm font-semibold" for="scripts_json">Automation Script Overrides (JSON Fallback)</label>
                                <textarea class="appearance-none border rounded-2xl w-full py-3 px-4 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="scripts_json" name="scripts_json" rows="16" placeholder='{"templates": {}}'>$scriptsJsonEscaped</textarea>
                                <p class="text-xs text-gray-500 mt-2">Erlaubte Bereiche: description_convention, profiles. Templates werden in data/automation/automation.json gepflegt.</p>
                            </div>
                        </div>
                    </details>

                    <div class="pb-2 flex justify-end items-center gap-4 scripts-section-switch">
                        <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Inventar / JSON speichern">
                    </div>
                </form>

                <form action="?set=automation_run_scheduler" method="post" class="pb-2 flex justify-end items-center scripts-section-switch">
                    <input type="hidden" name="csrf" value="$csrf">
                    <input type="hidden" class="scripts-active-tab-input" name="scripts_active_tab" value="$activeScriptsTab">
                    <button class="bg-slate-700 hover:bg-slate-800 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit">Scheduler jetzt ausfuehren</button>
                </form>

            <script>
                const automationCsrfToken = '$csrf';
                const profileSnmpDefaults = $profileSnmpDefaultsJson;
                const initialScriptsTab = '$activeScriptsTab';
                let currentScriptsTab = initialScriptsTab;

                function postAutomationAction(action, payload) {
                    const form = document.createElement('form');
                    form.method = 'post';
                    form.action = '?set=' + encodeURIComponent(action);

                    const fields = Object.assign({
                        csrf: automationCsrfToken,
                        scripts_active_tab: currentScriptsTab
                    }, payload || {});
                    Object.keys(fields).forEach(function(key) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = key;
                        input.value = fields[key];
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                }

                function loadInventoryEntry(button) {
                    document.getElementById('switch_original_name').value = button.dataset.switchName || '';
                    document.getElementById('switch_name').value = button.dataset.switchName || '';
                    document.getElementById('switch_mgmt_ip').value = button.dataset.switchMgmtIp || '';
                    document.getElementById('switch_profile').value = button.dataset.switchProfile || '';
                    document.getElementById('switch_device_id').value = button.dataset.switchDeviceId || '';
                    var igEl = document.getElementById('switch_item_group_id');
                    if (igEl) { igEl.value = button.dataset.switchItemGroupId || ''; }
                    document.getElementById('switch_credential_mode').value = button.dataset.switchCredentialMode || 'global';
                    document.getElementById('switch_auth_method').value = button.dataset.switchAuthMethod || 'password';
                    document.getElementById('switch_ssh_username').value = button.dataset.switchSshUsername || '';
                    document.getElementById('switch_ssh_password').value = '';
                    document.getElementById('switch_ssh_private_key').value = '';
                    document.getElementById('switch_snmp_enabled').checked = (button.dataset.switchSnmpEnabled || '0') === '1';
                    document.getElementById('switch_snmp_version').value = button.dataset.switchSnmpVersion || '2c';
                    document.getElementById('switch_snmp_community').value = button.dataset.switchSnmpCommunity || '';
                    document.getElementById('switch_snmp_mib').value = button.dataset.switchSnmpMib || '';
                    document.getElementById('switch_snmp_v3_username').value = button.dataset.switchSnmpV3Username || '';
                    document.getElementById('switch_snmp_v3_auth_protocol').value = button.dataset.switchSnmpV3AuthProtocol || 'SHA';
                    document.getElementById('switch_snmp_v3_auth_passphrase').value = button.dataset.switchSnmpV3AuthPassphrase || '';
                    document.getElementById('switch_snmp_v3_priv_protocol').value = button.dataset.switchSnmpV3PrivProtocol || 'AES';
                    document.getElementById('switch_snmp_v3_priv_passphrase').value = button.dataset.switchSnmpV3PrivPassphrase || '';

                    var submitButton = document.getElementById('inventory_submit_button');
                    if (submitButton) {
                        submitButton.textContent = 'Switch aktualisieren';
                        submitButton.className = 'bg-amber-500 hover:bg-amber-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline';
                    }

                    syncCredentialInputs();
                    syncSwitchSnmpFromProfile();
                }

                function resetInventoryForm() {
                    document.getElementById('switch_original_name').value = '';
                    document.getElementById('switch_name').value = '';
                    document.getElementById('switch_mgmt_ip').value = '';
                    document.getElementById('switch_profile').selectedIndex = 0;
                    document.getElementById('switch_device_id').value = '';
                    var igEl2 = document.getElementById('switch_item_group_id');
                    if (igEl2) { igEl2.value = ''; }
                    document.getElementById('switch_credential_mode').value = 'global';
                    document.getElementById('switch_auth_method').value = 'password';
                    document.getElementById('switch_ssh_username').value = '';
                    document.getElementById('switch_ssh_password').value = '';
                    document.getElementById('switch_ssh_private_key').value = '';
                    document.getElementById('switch_snmp_enabled').checked = false;
                    document.getElementById('switch_snmp_version').value = '2c';
                    document.getElementById('switch_snmp_community').value = '';
                    document.getElementById('switch_snmp_mib').value = '';
                    document.getElementById('switch_snmp_v3_username').value = '';
                    document.getElementById('switch_snmp_v3_auth_protocol').value = 'SHA';
                    document.getElementById('switch_snmp_v3_auth_passphrase').value = '';
                    document.getElementById('switch_snmp_v3_priv_protocol').value = 'AES';
                    document.getElementById('switch_snmp_v3_priv_passphrase').value = '';

                    var submitButton = document.getElementById('inventory_submit_button');
                    if (submitButton) {
                        submitButton.textContent = 'Switch hinzufuegen';
                        submitButton.className = 'bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline';
                    }

                    syncCredentialInputs();
                    syncSwitchSnmpFromProfile();
                }

                function syncCredentialInputs() {
                    const mode = document.getElementById('switch_credential_mode').value;
                    const disabled = mode !== 'individual';

                    ['switch_auth_method', 'switch_ssh_username', 'switch_ssh_password', 'switch_ssh_private_key'].forEach(function(id) {
                        const el = document.getElementById(id);
                        if (!el) {
                            return;
                        }
                        el.disabled = disabled;
                        if (disabled && (id === 'switch_ssh_password' || id === 'switch_ssh_private_key')) {
                            el.value = '';
                        }
                    });
                }

                function submitInventorySave() {
                    const name = document.getElementById('switch_name').value.trim();
                    const mgmtIp = document.getElementById('switch_mgmt_ip').value.trim();
                    const profile = document.getElementById('switch_profile').value.trim();
                    const deviceId = document.getElementById('switch_device_id').value.trim();
                    const itemGroupIdEl = document.getElementById('switch_item_group_id');
                    const itemGroupId = itemGroupIdEl ? itemGroupIdEl.value.trim() : '';
                    const credentialMode = document.getElementById('switch_credential_mode').value;
                    const authMethod = document.getElementById('switch_auth_method').value;
                    const switchUsername = document.getElementById('switch_ssh_username').value.trim();
                    const switchPassword = document.getElementById('switch_ssh_password').value;
                    const switchPrivateKey = document.getElementById('switch_ssh_private_key').value;
                    const snmpEnabled = document.getElementById('switch_snmp_enabled').checked ? '1' : '0';
                    const snmpVersion = document.getElementById('switch_snmp_version').value;
                    const snmpCommunity = document.getElementById('switch_snmp_community').value.trim();
                    const snmpMib = document.getElementById('switch_snmp_mib').value.trim();
                    const snmpV3Username = document.getElementById('switch_snmp_v3_username').value.trim();
                    const snmpV3AuthProtocol = document.getElementById('switch_snmp_v3_auth_protocol').value;
                    const snmpV3AuthPassphrase = document.getElementById('switch_snmp_v3_auth_passphrase').value;
                    const snmpV3PrivProtocol = document.getElementById('switch_snmp_v3_priv_protocol').value;
                    const snmpV3PrivPassphrase = document.getElementById('switch_snmp_v3_priv_passphrase').value;
                    const originalName = document.getElementById('switch_original_name').value.trim();
                    const action = originalName !== '' ? 'automation_inventory_update' : 'automation_inventory_add';

                    if (name === '' || mgmtIp === '' || profile === '') {
                        alert('Bitte Name, Mgmt IP und Profil ausfuellen.');
                        return;
                    }

                    postAutomationAction(action, {
                        original_switch_name: originalName,
                        switch_name: name,
                        switch_mgmt_ip: mgmtIp,
                        switch_profile: profile,
                        switch_device_id: deviceId,
                        switch_item_group_id: itemGroupId,
                        switch_credential_mode: credentialMode,
                        switch_auth_method: authMethod,
                        switch_ssh_username: switchUsername,
                        switch_ssh_password: switchPassword,
                        switch_ssh_private_key: switchPrivateKey,
                        switch_snmp_enabled: snmpEnabled,
                        switch_snmp_version: snmpVersion,
                        switch_snmp_community: snmpCommunity,
                        switch_snmp_mib: snmpMib,
                        switch_snmp_v3_username: snmpV3Username,
                        switch_snmp_v3_auth_protocol: snmpV3AuthProtocol,
                        switch_snmp_v3_auth_passphrase: snmpV3AuthPassphrase,
                        switch_snmp_v3_priv_protocol: snmpV3PrivProtocol,
                        switch_snmp_v3_priv_passphrase: snmpV3PrivPassphrase
                    });
                }

                function submitAutomationSettingsSave() {
                    postAutomationAction('automation_settings_save', {
                        ssh_host: document.getElementById('ssh_host').value.trim(),
                        ssh_port: document.getElementById('ssh_port').value,
                        ssh_auth_method: document.getElementById('ssh_auth_method').value,
                        ssh_username: document.getElementById('ssh_username').value.trim(),
                        ssh_password: document.getElementById('ssh_password').value,
                        ssh_private_key: document.getElementById('ssh_private_key').value
                    });
                }

                function syncSwitchSnmpFromProfile() {
                    const profileId = document.getElementById('switch_profile').value || '';
                    const defaults = profileSnmpDefaults[profileId] || null;
                    if (!defaults) {
                        return;
                    }

                    const snmpVersionEl = document.getElementById('switch_snmp_version');
                    const snmpCommunityEl = document.getElementById('switch_snmp_community');
                    const snmpMibEl = document.getElementById('switch_snmp_mib');
                    const snmpV3UsernameEl = document.getElementById('switch_snmp_v3_username');
                    const snmpV3AuthProtocolEl = document.getElementById('switch_snmp_v3_auth_protocol');
                    const snmpV3AuthPassphraseEl = document.getElementById('switch_snmp_v3_auth_passphrase');
                    const snmpV3PrivProtocolEl = document.getElementById('switch_snmp_v3_priv_protocol');
                    const snmpV3PrivPassphraseEl = document.getElementById('switch_snmp_v3_priv_passphrase');
                    if (!snmpVersionEl || !snmpCommunityEl || !snmpMibEl || !snmpV3UsernameEl || !snmpV3AuthProtocolEl || !snmpV3AuthPassphraseEl || !snmpV3PrivProtocolEl || !snmpV3PrivPassphraseEl) {
                        return;
                    }

                    if (!snmpVersionEl.value && defaults.version) {
                        snmpVersionEl.value = defaults.version;
                    }
                    if (snmpCommunityEl.value.trim() === '' && defaults.community) {
                        snmpCommunityEl.value = defaults.community;
                    }
                    if (snmpMibEl.value.trim() === '' && defaults.default_mib) {
                        snmpMibEl.value = defaults.default_mib;
                    }
                    if (snmpV3UsernameEl.value.trim() === '' && defaults.v3_username) {
                        snmpV3UsernameEl.value = defaults.v3_username;
                    }
                    if (snmpV3AuthPassphraseEl.value === '' && defaults.v3_auth_passphrase) {
                        snmpV3AuthPassphraseEl.value = defaults.v3_auth_passphrase;
                    }
                    if (snmpV3PrivPassphraseEl.value === '' && defaults.v3_priv_passphrase) {
                        snmpV3PrivPassphraseEl.value = defaults.v3_priv_passphrase;
                    }
                    if (defaults.v3_auth_protocol) {
                        snmpV3AuthProtocolEl.value = defaults.v3_auth_protocol;
                    }
                    if (defaults.v3_priv_protocol) {
                        snmpV3PrivProtocolEl.value = defaults.v3_priv_protocol;
                    }
                }

                function submitInventoryDelete(index) {
                    if (!confirm('Switch-Eintrag wirklich loeschen?')) {
                        return;
                    }
                    postAutomationAction('automation_inventory_delete', {
                        inventory_index: String(index)
                    });
                }

                function submitInventorySnmpScan(button) {
                    const name = button.dataset.switchName || '';
                    if (name === '') {
                        alert('Switch-Name fuer SNMP-Scan konnte nicht gelesen werden.');
                        return;
                    }
                    if (!confirm('SNMP-Scan fuer ' + name + ' jetzt ausfuehren?')) {
                        return;
                    }
                    postAutomationAction('automation_snmp_scan', {
                        snmp_switch_name: name,
                        scripts_json: document.getElementById('scripts_json').value,
                        switch_inventory_json: document.getElementById('switch_inventory_json').value
                    });
                }

                function submitInventorySnmpScanAll() {
                    if (!confirm('SNMP-Scan fuer ALLE Switches ausfuehren?')) {
                        return;
                    }
                    postAutomationAction('automation_snmp_scan_all', {
                        scripts_json: document.getElementById('scripts_json').value,
                        switch_inventory_json: document.getElementById('switch_inventory_json').value
                    });
                }

                function submitInventorySshTest(button) {
                    const name = button.dataset.switchName || '';
                    const mgmtIp = button.dataset.switchMgmtIp || '';
                    const credentialMode = button.dataset.switchCredentialMode || 'global';
                    const switchUsername = button.dataset.switchSshUsername || '';
                    const switchAuthMethod = button.dataset.switchAuthMethod || 'password';

                    if (name === '' || mgmtIp === '') {
                        alert('Switch-Daten fuer den SSH-Test konnten nicht gelesen werden.');
                        return;
                    }

                    if (!confirm('SSH-Verbindung fuer ' + name + ' (' + mgmtIp + ') testen?')) {
                        return;
                    }

                    postAutomationAction('automation_test_ssh', {
                        ssh_host: mgmtIp,
                        ssh_auth_method: credentialMode === 'individual' ? switchAuthMethod : document.getElementById('ssh_auth_method').value,
                        ssh_username: credentialMode === 'individual' ? switchUsername : document.getElementById('ssh_username').value,
                        ssh_port: document.getElementById('ssh_port').value,
                        ssh_password: document.getElementById('ssh_password').value,
                        ssh_private_key: document.getElementById('ssh_private_key').value,
                        scripts_json: document.getElementById('scripts_json').value,
                        switch_inventory_json: document.getElementById('switch_inventory_json').value
                    });
                }

                function submitInventorySnmpTest(button) {
                    const name = button.dataset.switchName || '';
                    const mgmtIp = button.dataset.switchMgmtIp || '';

                    if (name === '' || mgmtIp === '') {
                        alert('Switch-Daten fuer den SNMP-Test konnten nicht gelesen werden.');
                        return;
                    }

                    if (!confirm('SNMP-Verbindung fuer ' + name + ' (' + mgmtIp + ') testen?')) {
                        return;
                    }

                    postAutomationAction('automation_test_snmp', {
                        snmp_switch_name: name,
                        snmp_host: mgmtIp,
                        // Let backend resolve effective SNMP values from inventory + profile.
                        snmp_version: '',
                        snmp_community: '',
                        snmp_mib: '',
                        snmp_port: '',
                        snmp_timeout: '',
                        snmp_retries: '',
                        snmp_v3_username: '',
                        snmp_v3_auth_protocol: '',
                        snmp_v3_priv_protocol: '',
                        scripts_json: document.getElementById('scripts_json').value,
                        switch_inventory_json: document.getElementById('switch_inventory_json').value
                    });
                }

                function submitCurrentSnmpTest() {
                    const name = document.getElementById('switch_name').value.trim() || 'aktueller Switch';
                    const mgmtIp = document.getElementById('switch_mgmt_ip').value.trim();

                    if (mgmtIp === '') {
                        alert('Bitte zuerst eine Mgmt IP eintragen.');
                        return;
                    }

                    if (!confirm('SNMP-Verbindung fuer ' + name + ' (' + mgmtIp + ') testen?')) {
                        return;
                    }

                    postAutomationAction('automation_test_snmp', {
                        snmp_host: mgmtIp,
                        snmp_version: document.getElementById('switch_snmp_version').value,
                        snmp_community: document.getElementById('switch_snmp_community').value.trim(),
                        snmp_mib: document.getElementById('switch_snmp_mib').value.trim(),
                        snmp_port: '161',
                        snmp_timeout: '2',
                        snmp_retries: '1',
                        snmp_v3_username: document.getElementById('switch_snmp_v3_username').value.trim(),
                        snmp_v3_auth_protocol: document.getElementById('switch_snmp_v3_auth_protocol').value,
                        snmp_v3_auth_passphrase: document.getElementById('switch_snmp_v3_auth_passphrase').value,
                        snmp_v3_priv_protocol: document.getElementById('switch_snmp_v3_priv_protocol').value,
                        snmp_v3_priv_passphrase: document.getElementById('switch_snmp_v3_priv_passphrase').value,
                        scripts_json: document.getElementById('scripts_json').value,
                        switch_inventory_json: document.getElementById('switch_inventory_json').value
                    });
                }

                function loadTemplateOverride(button) {
                    document.getElementById('template_id').value = button.dataset.templateId || '';
                    document.getElementById('template_label').value = button.dataset.templateLabel || '';
                    document.getElementById('template_description').value = button.dataset.templateDescription || '';
                    document.getElementById('template_supported_profiles').value = button.dataset.templateProfiles || '';
                    document.getElementById('template_commands').value = button.dataset.templateCommands || '';
                    document.getElementById('template_uses_description_convention').checked = (button.dataset.templateUsesConvention === '1' || button.dataset.templateUsesConvention === 'true');
                    document.getElementById('template_id').focus();
                }

                function resetTemplateOverrideForm() {
                    document.getElementById('template_id').value = '';
                    document.getElementById('template_label').value = '';
                    document.getElementById('template_description').value = '';
                    document.getElementById('template_supported_profiles').value = '';
                    document.getElementById('template_commands').value = '';
                    document.getElementById('template_uses_description_convention').checked = false;
                }

                function submitTemplateUpsert() {
                    const templateId = document.getElementById('template_id').value.trim();
                    const templateLabel = document.getElementById('template_label').value.trim();
                    const templateDescription = document.getElementById('template_description').value.trim();
                    const templateProfiles = document.getElementById('template_supported_profiles').value.trim();
                    const templateCommands = document.getElementById('template_commands').value;
                    const usesDescriptionConvention = document.getElementById('template_uses_description_convention').checked ? '1' : '0';

                    if (templateId === '' || templateLabel === '' || templateCommands.trim() === '') {
                        alert('Template ID, Label und mindestens ein Command sind erforderlich.');
                        return;
                    }

                    postAutomationAction('automation_template_upsert', {
                        template_id: templateId,
                        template_label: templateLabel,
                        template_description: templateDescription,
                        template_supported_profiles: templateProfiles,
                        template_commands: templateCommands,
                        template_uses_description_convention: usesDescriptionConvention
                    });
                }

                function submitTemplateDelete(templateId) {
                    if (!confirm('Template wirklich loeschen?')) {
                        return;
                    }
                    postAutomationAction('automation_template_delete', {
                        template_id: templateId
                    });
                }

                function loadProfileDefinition(button) {
                    document.getElementById('profile_id').value = button.dataset.profileId || '';
                    document.getElementById('profile_label').value = button.dataset.profileLabel || '';
                    document.getElementById('profile_description').value = button.dataset.profileDescription || '';
                    document.getElementById('profile_enter_config').value = button.dataset.profileEnterConfig || '';
                    document.getElementById('profile_commit').value = button.dataset.profileCommit || '';
                    document.getElementById('profile_exit_config').value = button.dataset.profileExitConfig || '';
                    document.getElementById('profile_save').value = button.dataset.profileSave || '';
                    document.getElementById('profile_write_config').value = button.dataset.profileWriteConfig || '';
                    document.getElementById('profile_supports_commit').checked = (button.dataset.profileSupportsCommit || '0') === '1';
                    document.getElementById('profile_snmp_enabled').checked = (button.dataset.profileSnmpEnabled || '0') === '1';
                    document.getElementById('profile_snmp_version').value = button.dataset.profileSnmpVersion || '2c';
                    document.getElementById('profile_snmp_port').value = button.dataset.profileSnmpPort || '161';
                    document.getElementById('profile_snmp_timeout').value = button.dataset.profileSnmpTimeout || '2';
                    document.getElementById('profile_snmp_retries').value = button.dataset.profileSnmpRetries || '1';
                    document.getElementById('profile_snmp_community').value = button.dataset.profileSnmpCommunity || '';
                    document.getElementById('profile_snmp_v3_username').value = button.dataset.profileSnmpV3Username || '';
                    document.getElementById('profile_snmp_v3_auth_protocol').value = button.dataset.profileSnmpV3AuthProtocol || 'SHA';
                    document.getElementById('profile_snmp_v3_auth_passphrase').value = button.dataset.profileSnmpV3AuthPassphrase || '';
                    document.getElementById('profile_snmp_v3_priv_protocol').value = button.dataset.profileSnmpV3PrivProtocol || 'AES';
                    document.getElementById('profile_snmp_v3_priv_passphrase').value = button.dataset.profileSnmpV3PrivPassphrase || '';
                    document.getElementById('profile_snmp_default_mib').value = button.dataset.profileSnmpDefaultMib || '';
                    document.getElementById('profile_snmp_mib_overrides').value = button.dataset.profileSnmpMibOverrides || '';
                    document.getElementById('profile_id').focus();
                }

                function resetProfileDefinitionForm() {
                    document.getElementById('profile_id').value = '';
                    document.getElementById('profile_label').value = '';
                    document.getElementById('profile_description').value = '';
                    document.getElementById('profile_enter_config').value = '';
                    document.getElementById('profile_commit').value = '';
                    document.getElementById('profile_exit_config').value = '';
                    document.getElementById('profile_save').value = '';
                    document.getElementById('profile_write_config').value = '';
                    document.getElementById('profile_supports_commit').checked = false;
                    document.getElementById('profile_snmp_enabled').checked = false;
                    document.getElementById('profile_snmp_version').value = '2c';
                    document.getElementById('profile_snmp_port').value = '161';
                    document.getElementById('profile_snmp_timeout').value = '2';
                    document.getElementById('profile_snmp_retries').value = '1';
                    document.getElementById('profile_snmp_community').value = '';
                    document.getElementById('profile_snmp_v3_username').value = '';
                    document.getElementById('profile_snmp_v3_auth_protocol').value = 'SHA';
                    document.getElementById('profile_snmp_v3_auth_passphrase').value = '';
                    document.getElementById('profile_snmp_v3_priv_protocol').value = 'AES';
                    document.getElementById('profile_snmp_v3_priv_passphrase').value = '';
                    document.getElementById('profile_snmp_default_mib').value = '';
                    document.getElementById('profile_snmp_mib_overrides').value = '';
                }

                function submitProfileUpsert() {
                    const profileId = document.getElementById('profile_id').value.trim();
                    const profileLabel = document.getElementById('profile_label').value.trim();

                    if (profileId === '' || profileLabel === '') {
                        alert('Profile ID und Label sind erforderlich.');
                        return;
                    }

                    postAutomationAction('automation_profile_upsert', {
                        profile_id: profileId,
                        profile_label: profileLabel,
                        profile_description: document.getElementById('profile_description').value.trim(),
                        profile_enter_config: document.getElementById('profile_enter_config').value.trim(),
                        profile_commit: document.getElementById('profile_commit').value.trim(),
                        profile_exit_config: document.getElementById('profile_exit_config').value.trim(),
                        profile_save: document.getElementById('profile_save').value.trim(),
                        profile_write_config: document.getElementById('profile_write_config').value.trim(),
                        profile_supports_commit: document.getElementById('profile_supports_commit').checked ? '1' : '0',
                        profile_snmp_enabled: document.getElementById('profile_snmp_enabled').checked ? '1' : '0',
                        profile_snmp_version: document.getElementById('profile_snmp_version').value,
                        profile_snmp_port: document.getElementById('profile_snmp_port').value,
                        profile_snmp_timeout: document.getElementById('profile_snmp_timeout').value,
                        profile_snmp_retries: document.getElementById('profile_snmp_retries').value,
                        profile_snmp_community: document.getElementById('profile_snmp_community').value.trim(),
                        profile_snmp_v3_username: document.getElementById('profile_snmp_v3_username').value.trim(),
                        profile_snmp_v3_auth_protocol: document.getElementById('profile_snmp_v3_auth_protocol').value,
                        profile_snmp_v3_auth_passphrase: document.getElementById('profile_snmp_v3_auth_passphrase').value,
                        profile_snmp_v3_priv_protocol: document.getElementById('profile_snmp_v3_priv_protocol').value,
                        profile_snmp_v3_priv_passphrase: document.getElementById('profile_snmp_v3_priv_passphrase').value,
                        profile_snmp_default_mib: document.getElementById('profile_snmp_default_mib').value.trim(),
                        profile_snmp_mib_overrides: document.getElementById('profile_snmp_mib_overrides').value
                    });
                }

                function submitProfileDelete(profileId) {
                    if (!confirm('Profil wirklich loeschen?')) {
                        return;
                    }
                    postAutomationAction('automation_profile_delete', {
                        profile_id: profileId
                    });
                }

                function filterHistoryRows() {
                    var input = document.getElementById('history_filter');
                    var query = input ? input.value.trim().toLowerCase() : '';

                    document.querySelectorAll('.history-row').forEach(function(row) {
                        var searchText = (row.getAttribute('data-history-search') || '').toLowerCase();
                        var visible = query === '' || searchText.indexOf(query) !== -1;
                        row.classList.toggle('hidden', !visible);
                    });
                }

                function showScriptsTab(tabId) {
                    const resolvedTab = (tabId === 'profiles' || tabId === 'templates' || tabId === 'history') ? tabId : 'switch';
                    currentScriptsTab = resolvedTab;

                    document.querySelectorAll('.scripts-active-tab-input').forEach(function(input) {
                        input.value = resolvedTab;
                    });

                    const showSwitch = (resolvedTab === 'switch');
                    const showProfiles = (resolvedTab === 'profiles');
                    const showTemplates = (resolvedTab === 'templates');
                    const showHistory = (resolvedTab === 'history');

                    document.querySelectorAll('.scripts-section-switch').forEach(function(el) {
                        el.classList.toggle('hidden', !showSwitch);
                    });
                    document.querySelectorAll('.scripts-section-template').forEach(function(el) {
                        el.classList.toggle('hidden', !showTemplates);
                    });
                    document.querySelectorAll('.scripts-section-profile').forEach(function(el) {
                        el.classList.toggle('hidden', !showProfiles);
                    });
                    document.querySelectorAll('.scripts-section-history').forEach(function(el) {
                        el.classList.toggle('hidden', !showHistory);
                    });

                    document.querySelectorAll('.settings-script-tab').forEach(function(item) {
                        const itemTab = item.getAttribute('data-script-tab');
                        item.classList.toggle('settings-nav-subitem-active', itemTab === resolvedTab);
                    });
                }

                showScriptsTab(initialScriptsTab);
                var credentialModeEl = document.getElementById('switch_credential_mode');
                if (credentialModeEl) {
                    credentialModeEl.addEventListener('change', syncCredentialInputs);
                }
                var profileEl = document.getElementById('switch_profile');
                if (profileEl) {
                    profileEl.addEventListener('change', syncSwitchSnmpFromProfile);
                }
                syncCredentialInputs();
                syncSwitchSnmpFromProfile();
            </script>
        </div>
        HTML;
        break; 
    case 'appearance':
    default:
        $csrf = $auth->csrf();
        $userSettings = getSessionUserSettings();
        $currentLanguage = (string)($userSettings['language'] ?? 'de-DE');
        $currentTheme = (string)($userSettings['appearance']['theme'] ?? 'light');
        $currentFontFamily = (string)($userSettings['appearance']['font_family'] ?? 'jetbrains');
        $currentFontSize = (string)($userSettings['appearance']['font_size'] ?? 'normal');

        $langDeSelected = $currentLanguage === 'de-DE' ? 'selected' : '';
        $langEnSelected = ($currentLanguage === 'en-EN' || $currentLanguage === 'en-US') ? 'selected' : '';

        $themeLightSelected = $currentTheme === 'light' ? 'selected' : '';
        $themeDarkSelected = $currentTheme === 'dark' ? 'selected' : '';
        $themeContrastSelected = $currentTheme === 'contrast' ? 'selected' : '';

        $fontJetbrainsSelected = $currentFontFamily === 'jetbrains' ? 'selected' : '';
        $fontSourceSelected = $currentFontFamily === 'source_sans' ? 'selected' : '';
        $fontFiraSelected = $currentFontFamily === 'fira_sans' ? 'selected' : '';

        $sizeSmallSelected = $currentFontSize === 'small' ? 'selected' : '';
        $sizeNormalSelected = $currentFontSize === 'normal' ? 'selected' : '';
        $sizeLargeSelected = $currentFontSize === 'large' ? 'selected' : '';

        echo <<<HTML
        <div class="h-fit w-full p-4">
            <div class="text-xl font-bold pb-2">Darstellung</div>
            <p class="text-sm text-gray-600 pb-6">Farbschema, Schriftart und Schriftgroesse werden in deinem Nutzerprofil gespeichert.</p>

            <form action="?set=appearance_preferences" method="post" class="space-y-5">
                <input type="hidden" name="csrf" value="$csrf">

                <div class="pb-6">
                    <label class="block mb-2 text-sm font-semibold" for="language">
                        Sprache
                    </label>
                    <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="language" type="text" name="language">
                        <option value="de-DE" $langDeSelected>Deutsch</option>
                        <option value="en-EN" $langEnSelected>English</option>
                    </select>
                </div>

                <div>
                    <label class="block mb-2 text-sm font-semibold" for="theme">Farbschema</label>
                    <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="theme" name="theme">
                        <option value="light" $themeLightSelected>Lightmode</option>
                        <option value="dark" $themeDarkSelected>Darkmode</option>
                        <option value="contrast" $themeContrastSelected>Kontrastmodus</option>
                    </select>
                </div>

                <div>
                    <label class="block mb-2 text-sm font-semibold" for="font_family">Schriftart</label>
                    <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="font_family" name="font_family">
                        <option value="jetbrains" $fontJetbrainsSelected>JetBrains Mono</option>
                        <option value="source_sans" $fontSourceSelected>Source Sans 3</option>
                        <option value="fira_sans" $fontFiraSelected>Fira Sans</option>
                    </select>
                </div>

                <div>
                    <label class="block mb-2 text-sm font-semibold" for="font_size">Schriftgroesse</label>
                    <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="font_size" name="font_size">
                        <option value="small" $sizeSmallSelected>Kompakt</option>
                        <option value="normal" $sizeNormalSelected>Standard</option>
                        <option value="large" $sizeLargeSelected>Gross</option>
                    </select>
                </div>

                <div class="pb-6 flex justify-between items-center">
                    <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Darstellung speichern">
                </div>
            </form>
        </div>
        HTML;
        break;
    case 'changelog':
        if ($role !== 'admin') {
            $logger->log('user is not admin', 2, echoToWeb: true);
            header('Location: ?site=appearance');
            die();
        }

        $filterTable = trim((string)($_GET['filter_table'] ?? ''));
        $filterOperation = strtoupper(trim((string)($_GET['filter_operation'] ?? '')));
        $filterUser = trim((string)($_GET['filter_user'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 200);
        if ($limit < 50) {
            $limit = 50;
        }
        if ($limit > 1000) {
            $limit = 1000;
        }

        $conditions = [];
        $params = ['limit' => $limit];

        if ($filterTable !== '') {
            $conditions[] = 'c.changed_table ILIKE :filter_table';
            $params['filter_table'] = $filterTable;
        }

        if (in_array($filterOperation, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            $conditions[] = 'c.operation = :filter_operation';
            $params['filter_operation'] = $filterOperation;
        }

        if ($filterUser !== '') {
            $conditions[] = '(u.username ILIKE :filter_user OR c.users::text ILIKE :filter_user)';
            $params['filter_user'] = '%' . $filterUser . '%';
        }

        $whereClause = '';
        if (!empty($conditions)) {
            $whereClause = 'WHERE ' . implode(' AND ', $conditions);
        }

        $query = "SELECT c.uuid,
                         c.operation,
                         c.changed_table,
                         c.changed_row,
                         c.changed_data,
                         c.users,
                         u.username,
                         TO_CHAR(c.changed, 'YYYY-MM-DD HH24:MI:SS') AS changed_at
                  FROM changelog c
                  LEFT JOIN users u ON u.uuid = c.users
                  $whereClause
                  ORDER BY c.changed DESC
                  LIMIT :limit";

        $changelogRows = $db_adapter->db_query($query, $params) ?: [];

        $filterTableEscaped = htmlspecialchars($filterTable, ENT_QUOTES, 'UTF-8');
        $filterOperationEscaped = htmlspecialchars($filterOperation, ENT_QUOTES, 'UTF-8');
        $filterUserEscaped = htmlspecialchars($filterUser, ENT_QUOTES, 'UTF-8');
        $limitEscaped = htmlspecialchars((string)$limit, ENT_QUOTES, 'UTF-8');

        echo "<div class='h-fit w-full p-4'>";
        echo "<div class='text-2xl font-bold pb-2'>Changelog</div>";
        echo "<p class='text-sm text-gray-600 pb-6'>Nachvollziehbarkeit von Nutzer- und API-Aenderungen (INSERT/UPDATE/DELETE).</p>";

        echo "<form method='GET' class='bg-gray-50 border border-gray-200 rounded-xl p-4 mb-4 grid grid-cols-1 md:grid-cols-5 gap-3'>";
        echo "<input type='hidden' name='site' value='changelog'>";
        echo "<div><label class='block text-xs text-gray-600 mb-1'>Table</label><input class='w-full border rounded-full px-3 py-2' type='text' name='filter_table' value='{$filterTableEscaped}' placeholder='z. B. device_port'></div>";
        echo "<div><label class='block text-xs text-gray-600 mb-1'>Operation</label><select class='w-full border rounded-full px-3 py-2' name='filter_operation'>";
        echo "<option value=''" . ($filterOperationEscaped === '' ? ' selected' : '') . ">Alle</option>";
        echo "<option value='INSERT'" . ($filterOperationEscaped === 'INSERT' ? ' selected' : '') . ">INSERT</option>";
        echo "<option value='UPDATE'" . ($filterOperationEscaped === 'UPDATE' ? ' selected' : '') . ">UPDATE</option>";
        echo "<option value='DELETE'" . ($filterOperationEscaped === 'DELETE' ? ' selected' : '') . ">DELETE</option>";
        echo "</select></div>";
        echo "<div><label class='block text-xs text-gray-600 mb-1'>User</label><input class='w-full border rounded-full px-3 py-2' type='text' name='filter_user' value='{$filterUserEscaped}' placeholder='Username oder UUID'></div>";
        echo "<div><label class='block text-xs text-gray-600 mb-1'>Limit</label><input class='w-full border rounded-full px-3 py-2' type='number' min='50' max='1000' step='50' name='limit' value='{$limitEscaped}'></div>";
        echo "<div class='flex items-end gap-2'><button class='bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full' type='submit'>Filtern</button><a class='bg-gray-300 hover:bg-gray-400 text-gray-900 font-bold py-2 px-4 rounded-full' href='?site=changelog'>Reset</a></div>";
        echo "</form>";

        if (empty($changelogRows)) {
            echo "<div class='rounded-xl bg-blue-50 border border-blue-200 text-blue-900 px-4 py-3'>Keine Changelog-Eintraege fuer den gewaehlten Filter gefunden.</div>";
        } else {
            echo "<div class='max-h-[70vh] overflow-auto rounded-xl border border-gray-200'>";
            echo "<table class='w-full text-sm text-left text-gray-700'>";
            echo "<thead class='bg-gray-100 sticky top-0'><tr>";
            echo "<th class='p-2 border-b'>Zeit</th>";
            echo "<th class='p-2 border-b'>Operation</th>";
            echo "<th class='p-2 border-b'>Tabelle</th>";
            echo "<th class='p-2 border-b'>Changed Row</th>";
            echo "<th class='p-2 border-b'>User</th>";
            echo "<th class='p-2 border-b'>Data</th>";
            echo "<th class='p-2 border-b'>Action</th>";
            echo "</tr></thead><tbody>";

            foreach ($changelogRows as $row) {
                $uuidEscaped = htmlspecialchars((string)($row['uuid'] ?? ''), ENT_QUOTES, 'UTF-8');
                $changedAtEscaped = htmlspecialchars((string)($row['changed_at'] ?? ''), ENT_QUOTES, 'UTF-8');
                $operationEscaped = htmlspecialchars((string)($row['operation'] ?? ''), ENT_QUOTES, 'UTF-8');
                $tableEscaped = htmlspecialchars((string)($row['changed_table'] ?? ''), ENT_QUOTES, 'UTF-8');
                $changedRowEscaped = htmlspecialchars((string)($row['changed_row'] ?? ''), ENT_QUOTES, 'UTF-8');
                $username = (string)($row['username'] ?? '');
                $usersUuid = (string)($row['users'] ?? '');
                $userTextEscaped = htmlspecialchars($username !== '' ? $username : $usersUuid, ENT_QUOTES, 'UTF-8');
                $changedDataRaw = (string)($row['changed_data'] ?? '');

                $decoded = json_decode($changedDataRaw, true);
                if (is_array($decoded)) {
                    $hasDiff = isset($decoded['diff']) && is_array($decoded['diff']);
                    $isInsertOrDelete = in_array((string)($row['operation'] ?? ''), ['INSERT', 'DELETE'], true);
                    if ($hasDiff && $isInsertOrDelete) {
                        $diffBefore = $decoded['diff']['before'] ?? [];
                        $diffAfter = $decoded['diff']['after'] ?? [];
                        $beforeCount = is_array($diffBefore) ? count($diffBefore) : 0;
                        $afterCount = is_array($diffAfter) ? count($diffAfter) : 0;
                        $changedDataRaw = 'Diff verfuegbar (before=' . $beforeCount . ', after=' . $afterCount . ')';
                    } else {
                        $changedDataRaw = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                }
                if (!is_string($changedDataRaw)) {
                    $changedDataRaw = '';
                }
                if (strlen($changedDataRaw) > 800) {
                    $changedDataRaw = substr($changedDataRaw, 0, 800) . '...';
                }
                $changedDataEscaped = htmlspecialchars($changedDataRaw, ENT_QUOTES, 'UTF-8');

                echo "<tr class='hover:bg-gray-50 align-top'>";
                echo "<td class='p-2 border-b whitespace-nowrap'>{$changedAtEscaped}</td>";
                echo "<td class='p-2 border-b font-semibold'>{$operationEscaped}</td>";
                echo "<td class='p-2 border-b font-mono text-xs'>{$tableEscaped}</td>";
                echo "<td class='p-2 border-b font-mono text-xs'>{$changedRowEscaped}</td>";
                echo "<td class='p-2 border-b'>{$userTextEscaped}</td>";
                echo "<td class='p-2 border-b font-mono text-xs whitespace-pre-wrap break-all'>{$changedDataEscaped}</td>";
                echo "<td class='p-2 border-b whitespace-nowrap'>";
                echo "<button type='button' class='bg-amber-500 hover:bg-amber-700 text-white font-bold py-1 px-3 rounded-full text-xs' onclick=\"openChangelogDetailsPopup('{$uuidEscaped}')\">Details</button>";
                echo "</td>";
                echo "</tr>";
            }

            echo "</tbody></table></div>";
        }

        echo "<div id='changelogDetailsPopup' class='fixed inset-0 hidden z-50 bg-black/30'>";
        echo "<div class='bg-white rounded-xl shadow-xl max-w-5xl mx-auto mt-10 p-4 max-h-[85vh] overflow-y-auto'>";
        echo "<div class='flex items-center justify-between pb-3 border-b'>";
        echo "<div class='text-lg font-bold'>Changelog Details</div>";
        echo "<button type='button' class='h-9 w-9 rounded-full bg-red-500 hover:bg-red-700 text-white font-bold' onclick='closeChangelogDetailsPopup()'>X</button>";
        echo "</div>";
        echo "<div id='changelogDetailsMeta' class='pt-3 text-sm text-gray-700'></div>";
        echo "<div id='changelogDetailsDiff' class='mt-3 hidden'></div>";
        echo "<pre id='changelogDetailsPayload' class='mt-3 p-3 bg-gray-100 rounded text-xs font-mono whitespace-pre-wrap break-all'></pre>";
        echo "</div>";
        echo "</div>";

        echo "<script>\n"
            . "function escapeHtml(value){return String(value).replace(/[&<>\"']/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'})[c];});}\n"
            . "function closeChangelogDetailsPopup(){document.getElementById('changelogDetailsPopup').classList.add('hidden');}\n"
            . "function normalizeDiffValue(value){if(value===null||value===undefined){return '';}if(typeof value==='object'){try{return JSON.stringify(value);}catch(e){return '[object]';}}return String(value);}\n"
            . "function renderDiffTable(beforeObj, afterObj){var keys=[];Object.keys(beforeObj||{}).forEach(function(k){if(keys.indexOf(k)===-1){keys.push(k);}});Object.keys(afterObj||{}).forEach(function(k){if(keys.indexOf(k)===-1){keys.push(k);}});if(keys.length===0){return '<div class=\"text-xs text-gray-500\">Keine Diff-Daten verfuegbar.</div>';}\n"
            . "var rows='';keys.sort().forEach(function(key){var oldVal=normalizeDiffValue((beforeObj||{})[key]);var newVal=normalizeDiffValue((afterObj||{})[key]);var changed=(oldVal!==newVal);rows += '<tr class=\"'+(changed?'bg-amber-50':'')+'\"><td class=\"p-2 border-b font-mono text-xs\">'+escapeHtml(key)+'</td><td class=\"p-2 border-b font-mono text-xs whitespace-pre-wrap break-all\">'+escapeHtml(oldVal)+'</td><td class=\"p-2 border-b font-mono text-xs whitespace-pre-wrap break-all\">'+escapeHtml(newVal)+'</td></tr>';});\n"
            . "return '<div class=\"rounded border border-gray-200 overflow-auto\"><table class=\"w-full text-left\"><thead class=\"bg-gray-100\"><tr><th class=\"p-2 border-b text-xs\">Feld</th><th class=\"p-2 border-b text-xs\">Before</th><th class=\"p-2 border-b text-xs\">After</th></tr></thead><tbody>'+rows+'</tbody></table></div>';}\n"
            . "function openChangelogDetailsPopup(uuid){\n"
            . "  $.ajax({url:'?get=changelog_details&uuid='+encodeURIComponent(uuid),type:'GET',dataType:'json',success:function(response){\n"
            . "    if(!response){return;}\n"
            . "    var userText = response.username ? response.username : (response.users || '');\n"
            . "    var meta = ''\n"
            . "      + '<div><strong>Zeit:</strong> '+escapeHtml(response.changed_at || '')+'</div>'\n"
            . "      + '<div><strong>Operation:</strong> '+escapeHtml(response.operation || '')+'</div>'\n"
            . "      + '<div><strong>Tabelle:</strong> '+escapeHtml(response.changed_table || '')+'</div>'\n"
            . "      + '<div><strong>Changed Row:</strong> '+escapeHtml(response.changed_row || '')+'</div>'\n"
            . "      + '<div><strong>User:</strong> '+escapeHtml(userText)+'</div>'\n"
            . "      + '<div><strong>UUID:</strong> '+escapeHtml(response.uuid || '')+'</div>';\n"
            . "    document.getElementById('changelogDetailsMeta').innerHTML = meta;\n"
            . "    var payloadText = response.changed_data || '';\n"
            . "    var diffContainer = document.getElementById('changelogDetailsDiff');\n"
            . "    diffContainer.classList.add('hidden');\n"
            . "    diffContainer.innerHTML = '';\n"
            . "    try {\n"
            . "      var parsed = JSON.parse(payloadText);\n"
            . "      if(parsed && typeof parsed === 'object' && parsed.diff && typeof parsed.diff === 'object'){\n"
            . "        var beforeObj = parsed.diff.before && typeof parsed.diff.before === 'object' ? parsed.diff.before : {};\n"
            . "        var afterObj = parsed.diff.after && typeof parsed.diff.after === 'object' ? parsed.diff.after : {};\n"
            . "        var title = '<div class=\"text-sm font-semibold text-gray-800 mb-2\">Diff Ansicht</div>';\n"
            . "        diffContainer.innerHTML = title + renderDiffTable(beforeObj, afterObj);\n"
            . "        diffContainer.classList.remove('hidden');\n"
            . "      }\n"
            . "      payloadText = JSON.stringify(parsed, null, 2);\n"
            . "    } catch (e) {}\n"
            . "    document.getElementById('changelogDetailsPayload').textContent = payloadText;\n"
            . "    document.getElementById('changelogDetailsPopup').classList.remove('hidden');\n"
            . "  },error:function(){alert('Details konnten nicht geladen werden.');}});\n"
            . "}\n"
            . "</script>";

        echo "</div>";
        break;
};
?>
    </div>
</div>
<?php
    include_once 'includes/footer.php';
?>
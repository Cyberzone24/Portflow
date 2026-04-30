<?php

namespace Portflow\Modules\Snmp;

use Portflow\Core\AutomationStore;
use Portflow\Core\Logger;
use Portflow\Core\SnmpClient;
use Portflow\Core\SnmpScannerExtensionInterface;

class HuaweiScannerExtension implements SnmpScannerExtensionInterface
{
    private const OID_HUAWEI_VLAN_DESC = '.1.3.6.1.4.1.2011.5.25.42.1.4.1.1.5';
    private const OID_HUAWEI_VLAN_NAME = '.1.3.6.1.4.1.2011.5.25.42.1.4.1.1.4';
    private const OID_HW_POE_ENABLE = '.1.3.6.1.4.1.2011.5.25.195.4.1.1.2';
    private const OID_HW_POE_POWER_STATUS = '.1.3.6.1.4.1.2011.5.25.195.4.1.1.5';
    private const OID_HW_POE_PD_CLASS = '.1.3.6.1.4.1.2011.5.25.195.4.1.1.10';

    /** @var array<string,mixed> */
    private array $lastDiagnostics = [];

    public function getId(): string
    {
        return 'huawei';
    }

    public function supports(array $config, array $switch): bool
    {
        $extension = strtolower(trim((string)($config['snmp_extension'] ?? '')));
        if ($extension !== '') {
            return $extension === $this->getId();
        }

        $profileId = strtolower(trim((string)($config['profile_id'] ?? $switch['profile'] ?? '')));
        return str_contains($profileId, 'huawei');
    }

    public function collectVlanNames(SnmpClient $client, Logger $logger, array $config): ?array
    {
        $vlanNameMap = $this->walkMap($client, $config, self::OID_HUAWEI_VLAN_DESC);
        if (!empty($vlanNameMap)) {
            return [
                'map' => $vlanNameMap,
                'source' => 'HUAWEI-VLAN-MIB::hwL2VlanDescription',
            ];
        }

        $vlanNameMap = $this->walkMap($client, $config, self::OID_HUAWEI_VLAN_NAME);
        if (!empty($vlanNameMap)) {
            return [
                'map' => $vlanNameMap,
                'source' => 'HUAWEI-VLAN-MIB::hwVlanName',
            ];
        }

        return null;
    }

    public function collectPoeSnapshot(SnmpClient $client, Logger $logger, array $config): ?array
    {
        $hwEnable = $this->walkMap($client, $config, self::OID_HW_POE_ENABLE);
        if (empty($hwEnable)) {
            return null;
        }

        $hwStatus = $this->walkMap($client, $config, self::OID_HW_POE_POWER_STATUS);
        $hwClass = $this->walkMap($client, $config, self::OID_HW_POE_PD_CLASS);
        $admin = [];
        $detection = [];
        $class = [];
        foreach ($hwEnable as $ifIndex => $enable) {
            $key = (string)$ifIndex;
            $admin[$key] = (int)$enable;
            $detection[$key] = isset($hwStatus[$ifIndex]) ? (int)$hwStatus[$ifIndex] : null;
            $class[$key] = isset($hwClass[$ifIndex]) ? (int)$hwClass[$ifIndex] : null;
        }

        return [
            'admin' => $admin,
            'detection' => $detection,
            'class' => $class,
            'source' => 'HUAWEI-POE-MIB',
        ];
    }

    public function collectNodeIps(SnmpClient $client, Logger $logger, array $config): array
    {
        $mode = strtolower(trim((string)($config['node_ip_collection'] ?? '')));
        $this->lastDiagnostics = [
            'extension' => $this->getId(),
            'switch_name' => (string)($config['switch_name'] ?? ''),
            'node_ip_collection' => [
                'mode' => $mode,
                'status' => 'disabled',
            ],
        ];
        if (!in_array($mode, ['cli', 'cli-dhcp-snooping', 'cli-arp'], true)) {
            return [];
        }

        $this->lastDiagnostics['node_ip_collection']['status'] = 'starting';

        $connection = $this->resolveSshConnection($config);
        if ($connection === null) {
            $this->lastDiagnostics['node_ip_collection']['status'] = 'switch-not-found';
            $this->lastDiagnostics['node_ip_collection']['error'] = 'Switch konnte fuer CLI-Zugangsdaten nicht im Inventar gefunden werden.';
            $logger->log('HuaweiScannerExtension: no SSH connection data available for ' . (string)($config['switch_name'] ?? '?'), 2);
            return [];
        }

        $this->lastDiagnostics['node_ip_collection']['connection'] = $this->describeConnection($connection);

        $commands = [];
        if ($mode === 'cli' || $mode === 'cli-dhcp-snooping') {
            $commands[] = 'display dhcp snooping user-bind all';
        }
        if ($mode === 'cli' || $mode === 'cli-arp') {
            $commands[] = 'display arp all';
        }

        $result = $this->runReadOnlySshCommands($connection, $commands, $logger, (string)($config['switch_name'] ?? ''));
        $this->lastDiagnostics['node_ip_collection']['execution'] = $result['diagnostics'] ?? [];
        $source = $mode === 'cli-dhcp-snooping' ? 'cli:dhcp-snooping' : ($mode === 'cli-arp' ? 'cli:arp' : 'cli:huawei');
        $parsed = $this->parseNodeIpsFromCliOutput((string)($result['output'] ?? ''), $source);
        $this->lastDiagnostics['node_ip_collection']['parsed_count'] = count($parsed);
        $this->lastDiagnostics['node_ip_collection']['output_preview'] = $this->buildOutputPreview((string)($result['output'] ?? ''));

        if (!$result['ok']) {
            if (!empty($parsed)) {
                $this->lastDiagnostics['node_ip_collection']['status'] = 'ok-with-nonzero-exit';
                $this->lastDiagnostics['node_ip_collection']['warning'] = 'CLI-Output wurde trotz SSH-Exit-Code ' . (string)($result['diagnostics']['exit_code'] ?? '?') . ' erfolgreich geparst.';
                $logger->log(
                    'HuaweiScannerExtension: CLI node-IP collection for ' . (string)($config['switch_name'] ?? '?') . ' produced parsable output despite exit=' . (string)($result['diagnostics']['exit_code'] ?? '?'),
                    2
                );
                return $parsed;
            }

            $this->lastDiagnostics['node_ip_collection']['status'] = 'failed';
            $this->lastDiagnostics['node_ip_collection']['error'] = (string)($result['output'] ?? '');
            $logger->log('HuaweiScannerExtension: CLI node-IP collection failed for ' . (string)($config['switch_name'] ?? '?') . ': ' . (string)($result['output'] ?? ''), 2);
            return [];
        }

        $this->lastDiagnostics['node_ip_collection']['status'] = empty($parsed) ? 'parsed-empty' : 'ok';
        return $parsed;
    }

    public function getLastDiagnostics(): array
    {
        return $this->lastDiagnostics;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int|string,string>
     */
    private function walkMap(SnmpClient $client, array $config, string $baseOid): array
    {
        $result = $client->runWalk($config, $baseOid);
        if (!$result['ok']) {
            return [];
        }

        $parsed = SnmpClient::parseWalkLines($result['lines']);
        $map = [];
        foreach ($parsed as $oid => $value) {
            $lastDot = strrpos($oid, '.');
            if ($lastDot === false) {
                continue;
            }
            $index = substr($oid, $lastDot + 1);
            if ($index === '') {
                continue;
            }
            $map[$index] = $value;
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>|null
     */
    private function resolveSshConnection(array $config): ?array
    {
        $store = new AutomationStore();
        $settings = $store->getSettings();
        $inventory = json_decode((string)($settings['switch_inventory_json'] ?? '{}'), true);
        $switches = is_array($inventory['switches'] ?? null) ? $inventory['switches'] : [];

        $switchName = trim((string)($config['switch_name'] ?? ''));
        $switchItem = null;
        foreach ($switches as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if (strcasecmp((string)($candidate['name'] ?? ''), $switchName) === 0) {
                $switchItem = $candidate;
                break;
            }
        }

        if (!is_array($switchItem)) {
            return null;
        }

        $credentialMode = trim((string)($switchItem['credential_mode'] ?? 'global'));
        $authMethod = trim((string)($switchItem['ssh_auth_method'] ?? $settings['ssh_auth_method'] ?? 'password'));
        if (!in_array($authMethod, ['password', 'key'], true)) {
            $authMethod = 'password';
        }

        $username = $credentialMode === 'individual'
            ? trim((string)($switchItem['ssh_username'] ?? ''))
            : trim((string)($settings['ssh_username'] ?? ''));

        $password = $credentialMode === 'individual'
            ? (string)($switchItem['ssh_password'] ?? '')
            : (string)($settings['ssh_password'] ?? '');

        $privateKey = $credentialMode === 'individual'
            ? (string)($switchItem['ssh_private_key'] ?? '')
            : (string)($settings['ssh_private_key'] ?? '');

        return [
            'mgmt_ip' => trim((string)($switchItem['mgmt_ip'] ?? $config['host'] ?? '')),
            'ssh_port' => (int)($settings['ssh_port'] ?? 22),
            'credential_mode' => $credentialMode,
            'ssh_auth_method' => $authMethod,
            'ssh_username' => $username,
            'ssh_password' => $password,
            'ssh_private_key' => $privateKey,
        ];
    }

    /**
     * @param array<string,mixed> $connection
     * @param array<int,string> $commands
     * @return array{ok:bool,output:string,diagnostics:array<string,mixed>}
     */
    private function runReadOnlySshCommands(array $connection, array $commands, Logger $logger, string $switchName): array
    {
        $host = trim((string)($connection['mgmt_ip'] ?? ''));
        $port = (int)($connection['ssh_port'] ?? 22);
        $authMethod = trim((string)($connection['ssh_auth_method'] ?? 'password'));
        $username = trim((string)($connection['ssh_username'] ?? ''));
        $password = (string)($connection['ssh_password'] ?? '');
        $privateKey = (string)($connection['ssh_private_key'] ?? '');
        $diagnostics = [
            'credential_mode' => (string)($connection['credential_mode'] ?? ''),
            'auth_method' => $authMethod,
            'host_set' => $host !== '',
            'username_set' => $username !== '',
            'password_set' => $password !== '',
            'private_key_set' => trim($privateKey) !== '',
            'command_count' => count($commands),
            'commands' => array_values($commands),
        ];

        if ($host === '' || $username === '') {
            return ['ok' => false, 'output' => 'Host oder SSH-Benutzer fehlen.', 'diagnostics' => $diagnostics];
        }

        $sshPath = trim((string)shell_exec('command -v ssh 2>/dev/null'));
        $diagnostics['ssh_binary_found'] = $sshPath !== '';
        if ($sshPath === '') {
            return ['ok' => false, 'output' => 'ssh Binary wurde nicht gefunden.', 'diagnostics' => $diagnostics];
        }

        $timeoutPath = trim((string)shell_exec('command -v timeout 2>/dev/null'));
        $sshpassPath = trim((string)shell_exec('command -v sshpass 2>/dev/null'));
        $diagnostics['timeout_found'] = $timeoutPath !== '';
        $diagnostics['sshpass_found'] = $sshpassPath !== '';
        $knownHostsFile = $this->ensureKnownHostsFile();
        if ($knownHostsFile === null) {
            return ['ok' => false, 'output' => 'Known-Hosts-Datei konnte nicht angelegt werden.', 'diagnostics' => $diagnostics];
        }

        $sshOptions = '-F /dev/null -tt -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . escapeshellarg($knownHostsFile) . ' -o ConnectTimeout=8';
        $keyFile = null;
        if ($authMethod === 'password' && $password !== '') {
            $sshOptions .= ' -o PreferredAuthentications=password -o PubkeyAuthentication=no';
        } else {
            $sshOptions .= ' -o BatchMode=yes';
        }

        if ($authMethod === 'key') {
            if (trim($privateKey) === '') {
                return ['ok' => false, 'output' => 'SSH-Key ist leer.', 'diagnostics' => $diagnostics];
            }
            $keyFile = tempnam(sys_get_temp_dir(), 'portflow-huawei-key-');
            if ($keyFile === false) {
                return ['ok' => false, 'output' => 'Temporäre Key-Datei konnte nicht erstellt werden.', 'diagnostics' => $diagnostics];
            }
            file_put_contents($keyFile, rtrim($privateKey) . "\n");
            @chmod($keyFile, 0600);
            $sshOptions .= ' -o PreferredAuthentications=publickey -o PasswordAuthentication=no -i ' . escapeshellarg($keyFile);
        }

        $commandFile = tempnam(sys_get_temp_dir(), 'portflow-huawei-cli-');
        if ($commandFile === false) {
            if ($keyFile !== null) {
                @unlink($keyFile);
            }
            return ['ok' => false, 'output' => 'Temporäre Kommando-Datei konnte nicht erstellt werden.', 'diagnostics' => $diagnostics];
        }

        $commandLines = ['screen-length 0 temporary'];
        foreach ($commands as $command) {
            $command = trim($command);
            if ($command !== '') {
                $commandLines[] = $command;
            }
        }
        $commandLines[] = 'quit';
        file_put_contents($commandFile, implode("\n", $commandLines) . "\n");

        $target = escapeshellarg($username . '@' . $host);
        $sshCommand = $sshPath . ' ' . $sshOptions . ' -p ' . $port . ' ' . $target . ' < ' . escapeshellarg($commandFile);
        if ($authMethod === 'password' && $password !== '') {
            if ($sshpassPath === '') {
                @unlink($commandFile);
                if ($keyFile !== null) {
                    @unlink($keyFile);
                }
                return ['ok' => false, 'output' => 'sshpass wurde fuer Passwortauthentifizierung nicht gefunden.', 'diagnostics' => $diagnostics];
            }
            putenv('SSHPASS=' . $password);
            $sshCommand = $sshpassPath . ' -e ' . $sshCommand;
        }

        $fullCommand = $timeoutPath !== '' ? ($timeoutPath . ' 20s ' . $sshCommand) : $sshCommand;
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

        $logger->log('HuaweiScannerExtension CLI collector for ' . $switchName . ' returned exit=' . $exitCode, $exitCode === 0 ? 1 : 2);
        $diagnostics['exit_code'] = $exitCode;
        $diagnostics['output_preview'] = $this->buildOutputPreview(implode("\n", $lines));
        return [
            'ok' => $exitCode === 0,
            'output' => implode("\n", $lines),
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<string,mixed> $connection
     * @return array<string,mixed>
     */
    private function describeConnection(array $connection): array
    {
        return [
            'credential_mode' => (string)($connection['credential_mode'] ?? ''),
            'auth_method' => (string)($connection['ssh_auth_method'] ?? ''),
            'host_set' => trim((string)($connection['mgmt_ip'] ?? '')) !== '',
            'username_set' => trim((string)($connection['ssh_username'] ?? '')) !== '',
            'password_set' => (string)($connection['ssh_password'] ?? '') !== '',
            'private_key_set' => trim((string)($connection['ssh_private_key'] ?? '')) !== '',
            'ssh_port' => (int)($connection['ssh_port'] ?? 22),
        ];
    }

    private function buildOutputPreview(string $output): string
    {
        $normalized = trim((string)preg_replace("/\r\n?|\r/", "\n", $output));
        if ($normalized === '') {
            return '';
        }

        return mb_substr($normalized, 0, 1200);
    }

    private function ensureKnownHostsFile(): ?string
    {
        $directory = dirname(__DIR__, 3) . '/data/automation';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }

        $path = $directory . '/known_hosts';
        if (!file_exists($path) && @touch($path) === false) {
            return null;
        }

        @chmod($path, 0600);
        return $path;
    }

    /**
     * @return array<string,array{if_index:int,ip:string,hostname:string,source:string,mac:string,if_name?:string}>
     */
    private function parseNodeIpsFromCliOutput(string $output, string $source): array
    {
        $nodeIps = [];
        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];
        foreach ($lines as $line) {
            $ip = $this->extractIpv4($line);
            $mac = $this->extractMac($line);
            if ($ip === null || $mac === null) {
                continue;
            }

            $interfaceName = $this->extractInterfaceName($line);

            if (!isset($nodeIps[$mac])) {
                $nodeIps[$mac] = [
                    'if_index' => 0,
                    'ip' => $ip,
                    'hostname' => '',
                    'source' => $source,
                    'mac' => $mac,
                    'if_name' => $interfaceName,
                ];
            }
        }

        return $nodeIps;
    }

    private function extractIpv4(string $line): ?string
    {
        if (!preg_match('/\b((?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3})\b/', $line, $matches)) {
            return null;
        }
        return $matches[1];
    }

    private function extractMac(string $line): ?string
    {
        if (!preg_match('/\b([0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}|(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2})\b/', $line, $matches)) {
            return null;
        }

        $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $matches[1]) ?? '');
        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    private function extractInterfaceName(string $line): string
    {
        if (!preg_match('/\b((?:MultiGE|XGigabitEthernet|GigabitEthernet|GE|Eth-Trunk|Vlanif|MEth|25GE|100GE|40GE)\S*)\b/i', $line, $matches)) {
            return '';
        }

        return trim((string)$matches[1]);
    }
}
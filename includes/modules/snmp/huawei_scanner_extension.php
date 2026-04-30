<?php

namespace Portflow\Modules\Snmp;

use Portflow\Core\AutomationStore;
use Portflow\Core\Logger;
use Portflow\Core\SnmpClient;
use Portflow\Core\SnmpScannerExtensionInterface;

class HuaweiScannerExtension implements SnmpScannerExtensionInterface
{
    private const OID_IF_NAME = '.1.3.6.1.2.1.31.1.1.1.1';
    private const OID_HUAWEI_VLAN_DESC = '.1.3.6.1.4.1.2011.5.25.42.1.4.1.1.5';
    private const OID_HUAWEI_VLAN_NAME = '.1.3.6.1.4.1.2011.5.25.42.1.4.1.1.4';
    private const OID_HW_POE_ROOT = '.1.3.6.1.4.1.2011.5.25.195';
    private const OID_HW_POE_PORT_NAME = '.1.3.6.1.4.1.2011.5.25.195.3.1.2';
    private const OID_HW_POE_PORT_ENABLE = '.1.3.6.1.4.1.2011.5.25.195.3.1.3';
    private const OID_HW_POE_PORT_POWER_STATUS = '.1.3.6.1.4.1.2011.5.25.195.3.1.7';
    private const OID_HW_POE_PORT_PD_CLASS = '.1.3.6.1.4.1.2011.5.25.195.3.1.8';
    private const OID_HW_POE_PORT_REFERENCE_POWER = '.1.3.6.1.4.1.2011.5.25.195.3.1.9';
    private const OID_HW_POE_PORT_CONSUMING_POWER = '.1.3.6.1.4.1.2011.5.25.195.3.1.10';
    private const OID_HW_POE_PORT_PEAK_POWER = '.1.3.6.1.4.1.2011.5.25.195.3.1.11';
    private const OID_HW_POE_PORT_AVERAGE_POWER = '.1.3.6.1.4.1.2011.5.25.195.3.1.12';
    private const OID_HW_POE_SLOT_CONSUMING_POWER = '.1.3.6.1.4.1.2011.5.25.195.2.1.5';
    private const OID_HW_POE_DEVICE_USED_POWER = '.1.3.6.1.4.1.2011.5.25.195.5.1.6';
    private const OID_HW_POE_GLOBAL_POWER = '.1.3.6.1.4.1.2011.5.25.195.1.1';

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
        $hwEnable = $this->walkMap($client, $config, self::OID_HW_POE_PORT_ENABLE);
        $hwPortName = $this->walkMap($client, $config, self::OID_HW_POE_PORT_NAME);
        $hwStatus = $this->walkMap($client, $config, self::OID_HW_POE_PORT_POWER_STATUS);
        $hwClass = $this->walkMap($client, $config, self::OID_HW_POE_PORT_PD_CLASS);
        $hwReferencePower = $this->walkMap($client, $config, self::OID_HW_POE_PORT_REFERENCE_POWER);
        $hwConsumingPower = $this->walkMap($client, $config, self::OID_HW_POE_PORT_CONSUMING_POWER);
        $hwPeakPower = $this->walkMap($client, $config, self::OID_HW_POE_PORT_PEAK_POWER);
        $hwAveragePower = $this->walkMap($client, $config, self::OID_HW_POE_PORT_AVERAGE_POWER);
        $rootProbeDiagnostics = [];
        if (
            empty($hwEnable)
            && empty($hwPortName)
            && empty($hwStatus)
            && empty($hwClass)
            && empty($hwReferencePower)
            && empty($hwConsumingPower)
            && empty($hwPeakPower)
            && empty($hwAveragePower)
        ) {
            $rootProbeDiagnostics = $this->probeHuaweiPoeRoot($client, $config);
        }
        $poeIndices = $this->collectHuaweiPoePortIndices(
            $hwEnable,
            $hwPortName,
            $hwStatus,
            $hwClass,
            $hwReferencePower,
            $hwConsumingPower,
            $hwPeakPower,
            $hwAveragePower
        );

        $this->lastDiagnostics['poe'] = [
            'source' => 'HUAWEI-POE-MIB::hwPoePortEntry',
            'status' => empty($poeIndices)
                ? (!empty($rootProbeDiagnostics) && (int)($rootProbeDiagnostics['oid_count'] ?? 0) === 0
                    ? 'huawei-poe-mib-not-exposed'
                    : 'empty-port-table')
                : (empty($hwEnable) ? 'missing-enable-table-using-port-data' : 'enable-table-found'),
            'enable_count' => count($hwEnable),
            'status_count' => count($hwStatus),
            'class_count' => count($hwClass),
            'reference_power_count' => count($hwReferencePower),
            'consumption_count' => count($hwConsumingPower),
            'peak_power_count' => count($hwPeakPower),
            'average_power_count' => count($hwAveragePower),
            'port_name_count' => count($hwPortName),
            'data_index_count' => count($poeIndices),
            'root_probe_mode' => empty($rootProbeDiagnostics) ? 'off' : 'root-walk',
            'root_probe_oid_count' => (int)($rootProbeDiagnostics['oid_count'] ?? 0),
            'root_probe_status' => (string)($rootProbeDiagnostics['status'] ?? ''),
            'root_probe_sample_oid' => (string)($rootProbeDiagnostics['sample_oid'] ?? ''),
            'sample_indices' => array_slice(array_values(array_map('strval', $poeIndices)), 0, 10),
        ];
        if (empty($poeIndices)) {
            return null;
        }

        $hwDeviceUsedPower = $this->walkMap($client, $config, self::OID_HW_POE_DEVICE_USED_POWER);
        $hwSlotConsumingPower = $this->walkMap($client, $config, self::OID_HW_POE_SLOT_CONSUMING_POWER);
        $hwGlobalPower = $this->walkMap($client, $config, self::OID_HW_POE_GLOBAL_POWER);
        $admin = [];
        $detection = [];
        $class = [];
        $consumption = [];
        $portNames = [];
        foreach ($poeIndices as $ifIndex) {
            $key = (string)$ifIndex;
            $adminValue = array_key_exists($ifIndex, $hwEnable)
                ? $this->normalizeHuaweiEnabledStatus($hwEnable[$ifIndex])
                : $this->inferHuaweiAdminFromPortData(
                    (string)($hwStatus[$ifIndex] ?? ''),
                    $this->normalizeHuaweiInteger($hwClass[$ifIndex] ?? null),
                    $this->normalizeHuaweiInteger($hwReferencePower[$ifIndex] ?? null),
                    $this->normalizeHuaweiInteger($hwConsumingPower[$ifIndex] ?? null),
                    $this->normalizeHuaweiInteger($hwPeakPower[$ifIndex] ?? null),
                    $this->normalizeHuaweiInteger($hwAveragePower[$ifIndex] ?? null)
                );
            $powerStatus = (string)($hwStatus[$ifIndex] ?? '');
            $powerMw = $this->normalizeHuaweiInteger($hwConsumingPower[$ifIndex] ?? null);

            $admin[$key] = $adminValue;
            $detection[$key] = $this->normalizeHuaweiPoeDetection($adminValue, $powerStatus, $powerMw);
            $class[$key] = $this->normalizeHuaweiInteger($hwClass[$ifIndex] ?? null);
            if ($powerMw !== null) {
                $consumption[$key] = $powerMw;
            }
            if (isset($hwPortName[$ifIndex]) && trim((string)$hwPortName[$ifIndex]) !== '') {
                $portNames[$key] = trim((string)$hwPortName[$ifIndex]);
            }
        }

        $mainConsumption = $this->collectHuaweiMainConsumption($hwDeviceUsedPower, $hwSlotConsumingPower, $hwGlobalPower);

        $this->lastDiagnostics['poe'] = [
            'source' => 'HUAWEI-POE-MIB::hwPoePortEntry',
            'status' => empty($hwEnable) ? 'ok-without-enable-table' : 'ok',
            'enable_count' => count($hwEnable),
            'status_count' => count($hwStatus),
            'class_count' => count($class),
            'reference_power_count' => count($hwReferencePower),
            'consumption_count' => count($consumption),
            'peak_power_count' => count($hwPeakPower),
            'average_power_count' => count($hwAveragePower),
            'port_name_count' => count($portNames),
            'main_consumption_count' => count($mainConsumption),
            'data_index_count' => count($poeIndices),
            'resolved_port_count' => count($admin),
            'root_probe_mode' => empty($rootProbeDiagnostics) ? 'off' : 'root-walk',
            'root_probe_oid_count' => (int)($rootProbeDiagnostics['oid_count'] ?? 0),
            'root_probe_status' => (string)($rootProbeDiagnostics['status'] ?? ''),
            'root_probe_sample_oid' => (string)($rootProbeDiagnostics['sample_oid'] ?? ''),
            'sample_indices' => array_slice(array_values(array_map('strval', array_keys($admin))), 0, 10),
        ];

        return [
            'admin' => $admin,
            'detection' => $detection,
            'class' => $class,
            'consumption' => $consumption,
            'port_name' => $portNames,
            'main_consumption' => $mainConsumption,
            'source' => 'HUAWEI-POE-MIB::hwPoePortEntry',
        ];
    }

    public function collectNodeIps(SnmpClient $client, Logger $logger, array $config): array
    {
        $mode = strtolower(trim((string)($config['node_ip_collection'] ?? '')));
        $this->lastDiagnostics['extension'] = $this->getId();
        $this->lastDiagnostics['switch_name'] = (string)($config['switch_name'] ?? '');
        $this->lastDiagnostics['node_ip_collection'] = [
            'mode' => $mode,
            'status' => 'disabled',
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
            if (SnmpClient::isUnsupportedResponseValue((string)$value)) {
                continue;
            }
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

    private function normalizeHuaweiEnabledStatus(mixed $value): int
    {
        $normalized = strtolower(trim((string)$value));
        if ($normalized === '' || $normalized === '2' || str_contains($normalized, 'disable')) {
            return 2;
        }

        if ($normalized === '1' || str_contains($normalized, 'enable')) {
            return 1;
        }

        return ctype_digit($normalized) ? (int)$normalized : 2;
    }

    /**
     * @param array<int|string,string> ...$sources
     * @return array<int|string>
     */
    private function collectHuaweiPoePortIndices(array ...$sources): array
    {
        $indices = [];
        foreach ($sources as $source) {
            foreach (array_keys($source) as $index) {
                $key = (string)$index;
                if ($key === '') {
                    continue;
                }
                $indices[$key] = $index;
            }
        }

        ksort($indices, SORT_NATURAL);
        return array_values($indices);
    }

    private function inferHuaweiAdminFromPortData(
        string $powerStatus,
        ?int $pdClass,
        ?int $referencePowerMw,
        ?int $consumingPowerMw,
        ?int $peakPowerMw,
        ?int $averagePowerMw
    ): int {
        $normalizedStatus = strtolower(trim($powerStatus));

        if ($consumingPowerMw !== null && $consumingPowerMw > 0) {
            return 1;
        }
        if ($referencePowerMw !== null && $referencePowerMw > 0) {
            return 1;
        }
        if ($peakPowerMw !== null && $peakPowerMw > 0) {
            return 1;
        }
        if ($averagePowerMw !== null && $averagePowerMw > 0) {
            return 1;
        }
        if ($pdClass !== null && $pdClass > 0) {
            return 1;
        }
        if ($normalizedStatus !== '') {
            if (preg_match('/disable|disabled|manual\s*off|power\s*off|shutdown|shut/i', $normalizedStatus) === 1) {
                return 2;
            }
            return 1;
        }

        return 1;
    }

    /**
     * Single SNMP walk against the HUAWEI-POE-MIB root to determine whether
     * the subtree is exposed by the device at all. Replaces the previous
     * per-ifIndex direct probe which fired hundreds of snmpget calls (the
     * hwPoePort* indices are not ifIndex values, so that approach could
     * never match anything anyway).
     *
     * @param array<string,mixed> $config
     * @return array{oid_count:int,status:string,sample_oid:string}
     */
    private function probeHuaweiPoeRoot(SnmpClient $client, array $config): array
    {
        $result = $client->runWalk($config, self::OID_HW_POE_ROOT);
        if (!$result['ok']) {
            return [
                'oid_count' => 0,
                'status' => 'walk-failed',
                'sample_oid' => '',
            ];
        }

        $parsed = SnmpClient::parseWalkLines($result['lines']);
        $supported = [];
        foreach ($parsed as $oid => $value) {
            if (SnmpClient::isUnsupportedResponseValue((string)$value)) {
                continue;
            }
            $supported[$oid] = $value;
        }

        $sampleOid = '';
        if (!empty($supported)) {
            $sampleOid = (string)array_key_first($supported);
        }

        return [
            'oid_count' => count($supported),
            'status' => empty($supported) ? 'subtree-empty-or-blocked' : 'subtree-exposed',
            'sample_oid' => $sampleOid,
        ];
    }

    private function normalizeHuaweiInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/-?\d+/', $normalized, $matches) !== 1) {
            return null;
        }

        return (int)$matches[0];
    }

    private function normalizeHuaweiPoeDetection(int $adminValue, string $powerStatus, ?int $powerMw): ?int
    {
        if ($adminValue !== 1) {
            return 1;
        }

        if ($powerMw !== null && $powerMw > 0) {
            return 3;
        }

        $normalizedStatus = strtolower(trim($powerStatus));
        if ($normalizedStatus === '') {
            return 2;
        }

        if (preg_match('/fault|error|deny|abnormal|overload|short/i', $normalizedStatus) === 1) {
            return 4;
        }

        if (preg_match('/deliver|supply|power-?on|on\b/i', $normalizedStatus) === 1) {
            return 3;
        }

        if (preg_match('/search|detect|idle|wait|off\b|open/i', $normalizedStatus) === 1) {
            return 2;
        }

        $statusInt = $this->normalizeHuaweiInteger($powerStatus);
        if ($statusInt !== null) {
            if ($statusInt <= 1) {
                return 2;
            }
            return 3;
        }

        return 2;
    }

    /**
     * @param array<int|string,string> $deviceUsedPower
     * @param array<int|string,string> $slotConsumingPower
     * @param array<int|string,string> $globalPower
     * @return array<int,int>
     */
    private function collectHuaweiMainConsumption(array $deviceUsedPower, array $slotConsumingPower, array $globalPower): array
    {
        $values = [];
        foreach ([$deviceUsedPower, $slotConsumingPower, $globalPower] as $source) {
            foreach ($source as $rawValue) {
                $normalized = $this->normalizeHuaweiInteger($rawValue);
                if ($normalized === null) {
                    continue;
                }
                $values[] = $normalized;
            }
            if (!empty($values)) {
                break;
            }
        }

        return array_values($values);
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
     * @return array<string,array{if_index:int,ip:string,hostname:string,source:string,mac:string,if_name?:string,vlan?:int|null}>
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
            $vlanId = $this->extractVlanId($line);

            if (!isset($nodeIps[$mac])) {
                $nodeIps[$mac] = [
                    'if_index' => 0,
                    'ip' => $ip,
                    'hostname' => '',
                    'source' => $source,
                    'mac' => $mac,
                    'if_name' => $interfaceName,
                    'vlan' => $vlanId,
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

    private function extractVlanId(string $line): ?int
    {
        if (!preg_match('/(?:^|\s)(\d+)\/(?:--|[0-9-]+)(?=\s|$)/', $line, $matches)) {
            return null;
        }

        $vlanId = (int)($matches[1] ?? 0);
        return $vlanId > 0 ? $vlanId : null;
    }
}
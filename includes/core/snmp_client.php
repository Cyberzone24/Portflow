<?php

namespace Portflow\Core;

include_once __DIR__ . '/automation_store.php';
include_once __DIR__ . '/logger.php';

/**
 * Shared SNMP client used by settings.php (manual test) and snmp_scanner.php (discovery).
 * Wraps the net-snmp CLI tools (snmpget, snmpwalk, snmpbulkwalk).
 */
class SnmpClient
{
    public function __construct(
        private AutomationStore $store,
        private Logger $logger
    ) {
    }

    /**
     * Resolve the effective SNMP configuration for a named switch from the
     * inventory + profile, applying optional field overrides.
     *
     * @return array{ok:bool,error?:string,config?:array<string,mixed>,switch?:array<string,mixed>}
     */
    public function resolveSwitchConfig(string $switchName, array $overrides = []): array
    {
        $saved = $this->store->getSettings();
        $inventoryRaw = trim((string)($saved['switch_inventory_json'] ?? ''));
        $inventory = $this->decodeJson($inventoryRaw, ['switches' => []]);
        $switches = is_array($inventory['switches'] ?? null) ? $inventory['switches'] : [];

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

        if ($switchItem === null && $switchName !== '') {
            return ['ok' => false, 'error' => 'Switch "' . $switchName . '" wurde im Inventar nicht gefunden.'];
        }

        $switchSnmp = is_array(($switchItem['snmp'] ?? null)) ? $switchItem['snmp'] : [];
        $profileId = trim((string)($switchItem['profile'] ?? ''));
        $profileSnmp = $this->loadProfileSnmp($profileId);

        $pick = static function (string $key, ...$sources) {
            foreach ($sources as $source) {
                if ($source === null) {
                    continue;
                }
                $value = is_array($source) ? ($source[$key] ?? null) : null;
                if ($value === null) {
                    continue;
                }
                if (is_string($value) && trim($value) === '') {
                    continue;
                }
                return $value;
            }
            return null;
        };

        $config = [
            'switch_name'         => (string)($switchItem['name'] ?? $switchName),
            'host'                => trim((string)($overrides['host'] ?? $pick('mgmt_ip', $switchItem) ?? '')),
            'profile_id'          => $profileId,
            'version'             => trim((string)($overrides['version'] ?? $pick('version', $switchSnmp, $profileSnmp) ?? '2c')),
            'community'           => trim((string)($overrides['community'] ?? $pick('community', $switchSnmp, $profileSnmp) ?? '')),
            'mib'                 => trim((string)($overrides['mib'] ?? $pick('mib', $switchSnmp) ?? $pick('default_mib', $profileSnmp) ?? '')),
            'snmp_extension'      => trim((string)($overrides['extension'] ?? $pick('extension', $switchSnmp, $profileSnmp) ?? '')),
            'node_ip_collection'  => trim((string)($overrides['node_ip_collection'] ?? $pick('node_ip_collection', $switchSnmp, $profileSnmp) ?? '')),
            'port'                => (int)($overrides['port'] ?? $pick('port', $switchSnmp, $profileSnmp) ?? 161),
            'timeout'             => (int)($overrides['timeout'] ?? $pick('timeout', $switchSnmp, $profileSnmp) ?? 2),
            'retries'             => (int)($overrides['retries'] ?? $pick('retries', $switchSnmp, $profileSnmp) ?? 1),
            'v3_username'         => trim((string)($overrides['v3_username'] ?? $pick('v3_username', $switchSnmp, $profileSnmp) ?? '')),
            'v3_auth_protocol'    => self::normalizeAuthProtocol((string)($overrides['v3_auth_protocol'] ?? $pick('v3_auth_protocol', $switchSnmp, $profileSnmp) ?? '')),
            'v3_auth_passphrase'  => (string)($overrides['v3_auth_passphrase'] ?? $pick('v3_auth_passphrase', $switchSnmp, $profileSnmp) ?? ''),
            'v3_priv_protocol'    => self::normalizePrivProtocol((string)($overrides['v3_priv_protocol'] ?? $pick('v3_priv_protocol', $switchSnmp, $profileSnmp) ?? '')),
            'v3_priv_passphrase'  => (string)($overrides['v3_priv_passphrase'] ?? $pick('v3_priv_passphrase', $switchSnmp, $profileSnmp) ?? ''),
            'device_uuid'         => trim((string)($switchItem['device_id'] ?? '')),
            'item_group_uuid'     => trim((string)($switchItem['item_group_id'] ?? '')),
        ];

        if ($config['port'] < 1 || $config['port'] > 65535) {
            $config['port'] = 161;
        }
        if ($config['timeout'] < 1 || $config['timeout'] > 30) {
            $config['timeout'] = 2;
        }
        if ($config['retries'] < 0 || $config['retries'] > 10) {
            $config['retries'] = 1;
        }

        if (!in_array($config['version'], ['2c', '3'], true)) {
            $config['version'] = '2c';
        }

        // Heuristic: if a v2c switch has no community but the profile is v3, prefer v3.
        if ($config['version'] === '2c' && $config['community'] === '' && trim((string)($profileSnmp['version'] ?? '')) === '3') {
            $config['version'] = '3';
        }

        if ($config['version'] === '3') {
            $config['security_level'] = 'noAuthNoPriv';
            if ($config['v3_auth_passphrase'] !== '' && $config['v3_priv_passphrase'] !== '') {
                $config['security_level'] = 'authPriv';
            } elseif ($config['v3_auth_passphrase'] !== '') {
                $config['security_level'] = 'authNoPriv';
            }
        }

        if ($config['host'] === '') {
            return ['ok' => false, 'error' => 'Switch "' . $switchName . '" hat keine Management-Adresse.'];
        }
        if (!preg_match('/^[a-zA-Z0-9.:_-]+$/', $config['host'])) {
            return ['ok' => false, 'error' => 'Host enthaelt unzulaessige Zeichen.'];
        }
        if ($config['version'] === '2c' && $config['community'] === '') {
            return ['ok' => false, 'error' => 'Fuer SNMPv2c ist eine Community erforderlich.'];
        }
        if ($config['version'] === '3' && $config['v3_username'] === '') {
            return ['ok' => false, 'error' => 'Fuer SNMPv3 ist ein Username erforderlich.'];
        }

        return ['ok' => true, 'config' => $config, 'switch' => $switchItem];
    }

    /**
     * Run snmpget for one OID. Returns the parsed lines plus exit code and masked command.
     *
     * @return array{ok:bool,exit_code:int,lines:array<int,string>,masked_command:string,command:string}
     */
    public function runGet(array $config, string $oid): array
    {
        return $this->runTool('snmpget', $config, [$oid]);
    }

    /**
     * Run snmpwalk on a base OID. Output uses -Oqn for compact "<oid> <value>" rows.
     *
     * @return array{ok:bool,exit_code:int,lines:array<int,string>,masked_command:string,command:string}
     */
    public function runWalk(array $config, string $oid): array
    {
        return $this->runTool('snmpwalk', $config, [$oid], ['-Oqn']);
    }

    /**
     * Walk that forces OCTET STRING values to be printed in hex (`-Ox`).
     * Required for Q-BRIDGE-MIB port bitmaps (dot1qVlanCurrentEgressPorts/UntaggedPorts)
     * because plain `-Oqn` may render them as printable strings and lose data.
     *
     * @return array{ok:bool,exit_code:int,lines:array<int,string>,masked_command:string,command:string}
     */
    public function runWalkHex(array $config, string $oid): array
    {
        return $this->runTool('snmpwalk', $config, [$oid], ['-Oqnx']);
    }

    /**
     * Parse output of runWalk(...) into an associative map of "<full numeric oid>" => value (string, unquoted).
     *
     * Long OCTET STRING values (e.g. Q-BRIDGE-MIB port bitmaps for switches with many
     * bridge ports) span multiple snmpwalk output lines: only the FIRST line starts with
     * the OID, and the value is wrapped in quotes that only close on the LAST line.
     * Continuation lines are appended to the value of the most recently seen OID.
     *
     * @param array<int,string> $lines
     * @return array<string,string>
     */
    public static function parseWalkLines(array $lines): array
    {
        $result   = [];
        $lastOid  = null;
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            $trimmed = ltrim($line);
            if ($trimmed === '') {
                continue;
            }
            if ($trimmed[0] === '.') {
                // -Oqn output: "<.numeric.oid> <value>"
                $pos = strpos($trimmed, ' ');
                if ($pos === false) {
                    $result[$trimmed] = '';
                    $lastOid = $trimmed;
                    continue;
                }
                $oid = substr($trimmed, 0, $pos);
                $value = ltrim(substr($trimmed, $pos + 1));
                $result[$oid] = $value;
                $lastOid = $oid;
            } elseif ($lastOid !== null) {
                // Continuation line of a multi-line OCTET STRING value.
                $result[$lastOid] .= ' ' . $trimmed;
            }
        }
        // Strip a single pair of wrapping quotes from each value (now that
        // multi-line values have been joined).
        foreach ($result as $oid => $value) {
            $value = trim($value);
            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                $value = substr($value, 1, -1);
            }
            $result[$oid] = $value;
        }
        return $result;
    }

    public static function isUnsupportedResponseValue(string $value): bool
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return false;
        }

        return preg_match('/^(No Such Object|No Such Instance|End of MIB|No more variables left in this MIB View)/i', $normalized) === 1;
    }

    public static function normalizeAuthProtocol(string $protocol): string
    {
        $trimmed = trim($protocol);
        if ($trimmed === '') {
            return '';
        }
        $normalized = strtoupper(str_replace(['-', '_'], '', $trimmed));
        if ($normalized === 'SHA1') {
            $normalized = 'SHA';
        }
        $allowed = ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'];
        return in_array($normalized, $allowed, true) ? $normalized : 'SHA';
    }

    public static function normalizePrivProtocol(string $protocol): string
    {
        $trimmed = trim($protocol);
        if ($trimmed === '') {
            return '';
        }
        $normalized = strtoupper(str_replace(['-', '_'], '', $trimmed));
        if ($normalized === 'AES256C' || $normalized === 'AES256CFB') {
            $normalized = 'AES256';
        }
        $allowed = ['DES', 'AES', 'AES128', 'AES192', 'AES256'];
        return in_array($normalized, $allowed, true) ? $normalized : 'AES';
    }

    public static function mapAuthProtocolForCli(string $normalizedProtocol): string
    {
        $protocol = self::normalizeAuthProtocol($normalizedProtocol);
        $cliMap = [
            'SHA224' => 'SHA-224',
            'SHA256' => 'SHA-256',
            'SHA384' => 'SHA-384',
            'SHA512' => 'SHA-512'
        ];
        return $cliMap[$protocol] ?? $protocol;
    }

    public static function mapPrivProtocolForCli(string $normalizedProtocol): string
    {
        $protocol = self::normalizePrivProtocol($normalizedProtocol);
        $cliMap = [
            'AES128' => 'AES',
            'AES192' => 'AES-192',
            'AES256' => 'AES-256'
        ];
        return $cliMap[$protocol] ?? $protocol;
    }

    /**
     * @return array{ok:bool,exit_code:int,lines:array<int,string>,masked_command:string,command:string}
     */
    private function runTool(string $tool, array $config, array $oids, array $extraFlags = []): array
    {
        $binPath = trim((string)shell_exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null'));
        if ($binPath === '') {
            return [
                'ok' => false,
                'exit_code' => 127,
                'lines' => [$tool . ' Binary wurde nicht gefunden.'],
                'masked_command' => '',
                'command' => '',
            ];
        }
        $timeoutPath = trim((string)shell_exec('command -v timeout 2>/dev/null'));

        $version = (string)$config['version'];
        $cmd = [$binPath, '-v', escapeshellarg($version), '-On',
            '-t', escapeshellarg((string)$config['timeout']),
            '-r', escapeshellarg((string)$config['retries'])];
        $masked = $cmd;
        foreach ($extraFlags as $flag) {
            $cmd[] = $flag;
            $masked[] = $flag;
        }

        if ($version === '3') {
            $level = (string)($config['security_level'] ?? 'noAuthNoPriv');
            $cmd[] = '-l';
            $cmd[] = escapeshellarg($level);
            $cmd[] = '-u';
            $cmd[] = escapeshellarg((string)$config['v3_username']);
            $masked[] = '-l';
            $masked[] = escapeshellarg($level);
            $masked[] = '-u';
            $masked[] = escapeshellarg((string)$config['v3_username']);

            if (($config['v3_auth_passphrase'] ?? '') !== '') {
                $authCli = self::mapAuthProtocolForCli((string)$config['v3_auth_protocol']);
                $cmd[] = '-a';
                $cmd[] = escapeshellarg($authCli);
                $cmd[] = '-A';
                $cmd[] = escapeshellarg((string)$config['v3_auth_passphrase']);
                $masked[] = '-a';
                $masked[] = escapeshellarg($authCli);
                $masked[] = '-A';
                $masked[] = "'********'";
            }
            if ($level === 'authPriv') {
                $privCli = self::mapPrivProtocolForCli((string)$config['v3_priv_protocol']);
                $cmd[] = '-x';
                $cmd[] = escapeshellarg($privCli);
                $cmd[] = '-X';
                $cmd[] = escapeshellarg((string)$config['v3_priv_passphrase']);
                $masked[] = '-x';
                $masked[] = escapeshellarg($privCli);
                $masked[] = '-X';
                $masked[] = "'********'";
            }
        } else {
            $cmd[] = '-c';
            $cmd[] = escapeshellarg((string)$config['community']);
            $masked[] = '-c';
            $masked[] = "'********'";
        }

        $agent = (string)$config['host'] . ':' . (string)$config['port'];
        $cmd[] = escapeshellarg($agent);
        $masked[] = escapeshellarg($agent);
        foreach ($oids as $oid) {
            $cmd[] = escapeshellarg($oid);
            $masked[] = escapeshellarg($oid);
        }

        $command = implode(' ', $cmd);
        $maskedCommand = implode(' ', $masked);
        if ($timeoutPath !== '') {
            $command = $timeoutPath . ' 30s ' . $command;
            $maskedCommand = $timeoutPath . ' 30s ' . $maskedCommand;
        }

        $lines = [];
        $exit = 1;
        exec($command . ' 2>&1', $lines, $exit);

        return [
            'ok' => ($exit === 0),
            'exit_code' => $exit,
            'lines' => $lines,
            'masked_command' => $maskedCommand,
            'command' => $command,
        ];
    }

    private function loadProfileSnmp(string $profileId): array
    {
        if ($profileId === '') {
            return [];
        }
        $coreProfiles = $this->readAutomationProfiles(__DIR__ . '/automation.json');
        $dataProfiles = $this->readAutomationProfiles(__DIR__ . '/../../data/automation/automation.json');

        $coreProfile = is_array($coreProfiles[$profileId] ?? null) ? $coreProfiles[$profileId] : [];
        $dataProfile = is_array($dataProfiles[$profileId] ?? null) ? $dataProfiles[$profileId] : [];
        $mergedProfile = array_replace_recursive($coreProfile, $dataProfile);

        return is_array($mergedProfile['snmp'] ?? null) ? $mergedProfile['snmp'] : [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function readAutomationProfiles(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $raw = (string)file_get_contents($path);
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return [];
        }

        $profiles = is_array($parsed['profiles'] ?? null) ? $parsed['profiles'] : $parsed;
        return is_array($profiles) ? $profiles : [];
    }

    private function decodeJson(string $raw, array $fallback = []): array
    {
        if ($raw === '') {
            return $fallback;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $fallback;
    }
}

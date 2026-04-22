<?php
namespace Portflow\Core;

if (!defined('APP_NAME')) {
    die('Access denied');
}

class AutomationStore {
    private string $filePath;
    private string $cipher = 'aes-256-cbc';

    public function __construct(?string $filePath = null) {
        $this->filePath = $filePath ?? __DIR__ . '/../../data/automation/settings.json';
    }

    public function getSettings(): array {
        $stored = $this->readStored();

        $sshAuthMethod = trim((string)($stored['ssh_auth_method'] ?? ''));
        if (!in_array($sshAuthMethod, ['password', 'key'], true)) {
            $sshAuthMethod = 'password';
        }

        return [
            'ssh_host' => (string)($stored['ssh_host'] ?? ''),
            'ssh_port' => (int)($stored['ssh_port'] ?? 22),
            'ssh_auth_method' => $sshAuthMethod,
            'ssh_username' => $this->decrypt((string)($stored['ssh_username'] ?? '')),
            'ssh_password' => $this->decrypt((string)($stored['ssh_password'] ?? '')),
            'ssh_private_key' => $this->decrypt((string)($stored['ssh_private_key'] ?? '')),
            'scripts_json' => $this->decrypt((string)($stored['scripts_json'] ?? '')),
            'switch_inventory_json' => $this->decrypt((string)($stored['switch_inventory_json'] ?? '')),
            'updated_at' => (string)($stored['updated_at'] ?? ''),
            'scheduler_status' => is_array($stored['scheduler_status'] ?? null) ? $stored['scheduler_status'] : null
        ];
    }

    public function getScriptOverrides(): array {
        $settings = $this->getSettings();
        $rawJson = trim((string)($settings['scripts_json'] ?? ''));

        if ($rawJson === '') {
            return [];
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public function saveSettings(array $input): void {
        $current = $this->getSettings();

        $sshHost = trim((string)($input['ssh_host'] ?? ''));
        $sshPort = (int)($input['ssh_port'] ?? 22);
        $sshAuthMethod = trim((string)($input['ssh_auth_method'] ?? 'password'));
        $sshUsername = trim((string)($input['ssh_username'] ?? ''));
        $sshPassword = (string)($input['ssh_password'] ?? '');
        $sshPrivateKey = trim((string)($input['ssh_private_key'] ?? ''));
        $scriptsJson = trim((string)($input['scripts_json'] ?? ''));
        $switchInventoryJson = trim((string)($input['switch_inventory_json'] ?? ''));

        if (!in_array($sshAuthMethod, ['password', 'key'], true)) {
            $sshAuthMethod = 'password';
        }

        if ($sshPort <= 0 || $sshPort > 65535) {
            throw new \Exception('SSH Port ist ungueltig.');
        }

        if ($scriptsJson === '') {
            $scriptsJson = '{}';
        }

        $decodedScripts = json_decode($scriptsJson, true);
        if (!is_array($decodedScripts)) {
            throw new \Exception('Skript-JSON ist ungueltig.');
        }

        if ($switchInventoryJson === '') {
            $switchInventoryJson = '{"switches": []}';
        }

        $decodedInventory = json_decode($switchInventoryJson, true);
        if (!is_array($decodedInventory)) {
            throw new \Exception('Switch-Inventar JSON ist ungueltig.');
        }

        // Validate inventory structure: each switch should have name, mgmt_ip, profile, optional credential/snmp fields
        if (isset($decodedInventory['switches']) && is_array($decodedInventory['switches'])) {
            foreach ($decodedInventory['switches'] as $index => $switch) {
                if (!is_array($switch)) {
                    throw new \Exception('Switch-Eintrag muss ein Object sein.');
                }
                if (empty($switch['name'])) {
                    throw new \Exception('Jeder Switch benötigt einen "name".');
                }
                if (empty($switch['mgmt_ip'])) {
                    throw new \Exception('Jeder Switch benötigt eine "mgmt_ip".');
                }
                if (empty($switch['profile'])) {
                    throw new \Exception('Jeder Switch benötigt ein "profile".');
                }
                // device_id is optional, but if present should be non-empty
                if (isset($switch['device_id']) && empty($switch['device_id'])) {
                    throw new \Exception('device_id sollte nicht leer sein, wenn gesetzt.');
                }

                $credentialMode = trim((string)($switch['credential_mode'] ?? 'global'));
                if (!in_array($credentialMode, ['global', 'individual'], true)) {
                    throw new \Exception('credential_mode muss "global" oder "individual" sein.');
                }

                $switchAuthMethod = trim((string)($switch['ssh_auth_method'] ?? 'password'));
                if (!in_array($switchAuthMethod, ['password', 'key'], true)) {
                    throw new \Exception('ssh_auth_method muss "password" oder "key" sein.');
                }

                if ($credentialMode === 'individual') {
                    $switchUsername = trim((string)($switch['ssh_username'] ?? ''));
                    if ($switchUsername === '') {
                        throw new \Exception('Individuelle Switch-Credentials benoetigen ssh_username (Switch #' . ($index + 1) . ').');
                    }
                }

                if (isset($switch['snmp']) && !is_array($switch['snmp'])) {
                    throw new \Exception('snmp muss ein Objekt sein (Switch #' . ($index + 1) . ').');
                }

                $snmp = is_array($switch['snmp'] ?? null) ? $switch['snmp'] : [];
                $snmpVersion = trim((string)($snmp['version'] ?? '2c'));
                if (!in_array($snmpVersion, ['2c', '3'], true)) {
                    throw new \Exception('snmp.version muss "2c" oder "3" sein (Switch #' . ($index + 1) . ').');
                }

                $snmpV3AuthProtocol = $this->normalizeSnmpV3AuthProtocol((string)($snmp['v3_auth_protocol'] ?? 'SHA'));
                if (!in_array($snmpV3AuthProtocol, ['MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'], true)) {
                    throw new \Exception('snmp.v3_auth_protocol ist ungueltig (Switch #' . ($index + 1) . ').');
                }

                $snmpV3PrivProtocol = $this->normalizeSnmpV3PrivProtocol((string)($snmp['v3_priv_protocol'] ?? 'AES'));
                if (!in_array($snmpV3PrivProtocol, ['DES', 'AES', 'AES128', 'AES192', 'AES256'], true)) {
                    throw new \Exception('snmp.v3_priv_protocol ist ungueltig (Switch #' . ($index + 1) . ').');
                }

                $decodedInventory['switches'][$index]['snmp']['v3_auth_protocol'] = $snmpV3AuthProtocol;
                $decodedInventory['switches'][$index]['snmp']['v3_priv_protocol'] = $snmpV3PrivProtocol;
            }
        }

        $sshPasswordToStore = $sshPassword;
        if (trim($sshPasswordToStore) === '') {
            $sshPasswordToStore = (string)($current['ssh_password'] ?? '');
        }

        $sshPrivateKeyToStore = $sshPrivateKey;
        if ($sshPrivateKeyToStore === '') {
            $sshPrivateKeyToStore = (string)($current['ssh_private_key'] ?? '');
        }

        if (isset($decodedInventory['switches']) && is_array($decodedInventory['switches'])) {
            foreach ($decodedInventory['switches'] as $index => $switch) {
                if (!is_array($switch)) {
                    continue;
                }

                if (!isset($decodedInventory['switches'][$index]['credential_mode'])) {
                    $decodedInventory['switches'][$index]['credential_mode'] = 'global';
                }

                if (!isset($decodedInventory['switches'][$index]['ssh_auth_method'])) {
                    $decodedInventory['switches'][$index]['ssh_auth_method'] = 'password';
                }

                if (!isset($decodedInventory['switches'][$index]['snmp']) || !is_array($decodedInventory['switches'][$index]['snmp'])) {
                    $decodedInventory['switches'][$index]['snmp'] = [
                        'enabled' => false,
                        'version' => '2c',
                        'port' => 161,
                        'timeout' => 2,
                        'retries' => 1,
                        'community' => '',
                        'mib' => '',
                        'v3_username' => '',
                        'v3_auth_protocol' => 'SHA',
                        'v3_auth_passphrase' => '',
                        'v3_priv_protocol' => 'AES',
                        'v3_priv_passphrase' => ''
                    ];
                } elseif (!isset($decodedInventory['switches'][$index]['snmp']['mib'])) {
                    $decodedInventory['switches'][$index]['snmp']['mib'] = '';
                }

                if (!isset($decodedInventory['switches'][$index]['snmp']['v3_username'])) {
                    $decodedInventory['switches'][$index]['snmp']['v3_username'] = '';
                }
                if (!isset($decodedInventory['switches'][$index]['snmp']['v3_auth_protocol'])) {
                    $decodedInventory['switches'][$index]['snmp']['v3_auth_protocol'] = 'SHA';
                }
                if (!isset($decodedInventory['switches'][$index]['snmp']['v3_auth_passphrase'])) {
                    $decodedInventory['switches'][$index]['snmp']['v3_auth_passphrase'] = '';
                }
                if (!isset($decodedInventory['switches'][$index]['snmp']['v3_priv_protocol'])) {
                    $decodedInventory['switches'][$index]['snmp']['v3_priv_protocol'] = 'AES';
                }
                if (!isset($decodedInventory['switches'][$index]['snmp']['v3_priv_passphrase'])) {
                    $decodedInventory['switches'][$index]['snmp']['v3_priv_passphrase'] = '';
                }
            }
        }

        $payload = [
            'version' => 2,
            'updated_at' => gmdate('c'),
            'ssh_host' => $sshHost,
            'ssh_port' => $sshPort,
            'ssh_auth_method' => $sshAuthMethod,
            'ssh_username' => $this->encrypt($sshUsername),
            'ssh_password' => $this->encrypt($sshPasswordToStore),
            'ssh_private_key' => $this->encrypt($sshPrivateKeyToStore),
            'scripts_json' => $this->encrypt(json_encode($decodedScripts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            'switch_inventory_json' => $this->encrypt(json_encode($decodedInventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
        ];

        $this->writeStored($payload);
    }

    private function readStored(): array {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $raw = file_get_contents($this->filePath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function writeStored(array $payload): void {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new \Exception('Ablageordner fuer Automation konnte nicht erstellt werden.');
            }
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \Exception('Automationsdaten konnten nicht serialisiert werden.');
        }

        if (file_put_contents($this->filePath, $encoded, LOCK_EX) === false) {
            throw new \Exception('Automationsdaten konnten nicht gespeichert werden.');
        }

        @chmod($this->filePath, 0640);
    }

    private function encrypt(string $plainText): string {
        if ($plainText === '') {
            return '';
        }

        $key = $this->getKey();
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = random_bytes($ivLength);

        $cipherText = openssl_encrypt($plainText, $this->cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) {
            throw new \Exception('Verschluesselung fehlgeschlagen.');
        }

        $mac = hash_hmac('sha256', $iv . $cipherText, $key, true);
        return base64_encode($iv . $mac . $cipherText);
    }

    private function normalizeSnmpV3AuthProtocol(string $protocol): string {
        $normalized = strtoupper(str_replace(['-', '_'], '', trim($protocol)));
        if ($normalized === 'SHA1') {
            $normalized = 'SHA';
        }
        return $normalized;
    }

    private function normalizeSnmpV3PrivProtocol(string $protocol): string {
        $normalized = strtoupper(str_replace(['-', '_'], '', trim($protocol)));
        if ($normalized === 'AES256C' || $normalized === 'AES256CFB') {
            $normalized = 'AES256';
        }
        return $normalized;
    }

    private function decrypt(string $encoded): string {
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return '';
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        $macLength = 32;
        if (strlen($raw) <= $ivLength + $macLength) {
            return '';
        }

        $iv = substr($raw, 0, $ivLength);
        $mac = substr($raw, $ivLength, $macLength);
        $cipherText = substr($raw, $ivLength + $macLength);

        $key = $this->getKey();
        $calculatedMac = hash_hmac('sha256', $iv . $cipherText, $key, true);
        if (!hash_equals($mac, $calculatedMac)) {
            return '';
        }

        $plainText = openssl_decrypt($cipherText, $this->cipher, $key, OPENSSL_RAW_DATA, $iv);
        return $plainText === false ? '' : $plainText;
    }

    private function getKey(): string {
        $seed = defined('AUTOMATION_SECRET') ? trim((string)AUTOMATION_SECRET) : '';
        if ($seed === '') {
            $seed = DB_PASSWORD . '|' . DB_NAME . '|' . PORTFLOW_HOSTNAME . '|automation';
        }

        return hash('sha256', $seed, true);
    }

    /**
     * Update scheduler status (called after scheduler.php runs).
     * 
     * @param array $status Status array with keys: last_run, last_success, processed, succeeded, failed, message
     * @return void
     */
    public function updateSchedulerStatus(array $status): void {
        $stored = $this->readStored();
        $stored['scheduler_status'] = [
            'last_run' => (string)($status['last_run'] ?? ''),
            'last_success' => (string)($status['last_success'] ?? ''),
            'processed' => (int)($status['processed'] ?? 0),
            'succeeded' => (int)($status['succeeded'] ?? 0),
            'failed' => (int)($status['failed'] ?? 0),
            'message' => substr((string)($status['message'] ?? ''), 0, 500)
        ];
        $this->writeStored($stored);
    }
}
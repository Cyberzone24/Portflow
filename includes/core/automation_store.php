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

        return [
            'ssh_host' => (string)($stored['ssh_host'] ?? ''),
            'ssh_port' => (int)($stored['ssh_port'] ?? 22),
            'ssh_username' => $this->decrypt((string)($stored['ssh_username'] ?? '')),
            'ssh_password' => $this->decrypt((string)($stored['ssh_password'] ?? '')),
            'scripts_json' => $this->decrypt((string)($stored['scripts_json'] ?? '')),
            'switch_inventory_json' => $this->decrypt((string)($stored['switch_inventory_json'] ?? '')),
            'updated_at' => (string)($stored['updated_at'] ?? '')
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
        $sshUsername = trim((string)($input['ssh_username'] ?? ''));
        $sshPassword = (string)($input['ssh_password'] ?? '');
        $scriptsJson = trim((string)($input['scripts_json'] ?? ''));
        $switchInventoryJson = trim((string)($input['switch_inventory_json'] ?? ''));

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

        // Validate inventory structure: each switch should have name, mgmt_ip, profile, optional device_id
        if (isset($decodedInventory['switches']) && is_array($decodedInventory['switches'])) {
            foreach ($decodedInventory['switches'] as $switch) {
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
            }
        }

        $sshPasswordToStore = $sshPassword;
        if (trim($sshPasswordToStore) === '') {
            $sshPasswordToStore = (string)($current['ssh_password'] ?? '');
        }

        $payload = [
            'version' => 1,
            'updated_at' => gmdate('c'),
            'ssh_host' => $sshHost,
            'ssh_port' => $sshPort,
            'ssh_username' => $this->encrypt($sshUsername),
            'ssh_password' => $this->encrypt($sshPasswordToStore),
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
}
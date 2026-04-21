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
    use Portflow\Core\Auth;
    use Portflow\Core\Automation;
    use Portflow\Core\AutomationStore;
    use Portflow\Core\Logger;
    $auth = new Auth();
    $logger = new Logger();

    $automationTestResult = null;
    $automationFormDataOverride = null;

    function runAutomationSshTest(array $formData, AutomationStore $store, Logger $logger): array {
        $saved = $store->getSettings();

        $host = trim((string)($formData['ssh_host'] ?? ''));
        $port = (int)($formData['ssh_port'] ?? 22);
        $username = trim((string)($formData['ssh_username'] ?? ''));
        $password = (string)($formData['ssh_password'] ?? '');

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

        if ($host === '' || $username === '') {
            return [
                'ok' => false,
                'output' => "SSH-Test fehlgeschlagen: Host und Username sind erforderlich."
            ];
        }

        if (!preg_match('/^[a-zA-Z0-9.:_-]+$/', $host)) {
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

        $sshOptions = '-F /dev/null -tt -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8';

        if ($password !== '') {
            $sshOptions .= ' -o PreferredAuthentications=password -o PubkeyAuthentication=no';
        } else {
            $sshOptions .= ' -o BatchMode=yes';
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

        if ($password !== '') {
            if ($sshpassPath === '') {
                return [
                    'ok' => false,
                    'output' => "SSH-Test fehlgeschlagen: Passwortauthentifizierung benoetigt sshpass, ist aber nicht installiert."
                ];
            }

            $sshCommand = $sshpassPath . ' -p ' . escapeshellarg($password) . ' ' . $sshCommand;
        }

        $fullCommand = $sshCommand;
        if ($timeoutPath !== '') {
            $fullCommand = $timeoutPath . ' 15s ' . $fullCommand;
        }

        $lines = [];
        $exitCode = 1;
        exec($fullCommand . ' 2>&1', $lines, $exitCode);
        @unlink($commandFile);

        $maxLines = 60;
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

        if ($exitCode === 124) {
            $outputText .= "\n\nHinweis: Timeout erreicht. Verbindung wurde nicht rechtzeitig beendet.";
        }

        $logger->log('automation ssh test for ' . $host . ' returned exit code ' . $exitCode, $exitCode === 0 ? 1 : 3);

        return [
            'ok' => ($exitCode === 0),
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
        return in_array($rawTab, ['switch', 'templates', 'history'], true) ? $rawTab : 'switch';
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
                'channel' => 'mail'
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
            'slack' => $slackEnabled && $slackWebhook !== '',
            'telegram' => $telegramEnabled && $telegramBotToken !== '' && $telegramChatId !== '',
            'slack_enabled' => $slackEnabled,
            'telegram_enabled' => $telegramEnabled
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

    function scriptsUrlWithTab(string $tab): string {
        $safeTab = in_array($tab, ['switch', 'templates', 'history'], true) ? $tab : 'switch';
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

        if (file_put_contents($tempPath, $newContent, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Temporare .env Datei konnte nicht geschrieben werden.'];
        }

        if (!@rename($tempPath, $envPath)) {
            @unlink($tempPath);
            return ['ok' => false, 'message' => '.env konnte nicht atomar ersetzt werden.'];
        }

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
        if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $dbServer)) {
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
        if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $ldapServer)) {
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

                $allowedLevels = ['off', 'minimal', 'progress', 'all'];
                $allowedChannels = getAvailableNotificationChannels();

                $settings = getSessionUserSettings();
                $settings['notifications']['level'] = in_array($level, $allowedLevels, true)
                    ? $level
                    : 'minimal';
                $settings['notifications']['channel'] = in_array($channel, $allowedChannels, true)
                    ? $channel
                    : 'mail';

                saveUserSettings($db_adapter, $settings, (string)$_SESSION['uuid']);
                $logger->log('notification preferences updated', 1, echoToWeb: true);
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
                    header('Location: ?site=configuration');
                    die();
                }

                $dbFormData = configBuildDbFormData();
                $dbFormData['db_password'] = configGetPasswordValue('db_password', (string)DB_PASSWORD);
                $dbTestResult = configTestDatabase($dbFormData);
                configSetFeedback('db', (bool)$dbTestResult['ok'], (string)$dbTestResult['message'], $dbFormData);
                $logger->log('database configuration test executed', $dbTestResult['ok'] ? 1 : 2, echoToWeb: true);
                header('Location: ?site=configuration#cfg-db');
                break;
            case 'config_db_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for db configuration save', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
                    die();
                }

                $dbFormData = configBuildDbFormData();
                $dbFormData['db_password'] = configGetPasswordValue('db_password', (string)DB_PASSWORD);
                $dbValidationResult = configTestDatabase($dbFormData);
                if (!$dbValidationResult['ok']) {
                    configSetFeedback('db', false, (string)$dbValidationResult['message'], $dbFormData);
                    header('Location: ?site=configuration#cfg-db');
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
                header('Location: ?site=configuration#cfg-db');
                break;
            case 'config_ldap_test':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for ldap configuration test', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
                    die();
                }

                $ldapFormData = configBuildLdapFormData();
                $ldapFormData['ldap_bind_password'] = configGetPasswordValue('ldap_bind_password', (string)LDAP_BIND_PASSWORD);
                $ldapTestResult = configTestLdap($ldapFormData);
                configSetFeedback('ldap', (bool)$ldapTestResult['ok'], (string)$ldapTestResult['message'], $ldapFormData);
                $logger->log('ldap configuration test executed', $ldapTestResult['ok'] ? 1 : 2, echoToWeb: true);
                header('Location: ?site=configuration#cfg-ldap');
                break;
            case 'config_ldap_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for ldap configuration save', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
                    die();
                }

                $ldapFormData = configBuildLdapFormData();
                $ldapFormData['ldap_bind_password'] = configGetPasswordValue('ldap_bind_password', (string)LDAP_BIND_PASSWORD);

                if (configToBool($ldapFormData['ldap_enabled'] ?? false)) {
                    $ldapValidationResult = configTestLdap($ldapFormData);
                    if (!$ldapValidationResult['ok']) {
                        configSetFeedback('ldap', false, (string)$ldapValidationResult['message'], $ldapFormData);
                        header('Location: ?site=configuration#cfg-ldap');
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
                header('Location: ?site=configuration#cfg-ldap');
                break;
            case 'config_mail_test':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for mail configuration test', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
                    die();
                }

                $mailFormData = configBuildMailFormData();
                $mailFormData['mail_password'] = configGetPasswordValue('mail_password', (string)MAIL_PASSWORD);
                $mailTestResult = configTestMail($mailFormData);
                configSetFeedback('mail', (bool)$mailTestResult['ok'], (string)$mailTestResult['message'], $mailFormData);
                $logger->log('mail configuration test executed', $mailTestResult['ok'] ? 1 : 2, echoToWeb: true);
                header('Location: ?site=configuration#cfg-mail');
                break;
            case 'config_mail_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for mail configuration save', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
                    die();
                }

                $mailFormData = configBuildMailFormData();
                $mailFormData['mail_password'] = configGetPasswordValue('mail_password', (string)MAIL_PASSWORD);
                $mailValidationResult = configTestMail($mailFormData);
                if (!$mailValidationResult['ok']) {
                    configSetFeedback('mail', false, (string)$mailValidationResult['message'], $mailFormData);
                    header('Location: ?site=configuration#cfg-mail');
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
                header('Location: ?site=configuration#cfg-mail');
                break;
            case 'config_notification_save':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification configuration save', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
                    die();
                }

                $notifDailyTime = trim((string)($_POST['notification_daily_time'] ?? '08:00'));
                $notifTimezone  = trim((string)($_POST['notification_timezone'] ?? 'Europe/Berlin'));
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

                $notifWriteResult = configWriteEnvValues([
                    'NOTIFICATION_DAILY_TIME' => $notifDailyTime,
                    'NOTIFICATION_TIMEZONE'   => $notifTimezone,
                    'NOTIFICATION_SLACK_ENABLED' => configEnvBool($notifSlackEnabled),
                    'NOTIFICATION_SLACK_WEBHOOK_URL' => $notifSlackWebhook,
                    'NOTIFICATION_TELEGRAM_ENABLED' => configEnvBool($notifTelegramEnabled),
                    'NOTIFICATION_TELEGRAM_BOT_TOKEN' => $notifTelegramBotToken,
                    'NOTIFICATION_TELEGRAM_CHAT_ID' => $notifTelegramChatId
                ]);

                configSetFeedback('notification', (bool)$notifWriteResult['ok'], (string)$notifWriteResult['message'], [
                    'notification_daily_time' => $notifDailyTime,
                    'notification_timezone'   => $notifTimezone,
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
                        'notification_slack_enabled' => $notifSlackEnabled,
                        'notification_telegram_enabled' => $notifTelegramEnabled
                    ]);
                }
                header('Location: ?site=configuration#cfg-notification');
                break;
            case 'config_notification_enqueue_test':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification enqueue test', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
                    die();
                }

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $meta = [
                        'source' => 'settings_admin',
                        'triggered_by' => (string)($_SESSION['username'] ?? ''),
                        'triggered_at' => gmdate('c')
                    ];
                    $enqueued = $notificationCenter->enqueueGlobal(
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

                header('Location: ?site=configuration#cfg-notification');
                break;
            case 'config_notification_process_queue':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for notification queue processing', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
                    die();
                }

                $maxEntries = (int)($_POST['notification_process_limit'] ?? 100);
                if ($maxEntries < 1 || $maxEntries > 500) {
                    $maxEntries = 100;
                }

                try {
                    $notificationCenter = new NotificationCenter($db_adapter, $logger, $mail);
                    $result = $notificationCenter->processQueue($maxEntries);
                    $message = 'Queue verarbeitet: processed=' . (int)($result['processed'] ?? 0)
                        . ', sent=' . (int)($result['sent'] ?? 0)
                        . ', failed=' . (int)($result['failed'] ?? 0)
                        . ', remaining=' . (int)($result['remaining'] ?? 0);
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

                header('Location: ?site=configuration#cfg-notification');
                break;
            case 'config_notification_test_slack':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for slack notification test', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
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

                header('Location: ?site=configuration#cfg-notification');
                break;
            case 'config_notification_test_telegram':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for telegram notification test', 2, echoToWeb: true);
                    header('Location: ?site=configuration');
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

                header('Location: ?site=configuration#cfg-notification');
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

                if ($name === '' || $mgmtIp === '' || $profile === '') {
                    $logger->log('automation inventory add failed: name, mgmt_ip and profile are required', 2, echoToWeb: true);
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
                    'profile' => $profile
                ];
                if ($deviceId !== '') {
                    $newSwitch['device_id'] = $deviceId;
                }

                $inventory['switches'][] = $newSwitch;

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => $settings['ssh_host'] ?? '',
                        'ssh_port' => $settings['ssh_port'] ?? 22,
                        'ssh_username' => $settings['ssh_username'] ?? '',
                        'ssh_password' => $settings['ssh_password'] ?? '',
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

                if ($originalName === '' || $name === '' || $mgmtIp === '' || $profile === '') {
                    $logger->log('automation inventory update failed: required fields missing', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $mgmtIp)) {
                    $logger->log('automation inventory update failed: mgmt_ip contains invalid chars', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
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

                $updatedSwitch = [
                    'name' => $name,
                    'mgmt_ip' => $mgmtIp,
                    'profile' => $profile
                ];
                if ($deviceId !== '') {
                    $updatedSwitch['device_id'] = $deviceId;
                }

                $inventory['switches'][$targetIndex] = $updatedSwitch;

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => $settings['ssh_host'] ?? '',
                        'ssh_port' => $settings['ssh_port'] ?? 22,
                        'ssh_username' => $settings['ssh_username'] ?? '',
                        'ssh_password' => $settings['ssh_password'] ?? '',
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
                        'ssh_username' => $settings['ssh_username'] ?? '',
                        'ssh_password' => $settings['ssh_password'] ?? '',
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

                $automationStore = new AutomationStore();
                $structured = loadAutomationStructuredSettings($automationStore);
                $settings = $structured['settings'];
                $scripts = $structured['scripts'];
                $inventory = $structured['inventory'];

                if (!isset($scripts['templates']) || !is_array($scripts['templates'])) {
                    $scripts['templates'] = [];
                }

                $existingTemplate = [];
                if (isset($scripts['templates'][$templateId]) && is_array($scripts['templates'][$templateId])) {
                    $existingTemplate = $scripts['templates'][$templateId];
                }

                $templatePayload = $existingTemplate;
                $templatePayload['label'] = $label;
                $templatePayload['description'] = $description;
                $templatePayload['supported_profiles'] = $supportedProfiles;
                $templatePayload['commands'] = $commands;
                if ($usesDescriptionConvention) {
                    $templatePayload['uses_description_convention'] = true;
                } else {
                    unset($templatePayload['uses_description_convention']);
                }

                $scripts['templates'][$templateId] = $templatePayload;

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => $settings['ssh_host'] ?? '',
                        'ssh_port' => $settings['ssh_port'] ?? 22,
                        'ssh_username' => $settings['ssh_username'] ?? '',
                        'ssh_password' => $settings['ssh_password'] ?? '',
                        'scripts_json' => json_encode($scripts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        'switch_inventory_json' => json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ]);
                    $logger->log('automation template override upserted: ' . $templateId, 1, echoToWeb: true);
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

                $automationStore = new AutomationStore();
                $structured = loadAutomationStructuredSettings($automationStore);
                $settings = $structured['settings'];
                $scripts = $structured['scripts'];
                $inventory = $structured['inventory'];

                if (!isset($scripts['templates'][$templateId])) {
                    $logger->log('automation template delete failed: template not found', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                unset($scripts['templates'][$templateId]);

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => $settings['ssh_host'] ?? '',
                        'ssh_port' => $settings['ssh_port'] ?? 22,
                        'ssh_username' => $settings['ssh_username'] ?? '',
                        'ssh_password' => $settings['ssh_password'] ?? '',
                        'scripts_json' => json_encode($scripts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        'switch_inventory_json' => json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ]);
                    $logger->log('automation template override deleted: ' . $templateId, 1, echoToWeb: true);
                    logAutomationChange($db_adapter, 'DELETE', 'template_delete', [
                        'template_id' => $templateId
                    ]);
                } catch (\Exception $e) {
                    $logger->log('automation template delete failed: ' . $e->getMessage(), 3, echoToWeb: true);
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

                    // Handle scheduler trigger
                    if (isset($_GET['trigger_scheduler']) && $_GET['trigger_scheduler'] === '1') {
                        $logger->log('Manual scheduler trigger initiated', 1);
                        ob_end_clean();
                        passthru('php ' . escapeshellarg(__DIR__ . '/scheduler.php'));
                        echo "\nScheduler execution completed.\n";
                        die();
                    }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation settings', 2, echoToWeb: true);
                    redirectToScriptsTab(getScriptsTabFromRequest());
                    die();
                }

                $automationStore = new AutomationStore();

                try {
                    $automationStore->saveSettings([
                        'ssh_host' => $_POST['ssh_host'] ?? '',
                        'ssh_port' => $_POST['ssh_port'] ?? 22,
                        'ssh_username' => $_POST['ssh_username'] ?? '',
                        'ssh_password' => $_POST['ssh_password'] ?? '',
                        'scripts_json' => $_POST['scripts_json'] ?? '{}',
                        'switch_inventory_json' => $_POST['switch_inventory_json'] ?? '{"switches": []}'
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
                    'ssh_username' => $_POST['ssh_username'] ?? '',
                    'ssh_password' => '',
                    'scripts_json' => $_POST['scripts_json'] ?? '{}',
                    'switch_inventory_json' => $_POST['switch_inventory_json'] ?? '{"switches": []}'
                ];

                $automationTestResult = runAutomationSshTest($_POST, $automationStore, $logger);

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

    .settings-surface {
        background: var(--pf-surface-alt);
        border: 1px solid var(--pf-border);
        border-radius: 1rem;
        padding: 1rem;
        box-shadow: 0 2px 10px rgba(2, 6, 23, 0.25);
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

    .settings-icon-btn {
        height: 2.1rem;
        width: 2.1rem;
        border-radius: 9999px;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
    }

    .settings-icon-btn i[data-lucide] {
        width: 0.95rem;
        height: 0.95rem;
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
            <?php echo ($role == 'admin') ? '<a class="flex-none lg:flex-auto" href="?site=scripts"><li class="' . $settingsNavBaseClasses . ' ' . ($site == 'scripts' ? $settingsNavActiveClasses : '') . '">' . $lang['scripts'] . '</li></a>' : ''; ?>
            <?php if ($role == 'admin' && $site == 'scripts') : ?>
                <div class="settings-subnav mt-0 border-l-2 pl-2 lg:-mt-1" style="border-color: var(--pf-accent-500);">
                    <a href="?site=scripts&tab=switch"><li class="<?php echo $settingsNavSubBaseClasses . ' ' . (($activeScriptsTab === 'switch') ? $settingsNavSubActiveClasses : ''); ?>" data-script-tab="switch">Switch/SSH</li></a>
                    <a href="?site=scripts&tab=templates"><li class="<?php echo $settingsNavSubBaseClasses . ' ' . (($activeScriptsTab === 'templates') ? $settingsNavSubActiveClasses : ''); ?>" data-script-tab="templates">Template Overrides</li></a>
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
        $notificationLevel = (string)($userSettings['notifications']['level'] ?? 'minimal');
        $notificationChannel = (string)($userSettings['notifications']['channel'] ?? 'mail');
        $channelReadiness = getNotificationChannelReadiness();
        $availableChannels = getAvailableNotificationChannels();

        $levelOff = $notificationLevel === 'off' ? 'selected' : '';
        $levelMinimal = $notificationLevel === 'minimal' ? 'selected' : '';
        $levelProgress = $notificationLevel === 'progress' ? 'selected' : '';
        $levelAll = $notificationLevel === 'all' ? 'selected' : '';

        $slackStatus = 'deaktiviert';
        if (!empty($channelReadiness['slack_enabled'])) {
            $slackStatus = !empty($channelReadiness['slack'])
                ? 'bereit'
                : 'aktiv, aber unvollstaendig konfiguriert';
        }

        $telegramStatus = 'deaktiviert';
        if (!empty($channelReadiness['telegram_enabled'])) {
            $telegramStatus = !empty($channelReadiness['telegram'])
                ? 'bereit'
                : 'aktiv, aber unvollstaendig konfiguriert';
        }

        $slackStatusSafe = escapeSettingValue($slackStatus);
        $telegramStatusSafe = escapeSettingValue($telegramStatus);
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
            <div class="settings-surface max-w-3xl">
                <div class="text-xl font-bold pb-2">Benachrichtigungen</div>
                <p class="text-sm text-gray-600 pb-6">Globales Benachrichtigungssystem mit Levels und kanalbasiertem Versand (aktuell: Mail).</p>

                <form action="?set=notification_preferences" method="post" class="space-y-5">
                    <input type="hidden" name="csrf" value="$csrf">

                    <div>
                        <label class="block mb-2 text-sm font-semibold" for="notification_level">Benachrichtigungslevel</label>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="notification_level" name="notification_level">
                            <option value="off" $levelOff>Aus</option>
                            <option value="minimal" $levelMinimal>Minimal (fehlgeschlagene Logins)</option>
                            <option value="progress" $levelProgress>Fortschritt (Minimal + Tageszusammenfassung + Abweichungen)</option>
                            <option value="all" $levelAll>Alles (Fortschritt + erfolgreiche Logins)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block mb-2 text-sm font-semibold" for="notification_channel">Kanal</label>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="notification_channel" name="notification_channel">
                            $channelOptionsHtml
                        </select>
                    </div>

                    <div class="rounded-xl border border-slate-200 px-4 py-3 text-sm text-gray-700">
                        Versandplanung fuer Tageszusammenfassungen wird ueber <span class="font-mono">NOTIFICATION_DAILY_TIME</span> und <span class="font-mono">NOTIFICATION_TIMEZONE</span> in der .env gesteuert.
                    </div>

                    <div class="rounded-xl border border-slate-200 px-4 py-3 text-sm text-gray-700">
                        <div>Kanalstatus:</div>
                        <div>Mail: bereit</div>
                        <div>Slack: $slackStatusSafe</div>
                        <div>Telegram: $telegramStatusSafe</div>
                    </div>

                    <div class="pb-2 flex justify-between items-center">
                        <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Benachrichtigungen speichern">
                    </div>
                </form>
            </div>
        </div>
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

        $notificationCfgDefaults = [
            'notification_daily_time' => (string)NOTIFICATION_DAILY_TIME,
            'notification_timezone'   => (string)NOTIFICATION_TIMEZONE,
            'notification_slack_enabled' => NOTIFICATION_SLACK_ENABLED ? '1' : '0',
            'notification_slack_webhook_url' => (string)NOTIFICATION_SLACK_WEBHOOK_URL,
            'notification_telegram_enabled' => NOTIFICATION_TELEGRAM_ENABLED ? '1' : '0',
            'notification_telegram_bot_token' => (string)NOTIFICATION_TELEGRAM_BOT_TOKEN,
            'notification_telegram_chat_id' => (string)NOTIFICATION_TELEGRAM_CHAT_ID
        ];
        $notificationCfgValues = array_merge($notificationCfgDefaults, is_array($cfgFormData['notification'] ?? null) ? $cfgFormData['notification'] : []);

        $notificationOverview = [
            'counts' => ['total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0],
            'recent_sent' => [],
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

        echo '<section id="cfg-db" class="settings-surface">';
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

        echo '<section id="cfg-ldap" class="settings-surface">';
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

        echo '<section id="cfg-mail" class="settings-surface">';
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

        // Notification configuration section
        $allTimezones = \DateTimeZone::listIdentifiers();
        $currentTz = (string)$notificationCfgValues['notification_timezone'];
        $currentTime = (string)$notificationCfgValues['notification_daily_time'];
        $currentSlackEnabled = configToBool($notificationCfgValues['notification_slack_enabled'] ?? false);
        $currentSlackWebhook = (string)($notificationCfgValues['notification_slack_webhook_url'] ?? '');
        $currentTelegramEnabled = configToBool($notificationCfgValues['notification_telegram_enabled'] ?? false);
        $currentTelegramBotToken = (string)($notificationCfgValues['notification_telegram_bot_token'] ?? '');
        $currentTelegramChatId = (string)($notificationCfgValues['notification_telegram_chat_id'] ?? '');

        echo '<section id="cfg-notification" class="settings-surface">';
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

        echo '<div class="mt-4">';
        echo '<div class="text-sm font-semibold pb-2">Letzte Sends</div>';
        $recentSent = is_array($notificationOverview['recent_sent'] ?? null) ? $notificationOverview['recent_sent'] : [];
        if (!empty($recentSent)) {
            echo '<div class="settings-table-wrap max-h-64 overflow-y-auto"><table class="w-full text-sm text-left">';
            echo '<thead class="bg-gray-100 sticky top-0 z-1"><tr class="border-b border-slate-200 text-gray-800"><th class="p-2">Zeit (UTC)</th><th class="p-2">Event</th><th class="p-2">Titel</th><th class="p-2">Empfaenger</th><th class="p-2">Versuche</th></tr></thead><tbody>';
            foreach ($recentSent as $entry) {
                $sentAt = escapeSettingValue((string)($entry['sent_at'] ?? '-'));
                $eventType = escapeSettingValue((string)($entry['event_type'] ?? '-'));
                $title = escapeSettingValue((string)($entry['title'] ?? '-'));
                $recipientUser = escapeSettingValue((string)($entry['recipient_username'] ?? ''));
                $recipientEmail = escapeSettingValue((string)($entry['recipient_email'] ?? ''));
                $recipient = trim($recipientUser . ' <' . $recipientEmail . '>');
                if ($recipient === '<>' || $recipient === '') {
                    $recipient = '-';
                }
                $attempts = escapeSettingValue((string)($entry['attempts'] ?? 0));
                echo '<tr class="settings-data-row"><td class="p-2 border-b">' . $sentAt . '</td><td class="p-2 border-b">' . $eventType . '</td><td class="p-2 border-b">' . $title . '</td><td class="p-2 border-b">' . $recipient . '</td><td class="p-2 border-b">' . $attempts . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="text-sm text-gray-500">Noch keine versendeten Benachrichtigungen vorhanden.</div>';
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
        echo '<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white">Queue jetzt verarbeiten</button>';
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

        $query = "SELECT users.uuid, users.username, users.email, role.caption AS role, users.login_provider, users.ip_address, CASE WHEN users.activation_code = 'activated' THEN 'activated' ELSE 'deactivated' END AS activation_code, TO_CHAR (users.last_login, 'HH24:MI DD.MM.YYYY') AS last_login, TO_CHAR (users.created, 'HH24:MI DD.MM.YYYY') AS created FROM users INNER JOIN role ON users.role = role.uuid";
        $results = $db_adapter->db_query($query);

        if ($results) {
            echo "<div class='settings-surface'><div class='text-xl font-bold pb-4'>Accounts</div><div class='settings-table-wrap max-h-96 overflow-y-auto'><table class='w-full text-sm text-left'><thead class='bg-gray-100 sticky top-0 z-1'>";
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

            echo "</tbody></table></div></div>";
        } else {
            echo "No results found.";
        }

        $results = $allRoleRows;

        if ($results) {
            echo "<div class='settings-surface'><div class='text-xl font-bold pb-4'>Roles</div><div class='settings-table-wrap max-h-96 overflow-y-auto'><table class='w-full text-sm text-left'><thead class='bg-gray-100 sticky top-0 z-1'>";
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
            echo "</tbody></table></div></div>";
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

        if (!empty($results)) {
            echo "<div class='settings-surface'><div class='text-xl font-bold pb-2'>Access Rights</div>";
            echo "<p class='text-sm text-gray-600 pb-4'>Rechte direkt per Klick setzen: Read (4), Write (2), Execute (1).</p>";
            echo "<div class='settings-table-wrap max-h-96 overflow-y-auto'><table class='w-full text-sm text-left'><thead class='bg-gray-100 sticky top-0 z-1'>";
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
            echo "</tbody></table></div></div>";
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
                    });
                
                    document.getElementById('detailsPopup').classList.remove('hidden');
                    document.getElementById('detailsContent').innerHTML = 'Details for ' + uuid;
                }
                function closeDetailsPopup() {
                    document.getElementById('detailsPopup').classList.add('hidden');
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

        if (is_array($automationFormDataOverride)) {
            $automationSettings = array_merge($automationSettings, $automationFormDataOverride);
        }

        $sshHost = htmlspecialchars((string)($automationSettings['ssh_host'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sshPort = (int)($automationSettings['ssh_port'] ?? 22);
        $sshUsername = htmlspecialchars((string)($automationSettings['ssh_username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $scriptsJson = trim((string)($automationSettings['scripts_json'] ?? ''));
        if ($scriptsJson === '') {
            $scriptsJson = "{}";
        }
        $decodedScriptsConfig = decodeJsonObject($scriptsJson, []);
        if (!isset($decodedScriptsConfig['templates']) || !is_array($decodedScriptsConfig['templates'])) {
            $decodedScriptsConfig['templates'] = [];
        }
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
        $activeScriptsTab = getScriptsTabFromRequest();

        $historyRows = [];
        try {
            $historyRows = $db_adapter->db_query(
                "SELECT c.operation, c.changed_table, c.changed_data, TO_CHAR(c.changed, 'DD.MM.YYYY HH24:MI:SS') AS changed_at, u.username
                 FROM changelog c
                 LEFT JOIN users u ON u.uuid = c.users
                 WHERE c.changed_table IN ('automation_settings', 'script_execution')
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

        $automationConfig = new Automation();
        $availableProfiles = array_keys($automationConfig->getProfiles());
        if (empty($availableProfiles)) {
            $availableProfiles = ['huawei_core_commit', 'huawei_access_no_commit'];
        }

        $profileOptionsHtml = '';
        foreach ($availableProfiles as $profileId) {
            $profileEscaped = htmlspecialchars((string)$profileId, ENT_QUOTES, 'UTF-8');
            $profileOptionsHtml .= "<option value=\"{$profileEscaped}\">{$profileEscaped}</option>";
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
            $nameDataEscaped = htmlspecialchars((string)($switchItem['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $mgmtIpDataEscaped = htmlspecialchars((string)($switchItem['mgmt_ip'] ?? ''), ENT_QUOTES, 'UTF-8');
            $profileDataEscaped = htmlspecialchars((string)($switchItem['profile'] ?? ''), ENT_QUOTES, 'UTF-8');
            $deviceDataEscaped = htmlspecialchars((string)($switchItem['device_id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $indexValue = (int)$index;

            $inventoryRowsHtml .= <<<HTML
                <tr class="border-b border-gray-100">
                    <td class="py-2 px-3 font-medium text-gray-900">{$nameEscaped}</td>
                    <td class="py-2 px-3 font-mono text-sm text-gray-700">{$mgmtIpEscaped}</td>
                    <td class="py-2 px-3"><span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold">{$profileEscaped}</span></td>
                    <td class="py-2 px-3 text-sm text-gray-700">{$deviceEscaped}</td>
                    <td class="py-2 px-3 text-right">
                        <button class="settings-icon-btn bg-emerald-500 hover:bg-emerald-700 mr-2" type="button" title="SSH testen" aria-label="SSH testen" data-switch-name="{$nameDataEscaped}" data-switch-mgmt-ip="{$mgmtIpDataEscaped}" onclick="submitInventorySshTest(this)"><i data-lucide="terminal"></i></button>
                        <button class="settings-icon-btn bg-amber-500 hover:bg-amber-700 mr-2" type="button" title="Bearbeiten" aria-label="Bearbeiten" data-switch-name="{$nameDataEscaped}" data-switch-mgmt-ip="{$mgmtIpDataEscaped}" data-switch-profile="{$profileDataEscaped}" data-switch-device-id="{$deviceDataEscaped}" onclick="loadInventoryEntry(this)"><i data-lucide="pencil"></i></button>
                        <button class="settings-icon-btn bg-red-500 hover:bg-red-700" type="button" title="Loeschen" aria-label="Loeschen" onclick="submitInventoryDelete({$indexValue})"><i data-lucide="trash-2"></i></button>
                    </td>
                </tr>
            HTML;
        }
        if ($inventoryRowsHtml === '') {
            $inventoryRowsHtml = '<tr><td colspan="5" class="py-4 px-3 text-sm text-gray-500">Noch keine Switch-Eintraege vorhanden.</td></tr>';
        }

        $templateRowsHtml = '';
        foreach ($decodedScriptsConfig['templates'] as $templateId => $templateOverride) {
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
            $testOutputHtml = "<div class=\"rounded-2xl border p-4 {$testStateClass}\"><div class=\"text-sm font-semibold pb-2\">SSH Test Output</div><pre class=\"text-xs whitespace-pre-wrap leading-5\">{$testOutputEscaped}</pre></div>";
        }

        echo <<<HTML
        <div class="grid grid-cols-1 gap-6">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
                <div class="text-xl font-bold pb-2">Automation: Secure Settings</div>
                <p class="text-sm text-gray-600 pb-6">SSH-Zugangsdaten und Skript-Overrides werden verschluesselt in <span class="font-semibold">data/automation/settings.json</span> gespeichert.</p>
                {$testOutputHtml}
                <form action="?set=automation_scripts" method="post">
                    <input type="hidden" name="csrf" value="$csrf">
                    <input type="hidden" id="scripts_active_tab" name="scripts_active_tab" value="$activeScriptsTab">

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
                            <label class="block mb-2 text-sm font-semibold" for="ssh_username">SSH Username</label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="ssh_username" type="text" name="ssh_username" value="$sshUsername" placeholder="netadmin">
                        </div>
                        <div>
                            <label class="block mb-2 text-sm font-semibold" for="ssh_password">SSH Password</label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="ssh_password" type="password" name="ssh_password" placeholder="$passwordHint">
                            <p class="text-xs text-gray-500 mt-2">$passwordHint</p>
                        </div>
                    </div>

                    <div class="pb-6 scripts-section-switch">
                        <div class="flex items-center justify-between pb-2">
                            <label class="block text-sm font-semibold">Switch Inventory (Grafische Verwaltung)</label>
                        </div>
                        <div class="border border-gray-200 rounded-2xl overflow-hidden">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-gray-50 text-gray-700">
                                    <tr>
                                        <th class="py-2 px-3">Name</th>
                                        <th class="py-2 px-3">Mgmt IP</th>
                                        <th class="py-2 px-3">Profil</th>
                                        <th class="py-2 px-3">Device ID</th>
                                        <th class="py-2 px-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {$inventoryRowsHtml}
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">Erforderlich pro Switch: name, mgmt_ip, profile. Optional: device_id (UUID des verknuepften ITAM-Geraets).</p>

                        <input type="hidden" id="switch_original_name" value="">
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-6 gap-3">
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="text" id="switch_name" placeholder="SW-Core-01" required>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="text" id="switch_mgmt_ip" placeholder="10.0.0.10" required>
                            <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="switch_profile" required>
                                {$profileOptionsHtml}
                            </select>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" type="text" id="switch_device_id" placeholder="optional UUID">
                            <button id="inventory_submit_button" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="submitInventorySave()">Switch hinzufuegen</button>
                            <button class="bg-gray-600 hover:bg-gray-800 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="button" onclick="resetInventoryForm()">Formular leeren</button>
                        </div>
                    </div>

                    <div class="pb-6 scripts-section-template">
                        <div class="flex items-center justify-between pb-2">
                            <label class="block text-sm font-semibold">Template Overrides (Grafische Verwaltung)</label>
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
                                <p class="text-xs text-gray-500 mt-2">Erlaubte Bereiche: description_convention, profiles, templates. Diese Daten erweitern die Basisdatei aus includes/core/automation.json.</p>
                            </div>
                        </div>
                    </details>

                    <div class="pb-2 flex justify-end items-center gap-4 scripts-section-switch">
                        <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Automation speichern">
                    </div>
                </form>

                <script>
                    const automationCsrfToken = '$csrf';
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

                        var submitButton = document.getElementById('inventory_submit_button');
                        if (submitButton) {
                            submitButton.textContent = 'Switch aktualisieren';
                            submitButton.className = 'bg-amber-500 hover:bg-amber-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline';
                        }
                    }

                    function resetInventoryForm() {
                        document.getElementById('switch_original_name').value = '';
                        document.getElementById('switch_name').value = '';
                        document.getElementById('switch_mgmt_ip').value = '';
                        document.getElementById('switch_profile').selectedIndex = 0;
                        document.getElementById('switch_device_id').value = '';

                        var submitButton = document.getElementById('inventory_submit_button');
                        if (submitButton) {
                            submitButton.textContent = 'Switch hinzufuegen';
                            submitButton.className = 'bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline';
                        }
                    }

                    function submitInventorySave() {
                        const name = document.getElementById('switch_name').value.trim();
                        const mgmtIp = document.getElementById('switch_mgmt_ip').value.trim();
                        const profile = document.getElementById('switch_profile').value.trim();
                        const deviceId = document.getElementById('switch_device_id').value.trim();
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
                            switch_device_id: deviceId
                        });
                    }

                    function submitInventoryDelete(index) {
                        if (!confirm('Switch-Eintrag wirklich loeschen?')) {
                            return;
                        }
                        postAutomationAction('automation_inventory_delete', {
                            inventory_index: String(index)
                        });
                    }

                    function submitInventorySshTest(button) {
                        const name = button.dataset.switchName || '';
                        const mgmtIp = button.dataset.switchMgmtIp || '';

                        if (name === '' || mgmtIp === '') {
                            alert('Switch-Daten fuer den SSH-Test konnten nicht gelesen werden.');
                            return;
                        }

                        if (!confirm('SSH-Verbindung fuer ' + name + ' (' + mgmtIp + ') testen?')) {
                            return;
                        }

                        postAutomationAction('automation_test_ssh', {
                            ssh_host: mgmtIp,
                            ssh_username: document.getElementById('ssh_username').value,
                            ssh_port: document.getElementById('ssh_port').value,
                            ssh_password: document.getElementById('ssh_password').value,
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
                        if (!confirm('Template Override wirklich loeschen?')) {
                            return;
                        }
                        postAutomationAction('automation_template_delete', {
                            template_id: templateId
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
                        const resolvedTab = (tabId === 'templates' || tabId === 'history') ? tabId : 'switch';
                        currentScriptsTab = resolvedTab;

                        const hiddenTabInput = document.getElementById('scripts_active_tab');
                        if (hiddenTabInput) {
                            hiddenTabInput.value = resolvedTab;
                        }

                        const showSwitch = (resolvedTab === 'switch');
                        const showTemplates = (resolvedTab === 'templates');
                        const showHistory = (resolvedTab === 'history');

                        document.querySelectorAll('.scripts-section-switch').forEach(function(el) {
                            el.classList.toggle('hidden', !showSwitch);
                        });
                        document.querySelectorAll('.scripts-section-template').forEach(function(el) {
                            el.classList.toggle('hidden', !showTemplates);
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
                </script>
            </div>
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
            <div class="settings-surface max-w-3xl">
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
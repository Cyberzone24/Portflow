<?php
namespace Portflow\Core;

ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

// check if APP_NAME is defined
if (!defined('APP_NAME')) {
    die('Access denied');
}

// import db_adapter
include_once __DIR__ . '/db_adapter.php';
use Portflow\Core\DatabaseAdapter;

// import mail
include_once __DIR__ . '/mail.php';
use Portflow\Core\Mail;

// import notification center
include_once __DIR__ . '/notification_center.php';
use Portflow\Core\NotificationCenter;

class Auth {
    // define class variables
    private $logger;
    private $db_adapter;
    private $mail;
    private $notificationCenter;
    // form data
    private $csrf;
    private $username;
    private $password;
    private $email;
    private $role;
    // local_signin / local_signup
    private $uuid;
    private $password_db;
    private $settings;
    private $login_attempts;
    // ldap_signin
    private $ldap_server;
    private $ldap_port;
    private $ldap_basedn;
    private $ldap_userdn;
    private $ldap_found_user_dn;
    private $ldap_binduser_dn;
    private $activation_code;
    private $pendingWebAuthIssue;
    private $password_confirm;

    public function __construct() {
        // define post variables
        $this->csrf = $_POST['csrf'] ?? NULL;
        $this->username = $_POST['username'] ?? NULL;
        $this->password = $_POST['password'] ?? NULL;
        $this->password_confirm = $_POST['password_confirm'] ?? NULL;
        $this->email = $_POST['email'] ?? NULL;
        $this->role = $_POST['role'] ?? NULL;

        // create logger and db_adapter
        $this->logger = new Logger();
        $this->db_adapter = new DatabaseAdapter();
        $this->mail = new Mail();
        $this->notificationCenter = new NotificationCenter($this->db_adapter, $this->logger, $this->mail);
    }

    private function notifyUsers(array $userUuids, string $eventType, string $level, string $title, string $message, array $meta = []): void {
        try {
            if ($this->notificationCenter instanceof NotificationCenter) {
                $this->notificationCenter->enqueueForUsers($userUuids, $eventType, $level, $title, $message, $meta);
            }
        } catch (\Throwable $e) {
            $this->logger->log('targeted notification enqueue failed: ' . $e->getMessage(), 0);
        }
    }

    private function decodeSettings($settings): array {
        if (is_array($settings)) {
            return $settings;
        }

        if (!is_string($settings) || trim($settings) === '') {
            return [];
        }

        $decoded = json_decode($settings, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeSettings(array $settings): string {
        return json_encode($settings, JSON_UNESCAPED_SLASHES);
    }

    private function normalizeEnvValue(string $value): string {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        return preg_replace('/[\r\n]+/', ' ', $normalized) ?? $normalized;
    }

    private function writeRootEnvValues(array $updates): array {
        $envPath = dirname(__DIR__, 2) . '/.env';
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
            $normalizedUpdates[$normalizedKey] = $this->normalizeEnvValue((string)$value);
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

    private function escapeLdapFilterValue(string $value): string {
        if (function_exists('ldap_escape')) {
            return (string)ldap_escape($value, '', LDAP_ESCAPE_FILTER);
        }

        return strtr($value, [
            '\\' => '\\5c',
            '*' => '\\2a',
            '(' => '\\28',
            ')' => '\\29',
            "\x00" => '\\00'
        ]);
    }

    private function clearPasswordResetFromSettings(array $settings): array {
        if (isset($settings['password_reset'])) {
            unset($settings['password_reset']);
        }

        return $settings;
    }
    private const LOGIN_ATTEMPT_LIMIT = 3;
    private const LOGIN_COOLDOWN_SECONDS = 900;

    private function validateForgotPasswordRequest(): void {
        if (mb_strlen((string)$this->username) > 255 || mb_strlen((string)$this->username) < 2) {
            $this->logger->log('username length not correct', 2);
            throw new \InvalidArgumentException('username length not correct');
        }
        if (!isset($this->username, $this->email)) {
            $this->logger->log('username or email not set', 2);
            throw new \InvalidArgumentException('username or email not set');
        }
        if (empty($this->username) || empty($this->email)) {
            $this->logger->log('username or email empty', 2);
            throw new \InvalidArgumentException('username or email empty');
        }
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->log('email not valid', 2);
            throw new \InvalidArgumentException('email not valid');
        }
    }

    private function validatePasswordResetSubmission(string $token, string $email): void {
        if ($token === '' || $email === '') {
            throw new \InvalidArgumentException('password reset token or e-mail missing');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('email not valid');
        }
        if (!isset($this->password, $this->password_confirm)) {
            throw new \InvalidArgumentException('password not set');
        }
        if ($this->password === '' || $this->password_confirm === '') {
            throw new \InvalidArgumentException('password empty');
        }
        if (mb_strlen((string)$this->password) > 128 || mb_strlen((string)$this->password) < 8) {
            throw new \InvalidArgumentException('password length not correct');
        }
        if (!hash_equals((string)$this->password, (string)$this->password_confirm)) {
            throw new \InvalidArgumentException('password confirmation does not match');
        }
    }

    private function findLocalUserForPasswordResetRequest(): ?array {
        $query = "SELECT uuid, username, email, settings FROM users WHERE username = :username AND email = :email AND login_provider = :login_provider AND activation_code = :activation_code LIMIT 1";
        $rows = $this->db_adapter->db_query($query, [
            'username' => $this->username,
            'email' => $this->email,
            'login_provider' => 'local',
            'activation_code' => 'activated'
        ]);

        return !empty($rows) ? $rows[0] : null;
    }

    private function findLocalUserByResetEmail(string $email): ?array {
        $query = "SELECT uuid, username, email, settings, activation_code FROM users WHERE email = :email AND login_provider = :login_provider LIMIT 1";
        $rows = $this->db_adapter->db_query($query, ['email' => $email, 'login_provider' => 'local']);
        return !empty($rows) ? $rows[0] : null;
    }

    private function persistPasswordResetRequest(array $userRow, string $token): bool {
        $settings = $this->decodeSettings($userRow['settings'] ?? null);
        $settings['password_reset'] = [
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('c', time() + 3600),
            'requested_at' => gmdate('c')
        ];

        $query = "UPDATE users SET settings = :settings, changed = NOW() WHERE uuid = :uuid";
        $result = $this->db_adapter->db_query($query, [
            'settings' => $this->encodeSettings($settings),
            'uuid' => $userRow['uuid']
        ]);

        return $result !== false;
    }

    private function consumePasswordResetRequest(array $userRow): void {
        $settings = $this->decodeSettings($userRow['settings'] ?? null);
        $settings = $this->clearPasswordResetFromSettings($settings);
        $passwordHash = password_hash((string)$this->password, PASSWORD_DEFAULT);

        $query = "UPDATE users SET password = :password, settings = :settings, activation_code = :activation_code, login_attempts = :login_attempts, changed = NOW() WHERE uuid = :uuid";
        $this->db_adapter->db_query($query, [
            'password' => $passwordHash,
            'settings' => $this->encodeSettings($settings),
            'activation_code' => 'activated',
            'login_attempts' => null,
            'uuid' => $userRow['uuid']
        ]);
    }

    private function isPasswordResetTokenValid(array $userRow, string $token): bool {
        $settings = $this->decodeSettings($userRow['settings'] ?? null);
        $resetState = $settings['password_reset'] ?? null;
        if (!is_array($resetState)) {
            return false;
        }

        $tokenHash = (string)($resetState['token_hash'] ?? '');
        $expiresAt = (string)($resetState['expires_at'] ?? '');
        if ($tokenHash === '' || $expiresAt === '') {
            return false;
        }

        if (!hash_equals($tokenHash, hash('sha256', $token))) {
            return false;
        }

        $expiryTimestamp = strtotime($expiresAt);
        if ($expiryTimestamp === false || $expiryTimestamp < time()) {
            return false;
        }

        return true;
    }

    public function isPasswordResetLinkValid(string $token, string $email): bool {
        if ($token === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $userRow = $this->findLocalUserByResetEmail($email);
            if ($userRow === null) {
                return false;
            }

            return $this->isPasswordResetTokenValid($userRow, $token);
        } catch (\Throwable $e) {
            $this->logger->log('password reset link validation failed: ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function resolveActivatedUserUuidByUsername(string $username): ?string {
        $candidate = trim($username);
        if ($candidate === '') {
            return null;
        }

        try {
            $rows = $this->db_adapter->db_query(
                "SELECT uuid FROM users WHERE username = :username AND activation_code = :activation_code LIMIT 1",
                [
                    'username' => $candidate,
                    'activation_code' => 'activated'
                ]
            );
            if (!is_array($rows) || empty($rows[0]['uuid'])) {
                return null;
            }

            return (string)$rows[0]['uuid'];
        } catch (\Throwable $e) {
            $this->logger->log('resolve activated user uuid failed: ' . $e->getMessage(), 0);
            return null;
        }
    }

    public function csrf($token = NULL) {    
        // Create CSRF token
        if ($token == NULL) {
            // Current timestamp
            $timestamp = time();
            // Generate random bytes
            $randomBytes = random_bytes(32);
            // Combine timestamp and random bytes
            $token = bin2hex($randomBytes) . ':' . $timestamp;
            // Store token in session
            $_SESSION['csrf'] = $token;
            return $token;
        }
    }

    public function csrf_check() {
        // Check CSRF token
        if (!isset($this->csrf) || empty($this->csrf)) {
            $this->logger->log('csrf token not present in post request', 2);
            return false;
        }

        // Split token and timestamp from POST data
        list($tokenValue, $tokenTimestamp) = explode(':', $this->csrf);
        
        // Check if session token is set and split token and timestamp from session data
        if (!isset($_SESSION['csrf']) || !strpos($_SESSION['csrf'], ':')) {
            $this->logger->log('csrf token not set in session or invalid format', 2);
            return false;
        }
        list($sessionTokenValue, $sessionTokenTimestamp) = explode(':', $_SESSION['csrf']);

        // Check if the token matches and is not expired
        if ($tokenValue !== $sessionTokenValue) {
            $this->logger->log("csrf token incorrect", 2);
            return false;
        }
        if ($tokenTimestamp !== $sessionTokenTimestamp) {
            $this->logger->log("csrf token doesn't match session timestamp", 2);
            return false;
        }

        // Check if the token is expired (5 minutes = 300 seconds)
        if (time() - $tokenTimestamp > 300) {
            $this->logger->log('csrf token expired', 2);
            return false;
        }

        return true;
    }

    /**
     * Headless authentication entry point for JSON API / CLI requests.
     *
     * This intentionally reuses the SAME provider-specific signin paths as
     * the interactive web flow and only suppresses web-only behaviour such as
     * redirect targets and CSRF/form coupling. That keeps the database checks,
     * activation logic, login-attempt handling and notifications aligned
     * between browser and API authentication.
     */
    public function apiSignin(string $username, string $password): bool
    {
        $this->username = $username;
        $this->password = $password;

        if ($username === '' || $password === '') {
            return false;
        }

        if ($this->local_signin(true)) {
            return true;
        }

        if (defined('LDAP_ENABLED') && LDAP_ENABLED === true && $this->ldap_signin(true)) {
            return true;
        }

        return false;
    }

    /**
     * Populate $_SESSION for both interactive and headless signins.
     * The provider is stored so downstream API code can tell whether the
     * session originated from local auth or LDAP without rebuilding context.
     */
    private function establishAuthenticatedSession(string $provider): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (function_exists('portflow_apply_session_cookie_settings')) {
                \portflow_apply_session_cookie_settings();
            } else {
                $cookieParams = session_get_cookie_params();
                session_set_cookie_params([
                    'lifetime' => $cookieParams['lifetime'],
                    'path' => $cookieParams['path'],
                    'domain' => $cookieParams['domain'],
                    'secure' => defined('PORTFLOW_SECURE') ? PORTFLOW_SECURE : false,
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            }
            @session_start();
        }
        @session_regenerate_id(true);
        $_SESSION['loggedin']    = true;
        $_SESSION['name']        = $this->username;
        $_SESSION['uuid']        = $this->uuid;
        $_SESSION['settings']    = $this->settings;
        $_SESSION['auth_method'] = $provider;
    }

    private function redirectAfterSignin(): void
    {
        if (isset($_SESSION['referrer']) && strpos($_SESSION['referrer'], PORTFLOW_HOSTNAME) === 0) {
            header('Location: ' . $_SESSION['referrer']);
            return;
        }

        header('Location: ' . PORTFLOW_HOSTNAME . '/portview.php');
    }

    private function resetWebAuthIssue(): void
    {
        $this->pendingWebAuthIssue = null;
    }

    private function rememberWebAuthIssue(string $message, int $level = 2, bool $stopFlow = false): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        if (!is_array($this->pendingWebAuthIssue)) {
            $this->pendingWebAuthIssue = [
                'message' => $message,
                'level' => $level,
                'stopFlow' => $stopFlow,
            ];
            return;
        }

        if (!$this->pendingWebAuthIssue['stopFlow'] && $stopFlow) {
            $this->pendingWebAuthIssue = [
                'message' => $message,
                'level' => $level,
                'stopFlow' => true,
            ];
        }
    }

    private function hasBlockingWebAuthIssue(): bool
    {
        return is_array($this->pendingWebAuthIssue) && !empty($this->pendingWebAuthIssue['stopFlow']);
    }

    private function flushWebAuthIssue(string $fallbackMessage, int $fallbackLevel = 2): void
    {
        if (is_array($this->pendingWebAuthIssue)) {
            $this->logger->log(
                (string)$this->pendingWebAuthIssue['message'],
                (int)$this->pendingWebAuthIssue['level'],
                echoToWeb: true
            );
            $this->resetWebAuthIssue();
            return;
        }

        $this->logger->log($fallbackMessage, $fallbackLevel, echoToWeb: true);
    }

    private function resolveWebAuthIssueLevel(\Throwable $exception): int
    {
        if ($exception instanceof \InvalidArgumentException) {
            return 2;
        }

        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'database')
            || str_contains($message, 'ldap role')
            || str_contains($message, 'no connection')
            || str_contains($message, "couldn't")
            || str_contains($message, 'failed to create')) {
            return 3;
        }

        return 2;
    }

    private function logWebAuthException(\Throwable $exception): void
    {
        $this->logger->log(
            $exception->getMessage(),
            $this->resolveWebAuthIssueLevel($exception),
            echoToWeb: true
        );
    }

    private function local_signon(string $usage): void {
        // Validation must fail hard here. The callers already decide whether
        // a bad input should become a web error, a failed login, or an API 401.
        if (mb_strlen((string)$this->username) > 255 || mb_strlen((string)$this->username) < 2) {
            $this->logger->log('username length not correct', 2);
            throw new \InvalidArgumentException('username length not correct');
        }

        if ($usage == 'signin') {
            if (mb_strlen((string)$this->password) > 128 || mb_strlen((string)$this->password) < 8) {
                $this->logger->log('password length not correct', 2);
                throw new \InvalidArgumentException('password length not correct');
            }
            if (!isset($this->username, $this->password)) {
                $this->logger->log('username or password not set', 2);
                throw new \InvalidArgumentException('username or password not set');
            }
            if (empty($this->username) || empty($this->password)) {
                $this->logger->log('username or password empty', 2);
                throw new \InvalidArgumentException('username or password empty');
            }
        } elseif ($usage == 'signup') {
            if (mb_strlen((string)$this->password) > 128 || mb_strlen((string)$this->password) < 8) {
                $this->logger->log('password length not correct', 2);
                throw new \InvalidArgumentException('password length not correct');
            }
            if (!isset($this->username, $this->password, $this->email)) {
                $this->logger->log('username, password or email not set', 2);
                throw new \InvalidArgumentException('username, password or email not set');
            }
            if (empty($this->username) || empty($this->password) || empty($this->email)) {
                $this->logger->log('username, password or email empty', 2);
                throw new \InvalidArgumentException('username, password or email empty');
            }
            if(!filter_var($this->email, FILTER_VALIDATE_EMAIL)){
                $this->logger->log('email not valid', 2);
                throw new \InvalidArgumentException('email not valid');
            }
        } elseif ($usage == 'forgot_password') {
            $this->validateForgotPasswordRequest();
        } else {
            $this->logger->log('invalid usage of local_signon function', 3);
            throw new \Exception('invalid usage of local_signon function');
        }

        $this->logger->log('submitted post data correct', 1);
    }

    private function single_signon(): void {
        if (mb_strlen((string)$this->password) > 1024 || mb_strlen((string)$this->password) < 1) {
            $this->logger->log('password length not correct', 2);
            throw new \InvalidArgumentException('password length not correct');
        }
        if (mb_strlen((string)$this->username) > 1024 || mb_strlen((string)$this->username) < 1) {
            $this->logger->log('username length not correct', 2);
            throw new \InvalidArgumentException('username length not correct');
        }
        if (!isset($this->username, $this->password)) {
            $this->logger->log('username or password not set', 2);
            throw new \InvalidArgumentException('username or password not set');
        }
        if (empty($this->username) || empty($this->password)) {
            $this->logger->log('username or password empty', 2);
            throw new \InvalidArgumentException('username or password empty');
        }
        $this->logger->log('submitted post data correct', 1);
    }

    private function ip(){
        // get user ip
        $ip = (isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : isset($_SERVER['HTTP_X_FORWARDED_FOR'])) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
        return $ip;
    }

    public function random_string($length) {
        // generate random string
        return substr(str_shuffle(MD5(microtime())), 0, $length);
    }

    public function signin() {
        $this->resetWebAuthIssue();

        try {
            if ($this->csrf_check()) {
                $this->logger->log('CSRF token correct', 1);
            } else {
                throw new \InvalidArgumentException('CSRF token not correct');
            }

            if ($this->local_signin()) {
                return true;
            }
            $this->logger->log("local_signin of '$this->username' failed", 3);

            if ($this->hasBlockingWebAuthIssue()) {
                $this->flushWebAuthIssue('all available signin methods failed', 2);
                header("Location: " . PORTFLOW_HOSTNAME);
                exit();
            }

            if (LDAP_ENABLED == TRUE) {
                $ldap_signin_result = $this->ldap_signin();
                if ($ldap_signin_result) {
                    return true;
                }
                $this->logger->log("ldap_signin of '$this->username' failed", 3);
            }

            $this->flushWebAuthIssue('all available signin methods failed', 2);
            header("Location: " . PORTFLOW_HOSTNAME);
            exit();
        } catch (\Exception $e) {
            $this->logWebAuthException($e);
            header("Location: " . PORTFLOW_HOSTNAME);
            exit();
        }
    }

    private function canAttemptLogin($loginAttempts, $lastLoginAttempt): bool {
        $attempts = (int)($loginAttempts ?? 0);
        if ($attempts <= self::LOGIN_ATTEMPT_LIMIT) {
            return true;
        }

        $lastAttemptTs = is_string($lastLoginAttempt) ? strtotime($lastLoginAttempt) : false;
        if ($lastAttemptTs === false) {
            return true;
        }

        return (time() - $lastAttemptTs) >= self::LOGIN_COOLDOWN_SECONDS;
    }

    private function resetLoginAttempts(string $uuid): void {
        $this->db_adapter->db_query(
            "UPDATE users SET login_attempts = :login_attempts WHERE uuid = :uuid",
            ['login_attempts' => null, 'uuid' => $uuid]
        );
    }

    private function recordFailedLoginAttempt(string $uuid, int $nextAttempts): bool {
        $result = $this->db_adapter->db_query(
            "UPDATE users SET last_login_attempt = NOW(), login_attempts = :login_attempts, ip_address = :ip_address WHERE uuid = :uuid",
            ['login_attempts' => $nextAttempts, 'ip_address' => $this->ip(), 'uuid' => $uuid]
        );

        return !empty($result);
    }

    private function local_signin(bool $headless = false) {
        try {
            if (!$this->db_adapter->checkDatabaseAndTableExistence('users')) {
                die("the database table 'users' doesn't exist. please run the init script.");
            }

            if ($headless) {
                if (!isset($this->username, $this->password) || $this->username === '' || $this->password === '') {
                    throw new \Exception('username or password empty');
                }
            } else {
                $this->local_signon('signin');
            }

            $query = "SELECT uuid, password, email, settings, login_attempts, last_login_attempt FROM users WHERE username = :username AND activation_code = :activation_code";
            $result = $this->db_adapter->db_query($query, ['username' => $this->username, 'activation_code' => 'activated']);
            $result = !empty($result) ? $result[0] : null;
            $this->logger->log('checking if user exists and account is activated');

            if (empty($result)) {
                return false;
            }

            $this->logger->log('user exists and account is activated', 1);

            $this->uuid = $result['uuid'];
            $this->password_db = $result['password'];
            $this->settings = $result['settings'];
            $this->login_attempts = $result['login_attempts'];

            if (!$this->canAttemptLogin($this->login_attempts, $result['last_login_attempt'] ?? null)) {
                $this->logger->log('login cooldown active', 2);
                $this->notifyUsers(
                    [$this->uuid],
                    'login_failed',
                    'minimal',
                    'Fehlgeschlagener Login',
                    "Loginversuch blockiert (Cooldown aktiv) fuer Benutzer '{$this->username}'.",
                    ['username' => $this->username, 'ip' => $this->ip(), 'provider' => 'local']
                );
                throw new \Exception('login attempts exceeded');
            }

            if ((int)($this->login_attempts ?? 0) > self::LOGIN_ATTEMPT_LIMIT) {
                $this->resetLoginAttempts((string)$this->uuid);
                $this->login_attempts = null;
            }

            if (!password_verify($this->password, $this->password_db)) {
                $nextAttempts = (int)($this->login_attempts ?? 0) + 1;
                $result = $this->recordFailedLoginAttempt((string)$this->uuid, $nextAttempts);
                $this->logger->log('updating database');

                if (!empty($result)) {
                    $this->logger->log('incorrect password', 1);
                    $this->notifyUsers(
                        [$this->uuid],
                        'login_failed',
                        'minimal',
                        'Fehlgeschlagener Login',
                        "Fehlgeschlagener Login fuer Benutzer '{$this->username}' (lokal).",
                        ['username' => $this->username, 'ip' => $this->ip(), 'provider' => 'local']
                    );
                    throw new \Exception('incorrect password');
                }

                $this->logger->log("incorrect password is. database couldn't update", 3);
                throw new \Exception("incorrect password is. database couldn't update");
            }

            $this->establishAuthenticatedSession('local');
            $query = "UPDATE users SET last_login = NOW(), login_attempts = :login_attempts, ip_address = :ip_address WHERE uuid = :uuid";
            $result = $this->db_adapter->db_query($query, ['login_attempts' => null, 'ip_address' => $this->ip(), 'uuid' => $this->uuid]);

            if (empty($result)) {
                $this->logger->log("user logged in, but database couldn't update", 3);
                throw new \Exception("user logged in, but database couldn't update");
            }

            $this->logger->log("user '$this->username' logged in. database updated", 1);
            $this->notifyUsers(
                [$this->uuid],
                'login_success',
                'all',
                'Erfolgreicher Login',
                "Erfolgreicher Login fuer Benutzer '{$this->username}'.",
                ['username' => $this->username, 'ip' => $this->ip(), 'provider' => 'local']
            );
            if (!$headless) {
                $this->redirectAfterSignin();
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->log($e->getMessage(), 3);
            if (!$headless) {
                $this->rememberWebAuthIssue($e->getMessage(), $this->resolveWebAuthIssueLevel($e), $e instanceof \InvalidArgumentException);
            }
            return false;
        }
    }

    private function ldap_signin(bool $headless = false) {
        $ldap_connection = null;

        try {
            if ($headless) {
                if (!isset($this->username, $this->password) || $this->username === '' || $this->password === '') {
                    throw new \Exception('username or password empty');
                }
            } else {
                $this->single_signon();
            }

            $this->ldap_server = LDAP_SERVER;
            $this->ldap_port = LDAP_PORT;
            $this->ldap_basedn = LDAP_BASEDN;
            $this->ldap_userdn = LDAP_USERDN;
            $ldap_configFilter = LDAP_FILTER;
            $this->ldap_binduser_dn = 'uid=' . $this->username . ',' . $this->ldap_userdn . ',' . $this->ldap_basedn;

            $ldap_connection = @ldap_connect($this->ldap_server, $this->ldap_port);
            if (!$ldap_connection) {
                throw new \Exception("ldap_signin no connection to '$this->ldap_server'");
            }
            if (empty($this->password)) {
                throw new \Exception('Password field cannot be empty');
            }

            ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap_connection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ldap_connection, LDAP_OPT_NETWORK_TIMEOUT, 10);

            if (defined('LDAP_BIND') && LDAP_BIND) {
                $ldap_bind = @ldap_bind($ldap_connection, LDAP_BIND_USER, LDAP_BIND_PASSWORD);
                $this->logger->log('trying to bind with ' . LDAP_BIND_USER, 0);
            } else {
                $ldap_bind = @ldap_bind($ldap_connection, $this->ldap_binduser_dn, $this->password);
                $this->logger->log('trying to bind with ' . $this->username, 0);
            }
            if (!$ldap_bind) {
                throw new \Exception('bind failed');
            }

            $escapedUsername = $this->escapeLdapFilterValue((string)$this->username);
            $filter = "(&(|(sAMAccountName=$escapedUsername)(uid=$escapedUsername))$ldap_configFilter)";
            $attributes = ['displayname', 'mail', 'samaccountname', 'title', 'telephoneNumber', 'initials', 'physicalDeliveryOfficeName', 'department', 'accountExpires', 'lastLogonTimestamp', 'memberOf'];
            $res_id = ldap_search($ldap_connection, $this->ldap_basedn, $filter, $attributes);
            if (!$res_id) {
                throw new \Exception('no user with ldap filter: \'' . $filter . '\' found');
            }

            $user_entries = ldap_get_entries($ldap_connection, $res_id);
            $this->logger->log("user entrys count: '" . $user_entries['count'] . "'", 0);

            if ($user_entries['count'] != 1) {
                $this->logger->log("no entrys found for user: '$this->username' with filter: " . $filter, 0);
                return false;
            }

            $this->logger->log("user '$this->username' matched with filter: '" . $filter . "'", 0);
            if (!isset($user_entries[0]['dn'])) {
                $this->logger->log('user_dn not found', 3);
                return false;
            }

            $this->ldap_found_user_dn = $user_entries[0]['dn'];
            $this->logger->log('trying to bind with: ' . $this->ldap_found_user_dn, 0);
            if (!@ldap_bind($ldap_connection, $this->ldap_found_user_dn, $this->password)) {
                $this->logger->log('password verification failed', 2);
                $resolvedUuid = $this->resolveActivatedUserUuidByUsername((string)$this->username);
                if ($resolvedUuid !== null) {
                    $this->recordFailedLoginAttempt($resolvedUuid, 1);
                    $this->notifyUsers(
                        [$resolvedUuid],
                        'login_failed',
                        'minimal',
                        'Fehlgeschlagener Login',
                        "Fehlgeschlagener Login fuer Benutzer '{$this->username}' (LDAP Passwortpruefung).",
                        ['username' => $this->username, 'ip' => $this->ip(), 'provider' => 'ldap']
                    );
                }
                throw new \Exception('password verification failed');
            }

            $this->logger->log('password verification successful', 0);
            $this->email = $user_entries[0]['mail'][0] ?? null;

            $query = "SELECT uuid, email, activation_code, settings, login_attempts, last_login_attempt FROM users WHERE username = :username AND login_provider = :login_provider";
            $result = $this->db_adapter->db_query($query, ['username' => $this->username, 'login_provider' => 'ldap']);
            $result = !empty($result) ? $result[0] : null;
            $this->logger->log('checking if user exists in database');

            if (!empty($result)) {
                $this->logger->log('user exists in database', 1);

                $this->uuid = $result['uuid'];
                $this->email = $result['email'];
                $this->activation_code = $result['activation_code'] ?? null;
                $this->settings = $result['settings'];
                $this->login_attempts = $result['login_attempts'];

                if (!$this->canAttemptLogin($this->login_attempts, $result['last_login_attempt'] ?? null)) {
                    $this->logger->log('login cooldown active', 2);
                    $this->notifyUsers(
                        [$this->uuid],
                        'login_failed',
                        'minimal',
                        'Fehlgeschlagener Login',
                        "Loginversuch blockiert (Cooldown aktiv) fuer Benutzer '{$this->username}'.",
                        ['username' => $this->username, 'ip' => $this->ip(), 'provider' => 'ldap']
                    );
                    throw new \Exception('login attempts exceeded');
                }

                if ((int)($this->login_attempts ?? 0) > self::LOGIN_ATTEMPT_LIMIT) {
                    $this->resetLoginAttempts((string)$this->uuid);
                    $this->login_attempts = null;
                }

                $query = "UPDATE users SET last_login = NOW(), login_attempts = :login_attempts, ip_address = :ip_address WHERE uuid = :uuid";
                $result = $this->db_adapter->db_query($query, ['login_attempts' => null, 'ip_address' => $this->ip(), 'uuid' => $this->uuid]);
            } else {
                $this->logger->log('user does not exist in database', 1);

                $query = "SELECT uuid FROM role WHERE caption = :caption";
                $params = ['caption' => 'ldap'];
                $result = $this->db_adapter->db_query($query, $params);

                if (empty($result)) {
                    $this->logger->log('ldap role does not exist', 3);
                    throw new \Exception('ldap role does not exist');
                }

                $query = "INSERT INTO users (role, login_provider, username, email, activation_code, settings, ip_address, created, changed) VALUES (:role, :login_provider, :username, :email, :activation_code, :settings, :ip_address, :created, :changed) RETURNING uuid";
                $language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0] : 'en-EN';
                $settings = [
                    'language' => $language,
                    'appearance' => [
                        'theme' => 'light',
                        'font_family' => 'jetbrains',
                        'font_size' => 'normal'
                    ]
                ];

                LDAP_TRUST ? $this->activation_code = 'activated' : $this->activation_code = $this->random_string(10);

                $params = [
                    'role' => $result[0]['uuid'],
                    'login_provider' => 'ldap',
                    'username' => $this->username,
                    'email' => $this->email,
                    'activation_code' => $this->activation_code,
                    'settings' => json_encode($settings),
                    'ip_address' => $this->ip(),
                    'created' => 'NOW()',
                    'changed' => 'NOW()'
                ];
                $result = $this->db_adapter->db_query($query, $params);
                $this->uuid = $result[0]['uuid'];
                $this->logger->log('creating user account in database');
                $this->settings = $params['settings'];
            }

            if (LDAP_TRUST === TRUE && $this->activation_code !== 'activated') {
                $this->logger->log("user '$this->username' not activated", 2);
                throw new \Exception("user '$this->username' not activated");
            } elseif (LDAP_TRUST === FALSE && $this->activation_code !== 'activated') {
                $this->logger->log("user '$this->username' not activated", 2);
                throw new \Exception("user '$this->username' not activated");
            } elseif ($this->activation_code == 'deactivated') {
                $this->logger->log("user '$this->username' not activated", 2);
                throw new \Exception("user '$this->username' not activated");
            }

            $this->establishAuthenticatedSession('ldap');
            if (!$headless) {
                $this->redirectAfterSignin();
            }
            $this->notifyUsers(
                [$this->uuid],
                'login_success',
                'all',
                'Erfolgreicher Login',
                "Erfolgreicher Login fuer Benutzer '{$this->username}' (LDAP).",
                ['username' => $this->username, 'ip' => $this->ip(), 'provider' => 'ldap']
            );
            $this->logger->log("user '$this->username' logged in", 1);

            return true;
        } catch (\Exception $e) {
            $this->logger->log($e->getMessage(), 3);
            if (!$headless) {
                $this->rememberWebAuthIssue($e->getMessage(), $this->resolveWebAuthIssueLevel($e), $e instanceof \InvalidArgumentException);
            }
            return false;
        } finally {
            if (isset($ldap_connection)) {
                @ldap_close($ldap_connection);
            }
        }
    }

    public function forgot_password() {
        try {
            if ($this->csrf_check()) {
                $this->logger->log('CSRF token correct', 1);
            } else {
                throw new \InvalidArgumentException('CSRF token not correct');
            }

            if (!$this->db_adapter->checkDatabaseAndTableExistence('users')) {
                die("the database table 'users' doesn't exist. please run the init script.");
            }
            // check post data
            $this->local_signon('forgot_password');

            $userRow = $this->findLocalUserForPasswordResetRequest();
            $this->logger->log('checking if local user with username and e-mail exists');

            if ($userRow !== null) {
                $token = bin2hex(random_bytes(32));
                if (!$this->persistPasswordResetRequest($userRow, $token)) {
                    throw new \RuntimeException('password reset request could not be stored');
                }

                $resetLink = PORTFLOW_HOSTNAME . '?reset_password=1&token=' . urlencode($token) . '&email=' . urlencode((string)$userRow['email']);
                $mailTo = ['email' => $userRow['email'], 'username' => $userRow['username']];
                $subject = 'Portflow: Reset your password';
                $body = 'Use the following link to set a new password: <a href="' . $resetLink . '">Reset password</a>';
                $body .= '<br><br>This link is valid for 60 minutes.';
                $body .= '<br>If you did not request a password reset, you can ignore this e-mail.';
                $this->mail->send($mailTo, $subject, $body);
                $this->logger->log('password reset link sent to local user', 1);
            }

            $this->logger->log('If the provided username and e-mail are valid, a password reset link has been sent.', 1, echoToWeb: true);
            return true;
        } catch (\Exception $e) {
            $this->logWebAuthException($e);
            return false;
        }
    }

    public function reset_password(string $token, string $email): bool {
        try {
            if ($this->csrf_check()) {
                $this->logger->log('CSRF token correct', 1);
            } else {
                throw new \InvalidArgumentException('CSRF token not correct');
            }

            if (!$this->db_adapter->checkDatabaseAndTableExistence('users')) {
                die("the database table 'users' doesn't exist. please run the init script.");
            }

            $this->validatePasswordResetSubmission($token, $email);

            $userRow = $this->findLocalUserByResetEmail($email);
            if ($userRow === null || !$this->isPasswordResetTokenValid($userRow, $token)) {
                throw new \InvalidArgumentException('Password reset link invalid or expired');
            }

            $this->consumePasswordResetRequest($userRow);
            $this->logger->log('Password has been reset successfully. You can now sign in with your new password.', 1, echoToWeb: true);
            header('Location: ' . PORTFLOW_HOSTNAME);
            return true;
        } catch (\Exception $e) {
            $this->logWebAuthException($e);
            return false;
        }
    }

    public function signup() {
        try {
            if (PORTFLOW_FIRST_RUN === FALSE && PORTFLOW_REGISTER === FALSE) {
                throw new \InvalidArgumentException('registration disabled');
            }

            if (!$this->db_adapter->checkDatabaseAndTableExistence('users')) {
                die("the database table 'users' doesn't exist. please run the init script.");
            }
            // check post data
            $this->local_signon('signup');

            // check if first user
            $query = "SELECT uuid FROM users";
            $result = $this->db_adapter->db_query($query);
            if (empty($result)) {

                // create user role
                $query = "INSERT INTO role (caption, description) VALUES (:caption, :description) RETURNING uuid";
                $params = ['caption' => 'user', 'description' => 'user role created by portflow'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->role = $result[0]['uuid'];
                $this->logger->log('creating user role', 1);

                // set access right
                $query = "INSERT INTO access (role, resource, access_right) VALUES (:role, :resource, :access_right)";
                $params = ['role' => $this->role, 'resource' => 'api/*', 'access_right' => '0'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->logger->log('disallowing api access for user role', 0);
                
                // set automation access right (no access by default for user)
                $query = "INSERT INTO access (role, resource, access_right) VALUES (:role, :resource, :access_right)";
                $params = ['role' => $this->role, 'resource' => 'automation', 'access_right' => '0'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->logger->log('disallowing automation access for user role', 0);

                // create ldap role
                $query = "INSERT INTO role (caption, description) VALUES (:caption, :description) RETURNING uuid";
                $params = ['caption' => 'ldap', 'description' => 'ldap role created by portflow'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->role = $result[0]['uuid'];
                $this->logger->log('creating ldap role', 1);

                // set access right
                $query = "INSERT INTO access (role, resource, access_right) VALUES (:role, :resource, :access_right)";
                $params = ['role' => $this->role, 'resource' => 'api/*', 'access_right' => '0'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->logger->log('disallowing api access for ldap role', 0);
                
                // set automation access right (no access by default for ldap)
                $query = "INSERT INTO access (role, resource, access_right) VALUES (:role, :resource, :access_right)";
                $params = ['role' => $this->role, 'resource' => 'automation', 'access_right' => '0'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->logger->log('disallowing automation access for ldap role', 0);

                // create admin role
                $query = "INSERT INTO role (caption, description) VALUES (:caption, :description) RETURNING uuid";
                $params = ['caption' => 'admin', 'description' => 'administrator role created by portflow'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->role = $result[0]['uuid'];
                $this->logger->log('creating admin role and retrieving uuid', 1);

                // set access right
                $query = "INSERT INTO access (role, resource, access_right) VALUES (:role, :resource, :access_right)";
                $params = ['role' => $this->role, 'resource' => 'api/*', 'access_right' => '7'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->logger->log('allowing api access for admin role', 0);
                
                // set automation access right (allow automation for admin)
                $query = "INSERT INTO access (role, resource, access_right) VALUES (:role, :resource, :access_right)";
                $params = ['role' => $this->role, 'resource' => 'automation', 'access_right' => '1'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->logger->log('allowing automation access for admin role', 0);

                $envWriteResult = $this->writeRootEnvValues(['PORTFLOW_FIRST_RUN' => 'false']);
                if (!$envWriteResult['ok']) {
                    $this->logger->log('failed to update PORTFLOW_FIRST_RUN: ' . $envWriteResult['message'], 3);
                } else {
                    $this->logger->log('setting PORTFLOW_FIRST_RUN to FALSE', 0);
                }
            } else {
                // get user role uuid
                $query = "SELECT uuid FROM role WHERE caption = :caption";
                $params = ['caption' => 'user'];
                $result = $this->db_adapter->db_query($query, $params);
                $this->role = $result[0]['uuid'];
                $this->logger->log('retrieving user role uuid', 1);
            }

            // check if user exists
            $query = "SELECT uuid FROM users WHERE username = :username OR email = :email";
        
            // execute query
            $result = $this->db_adapter->db_query($query, ['username' => $this->username, 'email' => $this->email]);
            $this->logger->log("checking if user '$this->username' exists");
        
            foreach ($result as $row) {
                if (!empty($row['uuid'])) {
                    throw new \Exception('user already exists');
                }
            }
            $this->logger->log('user does not exist', 1);

            // create user account
            $query = "INSERT INTO users (role, login_provider, username, password, email, activation_code, settings, ip_address, created, changed) VALUES (:role, :login_provider, :username, :password, :email, :activation_code, :settings, :ip_address, :created, :changed)"; 

            // prepare vars for query
            $activation_code = $this->random_string(10);
            $language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0] : "en-EN";
            $settings = [
                'language' => $language,
                'appearance' => [
                    'theme' => 'light',
                    'font_family' => 'jetbrains',
                    'font_size' => 'normal'
                ]
            ];
            
            // hash password
            $this->password = password_hash($this->password, PASSWORD_DEFAULT);
            $this->logger->log('password hashed', 1);

            // execute query
            $params = [
                'login_provider' => 'local',
                'role' => $this->role,
                'username' => $this->username,
                'password' => $this->password,
                'email' => $this->email,
                'activation_code' => $activation_code,
                'settings' => json_encode($settings),
                'ip_address' => $this->ip(),
                'created' => 'NOW()',
                'changed' => 'NOW()'
            ];
            $result = $this->db_adapter->db_query($query, $params);
            $this->logger->log('creating user account');

            if ($result) {
                // send activation mail
                $activate_link = PORTFLOW_HOSTNAME . '?code=' . $activation_code . '&email=' . $this->email; 
                $subject = 'Portflow: Activate your account';
                $message = 'To activate your account, please click the following link: <a href="' . $activate_link . '">Activate</a>';
                $mail_to = ['email' => $this->email, 'username' => $this->username];
                if ($this->mail->send($mail_to, $subject, $message)) {
                    $this->logger->log('Account successfully created. An activation code has been sent to your e-mail.', 1, echoToWeb: true);
                    header('Location: ' . PORTFLOW_HOSTNAME);
                } else {
                    throw new \Exception('Account successfully created. An error occured while sending an activation code to your e-mail.');
                }
            } else {
                throw new \Exception('failed to create user account');
            }
        } catch (\Exception $e) {
            $this->logWebAuthException($e);
            // Additional exception handling logic here
        }
    }

    public function verify($code, $email) {
        try {
            // check if code and email are set
            if (!isset($code, $email)) {
                $this->logger->log('code or email not set', 1);
                throw new \Exception('code or email not set');
            }
            if (empty($code) || empty($email)) {
                $this->logger->log('code or email empty', 1);
                throw new \Exception('code or email empty');
            }

            // check if account exists or is already verified
            $query = "SELECT uuid, activation_code FROM users WHERE email = :email";
            $result = $this->db_adapter->db_query($query , ['email' => $email]);
            if ($result) {
                $this->logger->log('account with mail exists', 1);
                if ($result[0]['activation_code'] == $code) {
                    $this->logger->log("Your account with the e-mail: '$email' is now verified.", 1, echoToWeb: true);
                    $query = "UPDATE users SET activation_code = :activation_code WHERE uuid = :uuid";
                    $result = $this->db_adapter->db_query($query, ['activation_code' => 'activated', 'uuid' => $result[0]['uuid']]);
                    $this->logger->log('updated database', 0);
                    header('Location: ' . PORTFLOW_HOSTNAME);
                } elseif ($result[0]['activation_code'] == 'activated') {
                    $this->logger->log("Your account with the e-mail: '$email' is already verified", 1, echoToWeb: true);
                }
            } else {
                $this->logger->log("The activation code is incorrect", 2, echoToWeb: true);
            }
        } catch (\Exception $e) {
            // Log the exception message with ERROR level
            $this->logger->log($e->getMessage(), 3);
            // Here you can handle the exception as needed, for example:
            // - Redirect the user to an error page
            // - Show a specific error message to the user
            // Make sure to not directly output the Exception message if it contains sensitive information
        }
    }

    public function checkResourceAccess($userUuid, $resource, $requiredAction = 'any') {
        try {
            // Get user role
            $query = "SELECT role FROM users WHERE uuid = :uuid";
            $result = $this->db_adapter->db_query($query, ['uuid' => $userUuid]);
            
            if (empty($result)) {
                $this->logger->log("user uuid '$userUuid' not found", 2);
                return false;
            }
            
            $roleUuid = $result[0]['role'];
            
            // Check if role has access to resource
            $query = "SELECT access_right FROM access WHERE role = :role AND resource = :resource";
            $result = $this->db_adapter->db_query($query, ['role' => $roleUuid, 'resource' => $resource]);
            
            if (empty($result)) {
                $this->logger->log("no access right found for resource '$resource' and role '$roleUuid'", 2);
                return false;
            }
            
            $accessRight = (int)$result[0]['access_right'];
            
            $requiredAction = strtolower(trim((string)$requiredAction));
            $maskMap = [
                'read' => 4,
                'write' => 2,
                'execute' => 1,
                'any' => 0
            ];
            $requiredMask = $maskMap[$requiredAction] ?? 0;

            if ($requiredMask === 0) {
                $granted = $accessRight > 0;
            } else {
                $granted = (($accessRight & $requiredMask) === $requiredMask);
            }

            if ($granted) {
                $this->logger->log("access granted for resource '$resource' to user '$userUuid' (required=$requiredAction, access_right=$accessRight)", 1);
                return true;
            }
            
            $this->logger->log("access denied for resource '$resource' to user '$userUuid' (required=$requiredAction, access_right=$accessRight)", 2);
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log("checkResourceAccess exception: " . $e->getMessage(), 3);
            return false;
        }
    }
}
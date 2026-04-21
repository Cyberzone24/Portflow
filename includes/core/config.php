<?php
// Check if APP_NAME is defined
if (!defined('APP_NAME')) {
    die('Access denied');
}

/**
 * Simple .env file parser
 * Loads environment variables from .env file into $_ENV and getenv()
 */
function loadDotenvFile($filePath) {
    if (!file_exists($filePath)) {
        return false;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            // Handle boolean values
            if (strtolower($value) === 'true') {
                $value = true;
            } elseif (strtolower($value) === 'false') {
                $value = false;
            }

            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
    
    return true;
}

// Load .env file from root directory
$envPath = __DIR__ . '/../../.env';
if (!loadDotenvFile($envPath)) {
    // If .env doesn't exist, it's probably during setup
    error_log('Warning: .env file not found at ' . $envPath);
}

// Helper function to get .env value with default
function getEnvValue($key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value !== false) ? $value : $default;
}

// Load configuration from .env and define constants
define('LOG_LEVEL', (int) getEnvValue('LOG_LEVEL', 1));
define('DB_TYPE', getEnvValue('DB_TYPE', 'pgsql'));
define('DB_SERVER', getEnvValue('DB_SERVER', 'localhost'));
define('DB_PORT', getEnvValue('DB_PORT', '5432'));
define('DB_NAME', getEnvValue('DB_NAME', ''));
define('DB_USER', getEnvValue('DB_USER', ''));
define('DB_PASSWORD', getEnvValue('DB_PASSWORD', ''));

define('PORTFLOW_HOSTNAME', getEnvValue('PORTFLOW_HOSTNAME', 'http://localhost'));
define('PORTFLOW_SECURE', getEnvValue('PORTFLOW_SECURE', false) === true || getEnvValue('PORTFLOW_SECURE') === 'true');
define('PORTFLOW_REGISTER', getEnvValue('PORTFLOW_REGISTER', true) === true || getEnvValue('PORTFLOW_REGISTER') === 'true');
define('PORTFLOW_FIRST_RUN', getEnvValue('PORTFLOW_FIRST_RUN', false) === true || getEnvValue('PORTFLOW_FIRST_RUN') === 'true');

define('MAIL_HOST', getEnvValue('MAIL_HOST', ''));
define('MAIL_USER', getEnvValue('MAIL_USER', ''));
define('MAIL_PASSWORD', getEnvValue('MAIL_PASSWORD', ''));
define('MAIL_PORT', getEnvValue('MAIL_PORT', '587'));
define('MAIL_SMTPAUTH', getEnvValue('MAIL_SMTPAUTH', true) === true || getEnvValue('MAIL_SMTPAUTH') === 'true');
define('MAIL_SMTPSECURE', getEnvValue('MAIL_SMTPSECURE', 'tls'));

define('LDAP_ENABLED', getEnvValue('LDAP_ENABLED', false) === true || getEnvValue('LDAP_ENABLED') === 'true');
define('LDAP_SERVER', getEnvValue('LDAP_SERVER', ''));
define('LDAP_PORT', getEnvValue('LDAP_PORT', '389'));
define('LDAP_BASEDN', getEnvValue('LDAP_BASEDN', ''));
define('LDAP_USERDN', getEnvValue('LDAP_USERDN', ''));
define('LDAP_FILTER', getEnvValue('LDAP_FILTER', ''));
define('LDAP_BIND', getEnvValue('LDAP_BIND', false) === true || getEnvValue('LDAP_BIND') === 'true');
define('LDAP_BIND_USER', getEnvValue('LDAP_BIND_USER', ''));
define('LDAP_BIND_PASSWORD', getEnvValue('LDAP_BIND_PASSWORD', ''));
define('LDAP_TRUST', getEnvValue('LDAP_TRUST', true) === true || getEnvValue('LDAP_TRUST') === 'true');

define('AUTOMATION_SECRET', getEnvValue('AUTOMATION_SECRET', ''));

define('NOTIFICATION_DAILY_TIME', getEnvValue('NOTIFICATION_DAILY_TIME', '08:00'));
define('NOTIFICATION_TIMEZONE', getEnvValue('NOTIFICATION_TIMEZONE', 'Europe/Berlin'));

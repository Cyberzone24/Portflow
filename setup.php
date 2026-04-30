<?php
// Display all errors during development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set application name
const APP_NAME = 'Portflow';

$rootEnvPath = __DIR__ . '/.env';
if (file_exists($rootEnvPath)) {
    include_once __DIR__ . '/includes/core/config.php';
}

if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => defined('PORTFLOW_SECURE') ? PORTFLOW_SECURE : false,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

if (defined('PORTFLOW_FIRST_RUN') && PORTFLOW_FIRST_RUN === false) {
    http_response_code(403);
    echo 'Setup disabled.';
    exit;
}

// Import Logger class
include_once __DIR__ . '/includes/core/logger.php';
use Portflow\Core\Logger;
$logger = new Logger();

// Import DatabaseAdapter class
include_once __DIR__ . '/includes/core/db_adapter.php';
use Portflow\Core\DatabaseAdapter;

// Import alert function
include_once __DIR__ . '/includes/alert.php';

// Function to get the server URI
function uri() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $position = strrpos($_SERVER['SERVER_ADDR'] . $_SERVER['REQUEST_URI'], basename($_SERVER['PHP_SELF']));
    return $position !== false ? $protocol . substr($_SERVER['SERVER_ADDR'] . $_SERVER['REQUEST_URI'], 0, $position) : null;
}

function normalizeMailSecureForForm($value) {
    $value = trim((string)$value);
    if ($value === 'PHPMailer::ENCRYPTION_STARTTLS') {
        return 'tls';
    }
    if ($value === 'PHPMailer::ENCRYPTION_SMTPS') {
        return 'ssl';
    }
    return $value;
}

function defaultSetupConfig() {
    return [
        'LOG_LEVEL' => defined('LOG_LEVEL') ? (string)LOG_LEVEL : '1',
        'DB_SERVER' => defined('DB_SERVER') ? (string)DB_SERVER : 'localhost',
        'DB_PORT' => defined('DB_PORT') ? (string)DB_PORT : '5432',
        'DB_NAME' => defined('DB_NAME') ? (string)DB_NAME : '',
        'DB_USER' => defined('DB_USER') ? (string)DB_USER : '',
        'DB_PASSWORD' => defined('DB_PASSWORD') ? (string)DB_PASSWORD : '',
        'HOSTNAME' => defined('PORTFLOW_HOSTNAME') ? (string)PORTFLOW_HOSTNAME : (uri() ?? ''),
        'SSL' => (defined('PORTFLOW_SECURE') && PORTFLOW_SECURE) ? 'TRUE' : 'FALSE',
        'REGISTER' => (defined('PORTFLOW_REGISTER') && PORTFLOW_REGISTER) ? 'TRUE' : 'FALSE',
        'MAIL_HOST' => defined('MAIL_HOST') ? (string)MAIL_HOST : '',
        'MAIL_USER' => defined('MAIL_USER') ? (string)MAIL_USER : '',
        'MAIL_PASSWORD' => defined('MAIL_PASSWORD') ? (string)MAIL_PASSWORD : '',
        'MAIL_PORT' => defined('MAIL_PORT') ? (string)MAIL_PORT : '587',
        'MAIL_SMTPAUTH' => (defined('MAIL_SMTPAUTH') && MAIL_SMTPAUTH) ? 'TRUE' : 'FALSE',
        'MAIL_SMTPSECURE' => defined('MAIL_SMTPSECURE') ? normalizeMailSecureForForm((string)MAIL_SMTPSECURE) : '',
        'LDAP_ENABLED' => (defined('LDAP_ENABLED') && LDAP_ENABLED) ? 'TRUE' : 'FALSE',
        'LDAP_SERVER' => defined('LDAP_SERVER') ? (string)LDAP_SERVER : '',
        'LDAP_PORT' => defined('LDAP_PORT') ? (string)LDAP_PORT : '389',
        'LDAP_BASEDN' => defined('LDAP_BASEDN') ? (string)LDAP_BASEDN : '',
        'LDAP_USERDN' => defined('LDAP_USERDN') ? (string)LDAP_USERDN : '',
        'LDAP_FILTER' => defined('LDAP_FILTER') ? (string)LDAP_FILTER : '',
        'LDAP_BIND' => (defined('LDAP_BIND') && LDAP_BIND) ? 'TRUE' : 'FALSE',
        'LDAP_BIND_USER' => defined('LDAP_BIND_USER') ? (string)LDAP_BIND_USER : '',
        'LDAP_BIND_PASSWORD' => defined('LDAP_BIND_PASSWORD') ? (string)LDAP_BIND_PASSWORD : '',
        'LDAP_TRUST' => (defined('LDAP_TRUST') && LDAP_TRUST) ? 'TRUE' : 'FALSE',
        'AUTOMATION_SECRET' => defined('AUTOMATION_SECRET') ? (string)AUTOMATION_SECRET : '',
    ];
}

function normalizeSetupConfig($config) {
    return array_merge(defaultSetupConfig(), is_array($config) ? $config : []);
}

function configHasValues($config, $keys) {
    foreach ($keys as $key) {
        if (trim((string)($config[$key] ?? '')) === '') {
            return false;
        }
    }
    return true;
}

function hasBootstrapDatabaseConfig($config) {
    return configHasValues($config, ['DB_SERVER', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']);
}

function hasBootstrapServerConfig($config) {
    return configHasValues($config, ['HOSTNAME', 'LOG_LEVEL'])
        && array_key_exists('SSL', $config)
        && array_key_exists('REGISTER', $config);
}

function hasBootstrapAutomationConfig($config) {
    return trim((string)($config['AUTOMATION_SECRET'] ?? '')) !== '';
}

function hasBootstrapMailConfig($config) {
    return configHasValues($config, ['MAIL_HOST', 'MAIL_USER', 'MAIL_PASSWORD', 'MAIL_PORT']);
}

function hasBootstrapLdapConfig($config) {
    if (($config['LDAP_ENABLED'] ?? 'FALSE') !== 'TRUE') {
        return false;
    }

    return configHasValues($config, ['LDAP_SERVER', 'LDAP_PORT', 'LDAP_BASEDN', 'LDAP_USERDN']);
}

function isDatabaseSchemaInitialized() {
    try {
        $dbAdapter = new DatabaseAdapter();
        return $dbAdapter->checkDatabaseAndTableExistence('users');
    } catch (\Throwable $e) {
        return false;
    }
}

function isPhpFpmAvailable(): bool {
    $sapi = PHP_SAPI;
    if ($sapi === 'fpm-fcgi') {
        return true;
    }

    if (stripos($sapi, 'cgi') !== false) {
        return true;
    }

    return extension_loaded('Zend OPcache');
}

function checkApplicationDirectories(string $dir): bool {
    if (!is_dir($dir) || !is_readable($dir) || !is_executable($dir)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && (!$item->isReadable() || !$item->isExecutable())) {
            return false;
        }
    }

    return true;
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

    if (strpos($content, $schedulerPath) === false) {
        return ['ok' => false, 'label' => 'Present but points elsewhere'];
    }

    return ['ok' => true, 'label' => 'Installed'];
}

function nextSetupStep($config, $afterStep = 0) {
    if ($afterStep < 1) {
        return 1;
    }
    if ($afterStep < 2 && !hasBootstrapDatabaseConfig($config)) {
        return 2;
    }
    if ($afterStep < 3 && !hasBootstrapServerConfig($config)) {
        return 3;
    }
    if ($afterStep < 4) {
        return 4;
    }
    if ($afterStep < 5 && !hasBootstrapMailConfig($config)) {
        return 5;
    }
    if ($afterStep < 6 && !hasBootstrapLdapConfig($config)) {
        return 6;
    }
    if ($afterStep < 7 && !hasBootstrapAutomationConfig($config)) {
        return 7;
    }
    return null;
}

// Start, reset configuration, get time, or initialize database
if (isset($_GET['start'])) {
    $_SESSION['step'] = 0;
    $logger->log('Started configuration of Portflow', 1);
} elseif (isset($_GET['reset'])) {
    session_destroy();
    header('Location: ' . uri());
    exit;
} elseif (isset($_GET['get_time'])) {
    echo json_encode(['time' => date('Y-m-d H:i:s')]);
    exit;
} elseif (isset($_GET['db_init'])) {
    $db_adapter = new DatabaseAdapter();
    $logger->log('DB adapter imported', 0);
    $db_adapter->db_init();
    $logger->log('DB initialized', 1);

    // Go to next step
    $config = normalizeSetupConfig(isset($_SESSION['config']) ? $_SESSION['config'] : []);
    $nextStep = nextSetupStep($config, 2);
    if ($nextStep === null) {
        createEnvFile($config);
        header('refresh:5;url=index.php?signup');
        exit;
    }
    displayForm($nextStep, $config);
    $_SESSION['step'] = $nextStep;
    exit;
}

// Load configuration from session
$config = normalizeSetupConfig(isset($_SESSION['config']) ? $_SESSION['config'] : []);

// Display form based on the current step
function displayForm($step, $config = []) {
    $config = normalizeSetupConfig($config);
    $action = htmlspecialchars($_SERVER["PHP_SELF"]);
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Portflow</title>
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script>
            $(document).ready(function() {
                function serverTime() {
                    $.get('?get_time', function(data) {
                        const serverTime = JSON.parse(data).time;
                        $('#server_time').text(serverTime);
                    });
                }
                setInterval(serverTime, 1000);
                serverTime();

                function clientTime() {
                    const clientTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
                    $('#client_time').text(clientTime);
                }
                setInterval(clientTime, 1000);
                clientTime();


                $('#sync_time').on('click', function() {
                    const clientTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
                    $('#server_time_input').val(clientTime);
                });
            });
        </script>
    </head>
    <body class="bg-gray-300">
        <div class="flex flex-col items-center justify-center h-screen">
            <div class="bg-white shadow-lg rounded-2xl p-12 w-full max-w-lg">
    HTML;

    switch ($step) {
        case 1:
            echo <<<HTML
            <form class="m-0" method="POST" action="$action">
                <div class="flex justify-center items-center pb-12">
                    <img src="includes/img/portflow.png" alt="Portflow" class="max-h-18">
                </div>
                <div class="py-6">
                    <h1 class="text-4xl font-bold">Selftest</h1>
                </div>
            HTML;

            // Modules
            $modules = ['session', 'mbstring', 'pdo', 'pdo_pgsql', 'openssl', 'ldap', 'snmp'];
            echo <<<HTML
            <div class="pb-6">
                <p>PHP-Module</p>
                <table class="table-auto border-collapse border border-slate-500 w-full">
                    <tr>
                        <th class="p-4 border border-slate-500">Module</th>
                        <th class="p-4 border border-slate-500">Status</th>
                    </tr>
            HTML;
            echo '<tr><td class="p-4 border border-slate-500">php-fpm / CGI SAPI</td><td class="p-4 border border-slate-500">';
            echo isPhpFpmAvailable() ? 'Available' : 'Not Available';
            echo '</td></tr>';
            foreach ($modules as $module) {
                echo '<tr><td class="p-4 border border-slate-500">' . $module . '</td><td class="p-4 border border-slate-500">';
                if (extension_loaded($module)) {
                    echo 'Installed';
                } else {
                    echo 'Not Installed';
                }
                echo '</td></tr>';
            }
            echo <<<HTML
                </table>
            </div>
            HTML;

            // Permissions
            echo <<<HTML
            <div class="pb-6">
                <p>Permissions</p>
                <table class="table-auto border-collapse border border-slate-500 w-full">
                    <tr>
                        <th class="p-4 border border-slate-500">Directory</th>
                        <th class="p-4 border border-slate-500">Status</th>
            HTML;
            echo '<tr><td class="p-4 border border-slate-500">Portflow (Sub-)Directory</td><td class="p-4 border border-slate-500">';
            if (checkApplicationDirectories(getcwd())) {
                echo 'Readable + Traversable';
            } else {
                echo 'Not Readable/Traversable';
            }
            echo '</td></tr>';

            $currentDirOwner = 'unknown';
            $ownerId = @fileowner(getcwd());
            if ($ownerId !== false && function_exists('posix_getpwuid')) {
                $ownerInfo = posix_getpwuid($ownerId);
                if (is_array($ownerInfo) && isset($ownerInfo['name'])) {
                    $currentDirOwner = (string)$ownerInfo['name'];
                }
            }
            echo '<tr><td class="p-4 border border-slate-500">Current Directory Owner</td><td class="p-4 border border-slate-500">';
            if ($currentDirOwner == 'www-data') {
                echo 'www-data';
            } else {
                echo $currentDirOwner . ' (should be www-data)';
            }
            echo '</td></tr>';

            $logDir = '/var/log/portflow';
            echo '<tr><td class="p-4 border border-slate-500">Log Directory</td><td class="p-4 border border-slate-500">';
            if (is_dir($logDir) && is_writable($logDir)) {
                echo 'Writable';
            } elseif (!is_dir($logDir)) {
                echo 'Missing';
            } else {
                echo 'Not Writable';
            }
            echo '</td></tr>';

            $schedulerCron = getSchedulerCronStatus(getcwd());
            echo '<tr><td class="p-4 border border-slate-500">Scheduler Cronjob</td><td class="p-4 border border-slate-500">';
            echo htmlspecialchars((string)$schedulerCron['label'], ENT_QUOTES, 'UTF-8');
            echo '</td></tr>';

            echo <<<HTML
                </table>
            </div>
            HTML;

            echo <<<HTML
                <input type="hidden" name="step" value="1">
                <div class="pt-6 flex justify-between items-center">
                    <a href="?reset" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline">Reset</a>
                    <input type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" value="Next">
                </div>
            </form>
            HTML;
            break;

        case 2:
            $dbServer = htmlspecialchars((string)$config['DB_SERVER']);
            $dbPort = htmlspecialchars((string)$config['DB_PORT']);
            $dbName = htmlspecialchars((string)$config['DB_NAME']);
            $dbUser = htmlspecialchars((string)$config['DB_USER']);
            echo <<<HTML
            <form class="m-0" method="POST" action="$action">
                <div class="flex justify-center items-center pb-12">
                    <img src="includes/img/portflow.png" alt="Portflow" class="max-h-18">
                </div>
                <div class="py-6">
                    <h1 class="text-4xl font-bold">Database Settings</h1>
                </div>
                <div class="pb-6">
                    <label for="db_server">Database Server</label>
                    <input type="text" id="db_server" name="db_server" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" placeholder="'localhost' or any hostname" value="$dbServer">
                </div>
                <div class="pb-6">
                    <label for="db_port">Database Port</label>
                    <input type="number" id="db_port" name="db_port" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" placeholder="5432" value="$dbPort">
                </div>
                <div class="pb-6">
                    <label for="db_name">Database Name</label>
                    <input type="text" id="db_name" name="db_name" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" placeholder="portflow" value="$dbName">
                </div>
                <div class="pb-6">
                    <label for="db_user">Database User</label>
                    <input type="text" id="db_user" name="db_user" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" placeholder="username" value="$dbUser">
                </div>
                <div class="pb-6">
                    <label for="db_password">Database Password</label>
                    <input type="password" id="db_password" name="db_password" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" placeholder="password">
                </div>
                <input type="hidden" name="step" value="1">
                <div class="pt-6 flex justify-between items-center">
                    <a href="?reset" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline">Reset</a>
                    <input type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" value="Next">
                </div>
            </form>
            HTML;
            break;

        case 3:
            $uri = htmlspecialchars((string)($config['HOSTNAME'] ?: uri()));
            $sslChecked = ($config['SSL'] === 'TRUE') ? 'checked' : '';
            $registerChecked = ($config['REGISTER'] === 'TRUE') ? 'checked' : '';
            $logLevel = (string)$config['LOG_LEVEL'];
            $selectedDebug = ($logLevel === '0') ? 'selected' : '';
            $selectedInfo = ($logLevel === '1') ? 'selected' : '';
            $selectedWarn = ($logLevel === '2') ? 'selected' : '';
            $selectedError = ($logLevel === '3') ? 'selected' : '';
            $selectedNone = ($logLevel === '4') ? 'selected' : '';
            echo <<<HTML
            <form class="m-0" method="POST" action="$action">
                <div class="flex justify-center items-center pb-12">
                    <img src="includes/img/portflow.png" alt="Portflow" class="max-h-18">
                </div>
                <div class="py-6">
                    <h1 class="text-4xl font-bold">Server Settings</h1>
                </div>
                <div class="pb-6">
                    <label for="hostname">Portflow Hostname (FQDN)</label>
                    <input type="text" id="hostname" name="hostname" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" value="$uri" placeholder="http(s)://sub.domain.tld/portflow">
                </div>
                <div class="pb-6">
                    <label for="ssl">Force https connection</label>
                    <input type="checkbox" id="ssl" name="ssl" value="true" $sslChecked class="border rounded w-5 h-5 focus:outline-none focus:shadow-outline">
                </div>
                <div class="pb-6">
                    <label for="register">Allow user registration</label>
                    <input type="checkbox" id="register" name="register" value="true" $registerChecked class="border rounded w-5 h-5 focus:outline-none focus:shadow-outline">
                </div>
                <div class="pb-6">
                    <label for="log_level">Log Level</label>
                    <select id="log_level" name="log_level" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline">
                        <option value="0" $selectedDebug>DEBUG</option>
                        <option value="1" $selectedInfo>INFO</option>
                        <option value="2" $selectedWarn>WARN</option>
                        <option value="3" $selectedError>ERROR</option>
                        <option value="4" $selectedNone>NONE</option>
                    </select>
                </div>
                <input type="hidden" name="step" value="2">
                <div class="pt-6 flex justify-between items-center">
                    <a href="?reset" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline">Reset</a>
                    <input type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" value="Next">
                </div>
            </form>
            HTML;
            break;

        case 4:
            echo <<<HTML
            <form class="m-0" method="POST" action="$action">
                <div class="flex justify-center items-center pb-12">
                    <img src="includes/img/portflow.png" alt="Portflow" class="max-h-18">
                </div>
                <div class="py-6">
                    <h1 class="text-4xl font-bold">Time Settings</h1>
                </div>
                <div class="pb-6">
                    <div class="flex flex-row justify-between">
                        <div class="flex flex-col mb-2">
                            <label for="server_time">Server Time</label>
                            <span id="server_time" class="border rounded-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline"></span>
                            <input id="server_time_input" name="server_time" hidden readonly>
                        </div>
                        <div class="flex flex-col">
                            <label for="client_time">Client Time</label>
                            <span id="client_time" class="border rounded-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline"></span>
                        </div>
                    </div>   
                    <button type="button" id="sync_time" class="bg-gray-500 hover:bg-gray-700 text-white font-bold w-full py-2 px-4 rounded-full focus:outline-none focus:shadow-outline">Synchronize</button>
                </div>
                <input type="hidden" name="step" value="3">
                <div class="pt-6 flex justify-between items-center">
                    <a href="?reset" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline">Reset</a>
                    <input type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" value="Next">
                </div>
            </form>
            HTML;
            break;

        case 5:
            $mailHost = htmlspecialchars((string)$config['MAIL_HOST']);
            $mailUser = htmlspecialchars((string)$config['MAIL_USER']);
            $mailPassword = htmlspecialchars((string)$config['MAIL_PASSWORD']);
            $mailPort = htmlspecialchars((string)$config['MAIL_PORT']);
            $mailSmtpAuthChecked = ($config['MAIL_SMTPAUTH'] === 'TRUE') ? 'checked' : '';
            $mailSecure = (string)$config['MAIL_SMTPSECURE'];
            $mailSecureNone = ($mailSecure === '') ? 'selected' : '';
            $mailSecureTls = ($mailSecure === 'tls') ? 'selected' : '';
            $mailSecureSsl = ($mailSecure === 'ssl') ? 'selected' : '';
            echo <<<HTML
            <form class="m-0" method="POST" action="$action">
                <div class='flex justify-center items-center pb-12'>
                    <img src='includes/img/portflow.png' alt='Portflow' class='max-h-18'>
                </div>
                <div class='py-6'>
                    <h1 class='text-4xl font-bold'>Mail Configuration</h1>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_host'>Mail Host</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='mail_host' name='mail_host' placeholder='smtp.domain.tld' value='$mailHost' required>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_user'>Mail User</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='mail_user' name='mail_user' placeholder='user@domain.tld' value='$mailUser' required>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_password'>Mail Password</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='password' id='mail_password' name='mail_password' placeholder='password' value='$mailPassword' required>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_port'>Mail Port</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='mail_port' name='mail_port' placeholder='25, 465, 587' value='$mailPort' required>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_smtpauth'>SMTP Auth</label>
                    <input class='border rounded w-5 h-5 focus:outline-none focus:shadow-outline' type='checkbox' id='mail_smtpauth' name='mail_smtpauth' value='false' $mailSmtpAuthChecked>                
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_smtpsecure'>SMTP Secure</label>
                    <select class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id='mail_smtpsecure' name='mail_smtpsecure'>
                        <option value='' $mailSecureNone>None</option>
                        <option value='tls' $mailSecureTls>TLS</option>
                        <option value='ssl' $mailSecureSsl>SSL</option>
                    </select>
                </div>
                <input type='hidden' name='step' value='3'>
                <div class='pt-6 flex justify-between items-center'>
                    <a href='?reset' class='bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline'>Reset</a>
                    <input type='submit' class='bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline' value='Next'>
                </div>
            </form>
            HTML;
        break;

        case 6:
            $ldapEnabledChecked = ($config['LDAP_ENABLED'] === 'TRUE') ? 'checked' : '';
            $ldapServer = htmlspecialchars((string)$config['LDAP_SERVER']);
            $ldapPort = htmlspecialchars((string)$config['LDAP_PORT']);
            $ldapBaseDn = htmlspecialchars((string)$config['LDAP_BASEDN']);
            $ldapUserDn = htmlspecialchars((string)$config['LDAP_USERDN']);
            $ldapFilter = htmlspecialchars((string)$config['LDAP_FILTER']);
            $ldapBindChecked = ($config['LDAP_BIND'] === 'TRUE') ? 'checked' : '';
            $ldapBindUser = htmlspecialchars((string)$config['LDAP_BIND_USER']);
            $ldapBindPassword = htmlspecialchars((string)$config['LDAP_BIND_PASSWORD']);
            $ldapTrustChecked = ($config['LDAP_TRUST'] === 'TRUE') ? 'checked' : '';
            echo <<<HTML
            <form class="m-0" method="POST" action="$action">
                <div class='flex justify-center items-center pb-12'>
                    <img src='includes/img/portflow.png' alt='Portflow' class='max-h-18'>
                </div>
                <div class='py-6'>
                    <h1 class='text-4xl font-bold'>LDAP Configuration (Optional)</h1>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_enabled'>Enable LDAP Module</label>
                    <input class='border rounded w-5 h-5 focus:outline-none focus:shadow-outline' type='checkbox' id='ldap_enabled' name='ldap_enabled' value='false' $ldapEnabledChecked>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_server'>LDAP Server</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_server' name='ldap_server' placeholder='ldap.domain.tld' value='$ldapServer'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_server'>LDAP Port</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_port' name='ldap_port' value='$ldapPort' placeholder='389, 636'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_basedn'>LDAP Base DN</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_basedn' name='ldap_basedn' placeholder='dc=domain,dc=tld' value='$ldapBaseDn'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_userdn'>LDAP User DN</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_userdn' name='ldap_userdn' placeholder='ou=people' value='$ldapUserDn'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_filter'>LDAP Filter</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_filter' name='ldap_filter' placeholder='(|(title=Admin)(title=Network)) or (ou=Headoffice)' value='$ldapFilter'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_bind'>LDAP Bind</label>
                    <input class='border rounded w-5 h-5 focus:outline-none focus:shadow-outline' type='checkbox' id='ldap_bind' name='ldap_bind' value='false' $ldapBindChecked>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_bind_user'>LDAP Bind User</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_bind_user' name='ldap_bind_user' placeholder='username' value='$ldapBindUser'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_bind_password'>LDAP Bind Password</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='password' id='ldap_bind_password' name='ldap_bind_password' placeholder='password' value='$ldapBindPassword'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_trust'>LDAP Trust</label>
                    <p>Allows the login of ldap accounts without activating them beforehand.</p>
                    <input class='border rounded w-5 h-5 focus:outline-none focus:shadow-outline' type='checkbox' id='ldap_trust' name='ldap_trust' value='false' $ldapTrustChecked>
                </div>
                <input type='hidden' name='step' value='4'>
                <div class='pt-6 flex justify-between items-center'>
                    <a href='?reset' class='bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline'>Reset</a>
                    <input type='submit' class='bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline' value='Finish'>
                </div>
            </form>
            HTML;
        break;

        case 7:
            $automationSecret = htmlspecialchars((string)$config['AUTOMATION_SECRET']);
            echo <<<HTML
            <form class="m-0" method="POST" action="$action">
                <div class='flex justify-center items-center pb-12'>
                    <img src='includes/img/portflow.png' alt='Portflow' class='max-h-18'>
                </div>
                <div class='flex flex-col gap-12 py-6'>
                    <h1 class='text-4xl font-bold'>Automation Configuration</h1>
                </div> 
                <div class='pb-6'>
                    <label class='block mb-2' for='automation_secret'>Automation Secret</label>
                    <p class='text-lg'>Please set a long random value as automation secret. This secret is used to authenticate API requests from the automation module.</p>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='password' id='automation_secret' name='automation_secret' placeholder='change-this-to-a-long-random-value' value="$automationSecret" required>
                </div>
                <input type='hidden' name='step' value='5'>
                <div class='pt-6 flex justify-between items-center'>
                    <a href='?reset' class='bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline'>Reset</a>
                    <input type='submit' class='bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline' value='Finish'>
                </div>
            </form>
            HTML;
        break;

        default:
            echo <<<HTML
            <div>
                <div class='flex justify-center items-center pb-12'>
                    <img src='includes/img/portflow.png' alt='Portflow' class='max-h-18'>
                </div>
                <div class='flex flex-col gap-12 py-6'>
                    <h1 class='text-4xl font-bold'>Welcome!</h1>
                    <p class='text-lg'>This setup will guide you through the installation process.</p>
                </div>
                <div class='pt-6 flex justify-between items-center'>
                    <a href='?reset' class='bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline'>Reset</a>
                    <a href='?start' class='bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline'>Start</a>
                </div>
            </div>
            HTML;
        break;
    }

    echo <<<HTML
            </div>
        </div>
    </body>
    </html>
    HTML;
}

// Process form data
if (isset($_SESSION['step'])) {
    $step = intval($_SESSION['step']);

    if ($step === 2) {
        // Database settings
        $config['DB_SERVER'] = filter_var($_POST['db_server'], FILTER_SANITIZE_SPECIAL_CHARS);
        $config['DB_PORT'] = filter_var($_POST['db_port'], FILTER_SANITIZE_NUMBER_INT);
        $config['DB_NAME'] = filter_var($_POST['db_name'], FILTER_SANITIZE_SPECIAL_CHARS);
        $config['DB_USER'] = filter_var($_POST['db_user'], FILTER_SANITIZE_SPECIAL_CHARS);
        $config['DB_PASSWORD'] = filter_var($_POST['db_password'], FILTER_SANITIZE_SPECIAL_CHARS);

        // Create temporary config file (needed for db_init)
        $config['LOG_LEVEL'] = 1;
        $config['SSL'] = isset($_POST['ssl']) ? 'TRUE' : 'FALSE';
        $config['REGISTER'] = isset($_POST['register']) ? 'TRUE' : 'FALSE';
        $config['MAIL_SMTPAUTH'] = isset($_POST['mail_smtpauth']) ? 'TRUE' : 'FALSE';
        $config['LDAP_BIND'] = isset($_POST['ldap_bind']) ? 'TRUE' : 'FALSE';
        $config['LDAP_TRUST'] = isset($_POST['ldap_trust']) ? 'TRUE' : 'FALSE';
        createEnvFile($config);

        // Use db_init to create database
        header('Location: ?db_init');
        $_SESSION['config'] = $config;
        exit;
    } elseif ($step === 3) {
        // SSL and hostname
        if (substr(filter_var($_POST['hostname'], FILTER_SANITIZE_URL), -1) === '/') { $config['HOSTNAME'] = rtrim(filter_var($_POST['hostname'], FILTER_SANITIZE_URL), '/'); }
        $config['SSL'] = isset($_POST['ssl']) ? 'TRUE' : 'FALSE';
        $config['REGISTER'] = isset($_POST['register']) ? 'TRUE' : 'FALSE';
        $config['LOG_LEVEL'] = filter_var($_POST['log_level'], FILTER_SANITIZE_NUMBER_INT);
    } elseif ($step === 4) {
        // Server time
        if (isset($_POST['server_time'])) {
            $serverTimeRaw = trim((string)$_POST['server_time']);
            $serverTime = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $serverTimeRaw);
            $serverTimeErrors = \DateTimeImmutable::getLastErrors();

            if (!$serverTime || (($serverTimeErrors['warning_count'] ?? 0) > 0) || (($serverTimeErrors['error_count'] ?? 0) > 0)) {
                $logger->log('Failed to set server time: invalid format', 3);
                echo "<p class='error'>Failed to set server time. Invalid format.</p>";
            } else {
                $server_time = $serverTime->format('Y-m-d H:i:s');
                $output = [];
                $return_var = 0;
                exec('date -s ' . escapeshellarg($server_time), $output, $return_var);
                if ($return_var !== 0) {
                    $logger->log('Failed to set server time: ' . implode("\n", $output), 3);
                    echo "<p class='error'>Failed to set server time. Please check the logs.</p>";
                } else {
                    $logger->log('Server time set to ' . $server_time, 1);
                }
            }
        }
    } elseif ($step === 5) {
        // Mail settings
        $config['MAIL_HOST'] = filter_var($_POST['mail_host'], FILTER_SANITIZE_SPECIAL_CHARS);
        $config['MAIL_USER'] = filter_var($_POST['mail_user'], FILTER_SANITIZE_EMAIL);
        $config['MAIL_PASSWORD'] = filter_var($_POST['mail_password'], FILTER_SANITIZE_SPECIAL_CHARS);
        $config['MAIL_PORT'] = filter_var($_POST['mail_port'], FILTER_SANITIZE_NUMBER_INT);
        $config['MAIL_SMTPAUTH'] = isset($_POST['mail_smtpauth']) ? 'TRUE' : 'FALSE';
        if ($_POST['mail_smtpsecure'] === 'tls') {
            $config['MAIL_SMTPSECURE'] = 'PHPMailer::ENCRYPTION_STARTTLS';
        } elseif ($_POST['mail_smtpsecure'] === 'ssl') {
            $config['MAIL_SMTPSECURE'] = 'PHPMailer::ENCRYPTION_SMTPS';
        } else {
            $config['MAIL_SMTPSECURE'] = filter_var($_POST['mail_smtpsecure'], FILTER_SANITIZE_SPECIAL_CHARS);
        }
    } elseif ($step === 6) {
        // LDAP settings
        $config['LDAP_ENABLED'] = isset($_POST['ldap_enabled']) ? 'TRUE' : 'FALSE';
        $config['LDAP_SERVER'] = isset($_POST['ldap_server']) ? filter_var($_POST['ldap_server'], FILTER_SANITIZE_SPECIAL_CHARS) : NULL;
        $config['LDAP_PORT'] = isset($_POST['ldap_port']) ? filter_var($_POST['ldap_port'], FILTER_SANITIZE_NUMBER_INT) : NULL;
        $config['LDAP_BASEDN'] = isset($_POST['ldap_basedn']) ? filter_var($_POST['ldap_basedn'], FILTER_SANITIZE_SPECIAL_CHARS) : NULL;
        $config['LDAP_USERDN'] = isset($_POST['ldap_userdn']) ? filter_var($_POST['ldap_userdn'], FILTER_SANITIZE_SPECIAL_CHARS) : NULL;
        $config['LDAP_FILTER'] = isset($_POST['ldap_filter']) ? filter_var($_POST['ldap_filter'], FILTER_SANITIZE_SPECIAL_CHARS) : NULL;
        $config['LDAP_BIND'] = isset($_POST['ldap_bind']) ? 'TRUE' : 'FALSE';
        $config['LDAP_BIND_USER'] = isset($_POST['ldap_bind_user']) ? filter_var($_POST['ldap_bind_user'], FILTER_SANITIZE_SPECIAL_CHARS) : NULL;
        $config['LDAP_BIND_PASSWORD'] = isset($_POST['ldap_bind_password']) ? filter_var($_POST['ldap_bind_password'], FILTER_SANITIZE_SPECIAL_CHARS) : NULL;
        $config['LDAP_TRUST'] = isset($_POST['ldap_trust']) ? 'TRUE' : 'FALSE';
    } elseif ($step === 7) {
        // Automation secret
        $config['AUTOMATION_SECRET'] = isset($_POST['automation_secret']) ? filter_var($_POST['automation_secret'], FILTER_SANITIZE_SPECIAL_CHARS) : bin2hex(random_bytes(16));
    }

    if ($step < 7) {
        if ($step === 1 && hasBootstrapDatabaseConfig($config) && !isDatabaseSchemaInitialized()) {
            createEnvFile($config);
            $_SESSION['config'] = $config;
            $_SESSION['step'] = 2;
            header('Location: ?db_init=1');
            exit;
        }

        $nextStep = nextSetupStep($config, $step);
        if ($nextStep === null) {
            createEnvFile($config);
            header('refresh:5;url=index.php?signup');
            exit;
        }

        displayForm($nextStep, $config);
        $_SESSION['step'] = $nextStep;
    } else {
        // Create config file
        createEnvFile($config);
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Portflow</title>
            <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        </head>
        <body>
            <div class="flex flex-col items-center justify-center h-screen bg-gray-200">
                <div class="bg-white shadow-lg rounded-2xl p-12 w-full max-w-lg">
                    <div class='flex justify-center items-center pb-12'>
                        <img src='includes/img/portflow.png' alt='Portflow' class='max-h-18'>
                    </div>
                    <div class='flex flex-col gap-12 py-6'>
                        <h1 class='text-4xl font-bold'>Configuration completed!</h1>
                        <p class='text-lg'>Thank you for using Portflow!</p><br><br>
                        <p>You will be redirected in a few seconds so that you can create your user account.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        HTML;
        header('refresh:5;url=index.php?signup');
    }
    $_SESSION['config'] = $config;
} else {
    displayForm(NULL, $config);
}

function createEnvFile($config) {
    $config = normalizeSetupConfig($config);
    $envPath = __DIR__ . '/.env';
    $envContent = "# Portflow Configuration - Generated by setup wizard
# Logging
LOG_LEVEL={$config['LOG_LEVEL']}

# Database Configuration
DB_TYPE=pgsql
DB_SERVER={$config['DB_SERVER']}
DB_PORT={$config['DB_PORT']}
DB_NAME={$config['DB_NAME']}
DB_USER={$config['DB_USER']}
DB_PASSWORD={$config['DB_PASSWORD']}

# Portflow Server Settings
PORTFLOW_HOSTNAME={$config['HOSTNAME']}
PORTFLOW_SECURE={$config['SSL']}
PORTFLOW_REGISTER={$config['REGISTER']}
PORTFLOW_FIRST_RUN=true

# Mail Configuration
MAIL_HOST={$config['MAIL_HOST']}
MAIL_USER={$config['MAIL_USER']}
MAIL_PASSWORD={$config['MAIL_PASSWORD']}
MAIL_PORT={$config['MAIL_PORT']}
MAIL_SMTPAUTH={$config['MAIL_SMTPAUTH']}
MAIL_SMTPSECURE={$config['MAIL_SMTPSECURE']}

# LDAP Configuration (Optional)
LDAP_ENABLED={$config['LDAP_ENABLED']}
LDAP_SERVER={$config['LDAP_SERVER']}
LDAP_PORT={$config['LDAP_PORT']}
LDAP_BASEDN={$config['LDAP_BASEDN']}
LDAP_USERDN={$config['LDAP_USERDN']}
LDAP_FILTER={$config['LDAP_FILTER']}
LDAP_BIND={$config['LDAP_BIND']}
LDAP_BIND_USER={$config['LDAP_BIND_USER']}
LDAP_BIND_PASSWORD={$config['LDAP_BIND_PASSWORD']}
LDAP_TRUST={$config['LDAP_TRUST']}

# Automation Configuration
AUTOMATION_SECRET={$config['AUTOMATION_SECRET']}
";

    file_put_contents($envPath, $envContent);
    @chmod($envPath, 0600);
}

# Prüfung für SNMP und OpenSSL (benötigt für SNMPv3) hinzufügen
# Zeitzone prüfen und ggf. setzen (wichtig für Cronjobs, TFA und Logs)
?>
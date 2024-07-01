<?php
// Display all errors during development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set application name
const APP_NAME = 'Portflow';

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
    $step = intval($_SESSION['step']);
    displayForm($step + 1);
    $_SESSION['step'] = $step + 1;
    exit;
}

// Load configuration from session
$config = isset($_SESSION['config']) ? $_SESSION['config'] : [];

// Display form based on the current step
function displayForm($step) {
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
    <body>
        <div class="flex flex-col items-center justify-center h-screen bg-gray-200">
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
            $modules = ['fpm', 'session', 'mbstring', 'pdo', 'pdo_pgsql', 'openssl', 'ldap'];
            echo <<<HTML
            <div class="pb-6">
                <p>PHP-Module</p>
                <table class="table-auto border-collapse border border-slate-500 w-full">
                    <tr>
                        <th class="p-4 border border-slate-500">Module</th>
                        <th class="p-4 border border-slate-500">Status</th>
                    </tr>
            HTML;
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
            function checkDir($dir) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
                foreach($iterator as $item) {
                    if (!is_executable($item)) {
                        return false;
                    }
                }
                return true;
            }

            echo '<tr><td class="p-4 border border-slate-500">Portflow (Sub-)Directory</td><td class="p-4 border border-slate-500">';
            if (checkDir(getcwd())) {
                echo 'Executable';
            } else {
                echo 'Not Executable';
            }
            echo '</td></tr>';

            $currentDirOwner = posix_getpwuid(fileowner(getcwd()))['name'];
            echo '<tr><td class="p-4 border border-slate-500">Current Directory Owner</td><td class="p-4 border border-slate-500">';
            if ($currentDirOwner == 'www-data') {
                echo 'www-data';
            } else {
                echo $currentDirOwner . ' (should be www-data)';
            }
            echo '</td></tr>';

            $logDir = '/var/log/portflow';
            echo '<tr><td class="p-4 border border-slate-500">Log Directory</td><td class="p-4 border border-slate-500">';
            if (is_writable($logDir)) {
                echo 'Writable';
            } else {
                echo 'Not Writable';
            }
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
                    <input type="text" id="db_server" name="db_server" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" placeholder="'localhost' or any hostname" value="localhost">
                </div>
                <div class="pb-6">
                    <label for="db_port">Database Port</label>
                    <input type="number" id="db_port" name="db_port" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" placeholder="5432" value="5432">
                </div>
                <div class="pb-6">
                    <label for="db_name">Database Name</label>
                    <input type="text" id="db_name" name="db_name" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" placeholder="portflow">
                </div>
                <div class="pb-6">
                    <label for="db_user">Database User</label>
                    <input type="text" id="db_user" name="db_user" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" placeholder="username">
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
            $uri = uri();
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
                    <input type="checkbox" id="ssl" name="ssl" value="true" checked class="border rounded w-5 h-5 focus:outline-none focus:shadow-outline">
                </div>
                <div class="pb-6">
                    <label for="log_level">Log Level</label>
                    <select id="log_level" name="log_level" required class="border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline">
                        <option value="0">DEBUG</option>
                        <option value="1" selected>INFO</option>
                        <option value="2">WARN</option>
                        <option value="3">ERROR</option>
                        <option value="4">NONE</option>
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
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='mail_host' name='mail_host' placeholder='smtp.domain.tld' required>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_user'>Mail User</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='mail_user' name='mail_user' placeholder='user@domain.tld' required>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_password'>Mail Password</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='password' id='mail_password' name='mail_password' placeholder='password' required>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_port'>Mail Port</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='mail_port' name='mail_port' placeholder='25, 465, 587' required>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_smtpauth'>SMTP Auth</label>
                    <input class='border rounded w-5 h-5 focus:outline-none focus:shadow-outline' type='checkbox' id='mail_smtpauth' name='mail_smtpauth' value='false'>                
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='mail_smtpsecure'>SMTP Secure</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='mail_smtpsecure' name='mail_smtpsecure' placeholder='tls / ssl'>
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
                    <input class='border rounded w-5 h-5 focus:outline-none focus:shadow-outline' type='checkbox' id='ldap_enabled' name='ldap_enabled' value='false'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_server'>LDAP Server</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_server' name='ldap_server' placeholder='ldap.domain.tld'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_server'>LDAP Port</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_port' name='ldap_port' value='389' placeholder='389, 636'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_basedn'>LDAP Base DN</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_basedn' name='ldap_basedn' placeholder='dc=domain,dc=tld'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_userdn'>LDAP User DN</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_userdn' name='ldap_userdn' placeholder='ou=people'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_filter'>LDAP Filter</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_filter' name='ldap_filter' placeholder='(|(title=Admin)(title=Network)) or (ou=Headoffice)'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_bind'>LDAP Bind</label>
                    <input class='border rounded w-5 h-5 focus:outline-none focus:shadow-outline' type='checkbox' id='ldap_bind' name='ldap_bind' value='false'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_bind_user'>LDAP Bind User</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_bind_user' name='ldap_bind_user' placeholder='username'>
                </div>
                <div class='pb-6'>
                    <label class='block mb-2' for='ldap_bind_password'>LDAP Bind Password</label>
                    <input class='appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline' type='text' id='ldap_bind_password' name='ldap_bind_password' placeholder='password'>
                </div>
                <input type='hidden' name='step' value='4'>
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

        // Create config file (needed for db_init)
        $config['LOG_LEVEL'] = 1;
        $config['SSL'] = isset($_POST['ssl']) ? 'TRUE' : 'FALSE';
        $config['MAIL_SMTPAUTH'] = isset($_POST['mail_smtpauth']) ? 'TRUE' : 'FALSE';
        $config['LDAP_BIND'] = isset($_POST['ldap_bind']) ? 'TRUE' : 'FALSE';
        createConfigFile($config);

        // Use db_init to create database
        header('Location: ?db_init');
        $_SESSION['config'] = $config;
        exit;
    } elseif ($step === 3) {
        // SSL and hostname
        if (substr(filter_var($_POST['hostname'], FILTER_SANITIZE_URL), -1) === '/') { $config['HOSTNAME'] = rtrim(filter_var($_POST['hostname'], FILTER_SANITIZE_URL), '/'); }
        $config['SSL'] = isset($_POST['ssl']) ? 'TRUE' : 'FALSE';
        $config['LOG_LEVEL'] = filter_var($_POST['log_level'], FILTER_SANITIZE_NUMBER_INT);
    } elseif ($step === 4) {
        // Server time
        if (isset($_POST['server_time'])) {
            $server_time = filter_var($_POST['server_time'], FILTER_SANITIZE_SPECIAL_CHARS);
            $output = [];
            $return_var = 0;
            exec("date -s '$server_time'", $output, $return_var);
            if ($return_var !== 0) {
                $logger->log('Failed to set server time: ' . implode("\n", $output), 3);
                echo "<p class='error'>Failed to set server time. Please check the logs.</p>";
            } else {
                $logger->log('Server time set to ' . $server_time, 1);
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
    }

    if ($step < 6) {
        displayForm($step + 1);
    } else {
        // Create config file
        createConfigFile($config);
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
    $_SESSION['step'] = $step + 1;
} else {
    displayForm(NULL);
}

function createConfigFile($config) {
    $portflowDevices = "array('--' => '<i class='fa-solid fa-ban'></i>', 'phone' => '<i class='fa-solid fa-phone'></i>', 'notebook' => '<i class='fa-solid fa-laptop'></i>', 'switch' => '<i class='fa-solid fa-network-wired'></i>', 'zeroclient' => '<i class='fa-solid fa-desktop'></i>', 'thinclient' => '<i class='fa-solid fa-computer'></i>', 'desktop' => '<i class='fa-brands fa-windows'></i>', 'access_point' => '<i class='fa-solid fa-wifi'></i>', 'printer' => '<i class='fa-solid fa-print'></i>', 'other' => '<i class='fa-solid fa-question'></i>')";
    $configContent = 
"<?php
// check if APP_NAME is defined
if (!defined('APP_NAME')) {
    die('Access denied');
}
const LOG_LEVEL = {$config['LOG_LEVEL']};
const DB_TYPE = 'pgsql';
const DB_SERVER = '{$config['DB_SERVER']}';
const DB_PORT = '{$config['DB_PORT']}';
const DB_NAME = '{$config['DB_NAME']}';
const DB_USER = '{$config['DB_USER']}';
const DB_PASSWORD = '{$config['DB_PASSWORD']}';
const PORTFLOW_HOSTNAME = '{$config['HOSTNAME']}';
const PORTFLOW_SECURE = {$config['SSL']};
const PORTFLOW_DEVICES = " . '"' . $portflowDevices . '"' . ";
const MAIL_HOST = '{$config['MAIL_HOST']}';
const MAIL_USER = '{$config['MAIL_USER']}';
const MAIL_PASSWORD = '{$config['MAIL_PASSWORD']}';
const MAIL_PORT = '{$config['MAIL_PORT']}';
const MAIL_SMTPAUTH = {$config['MAIL_SMTPAUTH']};
const MAIL_SMTPSECURE = '{$config['MAIL_SMTPSECURE']}';
const LDAP_ENABLED = '{$config['LDAP_ENABLED']}';
const LDAP_SERVER = '{$config['LDAP_SERVER']}';
const LDAP_PORT = '{$config['LDAP_PORT']}';
const LDAP_BASEDN = '{$config['LDAP_BASEDN']}';
const LDAP_USERDN = '{$config['LDAP_USERDN']}';
const LDAP_FILTER = '{$config['LDAP_FILTER']}';
const LDAP_BIND = {$config['LDAP_BIND']};
const LDAP_BIND_USER = '{$config['LDAP_BIND_USER']}';
const LDAP_BIND_PASSWORD = '{$config['LDAP_BIND_PASSWORD']}';
";

    file_put_contents('includes/core/config.php', $configContent);
}
?>
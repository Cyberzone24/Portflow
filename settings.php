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
    use Portflow\Core\Auth;
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

        file_put_contents($commandFile, "screen-length 0 temporary\ndisplay version\n");

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

        $logger->log('automation ssh test for ' . $host . ' returned exit code ' . $exitCode, $exitCode === 0 ? 1 : 3);

        return [
            'ok' => ($exitCode === 0),
            'output' => $outputText
        ];
    }

    // import db_adapter
    use Portflow\Core\DatabaseAdapter;
    $db_adapter = new DatabaseAdapter();

    // import mail
    use Portflow\Core\Mail;
    $mail = new Mail();

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

                // find language in settings
                $settings = json_decode($_SESSION['settings']);
                $settings->language = $language;

                // update session
                $_SESSION['settings'] = json_encode($settings);

                // update database
                $query = "UPDATE users SET settings = :settings, changed = NOW() WHERE uuid = :uuid";
                $result = $db_adapter->db_query($query, ['settings' => json_encode($settings), 'uuid' => $_SESSION['uuid']]);
                $logger->log('language updated', 1, echoToWeb: true);
                header('Location: ?site=appearance');
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
                $accessRight = (int)($_POST['access_right'] ?? -1);

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
            case 'automation_scripts':
                // check if user is admin
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation settings', 2, echoToWeb: true);
                    header('Location: ?site=scripts');
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
                } catch (\Exception $e) {
                    $logger->log('automation settings update failed: ' . $e->getMessage(), 3, echoToWeb: true);
                }

                header('Location: ?site=scripts');
                break;
            case 'automation_test_ssh':
                if ($role !== 'admin') {
                    $logger->log('user is not admin', 2, echoToWeb: true);
                    header('Location: ?site=appearance');
                    die();
                }

                if (!$auth->csrf_check()) {
                    $logger->log('csrf token invalid for automation ssh test', 2, echoToWeb: true);
                    header('Location: ?site=scripts');
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
                break;
            default:
                $logger->log('no set parameter', 2, echoToWeb: true);
                header('Location: ?site=appearance');
                die();
            }
    // get
    } elseif ($_SERVER['REQUEST_METHOD'] == 'GET' && $get == 'details') {
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

        // get details
        $query = "SELECT * FROM users WHERE uuid = :uuid";
        $result = $db_adapter->db_query($query, ['uuid' => $uuid]);
        $result = !empty($result) ? $result[0] : null;

        if (!empty($result)) {
            echo json_encode($result);
        }
        die();
    } else {
        // import header
        include_once __DIR__ . '/includes/header.php';

        // get site
        $site = $_GET['site'] ?? NULL;
    }
?>
<div class="h-full flex overflow-x-clip bg-gray-100 rounded-xl shadow-md m-4 mt-0 p-4">
    <div class="basis-1/6 flex flex-col gap-6">  
        <p><?php echo $lang['settings']; ?></p>
        <ul class="w-full flex flex-col gap-6" id="itam_nav">
            <a href="?site=appearance"><li class="bg-white py-2 px-4 <?php echo ($site == 'appearance' || $site == NULL) ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4';?>"><?php echo $lang['appearance']; ?></li></a>
            <?php echo ($role !== 'ldap') ? '<a href="?site=account"><li class="bg-white py-2 px-4 ' . ($site == 'account' ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4') . '">' . $lang['account'] . '</li></a>' : ''; ?>
            <a href="?site=notifications"><li class="bg-white py-2 px-4 <?php echo ($site == 'notifications') ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4';?>"><?php echo $lang['notifications']; ?></li></a>
            <?php echo ($role == 'admin') ? '<a href="?site=configuration"><li class="bg-white py-2 px-4 ' . ($site == 'configuration' ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4') . '">' . $lang['configuration'] . '</li></a>' : ''; ?>
            <?php echo ($role == 'admin') ? '<a href="?site=scripts"><li class="bg-white py-2 px-4 ' . ($site == 'scripts' ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4') . '">' . $lang['scripts'] . '</li></a>' : ''; ?>
            <?php echo ($role == 'admin') ? '<a href="?site=access"><li class="bg-white py-2 px-4 ' . ($site == 'access' ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4') . '">' . $lang['access_management'] . '</li></a>' : ''; ?>
        </ul>
    </div>
    <div class="h-full basis-5/6 flex bg-white rounded-lg relative overflow-y-scroll">
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
        echo <<<HTML
        <div class="h-fit w-full p-4">
            <div class="h-fit max-w-lg">
                <div class="text-xl font-bold pb-6">Benachrichtigungen</div>
                <form action="?set=notification" method="post">
                    <div class="pb-6">
                        <label class="block mb-2" for="notification">
                            Benachrichtigung
                        </label>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="notification" type="text" name="notification">
                            <option value="1">Level 1</option>
                            <option value="2">Level 2</option>
                            <option value="3">Level 3</option>
                        </select>
                    </div>
                    <div class="pb-6">
                        <label class="block mb-2" for="provider">
                            Anbieter
                        </label>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="provider" type="text" name="provider">
                            <option value="mail">Mail</option>
                        </select>
                    </div>
                    <div class="pb-6 flex justify-between items-center">
                        <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Ändern">
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

        echo "Datenbank, LDAP, Mail, Backup";
        break;
    case 'access':
        // check if user is admin
        if ($role !== 'admin') {
            $logger->log('user is not admin', 2, echoToWeb: true);
            header('Location: ?site=appearance');
            die();
        }

        echo '<div class="h-fit w-full p-4">';

        $query = "SELECT users.uuid, users.username, users.email, role.caption AS role, users.login_provider, users.ip_address, CASE WHEN users.activation_code = 'activated' THEN 'activated' ELSE 'deactivated' END AS activation_code, TO_CHAR (users.last_login, 'HH24:MI DD.MM.YYYY') AS last_login, TO_CHAR (users.created, 'HH24:MI DD.MM.YYYY') AS created FROM users INNER JOIN role ON users.role = role.uuid";
        $results = $db_adapter->db_query($query);

        if ($results) {
            echo "<div class='text-xl font-bold pb-6'>Accounts</div><div class='max-h-96 overflow-y-auto'><table class='rounded-lg w-full text-sm text-left mb-4 text-gray-500 shadow-md'><thead class='bg-gray-200 sticky top-0 z-1'>";
            echo "<tr class='border-b bg-gray-200 text-gray-800'>";
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

                echo "<tr class='hover:bg-gray-200'>";
                foreach ($row as $column) {
                    echo "<td class='p-2 border-b'>{$column}</td>";
                }

                echo <<<HTML
                    <td class='p-2 border-b flex flex-row gap-4'>
                        <form action='?set=$form_action' method='post' class='m-0'>
                            <input type='hidden' name='uuid' value='$uuid'>
                            $button
                        </form>
                        <button class='h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center' onclick="openDetailsPopup('$uuid')">
                            <i data-lucide='info'></i>
                        </button>
                        <form action='?set=delete_account' method='post' class='m-0'>
                            <input type='hidden' name='uuid' value='$uuid'>
                            <button class='h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center'>
                                <i data-lucide='trash'></i>
                            </button>
                        </form>
                    </td>
                HTML;
                echo "</tr>";

                $uuid = NULL;}

            echo "</tbody></table></div>";
        } else {
            echo "No results found.";
        }

        echo "<br><br>";
        $query = "SELECT caption, description FROM role";
        $results = $db_adapter->db_query($query);

        if ($results) {
            echo "<div class='text-xl font-bold pb-6'>Roles</div><div class='max-h-96 overflow-y-auto'><table class='rounded-lg w-full text-sm text-left mb-4 text-gray-500 shadow-md'><thead class='bg-gray-200 sticky top-0 z-1'>";
            echo "<tr class='border-b bg-gray-200 text-gray-800'>";
            foreach (array_keys($results[0]) as $header) {
                echo "<th class='p-2'>{$header}</th>";
            }
            echo "</tr></thead><tbody>";
            foreach ($results as $row) {
                echo "<tr class='hover:bg-gray-200'>";
                foreach ($row as $column) {
                    echo "<td class='p-2 border-b'>{$column}</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        } else {
            echo "No results found.";
        }

        echo "<br><br>";
        $csrf = $auth->csrf();
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
            echo "<div class='text-xl font-bold pb-2'>Access Rights</div>";
            echo "<p class='text-sm text-gray-600 pb-4'>Rechte im Unix/Linux-Stil: 0-7 (z. B. 0 = kein Zugriff, 7 = voller Zugriff).</p>";
            echo "<div class='max-h-96 overflow-y-auto'><table class='rounded-lg w-full text-sm text-left mb-4 text-gray-500 shadow-md'><thead class='bg-gray-200 sticky top-0 z-1'>";
            echo "<tr class='border-b bg-gray-200 text-gray-800'>";
            echo "<th class='p-2'>Role</th>";
            echo "<th class='p-2'>Resource</th>";
            echo "<th class='p-2'>Access Right (0-7)</th>";
            echo "<th class='p-2'>Action</th>";
            echo "</tr></thead><tbody>";
            foreach ($results as $row) {
                $roleUuidEscaped = htmlspecialchars((string)$row['role'], ENT_QUOTES, 'UTF-8');
                $roleCaptionEscaped = htmlspecialchars((string)$row['role_caption'], ENT_QUOTES, 'UTF-8');
                $resourceEscaped = htmlspecialchars((string)$row['resource'], ENT_QUOTES, 'UTF-8');
                $accessRightValue = (int)($row['access_right'] ?? 0);

                echo "<tr class='hover:bg-gray-200'>";
                echo "<td class='p-2 border-b'>{$roleCaptionEscaped}</td>";
                echo "<td class='p-2 border-b font-mono'>{$resourceEscaped}</td>";
                echo "<td class='p-2 border-b'>";
                echo "<form action='?set=update_access_right' method='post' class='m-0 flex items-center gap-2'>";
                echo "<input type='hidden' name='csrf' value='{$csrf}'>";
                echo "<input type='hidden' name='role_uuid' value='{$roleUuidEscaped}'>";
                echo "<input type='hidden' name='resource' value='{$resourceEscaped}'>";
                echo "<input class='appearance-none border rounded-full w-20 py-1 px-3 leading-tight focus:outline-none focus:shadow-outline' type='number' min='0' max='7' step='1' name='access_right' value='{$accessRightValue}' required>";
                echo "</td>";
                echo "<td class='p-2 border-b'>";
                echo "<button class='bg-blue-500 hover:bg-blue-700 text-white font-bold py-1 px-3 rounded-full focus:outline-none focus:shadow-outline' type='submit'>Save</button>";
                echo "</form>";
                echo "</td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        } else {
            echo "No access rights found.";
        }

        echo <<<HTML
        <pre>
        username
        password
        email
        role
        remove tfa
        </pre>
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
        $scriptsJsonEscaped = htmlspecialchars($scriptsJson, ENT_QUOTES, 'UTF-8');
        $switchInventoryJson = trim((string)($automationSettings['switch_inventory_json'] ?? ''));
        if ($switchInventoryJson === '') {
            $switchInventoryJson = '{"switches": []}';
        }
        $switchInventoryJsonEscaped = htmlspecialchars($switchInventoryJson, ENT_QUOTES, 'UTF-8');
        $passwordHint = !empty($automationSettings['ssh_password']) ? 'Gespeichert (leer lassen zum Beibehalten)' : 'Noch nicht gesetzt';

        $testOutputHtml = '';
        if (is_array($automationTestResult) && isset($automationTestResult['output'])) {
            $testStateClass = !empty($automationTestResult['ok'])
                ? 'bg-green-50 border-green-200 text-green-900'
                : 'bg-red-50 border-red-200 text-red-900';
            $testOutputEscaped = htmlspecialchars((string)$automationTestResult['output'], ENT_QUOTES, 'UTF-8');
            $testOutputHtml = "<div class=\"rounded-2xl border p-4 {$testStateClass}\"><div class=\"text-sm font-semibold pb-2\">SSH Test Output</div><pre class=\"text-xs whitespace-pre-wrap leading-5\">{$testOutputEscaped}</pre></div>";
        }

        echo <<<HTML
        <div class="h-fit w-full p-4">
            <div class="grid grid-cols-1 gap-6">
                <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
                    <div class="text-xl font-bold pb-2">Automation: Secure Settings</div>
                    <p class="text-sm text-gray-600 pb-6">SSH-Zugangsdaten und Skript-Overrides werden verschluesselt in <span class="font-semibold">data/automation/settings.json</span> gespeichert.</p>
                    {$testOutputHtml}
                    <form action="?set=automation_scripts" method="post">
                        <input type="hidden" name="csrf" value="$csrf">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pb-4">
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

                        <div class="pb-4">
                            <label class="block mb-2 text-sm font-semibold" for="switch_inventory_json">Switch Inventory (Management IPs)</label>
                            <textarea class="appearance-none border rounded-2xl w-full py-3 px-4 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="switch_inventory_json" name="switch_inventory_json" rows="10" placeholder='{"switches":[{"name":"SW-Core-01","mgmt_ip":"10.0.0.10","profile":"huawei_core_commit","device_id":"uuid-from-itam"}]}'>{$switchInventoryJsonEscaped}</textarea>
                            <p class="text-xs text-gray-500 mt-2">Erforderlich pro Switch: name, mgmt_ip, profile. Optional: device_id (UUID des verknuepften ITAM-Geraets). Diese Liste wird im Automatisierungs-Tab als Zielauswahl genutzt.</p>
                        </div>

                        <div class="pb-4">
                            <label class="block mb-2 text-sm font-semibold" for="scripts_json">Automation Script Overrides (JSON)</label>
                            <textarea class="appearance-none border rounded-2xl w-full py-3 px-4 leading-tight focus:outline-none focus:shadow-outline font-mono text-sm" id="scripts_json" name="scripts_json" rows="16" placeholder='{"templates": {}}'>$scriptsJsonEscaped</textarea>
                            <p class="text-xs text-gray-500 mt-2">Erlaubte Bereiche: description_convention, profiles, templates. Diese Daten erweitern die Basisdatei aus includes/core/automation.json.</p>
                        </div>

                        <div class="pb-2 flex justify-between items-center gap-4">
                            <button class="bg-gray-600 hover:bg-gray-800 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" formaction="?set=automation_test_ssh">SSH testen</button>
                            <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Automation speichern">
                        </div>
                    </form>
                </div>
            </div>
        </div>
        HTML;
        break; 
    case 'appearance':
    default:
        echo <<<HTML
        <div class="h-fit w-full p-4">
            <div class="h-fit max-w-lg">
                <div class="text-xl font-bold pb-6">Sprache</div>
                <form action="?set=language" method="post">
                    <div class="pb-6">
                        <label class="block mb-2" for="language">
                            Sprache
                        </label>
                        <select class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="language" type="text" name="language">
                            <option value="de-DE">Deutsch</option>
                            <option value="en-EN">English</option>
                        </select>
                    </div>
                    <div class="pb-6 flex justify-between items-center">
                        <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Ändern">
                    </div>
                </form>
            </div>
            <p>Farbschema, Schriftart, Schriftgröße</p>
        </div>
        HTML;
        break;
};
?>
    </div>
</div>
<?php
    include_once 'includes/footer.php';
?>
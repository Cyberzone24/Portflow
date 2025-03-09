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
    use Portflow\Core\Auth;
    $auth = new Auth();

    // import db_adapter
    use Portflow\Core\DatabaseAdapter;
    $db_adapter = new DatabaseAdapter();

    // import logger
    use Portflow\Core\Logger;
    $logger = new Logger();

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
            <a href="?site=configuration"><li class="bg-white py-2 px-4 <?php echo ($site == 'configuration') ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4';?>"><?php echo $lang['configuration']; ?></li></a>
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
                echo "<tr class='hover:bg-gray-200'>";
                foreach ($row as $column) {
                    echo "<td class='p-2 border-b'>{$column}</td>";
                }
                echo <<<HTML
                    <td class='p-2 border-b flex flex-row gap-4'>
                        <form action='?set=activate_account' method='post'>
                            <input type='hidden' name='uuid' value='$uuid'>
                            <button class='h-10 w-10 rounded-full bg-green-500 hover:bg-green-700 text-white flex items-center justify-center'>
                                <i data-lucide='check'></i>
                            </button>
                        </form>
                        <button class='h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center' onclick="openDetailsPopup('$uuid')">
                            <i data-lucide='info'></i>
                        </button>
                        <form action='?set=delete_account' method='post'>
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
        $query = "SELECT metadata.caption, metadata.description, metadata.created, role.caption AS role, resource, access_right FROM access INNER JOIN role ON access.role = role.uuid LEFT JOIN metadata ON access.metadata = metadata.uuid";
        $results = $db_adapter->db_query($query);

        if ($results) {
            echo "<div class='text-xl font-bold pb-6'>Access Rights</div><div class='max-h-96 overflow-y-auto'><table class='rounded-lg w-full text-sm text-left mb-4 text-gray-500 shadow-md'><thead class='bg-gray-200 sticky top-0 z-1'>";
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

        echo <<<HTML
        <pre>
        - LDAP Accounts manuell erlauben

        username
        password
        email
        role
        remove tfa
        de-/activate
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
        echo "Skripte für Automatisierung, Cronjobs";
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
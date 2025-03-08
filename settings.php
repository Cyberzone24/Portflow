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

    include_once __DIR__ . '/includes/header.php';

    $site = $_GET['site'] ?? 'account';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $set = $_GET['set'] ?? null;

        switch ($set) {
            case 'username':
                $username = $_POST['username'] ?? null;
                $password = $_POST['password'] ?? null;
                $csrf = $_POST['csrf'] ?? null;

                if ($auth->csrf($csrf)) {
                    $auth->set_username($username, $password);
                }
                break;
            case 'email':
                $email = $_POST['email'] ?? null;
                $password = $_POST['password'] ?? null;
                $csrf = $_POST['csrf'] ?? null;

                if ($auth->csrf($csrf)) {
                    $auth->set_email($email, $password);
                }
                break;
            case 'password':
                $password = $_POST['password'] ?? null;
                $old_password = $_POST['old_password'] ?? null;
                $csrf = $_POST['csrf'] ?? null;

                if ($auth->csrf($csrf)) {
                    $auth->set_password($password, $old_password);
                }
                break;
        }
    } 
?>
<div class="h-full flex overflow-x-clip bg-gray-100 rounded-xl shadow-md m-4 mt-0 p-4">
    <div class="basis-1/6 flex flex-col gap-6">  
        <p><?php echo $lang['settings']; ?></p>
        <ul class="w-full flex flex-col gap-6" id="itam_nav">
            <a href="?site=account"><li class="bg-white py-2 px-4 <?php echo ($site == 'account') ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4';?>"><?php echo $lang['account']; ?></li></a>
            <a href="?site=appearance"><li class="bg-white py-2 px-4 <?php echo ($site == 'appearance') ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4';?>"><?php echo $lang['appearance']; ?></li></a>
            <a href="?site=notifications"><li class="bg-white py-2 px-4 <?php echo ($site == 'notifications') ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4';?>"><?php echo $lang['notifications']; ?></li></a>
            <a href="?site=configuration"><li class="bg-white py-2 px-4 <?php echo ($site == 'configuration') ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4';?>"><?php echo $lang['configuration']; ?></li></a>
            <a href="?site=access"><li class="bg-white py-2 px-4 <?php echo ($site == 'access') ? 'rounded-l-lg pr-0' : 'rounded-lg mr-4';?>"><?php echo $lang['access_management']; ?></li></a>
        </ul>
    </div>
    <div class="h-full basis-5/6 flex bg-white rounded-lg relative overflow-y-scroll">
<?php 
switch ($site) {        
    case 'appearance':
        echo <<<HTML
            <div class="h-fit w-full p-4">
                <p>Farbschema, Schriftart, Schriftgröße</p>
                <div class="h-fit max-w-lg">
                    <div class="text-xl font-bold pb-6">Sprache</div>
                    <div class="pb-6">
                        <label class="block mb-2" for="username">
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
                </div>
            </div>
        HTML;
        break;
    case 'notifications':
        echo "Benachrichtigungen, Anbieter";
        break;
    case 'configuration':
        echo "Datenbank, LDAP, Mail, Backup";
        break;
    case 'access':
        echo '<div class="h-fit w-full p-4">';

        echo "
        - LDAP Accounts manuell erlauben
        - Rollen verwalten
        - Benutzer verwalten";

        echo "
        users 10 einträge, dann scroll

        rollen 10 einträge, dann scroll

        acl 10 einträge, dann scroll";

        $query = "SELECT users.username, users.email, role.caption AS role, users.login_provider, users.ip_address, users.activation_code, users.last_login, users.created FROM users INNER JOIN role ON users.role = role.uuid";
        $results = $db_adapter->db_query($query);

        if ($results) {
            echo "<div class='text-xl font-bold pb-6'>Accounts</div><table class='rounded-lg w-full text-sm text-left mb-4 text-gray-500 shadow-md'><thead>";
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
            echo "</tbody></table>";
        } else {
            echo "No results found.";
        }

        echo "<br><br>";
        $query = "SELECT caption, description FROM role";
        $results = $db_adapter->db_query($query);

        if ($results) {
            echo "<div class='text-xl font-bold pb-6'>Roles</div><table class='rounded-lg w-full text-sm text-left mb-4 text-gray-500 shadow-md'><thead>";
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
            echo "</tbody></table>";
        } else {
            echo "No results found.";
        }

        echo "<br><br>";
        $query = "SELECT metadata.caption, metadata.description, metadata.created, role.caption AS role, resource, access_right FROM access INNER JOIN role ON access.role = role.uuid LEFT JOIN metadata ON access.metadata = metadata.uuid";
        $results = $db_adapter->db_query($query);

        if ($results) {
            echo "<div class='text-xl font-bold pb-6'>Access Rights</div><table class='rounded-lg w-full text-sm text-left mb-4 text-gray-500 shadow-md'><thead>";
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
            echo "</tbody></table>";
        } else {
            echo "No results found.";
        }

        echo "</div>";
        break;
    default:
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
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="username" type="text" placeholder="Username" name="username">
                        </div>
                        <div class="pb-6">
                            <label class="block mb-2" for="password">
                                Password
                            </label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="Password" name="password">
                        </div>
                        <div class="pb-6 flex justify-between items-center">
                            <input type="hidden" name="csrf" value="echo $csrf;">
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
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="email" type="email" placeholder="E-Mail" name="email">
                            </div>
                        <div class="pb-6">
                            <label class="block mb-2" for="password">
                                Password
                            </label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="Password" name="password">
                        </div>
                        <div class="pb-6 flex justify-between items-center">
                            <input type="hidden" name="csrf" value="echo $csrf;">
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
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="Password" name="password">
                        </div>
                        <div class="pb-6">
                            <label class="block mb-2" for="password">
                                Old Password
                            </label>
                            <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="Password" name="password">
                        </div>
                        <div class="pb-6 flex justify-between items-center">
                            <input type="hidden" name="csrf" value="echo $csrf;>">
                            <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Ändern">
                        </div>
                    </div>
                </form>
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
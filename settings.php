<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    const APP_NAME = 'Portflow';

    include_once __DIR__ . '/includes/core/session.php';
    if (!in_array(__DIR__ . '/includes/core/session.php', get_included_files())) {
        die('could not verify session');
    }

    // import database adapter
    include_once __DIR__ . '/includes/core/db_adapter.php';
    use Portflow\Core\DatabaseAdapter;
    $db_adapter = new DatabaseAdapter();

    include_once __DIR__ . '/includes/header.php';
?>
<div class="h-full flex overflow-x-clip bg-gray-100 rounded-xl shadow-md m-4 mt-0 p-4">
    <div class="basis-1/6 flex flex-col gap-6">  
        <p>Settings</p>
        <ul class="w-full flex flex-col gap-6" id="itam_nav">
            <li onclick="" class="bg-white py-2 px-4 rounded-l-lg pr-0">Account</li>
            <li onclick="" class="bg-white py-2 px-4 rounded-lg mr-4">Appearance</li>
            <li onclick="" class="bg-white py-2 px-4 rounded-lg mr-4">Notifications</li>
            <li onclick="" class="bg-white py-2 px-4 rounded-lg mr-4">Configuration</li>
            <li onclick="" class="bg-white py-2 px-4 rounded-lg mr-4">Access Management</li>
        </ul>
    </div>
    <div class="h-full basis-5/6 flex flex-col gap-6 bg-white rounded-lg p-4">
        <div class="h-fit max-w-lg">
            <div class="pb-6">
                <label class="block mb-2" for="username">
                    New username
                </label>
                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="username" type="text" placeholder="username" name="username">
            </div>
            <div class="pb-6">
                <label class="block mb-2" for="password">
                    Password
                </label>
                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="password" name="password">
            </div>
            <div class="pt-6 flex justify-between items-center">
                <!-- <input type="hidden" name="csrf" value="<?php #echo $auth->csrf(); ?>"> -->
                <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Ändern">
            </div>
        </div>
        <div class="h-fit max-w-lg">
            <div class="pb-6">
                <label class="block mb-2" for="email">
                    E-Mail
                </label>
                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="email" type="email" placeholder="E-Mail" name="email">
                </div>
            <div class="pb-6">
                <label class="block mb-2" for="password">
                    Password
                </label>
                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="password" name="password">
            </div>
            <div class="pt-6 flex justify-between items-center">
                <!-- <input type="hidden" name="csrf" value="<?php #echo $auth->csrf(); ?>"> -->
                <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Ändern">
            </div>
        </div>
        <div class="h-fit max-w-lg">
            <div class="pb-6">
                <label class="block mb-2" for="password">
                    New password
                </label>
                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="password" name="password">
            </div>
            <div class="pb-6">
                <label class="block mb-2" for="password">
                    Old password
                </label>
                <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="password" name="password">
            </div>
            <div class="pt-6 flex justify-between items-center">
                <!-- <input type="hidden" name="csrf" value="<?php #echo $auth->csrf(); ?>"> -->
                <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="Ändern">
            </div>
        </div>
        <div class="h-fit max-w-lg"><pre>
            Account: Nutzername, Passwort, E-Mail-Adresse
            Appearance: Sprache, Farbschema, Schriftart, Schriftgröße
            Notifications: Benachrichtigungen, Anbieter
            Configuration: Datenbank, LDAP, Mail

            ACL:
            - LDAP Accounts manuell erlauben
            - Rollen verwalten
            - Benutzer verwalten
        </pre></div>
    </div>
                users 10 einträge, dann scroll

                rollen 10 einträge, dann scroll

                acl 10 einträge, dann scroll
    <?php
            $query = "SELECT * FROM users";
            $results = $db_adapter->db_query($query);

            if ($results) {
                echo "<table border='1'>";
                echo "<tr>";
                foreach (array_keys($results[0]) as $header) {
                    echo "<th>{$header}</th>";
                }
                echo "</tr>";
                foreach ($results as $row) {
                    echo "<tr>";
                    foreach ($row as $column) {
                        echo "<td>{$column}</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "No results found.";
            }

            echo "<br><br>";
            $query = "SELECT * FROM role";
            $results = $db_adapter->db_query($query);

            if ($results) {
                echo "<table border='1'>";
                echo "<tr>";
                foreach (array_keys($results[0]) as $header) {
                    echo "<th>{$header}</th>";
                }
                echo "</tr>";
                foreach ($results as $row) {
                    echo "<tr>";
                    foreach ($row as $column) {
                        echo "<td>{$column}</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "No results found.";
            }

            echo "<br><br>";
            $query = "SELECT * FROM access";
            $results = $db_adapter->db_query($query);

            if ($results) {
                echo "<table border='1'>";
                echo "<tr>";
                foreach (array_keys($results[0]) as $header) {
                    echo "<th>{$header}</th>";
                }
                echo "</tr>";
                foreach ($results as $row) {
                    echo "<tr>";
                    foreach ($row as $column) {
                        echo "<td>{$column}</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "No results found.";
            }
    ?>
    <div>
    </div>
</div>
<?php
    include_once 'includes/footer.php';
?>
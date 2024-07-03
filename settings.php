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

    if (!isset($_GET['action'])) {
        include_once __DIR__ . '/includes/header.php';
        $limit = isset($_COOKIE['table_limit']) ? $_COOKIE['table_limit'] : 100;
        $page = isset($_GET['page']) ? $_GET['page'] : 1;
        $offset = ($page - 1) * $limit;
    } elseif ($_GET['action'] === 'get') {
        $limit = isset($_GET['limit']) ? $_GET['limit'] : 100;
        $limit = isset($_COOKIE['table_limit']) ? $_COOKIE['table_limit'] : 100;
        $page = isset($_GET['page']) ? $_GET['page'] : 1;
        $offset = ($page - 1) * $limit;
        #$sort = isset($_GET['sort']) ? $_GET['sort'] : '';
        #$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
        $table = isset($_GET['table']) ? $_GET['table'] : 'location';

        if (empty($_GET['search'])) {
            $columns = $db_adapter->db_query("SELECT column_name FROM information_schema.columns WHERE table_name = '$table'");

            $results = $db_adapter->db_query("SELECT * FROM $table LIMIT $limit OFFSET $offset");
            $totalResults = $db_adapter->db_query("SELECT COUNT(*) FROM $table");
        } else {
            $search = $_GET['search'];

            // Tabellenspalten abfragen
            $columns = $db_adapter->db_query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = '$table'");
            $textColumns = array_filter($columns, function($column) {
                return in_array($column['data_type'], ['text', 'character varying']);
            });

            // Bedingung für die Suchabfrage erstellen
            $searchConditions = [];
            foreach ($textColumns as $column) {
                $searchConditions[] = "{$column['column_name']} iLIKE '%$search%'";
            }
            $searchCondition = implode(' OR ', $searchConditions);

            // Suchabfrage ausführen
            $results = $db_adapter->db_query("SELECT * FROM $table WHERE $searchCondition LIMIT $limit OFFSET $offset");
            $totalResults = $db_adapter->db_query("SELECT COUNT(*) FROM $table WHERE $searchCondition");
        }

        $data = array(
            'columns' => array_map(function($column) { return $column['column_name']; }, $columns),
            'results' => $results,
            'totalResults' => $totalResults[0],
            'limit' => $limit,
            'currentPage' => $page
        );
        
        echo json_encode($data);
        die();
    }
?>
<div class="h-full flex overflow-x-clip bg-gray-100 rounded-xl shadow-md m-4 mt-0 p-4">
    <div class="basis-1/6 flex flex-col gap-6">  
        <p>Settings</p>
        <ul class="w-full flex flex-col gap-6" id="itam_nav">
            <li onclick="loadTable('location')" class="bg-white py-2 px-4 rounded-l-lg pr-0">Account</li>
            <li onclick="loadTable('device_port')" class="bg-white py-2 px-4 rounded-lg mr-4">Appearance</li>
            <li onclick="loadTable('connection')" class="bg-white py-2 px-4 rounded-lg mr-4">Notifications</li>
            <li onclick="loadTable('device')" class="bg-white py-2 px-4 rounded-lg mr-4">Configuration</li>
        </ul>
    </div>
    <div class="h-full basis-5/6 flex bg-white rounded-lg">
        <div class="h-fit w-full p-4"><pre>
            Account: Nutzername, Passwort, E-Mail-Adresse, Sprache
            Appearance: Farbschema, Schriftart, Schriftgröße
            Notifications: Benachrichtigungen
            Configuration: Datenbank, LDAP, Mail

            ACL:
            - LDAP Accounts manuell erlauben
            - Rollen verwalten
            - Benutzer verwalten
        </pre></div>
    </div>
</div>
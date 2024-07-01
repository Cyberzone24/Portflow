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
    } elseif ($_GET['action'] === 'view') {
        $limit = $_GET['limit'];
        $limit = isset($_COOKIE['table_limit']) ? $_COOKIE['table_limit'] : 100;
        $page = isset($_GET['page']) ? $_GET['page'] : 1;
        $offset = ($page - 1) * $limit;
        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'created';
        $order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
        $table = isset($_GET['table']) ? $_GET['table'] : 'location';

        if (empty($_GET['search'])) {
            $results = $db_adapter->db_query("SELECT * FROM $table ORDER BY $sort $order LIMIT $limit OFFSET $offset");
            $totalResults = $db_adapter->db_query("SELECT COUNT(*) FROM $table");
        } else {
            $search = $_GET['search'];
        
            // Tabellenspalten abfragen
            $columnsResult = $db_adapter->db_query("SELECT column_name FROM information_schema.columns WHERE table_name = $table");
            $columns = [];
            foreach ($columnsResult as $row) {
                $columns[] = $row['column_name'];
            }
        
            // Bedingung für die Suchabfrage erstellen
            $searchConditions = [];
            foreach ($columns as $column) {
                if ($column !== 'uuid') {
                    $searchConditions[] = "$column iLIKE $search";
                }
            }
            $searchCondition = implode(' OR ', $searchConditions);
        
            // Suchabfrage ausführen
            $results = $db_adapter->db_query("SELECT * FROM $table WHERE $searchCondition ORDER BY $sort $order LIMIT $limit OFFSET $offset");
            $totalResults = $db_adapter->db_query("SELECT COUNT(*) FROM $table WHERE $searchCondition");
        }

        $data = array(
            'results' => $results,
            'devices' => PORTFLOW_DEVICES,
            'totalResults' => $totalResults[0],
            'limit' => $limit,
            'currentPage' => $page
        );
        
        echo json_encode($data);
        die();
    }
?>
<div class="h-full flex overflow-x-clip bg-gray-100 rounded-xl shadow-md m-4 mt-0 p-4">
    <ul class="basis-1/6 flex flex-col gap-6">
        <li>IT Asset-Management</li>
        <a href="location" class="bg-white py-2 px-4 rounded-l-lg pr-0"><li>Location</li></a>
        <a href="device" class="bg-white py-2 px-4 rounded-lg mr-4"><li>Device</li></a>
        <a href="device_port" class="bg-white py-2 px-4 rounded-lg mr-4"><li>Device Port</li></a>
        <a href="connection" class="bg-white py-2 px-4 rounded-lg mr-4"><li>Connection</li></a>
        <a href="vlan" class="bg-white py-2 px-4 rounded-lg mr-4"><li>VLAN</li></a>
    </ul>
    <div class="h-full basis-5/6 flex bg-white rounded-lg">
        <?php
            if (isset($_GET['table'])) {
                $table = $_GET['table'];
                $results = $db_adapter->db_query("SELECT * FROM $table");
                $columns = $db_adapter->db_query("SELECT column_name FROM information_schema.columns WHERE table_name = '$table'");
                $columns = array_map(function($column) {
                    return $column['column_name'];
                }, $columns);
                $columns = array_diff($columns, ['uuid']);

                echo '<table class="w-full h-fit text-left">';
                echo '<thead>';
                echo '<tr>';
                foreach ($columns as $column) {
                    echo "<th>$column</th>";
                }
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';
                foreach ($results as $result) {
                    echo '<tr>';
                    foreach ($columns as $column) {
                        echo "<td>$result[$column]</td>";
                    }
                    echo '</tr>';
                }
                echo '</tbody>';
                echo '</table>';
            }
        ?>
    </div>
</div>
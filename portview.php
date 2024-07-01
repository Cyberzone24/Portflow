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
        $limit = $_GET['limit'];
        $limit = isset($_COOKIE['table_limit']) ? $_COOKIE['table_limit'] : 100;
        $page = isset($_GET['page']) ? $_GET['page'] : 1;
        $offset = ($page - 1) * $limit;
        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'created';
        $order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

        if (empty($_GET['search'])) {
            $results = $db_adapter->db_query("SELECT * FROM ports ORDER BY $sort $order LIMIT $limit OFFSET $offset");
            $totalResults = $db_adapter->db_query("SELECT COUNT(*) FROM ports");
        } else {
            $search = $_GET['search'];
            $results = $db_adapter->db_query("SELECT * FROM ports WHERE speed iLIKE '%$search%' OR device iLIKE '%$search%' OR status iLIKE '%$search%' OR room iLIKE '%$search%' OR port iLIKE '%$search%' OR hostname iLIKE '%$search%' OR vlan_tagged iLIKE '%$search%' OR vlan_untagged iLIKE '%$search%' OR mac iLIKE '%$search%' OR cable_number iLIKE '%$search%' OR panel iLIKE '%$search%' OR switch_name iLIKE '%$search%' OR switch_port iLIKE '%$search%' OR comment iLIKE '%$search%' OR tags iLIKE '%$search%' ORDER BY $sort $order LIMIT $limit OFFSET $offset");
            $totalResults = $db_adapter->db_query("SELECT COUNT(*) FROM ports WHERE speed iLIKE '%$search%' OR device iLIKE '%$search%' OR status iLIKE '%$search%' OR room iLIKE '%$search%' OR port iLIKE '%$search%' OR hostname iLIKE '%$search%' OR vlan_tagged iLIKE '%$search%' OR vlan_untagged iLIKE '%$search%' OR mac iLIKE '%$search%' OR cable_number iLIKE '%$search%' OR panel iLIKE '%$search%' OR switch_name iLIKE '%$search%' OR switch_port iLIKE '%$search%' OR comment iLIKE '%$search%' OR tags iLIKE '%$search%'");
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
    } elseif ($_GET['action'] === 'insert') {
        $query = 'INSERT INTO ports (speed, device, status, room, port, hostname, vlan_tagged, vlan_untagged, mac, cable_number, panel, switch_name, switch_port, comment, created, last_changed, tags) VALUES (:speed, :device, :status, :room, :port, :hostname, :vlan_tagged, :vlan_untagged, :mac, :cable_number, :panel, :switch_name, :switch_port, :comment, :created, :last_changed, :tags)';
        $params = [
            'speed' => !empty($_POST['speed']) ? $_POST['speed'] : '--',
            'device' => !empty($_POST['device']) ? $_POST['device'] : '--',
            'status' => !empty($_POST['status']) ? $_POST['status'] : 'unpatched',
            'room' => $_POST['room'],
            'port' => $_POST['port'],
            'hostname' => $_POST['hostname'],
            'vlan_tagged' => $_POST['vlan_tagged'],
            'vlan_untagged' => $_POST['vlan_untagged'],
            'mac' => $_POST['mac'],
            'cable_number' => $_POST['cable_number'],
            'panel' => $_POST['panel'],
            'switch_name' => $_POST['switch_name'],
            'switch_port' => $_POST['switch_port'],
            'comment' => $_POST['comment'],
            'created' => 'NOW()',
            'last_changed' => 'NOW()',
            'tags' => strtolower($_POST['tags'])
        ];
        $results = $db_adapter->db_query($query, $params);
    } elseif ($_GET['action'] === 'delete') {
        $query = 'DELETE FROM ports WHERE uuid = :uuid';
        $params = [
            'uuid' => $_GET['uuid']
        ];
        $results = $db_adapter->db_query($query, $params);
    } elseif ($_GET['action'] === 'update') {
        $query = 'UPDATE ports SET speed = :speed, device = :device, status = :status, room = :room, port = :port, hostname = :hostname, vlan_tagged = :vlan_tagged, vlan_untagged = :vlan_untagged, mac = :mac, cable_number = :cable_number, panel = :panel, switch_name = :switch_name, switch_port = :switch_port, comment = :comment, tags = :tags, last_changed = :last_changed WHERE uuid = :uuid';
        $params = [
            'speed' => !empty($_POST['speed']) ? $_POST['speed'] : '--',
            'device' => !empty($_POST['device']) ? $_POST['device'] : '--',
            'status' => !empty($_POST['status']) ? $_POST['status'] : 'unpatched',
            'room' => $_POST['room'],
            'port' => $_POST['port'],
            'hostname' => $_POST['hostname'],
            'vlan_tagged' => $_POST['vlan_tagged'],
            'vlan_untagged' => $_POST['vlan_untagged'],
            'mac' => $_POST['mac'],
            'cable_number' => $_POST['cable_number'],
            'panel' => $_POST['panel'],
            'switch_name' => $_POST['switch_name'],
            'switch_port' => $_POST['switch_port'],
            'comment' => $_POST['comment'],
            'tags' => $_POST['tags'],
            'last_changed' => 'NOW()',
            'uuid' => $_GET['uuid']
        ];
        $results = $db_adapter->db_query($query, $params);
    } elseif ($_GET['action'] === 'import') {
        // Define the valid column names
        $validColumnNames = ['speed', 'device', 'status', 'room', 'port', 'hostname', 'vlan_tagged', 'vlan_untagged', 'mac', 'cable_number', 'panel', 'switch_name', 'switch_port', 'comment', 'created', 'last_changed', 'tags'];

        $overrideFromCSV = true; // Set this to true or false as per your requirement
        $supplementFromCSV = true; // Set this to true or false as per your requirement

        $csv = array();

        if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
            // Ihr Code zum Verarbeiten der hochgeladenen Datei
        } else {
            // Fehlerbehandlung
            echo json_encode("Es gab einen Fehler beim Hochladen der Datei.");
        }

        // check there are no errors
        if ($_FILES['file']['error'] == 0) {
            $name = $_FILES['file']['name'];
            $fileNameParts = explode('.', $_FILES['file']['name']);
            $ext = strtolower(end($fileNameParts));
            $type = $_FILES['file']['type'];
            $tmpName = $_FILES['file']['tmp_name'];

            // check the file is a csv
            if ($ext === 'csv') {
                if (($handle = fopen($tmpName, 'r')) !== FALSE) {
                    // necessary if a large csv file
                    set_time_limit(0);

                    $row = 0;

                    // Get the column names from the first row
                    $columnNames = fgetcsv($handle, 1000, ';');

                    while (($data = fgetcsv($handle, 1000, ';')) !== FALSE) {
                        // Combine the column names with the data
                        $data = array_combine($columnNames, $data);

                        // number of fields in the csv
                        $col_count = count($data);

                        // get the values from the csv
                        if (isset($data[0])) {
                            $csv[$row]['col1'] = $data[0];
                            $csv[$row]['col2'] = isset($data[1]) ? $data[1] : null;
                        } else {
                            // Handle the case where $data[0] is not set
                            $csv[$row]['col1'] = null; // or some other default value
                        }
                        // inc the row
                        $row++;
                    }
                    fclose($handle);
                }
            }
        }

        $tmpName = $_FILES['file']['tmp_name'];
        $csvAsArray = array_map(function($v){return str_getcsv($v, ";");}, file($tmpName));

        // Get the column names from the first row
        $columnNames = array_shift($csvAsArray);

        foreach ($csvAsArray as $csvArray) {
            // Ensure $columnNames and $csvArray have the same number of elements
            $length = min(count($columnNames), count($csvArray));
            $columnNames = array_slice($columnNames, 0, $length);
            $csvArray = array_slice($csvArray, 0, $length);

            // Combine the column names with the data
            $data = array_combine($columnNames, $csvArray);

            // Filter out empty values
            $data = array_filter($data, function($value) { return $value !== ''; });

            // Set 'NOW()' for 'created' and 'last_changed' if not set
            if (!isset($data['created']) || $data['created'] === '') {
                $data['created'] = 'NOW()';
            }
            if (!isset($data['last_changed']) || $data['last_changed'] === '') {
                $data['last_changed'] = 'NOW()';
            }

            // Check if a record with the same (switch_name and switch_port) or (room and port) already exists
            $selectQuery = 'SELECT * FROM ports WHERE (switch_name = :switch_name AND switch_port = :switch_port) OR (room = :room AND port = :port)';
            $selectParams = [
                'switch_name' => $data['switch_name'],
                'switch_port' => $data['switch_port'],
                'room' => $data['room'],
                'port' => $data['port']
            ];
            $existingRecord = $db_adapter->db_query($selectQuery, $selectParams);

            if ($existingRecord) {
                if (isset($existingRecord[0]['created']) || !empty($existingRecord[0]['created'])) {
                    $data['created'] = $existingRecord['created'];
                }
            }

            if ($existingRecord && $overrideFromCSV) {
                if ($supplementFromCSV) {
                    // Replace the existing data with the new data
                    $data = array_replace($existingRecord[0], $data);                
                }

                $keys = array_filter(array_keys($data), function($key) use ($validColumnNames) {
                    return in_array($key, $validColumnNames) && !is_numeric($key);
                });

                // Update the existing record
                $query = 'UPDATE ports SET ' . implode(', ', array_map(function($key) { return "$key = :$key"; }, $keys)) . ' WHERE uuid = :uuid';
            } else {
                // Ensure all valid columns are in $data
                foreach ($validColumnNames as $columnName) {
                    if (!array_key_exists($columnName, $data)) {
                        $data[$columnName] = '';
                    }
                }

                // Convert all empty values in $data to empty strings
                $data = array_map(function($value) {
                    return $value === '' ? '' : $value;
                }, $data);

                // Insert a new record
                $keys = array_keys($data);
                $query = "INSERT INTO ports (".implode(",", $keys).") VALUES (:".implode(", :", $keys).")";
            }

            // Execute the SQL statement
            $results = $db_adapter->db_query($query, $data);
        }
    }
?>
<div class="h-full relative overflow-x-clip bg-gray-100 rounded-xl shadow-md m-4 mt-0 p-4">
    <div class="flex justify-between mb-4">
        <p id="count"></p>
        <div class="flex flex-row">        
            <form id="searchForm" class="flex flex-row" enctype="multipart/form-data">
                <input type="text" name="search" placeholder="Suchen ..." class="rounded-full px-4 py-2 shadow-md">
                <div class="h-10 w-10 ml-2 rounded-full bg-blue-500 hover:bg-blue-700 flex justify-center shadow-md">
                    <button type="submit" class="text-2xl text-white"><i class="fa-solid fa-magnifying-glass"></i></button>
                </div>
            </form>
            <div class="h-10 w-10 ml-4 rounded-full bg-cyan-500 hover:bg-cyan-700 flex justify-center shadow-md">
                <button form="" onclick="import_export()" class="import_export_button text-2xl text-white"><i class="fa-solid fa-file-import"></i></button>
            </div>
            <div class="h-10 w-10 ml-4 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md">
                <button form="" onclick="spawn_new_entry()" class="new_entry_button text-2xl text-white"><i class="fa-solid fa-plus"></i></button>
            </div>
        </div>
    </div>
    <div id="import_export"></div>
    <div class="flex justify-between my-4">
        <div id="pagination" class="flex flex-row"></div>
        <div class="flex flex-row">
            <p class="mr-4">Anzahl:</p>
            <select id="table_limit_1" name="limit" class="bg-transparent" onchange="setTableLimit(this.value)">
                <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50</option>
                <option value="100" <?php if ($limit == 100) echo 'selected'; ?>>100</option>
                <option value="500" <?php if ($limit == 500) echo 'selected'; ?>>500</option>
                <option value="1000" <?php if ($limit == 1000) echo 'selected'; ?>>1000</option>
            </select>
        </div>
    </div>
    <table class="static rounded-lg w-full text-sm text-left mb-4 text-gray-500 shadow-md">
        <thead class="text-gray-800">
            <tr class="border-b bg-gray-200">
                <th scope="col" class="p-2" data-sort="status">
                    Status <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="room">
                    Raum <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="port">
                    Dosenport <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="hostname">
                    Hostname <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="vlan_untagged">
                    VLAN <span class="sort-icon"></span><br><span class="text-xs">(untagged)</span>
                </th>
                <th scope="col" class="p-2" data-sort="vlan_tagged">
                    VLAN <span class="sort-icon"></span><br><span class="text-xs">(tagged)</span>
                </th>
                <th scope="col" class="p-2" data-sort="mac">
                    MAC-Adresse <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="cable_number">
                    Kabelnummer <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="panel">
                    Panel <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="switch_name">
                    Switch <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="switch_port">
                    Switchport <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="comment">
                    Kommentar <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2" data-sort="tags">
                    Tags <span class="sort-icon"></span>
                </th>
                <th scope="col" class="p-2">
                    Aktionen
                </th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    <div class="flex justify-between mt-4 mb-8">
        <div id="pagination_bottom" class="flex flex-row">
        </div>
        <div class="flex flex-row">
            <p class="mr-4">Anzahl:</p>
            <select id="table_limit_2" name="limit" class="bg-transparent" onchange="setTableLimit(this.value)">
                <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50</option>
                <option value="100" <?php if ($limit == 100) echo 'selected'; ?>>100</option>
                <option value="500" <?php if ($limit == 500) echo 'selected'; ?>>500</option>
                <option value="1000" <?php if ($limit == 1000) echo 'selected'; ?>>1000</option>
            </select>
        </div>
    </div>
</div>
<script>
    // sort table
    $(document).ready(function() {
        var currentSort = '';
        var currentOrder = '';
        var query = '';
        var limit = 100;
        var page = 1;

        // Update query, limit, and page when loadTable is called
        var originalLoadTable = loadTable;
        loadTable = function(newQuery, newLimit, newPage, sort, order) {
            if (newQuery !== undefined) query = newQuery;
            if (newLimit !== undefined) limit = newLimit;
            if (newPage !== undefined) page = newPage;
            originalLoadTable(query, limit, page, sort, order);
        };

        $('th[data-sort]').click(function() {
            var sort = $(this).data('sort');
            if (currentSort == sort) {
                currentOrder = (currentOrder == 'ASC') ? 'DESC' : 'ASC';
            } else {
                currentSort = sort;
                currentOrder = 'ASC';
            }

            loadTable(undefined, undefined, undefined, sort, currentOrder);

            // Remove all existing arrows
            $('.sort-icon').text('');

            // Add arrow to the current cell
            $(this).find('.sort-icon').text(currentOrder == 'ASC' ? '↑' : '↓');
        });
    });
    // set table limit
    function setTableLimit(limit) {
        document.cookie = `table_limit=${limit}; SameSite=Lax`;
        loadTable('', limit);
    }
    document.getElementById('table_limit_1').addEventListener('change', function() {
        document.getElementById('table_limit_2').value = this.value;
        setTableLimit(this.value);
    });
    document.getElementById('table_limit_2').addEventListener('change', function() {
        document.getElementById('table_limit_1').value = this.value;
        setTableLimit(this.value);
    });
    // generate pagination
    function generatePagination(totalPages, currentPage, query, limit) {
        var pagesPerGroup = 10;
        var pageGroup = Math.floor((currentPage - 1) / pagesPerGroup);

        // Leeren Sie das vorhandene Div
        $('#pagination, #pagination_bottom').empty();

        // Fügen Sie den Text 'Seite: ' hinzu
        $('#pagination').append('<div class="mr-2">Seite: </div>');

        // Fügen Sie eine Schaltfläche hinzu, um zur vorherigen Gruppe von Seiten zu navigieren
        var prevButton = $('<div class="mr-2 cursor-pointer">&larr;</div>');
        if (pageGroup > 0) {
            prevButton.click(function() {
                generatePagination(totalPages, (pageGroup - 1) * pagesPerGroup + 1, query, limit);
            });
        } else {
            prevButton.css('visibility', 'hidden');
        }
        $('#pagination').append(prevButton);

        // Durchlaufen Sie die Seiten in der aktuellen Gruppe
        for (var i = pageGroup * pagesPerGroup + 1; i <= Math.min((pageGroup + 1) * pagesPerGroup, totalPages); i++) {
            // Erstellen Sie ein neues div für jede Seitenzahl
            var pageDiv = $('<div class="mr-2 cursor-pointer"></div>');
            pageDiv.text(i);

            // Wenn es die aktuelle Seite ist, fügen Sie eine Klasse hinzu, um sie hervorzuheben
            if (i == currentPage) {
                pageDiv.addClass('current-page text-blue-500');
            }

            // Fügen Sie einen Klick-Event-Handler hinzu, der die Funktion loadTable aufruft
            pageDiv.click(function() {
                loadTable(query, limit, $(this).text());
            });

            // Fügen Sie die Seitenzahl zur Paginierungsleiste hinzu
            $('#pagination').append(pageDiv);
        }

        // Fügen Sie eine Schaltfläche hinzu, um zur nächsten Gruppe von Seiten zu navigieren
        var nextButton = $('<div class="mr-2 cursor-pointer">&rarr;</div>');
        if ((pageGroup + 1) * pagesPerGroup < totalPages) {
            nextButton.click(function() {
                generatePagination(totalPages, (pageGroup + 1) * pagesPerGroup + 1, query, limit);
            });
        } else {
            nextButton.css('visibility', 'hidden');
        }
        $('#pagination').append(nextButton);

        // Clone the pagination to 'pagination_bottom'
        $('#pagination_bottom').html($('#pagination').clone(true));
    }
    // load table
    function loadTable(query = '', limit = 100, page = 1, sort = 'created', order = 'DESC') {
        var url = '?action=get&search=' + encodeURIComponent(query) + '&limit=' + limit + '&page=' + page + '&sort=' + sort + '&order=' + order;
        $.ajax({
            url: url,
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                var tableBody = $('.static tbody');
                tableBody.empty();
                var results = data.results;
                var devices = data.devices;
                var totalResults = parseInt(data.totalResults.count);
                var totalPages = Math.ceil(totalResults / data.limit);
                var currentPage = data.currentPage;

                // assign totalResults to the #count
                $('#count').text('Datensätze: ' + totalResults);

                results.forEach(function(row) {
                    var tr = $('<tr class="hover:bg-gray-200">');

                    // generate tags with randomized colors
                    var tags = "";
                    if (row['tags']) {
                        var tagColors = ['bg-orange-400', 'bg-lime-400', 'bg-emerald-400', 'bg-cyan-400', 'bg-indigo-400', 'bg-fuchsia-400', 'bg-rose-400'];
                        row['tags'].split(',').forEach(function(tag) {
                            tag = tag.trim();
                            var tagHash = tag.split('').reduce((prevHash, currVal) => ((prevHash << 5) - prevHash) + currVal.charCodeAt(0), 0);
                            var tagColor = tagColors[Math.abs(tagHash) % tagColors.length];
                            tags += "<span class='py-1 px-2 rounded-full text-white " + tagColor + " mr-2 mb-2' style='line-height: 2;'>#" + tag + "</span> ";
                        });
                    }

                    // generate status info
                    var status = "";
                    switch (row['status']) {
                        case 'active':
                            status = 'text-green-500';
                            break;
                        case 'inactive':
                            status = 'text-yellow-500';
                            break;
                        case 'unpatched':
                            status = 'text-red-500';
                            break;
                        default:
                            status = 'text-blue-500';
                    }

                    var device = devices[row['device']] || devices['other'];

                    // generate speed info
                    var speed = "";
                    switch (row['speed']) {
                        case '100':
                            speed = '100 Mbit/s';
                            break;
                        case '1000':
                            speed = '1 Gbit/s';
                            break;
                        case '2500':
                            speed = '2.5 Gbit/s';
                            break;
                        case '10000':
                            speed = '10 Gbit/s';
                            break;
                        case '25000':
                            speed = '25 Gbit/s';
                            break;
                        case '40000':
                            speed = '40 Gbit/s';
                            break;
                        case '100000':
                            speed = '100 Gbit/s';
                            break;
                        default:
                            speed = '--';
                    }

                    let createdDate = new Date(row.created);
                    let formattedCreatedDate = createdDate.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + ' Uhr, ' + createdDate.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });

                    let lastChangedDate = new Date(row.last_changed);
                    let formattedLastChangedDate = lastChangedDate.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + ' Uhr, ' + lastChangedDate.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });

                    // Add the generated info to the table row
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto whitespace-nowrap" title="Status: ' + row.status + '\nGerät: ' + row.device + '\nGeschwindigkeit: ' + speed + '"><span class="' + status + ' text-xl">' + device + '</span> ' + speed + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.room + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.port + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.hostname + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.vlan_untagged + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.vlan_tagged + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.mac + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.cable_number + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.panel + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.switch_name + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.switch_port + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + row.comment + '</td>');
                    tr.append('<td class="p-2 border-b max-w-lg overflow-auto">' + tags + '</td>');
                    tr.append("<td class='p-2 border-b text-xl max-w-xl overflow-auto whitespace-nowrap'><button onclick=\"info_entry('i" + formattedCreatedDate + "', '" + formattedLastChangedDate + "', '" + row.status + "', '" + row.device + "', '" + speed + "', '" + row.speed + "')\" class='px-2 mr-2 text-blue-500 hover:text-blue-700'><i class='fa-solid fa-info'></i></button><button onclick='edit_entry(\"u" + row.uuid + "\", this)' class='px-2 mr-2 text-yellow-500 hover:text-yellow-700'><i class='fa-regular fa-pen-to-square'></i></button><button onclick='delete_entry(\"u" + row.uuid + "\", this)' class='px-2 text-red-500 hover:text-red-700'><i class='fa-regular fa-trash-can'></i></button></td>");
                    tableBody.append(tr);
                });
                generatePagination(totalPages, currentPage, query, limit);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log('Error:', jqXHR.responseText);
            }
        });
    }
    $(document).ready(function() {
        loadTable();
    });
    // search
    $(document).ready(function() {
        $('#searchForm').on('submit', function(event) {
            event.preventDefault();
            var query = $(this).find('input[name="search"]').val();
            loadTable(query);
        });
    });
    // import/export
    var isImportingExporting = false;
    function import_export() {
        if (isImportingExporting) {
            return;
        }
        isImportingExporting = true;

        // Get the import_export div
        var importExportDiv = document.getElementById('import_export');
        importExportDiv.className = "width-full p-4 bg-white rounded-3xl shadow-md";

        // Create the title
        var title = document.createElement('p');
        title.className = "font-lg pb-4 text-center";
        title.textContent = "Import/Export Assistent";
        importExportDiv.appendChild(title);

        // Create the flex container
        var flexContainer = document.createElement('div');
        flexContainer.className = "flex justify-between";
        importExportDiv.appendChild(flexContainer);

        // Create the action select element
        var actionDiv = document.createElement('div');
        actionDiv.className = "flex flex-row items-center";
        actionDiv.innerHTML = `
            <p class="mr-2">Aktion: </p>
            <select id="action" class="rounded-full px-4 py-2 bg-gray-200">
                <option value="import" selected>Import</option>
                <option value="export">Export</option>
            </select>
        `;
        flexContainer.appendChild(actionDiv);

        // Create the file input element
        var fileDiv = document.createElement('div');
        fileDiv.id = 'fileDiv';
        fileDiv.className = "flex flex-row items-center";
        fileDiv.innerHTML = `
            <p class="mr-2">Datei: </p>
            <label for="file" class="rounded-full px-4 py-2 bg-gray-200">Durchsuchen...</label>
            <input type="file" id="file" name="file" style="display: none;" accept=".csv, .json, .sql">
        `;
        flexContainer.appendChild(fileDiv);

        // Create the format select element
        var formatDiv = document.createElement('div');
        formatDiv.className = "flex flex-row items-center";
        formatDiv.innerHTML = `
            <p class="mr-2">Format: </p>
            <select id="format" class="rounded-full px-4 py-2 bg-gray-200">
                <option value="csv" selected>CSV</option>
                <option value="json">JSON</option>
                <option value="sql">SQL</option>
            </select>
        `;
        flexContainer.appendChild(formatDiv);

        // Create the execute and cancel buttons
        var buttonDiv = document.createElement('div');
        buttonDiv.className = "flex flex-row items-center";
        buttonDiv.innerHTML = `
            <div class='text-xl flex items-center'>
                <div class='h-10 w-10 ml-4 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md'>
                    <button id='execute' type='button' onclick='new_entry(this)' class='text-2xl text-white'><i class="fa-solid fa-download"></i></button>
                </div>
                <div class='h-10 w-10 ml-4 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md'>
                    <button id='cancel' type='button' onclick='cancel_new_entry(this)' class='text-2xl text-white'><i class='fa-solid fa-xmark'></i></button>
                </div>
            </div>
        `;
        flexContainer.appendChild(buttonDiv);

        // Add event listeners
        document.getElementById('action').addEventListener('change', function() {
            if (this.value === 'export') {
                flexContainer.removeChild(fileDiv);
                document.getElementById('execute').innerHTML = '<i class="fa-regular fa-floppy-disk"></i>';
            } else {
                flexContainer.insertBefore(fileDiv, formatDiv);
                document.getElementById('execute').innerHTML = '<i class="fa-solid fa-download"></i>';
            }
        });

        document.getElementById('execute').addEventListener('click', function() {
            isImportingExporting = false;
            var action = document.getElementById('action').value;
            var format = document.getElementById('format').value;
            var file = document.getElementById('file') ? document.getElementById('file').files[0] : null;

            var formData = new FormData();
            if (file) {
                formData.append('file', file);
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '?action=' + action + '&format=' + format, true);

            // Create a progress element
            var progress = document.createElement('progress');
            progress.max = 100;
            progress.value = 0;
            importExportDiv.appendChild(progress);

            // Update the progress element when the upload progress changes
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    progress.value = (e.loaded / e.total) * 100;
                }
            });

            xhr.onload = function () {
                if (xhr.status === 200) {
                    alert('Erfolgreich!');
                    loadTable();
                } else {
                    alert('Fehler!');
                }
                // Remove the progress element when the upload is complete
                importExportDiv.removeChild(progress);
            };
            xhr.send(formData);
        });

        document.getElementById('cancel').addEventListener('click', function() {
            isImportingExporting = false;
            importExportDiv.innerHTML = '';
            importExportDiv.className = '';
        });
    }
    // info row
    function info_entry(created, last_changed, status, device, speed) {
        created = created.substring(1);

        // Close existing popup if it exists
        var existingPopup = document.getElementById('infoPopup');
        if (existingPopup) {
            document.body.removeChild(existingPopup);
        }

        // create popup, hide and add content
        var popup = document.createElement('div');
        popup.id = 'infoPopup';
        popup.style.display = 'none';
        popup.innerHTML = `
            <h2 class="text-2xl font-bold">Information</h2>
            <p>Created: ${created}</p>
            <p>Last Changed: ${last_changed}</p>
            <p>Status: ${status}</p>
            <p>Gerät: ${device}</p>
            <p>Geschwindigkeit: ${speed}</p>
            <button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full" onclick="info_close(this)">Close</button>
        `;
        document.body.appendChild(popup);

        // add css
        popup.classList.add(
            'block', 
            'fixed', 
            'transform', 
            '-translate-x-1/2', 
            '-translate-y-1/2', 
            'top-1/2', 
            'left-1/2', 
            'p-8', 
            'bg-gray-300', 
            'rounded-lg'
        );
        popup.style.display = 'block';
    }
    function info_close(button) {
        var popup = event.target.closest('#infoPopup');
        if (popup) {
            document.body.removeChild(popup);
        }
    }
    // auto-select dropdown menu items -> spawn new entry
    document.addEventListener('DOMContentLoaded', (event) => {
        document.body.addEventListener('change', function(e) {
            if (e.target.name === 'status') {
                const statusSelect = document.querySelector('select[name="status"]');
                const speedSelect = document.querySelector('select[name="speed"]');
                const deviceSelect = document.querySelector('select[name="device"]');

                if (e.target.value === 'unpatched') {
                    speedSelect.value = '--';
                    deviceSelect.value = '--';
                } else {
                    speedSelect.value = '1000';
                }
            }
        });
    });
    // spawn new entry
    var isAddingNewEntry = false;
    function spawn_new_entry() {
        if (isAddingNewEntry) {
            return;
        }
        isAddingNewEntry = true;

        var tableHeaderWidth = document.querySelector('table thead').offsetWidth;

        const tbody = document.querySelector("tbody");
        const newRow = `
            <tr class='new_entry_row'>
                <td class='w-full border-b' colspan="14">
                    <form class='new_entry flex items-center' enctype="multipart/form-data">
                        <div class='flex-grow flex flex-col'>
                            <select name='status' class='w-full py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent'>
                                <option value='' disabled selected>Status</option>
                                <optgroup>
                                    <option value='active'>aktiv</option>
                                    <option value='inactive'>inaktiv</option>
                                    <option value='unpatched'>ungepatcht</option>
                                    <option value='other'>Sonstige</option>
                                </optgroup>
                            </select>
                            <select name='speed' class='w-full py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent'>
                                <option value='' disabled selected>Geschwindigkeit</option>
                                <optgroup>
                                    <option value='--'>--</option>
                                    <option value='100'>100 MBit/s</option>
                                    <option value='1000'>1 GBit/s</option>
                                    <option value='2500'>2.5 GBit/s</option>
                                    <option value='10000'>10 GBit/s</option>
                                    <option value='25000'>25 GBit/s</option>
                                    <option value='40000'>40 GBit/s</option>
                                    <option value='100000'>100 Gbit/s</option>
                                </optgroup>
                            </select>
                            <select name='device' class='w-full py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent'>
                                <option value='' disabled selected>Gerät</option>
                                <optgroup>
                                    <option value='--'>--</option>
                                    <option value='phone'>Telefon</option>
                                    <option value='notebook'>Laptop</option>
                                    <option value='switch'>Switch</option>
                                    <option value='zeroclient'>Zeroclient</option>
                                    <option value='thinclient'>Thinclient</option>
                                    <option value='desktop'>Desktop</option>
                                    <option value='access_point'>Access Point</option>
                                    <option value='printer'>Drucker</option>
                                    <option value='other'>Sonstige</option>
                                </optgroup>
                            </select>
                        </div>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='room' placeholder='Raum'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='port' placeholder='Dosenport'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' name='hostname' placeholder='Hostname'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='vlan_untagged' placeholder='VLAN (untagged)'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='vlan_tagged' placeholder='VLAN (tagged)'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='mac' placeholder='MAC'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='cable_number' placeholder='Kabelnummer'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='panel' placeholder='Panel'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='switch_name' placeholder='Switch'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='switch_port' placeholder='Switchport'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='comment' placeholder='Kommentar'>
                        <input class='mr-2 py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' type='text' name='tags' placeholder='Tags'>
                        <div class='text-xl flex items-center'>
                            <div class='h-10 w-10 ml-4 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md'>
                                <button type='button' onclick='new_entry(this)' class='text-2xl text-white'><i class='fa-regular fa-floppy-disk'></i></button>
                            </div>
                            <div class='h-10 w-10 ml-4 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md'>
                                <button type='button' onclick='cancel_new_entry(this)' class='text-2xl text-white'><i class='fa-solid fa-xmark'></i></button>
                            </div>
                        </div>
                    </form>
                </td>
            </tr>`;
            tbody.insertAdjacentHTML('afterbegin', newRow);
        var formRow = document.querySelector('.new_entry');
        formRow.style.width = tableHeaderWidth + 'px';

        var tableHeaders = Array.from(document.querySelectorAll('table thead th'));
        var formElements = Array.from(document.querySelectorAll('.new_entry > *'));

        formElements.forEach((element, index) => {
            if (tableHeaders[index]) {
                element.style.width = tableHeaders[index].offsetWidth + 'px';
            }
        });
    }
    function cancel_new_entry(button) {
        var row = button.closest('.new_entry_row');
        if (row) {
            row.remove();
            isAddingNewEntry = false;
        } else {
            console.error('Kein Element mit der Klasse .new-entry-row gefunden');
        }
    }
    // post data
    function new_entry(button) {
        var form = $(button).closest('form');
        $.ajax({
            type: 'POST',
            url: '?action=insert',
            data: form.serialize(),
            success: function(response) {
                loadTable();
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error(textStatus, errorThrown);
            }
        });
    }
    // edit entry
    var isEditingEntry = false;
    function edit_entry(uuid, button) {
        if (isEditingEntry) {
            return;
        }
        isEditingEntry = true;

        var tableHeaderWidth = document.querySelector('table thead').offsetWidth;
        uuid = uuid.substring(1);
        var row = $(button).closest('tr');

        if (row && row.length > 0) {
            var cells = Array.from(row[0].children);
            if (cells) {
                // Ignorieren Sie den ersten und letzten Wert
                cells = cells.slice(1, -1);
                var values = cells.map(cell => cell.textContent);

                // Extrahieren Sie die Werte aus dem info_entry-Button
                var infoButton = row[0].querySelector('button[onclick^="info_entry"]');
                if (infoButton) {
                    var infoValues = infoButton.getAttribute('onclick');
                    infoValues = infoValues.substring(11, infoValues.length - 2).split("', '");
                    // Ignorieren Sie die ersten beiden Werte
                    infoValues = infoValues.slice(2);
                    values = values.concat(infoValues);
                }

                var status = values[12] || '';
                var speed = values[15] || '';
                var device = values[13] || '';
                var room = values[0] || '';
                var port = values[1] || '';
                var hostname = values[2] || '';
                var vlan_untagged = values[3] || '';
                var vlan_tagged = values[4] || '';
                var mac = values[5] || '';
                var cable_number = values[6] || '';
                var panel = values[7] || '';
                var switch_name = values[8] || '';
                var switch_port = values[9] || '';
                var comment = values[10] || '';
                var tags = values[11] || '';
            }
        }

        const newRow = `
            <tr class='edit_entry_row'>
                <td class='w-full border-b' colspan="14">
                    <form class='edit_entry flex items-center' enctype="multipart/form-data">
                        <div class='flex-grow flex flex-col'>
                            <select name='status' class='w-full py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent'>
                                <option disabled>Status</option>
                                <optgroup>
                                    <option value='active'>aktiv</option>
                                    <option value='inactive'>inaktiv</option>
                                    <option value='unpatched'>ungepatcht</option>
                                    <option value='other'>Sonstige</option>
                                </optgroup>
                            </select>
                            <select name='speed' class='w-full py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent'>
                                <option disabled>Geschwindigkeit</option>
                                <optgroup>
                                    <option value='--'>--</option>
                                    <option value='100'>100 MBit/s</option>
                                    <option value='1000'>1 GBit/s</option>
                                    <option value='2500'>2.5 GBit/s</option>
                                    <option value='10000'>10 GBit/s</option>
                                    <option value='25000'>25 GBit/s</option>
                                    <option value='40000'>40 GBit/s</option>
                                    <option value='100000'>100 Gbit/s</option>
                                </optgroup>
                            </select>
                            <select name='device' class='w-full py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent'>
                                <option disabled>Gerät</option>
                                <optgroup>
                                    <option value='--'>--</option>
                                    <option value='phone'>Telefon</option>
                                    <option value='notebook'>Laptop</option>
                                    <option value='switch'>Switch</option>
                                    <option value='zeroclient'>Zeroclient</option>
                                    <option value='thinclient'>Thinclient</option>
                                    <option value='desktop'>Desktop</option>
                                    <option value='access_point'>Access Point</option>
                                    <option value='printer'>Drucker</option>
                                    <option value='other'>Sonstige</option>
                                </optgroup>
                            </select>
                        </div>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + room + `' type='text' name='room' placeholder='Raum'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + port + `' type='text' name='port' placeholder='Dosenport'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + hostname + `' name='hostname' placeholder='Hostname'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + vlan_untagged + `' type='text' name='vlan_untagged' placeholder='VLAN (untagged)'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + vlan_tagged + `' type='text' name='vlan_tagged' placeholder='VLAN (tagged)'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + mac + `' type='text' name='mac' placeholder='MAC'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + cable_number + `' type='text' name='cable_number' placeholder='Kabelnummer'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + panel + `' type='text' name='panel' placeholder='Panel'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + switch_name + `' type='text' name='switch_name' placeholder='Switch'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + switch_port + `' type='text' name='switch_port' placeholder='Switchport'>
                        <input class='py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + comment + `' type='text' name='comment' placeholder='Kommentar'>
                        <input class='mr-2 py-1 px-2 border-solid border-2 boder-gray-400 rounded bg-transparent' value='` + tags + `' type='text' name='tags' placeholder='Tags'>                  
                        <div class='text-xl flex items-center'>
                            <div class='h-10 w-10 ml-4 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md'>
                                <button type='button' onclick='save_edit_entry("u` + uuid + `", this)' class='text-2xl text-white'><i class='fa-regular fa-floppy-disk'></i></button>
                            </div>
                            <div class='h-10 w-10 ml-4 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md'>
                                <button type='button' onclick='cancel_edit_entry(this)' class='text-2xl text-white'><i class='fa-solid fa-xmark'></i></button>
                            </div>
                        </div>
                    </form>
                </td>
            </tr>`;
        row[0].insertAdjacentHTML('afterend', newRow);
        row[0].style.display = 'none';

        var formRow = document.querySelector('.edit_entry');
        formRow.style.width = tableHeaderWidth + 'px';

        var tableHeaders = Array.from(document.querySelectorAll('table thead th'));
        var formElements = Array.from(document.querySelectorAll('.edit_entry > *'));

        formElements.forEach((element, index) => {
            // Verschieben Sie den Index um eine Position zurück
            var shiftedIndex = index - 1;

            // Überprüfen Sie, ob ein Wert an der verschobenen Position existiert
            if (values[shiftedIndex]) {
                // Wenn das aktuelle Element das Tags-Element ist, entfernen Sie die Hashtags
                if (element.name === 'tags') {
                    element.value = values[shiftedIndex].split(' ').map(tag => tag.replace('#', '')).join(', ').trim();
                    if (element.value.endsWith(',')) {
                        element.value = element.value.slice(0, -1);
                    }
                } else {
                    element.value = values[shiftedIndex];
                }
            }
            if (tableHeaders[index]) {
                element.style.width = tableHeaders[index].offsetWidth + 'px';
            }
        });

        // Definieren Sie die setSelectedValue Funktion innerhalb der edit_entry Funktion
        function setSelectedValue(selectName, value) {
            var selectElement = document.querySelector("select[name='" + selectName + "']");
            for (var i = 0; i < selectElement.options.length; i++) {
                if (selectElement.options[i].value == value) {
                    selectElement.options[i].selected = true;
                    break;
                }
            }
        }

        // Verwenden Sie die setSelectedValue Funktion, um die ausgewählten Werte für die <select>-Elemente zu setzen
        setSelectedValue('status', status);
        setSelectedValue('speed', speed);
        setSelectedValue('device', device);
    }

    function cancel_edit_entry(button) {
        var row = button.closest('.edit_entry_row');
        if (row) {
            row.previousElementSibling.style.display = '';
            row.remove();
            isEditingEntry = false;
        } else {
            console.error('Kein Element mit der Klasse .edit-entry-row gefunden');
        }
    }

    // post data
    function save_edit_entry(uuid, button) {
        var form = $(button).closest('form');
        uuid = uuid.substring(1);
        $.ajax({
            type: 'POST',
            url: '?action=update' + '&uuid=' + uuid,
            data: form.serialize(),
            success: function(response) {
                loadTable();
                isEditingEntry = false;
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error(textStatus, errorThrown);
            }
        });
    }
    // delete entry
    function delete_entry(uuid, button) {
        uuid = uuid.substring(1);
        var row = $(button).closest('tr');
        var confirmButton = $('<button class="confirm bg-green-500 text-white px-2 py-1 mt-2 mr-4 rounded-full">Ja</button>');
        var cancelButton = $('<button class="cancel bg-red-500 text-white px-2 py-1 mt-2 rounded-full">Nein</button>');
        var popup = $('<div class="absolute bg-white p-2 rounded-md max-w-xl mr-4" style="top: ' + ($(button).offset().top + parseInt($(button).css('line-height'))) + 'px; left: ' + ($(button).offset().left - 100) + 'px;">Sicher, dass dieser Eintrag gelöscht werden soll?<br></div>');
        popup.append(confirmButton);
        popup.append(cancelButton);
        $('body').append(popup);
        popup.show();

        popup.find('.confirm').click(function() {
            $.get('?action=delete' + '&uuid=' + uuid, function(data) {
                row.remove();
            });
            popup.hide();
        });

        popup.find('.cancel').click(function() {
            popup.hide();
        });
    }
</script>
<?php
    include_once 'includes/footer.php';
?>

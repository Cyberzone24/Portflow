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
        <p>IT Asset-Management</p>
        <ul class="w-full flex flex-col gap-6" id="itam_nav">
            <li onclick="loadTable('location')" class="bg-white py-2 px-4 rounded-l-lg pr-0">Location</li>
            <li onclick="loadTable('device')" class="bg-white py-2 px-4 rounded-lg mr-4">Device</li>
            <li onclick="loadTable('device_port')" class="bg-white py-2 px-4 rounded-lg mr-4">Device Port</li>
            <li onclick="loadTable('connection')" class="bg-white py-2 px-4 rounded-lg mr-4">Connection</li>
            <li onclick="loadTable('vlan')" class="bg-white py-2 px-4 rounded-lg mr-4">VLAN</li>
        </ul>
    </div>
    <div class="h-full basis-5/6 flex bg-white rounded-lg">
        <div class="h-fit w-full p-4">
            <div class="flex justify-between mb-4">
                <p id="count"></p>
                <div class="flex flex-row">
                    <form id="searchForm" class="flex flex-row" enctype="multipart/form-data">
                        <input type="text" name="search" placeholder="Suchen ..." class="rounded-full px-4 py-2 shadow-md">
                        <div class="h-10 w-10 ml-2 rounded-full bg-blue-500 hover:bg-blue-700 flex justify-center shadow-md">
                            <button type="submit" class="text-2xl text-white"><i class="fa-solid fa-magnifying-glass"></i></button>
                        </div>
                    </form>
                    <div class="h-10 w-10 ml-4 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md">
                        <button form="" onclick="spawn_new_entry()" class="new_entry_button text-2xl text-white"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
            </div>
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
                <thead class="text-gray-800"></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
<script>
    $(document).ready(function() {
        // Markieren Sie das 'Location'-Element (das erste klickbare Element) standardmäßig als ausgewählt
        $('#itam_nav > li:first-child').addClass('rounded-l-lg pr-0').removeClass('rounded-lg mr-4');
    
        // Event-Listener für Klicks auf li-Elemente innerhalb der ul mit der ID 'itam_nav'
        $('#itam_nav > li').click(function() {
            // Setzen Sie alle li-Elemente auf die Standardklassen zurück
            $('#itam_nav > li').removeClass('rounded-l-lg pr-0').addClass('bg-white py-2 px-4 rounded-lg mr-4');
            // Fügen Sie dem geklickten li-Element die spezifischen Klassen hinzu
            $(this).removeClass('rounded-lg mr-4').addClass('rounded-l-lg pr-0');
        });
    });
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
    function loadTable(table = 'location', query = '', limit = 100, page = 1, sort = 'created', order = 'DESC') {
        var url = '?action=get&table=' + table +'&search=' + encodeURIComponent(query) + '&limit=' + limit + '&page=' + page + '&sort=' + sort + '&order=' + order;
        $.ajax({
            url: url,
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                var tableHead = $('.static thead');
                var tableBody = $('.static tbody');
                tableHead.empty();
                tableBody.empty();

                // Create table head based on columns
                var trHead = $('<tr class="border-b bg-gray-200">');
                data.columns.forEach(function(column) {
                    var th = $('<th scope="col" class="p-2" data-sort="' + column + '">').text(column).append('<span class="sort-icon"></span>');
                    trHead.append(th);
                });
                tableHead.append(trHead);

                var results = data.results;
                var totalResults = parseInt(data.totalResults.count);
                var totalPages = Math.ceil(totalResults / data.limit);
                var currentPage = data.currentPage;

                // assign totalResults to the #count
                $('#count').text('Datensätze: ' + totalResults);

                results.forEach(function(row) {
                    var tr = $('<tr class="hover:bg-gray-200">');

                    // Loop through each column in the row
                    data.columns.forEach(function(column) {
                        var td = $('<td class="p-2 border-b max-w-lg overflow-auto">').text(row[column] || '--');
                        tr.append(td);
                    });

                    // Append the row to the table body
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
</script>
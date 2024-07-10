<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    const APP_NAME = 'Portflow';

    include_once __DIR__ . '/includes/core/session.php';
    if (!in_array(__DIR__ . '/includes/core/session.php', get_included_files())) {
        die('could not verify session');
    }

    include_once __DIR__ . '/includes/header.php';

    $limit = $_COOKIE['table_limit'] ?? 100;
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
    /*
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
    });*/
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
    function loadTable(table = 'location', query = '', limit = 100, page = 1) {
        var configUrl = '<?php echo PORTFLOW_HOSTNAME; ?>' + '/includes/lang.php?nav';
        $.ajax({
            url: configUrl,
            dataType: 'json',
            success: function(config) {
                var columnsConfig = config[table];
                var url = '<?php echo PORTFLOW_HOSTNAME; ?>' + '/api/' + table;
                $.ajax({
                    url: url,
                    type: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        var tableHead = $('.static thead');
                        var tableBody = $('.static tbody');
                        tableHead.empty();
                        tableBody.empty();

                        var trHead = $('<tr class="border-b bg-gray-200">');
                        Object.keys(columnsConfig).forEach(function(key) {
                            var th = $('<th scope="col" class="p-2" data-sort="' + key + '">').text(columnsConfig[key]);
                            trHead.append(th);
                        });
                        tableHead.append(trHead);

                        var results = data.items;
                        $('#count').text('Datensätze: ' + parseInt(data.pageInfo.totalResults));

                        results.forEach(function(row) {
                            var tr = $('<tr class="hover:bg-gray-200">');
                            Object.keys(columnsConfig).forEach(function(key) {
                                var td = $('<td class="p-2 border-b max-w-lg overflow-auto">').text(row[key] || '--');
                                tr.append(td);
                            });
                            tableBody.append(tr);
                        });

                        generatePagination(Math.ceil(data.pageInfo.totalResults / data.pageInfo.resultsPerPage), data.pageInfo.currentPage, query, limit);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.log('Error:', jqXHR.responseText);
                    }
                });
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log('Error loading table config:', jqXHR.responseText);
            }
        });
    }
    loadTable();
    // search
    $(document).ready(function() {
        $('#searchForm').on('submit', function(event) {
            event.preventDefault();
            var query = $(this).find('input[name="search"]').val();
            loadTable(query);
        });
    });
</script>
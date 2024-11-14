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

    $_SESSION['settings'] = '{"language": "de", "tables": { "location_join_metadata_join_location": ["type", "metadata_status_0", "metadata_tags_0", "metadata_caption_0"] } }';
?>
<div class="h-full flex overflow-x-clip bg-gray-100 rounded-xl shadow-md m-4 mt-0 p-4">
    <div class="basis-1/6 flex flex-col gap-6">  
        <p><?php echo $lang['it asset-management']; ?></p>
        <ul class="w-full flex flex-col gap-6" id="itam_nav">
            <li onclick="loadTable('location_join_metadata_join_location')" class="bg-white py-2 px-4 rounded-l-lg pr-0"><?php echo $lang['location']; ?></li>
            <li onclick="loadTable('ip_range_join_metadata')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['ipam']; ?></li>
            <li onclick="loadTable('vlan_join_metadata_join_ip_range')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['vlan']; ?></li>
            <li onclick="loadTable('device_join_metadata_join_location_join_location')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['devices']; ?></li>
            <li onclick="loadTable('device_port_join_metadata_join_device_join_vlan_join_vlan')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['device ports']; ?></li>
            <li onclick="loadTable('connection_join_metadata_join_device_port_join_device_port_join_device_port_join_device_port')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['connections']; ?></li>
        </ul>
    </div>
    <div class="h-full basis-5/6 flex bg-white rounded-lg relative">
        <div class="h-fit w-full p-4">
            <div class="flex justify-between mb-4">
                <p id="count"></p>
                <div class="flex flex-row">
                    <form id="searchForm" class="flex flex-row" enctype="multipart/form-data">
                        <input type="text" name="search" placeholder="Suchen ..." class="rounded-full px-4 py-2 shadow-md">
                        <div class="h-10 w-10 ml-2 rounded-full bg-blue-500 hover:bg-blue-700 flex justify-center shadow-md">
                            <button type="submit" class="text-2xl text-white"><i data-lucide="search"></i></button>
                        </div>
                    </form>
                    <div class="h-10 w-10 ml-4 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md">
                        <button form="" onclick="newEntry()" class="new_entry_button text-2xl text-white"><i data-lucide="plus"></i></button>
                    </div>
                </div>
            </div>
            <div class="flex justify-between my-4">
                <div id="pagination" class="flex flex-row"></div>
                <div class="flex flex-row">
                    <p class="mr-4"><?php echo $lang['quantity']; ?>:</p>
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
        <div class="absolute top-0 left-0 h-full w-full p-4 bg-white rounded-lg z-2 hidden newEntry" id="location_join_metadata_join_location">
            <div class="flex justify-between pb-6">
                <div class="text-xl font-bold">
                    New Location
                </div>
                <div class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md">
                    <button type="button" onclick="cancelNewEntry(this)" class="text-2xl text-white"><i data-lucide="x"></i></button>
                </div>
            </div>
            <form id="metadata">
                <div class="text-lg">
                    Metadata
                </div>
                <div class="grid grid-cols-2 gap-4 justify-between">
                    <div class="flex flex-col">
                        <div class="pb-6 h-fit w-full max-w-lg ">
                            <label class="block mb-2" for="type">
                                Status
                            </label>
                            <select name="status" class="w-full py-2 px-4 appearance-none border rounded-full leading-tight focus:outline-none focus:shadow-outline">
                                <option value="0">Active</option>
                                <option value="2">Deactivated</option>
                                <option value="4">Offline</option>
                                <option value="6">Unused</option>
                            </select>
                        </div>
                        <div class="pb-6 h-fit w-full max-w-lg relative">
                            <label class="block mb-2" for="type">
                                Caption
                            </label>
                            <input type="text" name="caption" placeholder="Caption" class="w-full py-2 px-4 appearance-none border rounded-full leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                        <div class="pb-6 h-fit w-full max-w-lg relative">
                            <label class="block mb-2" for="type">
                                Description
                            </label>
                            <textarea name="description" placeholder="Description" class="w-full py-2 px-4 appearance-none border rounded-3xl leading-tight focus:outline-none focus:shadow-outline"></textarea>
                        </div>
                    </div>
                    <div class="flex flex-col">
                        <div class="pb-6 h-fit w-full max-w-lg relative">
                            <label class="block mb-2" for="type">
                                Specification
                            </label>
                            <textarea name="specification" placeholder="Specification" class="w-full py-2 px-4 appearance-none border rounded-3xl leading-tight focus:outline-none focus:shadow-outline"></textarea>
                        </div>
                        <div class="pb-6 h-fit w-full max-w-lg relative">
                            <label class="block mb-2" for="type">
                                Tags
                            </label>
                            <input type="text" name="tags" placeholder="Tags" class="w-full py-2 px-4 appearance-none border rounded-full leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                    </div>
                </div>
            </form>
            <form id="location">
                <input type="hidden" id="metadataUUID" name="metadata" value="">
                <div class="text-lg">
                    Location
                </div>
                <div class="grid grid-cols-2 gap-4 justify-between">
                    <div class="flex flex-col">
                        <div class="pb-6 h-fit w-full max-w-lg ">
                            <label class="block mb-2" for="type">
                                Type
                            </label>
                            <select id="type" name="type" class="w-full py-2 px-4 appearance-none border rounded-full leading-tight focus:outline-none focus:shadow-outline">
                                <option value="0">Region</option>
                                <option value="2">Building complex</option>
                                <option value="4">Building</option>
                                <option value="6">Room</option>
                                <option value="8">Rack</option>
                            </select>
                        </div>
                        <div class="pb-6 h-fit w-full max-w-lg relative">
                            <label class="block mb-2" for="type">
                                Parent Location
                            </label>
                            <input type="text" id="search" placeholder="Parent Location" class="w-full py-2 px-4 appearance-none border rounded-full leading-tight focus:outline-none focus:shadow-outline">
                            <input type="hidden" id="parent_location" name="parent_location" value="">
                            <div id="location_search" class="absolute z-3 w-full bg-white mt-2 rounded-3xl shadow-lg border leading-tight hidden"></div>
                        </div>
                    </div>
                    <div class="flex flex-col">
                        <div class="pb-6 h-fit w-full max-w-lg relative">
                            <label class="block mb-2" for="type">
                                Size
                            </label>
                            <textarea name="size" placeholder="Size" class="w-full py-2 px-4 appearance-none border rounded-3xl leading-tight focus:outline-none focus:shadow-outline"></textarea>
                        </div>
                        <div class="pb-6 h-fit w-full max-w-lg">
                            <label class="block mb-2" for="type">
                                Rotation
                            </label>
                            <textarea name="rotation" placeholder="Rotation" class="w-full py-2 px-4 appearance-none border rounded-3xl leading-tight focus:outline-none focus:shadow-outline"></textarea>
                        </div>
                    </div>
                </div>
                <div class="h-10 w-10 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md">
                    <button type="button" onclick="submitForms(this)" class="text-2xl text-white">
                        <i data-lucide="check"></i>
                    </button>
                </div>
                <script>
                $(document).ready(function() {
                    $('#type').on('change', function() {
                        var typeValue = $(this).val();
                        var searchQuery = 'typeMax=' + typeValue;
                        loadDropdown(searchQuery);
                    });

                    $('#search').on('input', function() {
                        var search = $(this).val();
                        var typeValue = $('#type').val();
                        var searchQuery = 'typeMax=' + typeValue + '&search=' + search;
                        loadDropdown(searchQuery);
                    });
                });

                function submitForms(element) {
                    // Metadata-Formulardaten sammeln und senden
                    var metadataData = $('#metadata').serializeArray();
                    var metadataObj = {};
                    $.each(metadataData, function(index, item) {
                        metadataObj[item.name] = item.value;
                    });

                    $.ajax({
                        url: '<?php echo PORTFLOW_HOSTNAME; ?>' + '/api/metadata/',
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(metadataObj),
                        success: function(response) {
                            // Die UUID aus dem ersten Element im Array extrahieren
                            var uuid = response[0].uuid;
                            $('#metadataUUID').val(uuid); // UUID in das versteckte Feld einfügen

                            // Location-Formulardaten sammeln und senden
                            var locationData = $('#location').serializeArray();
                            var locationObj = {};
                            $.each(locationData, function(index, item) {
                                locationObj[item.name] = item.value;
                            });

                            $.ajax({
                                url: '<?php echo PORTFLOW_HOSTNAME; ?>' + '/api/location/',
                                type: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify(locationObj),
                                success: function(response) {
                                    console.log('Erfolg:', response);
                                    cancelNewEntry(element);
                                    loadTable();
                                },
                                error: function(jqXHR, textStatus, errorThrown) {
                                    console.log('Fehler beim Senden der Location-Daten:', jqXHR.responseText);
                                }
                            });
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.log('Fehler beim Senden der Metadata-Daten:', jqXHR.responseText);
                        }
                    });
                }

                function loadDropdown(search) {
                    var url = '<?php echo PORTFLOW_HOSTNAME; ?>' + '/api/location_join_metadata_join_location/?' + search; // Beispiel-URL, passen Sie sie entsprechend an
                    $.ajax({
                        url: url,
                        type: 'GET',
                        dataType: 'json',
                        success: function(data) {
                            var dropdown = $('#location_search');
                            dropdown.empty(); // Vorhandene Optionen löschen

                            if (data && data.items && data.items.length > 0) {
                                data.items.forEach(function(item) {
                                    var option = $('<div class="hover:bg-gray-100 py-2 px-4 rounded-3xl cursor-pointer">').text(item.metadata_caption_0).attr('data-value', item.uuid);
                                    dropdown.append(option);

                                    option.on('click', function() {
                                        $('#search').val(item.metadata_caption_0); // Den Wert des Suchfelds aktualisieren
                                        $('#parent_location').val(item.uuid); // Den Wert des versteckten Feldes aktualisieren
                                        dropdown.hide(); // Dropdown ausblenden
                                    });
                                });
                                dropdown.show(); // Dropdown anzeigen, wenn Optionen hinzugefügt wurden
                            } else {
                                dropdown.append($('<div class="p-2 text-gray-500">').text('Keine Ergebnisse gefunden'));
                                dropdown.show();
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.log('Error loading dropdown data:', jqXHR.responseText);
                        }
                    });
                }
                </script>
            </form>
        </div>
    </div>
</div>
<script>
    // Globale Variable, um den Namen der zuletzt geladenen Tabelle zu speichern
    let currentTable = 'location_join_metadata_join_location';

    // Navigation
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

    // Funktion, um das entsprechende DIV basierend auf der aktuellen Tabelle einzublenden
    function newEntry() {
        // Bestimmen der ID des DIVs basierend auf dem Namen der aktuellen Tabelle
        let divId = '';
        switch (currentTable) {
            case 'location_join_metadata_join_location':
                divId = 'location_join_metadata_join_location';
                break;
            // Fügen Sie hier weitere Fälle hinzu, falls erforderlich
            default:
                console.log('Kein passendes DIV gefunden für: ' + currentTable);
                return; // Frühzeitiger Abbruch, wenn keine passende ID gefunden wurde
        }

        // Ein- oder Ausblenden des DIVs, wenn eine ID gefunden wurde
        if (divId) {
            const divElement = document.getElementById(divId);
            if (divElement.classList.contains('hidden')) {
                // Wenn das DIV versteckt ist, zeigen wir es an
                divElement.classList.remove('hidden');
                divElement.classList.add('block');
            } else {
                // Wenn das DIV angezeigt wird, verstecken wir es
                divElement.classList.remove('block');
                divElement.classList.add('hidden');
            }
        }
    }
    function cancelNewEntry(element) {
        var parentDiv = element.closest('.newEntry');
        if (parentDiv) {
            parentDiv.classList.add('hidden');
            parentDiv.classList.remove('block');
        } else {
            console.error('Kein Element mit der Id newEntry gefunden');
        }
    }
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
    function generatePagination(totalPages, currentPage, search, limit) {
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
                generatePagination(totalPages, (pageGroup - 1) * pagesPerGroup + 1, search, limit);
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
                loadTable(table, search, limit, $(this).text());
            });

            // Fügen Sie die Seitenzahl zur Paginierungsleiste hinzu
            $('#pagination').append(pageDiv);
        }

        // Fügen Sie eine Schaltfläche hinzu, um zur nächsten Gruppe von Seiten zu navigieren
        var nextButton = $('<div class="mr-2 cursor-pointer">&rarr;</div>');
        if ((pageGroup + 1) * pagesPerGroup < totalPages) {
            nextButton.click(function() {
                generatePagination(totalPages, (pageGroup + 1) * pagesPerGroup + 1, search, limit);
            });
        } else {
            nextButton.css('visibility', 'hidden');
        }
        $('#pagination').append(nextButton);

        // Clone the pagination to 'pagination_bottom'
        $('#pagination_bottom').html($('#pagination').clone(true));
    } 

    // load table
    function loadTable(table = 'location_join_metadata_join_location', search = '', limit = 100, page = 1) {
        currentTable = table;
        var configUrl = '<?php echo PORTFLOW_HOSTNAME; ?>' + '/includes/lang.php?nav';

        $.ajax({
            url: configUrl,
            dataType: 'json',
            success: function(config) {
                var tableConfig = config[table];
                var columnsConfig = tableConfig.columns;
                var defaultColumns = tableConfig.default;

                // Abruf der Benutzerpräferenzen aus der PHP-Session
                var userSettingsRaw = <?php echo json_encode($_SESSION['settings'] ?? []); ?>;
                var userSettings = typeof userSettingsRaw === 'string' ? JSON.parse(userSettingsRaw) : userSettingsRaw;
                var userColumns = userSettings.tables && userSettings.tables[table] ? userSettings.tables[table] : defaultColumns;

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
                        userColumns.forEach(function(columnKey) {
                            var th = $('<th scope="col" class="p-2" data-sort="' + columnKey + '">').text(columnsConfig[columnKey] || columnKey);
                            trHead.append(th);
                        });
                        // Detailspalte hinzufügen
                        trHead.append($('<th scope="col" class="p-2">Details</th>'));
                        tableHead.append(trHead);

                        var results = data.items;
                        $('#count').text('Datensätze: ' + parseInt(data.pageInfo.totalResults));

                        results.forEach(function(row) {
                            var tr = $('<tr class="hover:bg-gray-200">');
                            userColumns.forEach(function(columnKey) {
                                var td = $('<td class="p-2 border-b max-w-lg overflow-auto">').text(row[columnKey] || '--');
                                tr.append(td);
                            });

                            // Detailsspalte mit JSON-Popup
                            var detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white text-2xl flex items-center justify-center shadow-md"><i data-lucide="info"></i></button>');
                            detailsButton.on('click', function() {
                                var detailsData = {};
                                Object.keys(columnsConfig).forEach(function(key) {
                                    if (!userColumns.includes(key)) {
                                        detailsData[key] = row[key] || '--';
                                    }
                                });
                                alert(JSON.stringify(detailsData, null, 2)); // oder modales Popup hier hinzufügen
                            });
                            tr.append($('<td class="p-2 border-b max-w-lg overflow-auto">').append(detailsButton));
                            tableBody.append(tr);
                            
                            // Content is loaded after site is rendered, therefore icons could be missing without another lucide.createIcons()
                            lucide.createIcons();
                        });

                        generatePagination(Math.ceil(data.pageInfo.totalResults / data.pageInfo.resultsPerPage), data.pageInfo.currentPage, search, limit);
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
        $('#searchForm').on('change', function(event) {
            event.preventDefault();
            var search = $(this).find('input[name="search"]').val();
            loadTable(table = 'location', search);
        });
    });

    function saveUserColumnPreferences(table, selectedColumns) {
        settings[table] = settings[table] || {};
        settings[table].columns = selectedColumns;

        // Send updated preferences to PHP session via AJAX
        $.ajax({
            url: '<?php echo PORTFLOW_HOSTNAME; ?>' + '/api/' + 'settings',
            type: 'POST',
            data: JSON.stringify(settings),
            contentType: 'application/json',
            success: function() {
                console.log('Preferences saved successfully');
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log('Error saving preferences:', jqXHR.responseText);
            }
        });
    }

</script>
<?php
    include_once 'includes/footer.php';
?>
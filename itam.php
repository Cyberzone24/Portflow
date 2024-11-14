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
                    <form id="searchForm" class="flex flex-row" enctype="multipart/form-data" onsubmit="searchTable(event)">
                        <input type="text" name="search" placeholder="Suchen ..." class="rounded-full px-4 py-2 shadow-md">
                        <div class="h-10 w-10 ml-2 rounded-full bg-blue-500 hover:bg-blue-700 flex justify-center shadow-md">
                            <button type="submit" class="text-2xl text-white"><i data-lucide="search"></i></button>
                        </div>
                    </form>
                    <div class="h-10 w-10 ml-4 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md">
                        <button form="" onclick="openNewEntry()" class="new_entry_button text-2xl text-white"><i data-lucide="plus"></i></button>
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

        <!-- New Entry Popup -->
        <div class="absolute top-0 left-0 h-full w-full p-4 bg-white rounded-lg z-2 hidden newEntry" id="location_join_metadata_join_location">
            <div class="flex justify-between pb-6">
                <div class="text-xl font-bold">
                    New Location
                </div>
                <div class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md">
                    <button type="button" onclick="closeNewEntry(this)" class="text-2xl text-white"><i data-lucide="x"></i></button>
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
            </form>
        </div>
    </div>
</div>
<script>
// General helper functions
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

function ajaxPost(url, data, successCallback, errorCallback) {
    $.ajax({
        url: url,
        type: 'POST',
        data: JSON.stringify(data),
        contentType: 'application/json',
        success: successCallback,
        error: function(jqXHR) {
            console.log('Error:', jqXHR.responseText);
            if (errorCallback) errorCallback(jqXHR);
        }
    });
}

// submit location form
function submitForms(element) {
    // Metadata-Formulardaten sammeln und senden
    var metadataData = $('#metadata').serializeArray();
    var metadataObj = {};
    $.each(metadataData, function(index, item) {
        metadataObj[item.name] = item.value;
    });

    ajaxPost(
        '<?php echo PORTFLOW_HOSTNAME; ?>' + '/api/metadata/',
        metadataObj,
        function(response) {
            var uuid = response[0].uuid;
            $('#metadataUUID').val(uuid); // UUID in verstecktes Feld einfügen

            // Location-Formulardaten sammeln und senden
            var locationData = $('#location').serializeArray();
            var locationObj = {};
            $.each(locationData, function(index, item) {
                locationObj[item.name] = item.value;
            });

            ajaxPost(
                '<?php echo PORTFLOW_HOSTNAME; ?>' + '/api/location/',
                locationObj,
                function(response) {
                    console.log('Erfolg:', response);
                    closeNewEntry(element);
                    loadTable();
                },
                function(jqXHR, textStatus, errorThrown) {
                    console.log('Fehler beim Senden der Location-Daten:', jqXHR.responseText);
                }
            );
        },
        function(jqXHR, textStatus, errorThrown) {
            console.log('Fehler beim Senden der Metadata-Daten:', jqXHR.responseText);
        }
    );
}


// Generate pagination
function generatePagination(totalPages, currentPage, search, limit) {
    var pagesPerGroup = 10;
    var pageGroup = Math.floor((currentPage - 1) / pagesPerGroup);
    var $pagination = $('#pagination').empty().append('<div class="mr-2">Seite: </div>');
    
    function addPageButton(text, callback, hidden = false) {
        var button = $('<div class="mr-2 cursor-pointer">').html(text).css('visibility', hidden ? 'hidden' : 'visible');
        if (!hidden) button.click(callback);
        $pagination.append(button);
    }
    
    addPageButton('&larr;', () => generatePagination(totalPages, (pageGroup - 1) * pagesPerGroup + 1, search, limit), pageGroup === 0);

    for (let i = pageGroup * pagesPerGroup + 1; i <= Math.min((pageGroup + 1) * pagesPerGroup, totalPages); i++) {
        let pageDiv = $('<div class="mr-2 cursor-pointer">').text(i).toggleClass('current-page text-blue-500', i === currentPage);
        pageDiv.click(() => loadTable(currentTable, search, limit, i));
        $pagination.append(pageDiv);
    }
    
    addPageButton('&rarr;', () => generatePagination(totalPages, (pageGroup + 1) * pagesPerGroup + 1, search, limit), (pageGroup + 1) * pagesPerGroup >= totalPages);
    
    $('#pagination_bottom').html($pagination.clone(true));
}

// Load table data
function loadTable(table = 'location_join_metadata_join_location', search = '', limit = 100, page = 1) {
    currentTable = table;
    const configUrl = `${'<?php echo PORTFLOW_HOSTNAME; ?>'}/includes/lang.php?nav`;

    ajaxGet(configUrl, config => {
        let { columns, default: defaultColumns } = config[table];
        let userColumns = loadUserColumns(table, defaultColumns);

        ajaxGet(`${'<?php echo PORTFLOW_HOSTNAME; ?>'}/api/${table}`, data => {
            $('#count').text('Datensätze: ' + parseInt(data.pageInfo.totalResults));
            displayTable(columns, userColumns, data.items);
            generatePagination(Math.ceil(data.pageInfo.totalResults / data.pageInfo.resultsPerPage), data.pageInfo.currentPage, search, limit);
        });
    });
}
loadTable();

// Display table content
function displayTable(columnsConfig, userColumns, rows) {
    var $tableHead = $('.static thead').empty();
    var $tableBody = $('.static tbody').empty();

    let trHead = $('<tr class="border-b bg-gray-200">');
    userColumns.forEach(colKey => trHead.append($('<th class="p-2">').text(columnsConfig[colKey] || colKey)));
    trHead.append($('<th class="p-2">Details</th>'));
    $tableHead.append(trHead);

    rows.forEach(row => {
        let tr = $('<tr class="hover:bg-gray-200">');
        userColumns.forEach(colKey => tr.append($('<td class="p-2 border-b">').text(row[colKey] || '--')));
        
        let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 text-white flex items-center justify-center">')
            .html('<i data-lucide="info"></i>')
            .click(() => openDetailsPopup(row));
        tr.append($('<td class="p-2 border-b">').append(detailsButton));
        $tableBody.append(tr);
    });

    lucide.createIcons();
}

// Load user column preferences
function loadUserColumns(table, defaultColumns) {
    const userSettings = <?php echo json_encode($_SESSION['settings'] ?? []); ?>;
    return userSettings.tables && userSettings.tables[table] ? userSettings.tables[table] : defaultColumns;
}

// Open new close entry details
function openNewEntry() {
    // Öffnet das Formular für einen neuen Eintrag
    console.log("Neuer Eintrag wird erstellt");
    document.getElementById('location_join_metadata_join_location').classList.remove('hidden');
}

function closeNewEntry() {
    // Popup für neuen Eintrag ausblenden
    document.getElementById('location_join_metadata_join_location').classList.add('hidden');
}

// Open and close details popup
function openDetailsPopup(rowData) {
    var $detailsContent = $('#detailsContent').empty();
    Object.entries(rowData).forEach(([key, value]) => $detailsContent.append(`<p><strong>${key}:</strong> ${value || '--'}</p>`));
    $('#detailsPopup').removeClass('hidden');
}

function closeDetailsPopup() {
    $('#detailsPopup').addClass('hidden');
}

// Search functions
$(document).ready(function() {
    $('#searchForm').on('change', function(event) {
        event.preventDefault();
        loadTable('location', $(this).find('input[name="search"]').val());
    });

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

// Dropdown
function loadDropdown(search) {
    var url = '<?php echo PORTFLOW_HOSTNAME; ?>' + '/api/location_join_metadata_join_location/?' + search;

    // AJAX-GET-Anfrage mit Helferfunktionen
    $.get(url, function(data) {
        var dropdown = $('#location_search');
        dropdown.empty(); // Vorhandene Optionen löschen

        if (data && data.items && data.items.length > 0) {
            data.items.forEach(function(item) {
                var option = $('<div class="hover:bg-gray-100 py-2 px-4 rounded-3xl cursor-pointer">')
                    .text(item.metadata_caption_0)
                    .attr('data-value', item.uuid);

                dropdown.append(option);

                // Klick-Event für die Dropdown-Option
                option.on('click', function() {
                    $('#search').val(item.metadata_caption_0);
                    $('#parent_location').val(item.uuid);
                    dropdown.hide();
                });
            });
            dropdown.show(); // Dropdown anzeigen, wenn Optionen hinzugefügt wurden
        } else {
            dropdown.append($('<div class="p-2 text-gray-500">').text('Keine Ergebnisse gefunden'));
            dropdown.show();
        }
    }).fail(function(jqXHR, textStatus, errorThrown) {
        console.log('Fehler beim Laden der Dropdown-Daten:', jqXHR.responseText);
    });
}

// Save user column preferences
function saveUserColumnPreferences(table, selectedColumns) {
    const settings = { [table]: { columns: selectedColumns } };
    ajaxPost(`${'<?php echo PORTFLOW_HOSTNAME; ?>'}/api/settings`, settings, () => console.log('Preferences saved successfully'));
}
</script>
<?php
    include_once 'includes/footer.php';
?>
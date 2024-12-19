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
    <div class="basis-1/6 flex flex-col gap-6 overflow-y-scroll">  
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
    <div class="h-full basis-5/6 flex bg-white rounded-lg relative overflow-y-scroll">
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

        <!-- New Location -->
        <div id="formContainer" class="absolute top-0 left-0 h-full w-full p-4 bg-white rounded-lg z-2 overflow-y-scroll hidden newEntry"></div>
    </div>
</div>
<script>
// Generate form
async function generateFormFromJSON(table = 'location_join_metadata_join_location') {
    try {
        console.log('Loading form configuration for table:', table);
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>' + '/forms.json');
        const data = await response.json();

        const formConfig = data.forms[table];
        if (!formConfig) {
            console.error(`No form configuration found for table: ${table}`);
            return;
        }

        const container = document.getElementById('formContainer');
        if (!container) {
            console.error(`Container not found.`);
            return;
        }

        // Clear the container
        container.innerHTML = '';

        // Add header with form title and buttons
        if (formConfig.formTitle) {
            const header = document.createElement('div');
            header.className = 'flex justify-between items-center pb-6';

            const title = document.createElement('div');
            title.className = 'text-2xl font-bold';
            title.textContent = formConfig.formTitle;
            header.appendChild(title);

            const buttonContainer = document.createElement('div');
            buttonContainer.className = 'flex gap-4';

            const submitWrapper = document.createElement('div');
            submitWrapper.className = 'h-10 w-10 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md';

            const submitButton = document.createElement('button');
            submitButton.type = 'button';
            submitButton.onclick = () => submitForms(table);
            submitButton.className = 'text-2xl text-white';
            submitButton.innerHTML = '<i data-lucide="check"></i>';
            submitWrapper.appendChild(submitButton);

            const cancelWrapper = document.createElement('div');
            cancelWrapper.className = 'h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md';

            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.onclick = () => closeNewEntry();
            cancelButton.className = 'text-2xl text-white';
            cancelButton.innerHTML = '<i data-lucide="x"></i>';
            cancelWrapper.appendChild(cancelButton);

            buttonContainer.appendChild(submitWrapper);
            buttonContainer.appendChild(cancelWrapper);
            header.appendChild(buttonContainer);

            container.appendChild(header);
        }

        // Iterate over postOrder to generate fields
        formConfig.postOrder.forEach(post => {
            const form = document.createElement('form');
            form.id = post.table;

            // Add section title if defined
            if (post.sectionTitle) {
                const sectionTitle = document.createElement('div');
                sectionTitle.className = 'text-lg py-4 font-bold';
                sectionTitle.textContent = post.sectionTitle;
                form.appendChild(sectionTitle);
            }

            // Add fields
            const grid = document.createElement('div');
            grid.className = 'grid grid-cols-2 gap-4';
            post.fields.forEach(fieldKey => {
                const fieldConfig = formConfig.fields[fieldKey];
                if (fieldConfig) {
                    grid.appendChild(generateField(fieldKey, fieldConfig));
                }
            });

            form.appendChild(grid);
            container.appendChild(form);
        });
    } catch (error) {
        console.error("Error loading or processing forms.json:", error);
    }
}

// generate form fields
function generateField(name, config) {
    const wrapper = document.createElement('div');
    wrapper.className = 'pb-6 h-fit w-full max-w-lg relative';

    // Create label
    const label = document.createElement('label');
    label.className = 'block mb-2';
    label.setAttribute('for', name);
    label.textContent = config.label;
    wrapper.appendChild(label);

    // Create input fields
    switch (config.type) {
        case 'text':
        case 'number':
            field = document.createElement('input');
            field.type = config.type;
            field.className = 'w-full py-2 px-4 appearance-none border rounded-full leading-tight focus:outline-none focus:shadow-outline';
            break;
        case 'textarea':
            field = document.createElement('textarea');
            field.className = 'w-full py-2 px-4 appearance-none border rounded-3xl leading-tight focus:outline-none focus:shadow-outline';
            break;
        case 'dropdown':
            field = document.createElement('select');
            field.className = 'w-full py-2 px-4 appearance-none border rounded-full leading-tight focus:outline-none focus:shadow-outline';
            config.options.forEach(optionConfig => {
                const option = document.createElement('option');
                option.value = optionConfig.value;
                option.textContent = optionConfig.label;
                field.appendChild(option);
            });
            break;
        case 'boolean':
            field = document.createElement('input');
            field.type = 'checkbox';
            break;
        default:
            console.error(`Unsupported field type: ${config.type}`);
            return wrapper;
    }

    if (field) {
        field.name = name;
        field.placeholder = config.label;
        if (config.required) {
            field.required = true;
        }
        wrapper.appendChild(field);
    }

    return wrapper;
}

// submit forms
function submitForms(table) {
    console.log('Submitting forms for table:', table);
    const forms = document.querySelectorAll('form');

    let metadataUUID = '';

    forms.forEach(form => {
        const formData = new FormData(form);
        const postData = {};

        formData.forEach((value, key) => {
            postData[key] = value;
        });

        const apiUrl = `<?php echo PORTFLOW_HOSTNAME; ?>` + '/api/' + form.id + '/';

        // POST Metadata first
        if (form.id === 'metadata') {
            fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(postData)
            })
                .then(response => response.json())
                .then(data => {
                    metadataUUID = data[0].uuid;
                    document.querySelectorAll('[name="metadata"]').forEach(input => {
                        input.value = metadataUUID;
                    });

                    // Continue with other forms
                    forms.forEach(innerForm => {
                        if (innerForm.id !== 'metadata') {
                            submitOtherForms(innerForm, metadataUUID, table);
                        }
                    });
                })
                .catch(error => {
                    console.error('Fehler beim Senden der Metadata-Daten:', error);
                });
        }
    });
}

function submitOtherForms(form, metadataUUID, table) {
    console.log('Submitting table:', table);
    const formData = new FormData(form);
    const postData = {};

    formData.forEach((value, key) => {
        postData[key] = value;
    });

    // Include metadataUUID if required
    if (form.id !== 'metadata') {
        postData.metadata = metadataUUID;
    }

    const apiUrl = `<?php echo PORTFLOW_HOSTNAME; ?>` + '/api/' + form.id + '/';

    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(postData)
    })
        .then(response => response.json())
        .then(data => {
            console.log(`Erfolg bei ${form.id}:`, data);
            closeNewEntry();
            loadTable(table);
        })
        .catch(error => {
            console.error(`Fehler beim Senden der ${form.id}-Daten:`, error);
        });
}

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

    // Tabellenhervorhebung aktualisieren
    updateActiveTab(table);

    ajaxGet(configUrl, config => {
        let { columns, default: defaultColumns } = config[table];
        let userColumns = loadUserColumns(table, defaultColumns);

        ajaxGet(`${'<?php echo PORTFLOW_HOSTNAME; ?>'}/api/${table}`, data => {
            $('#count').text('Datensätze: ' + parseInt(data.pageInfo.totalResults));
            displayTable(columns, userColumns, data.items);
            generatePagination(Math.ceil(data.pageInfo.totalResults / data.pageInfo.resultsPerPage), data.pageInfo.currentPage, search, limit);
        });
    });

    // Formular für neuen Eintrag generieren
    console.log(table);
    generateFormFromJSON(table);
}
loadTable();

// Tabellenhervorhebung aktualisieren
function updateActiveTab(table) {
    // Alle Listenelemente zurücksetzen
    const listItems = document.querySelectorAll('#itam_nav li');
    listItems.forEach(item => {
        // Standardklassen für nicht ausgewähltes Element setzen
        item.className = 'bg-white py-2 px-4 rounded-lg mr-4';
    });

    // Das ausgewählte Listenelement hervorheben
    const selectedItem = document.querySelector(`#itam_nav li[onclick="loadTable('${table}')"]`);
    if (selectedItem) {
        // Klassen für das ausgewählte Element setzen
        selectedItem.className = 'bg-white py-2 px-4 rounded-l-lg pr-0';
    }
}

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
    document.getElementById('formContainer').classList.remove('hidden');
}

function closeNewEntry() {
    // Popup für neuen Eintrag ausblenden
    document.getElementById('formContainer').classList.add('hidden');
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
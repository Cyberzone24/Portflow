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
// search
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.querySelector('#searchForm input[name="search"]');
    searchInput.addEventListener('input', () => {
        loadTable(currentTable, searchInput.value);
    });
});
function searchTable(event) {
    event.preventDefault();
}

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

    // Wenn der Feldname mit "expected_" beginnt, füge die Klasse "hidden" hinzu
    if(name.startsWith('expected_')) {
        wrapper.classList.add('hidden');
    }

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
        case 'searchDropdown':
            const textInput = document.createElement('input');
            textInput.type = 'text';
            textInput.placeholder = config.placeholder || config.label;
            textInput.className = 'w-full py-2 px-4 border rounded-full';
        
            const hiddenField = document.createElement('input');
            hiddenField.type = 'hidden';
            hiddenField.name = name;

            const dropdownList = document.createElement('div');
            dropdownList.className = 'absolute bg-white border rounded-lg w-full z-10 mt-12 hidden';

            let lastSelectedText = '';

            textInput.addEventListener('input', async () => {
                // Falls keine Eingabe:
                if (!textInput.value) {
                    hiddenField.value = '';
                }
                // Falls es wieder eine Abweichung vom zuletzt gewählten Eintrag gibt:
                else if (textInput.value !== lastSelectedText) {
                    hiddenField.value = '';
                }

                try {
                    // Danach das Dropdown neu laden
                    dropdownList.innerHTML = '';
                    let searchUrl = '<?php echo PORTFLOW_HOSTNAME; ?>/api/' + config.resource;
                    const params = new URLSearchParams();
                    params.set('search', textInput.value);

                    if(config.dependencies) {
                        Object.entries(config.dependencies).forEach(([queryParam, fieldName]) => {
                            const depField = document.querySelector(`[name="${fieldName}"]`);
                            if(depField && depField.value) {
                                params.set(queryParam, depField.value);
                            }
                        });
                    }

                    const response = await fetch(searchUrl + '?' + params.toString());
                    const results = await response.json();
                    dropdownList.classList.remove('hidden');

                    results.items.forEach(item => {
                        const entry = document.createElement('div');
                        entry.className = 'hover:bg-gray-100 cursor-pointer p-2';
                        entry.textContent = item.metadata_caption_0;
                        entry.onclick = () => {
                            textInput.value = item.metadata_caption_0;
                            hiddenField.value = item.uuid;
                            lastSelectedText = item.metadata_caption_0;
                            dropdownList.classList.add('hidden');
                        };
                        dropdownList.appendChild(entry);
                    });
                } catch(e) {
                    console.error(e);
                }
            });

            field = textInput;

            wrapper.appendChild(field);
            wrapper.appendChild(hiddenField);
            wrapper.appendChild(dropdownList);
            break;
        default:
            console.error(`Unsupported field type: ${config.type}`);
            return wrapper;
    }

    if (field) {
        if (config.type !== 'searchDropdown') {
            field.name = name;
        }
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

        if (key.startsWith('expected_')) {
            // Entferne "expected_" vom Schlüssel, um den Namen des regulären Feldes zu erhalten
            const normalKey = key.slice('expected_'.length);
            postData[key] = postData[normalKey];
        }
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

function ajaxPost(url, type, data, successCallback, errorCallback) {
    $.ajax({
        url: url,
        type: type,
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

    // Close Details Popup
    closeDetailsPopup();

    ajaxGet(configUrl, config => {
        let { columns, default: defaultColumns } = config[table];
        let userColumns = loadUserColumns(table, defaultColumns);

        if (search) { query = `?search=${search}`; } else { query = ''; }

        ajaxGet(`${'<?php echo PORTFLOW_HOSTNAME; ?>'}/api/${table}` + query, data => {
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

function displayTable(columnsConfig, userColumns, rows) {
    var $tableHead = $('.static thead').empty();
    var $tableBody = $('.static tbody').empty();

    let trHead = $('<tr class="border-b bg-gray-200">');
    userColumns.forEach(colKey => trHead.append($('<th class="p-2">').text(columnsConfig[colKey] || colKey)));
    trHead.append($('<th class="p-2">Actions</th>'));
    $tableHead.append(trHead);

    // Nur für location_join_metadata_join_location: Hierarchie aufbauen
    if (currentTable === 'location_join_metadata_join_location') {
        // Map für schnellen Zugriff
        const byParent = {};
        const allParents = new Set();
        const allUuids = new Set();
    
        rows.forEach(row => {
            const parent = row.parent_location || 'root';
            if (!byParent[parent]) byParent[parent] = [];
            byParent[parent].push(row);
            allParents.add(parent);
            allUuids.add(row.uuid);
        });
    
        // Finde alle Wurzeln: Eltern, die selbst nicht als Kind vorkommen
        const roots = Array.from(allParents).filter(parent => !allUuids.has(parent));
    
        function renderRows(parent, level = 0) {
            (byParent[parent] || []).forEach(row => {
                let tr = $('<tr class="border-b hover:bg-gray-200">');
                userColumns.forEach(colKey => {
                    let td;
                    let dashes = level > 0 ? Array(level + 1).join('— ') : '';

                    if (colKey === 'type') {
                        let iconHtml = '';
                        let typeTitle = '';
                        let statusColor = '';
                        switch (row.metadata_status_0) {
                            case 0: statusColor = 'color: #22c55e;'; break;
                            case 2: statusColor = 'color: #eab308;'; break;
                            case 4: statusColor = 'color: #ef4444;'; break;
                            case 6: statusColor = 'color: #6b7280;'; break;
                            default: statusColor = '';
                        }
                        switch (row[colKey]) {
                            case '0':
                                iconHtml = `${dashes}<i data-lucide="scan" title="Campus" style="${statusColor};display:inline-block;vertical-align:middle"></i>`;
                                typeTitle = 'Region';
                                break;
                            case '2':
                                iconHtml = `${dashes}<i data-lucide="land-plot" title="Standort" style="${statusColor};display:inline-block;vertical-align:middle"></i>`;
                                typeTitle = 'Komplex';
                                break;
                            case '4':
                                iconHtml = `${dashes}<i data-lucide="school" title="Gebäude" style="${statusColor};display:inline-block;vertical-align:middle"></i>`;
                                typeTitle = 'Gebäude';
                                break;
                            case '6':
                                iconHtml = `${dashes}<i data-lucide="door-closed" title="Raum" style="${statusColor};display:inline-block;vertical-align:middle"></i>`;
                                typeTitle = 'Raum';
                                break;
                            case '8':
                                iconHtml = `${dashes}<i data-lucide="server" title="Gerät" style="${statusColor};display:inline-block;vertical-align:middle"></i>`;
                                typeTitle = 'Rack';
                                break;
                            default:
                                iconHtml = dashes + (row[colKey] || '--');
                                typeTitle = '';
                        }
                        iconHtml += ` <span>${row.metadata_caption_0 || ''}</span>`;
                        td = $('<td class="p-2">').html(iconHtml).attr('title', typeTitle);
                    } else {
                        td = $('<td class="p-2">').text(row[colKey] || '--');
                    }
                    tr.append(td);
                });
                let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center">')
                    .html('<i data-lucide="info"></i>')
                    .click(() => openDetailsPopup(row));
                let deleteButton = $('<button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center">')
                    .html('<i data-lucide="trash"></i>')
                    .click(() => deleteEntry(row.uuid, row));
                tr.append($('<td class="p-2 flex flex-row gap-4">').append(detailsButton).append(deleteButton));
                $tableBody.append(tr);

                // Rekursiv für Kinder
                renderRows(row.uuid, level + 1);
            });
        }    
        // Für alle Wurzeln rendern
        roots.forEach(root => renderRows(root));

    } else if (currentTable === 'ip_range_join_metadata') {
        // IP-Bereiche anzeigen
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'ip_range') {
                    // Tooltip für nutzbare Adressen
                    let usable = '';
                    if (row.ip_range && row.subnet) {
                        // IPv4: Berechne Start/Ende aus Range und Subnet
                        function ipToInt(ip) {
                            return ip.split('.').reduce((acc, oct) => (acc << 8) + parseInt(oct), 0);
                        }
                        function intToIp(int) {
                            return [24,16,8,0].map(shift => (int >> shift) & 255).join('.');
                        }
                        let [rangeBase] = row.ip_range.split('/');
                        let subnet = parseInt(row.subnet);
                        let start = ipToInt(rangeBase);
                        let hostBits = 32 - subnet;
                        let count = Math.pow(2, hostBits);
                        let end = start + count - 1;
                        if (count > 2) usable = `Nutzbare Adressen: ${count - 2}`;
                        else if (count > 0) usable = `Nutzbare Adressen: ${count}`;
                    }
                    td = $('<td class="p-2">')
                        .text(row.ip_range || '--')
                        .attr('title', usable);
                } else if (colKey === 'subnet') {
                    // Tooltip für Subnetzmaske
                    let mask = row['subnet'] ? (function(subnet) {
                        let mask = [];
                        for (let i = 0; i < 4; i++) {
                            let n = Math.min(8, subnet);
                            mask.push(256 - Math.pow(2, 8 - n));
                            subnet -= n;
                        }
                        return mask.join('.');
                    })(parseInt(row['subnet'])) : '';
                    td = $('<td class="p-2">')
                        .text(row[colKey] !== undefined ? row[colKey] : '--')
                        .attr('title', mask ? `Subnetz-Maske: ${mask}` : '');
                } else if (colKey === 'metadata_status_0') {
                    // Status-Icon mit Farbe und Netztyp + Netzklasse
                    let statusColor = '';
                    let statusTitle = '';
                    switch (row.metadata_status_0) {
                        case 0: statusColor = '#22c55e'; statusTitle = 'Aktiv'; break;
                        case 2: statusColor = '#eab308'; statusTitle = 'Reserviert'; break;
                        case 4: statusColor = '#ef4444'; statusTitle = 'Inaktiv'; break;
                        case 6: statusColor = '#6b7280'; statusTitle = 'Archiviert'; break;
                        default: statusColor = '#64748b'; statusTitle = 'Unbekannt';
                    }
                
                    // Netztyp (privat/öffentlich) und Netzklasse anhand ip_range berechnen
                    let netType = '';
                    let netClass = '';
                    if (row.ip_range && row.subnet) {
                        function ipToInt(ip) {
                            return ip.split('.').reduce((acc, oct) => (acc << 8) + parseInt(oct), 0);
                        }
                        let [rangeBase] = row.ip_range.split('/');
                        let ipInt = ipToInt(rangeBase);
                
                        // Netzklasse bestimmen
                        if (ipInt >= ipToInt('0.0.0.0') && ipInt <= ipToInt('127.255.255.255')) netClass = 'A';
                        else if (ipInt >= ipToInt('128.0.0.0') && ipInt <= ipToInt('191.255.255.255')) netClass = 'B';
                        else if (ipInt >= ipToInt('192.0.0.0') && ipInt <= ipToInt('223.255.255.255')) netClass = 'C';
                        else if (ipInt >= ipToInt('224.0.0.0') && ipInt <= ipToInt('239.255.255.255')) netClass = 'D';
                        else if (ipInt >= ipToInt('240.0.0.0') && ipInt <= ipToInt('255.255.255.255')) netClass = 'E';
                
                        // Privat/Öffentlich bestimmen
                        let isPrivate = (
                            (ipInt >= ipToInt('10.0.0.0')   && ipInt <= ipToInt('10.255.255.255')) ||
                            (ipInt >= ipToInt('172.16.0.0') && ipInt <= ipToInt('172.31.255.255')) ||
                            (ipInt >= ipToInt('192.168.0.0')&& ipInt <= ipToInt('192.168.255.255'))
                        );
                        if (isPrivate) {
                            netType = `<i data-lucide="lock-keyhole" style="color:#6366f1;vertical-align:middle" title="Privates Netz"></i>`;
                        } else {
                            netType = `<i data-lucide="lock-keyhole-open" style="color:#f59e42;vertical-align:middle" title="Öffentliches Netz"></i>`;
                        }
                        if (netClass) {
                            netClass = `<span class="h-10 w-10 rounded-full bg-gray-200 text-white flex items-center justify-center font-bold"><p>${netClass}</p></span>`;
                        }
                    }
                
                    td = $('<td class="p-2 flex flex-row gap-4">').html(
                        `<span class="h-10 w-10 rounded-full flex items-center justify-center"><i data-lucide="chevrons-left-right-ellipsis" style="color:${statusColor};vertical-align:middle" title="${statusTitle}"></i></span> <span class="h-10 w-10 rounded-full flex items-center justify-center">${netType}</span> ${netClass}`
                    );
                } else if (colKey === 'metadata_tags_0') {
                    // Tags als Badges unterhalb des Zelleninhalts anzeigen
                    let tags = '';
                    if (row['metadata_tags_0']) {
                        var tagColors = ['bg-orange-400', 'bg-lime-400', 'bg-emerald-400', 'bg-cyan-400', 'bg-indigo-400', 'bg-fuchsia-400', 'bg-rose-400'];
                        row['metadata_tags_0'].split(',').forEach(function(tag) {
                            tag = tag.trim();
                            if (!tag) return;
                            var tagHash = tag.split('').reduce((prevHash, currVal) => ((prevHash << 5) - prevHash) + currVal.charCodeAt(0), 0);
                            var tagColor = tagColors[Math.abs(tagHash) % tagColors.length];
                            tags += "<span class='py-1 px-2 rounded-full text-white " + tagColor + " mr-2 mb-2 text-xs inline-block'>#" + tag + "</span> ";
                        });
                    }
                    td = $('<td class="p-2">').html(
                        (tags ? `<div class="mt-1">${tags}</div>` : '--')
                    );
                } else {
                    td = $('<td class="p-2">').text(row[colKey] !== undefined ? row[colKey] : '--');
                }
                tr.append(td);
            });
    
            let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center">')
                .html('<i data-lucide="info"></i>')
                .click(() => openDetailsPopup(row));
            let deleteButton = $('<button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center">')
                .html('<i data-lucide="trash"></i>')
                .click(() => deleteEntry(row.uuid, row));
            tr.append($('<td class="p-2 flex flex-row gap-4">').append(detailsButton).append(deleteButton));
            $tableBody.append(tr);
        });
    } else if (currentTable === 'vlan_join_metadata_join_ip_range') {
        // VLANs anzeigen
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'vlan_id') {
                    // VLAN ID mit Status-Icon
                    let statusColor = '';
                    switch (row.metadata_status_0) {
                        case 0: statusColor = '#22c55e'; break; // Aktiv
                        case 2: statusColor = '#eab308'; break; // Reserviert
                        case 4: statusColor = '#ef4444'; break; // Inaktiv
                        case 6: statusColor = '#6b7280'; break; // Archiviert
                        default: statusColor = '#64748b'; // Unbekannt
                    }
                    td = $('<td class="p-2">').html(
                        `<span class="h-10 w-10 rounded-full flex items-center justify-center"><i data-lucide="chevrons-left-right-ellipsis" style="color:${statusColor};vertical-align:middle" title="${row.metadata_status_0}"></i></span> ${row[colKey] || '--'}`
                    );
                } else if (colKey === 'metadata_status_0') {
                    // Status-Icon mit Farbe
                    let statusColor = '';
                    switch (row.metadata_status_0) {
                        case 0: statusColor = '#22c55e'; break; // Aktiv
                        case 2: statusColor = '#eab308'; break; // Reserviert
                        case 4: statusColor = '#ef4444'; break; // Inaktiv
                        case 6: statusColor = '#6b7280'; break; // Archiviert
                        default: statusColor = '#64748b'; // Unbekannt
                    }
                    td = $('<td class="p-2">').html(
                        `<span class="h-10 w-10 rounded-full flex items-center justify-center"><i data-lucide="workflow" style="color:${statusColor};vertical-align:middle" title="${row.metadata_status_0}"></i></span>`
                    );
                } else if (colKey === 'metadata_tags_0') {
                    // Tags als Badges unterhalb des Zelleninhalts anzeigen
                    let tags = '';
                    if (row['metadata_tags_0']) {
                        var tagColors = ['bg-orange-400', 'bg-lime-400', 'bg-emerald-400', 'bg-cyan-400', 'bg-indigo-400', 'bg-fuchsia-400', 'bg-rose-400'];
                        row['metadata_tags_0'].split(',').forEach(function(tag) {
                            tag = tag.trim();
                            if (!tag) return;
                            var tagHash = tag.split('').reduce((prevHash, currVal) => ((prevHash << 5) - prevHash) + currVal.charCodeAt(0), 0);
                            var tagColor = tagColors[Math.abs(tagHash) % tagColors.length];
                            tags += "<span class='py-1 px-2 rounded-full text-white " + tagColor + " mr-2 mb-2 text-xs inline-block'>#" + tag + "</span> ";
                        });
                    }
                    td = $('<td class="p-2">').html(
                        (tags ? `<div class="mt-1">${tags}</div>` : '--')
                    );
                } else if (colKey === 'ip_range') {
                    // IP-Bereich anzeigen
                    let usable = '';
                    if (row.ip_range && row.subnet) {
                        // IPv4: Berechne Start/Ende aus Range und Subnet
                        function ipToInt(ip) {
                            return ip.split('.').reduce((acc, oct) => (acc << 8) + parseInt(oct), 0);
                        }
                        function intToIp(int) {
                            return [24,16,8,0].map(shift => (int >> shift) & 255).join('.');
                        }
                        let [rangeBase] = row.ip_range.split('/');
                        let subnet = parseInt(row.subnet);
                        let start = ipToInt(rangeBase);
                        let hostBits = 32 - subnet;
                        let count = Math.pow(2, hostBits);
                        let end = start + count - 1;
                        if (count > 2) usable = `Nutzbare Adressen: ${count - 2}`;
                        else if (count > 0) usable = `Nutzbare Adressen: ${count}`;
                    }
                    td = $('<td class="p-2">')
                        .text(row.ip_range || '--')
                        .attr('title', usable);
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center">')
                .html('<i data-lucide="info"></i>')
                .click(() => openDetailsPopup(row));
            let deleteButton = $('<button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center">')
                .html('<i data-lucide="trash"></i>')
                .click(() => deleteEntry(row.uuid, row));
            tr.append($('<td class="p-2 flex flex-row gap-4">').append(detailsButton).append(deleteButton));
            $tableBody.append(tr);
        });
    
    } else if (currentTable === 'device_join_metadata_join_location_join_location') {
        // Geräte anzeigen
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'metadata_tags_0') {
                    // Tags als Badges unterhalb des Zelleninhalts anzeigen
                    let tags = '';
                    if (row['metadata_tags_0']) {
                        var tagColors = ['bg-orange-400', 'bg-lime-400', 'bg-emerald-400', 'bg-cyan-400', 'bg-indigo-400', 'bg-fuchsia-400', 'bg-rose-400'];
                        row['metadata_tags_0'].split(',').forEach(function(tag) {
                            tag = tag.trim();
                            if (!tag) return;
                            var tagHash = tag.split('').reduce((prevHash, currVal) => ((prevHash << 5) - prevHash) + currVal.charCodeAt(0), 0);
                            var tagColor = tagColors[Math.abs(tagHash) % tagColors.length];
                            tags += "<span class='py-1 px-2 rounded-full text-white " + tagColor + " mr-2 mb-2 text-xs inline-block'>#" + tag + "</span> ";
                        });
                    }
                    td = $('<td class="p-2">').html(
                        (tags ? `<div class="mt-1">${tags}</div>` : '--')
                    );
                } else if (colKey === 'type') {
                    // Gerätetyp mit Icon und Statusfarbe
                    let iconHtml = '';
                    let statusColor = '';
                    switch (row.metadata_status_0) {
                        case 0: statusColor = '#22c55e'; break; // Aktiv
                        case 2: statusColor = '#eab308'; break; // Reserviert
                        case 4: statusColor = '#ef4444'; break; // Inaktiv
                        case 6: statusColor = '#6b7280'; break; // Archiviert
                        default: statusColor = '#64748b'; // Unbekannt
                    }
                    switch (row[colKey]) {
                        case '--':
                            iconHtml = `<i data-lucide="ban" title="Unbekannt" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'Patchpanel':
                            iconHtml = `<i data-lucide="rectangle-ellipsis" title="Patchpanel" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'net_outlet':
                            iconHtml = `<i data-lucide="ethernet-port" title="Netzwerkdose" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'phone':
                            iconHtml = `<i data-lucide="phone" title="Telefon" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'notebook':
                            iconHtml = `<i data-lucide="laptop" title="Notebook" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'thinclient':
                            iconHtml = `<i data-lucide="monitor-smartphone" title="Thin Client" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'desktop':
                            iconHtml = `<i data-lucide="pc-case" title="Desktop" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'accesspoint':
                            iconHtml = `<i data-lucide="wifi" title="Access Point" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'printer':
                            iconHtml = `<i data-lucide="printer" title="Drucker" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'switch':
                            iconHtml = `<i data-lucide="network" title="Switch" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'server':
                            iconHtml = `<i data-lucide="server" title="Server" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'router':
                            iconHtml = `<i data-lucide="router" title="Router" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'firewall':
                            iconHtml = `<i data-lucide="brick-wall-fire" title="Firewall" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'loadbalancer':
                            iconHtml = `<i data-lucide="loader-circle" title="Load Balancer" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'storage':
                            iconHtml = `<i data-lucide="hard-drive" title="Storage" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'sensor':
                            iconHtml = `<i data-lucide="thermometer" title="Sensor" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        case 'ups':
                            iconHtml = `<i data-lucide="battery-full" title="USV" style="color:${statusColor};vertical-align:middle"></i>`;
                            break;
                        default:
                            iconHtml = `<i data-lucide="server" title="${row[colKey]}" style="color:${statusColor};vertical-align:middle"></i>`;
                    }
                    td = $('<td class="p-2">').html(
                        `${iconHtml} <span class="ml-2">${row[colKey] || '--'}</span>`
                    );
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center">')
                .html('<i data-lucide="info"></i>')
                .click(() => openDetailsPopup(row));
            let deleteButton = $('<button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center">')
                .html('<i data-lucide="trash"></i>')
                .click(() => deleteEntry(row.uuid, row));
            tr.append($('<td class="p-2 flex flex-row gap-4">').append(detailsButton).append(deleteButton));
            $tableBody.append(tr);
        });

    } else if (currentTable === 'device_port_join_metadata_join_device_join_vlan_join_vlan') {
        // Device Ports anzeigen
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'device_port') {
                    // Portnummer mit Gerät und VLAN
                    td = $('<td class="p-2">').html(
                        `${row.device_port || '--'} <br> <span class="text-xs text-gray-500">${row.device_name || '--'}</span> <br> <span class="text-xs text-gray-500">${row.vlan_id || '--'}</span>`
                    );
                } else if (colKey === 'metadata_status_0') {
                    // Status-Icon mit Farbe
                    let statusColor = '';
                    switch (row.metadata_status_0) {
                        case 0: statusColor = '#22c55e'; break; // Aktiv
                        case 2: statusColor = '#eab308'; break; // Reserviert
                        case 4: statusColor = '#ef4444'; break; // Inaktiv
                        case 6: statusColor = '#6b7280'; break; // Archiviert
                        default: statusColor = '#64748b'; // Unbekannt
                    }
                    td = $('<td class="p-2">').html(
                        `<span class="h-10 w-10 rounded-full flex items-center justify-center"><i data-lucide="ethernet-port" style="color:${statusColor};vertical-align:middle" title="${row.metadata_status_0}"></i></span>`
                    );
                } else if (colKey === 'metadata_tags_0') {
                    // Tags als Badges unterhalb des Zelleninhalts anzeigen
                    let tags = '';
                    if (row['metadata_tags_0']) {
                        var tagColors = ['bg-orange-400', 'bg-lime-400', 'bg-emerald-400', 'bg-cyan-400', 'bg-indigo-400', 'bg-fuchsia-400', 'bg-rose-400'];
                        row['metadata_tags_0'].split(',').forEach(function(tag) {
                            tag = tag.trim();
                            if (!tag) return;
                            var tagHash = tag.split('').reduce((prevHash, currVal) => ((prevHash << 5) - prevHash) + currVal.charCodeAt(0), 0);
                            var tagColor = tagColors[Math.abs(tagHash) % tagColors.length];
                            tags += "<span class='py-1 px-2 rounded-full text-white " + tagColor + " mr-2 mb-2 text-xs inline-block'>#" + tag + "</span> ";
                        });
                    }
                    td = $('<td class="p-2">').html(
                        (tags ? `<div class="mt-1">${tags}</div>` : '--')
                    );
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center">')
                .html('<i data-lucide="info"></i>')
                .click(() => openDetailsPopup(row));
            let deleteButton = $('<button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center">')
                .html('<i data-lucide="trash"></i>')
                .click(() => deleteEntry(row.uuid, row));
            tr.append($('<td class="p-2 flex flex-row gap-4">').append(detailsButton).append(deleteButton));
            $tableBody.append(tr);
        });    

    } else if (currentTable === 'connection_join_metadata_join_device_port_join_device_port_join_device_port_join_device_port') {
        // Verbindungen anzeigen
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'connection') {
                    // Verbindung mit Geräten und Ports
                    td = $('<td class="p-2">').html(
                        `${row.connection || '--'} <br> <span class="text-xs text-gray-500">${row.device_name || '--'}</span> <br> <span class="text-xs text-gray-500">${row.device_port || '--'}</span>`
                    );
                } else if (colKey === 'metadata_status_0') {
                    // Status-Icon mit Farbe
                    let statusColor = '';
                    switch (row.metadata_status_0) {
                        case 0: statusColor = '#22c55e'; break; // Aktiv
                        case 2: statusColor = '#eab308'; break; // Reserviert
                        case 4: statusColor = '#ef4444'; break; // Inaktiv
                        case 6: statusColor = '#6b7280'; break; // Archiviert
                        default: statusColor = '#64748b'; // Unbekannt
                    }
                    td = $('<td class="p-2">').html(
                        `<span class="h-10 w-10 rounded-full flex items-center justify-center"><i data-lucide="link" style="color:${statusColor};vertical-align:middle" title="${row.metadata_status_0}"></i></span>`
                    );
                } else if (colKey === 'metadata_tags_0') {
                    // Tags als Badges unterhalb des Zelleninhalts anzeigen
                    let tags = '';
                    if (row['metadata_tags_0']) {
                        var tagColors = ['bg-orange-400', 'bg-lime-400', 'bg-emerald-400', 'bg-cyan-400', 'bg-indigo-400', 'bg-fuchsia-400', 'bg-rose-400'];
                        row['metadata_tags_0'].split(',').forEach(function(tag) {
                            tag = tag.trim();
                            if (!tag) return;
                            var tagHash = tag.split('').reduce((prevHash, currVal) => ((prevHash << 5) - prevHash) + currVal.charCodeAt(0), 0);
                            var tagColor = tagColors[Math.abs(tagHash) % tagColors.length];
                            tags += "<span class='py-1 px-2 rounded-full text-white " + tagColor + " mr-2 mb-2 text-xs inline-block'>#" + tag + "</span> ";
                        });
                    }
                    td = $('<td class="p-2">').html(
                        (tags ? `<div class="mt-1">${tags}</div>` : '--')
                    );
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center">')
                .html('<i data-lucide="info"></i>')
                .click(() => openDetailsPopup(row));
            let deleteButton = $('<button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center">')
                .html('<i data-lucide="trash"></i>')
                .click(() => deleteEntry(row.uuid, row));
            tr.append($('<td class="p-2 flex flex-row gap-4">').append(detailsButton).append(deleteButton));
            $tableBody.append(tr);
        });    

    } else {
        // Standardanzeige
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'metadata_tags_0') {
                    // Tags als Badges unterhalb des Zelleninhalts anzeigen
                    let tags = '';
                    if (row['metadata_tags_0']) {
                        var tagColors = ['bg-orange-400', 'bg-lime-400', 'bg-emerald-400', 'bg-cyan-400', 'bg-indigo-400', 'bg-fuchsia-400', 'bg-rose-400'];
                        row['metadata_tags_0'].split(',').forEach(function(tag) {
                            tag = tag.trim();
                            if (!tag) return;
                            var tagHash = tag.split('').reduce((prevHash, currVal) => ((prevHash << 5) - prevHash) + currVal.charCodeAt(0), 0);
                            var tagColor = tagColors[Math.abs(tagHash) % tagColors.length];
                            tags += "<span class='py-1 px-2 rounded-full text-white " + tagColor + " mr-2 mb-2 text-xs inline-block'>#" + tag + "</span> ";
                        });
                    }
                    td = $('<td class="p-2">').html(
                        (tags ? `<div class="mt-1">${tags}</div>` : '--')
                    );
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });

            let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center">')
                .html('<i data-lucide="info"></i>')
                .click(() => openDetailsPopup(row));
            let deleteButton = $('<button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center">')
                .html('<i data-lucide="trash"></i>')
                .click(() => deleteEntry(row.uuid, row));
            tr.append($('<td class="p-2 flex flex-row gap-4">').append(detailsButton).append(deleteButton));

            $tableBody.append(tr);
        });
    }

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
    // only if detailsPopup is open
    if (!$('#detailsPopup').hasClass('hidden')) {
        $('#detailsPopup').addClass('hidden');
    }
}

// Delete entry
function deleteEntry(uuid, rowData) {
    console.log(rowData);
    if (confirm('Möchten Sie diesen Eintrag wirklich löschen?')) {
        fetch('<?php echo PORTFLOW_HOSTNAME; ?>' + '/forms.json')
        .then(response => response.json())
        .then(data => {
            var formConfig = data.forms[currentTable];
            if (!formConfig) {
                console.error(`No form configuration found for table: ${currentTable}`);
                return;
            }

            // Umgekehrte Reihenfolge der Tabellen für das Löschen
            var tablesToDelete = formConfig.postOrder.map(item => item.table).reverse();

            // Extrahieren Sie alle UUIDs aus rowData
            var uuidsToDelete = {};
            Object.entries(rowData).forEach(([key, value]) => {
                if (key.match(/_uuid(_\d+)?$/)) {
                    var table = key.split('_uuid')[0];
                    uuidsToDelete[table] = value;
                }
            });

            // Fügen Sie die initiale UUID für die erste Tabelle hinzu
            uuidsToDelete[currentTable] = uuid;

            // Funktion zum rekursiven Löschen der Einträge
            function deleteNextTable(index) {
                if (index >= tablesToDelete.length) {
                    console.log('Alle Einträge wurden gelöscht.');
                    loadTable(currentTable);
                    return;
                }

                var table = tablesToDelete[index];
                var tableUuid;

                // Verwenden Sie die übergebene UUID für die erste Tabelle
                if (index === 0) {
                    tableUuid = uuid;
                } else {
                    tableUuid = uuidsToDelete[table];
                }

                if (!tableUuid) {
                    console.error(`No UUID found for table: ${table}`);
                    deleteNextTable(index + 1);
                    return;
                }

                console.log('deleteEntry: ' + table);

                var url = '<?php echo PORTFLOW_HOSTNAME; ?>' + '/api/' + table + '/' + tableUuid;
                ajaxPost(url, 'DELETE', {}, function() {
                    console.log('Eintrag in Tabelle ' + table + ' gelöscht.');
                    deleteNextTable(index + 1);
                }, function(error) {
                    console.error('Fehler beim Löschen des Eintrags in Tabelle ' + table + ':', error);
                });
            }

            // Starten Sie den Löschvorgang mit der ersten Tabelle
            deleteNextTable(0);
        })
        .catch(error => {
            console.error('Error fetching forms.json:', error);
        });
    }
}

// Save user column preferences
function saveUserColumnPreferences(table, selectedColumns) {
    const settings = { [table]: { columns: selectedColumns } };
    ajaxPost(`${'<?php echo PORTFLOW_HOSTNAME; ?>'}/api/settings`, 'POST', settings, () => console.log('Preferences saved successfully'));
}
</script>
<?php
    include_once 'includes/footer.php';
?>
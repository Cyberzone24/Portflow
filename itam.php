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
            <li onclick="loadTable('location_details')" class="bg-white py-2 px-4 rounded-l-lg pr-0"><?php echo $lang['location']; ?></li>
            <li onclick="loadTable('ip_range_join_metadata')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['ipam']; ?></li>
            <li onclick="loadTable('vlan_details')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['vlan']; ?></li>
            <li onclick="loadTable('device_details')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['devices']; ?></li>
            <li onclick="loadTable('device_port_details')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['device ports']; ?></li>
            <li onclick="loadTable('connection_details')" class="bg-white py-2 px-4 rounded-lg mr-4"><?php echo $lang['connections']; ?></li>
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
async function generateFormFromJSON(table = 'location_details') {
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
                        // Dynamisch das Feld für die Anzeige suchen
                        let captionKey = Object.keys(item).find(k => k.endsWith('_metadata_caption')) 
                            || Object.keys(item).find(k => k.endsWith('_caption')) 
                            || Object.keys(item).find(k => k.endsWith('_name')) 
                            || Object.keys(item)[0]; // Fallback: erstes Feld
                    
                        // Dynamisch das passende UUID-Feld bestimmen
                        let resourceBase = config.resource.replace(/_details$/, '');
                        let uuidKey = Object.keys(item).find(k => k === resourceBase + '_uuid') 
                            || Object.keys(item).find(k => k.endsWith('_uuid')) 
                            || 'uuid';
                    
                        const entry = document.createElement('div');
                        entry.className = 'hover:bg-gray-100 cursor-pointer p-2';
                        entry.textContent = item[captionKey] || item[uuidKey] || '[kein Name]';
                        entry.onclick = () => {
                            textInput.value = item[captionKey] || '';
                            hiddenField.value = item[uuidKey] || '';
                            lastSelectedText = item[captionKey] || '';
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
async function submitForms(table) {
    console.log('Submitting forms for table:', table);
    const forms = Array.from(document.querySelectorAll('form'));
    const responseUuids = {}; // Hier werden die erzeugten UUIDs gespeichert

    // Lade die postOrder-Konfiguration
    const configResponse = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/forms.json');
    const configData = await configResponse.json();
    const postOrder = configData.forms[table].postOrder;

    // Hilfsfunktion, um die UUIDs in die richtigen Felder einzutragen
    function injectUuids(postData, postConfig) {
        if (postConfig.useMetadataUUID && responseUuids.metadata) {
            postData.metadata = responseUuids.metadata;
        }
        if (postConfig.useVlanUUID && responseUuids.device_port_vlan) {
            postData.device_port_vlan = responseUuids.device_port_vlan;
        }
        if (postConfig.useIpUUID && responseUuids.device_port_ip) {
            postData.device_port_ip = responseUuids.device_port_ip;
        }
    }

    // Reihenfolge gemäß postOrder abarbeiten
    for (const postConfig of postOrder) {
        const form = forms.find(f => f.id === postConfig.table);
        if (!form) continue;

        const formData = new FormData(form);
        const postData = {};
        formData.forEach((value, key) => { postData[key] = value; });

        // UUIDs aus vorherigen POSTs einfügen, falls benötigt
        injectUuids(postData, postConfig);

        const apiUrl = `<?php echo PORTFLOW_HOSTNAME; ?>/api/${postConfig.table}/`;
        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(postData)
            });
            const data = await response.json();
            // Speichere die erzeugte UUID für spätere POSTs
            if (data && data[0] && data[0].uuid) {
                responseUuids[postConfig.table] = data[0].uuid;
                if (postConfig.table === 'metadata') responseUuids.metadata = data[0].uuid;
                if (postConfig.table === 'device_port_vlan') responseUuids.device_port_vlan = data[0].uuid;
                if (postConfig.table === 'device_port_ip') responseUuids.device_port_ip = data[0].uuid;
            }
        } catch (error) {
            console.error(`Fehler beim Senden der ${postConfig.table}-Daten:`, error);
            break;
        }
    }

    closeNewEntry();
    loadTable(table);
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
function loadTable(table = 'location_details', search = '', limit = 100, page = 1) {
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

    // Standard Farben für Status
    const STATUS_COLORS = {
        0: '#22c55e',  // Aktiv - Grün
        2: '#eab308',  // Deaktiviert - Gelb
        4: '#ef4444',  // Offline - Rot
        6: '#6b7280',  // Ungenutzt - Grau
        default: '#6b7280'  // Unbekannt - Grau
    };

    const STATUS_TITLES = {
        0: 'Aktiv',
        2: 'Deaktiviert', 
        4: 'Offline',
        6: 'Ungenutzt',
        default: 'Unbekannt'
    };

    // Tag Farben
    const TAG_COLORS = ['bg-orange-400', 'bg-lime-400', 'bg-emerald-400', 'bg-cyan-400', 'bg-indigo-400', 'bg-fuchsia-400', 'bg-rose-400'];

    // Tabellenkopf erstellen
    let trHead = $('<tr class="border-b bg-gray-200">');
    userColumns.forEach(colKey => trHead.append($('<th class="p-2">').text(columnsConfig[colKey] || colKey)));
    trHead.append($('<th class="p-2">Actions</th>'));
    $tableHead.append(trHead);

    // Hilfsfunktionen
    function getStatusColor(status) {
        return STATUS_COLORS[status] || STATUS_COLORS.default;
    }

    function getStatusTitle(status) {
        return STATUS_TITLES[status] || STATUS_TITLES.default;
    }

    function createStatusIcon(iconName, status, title = null) {
        const color = getStatusColor(status);
        const statusTitle = title || getStatusTitle(status);
        return `<span class="h-10 w-10 rounded-full flex items-center justify-center">
                    <i data-lucide="${iconName}" style="color:${color};vertical-align:middle" title="${statusTitle}"></i>
                </span>`;
    }

    function createTagsHtml(tagsString) {
        if (!tagsString) return '--';
        
        let tags = '';
        tagsString.split(',').forEach(function(tag) {
            tag = tag.trim();
            if (!tag) return;
            var tagHash = tag.split('').reduce((prevHash, currVal) => ((prevHash << 5) - prevHash) + currVal.charCodeAt(0), 0);
            var tagColor = TAG_COLORS[Math.abs(tagHash) % TAG_COLORS.length];
            tags += `<span class='py-1 px-2 rounded-full text-white ${tagColor} mr-2 mb-2 text-xs inline-block'>#${tag}</span> `;
        });
        return `<div class="mt-1">${tags}</div>`;
    }

    function calculateUsableIPs(ipRangeCidr) {
        if (!ipRangeCidr) return '';
        let [ip, subnet] = ipRangeCidr.split('/');
        let subnetInt = parseInt(subnet);
        let hostBits = 32 - subnetInt;
        let count = Math.pow(2, hostBits);
        if (count > 2) return `Nutzbare Adressen: ${count - 2}`;
        if (count > 0) return `Nutzbare Adressen: ${count}`;
        return '';
    }
    
    function getSubnetMask(ipRangeCidr) {
        if (!ipRangeCidr) return '';
        let [, subnet] = ipRangeCidr.split('/');
        let subnetInt = parseInt(subnet);
        let mask = [];
        for (let i = 0; i < 4; i++) {
            let n = Math.min(8, subnetInt);
            mask.push(256 - Math.pow(2, 8 - n));
            subnetInt -= n;
        }
        return `Subnetz-Maske: ${mask.join('.')}`;
    }

    function getNetworkInfo(ipRangeCidr) {
        if (!ipRangeCidr) return { type: '', class: '' };
    
        function ipToInt(ip) {
            return ip.split('.').reduce((acc, oct) => (acc << 8) + parseInt(oct), 0);
        }
    
        // Extrahiere IP und Subnet aus dem CIDR-String
        let [rangeBase, subnet] = ipRangeCidr.split('/');
        let ipInt = ipToInt(rangeBase);
        let subnetInt = parseInt(subnet);
    
        // Netzklasse bestimmen
        let netClass = '';
        if (subnetInt <= 8) netClass = 'A';
        else if (subnetInt <= 16) netClass = 'B';
        else if (subnetInt <= 24) netClass = 'C';
        else if (subnetInt <= 30) netClass = 'D';
        else if (subnetInt <= 32) netClass = 'E';
        else netClass = '';
    
        // Privat/Öffentlich bestimmen
        let isPrivate = (
            (ipInt >= ipToInt('10.0.0.0') && ipInt <= ipToInt('10.255.255.255')) ||
            (ipInt >= ipToInt('172.16.0.0') && ipInt <= ipToInt('172.31.255.255')) ||
            (ipInt >= ipToInt('192.168.0.0') && ipInt <= ipToInt('192.168.255.255'))
        );
    
        let netType = isPrivate ?
            `<i data-lucide="lock-keyhole" style="color:#6366f1;vertical-align:middle" title="Privates Netz"></i>` :
            `<i data-lucide="lock-keyhole-open" style="color:#f59e42;vertical-align:middle" title="Öffentliches Netz"></i>`;
    
        let netClassHtml = `<span class="h-10 w-10 rounded-full bg-gray-200 text-white flex items-center justify-center font-bold"><p>${netClass}</p></span>`;
    
        return { type: netType, class: netClassHtml };
    }

    // Spezielle Renderer für verschiedene Tabellen
    const renderers = {
        'location_details': renderLocationHierarchy,
        'ip_range_join_metadata': renderIPRanges,
        'vlan_details': renderVLANs,
        'device_details': renderDevices,
        'device_port_details': renderDevicePorts,
        'connection_details': renderConnections
    };

    function renderLocationHierarchy(rows) {
        // Dynamische Feldnamen bestimmen
        const tableBase = currentTable.replace(/_details$/, '');
        const uuidField = tableBase + '_uuid';
        const parentField = tableBase + '_parent_location';

        const byParent = {};
        const allParents = new Set();
        const allUuids = new Set();

        rows.forEach(row => {
            const parent = row[parentField] || 'root';
            if (!byParent[parent]) byParent[parent] = [];
            byParent[parent].push(row);
            allParents.add(parent);
            allUuids.add(row[uuidField]);
        });

        const roots = Array.from(allParents).filter(parent => !allUuids.has(parent));

        function renderRows(parent, level = 0) {
            (byParent[parent] || []).forEach(row => {
                let tr = $('<tr class="border-b hover:bg-gray-200">');
                let dashes = level > 0 ? Array(level + 1).join('— ') : '';

                userColumns.forEach(colKey => {
                    let td;
                    if (colKey === 'location_type') {
                        const locationIcons = {
                            '0': { icon: 'scan', title: 'Region' },
                            '2': { icon: 'land-plot', title: 'Komplex' },
                            '4': { icon: 'school', title: 'Gebäude' },
                            '6': { icon: 'door-closed', title: 'Raum' },
                            '8': { icon: 'server', title: 'Rack' }
                        };

                        const config = locationIcons[row[colKey]] || { icon: 'help-circle', title: 'Unbekannt' };
                        const color = getStatusColor(row[tableBase + '_metadata_status']);

                        let iconHtml = `${dashes}<i data-lucide="${config.icon}" style="color:${color};display:inline-block;vertical-align:middle" title="${config.title}"></i>`;
                        iconHtml += ` <span>${row[tableBase + '_metadata_caption'] || ''}</span>`;

                        td = $('<td class="p-2">').html(iconHtml).attr('title', config.title);
                    } else {
                        td = $('<td class="p-2">').text(row[colKey] || '--');
                    }
                    tr.append(td);
                });

                tr.append(createActionButtons(row));
                $tableBody.append(tr);
                renderRows(row[uuidField], level + 1);
            });
        }

        roots.forEach(root => renderRows(root));
    }

    function renderIPRanges(rows) {
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'ip_range') {
                    let usable = calculateUsableIPs(row.ip_range_ip_range);
                    let mask = getSubnetMask(row.ip_range_ip_range);
                    td = $('<td class="p-2">').text(row.ip_range_ip_range || '--').attr('title', usable + '\n' + mask);
                } else if (colKey === 'ip_range_metadata_status') {
                    let networkInfo = getNetworkInfo(row.ip_range_ip_range);
                    td = $('<td class="p-2 flex flex-row gap-4">').html(
                        createStatusIcon('chevrons-left-right-ellipsis', row.ip_range_metadata_status) +
                        `<span class="h-10 w-10 rounded-full flex items-center justify-center">${networkInfo.type}</span> ${networkInfo.class}`
                    );
                } else if (colKey === 'ip_range_metadata_tags') {
                    td = $('<td class="p-2">').html(createTagsHtml(row[colKey]));
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            
            tr.append(createActionButtons(row));
            $tableBody.append(tr);
        });
    }

    function renderVLANs(rows) {
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'vlan_id') {
                    td = $('<td class="p-2">').html(
                        createStatusIcon('chevrons-left-right-ellipsis', row.vlan_metadata_status) + ` ${row[colKey] || '--'}`
                    );
                } else if (colKey === 'vlan_metadata_status') {
                    td = $('<td class="p-2">').html(createStatusIcon('workflow', row.vlan_metadata_status));
                } else if (colKey === 'vlan_ip_range_ip_range') {
                    let usable = calculateUsableIPs(row.vlan_ip_range_ip_range);
                    td = $('<td class="p-2">').text(row.vlan_ip_range_ip_range || '--').attr('title', usable);
                } else if (colKey === 'vlan_metadata_tags') {
                    td = $('<td class="p-2">').html(createTagsHtml(row[colKey]));
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            
            tr.append(createActionButtons(row));
            $tableBody.append(tr);
        });
    }

    function renderDevices(rows) {
        const deviceIcons = {
            'Patchpanel': 'rectangle-ellipsis',
            'net_outlet': 'ethernet-port',
            'phone': 'phone',
            'notebook': 'laptop',
            'thinclient': 'monitor-smartphone',
            'desktop': 'pc-case',
            'accesspoint': 'wifi',
            'printer': 'printer',
            'switch': 'network',
            'server': 'server',
            'router': 'router',
            'firewall': 'brick-wall-fire',
            'loadbalancer': 'loader-circle',
            'storage': 'hard-drive',
            'sensor': 'thermometer',
            'ups': 'battery-full'
        };

        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'device_type') {
                    const icon = deviceIcons[row[colKey]] || 'server';
                    const color = getStatusColor(row.device_metadata_status);
                    td = $('<td class="p-2">').html(
                        `<i data-lucide="${icon}" title="${row[colKey]}" style="color:${color};vertical-align:middle"></i>`
                    );
                } else if (colKey === 'device_metadata_tags') {
                    td = $('<td class="p-2">').html(createTagsHtml(row[colKey]));
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            
            tr.append(createActionButtons(row));
            $tableBody.append(tr);
        });
    }

    function renderDevicePorts(rows) {
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'device_port') {
                    td = $('<td class="p-2">').html(
                        `${row.device_port || '--'} <br> <span class="text-xs text-gray-500">${row.device_name || '--'}</span> <br> <span class="text-xs text-gray-500">${row.vlan_id || '--'}</span>`
                    );
                } else if (colKey === 'metadata_status_0') {
                    td = $('<td class="p-2">').html(createStatusIcon('ethernet-port', row.metadata_status_0));
                } else if (colKey === 'metadata_tags_0') {
                    td = $('<td class="p-2">').html(createTagsHtml(row[colKey]));
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            
            tr.append(createActionButtons(row));
            $tableBody.append(tr);
        });
    }

    function renderConnections(rows) {
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'connection') {
                    td = $('<td class="p-2">').html(
                        `${row.connection || '--'} <br> <span class="text-xs text-gray-500">${row.device_name || '--'}</span> <br> <span class="text-xs text-gray-500">${row.device_port || '--'}</span>`
                    );
                } else if (colKey === 'metadata_status_0') {
                    td = $('<td class="p-2">').html(createStatusIcon('link', row.metadata_status_0));
                } else if (colKey === 'metadata_tags_0') {
                    td = $('<td class="p-2">').html(createTagsHtml(row[colKey]));
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            
            tr.append(createActionButtons(row));
            $tableBody.append(tr);
        });
    }

    function renderDefault(rows) {
        rows.forEach(row => {
            let tr = $('<tr class="border-b hover:bg-gray-200">');
            
            userColumns.forEach(colKey => {
                let td;
                if (colKey === 'metadata_tags_0') {
                    td = $('<td class="p-2">').html(createTagsHtml(row[colKey]));
                } else {
                    td = $('<td class="p-2">').text(row[colKey] || '--');
                }
                tr.append(td);
            });
            
            tr.append(createActionButtons(row));
            $tableBody.append(tr);
        });
    }

    function createActionButtons(row) {
        let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center">')
            .html('<i data-lucide="info"></i>')
            .click(() => openDetailsPopup(row));
        let deleteButton = $('<button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center">')
            .html('<i data-lucide="trash"></i>')
            .click(() => deleteEntry(row.uuid, row));
        return $('<td class="p-2 flex flex-row gap-4">').append(detailsButton).append(deleteButton);
    }

    // Renderer basierend auf currentTable wählen
    const renderer = renderers[currentTable] || renderDefault;
    renderer(rows);

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
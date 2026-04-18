<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

const APP_NAME = 'Portflow';

include_once __DIR__ . '/includes/core/session.php';
if (!in_array(__DIR__ . '/includes/core/session.php', get_included_files(), true)) {
    die('could not verify session');
}

include_once __DIR__ . '/includes/core/db_adapter.php';
use Portflow\Core\DatabaseAdapter;

$db_adapter = new DatabaseAdapter();

function normalizeStatusClass($status): string {
    $statusText = strtolower(trim((string)$status));

    if (in_array($statusText, ['0', 'active'], true)) {
        return 'text-green-500';
    }

    if (in_array($statusText, ['2', 'inactive', 'deactivated'], true)) {
        return 'text-amber-500';
    }

    if (in_array($statusText, ['4', 'offline', 'unpatched'], true)) {
        return 'text-red-500';
    }

    if (in_array($statusText, ['6', 'unused'], true)) {
        return 'text-slate-500';
    }

    return 'text-blue-500';
}

function formatSpeedLabel($speed): string {
    if ($speed === null || $speed === '') {
        return '--';
    }

    $speedText = trim((string)$speed);
    $map = [
        '100' => '100 Mbit/s',
        '1000' => '1 Gbit/s',
        '2500' => '2.5 Gbit/s',
        '10000' => '10 Gbit/s',
        '25000' => '25 Gbit/s',
        '40000' => '40 Gbit/s',
        '100000' => '100 Gbit/s'
    ];

    return $map[$speedText] ?? ($speedText . ' Mbit/s');
}

if (isset($_GET['action']) && $_GET['action'] === 'get') {
    header('Content-Type: application/json');

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $limit = in_array($limit, [50, 100, 500, 1000], true) ? $limit : 100;

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }

    $offset = ($page - 1) * $limit;
    $searchRaw = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

    $allowedSortColumns = [
        'switch_port_status',
        'endpoint_room',
        'endpoint_port_caption',
        'endpoint_port_hostname',
        'switch_port_vlan_untagged',
        'switch_port_vlan_tagged',
        'endpoint_port_mac_address',
        'patchpanel_caption',
        'switch_caption',
        'switch_port_caption',
        'endpoint_device_type',
        'connection_speed'
    ];

    $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'switch_caption';
    if (!in_array($sort, $allowedSortColumns, true)) {
        $sort = 'switch_caption';
    }

    $order = isset($_GET['order']) ? strtoupper((string)$_GET['order']) : 'ASC';
    $order = $order === 'DESC' ? 'DESC' : 'ASC';

    $baseSelect = 'SELECT * FROM portview';
    $baseCount = 'SELECT COUNT(*) AS count FROM portview';

    $searchClause = '';
    $params = [];

    if ($searchRaw !== '') {
        $searchClause = ' WHERE '
            . 'CAST(connection_uuid AS TEXT) ILIKE :search '
            . 'OR CAST(switch_port_status AS TEXT) ILIKE :search '
            . 'OR CAST(connection_speed AS TEXT) ILIKE :search '
            . 'OR CAST(endpoint_device_type AS TEXT) ILIKE :search '
            . 'OR CAST(endpoint_room AS TEXT) ILIKE :search '
            . 'OR CAST(endpoint_port_caption AS TEXT) ILIKE :search '
            . 'OR CAST(endpoint_port_hostname AS TEXT) ILIKE :search '
            . 'OR CAST(switch_port_vlan_tagged AS TEXT) ILIKE :search '
            . 'OR CAST(switch_port_vlan_untagged AS TEXT) ILIKE :search '
            . 'OR CAST(endpoint_port_mac_address AS TEXT) ILIKE :search '
            . 'OR CAST(patchpanel_caption AS TEXT) ILIKE :search '
            . 'OR CAST(switch_caption AS TEXT) ILIKE :search '
            . 'OR CAST(switch_port_caption AS TEXT) ILIKE :search';

        $params['search'] = '%' . $searchRaw . '%';
    }

    $resultsQuery = $baseSelect . $searchClause . " ORDER BY \"$sort\" $order LIMIT :limit OFFSET :offset";
    $resultsParams = $params;
    $resultsParams['limit'] = $limit;
    $resultsParams['offset'] = $offset;

    $results = $db_adapter->db_query($resultsQuery, $resultsParams);
    $totalResults = $db_adapter->db_query($baseCount . $searchClause, $params);
    $totalCount = isset($totalResults[0]['count']) ? (int)$totalResults[0]['count'] : 0;

    echo json_encode([
        'results' => $results,
        'totalCount' => $totalCount,
        'limit' => $limit,
        'currentPage' => $page
    ]);
    die();
}

include_once __DIR__ . '/includes/header.php';
$limit = isset($_COOKIE['table_limit']) ? (int)$_COOKIE['table_limit'] : 100;
if (!in_array($limit, [50, 100, 500, 1000], true)) {
    $limit = 100;
}
?>

<div class="m-4 mt-0 rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Port View</h1>
            <p class="text-sm text-slate-500">Verdichtete Portansicht aus dem neuen Read-Model View.</p>
        </div>
        <form id="searchForm" class="flex min-w-[18rem] flex-1 items-center justify-end gap-2">
            <input
                type="text"
                name="search"
                placeholder="Suchen ..."
                class="w-full max-w-md rounded-full border border-slate-300 px-4 py-2 shadow-sm focus:border-blue-500 focus:outline-none"
            >
            <button
                type="submit"
                class="h-10 w-10 rounded-full bg-blue-500 text-white shadow-md transition hover:bg-blue-700"
                title="Suche"
            >
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </form>
    </div>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-4">
            <p id="count" class="text-sm font-semibold text-slate-700">Datensaetze: 0</p>
            <p id="resultSummary" class="text-sm text-slate-500">Zeige 0-0 von 0</p>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <label for="table_limit_1" class="text-slate-600">Anzahl:</label>
            <select id="table_limit_1" name="limit" class="rounded-full border border-slate-300 px-3 py-1">
                <option value="50" <?php if ($limit === 50) echo 'selected'; ?>>50</option>
                <option value="100" <?php if ($limit === 100) echo 'selected'; ?>>100</option>
                <option value="500" <?php if ($limit === 500) echo 'selected'; ?>>500</option>
                <option value="1000" <?php if ($limit === 1000) echo 'selected'; ?>>1000</option>
            </select>
        </div>
    </div>

    <div id="pagination" class="mb-3 flex flex-wrap items-center gap-2 text-sm"></div>

    <div class="overflow-auto rounded-xl border border-slate-300 shadow-sm">
        <table class="w-full min-w-[1100px] text-left text-sm text-slate-600">
            <thead class="bg-slate-200 text-slate-900">
                <tr>
                    <th scope="col" class="p-2" data-sort="switch_port_status">Status <span class="sort-icon"></span></th>
                    <th scope="col" class="p-2" data-sort="endpoint_room">Raum <span class="sort-icon"></span></th>
                    <th scope="col" class="p-2" data-sort="endpoint_port_caption">Endgeraete-Port <span class="sort-icon"></span></th>
                    <th scope="col" class="p-2" data-sort="endpoint_port_hostname">Hostname <span class="sort-icon"></span></th>
                    <th scope="col" class="p-2" data-sort="switch_port_vlan_untagged">VLAN<br><span class="text-xs">(untagged)</span> <span class="sort-icon"></span></th>
                    <th scope="col" class="p-2" data-sort="switch_port_vlan_tagged">VLAN<br><span class="text-xs">(tagged)</span> <span class="sort-icon"></span></th>
                    <th scope="col" class="p-2" data-sort="endpoint_port_mac_address">MAC-Adresse <span class="sort-icon"></span></th>
                    <th scope="col" class="p-2" data-sort="patchpanel_caption">Patchpanel <span class="sort-icon"></span></th>
                    <th scope="col" class="p-2" data-sort="switch_caption">Switch <span class="sort-icon"></span></th>
                    <th scope="col" class="p-2" data-sort="switch_port_caption">Switchport <span class="sort-icon"></span></th>
                </tr>
            </thead>
            <tbody id="portviewTableBody"></tbody>
        </table>
    </div>

    <div class="mt-3 flex items-center justify-end gap-2 text-sm">
        <label for="table_limit_2" class="text-slate-600">Anzahl:</label>
        <select id="table_limit_2" name="limit" class="rounded-full border border-slate-300 px-3 py-1">
            <option value="50" <?php if ($limit === 50) echo 'selected'; ?>>50</option>
            <option value="100" <?php if ($limit === 100) echo 'selected'; ?>>100</option>
            <option value="500" <?php if ($limit === 500) echo 'selected'; ?>>500</option>
            <option value="1000" <?php if ($limit === 1000) echo 'selected'; ?>>1000</option>
        </select>
    </div>

    <div id="pagination_bottom" class="mt-3 flex flex-wrap items-center gap-2 text-sm"></div>
</div>

<script>
(function () {
    let currentSort = 'switch_caption';
    let currentOrder = 'ASC';
    let currentQuery = '';
    let currentLimit = parseInt(document.getElementById('table_limit_1').value, 10) || 100;
    let currentPage = 1;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function statusClass(statusRaw) {
        const value = String(statusRaw ?? '').trim().toLowerCase();
        if (value === '0' || value === 'active') return 'text-green-500';
        if (value === '2' || value === 'inactive' || value === 'deactivated') return 'text-amber-500';
        if (value === '4' || value === 'offline' || value === 'unpatched') return 'text-red-500';
        if (value === '6' || value === 'unused') return 'text-slate-500';
        return 'text-blue-500';
    }

    function speedLabel(speedRaw) {
        const value = String(speedRaw ?? '').trim();
        const map = {
            '100': '100 Mbit/s',
            '1000': '1 Gbit/s',
            '2500': '2.5 Gbit/s',
            '10000': '10 Gbit/s',
            '25000': '25 Gbit/s',
            '40000': '40 Gbit/s',
            '100000': '100 Gbit/s'
        };
        if (!value) return '--';
        return map[value] || (value + ' Mbit/s');
    }

    function renderPagination(totalPages, currentPageValue) {
        const top = $('#pagination');
        const bottom = $('#pagination_bottom');
        top.empty();
        bottom.empty();

        const groupSize = 10;
        const groupIndex = Math.floor((currentPageValue - 1) / groupSize);
        const start = groupIndex * groupSize + 1;
        const end = Math.min(start + groupSize - 1, totalPages);

        const makeButton = (label, enabled, targetPage) => {
            const btn = $('<button type="button" class="rounded-full border border-slate-300 px-3 py-1 transition"></button>');
            btn.text(label);
            if (!enabled) {
                btn.prop('disabled', true).addClass('cursor-not-allowed opacity-40');
            } else {
                btn.addClass('hover:bg-slate-100').on('click', function () {
                    loadTable(currentQuery, currentLimit, targetPage, currentSort, currentOrder);
                });
            }
            return btn;
        };

        const prevGroupPage = start - 1;
        const nextGroupPage = end + 1;

        top.append($('<span class="text-slate-600">Seite:</span>'));
        top.append(makeButton('←', start > 1, prevGroupPage));

        for (let i = start; i <= end; i += 1) {
            const pageButton = makeButton(String(i), true, i);
            if (i === currentPageValue) {
                pageButton.removeClass('hover:bg-slate-100').addClass('border-blue-500 bg-blue-500 text-white');
            }
            top.append(pageButton);
        }

        top.append(makeButton('→', end < totalPages, nextGroupPage));
        bottom.html(top.html());

        bottom.find('button').each(function (index) {
            const topButton = top.find('button').eq(index);
            if (!topButton.length) {
                return;
            }

            const targetText = topButton.text();
            const isDisabled = topButton.prop('disabled');

            if (isDisabled) {
                $(this).prop('disabled', true).addClass('cursor-not-allowed opacity-40');
                return;
            }

            if (targetText === '←') {
                $(this).on('click', function () {
                    loadTable(currentQuery, currentLimit, prevGroupPage, currentSort, currentOrder);
                });
            } else if (targetText === '→') {
                $(this).on('click', function () {
                    loadTable(currentQuery, currentLimit, nextGroupPage, currentSort, currentOrder);
                });
            } else {
                const target = parseInt(targetText, 10);
                $(this).on('click', function () {
                    loadTable(currentQuery, currentLimit, target, currentSort, currentOrder);
                });
            }
        });
    }

    function buildStatusSummary(row) {
        const statusValue = row.switch_port_status ?? '';
        const deviceType = row.endpoint_device_type ?? '--';
        const speed = speedLabel(row.connection_speed ?? '');
        const cls = statusClass(statusValue);

        return '<span class="' + cls + ' text-xl" title="Status: ' + escapeHtml(statusValue) + '\nGeraetetyp: ' + escapeHtml(deviceType) + '\nGeschwindigkeit: ' + escapeHtml(speed) + '">●</span> '
            + '<span class="font-medium text-slate-800">' + escapeHtml(deviceType) + '</span> '
            + '<span class="text-slate-500">' + escapeHtml(speed) + '</span>';
    }

    function loadTable(query = '', limit = 100, page = 1, sort = 'switch_caption', order = 'ASC') {
        currentQuery = query;
        currentLimit = limit;
        currentPage = page;
        currentSort = sort;
        currentOrder = order;

        const url = '?action=get'
            + '&search=' + encodeURIComponent(query)
            + '&limit=' + encodeURIComponent(limit)
            + '&page=' + encodeURIComponent(page)
            + '&sort=' + encodeURIComponent(sort)
            + '&order=' + encodeURIComponent(order);

        $.ajax({
            url: url,
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                const tbody = $('#portviewTableBody');
                tbody.empty();

                const results = Array.isArray(data.results) ? data.results : [];
                const totalCount = parseInt(data.totalCount, 10) || 0;
                const perPage = parseInt(data.limit, 10) || limit;
                const pageNumber = parseInt(data.currentPage, 10) || page;
                const totalPages = Math.max(1, Math.ceil(totalCount / perPage));

                const startItem = totalCount === 0 ? 0 : ((pageNumber - 1) * perPage + 1);
                const endItem = totalCount === 0 ? 0 : Math.min(pageNumber * perPage, totalCount);

                $('#count').text('Datensaetze: ' + totalCount);
                $('#resultSummary').text('Zeige ' + startItem + '-' + endItem + ' von ' + totalCount);

                if (results.length === 0) {
                    tbody.append('<tr><td colspan="10" class="p-4 text-center text-slate-500">Keine Ergebnisse gefunden.</td></tr>');
                } else {
                    results.forEach(function (row) {
                        const tr = $('<tr class="border-b border-slate-200 hover:bg-slate-50"></tr>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + buildStatusSummary(row) + '</td>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + escapeHtml(row.endpoint_room ?? '--') + '</td>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + escapeHtml(row.endpoint_port_caption ?? '--') + '</td>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + escapeHtml(row.endpoint_port_hostname ?? '--') + '</td>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + escapeHtml(row.switch_port_vlan_untagged ?? '--') + '</td>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + escapeHtml(row.switch_port_vlan_tagged ?? '--') + '</td>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + escapeHtml(row.endpoint_port_mac_address ?? '--') + '</td>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + escapeHtml(row.patchpanel_caption ?? '--') + '</td>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + escapeHtml(row.switch_caption ?? '--') + '</td>');
                        tr.append('<td class="p-2 whitespace-nowrap">' + escapeHtml(row.switch_port_caption ?? '--') + '</td>');
                        tbody.append(tr);
                    });
                }

                renderPagination(totalPages, pageNumber);

                $('.sort-icon').text('');
                const currentHeader = $('th[data-sort="' + currentSort + '"] .sort-icon');
                if (currentHeader.length) {
                    currentHeader.text(currentOrder === 'ASC' ? '↑' : '↓');
                }
            },
            error: function (jqXHR) {
                console.error('Error:', jqXHR.responseText);
            }
        });
    }

    $('th[data-sort]').on('click', function () {
        const sort = $(this).data('sort');
        if (currentSort === sort) {
            currentOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
        } else {
            currentSort = sort;
            currentOrder = 'ASC';
        }

        loadTable(currentQuery, currentLimit, currentPage, currentSort, currentOrder);
    });

    function setTableLimit(limit) {
        document.cookie = 'table_limit=' + limit + '; SameSite=Lax';
        $('#table_limit_1').val(String(limit));
        $('#table_limit_2').val(String(limit));
        loadTable(currentQuery, parseInt(limit, 10), 1, currentSort, currentOrder);
    }

    $('#table_limit_1').on('change', function () {
        setTableLimit(this.value);
    });

    $('#table_limit_2').on('change', function () {
        setTableLimit(this.value);
    });

    $('#searchForm').on('submit', function (event) {
        event.preventDefault();
        const query = ($(this).find('input[name="search"]').val() || '').toString().trim();
        loadTable(query, currentLimit, 1, currentSort, currentOrder);
    });

    loadTable('', currentLimit, 1, currentSort, currentOrder);
})();
</script>

<?php
include_once __DIR__ . '/includes/footer.php';
?>

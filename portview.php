<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

const APP_NAME = 'Portflow';

include_once __DIR__ . '/includes/core/session.php';
if (!in_array(__DIR__ . '/includes/core/session.php', get_included_files(), true)) {
    die('could not verify session');
}

include_once __DIR__ . '/includes/header.php';
$limit = isset($_COOKIE['table_limit']) ? (int)$_COOKIE['table_limit'] : 100;
if (!in_array($limit, [50, 100, 500, 1000], true)) {
    $limit = 100;
}

$portviewLanguage = is_array($language ?? null) ? (string) ($language[0] ?? '') : (string) ($language ?? '');
$portviewLocale = strtolower(substr($portviewLanguage, 0, 2));
if ($portviewLocale === '') {
    $portviewLocale = 'en';
}

$portviewDefaultColumnsFromNav = [];
$__pf_nav_file = __DIR__ . '/includes/lang/' . ($portviewLocale === 'de' ? 'de-DE.php' : 'en-EN.php');
$__pf_nav_prev_get = $_GET['nav'] ?? null;
$__pf_nav_had_get = array_key_exists('nav', $_GET);
$__pf_nav_prev_cfg = $nav ?? null;
$__pf_nav_had_cfg = isset($nav);

if (is_file($__pf_nav_file)) {
    $_GET['nav'] = '1';
    ob_start();
    include $__pf_nav_file;
    ob_end_clean();

    if (isset($nav['portview']['default']) && is_array($nav['portview']['default'])) {
        $portviewDefaultColumnsFromNav = array_values($nav['portview']['default']);
    }
}

if ($__pf_nav_had_get) {
    $_GET['nav'] = $__pf_nav_prev_get;
} else {
    unset($_GET['nav']);
}

if ($__pf_nav_had_cfg) {
    $nav = $__pf_nav_prev_cfg;
} else {
    unset($nav);
}
?>

<style>
    #portviewApp,
    #chainDetailDialog {
        color: var(--pf-text);
    }

    #portviewApp {
        background: var(--pf-surface-alt);
        border-color: var(--pf-border);
    }

    #portviewApp .bg-white,
    #chainDetailDialog .bg-white {
        background-color: var(--pf-surface-alt) !important;
    }

    #portviewApp .bg-slate-50,
    #portviewApp .bg-slate-100,
    #chainDetailDialog .bg-slate-50,
    #chainDetailDialog .bg-slate-100 {
        background-color: var(--pf-surface-soft) !important;
    }

    #portviewApp .border-slate-300,
    #portviewApp .border-slate-200,
    #portviewApp .border-slate-100,
    #chainDetailDialog .border-slate-300,
    #chainDetailDialog .border-slate-200,
    #chainDetailDialog .border-slate-100 {
        border-color: var(--pf-border) !important;
    }

    #portviewApp .text-slate-900,
    #portviewApp .text-slate-800,
    #portviewApp .text-slate-700,
    #portviewApp .text-gray-800,
    #portviewApp .text-gray-500,
    #chainDetailDialog .text-slate-900,
    #chainDetailDialog .text-slate-800,
    #chainDetailDialog .text-slate-700,
    #chainDetailDialog .text-gray-800,
    #chainDetailDialog .text-gray-500 {
        color: var(--pf-text) !important;
    }

    #portviewApp .text-slate-600,
    #portviewApp .text-slate-500,
    #portviewApp .text-slate-400,
    #chainDetailDialog .text-slate-600,
    #chainDetailDialog .text-slate-500,
    #chainDetailDialog .text-slate-400 {
        color: var(--pf-muted) !important;
    }

    #portviewApp .hover\:bg-slate-50:hover,
    #portviewApp .hover\:bg-slate-100:hover,
    #portviewApp .hover\:bg-white:hover,
    #chainDetailDialog .hover\:bg-slate-50:hover,
    #chainDetailDialog .hover\:bg-slate-100:hover,
    #chainDetailDialog .hover\:bg-white:hover {
        background-color: var(--pf-hover) !important;
    }

    #portviewApp input:not([type="checkbox"]),
    #portviewApp select,
    #chainDetailDialog input:not([type="checkbox"]),
    #chainDetailDialog select {
        background-color: var(--pf-surface-alt);
        color: var(--pf-text);
        border-color: var(--pf-border);
    }

    #portviewApp input::placeholder,
    #chainDetailDialog input::placeholder {
        color: var(--pf-muted);
    }

    #portviewApp .pv-table-scroll {
        scrollbar-gutter: stable both-edges;
    }

    #portviewApp .pv-table {
        min-width: 90rem;
    }

    #portviewApp .pv-table thead,
    #portviewApp .pv-table thead tr,
    #portviewApp .pv-table thead th {
        background: var(--pf-surface-soft) !important;
        color: var(--pf-text) !important;
        border-color: var(--pf-border) !important;
    }

    #portviewApp .pv-table thead button {
        color: var(--pf-text) !important;
    }

    #portviewApp .pv-table thead button:hover {
        background: var(--pf-hover) !important;
    }

    #portviewApp .pv-status-icon {
        line-height: 1;
    }

    #portviewApp .pv-status-icon svg,
    #portviewApp .pv-status-icon i {
        display: inline-block;
        vertical-align: middle;
    }

    #portviewApp .pv-status-label {
        color: var(--pf-text);
    }
</style>

<div id="portviewApp" class="mx-4 mb-4 mt-0 rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900"><?php echo $lang['portview_page_title']; ?></h1>
            <p class="text-sm text-slate-500"><?php echo $lang['portview_page_subtitle']; ?></p>
        </div>
        <form id="searchForm" class="flex min-w-[18rem] flex-1 items-center justify-end gap-2">
            <input
                type="text"
                name="search"
                placeholder="<?php echo $lang['search']; ?> ..."
                class="w-full max-w-md rounded-full border border-slate-300 px-4 py-2.5 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-400"
            >
            <button
                type="submit"
                class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-blue-500 text-white shadow-md transition hover:bg-blue-700"
                title="<?php echo $lang['search']; ?>"
            >
                <i data-lucide="search"></i>
            </button>
        </form>
        <button id="customizeColumnsButton" type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100" title="<?php echo $lang['columns_customize']; ?>">
            <i data-lucide="columns-3" class="h-4 w-4"></i>
            <span><?php echo $lang['columns_customize']; ?></span>
        </button>
        <button id="exportCsvButton" type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100" title="<?php echo $lang['transfer_export_csv']; ?>">
            <i data-lucide="file-down" class="h-4 w-4"></i>
            <span><?php echo $lang['transfer_export_csv']; ?></span>
        </button>
    </div>

    <div class="mb-3 grid items-center gap-3 md:grid-cols-[1fr_auto_1fr]">
        <div class="inline-flex flex-wrap items-center gap-4">
            <p id="count" class="text-sm font-semibold text-slate-700"><?php echo $lang['datasets']; ?>: 0</p>
        </div>
        <div id="pagination" class="flex flex-row justify-center"></div>
        <div class="inline-flex items-center justify-end gap-2 text-sm">
            <label for="table_limit_1" class="text-slate-600"><?php echo $lang['quantity']; ?>:</label>
            <select id="table_limit_1" name="limit" class="rounded-full border border-slate-300 bg-white px-3 py-1.5">
                <option value="50" <?php if ($limit === 50) echo 'selected'; ?>>50</option>
                <option value="100" <?php if ($limit === 100) echo 'selected'; ?>>100</option>
                <option value="500" <?php if ($limit === 500) echo 'selected'; ?>>500</option>
                <option value="1000" <?php if ($limit === 1000) echo 'selected'; ?>>1000</option>
            </select>
        </div>
    </div>

    <div class="mb-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm font-semibold text-slate-900"><?php echo $lang['filters']; ?></div>
                <div class="text-xs text-slate-500"><?php echo $lang['portview_filters_hint']; ?></div>
            </div>
            <button id="resetFiltersButton" type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100">
                <i data-lucide="rotate-ccw" class="h-4 w-4"></i>
                <span><?php echo $lang['portview_reset_filters']; ?></span>
            </button>
        </div>
        <div class="grid gap-3 md:grid-cols-3">
            <label class="flex flex-col gap-1 text-sm text-slate-600">
                <span><?php echo $lang['portview_filter_room']; ?></span>
                <select id="filterRoom" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value=""><?php echo $lang['portview_all_rooms']; ?></option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm text-slate-600">
                <span><?php echo $lang['portview_filter_endpoint_type']; ?></span>
                <select id="filterEndpointType" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value=""><?php echo $lang['portview_all_endpoint_types']; ?></option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm text-slate-600">
                <span><?php echo $lang['portview_filter_vlan']; ?></span>
                <select id="filterVlan" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value=""><?php echo $lang['portview_all_vlans']; ?></option>
                </select>
            </label>
        </div>
    </div>

    <div class="flex min-h-0 flex-1 overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm">
        <div class="pv-table-scroll h-full w-full">
            <table class="pv-table static w-full table-auto rounded-lg text-left text-sm text-gray-500 shadow-md">
                <thead id="portviewTableHead" class="bg-white text-gray-800 top-0 sticky z-1"></thead>
                <tbody id="portviewTableBody"></tbody>
            </table>
        </div>
    </div>

    <div id="chainDetailDialog" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-950/60 p-4">
        <div class="mx-auto mt-8 flex max-h-[85vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900"><?php echo $lang['portview_detail_title']; ?></h2>
                    <p class="text-sm text-slate-500"><?php echo $lang['portview_detail_subtitle']; ?></p>
                </div>
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-red-500 text-white shadow-sm hover:bg-red-700" title="<?php echo $lang['button_close']; ?>" onclick="window.portViewApp.closeChainDetail()"><i data-lucide="x" class="h-4 w-4"></i></button>
            </div>
            <div id="chainDetailBody" class="min-h-0 flex-1 space-y-3 overflow-y-auto p-5"></div>
        </div>
    </div>

    <div class="mt-3 grid items-center gap-3 md:grid-cols-[1fr_auto_1fr]">
        <div class="inline-flex flex-wrap items-center gap-4">
            <p id="count_bottom" class="text-sm font-semibold text-slate-700"><?php echo $lang['datasets']; ?>: 0</p>
        </div>
        <div id="pagination_bottom" class="flex flex-row justify-center"></div>
        <div class="inline-flex items-center justify-end gap-2 text-sm">
            <label for="table_limit_2" class="text-slate-600"><?php echo $lang['quantity']; ?>:</label>
            <select id="table_limit_2" name="limit" class="rounded-full border border-slate-300 bg-white px-3 py-1.5">
                <option value="50" <?php if ($limit === 50) echo 'selected'; ?>>50</option>
                <option value="100" <?php if ($limit === 100) echo 'selected'; ?>>100</option>
                <option value="500" <?php if ($limit === 500) echo 'selected'; ?>>500</option>
                <option value="1000" <?php if ($limit === 1000) echo 'selected'; ?>>1000</option>
            </select>
        </div>
    </div>
</div>

<script>
(function () {
    const PORTVIEW_I18N = <?php echo json_encode($lang, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const PORTVIEW_LOCALE = <?php echo json_encode($portviewLocale, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const PORTVIEW_USER_SETTINGS = <?php
        $__pf_portview_raw = $_SESSION['settings'] ?? null;
        $__pf_portview_settings = is_array($__pf_portview_raw)
            ? $__pf_portview_raw
            : (is_string($__pf_portview_raw) && $__pf_portview_raw !== '' ? (json_decode($__pf_portview_raw, true) ?: []) : []);
        echo json_encode($__pf_portview_settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    const PORTVIEW_SEARCH_PLACEHOLDER = `${PORTVIEW_I18N.search} ...`;
    const PORTVIEW_PICKER_KIND_LABELS = {
        locations: PORTVIEW_I18N.portview_picker_kind_location,
        devices: PORTVIEW_I18N.portview_picker_kind_device,
        ports: PORTVIEW_I18N.portview_picker_kind_port
    };
    const PORTVIEW_TABLE_KEY = 'portview';

    let currentSort = '';
    let currentOrder = 'asc';
    let currentQuery = '';
    let currentLimit = parseInt(document.getElementById('table_limit_1').value, 10) || 100;
    let currentPage = 1;
    let allChainsCache = [];
    let visibleChainsCache = [];
    let searchIndexCache = {
        locations: [],
        devices: [],
        ports: []
    };
    let fullPortSearchEntriesCache = null;
    let fullPortSearchEntriesPromise = null;
    let currentDetailChain = [];
    let currentDetailConnectionUuid = '';
    let editingConnectionUuid = '';
    let detailNotice = '';
    let filterState = {
        room: '',
        endpointType: '',
        vlan: ''
    };

    const PORTVIEW_COLUMN_DEFINITIONS = [
        { key: 'statusValue', label: PORTVIEW_I18N.portview_column_status },
        { key: 'switchCaption', label: PORTVIEW_I18N.portview_column_switch },
        { key: 'switchPortCaption', label: PORTVIEW_I18N.portview_column_switch_port },
        { key: 'patchpanelCaption', label: PORTVIEW_I18N.portview_column_patchpanel },
        { key: 'patchpanelPortCaption', label: PORTVIEW_I18N.portview_column_patchpanel_port },
        { key: 'cableName', label: PORTVIEW_I18N.portview_column_cable },
        { key: 'roomCaption', label: PORTVIEW_I18N.portview_column_room },
        { key: 'wallplateCaption', label: PORTVIEW_I18N.portview_column_wallplate },
        { key: 'wallplatePortCaption', label: PORTVIEW_I18N.portview_column_wallplate_port },
        { key: 'endpointCaption', label: PORTVIEW_I18N.portview_column_endpoint },
        { key: 'endpointPortCaption', label: PORTVIEW_I18N.portview_column_endpoint_port },
        { key: 'endpointIp', label: PORTVIEW_I18N.portview_column_ip },
        { key: 'endpointHostname', label: PORTVIEW_I18N.portview_column_hostname },
        { key: 'endpointMac', label: PORTVIEW_I18N.portview_column_mac },
        { key: 'vlanTaggedText', label: PORTVIEW_I18N.portview_column_vlan_tagged },
        { key: 'vlanUntaggedText', label: PORTVIEW_I18N.portview_column_vlan_untagged }
    ];
    const PORTVIEW_COLUMN_MAP = new Map(PORTVIEW_COLUMN_DEFINITIONS.map((column) => [column.key, column]));
    const PORTVIEW_ALL_COLUMNS = PORTVIEW_COLUMN_DEFINITIONS.map((column) => column.key);
    const PORTVIEW_FALLBACK_DEFAULT_COLUMNS = [
        'statusValue',
        'switchCaption',
        'switchPortCaption',
        'patchpanelCaption',
        'patchpanelPortCaption',
        'roomCaption',
        'wallplateCaption',
        'wallplatePortCaption',
        'endpointCaption',
        'endpointPortCaption',
        'endpointIp',
        'endpointHostname',
        'endpointMac',
        'vlanTaggedText',
        'vlanUntaggedText'
    ];
    const PORTVIEW_SERVER_DEFAULT_COLUMNS = <?php echo json_encode($portviewDefaultColumnsFromNav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const PORTVIEW_DEFAULT_COLUMNS = (() => {
        const source = Array.isArray(PORTVIEW_SERVER_DEFAULT_COLUMNS) && PORTVIEW_SERVER_DEFAULT_COLUMNS.length > 0
            ? PORTVIEW_SERVER_DEFAULT_COLUMNS
            : PORTVIEW_FALLBACK_DEFAULT_COLUMNS;
        const filtered = source.filter((column) => PORTVIEW_ALL_COLUMNS.includes(String(column || '')));
        return filtered.length > 0 ? filtered : PORTVIEW_FALLBACK_DEFAULT_COLUMNS.slice();
    })();
    let visibleColumnKeys = loadStoredPortviewColumns(PORTVIEW_DEFAULT_COLUMNS);

    function formatMessage(template, replacements = {}) {
        return String(template || '').replace(/\{(\w+)\}/g, (match, key) => {
            return Object.prototype.hasOwnProperty.call(replacements, key) ? String(replacements[key]) : match;
        });
    }

    function sanitizePortviewColumns(columns, fallback = PORTVIEW_DEFAULT_COLUMNS) {
        const allowed = new Set(PORTVIEW_ALL_COLUMNS);
        const sanitized = Array.isArray(columns)
            ? columns.filter((column) => allowed.has(String(column || '')))
            : [];
        return sanitized.length > 0 ? sanitized : fallback.slice();
    }

    function getVisiblePortviewColumns() {
        return sanitizePortviewColumns(visibleColumnKeys).map((key) => PORTVIEW_COLUMN_MAP.get(key)).filter(Boolean);
    }

    function loadStoredPortviewColumns(defaultColumns) {
        const live = window.__pfTableUserColumns && window.__pfTableUserColumns[PORTVIEW_TABLE_KEY];
        if (Array.isArray(live) && live.length > 0) {
            return sanitizePortviewColumns(live, defaultColumns);
        }

        if (window.__pfTableUserColumnsReset && window.__pfTableUserColumnsReset[PORTVIEW_TABLE_KEY]) {
            return defaultColumns.slice();
        }

        const stored = PORTVIEW_USER_SETTINGS && PORTVIEW_USER_SETTINGS.tables && PORTVIEW_USER_SETTINGS.tables[PORTVIEW_TABLE_KEY];
        if (!Array.isArray(stored) || stored.length === 0) {
            return defaultColumns.slice();
        }
        return sanitizePortviewColumns(stored, defaultColumns);
    }

    function summaryText(totalCount) {
        const start = totalCount === 0 ? 0 : ((currentPage - 1) * currentLimit) + 1;
        const end = totalCount === 0 ? 0 : Math.min(currentPage * currentLimit, totalCount);
        return formatMessage(PORTVIEW_I18N.portview_result_summary, { start, end, total: totalCount });
    }

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

    function deviceIcon(deviceType) {
        const type = String(deviceType ?? '').trim().toLowerCase();
        const matches = (...needles) => needles.some((needle) => type.includes(needle));
        let icon = 'help-circle';

        if (!type) {
            return `<i data-lucide="${icon}" class="h-4 w-4"></i>`;
        }

        if (matches('patchpanel')) {
            icon = 'rectangle-ellipsis';
        } else if (matches('net_outlet', 'outlet', 'coupler')) {
            icon = 'ethernet-port';
        } else if (matches('phone', 'telefon')) {
            icon = 'phone';
        } else if (matches('notebook', 'laptop')) {
            icon = 'laptop';
        } else if (matches('thinclient')) {
            icon = 'monitor-smartphone';
        } else if (matches('desktop', 'computer', 'pc')) {
            icon = 'pc-case';
        } else if (matches('accesspoint', 'access point', 'wifi')) {
            icon = 'wifi';
        } else if (matches('printer', 'drucker')) {
            icon = 'printer';
        } else if (matches('switch')) {
            icon = 'network';
        } else if (matches('server')) {
            icon = 'server';
        } else if (matches('router')) {
            icon = 'router';
        } else if (matches('firewall')) {
            icon = 'brick-wall-fire';
        } else if (matches('loadbalancer', 'load balancer')) {
            icon = 'loader-circle';
        } else if (matches('storage', 'nas', 'san')) {
            icon = 'hard-drive';
        } else if (matches('sensor')) {
            icon = 'thermometer';
        } else if (matches('ups')) {
            icon = 'battery-full';
        } else if (matches('monitor', 'tv', 'display')) {
            icon = 'monitor';
        }

        return `<i data-lucide="${icon}" class="h-4 w-4"></i>`;
    }

    function speedLabel(speedRaw) {
        const value = String(speedRaw ?? '').trim();
        const numeric = parseMaybeNumber(value);
        const normalized = numeric === null
            ? value
            : (Number.isInteger(numeric) ? String(numeric) : String(numeric));
        const map = {
            '100': '100 Mbit/s',
            '1000': '1 Gbit/s',
            '2500': '2.5 Gbit/s',
            '10000': '10 Gbit/s',
            '25000': '25 Gbit/s',
            '40000': '40 Gbit/s',
            '100000': '100 Gbit/s'
        };
        if (!normalized) return '--';
        return map[normalized] || (normalized + ' Mbit/s');
    }

    function parseMaybeNumber(value) {
        const text = String(value ?? '').trim();
        if (text === '') {
            return null;
        }
        const numeric = Number(text);
        return Number.isFinite(numeric) ? numeric : null;
    }

    function getLocationCaption(conn, side) {
        return side === 'src'
            ? (conn.src_location_caption || '--')
            : (conn.dst_location_caption || '--');
    }

    function getLocationMetadataUuid(conn, side) {
        return side === 'src'
            ? String(conn.src_location_metadata_uuid || '')
            : String(conn.dst_location_metadata_uuid || '');
    }

    function getPortMetadataUuid(conn, side) {
        return side === 'src'
            ? String(conn.src_port_metadata_uuid || '')
            : String(conn.dst_port_metadata_uuid || '');
    }

    function getPortIpUuid(conn, side) {
        return side === 'src'
            ? String(conn.src_port_ip_uuid || '')
            : String(conn.dst_port_ip_uuid || '');
    }

    function apiUpdateResource(resource, uuid, payload) {
        return fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?' + new URLSearchParams({ table: resource, uuid: uuid }).toString(), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        }).then(async (response) => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data?.error || data?.details || PORTVIEW_I18N.portview_update_failed);
            }
            return data;
        });
    }

    function apiCreateResource(resource, payload) {
        return fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?' + new URLSearchParams({ table: resource }).toString(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        }).then(async (response) => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data?.error || data?.details || PORTVIEW_I18N.portview_create_failed);
            }
            return data;
        });
    }

    function apiGetItems(resource, options = {}) {
        const params = new URLSearchParams({
            table: resource,
            limit: String(options.limit || 5000),
            page: String(options.page || 1)
        });

        if (options.search) {
            params.set('search', String(options.search));
        }

        return fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?' + params.toString())
            .then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data?.error || data?.details || (PORTVIEW_I18N.portview_fetch_failed + ': ' + resource));
                }
                return Array.isArray(data.items) ? data.items : [];
            });
    }

    function extractUuidFromApiPayload(payload) {
        if (Array.isArray(payload) && payload[0] && payload[0].uuid) {
            return String(payload[0].uuid);
        }
        if (payload && payload.uuid) {
            return String(payload.uuid);
        }
        if (payload && Array.isArray(payload.items) && payload.items[0] && payload.items[0].uuid) {
            return String(payload.items[0].uuid);
        }
        return '';
    }

    function isCorePatchpanelOutletConnection(conn) {
        const srcType = String(conn?.src_device_type || '').toLowerCase();
        const dstType = String(conn?.dst_device_type || '').toLowerCase();
        return (srcType === 'patchpanel' && dstType === 'net_outlet') || (srcType === 'net_outlet' && dstType === 'patchpanel');
    }

    function resolvePortUuidFromPickerInput(inputElement) {
        if (!inputElement) {
            return '';
        }

        const selectedUuid = String(inputElement.dataset.selectedUuid || '').trim();
        if (selectedUuid) {
            return selectedUuid;
        }

        const caption = String(inputElement.value || '').trim().toLowerCase();
        if (!caption) {
            return '';
        }

        const matches = (searchIndexCache.ports || []).filter((item) => String(item.caption || '').trim().toLowerCase() === caption);
        if (matches.length === 1) {
            return String(matches[0].uuid || '');
        }
        return '';
    }

    async function ensureFullPortSearchEntries() {
        if (Array.isArray(fullPortSearchEntriesCache)) {
            return fullPortSearchEntriesCache;
        }

        if (fullPortSearchEntriesPromise) {
            return fullPortSearchEntriesPromise;
        }

        fullPortSearchEntriesPromise = Promise.allSettled([
            apiGetItems('metadata', { limit: 20000 }),
            apiGetItems('device', { limit: 20000 }),
            apiGetItems('location', { limit: 20000 }),
            apiGetItems('device_port', { limit: 50000 }),
            apiGetItems('device_port_ip', { limit: 50000 })
        ]).then((results) => {
            const metadataItems = results[0]?.status === 'fulfilled' ? results[0].value : [];
            const deviceItems = results[1]?.status === 'fulfilled' ? results[1].value : [];
            const locationItems = results[2]?.status === 'fulfilled' ? results[2].value : [];
            const devicePortItems = results[3]?.status === 'fulfilled' ? results[3].value : [];
            const devicePortIpItems = results[4]?.status === 'fulfilled' ? results[4].value : [];

            const metadataByUuid = new Map((metadataItems || []).map((item) => [String(item.uuid || ''), item]));
            const portIpByUuid = new Map((devicePortIpItems || []).map((item) => [String(item.uuid || ''), item]));

            const locationCaptionByUuid = new Map();
            (locationItems || []).forEach((location) => {
                const locationUuid = String(location.uuid || '');
                const locationMetadata = metadataByUuid.get(String(location.metadata || '')) || null;
                const locationCaption = String(locationMetadata?.caption || '').trim();
                if (locationUuid && locationCaption) {
                    locationCaptionByUuid.set(locationUuid, locationCaption);
                }
            });

            const deviceMeta = new Map();
            (deviceItems || []).forEach((device) => {
                const deviceUuid = String(device.uuid || '');
                if (!deviceUuid) {
                    return;
                }

                const deviceMetadata = metadataByUuid.get(String(device.metadata || '')) || null;
                const caption = String(deviceMetadata?.caption || '').trim();
                const locationCaption = locationCaptionByUuid.get(String(device.location || '')) || '';
                const type = String(device.type || '').trim();
                deviceMeta.set(deviceUuid, { caption, locationCaption, type });
            });

            const entries = [];
            (devicePortItems || []).forEach((port) => {
                const uuid = String(port.uuid || '').trim();
                const portMetadata = metadataByUuid.get(String(port.metadata || '')) || null;
                const caption = String(portMetadata?.caption || '').trim();
                if (!uuid || !caption) {
                    return;
                }

                const deviceInfo = deviceMeta.get(String(port.device || '')) || {};
                const hostname = String((portIpByUuid.get(String(port.device_port_ip || '')) || {}).hostname || '').trim();
                const mac = String(port.mac_address || '').trim();
                const metaParts = [deviceInfo.caption, deviceInfo.type, deviceInfo.locationCaption, hostname, mac].filter(Boolean);

                entries.push({
                    uuid,
                    caption,
                    kind: PORTVIEW_PICKER_KIND_LABELS.ports,
                    meta: metaParts.join(' · ')
                });
            });

            fullPortSearchEntriesCache = entries;
            return entries;
        }).catch((error) => {
            console.error(PORTVIEW_I18N.portview_full_port_search_failed + ':', error);
            fullPortSearchEntriesCache = [];
            return [];
        }).finally(() => {
            fullPortSearchEntriesPromise = null;
        });

        return fullPortSearchEntriesPromise;
    }

    function mergePortSearchEntries(baseEntries, extraEntries) {
        const merged = new Map();
        (Array.isArray(baseEntries) ? baseEntries : []).forEach((entry) => {
            const uuid = String(entry?.uuid || '').trim();
            const caption = String(entry?.caption || '').trim();
            if (!uuid || !caption || merged.has(uuid)) {
                return;
            }
            merged.set(uuid, entry);
        });
        (Array.isArray(extraEntries) ? extraEntries : []).forEach((entry) => {
            const uuid = String(entry?.uuid || '').trim();
            const caption = String(entry?.caption || '').trim();
            if (!uuid || !caption || merged.has(uuid)) {
                return;
            }
            merged.set(uuid, entry);
        });
        return Array.from(merged.values());
    }

    function isOutletInfrastructureType(deviceType) {
        return ['net_outlet', 'outlet', 'coupler'].includes(String(deviceType || '').toLowerCase());
    }

    function isInfrastructureType(deviceType) {
        return ['switch', 'patchpanel', 'net_outlet', 'outlet', 'coupler'].includes(String(deviceType || '').toLowerCase());
    }

    function summarizeChain(chain) {
        let switchConn = null;
        let coreConn = null;
        let endpointConn = null;
        let directSwitchConn = null;

        for (const conn of chain) {
            const srcType = String(conn.src_device_type || '').toLowerCase();
            const dstType = String(conn.dst_device_type || '').toLowerCase();

            if ((srcType === 'switch' && dstType === 'patchpanel') || (srcType === 'patchpanel' && dstType === 'switch')) {
                switchConn = conn;
            } else if ((srcType === 'patchpanel' && isOutletInfrastructureType(dstType)) || (isOutletInfrastructureType(srcType) && dstType === 'patchpanel')) {
                coreConn = conn;
            } else if ((srcType === 'switch' && isOutletInfrastructureType(dstType)) || (isOutletInfrastructureType(srcType) && dstType === 'switch')) {
                directSwitchConn = conn;
            } else if (!isInfrastructureType(srcType) || !isInfrastructureType(dstType)) {
                endpointConn = conn;
            }
        }

        if (!switchConn && directSwitchConn) {
            switchConn = directSwitchConn;
        }

        const displayEndpointConn = endpointConn || (switchConn && directSwitchConn && switchConn.connection_uuid !== directSwitchConn.connection_uuid ? directSwitchConn : null);
        const endpointSideIsSrc = displayEndpointConn && !isOutletInfrastructureType(displayEndpointConn.src_device_type || '');
        const roomCaption = coreConn ? (String(coreConn.src_device_type || '').toLowerCase() === 'patchpanel' ? coreConn.dst_location_caption : coreConn.src_location_caption) : '--';
        const endpointType = displayEndpointConn ? (endpointSideIsSrc ? displayEndpointConn.src_device_type : displayEndpointConn.dst_device_type) : '';
        const vlanTagged = switchConn ? (String(switchConn.src_device_type || '').toLowerCase() === 'switch' ? switchConn.src_vlan_tagged : switchConn.dst_vlan_tagged) : '';
        const vlanUntagged = switchConn ? (String(switchConn.src_device_type || '').toLowerCase() === 'switch' ? switchConn.src_vlan_untagged : switchConn.dst_vlan_untagged) : '';

        return {
            switchConn,
            coreConn,
            endpointConn: displayEndpointConn,
            roomCaption,
            endpointType,
            vlanTagged: vlanTagged ? String(vlanTagged) : '',
            vlanUntagged: vlanUntagged ? String(vlanUntagged) : ''
        };
    }

    function getChainTableData(chain) {
        const summary = summarizeChain(chain);
        const switchConn = summary.switchConn;
        const coreConn = summary.coreConn;
        const endpointConn = summary.endpointConn;

        const switchSideIsSrc = switchConn && String(switchConn.src_device_type || '').toLowerCase() === 'switch';
        const coreSideIsSrc = coreConn && String(coreConn.src_device_type || '').toLowerCase() === 'patchpanel';
        const endpointSideIsSrc = endpointConn && !isInfrastructureType(endpointConn.src_device_type || '');

        const switchCaption = switchConn ? (switchSideIsSrc ? switchConn.src_device_caption : switchConn.dst_device_caption) : '--';
        const switchPortCaption = switchConn ? (switchSideIsSrc ? switchConn.src_port_caption : switchConn.dst_port_caption) : '--';
        const statusValue = switchConn ? (switchSideIsSrc ? switchConn.src_port_status : switchConn.dst_port_status) : '';
        const switchVlanTagged = switchConn ? (switchSideIsSrc ? switchConn.src_vlan_tagged : switchConn.dst_vlan_tagged) : '';
        const switchVlanUntagged = switchConn ? (switchSideIsSrc ? switchConn.src_vlan_untagged : switchConn.dst_vlan_untagged) : '';
        const patchpanelCaption = coreConn ? (coreSideIsSrc ? coreConn.src_device_caption : coreConn.dst_device_caption) : '--';
        const patchpanelPortCaption = coreConn ? (coreSideIsSrc ? coreConn.src_port_caption : coreConn.dst_port_caption) : '--';
        const wallplateCaption = coreConn ? (coreSideIsSrc ? coreConn.dst_device_caption : coreConn.src_device_caption) : '--';
        const wallplatePortCaption = coreConn ? (coreSideIsSrc ? coreConn.dst_port_caption : coreConn.src_port_caption) : '--';
        const endpointCaption = endpointConn ? (endpointSideIsSrc ? endpointConn.src_device_caption : endpointConn.dst_device_caption) : '--';
        const endpointType = endpointConn ? (endpointSideIsSrc ? endpointConn.src_device_type : endpointConn.dst_device_type) : '';
        const endpointPortCaption = endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_caption : endpointConn.dst_port_caption) : '--';
        const endpointIp = endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_ip : endpointConn.dst_port_ip) : '--';
        const endpointHostname = endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_hostname : endpointConn.dst_port_hostname) : '--';
        const endpointMac = endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_mac : endpointConn.dst_port_mac) : '--';
        const cableName = (switchConn && switchConn.cable_name) || (coreConn && coreConn.cable_name) || (endpointConn && endpointConn.cable_name) || '--';
        const switchPortSpeed = switchConn ? (switchSideIsSrc ? switchConn.src_port_speed : switchConn.dst_port_speed) : '';
        const connectionSpeed = (switchConn && switchConn.connection_speed) || (coreConn && coreConn.connection_speed) || (endpointConn && endpointConn.connection_speed) || '';
        const displaySpeed = switchPortSpeed || connectionSpeed;

        return {
            summary,
            statusValue: statusValue === null || statusValue === undefined ? '' : String(statusValue),
            speedLabelText: speedLabel(displaySpeed),
            switchCaption: String(switchCaption || '--'),
            switchPortCaption: String(switchPortCaption || '--'),
            patchpanelCaption: String(patchpanelCaption || '--'),
            patchpanelPortCaption: String(patchpanelPortCaption || '--'),
            cableName: String(cableName || '--'),
            roomCaption: String(summary.roomCaption || '--'),
            wallplateCaption: String(wallplateCaption || '--'),
            wallplatePortCaption: String(wallplatePortCaption || '--'),
            endpointCaption: String(endpointCaption || '--'),
            endpointType: String(endpointType || ''),
            endpointPortCaption: String(endpointPortCaption || '--'),
            endpointIp: String(endpointIp || '--'),
            endpointHostname: String(endpointHostname || '--'),
            endpointMac: String(endpointMac || '--'),
            vlanTaggedText: switchVlanTagged ? String(switchVlanTagged) : '--',
            vlanUntaggedText: switchVlanUntagged ? String(switchVlanUntagged) : '--'
        };
    }

    function compareSortValues(left, right) {
        const leftValue = String(left ?? '').trim();
        const rightValue = String(right ?? '').trim();

        if (leftValue === rightValue) {
            return 0;
        }

        const numberPattern = /^-?\d+(?:[.,]\d+)?$/;
        if (numberPattern.test(leftValue) && numberPattern.test(rightValue)) {
            return Number(leftValue.replace(',', '.')) - Number(rightValue.replace(',', '.'));
        }

        if (leftValue === '' || leftValue === '--') {
            return 1;
        }
        if (rightValue === '' || rightValue === '--') {
            return -1;
        }

        return leftValue.localeCompare(rightValue, PORTVIEW_LOCALE, { numeric: true, sensitivity: 'base' });
    }

    function sortChains(chains) {
        if (!currentSort) {
            return Array.isArray(chains) ? chains.slice() : [];
        }

        const direction = currentOrder === 'desc' ? -1 : 1;
        return (Array.isArray(chains) ? chains.slice() : []).sort((leftChain, rightChain) => {
            const leftData = getChainTableData(leftChain);
            const rightData = getChainTableData(rightChain);
            const result = compareSortValues(leftData[currentSort], rightData[currentSort]);
            if (result !== 0) {
                return result * direction;
            }
            return compareSortValues(leftData.switchCaption, rightData.switchCaption);
        });
    }

    function renderTableHeader() {
        const head = document.getElementById('portviewTableHead');
        if (!head) {
            return;
        }

        const cells = getVisiblePortviewColumns().map((column) => {
            const isActive = currentSort === column.key;
            const icon = isActive
                ? (currentOrder === 'asc' ? 'arrow-up' : 'arrow-down')
                : 'chevrons-up-down';
            const iconClass = isActive ? 'text-slate-700' : 'text-slate-400';

            return `
                <th scope="col" class="border-b border-slate-200 bg-white p-0">
                    <button type="button" class="flex w-full items-center gap-2 px-3 py-3 text-left text-sm font-semibold text-slate-800 transition hover:bg-slate-100" data-sort-key="${escapeHtml(column.key)}">
                        <span>${escapeHtml(column.label)}</span>
                        <i data-lucide="${icon}" class="h-4 w-4 ${iconClass}"></i>
                    </button>
                </th>
            `;
        }).join('');

        head.innerHTML = `<tr class="border-b bg-gray-200">${cells}</tr>`;

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function toggleColumnSort(sortKey) {
        if (!sortKey) {
            return;
        }

        if (currentSort !== sortKey) {
            currentSort = sortKey;
            currentOrder = 'asc';
        } else if (currentOrder === 'asc') {
            currentOrder = 'desc';
        } else {
            currentSort = '';
            currentOrder = 'asc';
        }

        currentPage = 1;
        loadTable();
    }

    function chainMatchesFilters(chain) {
        const summary = summarizeChain(chain);

        if (filterState.room && String(summary.roomCaption || '').toLowerCase() !== String(filterState.room).toLowerCase()) {
            return false;
        }

        if (filterState.endpointType && String(summary.endpointType || '').toLowerCase() !== String(filterState.endpointType).toLowerCase()) {
            return false;
        }

        if (filterState.vlan) {
            const vlan = String(filterState.vlan).toLowerCase();
            const tagged = String(summary.vlanTagged || '').toLowerCase();
            const untagged = String(summary.vlanUntagged || '').toLowerCase();
            if (!tagged.includes(vlan) && !untagged.includes(vlan)) {
                return false;
            }
        }

        return true;
    }

    function buildRowForChain(chain, index) {
        const row = getChainTableData(chain);
        const iconClass = deviceIcon(row.endpointType);
        const statusCell = `<span class="inline-flex items-center gap-2 ${statusClass(row.statusValue)}"><span class="pv-status-icon inline-flex items-center justify-center">${iconClass}</span><span class="pv-status-label">${escapeHtml(row.speedLabelText)}</span></span>`;
        const cellValues = {
            statusValue: statusCell,
            switchCaption: escapeHtml(row.switchCaption),
            switchPortCaption: escapeHtml(row.switchPortCaption),
            patchpanelCaption: escapeHtml(row.patchpanelCaption),
            patchpanelPortCaption: escapeHtml(row.patchpanelPortCaption),
            cableName: escapeHtml(row.cableName),
            roomCaption: escapeHtml(row.roomCaption),
            wallplateCaption: escapeHtml(row.wallplateCaption),
            wallplatePortCaption: escapeHtml(row.wallplatePortCaption),
            endpointCaption: escapeHtml(row.endpointCaption),
            endpointPortCaption: escapeHtml(row.endpointPortCaption),
            endpointIp: escapeHtml(row.endpointIp),
            endpointHostname: escapeHtml(row.endpointHostname),
            endpointMac: escapeHtml(row.endpointMac),
            vlanTaggedText: escapeHtml(row.vlanTaggedText),
            vlanUntaggedText: escapeHtml(row.vlanUntaggedText)
        };
        const cellClasses = {
            statusValue: 'px-3 py-2.5 font-medium align-top whitespace-nowrap',
            vlanTaggedText: 'px-3 py-2.5 text-xs align-top whitespace-nowrap',
            vlanUntaggedText: 'px-3 py-2.5 text-xs align-top whitespace-nowrap'
        };
        const cells = getVisiblePortviewColumns().map((column) => {
            const className = cellClasses[column.key] || 'px-3 py-2.5 align-top whitespace-nowrap';
            const content = Object.prototype.hasOwnProperty.call(cellValues, column.key) ? cellValues[column.key] : '--';
            return `<td class="${className}">${content}</td>`;
        }).join('');

        return `
            <tr class="cursor-pointer border-b border-slate-200 transition hover:bg-slate-50" data-chain-index="${index}" onclick="window.portViewApp.openChainDetail(${index})">
                ${cells}
            </tr>
        `;
    }

    function renderChainDetail(chain) {
        if (!chain || chain.length === 0) {
            return '<p class="text-sm text-slate-500">' + escapeHtml(PORTVIEW_I18N.portview_no_details) + '</p>';
        }

        const notice = detailNotice ? `
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                ${escapeHtml(detailNotice)}
            </div>
        ` : '';

        const tracePanel = currentDetailConnectionUuid ? `
            <div class="mb-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">${escapeHtml(PORTVIEW_I18N.portview_trace_title)}</div>
                        <div class="text-xs text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_trace_subtitle)}</div>
                    </div>
                    <button type="button" class="inline-flex h-9 items-center gap-2 rounded-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-100" data-action="reload-trace" title="${escapeHtml(PORTVIEW_I18N.portview_reload_trace)}">
                        <i data-lucide="route"></i><span>${escapeHtml(PORTVIEW_I18N.portview_reload_trace)}</span>
                    </button>
                </div>
                <div id="chainDetailTrace" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_trace_loading)}</div>
            </div>
        ` : '';

        const addPanel = `
            <div class="mb-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="mb-2 text-sm font-semibold text-slate-900">${escapeHtml(PORTVIEW_I18N.portview_connection_add_title)}</div>
                <form class="grid gap-3 text-sm md:grid-cols-2" data-connection-add-form="1">
                    ${createPickerMarkup('add_src_port_caption', PORTVIEW_I18N.portview_source_port, '', 'ports', PORTVIEW_I18N.portview_source_port_placeholder)}
                    ${createPickerMarkup('add_dst_port_caption', PORTVIEW_I18N.portview_destination_port, '', 'ports', PORTVIEW_I18N.portview_destination_port_placeholder)}
                    <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_cable)}</span>
                        <input name="add_cable_name" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="${escapeHtml(PORTVIEW_I18N.portview_optional)}">
                    </label>
                    <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_speed)}</span>
                        <input name="add_speed" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="${escapeHtml(PORTVIEW_I18N.portview_speed_placeholder)}">
                    </label>
                    <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_length)}</span>
                        <input name="add_length" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="${escapeHtml(PORTVIEW_I18N.portview_length_placeholder)}">
                    </label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-slate-700">
                        <input type="checkbox" name="add_crossover" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>${escapeHtml(PORTVIEW_I18N.portview_crossover)}</span>
                    </label>
                    <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3 md:col-span-2">
                        <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_type)}</span>
                        <input name="add_type" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="${escapeHtml(PORTVIEW_I18N.portview_type_placeholder)}">
                    </label>
                    <div class="md:col-span-2">
                        <button type="button" class="inline-flex h-9 items-center gap-2 rounded-full bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700" data-action="add-connection" title="${escapeHtml(PORTVIEW_I18N.portview_add_connection)}">
                            <i data-lucide="plus"></i><span>${escapeHtml(PORTVIEW_I18N.portview_add_connection)}</span>
                        </button>
                    </div>
                </form>
            </div>
        `;

        return notice + tracePanel + addPanel + chain.map((conn, index) => {
            const isEditing = editingConnectionUuid === String(conn.connection_uuid || '');
            const canDeleteConnection = !isCorePatchpanelOutletConnection(conn);
            const srcCaption = escapeHtml(conn.src_device_caption || '--');
            const srcPort = escapeHtml(conn.src_port_caption || '--');
            const dstCaption = escapeHtml(conn.dst_device_caption || '--');
            const dstPort = escapeHtml(conn.dst_port_caption || '--');
            const speed = escapeHtml(speedLabel(conn.connection_speed || ''));
            const cable = escapeHtml(conn.cable_name || '--');
            const srcStatus = escapeHtml(conn.src_port_status ?? '--');
            const vlanTagged = escapeHtml(conn.src_vlan_tagged || conn.dst_vlan_tagged || '--');
            const vlanUntagged = escapeHtml(conn.src_vlan_untagged || conn.dst_vlan_untagged || '--');
            const srcLocationCaption = escapeHtml(getLocationCaption(conn, 'src'));
            const dstLocationCaption = escapeHtml(getLocationCaption(conn, 'dst'));
            const connectionUuid = escapeHtml(conn.connection_uuid || '--');
            const editForm = isEditing ? `
                <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">${escapeHtml(PORTVIEW_I18N.button_edit)}</div>
                            <div class="text-xs text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_edit_subtitle)}</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="inline-flex h-9 items-center gap-2 rounded-full bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700" data-action="save-connection-edit" title="${escapeHtml(PORTVIEW_I18N.save)}">
                                <i data-lucide="save"></i><span>${escapeHtml(PORTVIEW_I18N.save)}</span>
                            </button>
                            <button type="button" class="inline-flex h-9 items-center gap-2 rounded-full border border-slate-300 px-3 text-sm font-semibold text-slate-700 hover:bg-slate-100" data-action="cancel-connection-edit" title="${escapeHtml(PORTVIEW_I18N.cancel)}">
                                <i data-lucide="x"></i><span>${escapeHtml(PORTVIEW_I18N.cancel)}</span>
                            </button>
                        </div>
                    </div>
                    <form class="grid gap-3 text-sm md:grid-cols-2" data-connection-edit-form="1" data-connection-uuid="${connectionUuid}">
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3 md:col-span-2">
                            <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_connection_cable)}</span>
                            <input name="cable_name" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.cable_name || '')}">
                        </label>
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_speed)}</span>
                            <input name="speed" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.connection_speed ?? '')}">
                        </label>
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_type)}</span>
                            <input name="type" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.connection_type || '')}">
                        </label>
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_length)}</span>
                            <input name="length" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.length || '')}">
                        </label>
                        ${canDeleteConnection ? `<div class="flex items-end gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <button type="button" class="inline-flex h-10 items-center gap-2 rounded-full bg-red-500 px-4 text-white hover:bg-red-600" data-action="delete-connection" data-connection-uuid="${connectionUuid}" title="${escapeHtml(PORTVIEW_I18N.portview_delete_connection)}">
                                <i data-lucide="trash-2"></i><span>${escapeHtml(PORTVIEW_I18N.portview_delete_connection)}</span>
                            </button>
                        </div>` : `<div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_delete_blocked)}</div>`}
                        ${createPickerMarkup('src_location_caption', PORTVIEW_I18N.portview_source_room, conn.src_location_caption || '', 'locations', PORTVIEW_SEARCH_PLACEHOLDER)}
                        ${createPickerMarkup('dst_location_caption', PORTVIEW_I18N.portview_destination_room, conn.dst_location_caption || '', 'locations', PORTVIEW_SEARCH_PLACEHOLDER)}
                        ${createPickerMarkup('src_device_caption', PORTVIEW_I18N.portview_source_device, conn.src_device_caption || '', 'devices', PORTVIEW_SEARCH_PLACEHOLDER)}
                        ${createPickerMarkup('dst_device_caption', PORTVIEW_I18N.portview_destination_device, conn.dst_device_caption || '', 'devices', PORTVIEW_SEARCH_PLACEHOLDER)}
                        ${createPickerMarkup('src_port_caption', PORTVIEW_I18N.portview_source_port_caption, conn.src_port_caption || '', 'ports', PORTVIEW_SEARCH_PLACEHOLDER)}
                        ${createPickerMarkup('dst_port_caption', PORTVIEW_I18N.portview_destination_port_caption, conn.dst_port_caption || '', 'ports', PORTVIEW_SEARCH_PLACEHOLDER)}
                        ${createPickerMarkup('src_port_hostname', PORTVIEW_I18N.portview_source_hostname, conn.src_port_hostname || '', 'ports', PORTVIEW_SEARCH_PLACEHOLDER)}
                        ${createPickerMarkup('dst_port_hostname', PORTVIEW_I18N.portview_destination_hostname, conn.dst_port_hostname || '', 'ports', PORTVIEW_SEARCH_PLACEHOLDER)}
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_source_mac)}</span>
                            <input name="src_port_mac" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.src_port_mac || '')}">
                        </label>
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_destination_mac)}</span>
                            <input name="dst_port_mac" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.dst_port_mac || '')}">
                        </label>
                    </form>
                </div>
            ` : '';

            return `
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 shadow-sm">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <div class="font-semibold text-slate-900">${escapeHtml(PORTVIEW_I18N.connections)} ${index + 1}</div>
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            <span>${connectionUuid}</span>
                            <button type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-600 hover:bg-white" title="${escapeHtml(PORTVIEW_I18N.button_edit)}" data-action="edit-connection" data-connection-uuid="${connectionUuid}"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button>
                        </div>
                    </div>
                    <div class="grid gap-2 text-sm sm:grid-cols-2">
                        <div><span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_source_label)}:</span> ${srcCaption} / ${srcPort}</div>
                        <div><span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_destination_label)}:</span> ${dstCaption} / ${dstPort}</div>
                        <div><span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_source_room)}:</span> ${srcLocationCaption}</div>
                        <div><span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_destination_room)}:</span> ${dstLocationCaption}</div>
                        <div><span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_cable)}:</span> ${cable}</div>
                        <div><span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_speed)}:</span> ${speed}</div>
                        <div><span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_status_label)}:</span> ${srcStatus}</div>
                        <div><span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_tagged_label)}:</span> ${vlanTagged}</div>
                        <div><span class="text-slate-500">${escapeHtml(PORTVIEW_I18N.portview_untagged_label)}:</span> ${vlanUntagged}</div>
                    </div>
                    ${editForm}
                </div>
            `;
        }).join('');
    }

    function renderCurrentDetail() {
        const dialog = document.getElementById('chainDetailDialog');
        const body = document.getElementById('chainDetailBody');
        body.innerHTML = renderChainDetail(currentDetailChain);
        dialog.classList.remove('hidden');
        dialog.dataset.open = '1';
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
        loadCurrentChainTrace();
    }

    async function loadConnectionTrace(connectionUuid) {
        const target = document.getElementById('chainDetailTrace');
        if (!target || !connectionUuid) {
            return;
        }

        target.innerHTML = '<div class="text-sm text-slate-500">' + escapeHtml(PORTVIEW_I18N.portview_trace_loading) + '</div>';
        try {
            const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/cable_trace?from=' + encodeURIComponent(connectionUuid) + '&kind=connection', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();
            target.innerHTML = renderTraceInlineHtml(result);
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        } catch (error) {
            target.innerHTML = '<div class="rounded-lg border border-red-300 bg-red-50 p-2 text-xs text-red-700">' + escapeHtml(PORTVIEW_I18N.portview_trace_error) + ': ' + escapeHtml(error.message || error) + '</div>';
        }
    }

    function loadCurrentChainTrace() {
        if (!currentDetailConnectionUuid) {
            return;
        }
        loadConnectionTrace(currentDetailConnectionUuid);
    }

    function renderTraceInlineHtml(result) {
        if (!result || result.error) {
            return '<div class="rounded-lg border border-red-300 bg-red-50 p-2 text-xs text-red-700">' + escapeHtml((result && result.error) || PORTVIEW_I18N.portview_trace_error) + '</div>';
        }

        if (result.kind === 'device') {
            const ports = Array.isArray(result.ports) ? result.ports : [];
            if (!ports.length) {
                return '<div class="text-xs text-slate-500">' + escapeHtml(PORTVIEW_I18N.portview_no_connected_ports) + '</div>';
            }
            return ports.map((portTrace) => '<div class="mb-3">' + renderTraceInlineHtml(portTrace) + '</div>').join('');
        }

        const startLabel = result.start && (result.start.cable_name || result.start.caption)
            ? '<div class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">' + escapeHtml(PORTVIEW_I18N.portview_trace_start) + ': ' + escapeHtml(result.start.cable_name || result.start.caption) + '</div>'
            : '';
        const branches = Array.isArray(result.branches) ? result.branches : [];
        if (!branches.length) {
            return startLabel + '<div class="text-xs text-slate-500">' + escapeHtml(PORTVIEW_I18N.portview_trace_no_further) + '</div>';
        }

        return startLabel + branches.map((branch, index) => {
            const items = [];
            if (result.kind === 'device_port' && result.start) {
                items.push(traceNodePill(result.start));
            }
            branch.forEach((hop) => {
                items.push(traceCableArrow(hop.cable || {}));
                items.push(traceNodePill(hop.port));
            });
            return '<div class="mb-2"><div class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">' + escapeHtml(formatMessage(PORTVIEW_I18N.portview_trace_path, { index: index + 1 })) + '</div><div class="flex flex-wrap items-stretch gap-1">' + items.join('') + '</div></div>';
        }).join('');
    }

    function traceNodePill(node) {
        if (!node || node.type !== 'port') {
            return '<div class="rounded-lg border border-slate-300 bg-slate-100 p-2 text-xs text-slate-700">' + escapeHtml(PORTVIEW_I18N.status_unknown) + '</div>';
        }
        const device = node.device || {};
        const location = node.location || {};
        const status = node.snmp && node.snmp.oper_status === 1 ? 'up' : node.snmp && node.snmp.admin_status === 2 ? 'admin_down' : node.snmp && node.snmp.oper_status === 2 ? 'down' : 'unknown';
        const statusClassName = {
            up: 'bg-emerald-100 text-emerald-800',
            down: 'bg-amber-100 text-amber-800',
            admin_down: 'bg-red-100 text-red-800',
            unknown: 'bg-slate-100 text-slate-700'
        }[status];
        const endpointBadge = node.endpoint ? '<span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-blue-700">' + escapeHtml(PORTVIEW_I18N.portview_trace_endpoint) + '</span>' : '';
        const truncated = node.truncated_reason ? '<div class="mt-1 text-[10px] text-amber-700">' + escapeHtml(PORTVIEW_I18N.portview_trace_note) + ': ' + escapeHtml(node.truncated_reason) + '</div>' : '';
        return '<div class="min-w-[170px] rounded-lg border border-slate-300 bg-white p-2 text-xs shadow-sm">'
            + '<div class="flex items-center justify-between gap-1"><div class="font-bold text-slate-900">' + escapeHtml(device.caption || PORTVIEW_I18N.portview_trace_device_fallback) + endpointBadge + '</div>'
            + '<span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase ' + statusClassName + '">' + escapeHtml(device.type || '') + '</span></div>'
            + (location.caption ? '<div class="text-[10px] text-slate-500">' + escapeHtml(PORTVIEW_I18N.portview_trace_location) + ': ' + escapeHtml(location.caption) + '</div>' : '')
            + '<div class="mt-1 rounded bg-slate-50 px-1.5 py-0.5"><span class="font-semibold">' + escapeHtml(PORTVIEW_I18N.portview_trace_port) + ':</span> ' + escapeHtml(node.caption || '—') + '</div>'
            + (node.ip ? '<div class="text-[10px] text-slate-600">' + escapeHtml(PORTVIEW_I18N.portview_trace_ip) + ': ' + escapeHtml(node.ip) + '</div>' : '')
            + truncated
            + '</div>';
    }

    function traceCableArrow(edge) {
        const parts = [];
        if (edge.cable_name) parts.push(escapeHtml(edge.cable_name));
        if (edge.cable_type) parts.push(escapeHtml(edge.cable_type));
        if (edge.length) parts.push(escapeHtml(edge.length) + ' m');
        const label = parts.length ? parts.join(' · ') : escapeHtml(PORTVIEW_I18N.portview_trace_cable_fallback);
        return '<div class="flex flex-col items-center justify-center px-1 text-slate-500"><div class="text-[9px] uppercase tracking-wider">' + label + '</div>'
            + '<svg viewBox="0 0 60 12" width="60" height="12" class="my-0.5"><line x1="2" y1="6" x2="58" y2="6" stroke="#64748b" stroke-width="2" stroke-dasharray="4 3"/><polygon points="58,6 52,3 52,9" fill="#64748b"/></svg></div>';
    }

    async function saveConnectionEdit(form, extraPayload = {}) {
        const connectionUuid = String(form.dataset.connectionUuid || '');
        if (!connectionUuid) {
            detailNotice = PORTVIEW_I18N.portview_no_connection_to_save;
            renderCurrentDetail();
            return;
        }

        const cableName = String(form.querySelector('[name="cable_name"]').value || '').trim();
        const speed = parseMaybeNumber(form.querySelector('[name="speed"]').value);
        const type = String(form.querySelector('[name="type"]').value || '').trim();
        const length = parseMaybeNumber(form.querySelector('[name="length"]').value);
        const srcLocationCaption = String(form.querySelector('[name="src_location_caption"]').value || '').trim();
        const dstLocationCaption = String(form.querySelector('[name="dst_location_caption"]').value || '').trim();
        const srcDeviceCaption = String(form.querySelector('[name="src_device_caption"]').value || '').trim();
        const dstDeviceCaption = String(form.querySelector('[name="dst_device_caption"]').value || '').trim();
        const srcPortCaption = String(form.querySelector('[name="src_port_caption"]').value || '').trim();
        const dstPortCaption = String(form.querySelector('[name="dst_port_caption"]').value || '').trim();
        const srcPortHostname = String(form.querySelector('[name="src_port_hostname"]').value || '').trim();
        const dstPortHostname = String(form.querySelector('[name="dst_port_hostname"]').value || '').trim();
        const srcPortMac = String(form.querySelector('[name="src_port_mac"]').value || '').trim();
        const dstPortMac = String(form.querySelector('[name="dst_port_mac"]').value || '').trim();

        const connectionPayload = { ...extraPayload };
        if (cableName !== '') connectionPayload.cable_name = cableName;
        if (speed !== null) connectionPayload.speed = speed;
        if (type !== '') connectionPayload.type = type;
        if (length !== null) connectionPayload.length = length;

        const summary = summarizeChain(currentDetailChain);
        const connection = currentDetailChain.find((conn) => String(conn.connection_uuid || '') === connectionUuid) || null;
        const locationUpdates = [];
        const portUpdates = [];
        const portIpUpdates = [];

        const srcPortMetadataUuid = getPortMetadataUuid(connection || summary.coreConn || currentDetailChain[0] || {}, 'src');
        const dstPortMetadataUuid = getPortMetadataUuid(connection || summary.coreConn || currentDetailChain[0] || {}, 'dst');
        const srcPortIpUuid = getPortIpUuid(connection || summary.coreConn || currentDetailChain[0] || {}, 'src');
        const dstPortIpUuid = getPortIpUuid(connection || summary.coreConn || currentDetailChain[0] || {}, 'dst');

        const srcLocationMetadataUuid = getLocationMetadataUuid(connection || summary.coreConn || currentDetailChain[0] || {}, 'src');
        const dstLocationMetadataUuid = getLocationMetadataUuid(connection || summary.coreConn || currentDetailChain[0] || {}, 'dst');
        const srcDeviceMetadataUuid = String((connection || {}).src_device_metadata_uuid || '');
        const dstDeviceMetadataUuid = String((connection || {}).dst_device_metadata_uuid || '');

        if (srcDeviceMetadataUuid && srcDeviceCaption !== '' && srcDeviceCaption !== String((connection || {}).src_device_caption || '')) {
            portUpdates.push(apiUpdateResource('metadata', srcDeviceMetadataUuid, { caption: srcDeviceCaption }));
        }

        if (dstDeviceMetadataUuid && dstDeviceCaption !== '' && dstDeviceCaption !== String((connection || {}).dst_device_caption || '')) {
            portUpdates.push(apiUpdateResource('metadata', dstDeviceMetadataUuid, { caption: dstDeviceCaption }));
        }

        if (srcPortMetadataUuid && srcPortCaption !== '' && srcPortCaption !== String((connection || {}).src_port_caption || '')) {
            portUpdates.push(apiUpdateResource('metadata', srcPortMetadataUuid, { caption: srcPortCaption }));
        }

        if (dstPortMetadataUuid && dstPortCaption !== '' && dstPortCaption !== String((connection || {}).dst_port_caption || '')) {
            portUpdates.push(apiUpdateResource('metadata', dstPortMetadataUuid, { caption: dstPortCaption }));
        }

        if (srcPortIpUuid && srcPortHostname !== '' && srcPortHostname !== String((connection || {}).src_port_hostname || '')) {
            portIpUpdates.push(apiUpdateResource('device_port_ip', srcPortIpUuid, { hostname: srcPortHostname }));
        }

        if (dstPortIpUuid && dstPortHostname !== '' && dstPortHostname !== String((connection || {}).dst_port_hostname || '')) {
            portIpUpdates.push(apiUpdateResource('device_port_ip', dstPortIpUuid, { hostname: dstPortHostname }));
        }

        if (connection?.src_port_uuid && srcPortMac !== '' && srcPortMac !== String(connection.src_port_mac || '')) {
            portUpdates.push(apiUpdateResource('device_port', connection.src_port_uuid, { mac_address: srcPortMac }));
        }

        if (connection?.dst_port_uuid && dstPortMac !== '' && dstPortMac !== String(connection.dst_port_mac || '')) {
            portUpdates.push(apiUpdateResource('device_port', connection.dst_port_uuid, { mac_address: dstPortMac }));
        }

        if (srcLocationMetadataUuid && srcLocationCaption !== '' && srcLocationCaption !== String((connection || {}).src_location_caption || '')) {
            locationUpdates.push(apiUpdateResource('metadata', srcLocationMetadataUuid, { caption: srcLocationCaption }));
        }

        if (dstLocationMetadataUuid && dstLocationCaption !== '' && dstLocationMetadataUuid !== srcLocationMetadataUuid && dstLocationCaption !== String((connection || {}).dst_location_caption || '')) {
            locationUpdates.push(apiUpdateResource('metadata', dstLocationMetadataUuid, { caption: dstLocationCaption }));
        }

        try {
            detailNotice = PORTVIEW_I18N.portview_saving_changes;
            renderCurrentDetail();

            if (Object.keys(connectionPayload).length > 0) {
                await apiUpdateResource('connection', connectionUuid, connectionPayload);
            }

            const updateJobs = [
                ...locationUpdates,
                ...portUpdates,
                ...portIpUpdates
            ];

            if (updateJobs.length > 0) {
                await Promise.all(updateJobs);
            }

            editingConnectionUuid = '';
            detailNotice = PORTVIEW_I18N.portview_changes_saved;
            await loadTable();
            renderCurrentDetail();
        } catch (error) {
            detailNotice = error?.message || PORTVIEW_I18N.portview_save_failed;
            renderCurrentDetail();
        }
    }

    async function addConnectionFromForm(form) {
        if (!form) {
            return;
        }

        const srcInput = form.querySelector('[name="add_src_port_caption"]');
        const dstInput = form.querySelector('[name="add_dst_port_caption"]');
        const cableName = String(form.querySelector('[name="add_cable_name"]').value || '').trim();
        const speed = parseMaybeNumber(form.querySelector('[name="add_speed"]').value);
        const length = parseMaybeNumber(form.querySelector('[name="add_length"]').value);
        const crossover = !!(form.querySelector('[name="add_crossover"]') && form.querySelector('[name="add_crossover"]').checked);
        const type = String(form.querySelector('[name="add_type"]').value || '').trim();

        const sourcePortUuid = resolvePortUuidFromPickerInput(srcInput);
        const destinationPortUuid = resolvePortUuidFromPickerInput(dstInput);

        if (!sourcePortUuid || !destinationPortUuid) {
            detailNotice = PORTVIEW_I18N.portview_select_source_destination;
            renderCurrentDetail();
            return;
        }

        if (sourcePortUuid === destinationPortUuid) {
            detailNotice = PORTVIEW_I18N.portview_identical_ports;
            renderCurrentDetail();
            return;
        }

        try {
            detailNotice = PORTVIEW_I18N.portview_creating_connection;
            renderCurrentDetail();

            const metadataPayload = {
                status: 0,
                caption: PORTVIEW_I18N.portview_new_connection_caption,
                description: PORTVIEW_I18N.portview_new_connection_description
            };
            const metadataResult = await apiCreateResource('metadata', metadataPayload);
            const metadataUuid = extractUuidFromApiPayload(metadataResult);
            if (!metadataUuid) {
                throw new Error(PORTVIEW_I18N.portview_create_metadata_failed);
            }

            const connectionPayload = {
                metadata: metadataUuid,
                device_port_source: sourcePortUuid,
                device_port_destination: destinationPortUuid
            };
            if (cableName) connectionPayload.cable_name = cableName;
            if (speed !== null) connectionPayload.speed = speed;
            if (length !== null) connectionPayload.length = length;
            connectionPayload.crossover = crossover;
            if (type) connectionPayload.type = type;

            const connectionResult = await apiCreateResource('connection', connectionPayload);
            const newConnectionUuid = extractUuidFromApiPayload(connectionResult);

            detailNotice = PORTVIEW_I18N.portview_connection_added;
            await loadTable();

            if (newConnectionUuid) {
                const matchingChain = allChainsCache.find((entry) => entry.some((conn) => String(conn.connection_uuid || '') === newConnectionUuid));
                if (matchingChain) {
                    currentDetailChain = matchingChain;
                    currentDetailConnectionUuid = newConnectionUuid;
                }
            }

            renderCurrentDetail();
        } catch (error) {
            detailNotice = error?.message || PORTVIEW_I18N.portview_connection_add_failed;
            renderCurrentDetail();
        }
    }

    async function deleteConnection(connectionUuid) {
        const connection = currentDetailChain.find((conn) => String(conn.connection_uuid || '') === String(connectionUuid || '')) || null;
        if (!connection) {
            detailNotice = PORTVIEW_I18N.portview_delete_missing;
            renderCurrentDetail();
            return;
        }

        if (isCorePatchpanelOutletConnection(connection)) {
            detailNotice = PORTVIEW_I18N.portview_delete_blocked;
            renderCurrentDetail();
            return;
        }

        const confirmText = PORTVIEW_I18N.portview_delete_confirm;

        if (!window.confirm(confirmText)) {
            return;
        }

        try {
            detailNotice = PORTVIEW_I18N.portview_deleting_connection;
            renderCurrentDetail();

            const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?' + new URLSearchParams({ table: 'connection', uuid: connectionUuid }).toString(), {
                method: 'DELETE'
            });

            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(payload?.error || payload?.details || PORTVIEW_I18N.portview_delete_failed);
            }

            editingConnectionUuid = '';
            detailNotice = PORTVIEW_I18N.portview_connection_deleted;
            currentDetailChain = [];
            currentDetailConnectionUuid = '';
            await loadTable();
            const dialog = document.getElementById('chainDetailDialog');
            dialog.classList.add('hidden');
            dialog.dataset.open = '0';
        } catch (error) {
            detailNotice = error?.message || PORTVIEW_I18N.portview_delete_failed;
            renderCurrentDetail();
        }
    }

    function renderPagination(totalCount) {
        const totalPages = Math.ceil(totalCount / currentLimit);
        const container = document.getElementById('pagination');
        const containerBottom = document.getElementById('pagination_bottom');
        if (!container || !containerBottom) {
            return;
        }

        if (totalPages <= 1) {
            container.innerHTML = '';
            containerBottom.innerHTML = '';
            return;
        }

        const pagesPerGroup = 10;
        const pageGroup = Math.floor((currentPage - 1) / pagesPerGroup);
        const groupStart = pageGroup * pagesPerGroup + 1;
        const groupEnd = Math.min((pageGroup + 1) * pagesPerGroup, totalPages);
        const prevPage = (pageGroup - 1) * pagesPerGroup + 1;
        const nextPage = (pageGroup + 1) * pagesPerGroup + 1;

        let html = '<div class="flex flex-wrap items-center text-sm text-slate-700"><div class="mr-2 text-slate-500">' + escapeHtml(PORTVIEW_I18N.page) + ':</div>';

        html += `<button type="button" class="mr-2 cursor-pointer ${pageGroup === 0 ? 'invisible' : ''}" ${pageGroup === 0 ? 'tabindex="-1" aria-hidden="true"' : `onclick="window.portViewApp.goToPage(${prevPage})"`}>&larr;</button>`;

        for (let i = groupStart; i <= groupEnd; i++) {
            const isActive = i === currentPage;
            html += `<button type="button" class="mr-2 cursor-pointer ${isActive ? 'current-page text-blue-500 font-semibold' : ''}" onclick="window.portViewApp.goToPage(${i})">${i}</button>`;
        }

        html += `<button type="button" class="mr-2 cursor-pointer ${groupEnd >= totalPages ? 'invisible' : ''}" ${groupEnd >= totalPages ? 'tabindex="-1" aria-hidden="true"' : `onclick="window.portViewApp.goToPage(${nextPage})"`}>&rarr;</button>`;

        html += '</div>';

        container.innerHTML = html;
        containerBottom.innerHTML = html;
    }

    function buildChains(results) {
        const chains = [];
        const usedUuids = new Set();

        const deviceTypeOf = (conn, side) => String(conn?.[side + '_device_type'] ?? '').toLowerCase();
        const portUuidOf = (conn, side) => {
            const value = conn?.[side + '_port_uuid'] ?? null;
            return value !== null && value !== '' ? String(value) : null;
        };

        const isCoreChain = (conn) => {
            const srcType = deviceTypeOf(conn, 'src');
            const dstType = deviceTypeOf(conn, 'dst');
            return (srcType === 'patchpanel' && dstType === 'net_outlet') || (srcType === 'net_outlet' && dstType === 'patchpanel');
        };

        for (const conn of results) {
            if (!isCoreChain(conn) || usedUuids.has(conn.connection_uuid)) {
                continue;
            }

            const chain = [];
            const patchpanelPortUuid = deviceTypeOf(conn, 'src') === 'patchpanel'
                ? portUuidOf(conn, 'src')
                : portUuidOf(conn, 'dst');
            const wallplatePortUuid = deviceTypeOf(conn, 'src') === 'net_outlet'
                ? portUuidOf(conn, 'src')
                : portUuidOf(conn, 'dst');

            let switchConn = null;
            let endpointConn = null;

            for (const candidate of results) {
                if (usedUuids.has(candidate.connection_uuid)) {
                    continue;
                }

                const candidateSrcType = deviceTypeOf(candidate, 'src');
                const candidateDstType = deviceTypeOf(candidate, 'dst');
                const candidateSrcPort = portUuidOf(candidate, 'src');
                const candidateDstPort = portUuidOf(candidate, 'dst');

                if (
                    switchConn === null
                    && patchpanelPortUuid !== null
                    && ((candidateSrcPort === patchpanelPortUuid && candidateDstType === 'switch')
                        || (candidateDstPort === patchpanelPortUuid && candidateSrcType === 'switch'))
                ) {
                    switchConn = candidate;
                    continue;
                }

                if (
                    endpointConn === null
                    && wallplatePortUuid !== null
                    && ((candidateSrcPort === wallplatePortUuid && !['patchpanel', 'net_outlet', 'outlet', 'coupler'].includes(candidateDstType))
                        || (candidateDstPort === wallplatePortUuid && !['patchpanel', 'net_outlet', 'outlet', 'coupler'].includes(candidateSrcType)))
                ) {
                    endpointConn = candidate;
                }
            }

            if (switchConn !== null) {
                chain.push(switchConn);
                usedUuids.add(switchConn.connection_uuid);
            }

            chain.push(conn);
            usedUuids.add(conn.connection_uuid);

            if (endpointConn !== null) {
                chain.push(endpointConn);
                usedUuids.add(endpointConn.connection_uuid);
            }

            chains.push(chain);
        }

        for (const conn of results) {
            if (usedUuids.has(conn.connection_uuid)) {
                continue;
            }

            chains.push([conn]);
            usedUuids.add(conn.connection_uuid);
        }

        return chains;
    }

    function chainMatchesQuery(chain, query) {
        const normalizedQuery = String(query ?? '').trim().toLowerCase();
        if (normalizedQuery === '') {
            return true;
        }

        return chain.some((conn) => {
            const values = Object.values(conn || {});
            return values.some((value) => String(value ?? '').toLowerCase().includes(normalizedQuery));
        });
    }

    function uniqueSorted(values) {
        return Array.from(new Set(values.filter(Boolean))).sort((a, b) => String(a).localeCompare(String(b), PORTVIEW_LOCALE));
    }

    function buildSearchIndex(chains) {
        const locations = new Map();
        const devices = new Map();
        const ports = new Map();

        const addItem = (map, item) => {
            const uuid = String(item.uuid || '').trim();
            const caption = String(item.caption || '').trim();
            if (!uuid || !caption || map.has(uuid)) {
                return;
            }
            map.set(uuid, item);
        };

        chains.forEach((chain) => {
            chain.forEach((conn) => {
                addItem(locations, {
                    uuid: conn.src_location_uuid,
                    caption: conn.src_location_caption,
                    kind: PORTVIEW_PICKER_KIND_LABELS.locations,
                    meta: conn.src_device_caption || conn.src_port_caption || ''
                });
                addItem(locations, {
                    uuid: conn.dst_location_uuid,
                    caption: conn.dst_location_caption,
                    kind: PORTVIEW_PICKER_KIND_LABELS.locations,
                    meta: conn.dst_device_caption || conn.dst_port_caption || ''
                });

                addItem(devices, {
                    uuid: conn.src_device_uuid,
                    caption: conn.src_device_caption,
                    kind: PORTVIEW_PICKER_KIND_LABELS.devices,
                    meta: [conn.src_device_type, conn.src_location_caption].filter(Boolean).join(' · ')
                });
                addItem(devices, {
                    uuid: conn.dst_device_uuid,
                    caption: conn.dst_device_caption,
                    kind: PORTVIEW_PICKER_KIND_LABELS.devices,
                    meta: [conn.dst_device_type, conn.dst_location_caption].filter(Boolean).join(' · ')
                });

                addItem(ports, {
                    uuid: conn.src_port_uuid,
                    caption: conn.src_port_caption,
                    kind: PORTVIEW_PICKER_KIND_LABELS.ports,
                    meta: [conn.src_device_caption, conn.src_port_hostname, conn.src_port_mac].filter(Boolean).join(' · ')
                });
                addItem(ports, {
                    uuid: conn.dst_port_uuid,
                    caption: conn.dst_port_caption,
                    kind: PORTVIEW_PICKER_KIND_LABELS.ports,
                    meta: [conn.dst_device_caption, conn.dst_port_hostname, conn.dst_port_mac].filter(Boolean).join(' · ')
                });
            });
        });

        return {
            locations: Array.from(locations.values()),
            devices: Array.from(devices.values()),
            ports: Array.from(ports.values())
        };
    }

    function searchIndexEntries(kind, query) {
        const normalizedQuery = String(query ?? '').trim().toLowerCase();
        const source = Array.isArray(searchIndexCache[kind]) ? searchIndexCache[kind] : [];
        const entries = normalizedQuery
            ? source.filter((item) => {
                const haystack = [item.caption, item.meta, item.uuid, item.kind].join(' ').toLowerCase();
                return haystack.includes(normalizedQuery);
            })
            : source;

        return entries.slice(0, 8);
    }

    function renderSearchSuggestions(kind, query) {
        const entries = searchIndexEntries(kind, query);
        if (entries.length === 0) {
            return '<div class="px-3 py-2 text-xs text-slate-500">' + escapeHtml(PORTVIEW_I18N.portview_no_search_hits) + '</div>';
        }

        return entries.map((entry) => {
            const caption = escapeHtml(entry.caption || '--');
            const meta = escapeHtml(entry.meta || '');
            const kindLabel = escapeHtml(entry.kind || '');
            const uuid = escapeHtml(entry.uuid || '');
            return `
                <button type="button" class="w-full border-b border-slate-100 px-3 py-2 text-left hover:bg-slate-100 last:border-b-0" data-search-select="1" data-search-caption="${caption}" data-search-uuid="${uuid}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium text-slate-900">${caption}</span>
                        <span class="text-[11px] uppercase tracking-wide text-slate-500">${kindLabel}</span>
                    </div>
                    <div class="text-xs text-slate-500">${meta || uuid}</div>
                </button>
            `;
        }).join('');
    }

    function createPickerMarkup(name, label, value, kind, placeholder) {
        return `
            <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3" data-search-picker="${name}">
                <span class="text-slate-500">${escapeHtml(label)}</span>
                <div class="relative">
                    <input name="${name}" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 pr-10 shadow-sm focus:border-blue-500 focus:outline-none" value="${escapeHtml(value || '')}" placeholder="${escapeHtml(placeholder || label)}" autocomplete="off" data-search-input="1" data-search-kind="${kind}">
                    <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800" data-search-clear="1" title="${escapeHtml(PORTVIEW_I18N.portview_clear_field)}">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <div class="mt-1 hidden max-h-44 overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg" data-search-dropdown="1"></div>
            </label>
        `;
    }

    function csvEscape(value) {
        const text = String(value ?? '');
        return '"' + text.replace(/"/g, '""') + '"';
    }

    function chainToCsvRow(chain) {
        const summary = summarizeChain(chain);
        const switchConn = summary.switchConn;
        const coreConn = summary.coreConn;
        const endpointConn = summary.endpointConn;
        const switchSideIsSrc = switchConn && String(switchConn.src_device_type || '').toLowerCase() === 'switch';
        const coreSideIsSrc = coreConn && String(coreConn.src_device_type || '').toLowerCase() === 'patchpanel';
        const endpointSideIsSrc = endpointConn && !['switch', 'patchpanel', 'net_outlet'].includes(String(endpointConn.src_device_type || '').toLowerCase());

        const row = getChainTableData(chain);
        const csvValues = {
            statusValue: row.statusValue,
            switchCaption: row.switchCaption,
            switchPortCaption: row.switchPortCaption,
            patchpanelCaption: row.patchpanelCaption,
            patchpanelPortCaption: row.patchpanelPortCaption,
            cableName: row.cableName,
            roomCaption: row.roomCaption,
            wallplateCaption: row.wallplateCaption,
            wallplatePortCaption: row.wallplatePortCaption,
            endpointCaption: row.endpointCaption,
            endpointPortCaption: row.endpointPortCaption,
            endpointIp: row.endpointIp,
            endpointHostname: row.endpointHostname,
            endpointMac: row.endpointMac,
            vlanTaggedText: row.vlanTaggedText,
            vlanUntaggedText: row.vlanUntaggedText
        };

        return getVisiblePortviewColumns().map((column) => csvEscape(csvValues[column.key] ?? '')).join(',');
    }

    function exportVisibleChainsAsCsv() {
        const header = getVisiblePortviewColumns().map((column) => column.label);

        const csvLines = [header.map(csvEscape).join(',')];
        visibleChainsCache.forEach((chain) => {
            csvLines.push(chainToCsvRow(chain));
        });

        const blob = new Blob([csvLines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'portview.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    function ensurePortviewSortKeyVisible() {
        const visible = new Set(sanitizePortviewColumns(visibleColumnKeys));
        if (currentSort && !visible.has(currentSort)) {
            currentSort = '';
            currentOrder = 'asc';
        }
    }

    function buildPortviewColumnPickerRow(key, label, checked) {
        const row = document.createElement('div');
        row.className = 'pf-col-row flex items-center gap-2 rounded-md border border-slate-200 bg-white px-2 py-1.5 hover:bg-slate-50';
        row.draggable = true;
        row.dataset.colKey = key;
        row.innerHTML = `
            <span class="cursor-grab text-slate-400 hover:text-slate-600" title="${escapeHtml(PORTVIEW_I18N.drag_label)}"><i data-lucide="grip-vertical" class="h-4 w-4"></i></span>
            <input type="checkbox" class="pf-col-check h-4 w-4 rounded border-slate-300" ${checked ? 'checked' : ''} />
            <code class="text-[10px] text-slate-400">${escapeHtml(key)}</code>
            <span class="ml-1 flex-1 text-sm text-slate-800">${escapeHtml(label)}</span>
        `;
        row.addEventListener('dragstart', (event) => {
            row.classList.add('opacity-50');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', key);
        });
        row.addEventListener('dragend', () => row.classList.remove('opacity-50'));
        row.addEventListener('dragover', (event) => {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        });
        row.addEventListener('drop', (event) => {
            event.preventDefault();
            const sourceKey = event.dataTransfer.getData('text/plain');
            if (!sourceKey || sourceKey === key) {
                return;
            }
            const list = row.parentElement;
            const sourceElement = list.querySelector('.pf-col-row[data-col-key="' + CSS.escape(sourceKey) + '"]');
            if (!sourceElement) {
                return;
            }
            const rect = row.getBoundingClientRect();
            const before = (event.clientY - rect.top) < (rect.height / 2);
            list.insertBefore(sourceElement, before ? row : row.nextSibling);
        });
        return row;
    }

    function closePortviewColumnPicker() {
        const modal = document.getElementById('pf-column-picker');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    function openPortviewColumnPicker() {
        const current = sanitizePortviewColumns(visibleColumnKeys);
        const selectedSet = new Set(current);
        const ordered = current.filter((key) => PORTVIEW_COLUMN_MAP.has(key))
            .concat(PORTVIEW_ALL_COLUMNS.filter((key) => !selectedSet.has(key)));

        let modal = document.getElementById('pf-column-picker');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'pf-column-picker';
            modal.className = 'fixed inset-0 z-[1200] hidden items-center justify-center bg-slate-900/70 p-4';
            document.body.appendChild(modal);
        }

        modal.innerHTML = `
            <div class="flex h-full max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-slate-700"><i data-lucide="columns-3" class="h-4 w-4"></i></span>
                        <div>
                            <div class="text-sm font-bold text-slate-900">${escapeHtml(PORTVIEW_I18N.columns_customize)}</div>
                            <div class="text-xs text-slate-500">${escapeHtml(PORTVIEW_TABLE_KEY)}</div>
                        </div>
                    </div>
                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-300 text-slate-800 hover:bg-slate-400" data-action="close-portview-columns" aria-label="${escapeHtml(PORTVIEW_I18N.button_close)}"><i data-lucide="x" class="h-4 w-4"></i></button>
                </div>
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-600">${escapeHtml(PORTVIEW_I18N.columns_hint)}</div>
                <div id="pf-column-picker-list" class="flex-1 overflow-auto p-3 space-y-1"></div>
                <div class="flex items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                    <button type="button" data-action="reset-portview-columns" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100"><i data-lucide="rotate-ccw" class="mr-1 inline-block h-3.5 w-3.5"></i>${escapeHtml(PORTVIEW_I18N.columns_reset)}</button>
                    <div class="flex items-center gap-2">
                        <button type="button" data-action="cancel-portview-columns" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">${escapeHtml(PORTVIEW_I18N.cancel)}</button>
                        <button type="button" data-action="save-portview-columns" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">${escapeHtml(PORTVIEW_I18N.save)}</button>
                    </div>
                </div>
            </div>
        `;

        modal.classList.remove('hidden');
        modal.classList.add('flex');

        const list = document.getElementById('pf-column-picker-list');
        list.innerHTML = '';
        ordered.forEach((key) => {
            const column = PORTVIEW_COLUMN_MAP.get(key);
            if (column) {
                list.appendChild(buildPortviewColumnPickerRow(key, column.label, selectedSet.has(key)));
            }
        });

        modal.querySelector('[data-action="close-portview-columns"]').addEventListener('click', closePortviewColumnPicker);
        modal.querySelector('[data-action="cancel-portview-columns"]').addEventListener('click', closePortviewColumnPicker);
        modal.querySelector('[data-action="reset-portview-columns"]').addEventListener('click', () => resetPortviewColumnPicker());
        modal.querySelector('[data-action="save-portview-columns"]').addEventListener('click', () => savePortviewColumnPicker());

        if (window.lucide && window.lucide.createIcons) {
            window.lucide.createIcons({ nodes: [modal] });
        }
    }

    async function persistPortviewColumnPicker(columns) {
        try {
            const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/user_table_columns', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ table: PORTVIEW_TABLE_KEY, columns })
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload.error) {
                throw new Error(payload.error || ('HTTP ' + response.status));
            }

            if (!window.__pfTableUserColumns) {
                window.__pfTableUserColumns = {};
            }
            if (!window.__pfTableUserColumnsReset) {
                window.__pfTableUserColumnsReset = {};
            }

            if (Array.isArray(payload.columns) && payload.columns.length > 0) {
                visibleColumnKeys = sanitizePortviewColumns(payload.columns);
                window.__pfTableUserColumns[PORTVIEW_TABLE_KEY] = visibleColumnKeys.slice();
                delete window.__pfTableUserColumnsReset[PORTVIEW_TABLE_KEY];
            } else {
                visibleColumnKeys = PORTVIEW_DEFAULT_COLUMNS.slice();
                delete window.__pfTableUserColumns[PORTVIEW_TABLE_KEY];
                window.__pfTableUserColumnsReset[PORTVIEW_TABLE_KEY] = true;
            }

            ensurePortviewSortKeyVisible();
            closePortviewColumnPicker();
            loadTable();
        } catch (error) {
            alert(PORTVIEW_I18N.columns_save_failed + ': ' + (error.message || error));
        }
    }

    async function savePortviewColumnPicker() {
        const list = document.getElementById('pf-column-picker-list');
        if (!list) {
            closePortviewColumnPicker();
            return;
        }
        const picked = [];
        list.querySelectorAll('.pf-col-row').forEach((row) => {
            const checkbox = row.querySelector('.pf-col-check');
            if (checkbox && checkbox.checked) {
                picked.push(row.dataset.colKey);
            }
        });
        await persistPortviewColumnPicker(picked);
    }

    async function resetPortviewColumnPicker() {
        await persistPortviewColumnPicker(null);
    }

    function populateFilters(chains) {
        const rooms = uniqueSorted(chains.map(chain => summarizeChain(chain).roomCaption).filter(value => value && value !== '--'));
        const endpointTypes = uniqueSorted(chains.map(chain => summarizeChain(chain).endpointType).filter(value => value));
        const vlans = uniqueSorted(chains.flatMap((chain) => {
            const summary = summarizeChain(chain);
            return [summary.vlanTagged, summary.vlanUntagged].filter(Boolean);
        }));

        const roomSelect = document.getElementById('filterRoom');
        const endpointSelect = document.getElementById('filterEndpointType');
        const vlanSelect = document.getElementById('filterVlan');

        const currentRoom = roomSelect.value;
        const currentEndpointType = endpointSelect.value;
        const currentVlan = vlanSelect.value;

        const fillSelect = (select, values, currentValue, placeholder) => {
            select.innerHTML = `<option value="">${placeholder}</option>`;
            values.forEach((value) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                if (String(value) === String(currentValue)) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        };

        fillSelect(roomSelect, rooms, currentRoom, PORTVIEW_I18N.portview_all_rooms);
        fillSelect(endpointSelect, endpointTypes, currentEndpointType, PORTVIEW_I18N.portview_all_endpoint_types);
        fillSelect(vlanSelect, vlans, currentVlan, PORTVIEW_I18N.portview_all_vlans);
    }

    function bindFilterControls() {
        const roomSelect = document.getElementById('filterRoom');
        const endpointSelect = document.getElementById('filterEndpointType');
        const vlanSelect = document.getElementById('filterVlan');
        const resetButton = document.getElementById('resetFiltersButton');

        roomSelect.addEventListener('change', () => {
            filterState.room = roomSelect.value;
            currentPage = 1;
            loadTable();
        });

        endpointSelect.addEventListener('change', () => {
            filterState.endpointType = endpointSelect.value;
            currentPage = 1;
            loadTable();
        });

        vlanSelect.addEventListener('change', () => {
            filterState.vlan = vlanSelect.value;
            currentPage = 1;
            loadTable();
        });

        if (resetButton) {
            resetButton.addEventListener('click', () => {
                filterState = {
                    room: '',
                    endpointType: '',
                    vlan: ''
                };
                roomSelect.value = '';
                endpointSelect.value = '';
                vlanSelect.value = '';
                currentPage = 1;
                loadTable();
            });
        }
    }

    async function fetchPortviewChains() {
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/portview_chains');
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data?.error || data?.details || (PORTVIEW_I18N.portview_fetch_failed + ': portview_chains'));
        }
        return Array.isArray(data.items) ? data.items : [];
    }

    async function loadTable() {
        renderTableHeader();

        try {
            const tbody = document.getElementById('portviewTableBody');
            tbody.innerHTML = '';

            allChainsCache = await fetchPortviewChains();

            const baseSearchIndex = buildSearchIndex(allChainsCache);
            const fullPortEntries = await ensureFullPortSearchEntries();
            searchIndexCache = {
                ...baseSearchIndex,
                ports: mergePortSearchEntries(baseSearchIndex.ports, fullPortEntries)
            };

            populateFilters(allChainsCache);
            const filteredChains = allChainsCache
                .filter(chain => chainMatchesQuery(chain, currentQuery))
                .filter(chain => chainMatchesFilters(chain));
            const sortedChains = sortChains(filteredChains);
            const totalCount = sortedChains.length;
            const start = (currentPage - 1) * currentLimit;
            const chains = sortedChains.slice(start, start + currentLimit);

            visibleChainsCache = chains;

            if (chains.length > 0) {
                chains.forEach((chain, index) => {
                    tbody.innerHTML += buildRowForChain(chain, index);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="' + String(getVisiblePortviewColumns().length) + '" class="p-4 text-center text-slate-500">' + escapeHtml(PORTVIEW_I18N.portview_no_entries) + '</td></tr>';
            }

            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons({ nodes: [tbody] });
            }

            document.getElementById('count').textContent = `${PORTVIEW_I18N.datasets}: ${totalCount}`;
            document.getElementById('count_bottom').textContent = `${PORTVIEW_I18N.datasets}: ${totalCount}`;
            document.getElementById('count').setAttribute('title', summaryText(totalCount));
            document.getElementById('count_bottom').setAttribute('title', summaryText(totalCount));

            renderPagination(totalCount);

            if (dialogIsOpen()) {
                const matchingChain = currentDetailConnectionUuid
                    ? allChainsCache.find((chain) => chain.some((conn) => String(conn.connection_uuid || '') === currentDetailConnectionUuid))
                    : null;

                if (matchingChain) {
                    currentDetailChain = matchingChain;
                    renderCurrentDetail();
                }
            }
        } catch (err) {
            console.error(PORTVIEW_I18N.portview_fetch_failed + ':', err);
        }
    }

    function dialogIsOpen() {
        const dialog = document.getElementById('chainDetailDialog');
        return dialog && dialog.dataset.open === '1';
    }

    // Event handlers
    document.getElementById('searchForm').addEventListener('submit', (e) => {
        e.preventDefault();
        currentQuery = document.querySelector('input[name="search"]').value;
        currentPage = 1;
        loadTable();
    });

    let searchInputDebounce = null;
    document.querySelector('input[name="search"]').addEventListener('input', (e) => {
        window.clearTimeout(searchInputDebounce);
        searchInputDebounce = window.setTimeout(() => {
            currentQuery = e.target.value;
            currentPage = 1;
            loadTable();
        }, 180);
    });

    document.querySelector('input[name="search"]').addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            e.target.value = '';
            currentQuery = '';
            currentPage = 1;
            loadTable();
        }
    });

    document.getElementById('table_limit_1').addEventListener('change', (e) => {
        currentLimit = parseInt(e.target.value, 10);
        document.getElementById('table_limit_2').value = currentLimit;
        document.cookie = `table_limit=${currentLimit}; path=/`;
        currentPage = 1;
        loadTable();
    });

    document.getElementById('table_limit_2').addEventListener('change', (e) => {
        currentLimit = parseInt(e.target.value, 10);
        document.getElementById('table_limit_1').value = currentLimit;
        document.cookie = `table_limit=${currentLimit}; path=/`;
        currentPage = 1;
        loadTable();
    });

    document.getElementById('portviewTableHead').addEventListener('click', (e) => {
        const button = e.target.closest('[data-sort-key]');
        if (!button) {
            return;
        }

        toggleColumnSort(String(button.dataset.sortKey || ''));
    });

    document.getElementById('chainDetailDialog').addEventListener('click', (e) => {
        const searchSelectButton = e.target.closest('[data-search-select="1"]');
        if (searchSelectButton) {
            const picker = searchSelectButton.closest('[data-search-picker]');
            const input = picker ? picker.querySelector('[data-search-input="1"]') : null;
            const dropdown = picker ? picker.querySelector('[data-search-dropdown="1"]') : null;
            if (input) {
                input.value = searchSelectButton.dataset.searchCaption || '';
                input.dataset.selectedUuid = searchSelectButton.dataset.searchUuid || '';
            }
            if (dropdown) {
                dropdown.classList.add('hidden');
            }
            return;
        }

        const clearButton = e.target.closest('[data-search-clear="1"]');
        if (clearButton) {
            const picker = clearButton.closest('[data-search-picker]');
            const input = picker ? picker.querySelector('[data-search-input="1"]') : null;
            const dropdown = picker ? picker.querySelector('[data-search-dropdown="1"]') : null;
            if (input) {
                input.value = '';
                input.dataset.selectedUuid = '';
                input.focus();
            }
            if (dropdown) {
                dropdown.classList.add('hidden');
            }
            return;
        }

        const actionButton = e.target.closest('[data-action]');
        if (actionButton) {
            const action = actionButton.dataset.action || '';
            const connectionUuid = String(actionButton.dataset.connectionUuid || '');

            if (action === 'edit-connection') {
                editingConnectionUuid = connectionUuid;
                detailNotice = '';
                renderCurrentDetail();
                return;
            }

            if (action === 'cancel-connection-edit') {
                editingConnectionUuid = '';
                detailNotice = '';
                renderCurrentDetail();
                return;
            }

            if (action === 'save-connection-edit') {
                const form = actionButton.closest('[data-connection-edit-form="1"]');
                if (form) {
                    saveConnectionEdit(form);
                }
                return;
            }

            if (action === 'delete-connection') {
                const uuid = String(actionButton.dataset.connectionUuid || '');
                deleteConnection(uuid || currentDetailConnectionUuid);
                return;
            }

            if (action === 'add-connection') {
                const form = actionButton.closest('[data-connection-add-form="1"]');
                addConnectionFromForm(form);
                return;
            }

            if (action === 'reload-trace') {
                loadCurrentChainTrace();
                return;
            }
        }

        if (e.target.id === 'chainDetailDialog') {
            window.portViewApp.closeChainDetail();
        }
    });

    document.addEventListener('input', (e) => {
        const input = e.target.closest && e.target.closest('[data-search-input="1"]');
        if (!input) {
            return;
        }

        input.dataset.selectedUuid = '';

        const picker = input.closest('[data-search-picker]');
        const dropdown = picker ? picker.querySelector('[data-search-dropdown="1"]') : null;
        if (!dropdown) {
            return;
        }

        const kind = input.dataset.searchKind || 'devices';
        dropdown.innerHTML = renderSearchSuggestions(kind, String(input.value || ''));
        dropdown.classList.remove('hidden');
    });

    document.addEventListener('focusin', (e) => {
        const input = e.target.closest && e.target.closest('[data-search-input="1"]');
        if (!input) {
            return;
        }

        const picker = input.closest('[data-search-picker]');
        const dropdown = picker ? picker.querySelector('[data-search-dropdown="1"]') : null;
        if (!dropdown) {
            return;
        }

        const kind = input.dataset.searchKind || 'devices';
        dropdown.innerHTML = renderSearchSuggestions(kind, String(input.value || ''));
        dropdown.classList.remove('hidden');
    });

    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-search-picker]')) {
            return;
        }

        document.querySelectorAll('[data-search-dropdown="1"]').forEach((dropdown) => {
            dropdown.classList.add('hidden');
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            window.portViewApp.closeChainDetail();
        }
    });

    // Export global functions for pagination
    window.portViewApp = {
        goToPage: (page) => {
            currentPage = page;
            loadTable();
        },
        openChainDetail: (index) => {
            const chain = visibleChainsCache[index] || [];
            currentDetailChain = chain;
            currentDetailConnectionUuid = (summarizeChain(chain).coreConn && summarizeChain(chain).coreConn.connection_uuid) || (chain[0] && chain[0].connection_uuid) || '';
            editingConnectionUuid = '';
            detailNotice = '';
            renderCurrentDetail();
        },
        exportCsv: () => {
            exportVisibleChainsAsCsv();
        },
        closeChainDetail: () => {
            const dialog = document.getElementById('chainDetailDialog');
            dialog.classList.add('hidden');
            dialog.dataset.open = '0';
            currentDetailChain = [];
            currentDetailConnectionUuid = '';
            editingConnectionUuid = '';
            detailNotice = '';
        }
    };

    document.getElementById('exportCsvButton').addEventListener('click', () => {
        window.portViewApp.exportCsv();
    });

    document.getElementById('customizeColumnsButton').addEventListener('click', () => {
        openPortviewColumnPicker();
    });

    bindFilterControls();
    ensurePortviewSortKeyVisible();

    // Initial load
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
    loadTable();
})();
</script>
<?php
    include_once 'includes/footer.php';
?>
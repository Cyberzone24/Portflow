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
?>

<div class="mx-4 mb-4 mt-0 rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Port View</h1>
            <p class="text-sm text-slate-500">Kompakte Portübersicht von Switch bis Endgerät</p>
        </div>
        <form id="searchForm" class="flex min-w-[18rem] flex-1 items-center justify-end gap-2">
            <input
                type="text"
                name="search"
                placeholder="Suchen ..."
                class="w-full max-w-md rounded-full border border-slate-300 px-4 py-2.5 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-400"
            >
            <button
                type="submit"
                class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-blue-500 text-white shadow-md transition hover:bg-blue-700"
                title="Suche"
            >
                <i data-lucide="search"></i>
            </button>
        </form>
        <button id="exportCsvButton" type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100" title="CSV exportieren">
            <i data-lucide="file-down" class="h-4 w-4"></i>
            <span>CSV exportieren</span>
        </button>
    </div>

    <div class="mb-3 grid items-center gap-3 md:grid-cols-[1fr_auto_1fr]">
        <div class="inline-flex flex-wrap items-center gap-4">
            <p id="count" class="text-sm font-semibold text-slate-700">Datensätze: 0</p>
            <p id="resultSummary" class="text-sm text-slate-500">Zeige 0-0 von 0</p>
        </div>
        <div id="pagination" class="flex flex-row justify-center"></div>
        <div class="inline-flex items-center justify-end gap-2 text-sm">
            <label for="table_limit_1" class="text-slate-600">Anzahl:</label>
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
                <div class="text-sm font-semibold text-slate-900">Filter</div>
                <div class="text-xs text-slate-500">Portketten nach Raum, Endgeraet oder VLAN eingrenzen.</div>
            </div>
            <button id="resetFiltersButton" type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-100">
                <i data-lucide="rotate-ccw" class="h-4 w-4"></i>
                <span>Filter zuruecksetzen</span>
            </button>
        </div>
        <div class="grid gap-3 md:grid-cols-3">
            <label class="flex flex-col gap-1 text-sm text-slate-600">
                <span>Ort</span>
                <select id="filterRoom" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="">Alle Orte</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm text-slate-600">
                <span>Gerätetyp</span>
                <select id="filterEndpointType" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="">Alle Gerätetypen</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm text-slate-600">
                <span>VLAN</span>
                <select id="filterVlan" class="rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="">Alle VLANs</option>
                </select>
            </label>
        </div>
    </div>

    <div class="flex min-h-0 flex-1 overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm">
        <div class="h-full w-full overflow-x-auto overflow-y-auto">
            <table class="w-full min-w-[1800px] table-auto text-left text-sm text-gray-500 shadow-md">
                <thead id="portviewTableHead" class="sticky top-0 z-10 bg-white text-gray-800"></thead>
                <tbody id="portviewTableBody"></tbody>
            </table>
        </div>
    </div>

    <div id="chainDetailDialog" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-950/60 p-4">
        <div class="mx-auto mt-8 flex max-h-[85vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Detailansicht</h2>
                    <p class="text-sm text-slate-500">Verbindung und beteiligte Geräte</p>
                </div>
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-red-500 text-white shadow-sm hover:bg-red-700" title="Schließen" onclick="window.portViewApp.closeChainDetail()"><i data-lucide="x" class="h-4 w-4"></i></button>
            </div>
            <div id="chainDetailBody" class="min-h-0 flex-1 space-y-3 overflow-y-auto p-5"></div>
        </div>
    </div>

    <div class="mt-3 grid items-center gap-3 md:grid-cols-[1fr_auto_1fr]">
        <div class="inline-flex flex-wrap items-center gap-4">
            <p id="count_bottom" class="text-sm font-semibold text-slate-700">Datensätze: 0</p>
            <p id="resultSummaryBottom" class="text-sm text-slate-500">Zeige 0-0 von 0</p>
        </div>
        <div id="pagination_bottom" class="flex flex-row justify-center"></div>
        <div class="inline-flex items-center justify-end gap-2 text-sm">
            <label for="table_limit_2" class="text-slate-600">Anzahl:</label>
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

    const PORTVIEW_COLUMNS = [
        { key: 'statusValue', label: 'Status' },
        { key: 'switchCaption', label: 'Switch' },
        { key: 'switchPortCaption', label: 'Switch-Port' },
        { key: 'patchpanelCaption', label: 'Patchpanel' },
        { key: 'patchpanelPortCaption', label: 'PP-Port' },
        { key: 'cableName', label: 'Kabel' },
        { key: 'roomCaption', label: 'Raum' },
        { key: 'wallplateCaption', label: 'Wallplate' },
        { key: 'wallplatePortCaption', label: 'WP-Port' },
        { key: 'endpointCaption', label: 'Endgerät' },
        { key: 'endpointPortCaption', label: 'EP-Port' },
        { key: 'endpointHostname', label: 'Hostname' },
        { key: 'endpointMac', label: 'MAC' },
        { key: 'vlanTaggedText', label: 'VLAN (tagged)' },
        { key: 'vlanUntaggedText', label: 'VLAN (untagged)' }
    ];

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
        if (type.includes('phone')) {
            return '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.89.33 1.76.63 2.58a2 2 0 0 1-.45 2.11L8 9.71a16 16 0 0 0 6.29 6.29l1.3-1.29a2 2 0 0 1 2.11-.45c.82.3 1.69.51 2.58.63A2 2 0 0 1 22 16.92z"/></svg>';
        }
        if (type.includes('laptop') || type.includes('notebook')) {
            return '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M2 20h20"/></svg>';
        }
        if (type.includes('computer') || type.includes('pc') || type.includes('desktop')) {
            return '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M8 20h8"/><path d="M12 18v2"/></svg>';
        }
        if (type.includes('printer')) {
            return '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V4h12v5"/><rect x="6" y="14" width="12" height="6" rx="2"/><path d="M6 11H5a2 2 0 0 0-2 2v2h18v-2a2 2 0 0 0-2-2h-1"/></svg>';
        }
        if (type.includes('tv') || type.includes('monitor')) {
            return '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="12" rx="2"/><path d="M8 21h8"/></svg>';
        }
        return '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
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
                throw new Error(data?.error || data?.details || 'Update fehlgeschlagen');
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
                throw new Error(data?.error || data?.details || 'Erstellen fehlgeschlagen');
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
                    throw new Error(data?.error || data?.details || ('Abruf fehlgeschlagen: ' + resource));
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
                    kind: 'Port',
                    meta: metaParts.join(' · ')
                });
            });

            fullPortSearchEntriesCache = entries;
            return entries;
        }).catch((error) => {
            console.error('Vollständiger Portsuchindex konnte nicht geladen werden:', error);
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

    function summarizeChain(chain) {
        let switchConn = null;
        let coreConn = null;
        let endpointConn = null;

        for (const conn of chain) {
            const srcType = String(conn.src_device_type || '').toLowerCase();
            const dstType = String(conn.dst_device_type || '').toLowerCase();

            if ((srcType === 'switch' && dstType === 'patchpanel') || (srcType === 'patchpanel' && dstType === 'switch')) {
                switchConn = conn;
            } else if ((srcType === 'patchpanel' && dstType === 'net_outlet') || (srcType === 'net_outlet' && dstType === 'patchpanel')) {
                coreConn = conn;
            } else if (!['switch', 'patchpanel', 'net_outlet'].includes(srcType) || !['switch', 'patchpanel', 'net_outlet'].includes(dstType)) {
                endpointConn = conn;
            }
        }

        const endpointSideIsSrc = endpointConn && !['switch', 'patchpanel', 'net_outlet'].includes(String(endpointConn.src_device_type || '').toLowerCase());
        const roomCaption = coreConn ? (String(coreConn.src_device_type || '').toLowerCase() === 'patchpanel' ? coreConn.dst_location_caption : coreConn.src_location_caption) : '--';
        const endpointType = endpointConn ? (endpointSideIsSrc ? endpointConn.src_device_type : endpointConn.dst_device_type) : '';
        const vlanTagged = switchConn ? (String(switchConn.src_device_type || '').toLowerCase() === 'switch' ? switchConn.src_vlan_tagged : switchConn.dst_vlan_tagged) : '';
        const vlanUntagged = switchConn ? (String(switchConn.src_device_type || '').toLowerCase() === 'switch' ? switchConn.src_vlan_untagged : switchConn.dst_vlan_untagged) : '';

        return {
            switchConn,
            coreConn,
            endpointConn,
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
        const endpointSideIsSrc = endpointConn && !['switch', 'patchpanel', 'net_outlet'].includes(String(endpointConn.src_device_type || '').toLowerCase());

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
        const endpointHostname = endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_hostname : endpointConn.dst_port_hostname) : '--';
        const endpointMac = endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_mac : endpointConn.dst_port_mac) : '--';
        const cableName = (switchConn && switchConn.cable_name) || (coreConn && coreConn.cable_name) || (endpointConn && endpointConn.cable_name) || '--';
        const connectionSpeed = (switchConn && switchConn.connection_speed) || (coreConn && coreConn.connection_speed) || (endpointConn && endpointConn.connection_speed) || '';

        return {
            summary,
            statusValue: statusValue === null || statusValue === undefined ? '' : String(statusValue),
            speedLabelText: speedLabel(connectionSpeed),
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

        return leftValue.localeCompare(rightValue, 'de', { numeric: true, sensitivity: 'base' });
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

        const cells = PORTVIEW_COLUMNS.map((column) => {
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
        const statusCell = `<span class="inline-flex items-center gap-2 ${statusClass(row.statusValue)}">${iconClass}<span class="text-slate-700">${escapeHtml(row.speedLabelText)}</span></span>`;

        return `
            <tr class="cursor-pointer border-b border-slate-200 transition hover:bg-slate-50" data-chain-index="${index}" onclick="window.portViewApp.openChainDetail(${index})">
                <td class="px-3 py-2.5 font-medium align-top whitespace-nowrap">${statusCell}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.switchCaption)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.switchPortCaption)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.patchpanelCaption)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.patchpanelPortCaption)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.cableName)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.roomCaption)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.wallplateCaption)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.wallplatePortCaption)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.endpointCaption)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.endpointPortCaption)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.endpointHostname)}</td>
                <td class="px-3 py-2.5 align-top whitespace-nowrap">${escapeHtml(row.endpointMac)}</td>
                <td class="px-3 py-2.5 text-xs align-top whitespace-nowrap">${escapeHtml(row.vlanTaggedText)}</td>
                <td class="px-3 py-2.5 text-xs align-top whitespace-nowrap">${escapeHtml(row.vlanUntaggedText)}</td>
            </tr>
        `;
    }

    function renderChainDetail(chain) {
        if (!chain || chain.length === 0) {
            return '<p class="text-sm text-slate-500">Keine Details vorhanden.</p>';
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
                        <div class="text-sm font-semibold text-slate-900">Kabel-Trace</div>
                        <div class="text-xs text-slate-500">Verfolgt den aktuellen Verbindungsweg ueber den vorhandenen Trace-Endpunkt.</div>
                    </div>
                    <button type="button" class="inline-flex h-9 items-center gap-2 rounded-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-100" data-action="reload-trace" title="Trace neu laden">
                        <i data-lucide="route"></i><span>Neu laden</span>
                    </button>
                </div>
                <div id="chainDetailTrace" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-500">Lade Kabelverlauf ...</div>
            </div>
        ` : '';

        const addPanel = `
            <div class="mb-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="mb-2 text-sm font-semibold text-slate-900">Verbindung hinzufügen</div>
                <form class="grid gap-3 text-sm md:grid-cols-2" data-connection-add-form="1">
                    ${createPickerMarkup('add_src_port_caption', 'Quelle Port', '', 'ports', 'Quell-Port suchen ...')}
                    ${createPickerMarkup('add_dst_port_caption', 'Ziel Port', '', 'ports', 'Ziel-Port suchen ...')}
                    <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span class="text-slate-500">Kabel</span>
                        <input name="add_cable_name" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="optional">
                    </label>
                    <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span class="text-slate-500">Geschwindigkeit</span>
                        <input name="add_speed" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="z.B. 1000">
                    </label>
                    <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span class="text-slate-500">Länge</span>
                        <input name="add_length" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="z.B. 12.5">
                    </label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-slate-700">
                        <input type="checkbox" name="add_crossover" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Crossover</span>
                    </label>
                    <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3 md:col-span-2">
                        <span class="text-slate-500">Typ</span>
                        <input name="add_type" class="rounded-lg border border-slate-300 px-3 py-2" placeholder="z.B. copper, fiber, trunk">
                    </label>
                    <div class="md:col-span-2">
                        <button type="button" class="inline-flex h-9 items-center gap-2 rounded-full bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700" data-action="add-connection" title="Verbindung hinzufügen">
                            <i data-lucide="plus"></i><span>Verbindung hinzufügen</span>
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
                            <div class="text-sm font-semibold text-slate-900">Bearbeiten</div>
                            <div class="text-xs text-slate-500">Suche nach Räumen, Geräten und Ports, dann gezielt speichern.</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="inline-flex h-9 items-center gap-2 rounded-full bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700" data-action="save-connection-edit" title="Speichern">
                                <i data-lucide="save"></i><span>Speichern</span>
                            </button>
                            <button type="button" class="inline-flex h-9 items-center gap-2 rounded-full border border-slate-300 px-3 text-sm font-semibold text-slate-700 hover:bg-slate-100" data-action="cancel-connection-edit" title="Abbrechen">
                                <i data-lucide="x"></i><span>Abbrechen</span>
                            </button>
                        </div>
                    </div>
                    <form class="grid gap-3 text-sm md:grid-cols-2" data-connection-edit-form="1" data-connection-uuid="${connectionUuid}">
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3 md:col-span-2">
                            <span class="text-slate-500">Kabel / Verbindung</span>
                            <input name="cable_name" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.cable_name || '')}">
                        </label>
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">Geschwindigkeit</span>
                            <input name="speed" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.connection_speed ?? '')}">
                        </label>
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">Typ</span>
                            <input name="type" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.connection_type || '')}">
                        </label>
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">Länge</span>
                            <input name="length" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.length || '')}">
                        </label>
                        ${canDeleteConnection ? `<div class="flex items-end gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <button type="button" class="inline-flex h-10 items-center gap-2 rounded-full bg-red-500 px-4 text-white hover:bg-red-600" data-action="delete-connection" data-connection-uuid="${connectionUuid}" title="Verbindung löschen">
                                <i data-lucide="trash-2"></i><span>Verbindung löschen</span>
                            </button>
                        </div>` : `<div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">Patchpanel zu Dose ist Kernverkabelung und kann hier nicht gelöscht werden.</div>`}
                        ${createPickerMarkup('src_location_caption', 'Quelle Ort', conn.src_location_caption || '', 'locations', 'Ort suchen ...')}
                        ${createPickerMarkup('dst_location_caption', 'Ziel Ort', conn.dst_location_caption || '', 'locations', 'Ort suchen ...')}
                        ${createPickerMarkup('src_device_caption', 'Quelle Gerät', conn.src_device_caption || '', 'devices', 'Gerät suchen ...')}
                        ${createPickerMarkup('dst_device_caption', 'Ziel Gerät', conn.dst_device_caption || '', 'devices', 'Gerät suchen ...')}
                        ${createPickerMarkup('src_port_caption', 'Quelle Port-Caption', conn.src_port_caption || '', 'ports', 'Port suchen ...')}
                        ${createPickerMarkup('dst_port_caption', 'Ziel Port-Caption', conn.dst_port_caption || '', 'ports', 'Port suchen ...')}
                        ${createPickerMarkup('src_port_hostname', 'Quelle Hostname', conn.src_port_hostname || '', 'ports', 'Hostname suchen ...')}
                        ${createPickerMarkup('dst_port_hostname', 'Ziel Hostname', conn.dst_port_hostname || '', 'ports', 'Hostname suchen ...')}
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">Quelle MAC</span>
                            <input name="src_port_mac" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.src_port_mac || '')}">
                        </label>
                        <label class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <span class="text-slate-500">Ziel MAC</span>
                            <input name="dst_port_mac" class="rounded-lg border border-slate-300 px-3 py-2" value="${escapeHtml(conn.dst_port_mac || '')}">
                        </label>
                    </form>
                </div>
            ` : '';

            return `
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 shadow-sm">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <div class="font-semibold text-slate-900">Verbindung ${index + 1}</div>
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            <span>${connectionUuid}</span>
                            <button type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 text-slate-600 hover:bg-white" title="Bearbeiten" data-action="edit-connection" data-connection-uuid="${connectionUuid}"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button>
                        </div>
                    </div>
                    <div class="grid gap-2 text-sm sm:grid-cols-2">
                        <div><span class="text-slate-500">Quelle:</span> ${srcCaption} / ${srcPort}</div>
                        <div><span class="text-slate-500">Ziel:</span> ${dstCaption} / ${dstPort}</div>
                        <div><span class="text-slate-500">Quelle Ort:</span> ${srcLocationCaption}</div>
                        <div><span class="text-slate-500">Ziel Ort:</span> ${dstLocationCaption}</div>
                        <div><span class="text-slate-500">Kabel:</span> ${cable}</div>
                        <div><span class="text-slate-500">Geschwindigkeit:</span> ${speed}</div>
                        <div><span class="text-slate-500">Status:</span> ${srcStatus}</div>
                        <div><span class="text-slate-500">VLAN tagged:</span> ${vlanTagged}</div>
                        <div><span class="text-slate-500">VLAN untagged:</span> ${vlanUntagged}</div>
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

        target.innerHTML = '<div class="text-sm text-slate-500">Lade Kabelverlauf ...</div>';
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
            target.innerHTML = '<div class="rounded-lg border border-red-300 bg-red-50 p-2 text-xs text-red-700">Fehler: ' + escapeHtml(error.message || error) + '</div>';
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
            return '<div class="rounded-lg border border-red-300 bg-red-50 p-2 text-xs text-red-700">' + escapeHtml((result && result.error) || 'Fehler') + '</div>';
        }

        if (result.kind === 'device') {
            const ports = Array.isArray(result.ports) ? result.ports : [];
            if (!ports.length) {
                return '<div class="text-xs text-slate-500">Keine verbundenen Ports.</div>';
            }
            return ports.map((portTrace) => '<div class="mb-3">' + renderTraceInlineHtml(portTrace) + '</div>').join('');
        }

        const startLabel = result.start && (result.start.cable_name || result.start.caption)
            ? '<div class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Start: ' + escapeHtml(result.start.cable_name || result.start.caption) + '</div>'
            : '';
        const branches = Array.isArray(result.branches) ? result.branches : [];
        if (!branches.length) {
            return startLabel + '<div class="text-xs text-slate-500">Keine weiteren Verbindungen ab diesem Punkt.</div>';
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
            return '<div class="mb-2"><div class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Pfad ' + (index + 1) + '</div><div class="flex flex-wrap items-stretch gap-1">' + items.join('') + '</div></div>';
        }).join('');
    }

    function traceNodePill(node) {
        if (!node || node.type !== 'port') {
            return '<div class="rounded-lg border border-slate-300 bg-slate-100 p-2 text-xs text-slate-700">Unbekannt</div>';
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
        const endpointBadge = node.endpoint ? '<span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-blue-700">Endpunkt</span>' : '';
        const truncated = node.truncated_reason ? '<div class="mt-1 text-[10px] text-amber-700">Hinweis: ' + escapeHtml(node.truncated_reason) + '</div>' : '';
        return '<div class="min-w-[170px] rounded-lg border border-slate-300 bg-white p-2 text-xs shadow-sm">'
            + '<div class="flex items-center justify-between gap-1"><div class="font-bold text-slate-900">' + escapeHtml(device.caption || 'Device') + endpointBadge + '</div>'
            + '<span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase ' + statusClassName + '">' + escapeHtml(device.type || '') + '</span></div>'
            + (location.caption ? '<div class="text-[10px] text-slate-500">Ort: ' + escapeHtml(location.caption) + '</div>' : '')
            + '<div class="mt-1 rounded bg-slate-50 px-1.5 py-0.5"><span class="font-semibold">Port:</span> ' + escapeHtml(node.caption || '—') + '</div>'
            + (node.ip ? '<div class="text-[10px] text-slate-600">IP: ' + escapeHtml(node.ip) + '</div>' : '')
            + truncated
            + '</div>';
    }

    function traceCableArrow(edge) {
        const parts = [];
        if (edge.cable_name) parts.push(escapeHtml(edge.cable_name));
        if (edge.cable_type) parts.push(escapeHtml(edge.cable_type));
        if (edge.length) parts.push(escapeHtml(edge.length) + ' m');
        const label = parts.length ? parts.join(' · ') : 'Kabel';
        return '<div class="flex flex-col items-center justify-center px-1 text-slate-500"><div class="text-[9px] uppercase tracking-wider">' + label + '</div>'
            + '<svg viewBox="0 0 60 12" width="60" height="12" class="my-0.5"><line x1="2" y1="6" x2="58" y2="6" stroke="#64748b" stroke-width="2" stroke-dasharray="4 3"/><polygon points="58,6 52,3 52,9" fill="#64748b"/></svg></div>';
    }

    async function saveConnectionEdit(form, extraPayload = {}) {
        const connectionUuid = String(form.dataset.connectionUuid || '');
        if (!connectionUuid) {
            detailNotice = 'Keine Verbindung zum Speichern gefunden.';
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
            detailNotice = 'Speichere Änderungen ...';
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
            detailNotice = 'Änderungen gespeichert.';
            await loadTable();
            renderCurrentDetail();
        } catch (error) {
            detailNotice = error?.message || 'Speichern fehlgeschlagen.';
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
            detailNotice = 'Bitte Quelle und Ziel über die Portsuche auswählen.';
            renderCurrentDetail();
            return;
        }

        if (sourcePortUuid === destinationPortUuid) {
            detailNotice = 'Quelle und Ziel dürfen nicht identisch sein.';
            renderCurrentDetail();
            return;
        }

        try {
            detailNotice = 'Erstelle neue Verbindung ...';
            renderCurrentDetail();

            const metadataPayload = {
                status: 0,
                caption: 'Portview Verbindung',
                description: 'Erstellt über Portview'
            };
            const metadataResult = await apiCreateResource('metadata', metadataPayload);
            const metadataUuid = extractUuidFromApiPayload(metadataResult);
            if (!metadataUuid) {
                throw new Error('Metadata für neue Verbindung konnte nicht erstellt werden');
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

            detailNotice = 'Verbindung hinzugefügt.';
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
            detailNotice = error?.message || 'Verbindung konnte nicht hinzugefügt werden.';
            renderCurrentDetail();
        }
    }

    async function deleteConnection(connectionUuid) {
        const connection = currentDetailChain.find((conn) => String(conn.connection_uuid || '') === String(connectionUuid || '')) || null;
        if (!connection) {
            detailNotice = 'Verbindung zum Löschen nicht gefunden.';
            renderCurrentDetail();
            return;
        }

        if (isCorePatchpanelOutletConnection(connection)) {
            detailNotice = 'Patchpanel zu Dose ist Kernverkabelung und kann hier nicht gelöscht werden.';
            renderCurrentDetail();
            return;
        }

        const confirmText = 'Möchten Sie diese Verbindung vollständig löschen?';

        if (!window.confirm(confirmText)) {
            return;
        }

        try {
            detailNotice = 'Lösche Verbindung ...';
            renderCurrentDetail();

            const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?' + new URLSearchParams({ table: 'connection', uuid: connectionUuid }).toString(), {
                method: 'DELETE'
            });

            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(payload?.error || payload?.details || 'Verbindung konnte nicht gelöscht werden');
            }

            editingConnectionUuid = '';
            detailNotice = 'Verbindung gelöscht.';
            currentDetailChain = [];
            currentDetailConnectionUuid = '';
            await loadTable();
            const dialog = document.getElementById('chainDetailDialog');
            dialog.classList.add('hidden');
            dialog.dataset.open = '0';
        } catch (error) {
            detailNotice = error?.message || 'Verbindung konnte nicht gelöscht werden.';
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

        let html = '<div class="inline-flex flex-wrap items-center gap-2"><span class="mr-1 text-sm text-slate-500">Seite:</span>';

        const groupSize = 10;
        const currentGroup = Math.floor((currentPage - 1) / groupSize);
        const groupStart = currentGroup * groupSize + 1;
        const groupEnd = Math.min(groupStart + groupSize - 1, totalPages);

        if (currentGroup > 0) {
            html += `<button type="button" class="inline-flex h-9 min-w-9 items-center justify-center rounded-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100" onclick="window.portViewApp.goToPage(${groupStart - 1})">←</button>`;
        }

        for (let i = groupStart; i <= groupEnd; i++) {
            const isActive = i === currentPage;
            html += `<button type="button" class="inline-flex h-9 min-w-9 items-center justify-center rounded-full px-3 text-sm font-semibold transition ${isActive ? 'bg-blue-500 text-white shadow-sm' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100'}" onclick="window.portViewApp.goToPage(${i})">${i}</button>`;
        }

        if (groupEnd < totalPages) {
            html += `<button type="button" class="inline-flex h-9 min-w-9 items-center justify-center rounded-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100" onclick="window.portViewApp.goToPage(${groupEnd + 1})">→</button>`;
        }

        html += '</div>';

        container.innerHTML = html;
        containerBottom.innerHTML = html;
    }

    function buildChains(results) {
        const chains = [];
        const usedUuids = new Set();

        const deviceTypeOf = (conn, side) => String(conn?.[side + '_device_type'] ?? '');
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
                    && ((candidateSrcPort === wallplatePortUuid && !['patchpanel', 'net_outlet', 'switch'].includes(candidateDstType))
                        || (candidateDstPort === wallplatePortUuid && !['patchpanel', 'net_outlet', 'switch'].includes(candidateSrcType)))
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
        return Array.from(new Set(values.filter(Boolean))).sort((a, b) => String(a).localeCompare(String(b), 'de'));
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
                    kind: 'Ort',
                    meta: conn.src_device_caption || conn.src_port_caption || ''
                });
                addItem(locations, {
                    uuid: conn.dst_location_uuid,
                    caption: conn.dst_location_caption,
                    kind: 'Ort',
                    meta: conn.dst_device_caption || conn.dst_port_caption || ''
                });

                addItem(devices, {
                    uuid: conn.src_device_uuid,
                    caption: conn.src_device_caption,
                    kind: 'Gerät',
                    meta: [conn.src_device_type, conn.src_location_caption].filter(Boolean).join(' · ')
                });
                addItem(devices, {
                    uuid: conn.dst_device_uuid,
                    caption: conn.dst_device_caption,
                    kind: 'Gerät',
                    meta: [conn.dst_device_type, conn.dst_location_caption].filter(Boolean).join(' · ')
                });

                addItem(ports, {
                    uuid: conn.src_port_uuid,
                    caption: conn.src_port_caption,
                    kind: 'Port',
                    meta: [conn.src_device_caption, conn.src_port_hostname, conn.src_port_mac].filter(Boolean).join(' · ')
                });
                addItem(ports, {
                    uuid: conn.dst_port_uuid,
                    caption: conn.dst_port_caption,
                    kind: 'Port',
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
            return '<div class="px-3 py-2 text-xs text-slate-500">Keine Treffer</div>';
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
                    <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800" data-search-clear="1" title="Feld leeren">
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

        const row = [
            switchConn ? (switchSideIsSrc ? switchConn.src_port_status : switchConn.dst_port_status) : '--',
            switchConn ? (switchSideIsSrc ? switchConn.src_device_caption : switchConn.dst_device_caption) : '--',
            switchConn ? (switchSideIsSrc ? switchConn.src_port_caption : switchConn.dst_port_caption) : '--',
            coreConn ? (coreSideIsSrc ? coreConn.src_device_caption : coreConn.dst_device_caption) : '--',
            coreConn ? (coreSideIsSrc ? coreConn.src_port_caption : coreConn.dst_port_caption) : '--',
            (switchConn && switchConn.cable_name) || (coreConn && coreConn.cable_name) || (endpointConn && endpointConn.cable_name) || '--',
            summary.roomCaption || '--',
            coreConn ? (coreSideIsSrc ? coreConn.dst_device_caption : coreConn.src_device_caption) : '--',
            coreConn ? (coreSideIsSrc ? coreConn.dst_port_caption : coreConn.src_port_caption) : '--',
            endpointConn ? (endpointSideIsSrc ? endpointConn.src_device_caption : endpointConn.dst_device_caption) : '--',
            endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_caption : endpointConn.dst_port_caption) : '--',
            endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_hostname : endpointConn.dst_port_hostname) : '--',
            endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_mac : endpointConn.dst_port_mac) : '--',
            summary.vlanTagged || '--',
            summary.vlanUntagged || '--'
        ];

        return row.map(csvEscape).join(',');
    }

    function exportVisibleChainsAsCsv() {
        const header = [
            'Status',
            'Switch',
            'Switch-Port',
            'Patchpanel',
            'PP-Port',
            'Kabel',
            'Raum',
            'Wallplate',
            'WP-Port',
            'Endgerät',
            'EP-Port',
            'Hostname',
            'MAC',
            'VLAN (tagged)',
            'VLAN (untagged)'
        ];

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

        fillSelect(roomSelect, rooms, currentRoom, 'Alle Orte');
        fillSelect(endpointSelect, endpointTypes, currentEndpointType, 'Alle Gerätetypen');
        fillSelect(vlanSelect, vlans, currentVlan, 'Alle VLANs');
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

    async function loadTable() {
        renderTableHeader();

        const params = new URLSearchParams({
            table: 'portview',
            limit: '5000',
            page: '1'
        });

        try {
            const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?' + params.toString());
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data?.error || data?.details || 'Portview konnte nicht geladen werden');
            }

            const tbody = document.getElementById('portviewTableBody');
            tbody.innerHTML = '';

            const items = Array.isArray(data.items) ? data.items : [];
            allChainsCache = buildChains(items);

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
                tbody.innerHTML = '<tr><td colspan="15" class="p-4 text-center text-slate-500">Keine Einträge gefunden</td></tr>';
            }

            const startIdx = (currentPage - 1) * currentLimit + 1;
            const endIdx = Math.min(currentPage * currentLimit, totalCount);
            const summaryText = `Zeige ${totalCount === 0 ? 0 : startIdx}-${endIdx} von ${totalCount}`;
            document.getElementById('count').textContent = `Datensätze: ${totalCount}`;
            document.getElementById('count_bottom').textContent = `Datensätze: ${totalCount}`;
            document.getElementById('resultSummary').textContent = summaryText;
            document.getElementById('resultSummaryBottom').textContent = summaryText;

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
            console.error('Error loading table:', err);
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

    bindFilterControls();

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
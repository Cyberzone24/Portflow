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

<div class="m-4 mt-0 rounded-2xl border border-slate-300 bg-white p-4 shadow-sm">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Port View</h1>
            <p class="text-sm text-slate-500">Verdichtete Portansicht mit Ketten-Aggregation.</p>
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
            <p id="count" class="text-sm font-semibold text-slate-700">Ketten: 0</p>
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

    <div class="overflow-x-auto rounded-xl border border-slate-300 shadow-sm">
        <table class="w-full min-w-[1800px] text-left text-sm text-slate-600">
            <thead class="bg-slate-200 text-slate-900">
                <tr>
                    <th scope="col" class="p-2">Status</th>
                    <th scope="col" class="p-2">Switch</th>
                    <th scope="col" class="p-2">Switch-Port</th>
                    <th scope="col" class="p-2">Patchpanel</th>
                    <th scope="col" class="p-2">PP-Port</th>
                    <th scope="col" class="p-2">Kabel</th>
                    <th scope="col" class="p-2">Raum</th>
                    <th scope="col" class="p-2">Wallplate</th>
                    <th scope="col" class="p-2">WP-Port</th>
                    <th scope="col" class="p-2">Endgerät</th>
                    <th scope="col" class="p-2">EP-Port</th>
                    <th scope="col" class="p-2">Hostname</th>
                    <th scope="col" class="p-2">MAC</th>
                    <th scope="col" class="p-2">VLAN (tagged)</th>
                    <th scope="col" class="p-2">VLAN (untagged)</th>
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
    let currentSort = 'src_device_caption';
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

    function buildRowForChain(chain) {
        let switchConn = null;
        let coreConn = null;
        let endpointConn = null;

        for (let conn of chain) {
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

        const switchSideIsSrc = switchConn && String(switchConn.src_device_type || '').toLowerCase() === 'switch';
        const coreSideIsSrc = coreConn && String(coreConn.src_device_type || '').toLowerCase() === 'patchpanel';
        const endpointSideIsSrc = endpointConn && !['switch', 'patchpanel', 'net_outlet'].includes(String(endpointConn.src_device_type || '').toLowerCase());

        const switchCaption = switchConn ? (switchSideIsSrc ? switchConn.src_device_caption : switchConn.dst_device_caption) : '--';
        const switchPortCaption = switchConn ? (switchSideIsSrc ? switchConn.src_port_caption : switchConn.dst_port_caption) : '--';
        const switchPortStatus = switchConn ? (switchSideIsSrc ? switchConn.src_port_status : switchConn.dst_port_status) : '';
        const switchVlanTagged = switchConn ? (switchSideIsSrc ? switchConn.src_vlan_tagged : switchConn.dst_vlan_tagged) : '';
        const switchVlanUntagged = switchConn ? (switchSideIsSrc ? switchConn.src_vlan_untagged : switchConn.dst_vlan_untagged) : '';

        const patchpanelCaption = coreConn ? (coreSideIsSrc ? coreConn.src_device_caption : coreConn.dst_device_caption) : '--';
        const patchpanelPortCaption = coreConn ? (coreSideIsSrc ? coreConn.src_port_caption : coreConn.dst_port_caption) : '--';
        const roomCaption = coreConn ? (coreSideIsSrc ? coreConn.dst_location_caption : coreConn.src_location_caption) : '--';
        const wallplateCaption = coreConn ? (coreSideIsSrc ? coreConn.dst_device_caption : coreConn.src_device_caption) : '--';
        const wallplatePortCaption = coreConn ? (coreSideIsSrc ? coreConn.dst_port_caption : coreConn.src_port_caption) : '--';

        const endpointCaption = endpointConn ? (endpointSideIsSrc ? endpointConn.src_device_caption : endpointConn.dst_device_caption) : '--';
        const endpointType = endpointConn ? (endpointSideIsSrc ? endpointConn.src_device_type : endpointConn.dst_device_type) : '';
        const endpointPortCaption = endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_caption : endpointConn.dst_port_caption) : '--';
        const endpointHostname = endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_hostname : endpointConn.dst_port_hostname) : '--';
        const endpointMac = endpointConn ? (endpointSideIsSrc ? endpointConn.src_port_mac : endpointConn.dst_port_mac) : '--';

        const cableName = (switchConn && switchConn.cable_name) || (coreConn && coreConn.cable_name) || (endpointConn && endpointConn.cable_name) || '--';
        const connectionSpeed = (switchConn && switchConn.connection_speed) || (coreConn && coreConn.connection_speed) || (endpointConn && endpointConn.connection_speed) || '';
        const speedLabelText = speedLabel(connectionSpeed);
        const statusValue = switchPortStatus || '';
        const iconClass = deviceIcon(endpointType);
        const vlanTaggedText = switchVlanTagged ? String(switchVlanTagged) : '--';
        const vlanUntaggedText = switchVlanUntagged ? String(switchVlanUntagged) : '--';
        const statusCell = `<span class="inline-flex items-center gap-2 ${statusClass(statusValue)}">${deviceIcon(endpointType)}<span class="text-slate-700">${escapeHtml(speedLabelText)}</span></span>`;

        return `
            <tr class="border-t border-slate-200 hover:bg-slate-50">
                <td class="p-2 font-medium">${statusCell}</td>
                <td class="p-2">${escapeHtml(switchCaption)}</td>
                <td class="p-2">${escapeHtml(switchPortCaption)}</td>
                <td class="p-2">${escapeHtml(patchpanelCaption)}</td>
                <td class="p-2">${escapeHtml(patchpanelPortCaption)}</td>
                <td class="p-2">${escapeHtml(cableName)}</td>
                <td class="p-2">${escapeHtml(roomCaption)}</td>
                <td class="p-2">${escapeHtml(wallplateCaption)}</td>
                <td class="p-2">${escapeHtml(wallplatePortCaption)}</td>
                <td class="p-2">${escapeHtml(endpointCaption)}</td>
                <td class="p-2">${escapeHtml(endpointPortCaption)}</td>
                <td class="p-2">${escapeHtml(endpointHostname)}</td>
                <td class="p-2">${escapeHtml(endpointMac)}</td>
                <td class="p-2 text-xs">${escapeHtml(vlanTaggedText)}</td>
                <td class="p-2 text-xs">${escapeHtml(vlanUntaggedText)}</td>
            </tr>
        `;
    }

    function renderPagination(totalCount) {
        const totalPages = Math.ceil(totalCount / currentLimit);
        if (totalPages <= 1) return;

        const container = document.getElementById('pagination');
        const containerBottom = document.getElementById('pagination_bottom');
        let html = '';

        const groupSize = 10;
        const currentGroup = Math.floor((currentPage - 1) / groupSize);
        const groupStart = currentGroup * groupSize + 1;
        const groupEnd = Math.min(groupStart + groupSize - 1, totalPages);

        if (currentGroup > 0) {
            html += `<button class="rounded border border-slate-300 px-2 py-1 hover:bg-slate-100" onclick="window.portViewApp.goToPage(${groupStart - 1})">← Prev</button>`;
        }

        for (let i = groupStart; i <= groupEnd; i++) {
            const isActive = i === currentPage;
            html += `<button class="rounded ${isActive ? 'bg-blue-500 text-white' : 'border border-slate-300 hover:bg-slate-100'} px-2 py-1" onclick="window.portViewApp.goToPage(${i})">${i}</button>`;
        }

        if (groupEnd < totalPages) {
            html += `<button class="rounded border border-slate-300 px-2 py-1 hover:bg-slate-100" onclick="window.portViewApp.goToPage(${groupEnd + 1})">Next →</button>`;
        }

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

    function loadTable() {
        const params = new URLSearchParams({
            table: 'portview',
            limit: '5000',
            page: '1'
        });

        if (currentQuery.trim() !== '') {
            params.set('search', currentQuery.trim());
        }

        fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?' + params.toString())
            .then(r => r.json())
            .then(data => {
                const tbody = document.getElementById('portviewTableBody');
                tbody.innerHTML = '';

                const items = Array.isArray(data.items) ? data.items : [];
                const allChains = buildChains(items);
                const totalCount = allChains.length;
                const start = (currentPage - 1) * currentLimit;
                const chains = allChains.slice(start, start + currentLimit);

                if (chains.length > 0) {
                    chains.forEach(chain => {
                        tbody.innerHTML += buildRowForChain(chain);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="15" class="p-4 text-center text-slate-500">Keine Einträge gefunden</td></tr>';
                }

                const startIdx = (currentPage - 1) * currentLimit + 1;
                const endIdx = Math.min(currentPage * currentLimit, totalCount);
                document.getElementById('count').textContent = `Ketten: ${totalCount}`;
                document.getElementById('resultSummary').textContent = `Zeige ${totalCount === 0 ? 0 : startIdx}-${endIdx} von ${totalCount}`;

                renderPagination(totalCount);
            })
            .catch(err => console.error('Error loading table:', err));
    }

    // Event handlers
    document.getElementById('searchForm').addEventListener('submit', (e) => {
        e.preventDefault();
        currentQuery = document.querySelector('input[name="search"]').value;
        currentPage = 1;
        loadTable();
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

    // Export global functions for pagination
    window.portViewApp = {
        goToPage: (page) => {
            currentPage = page;
            loadTable();
        }
    };

    // Initial load
    loadTable();
})();
</script>
<?php
    include_once 'includes/footer.php';
?>
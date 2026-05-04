/**
 * PortflowSwitch2D — renders a 2D faceplate of a switch / patchpanel device.
 *
 * Usage:
 *   PortflowSwitch2D.render(container, {
 *     deviceUuid:   '<uuid>',
 *     onPortClick:  (portRow) => { ... },   // optional, default: open cable trace
 *     showStack:    true,                    // default true: render all stack members
 *     showLegend:   true,                    // default true
 *   });
 *
 * The module is self-contained: it fetches device + ports + VLANs + SNMP
 * state via the standard /api endpoints. Rendering is plain SVG so the
 * same module can be embedded anywhere (itam detail popup, port detail
 * view, cable detail view, etc.).
 *
 * Status colors:
 *   - green  : oper UP                      (snmp.oper_status === 1)
 *   - amber  : admin UP / oper DOWN         (admin 1, oper 2/down)
 *   - red    : admin DOWN                   (admin 2)
 *   - slate  : unknown / no SNMP data
 */
(function (root) {
    'use strict';

    const API_BASE = (typeof window !== 'undefined' && window.PORTFLOW_API_BASE)
        ? String(window.PORTFLOW_API_BASE).replace(/\/+$/, '')
        : '/api';

    const PORT_PX_PER_MM = 1.5;     // scale factor for port positions (mm -> px)
    const PORT_DEFAULT_W = 14;       // mm
    const PORT_DEFAULT_H = 14;       // mm
    const FACEPLATE_PAD  = 14;       // px margin around each device

    function decodeHtmlEntities(str) {
        // The Portflow API returns JSON columns HTML-escaped (e.g. &quot;).
        // Decode common entities before attempting JSON.parse.
        return String(str)
            .replace(/&quot;/g, '"')
            .replace(/&#34;/g, '"')
            .replace(/&#039;/g, "'")
            .replace(/&#39;/g, "'")
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&amp;/g, '&');
    }

    function safeJsonParse(raw, fallback) {
        if (raw == null) return fallback;
        if (typeof raw === 'object') return raw;
        const str = String(raw);
        try { return JSON.parse(str); } catch (_e1) {
            try { return JSON.parse(decodeHtmlEntities(str)); } catch (_e2) { return fallback; }
        }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function classifyPortStatus(snmp) {
        if (!snmp || ((snmp.if_admin_status == null && snmp.admin_status == null) && (snmp.if_oper_status == null && snmp.oper_status == null))) {
            return 'unknown';
        }
        const adminStatus = Number(snmp.if_admin_status ?? snmp.admin_status);
        const operStatus = Number(snmp.if_oper_status ?? snmp.oper_status);
        if (adminStatus === 2) return 'admin_down';
        if (operStatus  === 1) return 'up';
        if (operStatus  === 2) return 'down';
        return 'unknown';
    }

    const STATUS_STYLE = {
        up:         { fill: '#16a34a', stroke: '#166534', label: 'Up' },
        down:       { fill: '#f59e0b', stroke: '#b45309', label: 'Down' },
        admin_down: { fill: '#dc2626', stroke: '#991b1b', label: 'Admin-Down' },
        unknown:    { fill: '#94a3b8', stroke: '#475569', label: 'Unbekannt' },
    };

    async function apiGet(path) {
        const url = path.startsWith('http') ? path : (API_BASE + path);
        const r = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status + ' for ' + url);
        return r.json();
    }

    async function loadStackDevices(deviceUuid) {
        // Load the requested device. If it has an item_group, also load all
        // sibling devices that share the same item_group (stack members).
        const dev = await apiGet('/?table=device_details&device_uuid=' + encodeURIComponent(deviceUuid));
        const baseRows = (dev && (dev.items || dev.data)) || (Array.isArray(dev) ? dev : []);
        const baseRow = baseRows[0] || null;
        if (!baseRow) throw new Error('Device not found: ' + deviceUuid);

        const itemGroup = baseRow.device_item_group || baseRow.item_group || null;
        if (!itemGroup) return [baseRow];

        // Try to fetch siblings via filter; fall back to client-side filtering.
        try {
            const siblings = await apiGet('/?table=device_details&device_item_group=' + encodeURIComponent(itemGroup) + '&limit=64');
            const rows = (siblings && (siblings.items || siblings.data)) || [];
            const matched = rows.filter(r => String(r.device_item_group || r.item_group) === String(itemGroup));
            if (matched.length > 0) {
                matched.sort((a, b) => String(a.device_metadata_caption || '').localeCompare(String(b.device_metadata_caption || '')));
                return matched;
            }
        } catch (_e) { /* fall through */ }
        return [baseRow];
    }

    // Default Apache/nginx accept ~8 KiB request lines. A UUID is 36 chars;
    // chunked at 50 keeps each query well below ~2 KiB even with the longest
    // filter parameter name we use.
    const UUID_CHUNK_SIZE = 50;

    function chunkArray(arr, size) {
        const out = [];
        for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
        return out;
    }

    async function fetchInBatches(uuids, buildPath) {
        const items = [];
        for (const chunk of chunkArray(uuids, UUID_CHUNK_SIZE)) {
            const payload = await apiGet(buildPath(chunk.join(',')));
            const rows = (payload && (payload.items || payload.data)) || [];
            items.push(...rows);
        }
        return items;
    }

    function pickFirstValue(row, keys) {
        for (const key of keys) {
            if (row && row[key] != null && row[key] !== '') {
                return row[key];
            }
        }
        return null;
    }

    function normalizeSnmpRow(row) {
        if (!row || typeof row !== 'object') {
            return null;
        }

        const devicePort = pickFirstValue(row, [
            'device_port',
            'device_port_uuid',
            'device_port_snmp_state_device_port',
            'device_port_snmp_state_device_port_uuid'
        ]);
        if (!devicePort) {
            return null;
        }

        return {
            device_port: String(devicePort),
            if_admin_status: pickFirstValue(row, ['if_admin_status', 'device_port_snmp_state_if_admin_status']),
            if_oper_status: pickFirstValue(row, ['if_oper_status', 'device_port_snmp_state_if_oper_status']),
            if_alias: pickFirstValue(row, ['if_alias', 'device_port_snmp_state_if_alias']),
            if_name: pickFirstValue(row, ['if_name', 'device_port_snmp_state_if_name']),
            if_speed: pickFirstValue(row, ['if_speed', 'device_port_snmp_state_if_speed'])
        };
    }

    async function loadDeviceContext(deviceUuids) {
        // Ports filtered by device uuids via *In suffix (API handles array filters).
        // Chunked to keep request URI short enough for typical web servers.
        const ports = await fetchInBatches(
            deviceUuids.map(String),
            (csv) => '/?table=device_port_details&device_port_deviceIn=' + encodeURIComponent(csv) + '&limit=5000'
        );
        const portUuids = ports.map(p => String(p.device_port_uuid || p.uuid || '')).filter(Boolean);

        const vlansByPort = new Map();
        if (portUuids.length) {
            try {
                const vlanRows = await fetchInBatches(
                    portUuids,
                    (csv) => '/?table=device_port_vlan_details&device_port_vlan_device_portIn=' + encodeURIComponent(csv) + '&limit=5000'
                );
                for (const v of vlanRows) {
                    const k = String(v.device_port_vlan_device_port || v.device_port || '');
                    if (!vlansByPort.has(k)) vlansByPort.set(k, []);
                    vlansByPort.get(k).push(v);
                }
            } catch (_e) { /* VLAN optional */ }
        }

        // SNMP state — best effort: the table device_port_snmp_state has no
        // *_details view registered, so query the raw table and filter server-side.
        const snmpByPort = new Map();
        if (portUuids.length) {
            try {
                const snmp = await fetchInBatches(
                    portUuids,
                    (csv) => '/?table=device_port_snmp_state&device_portIn=' + encodeURIComponent(csv) + '&limit=5000'
                );
                for (const rawRow of snmp) {
                    const s = normalizeSnmpRow(rawRow);
                    if (!s) continue;
                    snmpByPort.set(s.device_port, s);
                }
            } catch (_e) { /* SNMP optional */ }

            if (snmpByPort.size === 0) {
                try {
                    const snmpDetails = await fetchInBatches(
                        portUuids,
                        (csv) => '/?table=device_port_snmp_state_details&device_port_snmp_state_device_portIn=' + encodeURIComponent(csv) + '&limit=5000'
                    );
                    for (const rawRow of snmpDetails) {
                        const s = normalizeSnmpRow(rawRow);
                        if (!s) continue;
                        snmpByPort.set(s.device_port, s);
                    }
                } catch (_e) { /* SNMP optional */ }
            }
        }

        return { ports, vlansByPort, snmpByPort };
    }

    function normalizePortGeometry(portRow) {
        const pos  = safeJsonParse(portRow.device_port_position || portRow.position, {});
        const size = safeJsonParse(portRow.device_port_size     || portRow.size,     {});
        return {
            x:    Number(pos.x || 0),
            y:    Number(pos.y || 0),
            side: String(pos.side || 'front').toLowerCase(),
            w:    Number(size.x || PORT_DEFAULT_W),
            h:    Number(size.y || PORT_DEFAULT_H),
        };
    }

    function summarizeVlans(vlanRows) {
        const untagged = [];
        const tagged   = [];
        for (const v of (vlanRows || [])) {
            const id = v.device_port_vlan_vlan_vlan || v.vlan_vlan || v.vlan || null;
            const isTagged = String(v.device_port_vlan_tagged || v.tagged) === 'true' || v.device_port_vlan_tagged === true;
            if (id == null || id === '') continue;
            (isTagged ? tagged : untagged).push(String(id));
        }
        return { untagged, tagged };
    }

    function buildTooltip(portRow, snmp, vlans) {
        const cap = portRow.device_port_metadata_caption || portRow.metadata_caption || 'Port';
        const status = STATUS_STYLE[classifyPortStatus(snmp)].label;
        const speed = portRow.device_port_speed || snmp?.if_speed || null;
        const ip   = portRow.device_port_device_port_ip_ip || null;
        const mac  = portRow.device_port_mac_address || null;
        const alias = snmp?.if_alias || null;
        const lines = [
            '<div class="font-bold">' + escapeHtml(cap) + '</div>',
            '<div class="text-xs text-slate-300">Status: ' + escapeHtml(status) + '</div>',
        ];
        if (alias) lines.push('<div class="text-xs">Alias: ' + escapeHtml(alias) + '</div>');
        if (speed) lines.push('<div class="text-xs">Speed: ' + escapeHtml(speed) + '</div>');
        if (ip)    lines.push('<div class="text-xs">IP: '    + escapeHtml(ip)    + '</div>');
        if (mac)   lines.push('<div class="text-xs">MAC: '   + escapeHtml(mac)   + '</div>');
        if (vlans.untagged.length) lines.push('<div class="text-xs">VLAN (untagged): ' + escapeHtml(vlans.untagged.join(', ')) + '</div>');
        if (vlans.tagged.length)   lines.push('<div class="text-xs">VLAN (tagged): '   + escapeHtml(vlans.tagged.join(', '))   + '</div>');
        return lines.join('');
    }

    function renderFaceplate(deviceRow, ports, ctx, opts) {
        const wrapper = document.createElement('div');
        wrapper.className = 'pf-switch2d-device rounded-xl border border-slate-300 bg-slate-900 text-slate-100 shadow-sm overflow-hidden';

        const header = document.createElement('div');
        header.className = 'flex items-center justify-between gap-3 border-b border-slate-700 bg-slate-800 px-3 py-2';
        const cap = deviceRow.device_metadata_caption || deviceRow.metadata_caption || 'Device';
        const subType = (deviceRow.device_type || '').toString();
        header.innerHTML = '<div class="flex flex-col"><div class="text-sm font-bold">' + escapeHtml(cap) + '</div>' +
            (subType ? '<div class="text-[11px] uppercase tracking-wider text-slate-400">' + escapeHtml(subType) + '</div>' : '') + '</div>';
        wrapper.appendChild(header);

        // Compute bounds (front side only — we keep it simple).
        const frontPorts = ports.filter(p => normalizePortGeometry(p).side === 'front');
        const portsToDraw = frontPorts.length ? frontPorts : ports;
        let maxX = 0, maxY = 0;
        for (const p of portsToDraw) {
            const g = normalizePortGeometry(p);
            maxX = Math.max(maxX, g.x + g.w);
            maxY = Math.max(maxY, g.y + g.h);
        }
        const svgW = Math.max(160, Math.ceil(maxX * PORT_PX_PER_MM) + FACEPLATE_PAD * 2);
        const svgH = Math.max(60,  Math.ceil(maxY * PORT_PX_PER_MM) + FACEPLATE_PAD * 2);

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 ' + svgW + ' ' + svgH);
        svg.setAttribute('width', String(svgW));
        svg.setAttribute('height', String(svgH));
        svg.setAttribute('class', 'block bg-slate-950');

        for (const p of portsToDraw) {
            const g = normalizePortGeometry(p);
            const portUuid = String(p.device_port_uuid || p.uuid || '');
            const snmp = ctx.snmpByPort.get(portUuid) || null;
            const vlanRows = ctx.vlansByPort.get(portUuid) || [];
            const vlans = summarizeVlans(vlanRows);
            const status = classifyPortStatus(snmp);
            const style = STATUS_STYLE[status];

            const x = FACEPLATE_PAD + g.x * PORT_PX_PER_MM;
            const y = FACEPLATE_PAD + g.y * PORT_PX_PER_MM;
            const w = Math.max(8, g.w * PORT_PX_PER_MM);
            const h = Math.max(8, g.h * PORT_PX_PER_MM);

            const groupEl = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            groupEl.setAttribute('class', 'pf-switch2d-port cursor-pointer');
            groupEl.setAttribute('data-port-uuid', portUuid);

            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x', String(x));
            rect.setAttribute('y', String(y));
            rect.setAttribute('width',  String(w));
            rect.setAttribute('height', String(h));
            rect.setAttribute('rx', '2');
            rect.setAttribute('fill',   style.fill);
            rect.setAttribute('stroke', style.stroke);
            rect.setAttribute('stroke-width', '1');
            groupEl.appendChild(rect);

            // Port label below the port
            const cap = p.device_port_metadata_caption || p.metadata_caption || '';
            if (cap && h >= 12) {
                const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                text.setAttribute('x', String(x + w / 2));
                text.setAttribute('y', String(y + h / 2 + 3));
                text.setAttribute('text-anchor', 'middle');
                text.setAttribute('font-size', '7');
                text.setAttribute('fill', '#0f172a');
                text.setAttribute('font-weight', '700');
                text.textContent = cap.length > 4 ? cap.slice(-3) : cap;
                groupEl.appendChild(text);
            }

            // tooltip via title for accessibility + native fallback
            const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            title.textContent = (cap || 'Port') + ' — ' + style.label;
            groupEl.appendChild(title);

            // Rich tooltip on hover
            groupEl.addEventListener('mouseenter', (ev) => showTooltip(ev, buildTooltip(p, snmp, vlans)));
            groupEl.addEventListener('mouseleave', hideTooltip);
            groupEl.addEventListener('mousemove',  moveTooltip);

            // Click handler -> open trace (or custom)
            groupEl.addEventListener('click', () => {
                if (typeof opts.onPortClick === 'function') {
                    opts.onPortClick(p);
                } else if (root.PortflowCableTrace && typeof root.PortflowCableTrace.open === 'function') {
                    root.PortflowCableTrace.open({ kind: 'device_port', uuid: portUuid, label: cap });
                }
            });

            svg.appendChild(groupEl);
        }

        wrapper.appendChild(svg);
        return wrapper;
    }

    function renderLegend() {
        const el = document.createElement('div');
        el.className = 'flex flex-wrap items-center gap-3 text-xs text-slate-700';
        el.innerHTML = Object.entries(STATUS_STYLE).map(([_k, s]) =>
            '<span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:' + s.fill + ';border:1px solid ' + s.stroke + '"></span>' + escapeHtml(s.label) + '</span>'
        ).join('');
        return el;
    }

    // -------------------- shared tooltip --------------------
    let tooltipEl = null;
    function ensureTooltip() {
        if (tooltipEl) return tooltipEl;
        tooltipEl = document.createElement('div');
        tooltipEl.className = 'pf-switch2d-tooltip pointer-events-none fixed z-[1000] hidden rounded-lg bg-slate-900 px-3 py-2 text-slate-100 shadow-lg';
        tooltipEl.style.maxWidth = '320px';
        document.body.appendChild(tooltipEl);
        return tooltipEl;
    }
    function showTooltip(ev, html) {
        const t = ensureTooltip();
        t.innerHTML = html;
        t.classList.remove('hidden');
        moveTooltip(ev);
    }
    function moveTooltip(ev) {
        if (!tooltipEl) return;
        const pad = 12;
        let x = ev.clientX + pad;
        let y = ev.clientY + pad;
        const rect = tooltipEl.getBoundingClientRect();
        if (x + rect.width  > window.innerWidth)  x = ev.clientX - rect.width  - pad;
        if (y + rect.height > window.innerHeight) y = ev.clientY - rect.height - pad;
        tooltipEl.style.left = x + 'px';
        tooltipEl.style.top  = y + 'px';
    }
    function hideTooltip() { if (tooltipEl) tooltipEl.classList.add('hidden'); }

    // -------------------- public API --------------------
    async function render(container, opts) {
        opts = opts || {};
        if (typeof container === 'string') container = document.querySelector(container);
        if (!container) throw new Error('PortflowSwitch2D.render: container not found');
        if (!opts.deviceUuid) throw new Error('PortflowSwitch2D.render: deviceUuid required');

        container.innerHTML = '<div class="text-sm text-slate-500">Lade Switch-Daten ...</div>';

        try {
            const stack = (opts.showStack !== false)
                ? await loadStackDevices(opts.deviceUuid)
                : [(await loadStackDevices(opts.deviceUuid))[0]];

            const ctx = await loadDeviceContext(stack.map(d => d.device_uuid || d.uuid));
            const portsByDevice = new Map();
            for (const p of ctx.ports) {
                const k = String(p.device_port_device || p.device || '');
                if (!portsByDevice.has(k)) portsByDevice.set(k, []);
                portsByDevice.get(k).push(p);
            }

            container.innerHTML = '';
            const grid = document.createElement('div');
            grid.className = 'grid gap-3';
            for (const dev of stack) {
                const key = String(dev.device_uuid || dev.uuid);
                const ports = portsByDevice.get(key) || [];
                grid.appendChild(renderFaceplate(dev, ports, ctx, opts));
            }
            container.appendChild(grid);

            if (opts.showLegend !== false) {
                const legendWrap = document.createElement('div');
                legendWrap.className = 'mt-3 flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2';
                const legendLabel = document.createElement('div');
                legendLabel.className = 'text-xs font-semibold uppercase tracking-wider text-slate-500';
                legendLabel.textContent = 'Port-Status';
                legendWrap.appendChild(legendLabel);
                legendWrap.appendChild(renderLegend());
                container.appendChild(legendWrap);
            }
        } catch (e) {
            container.innerHTML = '<div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700">Switch-Ansicht konnte nicht geladen werden: ' + escapeHtml(e.message || e) + '</div>';
        }
    }

    root.PortflowSwitch2D = {
        render,
        classifyPortStatus,
        STATUS_STYLE,
    };

})(typeof window !== 'undefined' ? window : this);

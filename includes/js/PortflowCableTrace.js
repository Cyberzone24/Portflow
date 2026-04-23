/**
 * PortflowCableTrace — opens a modal showing the full cable trace from
 * any starting object (device_port, connection/cable, device).
 *
 * Usage:
 *   PortflowCableTrace.open({ kind: 'device_port', uuid: '<uuid>' });
 *   PortflowCableTrace.open({ kind: 'connection',  uuid: '<uuid>' });
 *
 * The module is independent and can be reused from any detail view in
 * itam.php (switch port, cable, patchpanel port, etc.).
 *
 * Calls GET /api/cable_trace?from=<uuid>&kind=<kind>.
 */
(function (root) {
    'use strict';

    const API_BASE = (typeof window !== 'undefined' && window.PORTFLOW_API_BASE)
        ? String(window.PORTFLOW_API_BASE).replace(/\/+$/, '')
        : '/api';

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function ensureModal() {
        let modal = document.getElementById('pf-cable-trace-modal');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'pf-cable-trace-modal';
        modal.className = 'fixed inset-0 z-[1100] hidden items-center justify-center bg-slate-900/70 p-4';
        modal.innerHTML = `
            <div class="flex h-full max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 text-blue-700"><i data-lucide="route" class="h-4 w-4"></i></span>
                        <div>
                            <div class="text-sm font-bold text-slate-900" id="pf-cable-trace-title">Kabelverlauf</div>
                            <div class="text-xs text-slate-500" id="pf-cable-trace-subtitle"></div>
                        </div>
                    </div>
                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-300 text-slate-800 hover:bg-slate-400" onclick="PortflowCableTrace.close()" aria-label="Schliessen"><i data-lucide="x" class="h-4 w-4"></i></button>
                </div>
                <div class="flex-1 overflow-auto p-4" id="pf-cable-trace-body">
                    <div class="text-sm text-slate-500">Lade Kabelverlauf ...</div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        if (root.lucide && typeof root.lucide.createIcons === 'function') root.lucide.createIcons();
        return modal;
    }

    function devicePill(node) {
        if (!node || node.type !== 'port') {
            return '<div class="rounded-lg border border-slate-300 bg-slate-100 p-3 text-xs text-slate-700">Unbekannt</div>';
        }
        const dev = node.device || {};
        const loc = node.location || {};
        const status = node.snmp && node.snmp.oper_status === 1 ? 'up'
            : node.snmp && node.snmp.admin_status === 2 ? 'admin_down'
            : node.snmp && node.snmp.oper_status === 2 ? 'down' : 'unknown';
        const statusColor = { up: 'bg-emerald-100 text-emerald-800', down: 'bg-amber-100 text-amber-800', admin_down: 'bg-red-100 text-red-800', unknown: 'bg-slate-100 text-slate-700' }[status];
        const endpointBadge = node.endpoint ? '<span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-blue-700">Endpunkt</span>' : '';
        const truncated = node.truncated_reason ? `<div class="mt-1 text-[11px] text-amber-700">⚠ Trace abgebrochen: ${escapeHtml(node.truncated_reason)}</div>` : '';
        return `
            <div class="min-w-[200px] rounded-xl border border-slate-300 bg-white p-3 shadow-sm">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-sm font-bold text-slate-900">${escapeHtml(dev.caption || 'Device')}${endpointBadge}</div>
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ${statusColor}">${escapeHtml(dev.type || '')}</span>
                </div>
                ${loc.caption ? `<div class="mt-1 text-xs text-slate-500">📍 ${escapeHtml(loc.caption)}</div>` : ''}
                <div class="mt-2 rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-700"><span class="font-semibold">Port:</span> ${escapeHtml(node.caption || '—')}</div>
                ${node.ip ? `<div class="mt-1 text-[11px] text-slate-600">IP: ${escapeHtml(node.ip)}</div>` : ''}
                ${node.hostname ? `<div class="text-[11px] text-slate-600">Host: ${escapeHtml(node.hostname)}</div>` : ''}
                ${node.snmp && node.snmp.if_alias ? `<div class="text-[11px] text-slate-600">Alias: ${escapeHtml(node.snmp.if_alias)}</div>` : ''}
                ${truncated}
            </div>
        `;
    }

    function cableArrow(edge) {
        const parts = [];
        if (edge.cable_name) parts.push(escapeHtml(edge.cable_name));
        if (edge.cable_type) parts.push(escapeHtml(edge.cable_type));
        if (edge.length)     parts.push(escapeHtml(edge.length) + ' m');
        const label = parts.length ? parts.join(' · ') : 'Kabel';
        return `
            <div class="flex flex-col items-center justify-center px-2 text-slate-500">
                <div class="text-[10px] uppercase tracking-wider">${label}</div>
                <svg viewBox="0 0 80 14" width="80" height="14" class="my-1"><line x1="2" y1="7" x2="78" y2="7" stroke="#64748b" stroke-width="2" stroke-dasharray="4 3"/><polygon points="78,7 70,3 70,11" fill="#64748b"/></svg>
            </div>
        `;
    }

    function renderBranchChain(startNode, branch) {
        const items = [];
        items.push(devicePill(startNode));
        for (const hop of branch) {
            items.push(cableArrow(hop.cable || {}));
            items.push(devicePill(hop.port));
        }
        return `<div class="flex flex-wrap items-stretch gap-1">${items.join('')}</div>`;
    }

    function renderTrace(result) {
        if (!result || result.error) {
            return '<div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700">Trace nicht verfuegbar: ' + escapeHtml(result && result.error || 'unbekannter Fehler') + '</div>';
        }
        const branches = Array.isArray(result.branches) ? result.branches : [];
        if (result.kind === 'device') {
            const ports = Array.isArray(result.ports) ? result.ports : [];
            if (!ports.length) return '<div class="text-sm text-slate-500">Keine verbundenen Ports gefunden.</div>';
            return ports.map(pt => '<div class="mb-4">' + renderTrace(pt) + '</div>').join('');
        }
        if (!branches.length) {
            return '<div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">Keine Verbindungen gefunden.</div>';
        }
        const startNode = result.start || null;
        if (result.kind === 'connection') {
            // For a connection we have two branches starting from the cable.
            const cableHeader = `
                <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-700">
                    <i data-lucide="cable" class="h-3.5 w-3.5"></i>
                    <span class="font-semibold">${escapeHtml(startNode.cable_name || startNode.caption || 'Kabel')}</span>
                    ${startNode.cable_type ? `<span class="text-slate-500">· ${escapeHtml(startNode.cable_type)}</span>` : ''}
                    ${startNode.length ? `<span class="text-slate-500">· ${escapeHtml(startNode.length)} m</span>` : ''}
                </div>
            `;
            return cableHeader + branches.map((branch, idx) =>
                '<div class="mb-3"><div class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Seite ' + (idx + 1) + '</div>' +
                renderBranchChain({ type: 'port', caption: '↪', device: {}, location: {} }, branch) +
                '</div>'
            ).join('');
        }
        return branches.map((branch, idx) =>
            '<div class="mb-4"><div class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Pfad ' + (idx + 1) + '</div>' +
            renderBranchChain(startNode, branch) +
            '</div>'
        ).join('');
    }

    async function open(opts) {
        opts = opts || {};
        if (!opts.uuid) return;
        const kind = opts.kind || 'device_port';
        const modal = ensureModal();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.getElementById('pf-cable-trace-title').textContent = 'Kabelverlauf' + (opts.label ? ' — ' + opts.label : '');
        document.getElementById('pf-cable-trace-subtitle').textContent = kind + ' · ' + opts.uuid;
        const body = document.getElementById('pf-cable-trace-body');
        body.innerHTML = '<div class="text-sm text-slate-500">Lade Kabelverlauf ...</div>';

        try {
            const url = API_BASE + '/cable_trace?from=' + encodeURIComponent(opts.uuid) + '&kind=' + encodeURIComponent(kind);
            const r = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            const result = await r.json();
            body.innerHTML = renderTrace(result);
            if (root.lucide && typeof root.lucide.createIcons === 'function') root.lucide.createIcons();
        } catch (e) {
            body.innerHTML = '<div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700">Fehler: ' + escapeHtml(e.message || e) + '</div>';
        }
    }

    function close() {
        const m = document.getElementById('pf-cable-trace-modal');
        if (m) { m.classList.add('hidden'); m.classList.remove('flex'); }
    }

    root.PortflowCableTrace = { open, close };

})(typeof window !== 'undefined' ? window : this);

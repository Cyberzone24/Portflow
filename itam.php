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
<style>
    .itam-shell {
        margin: 0.75rem 1rem 1rem;
        margin-top: 0;
        padding: 0;
        border-radius: 0;
        background: transparent;
        border: 0;
        box-shadow: none;
        display: grid;
        gap: 0.95rem;
        grid-template-columns: 1fr;
        min-height: 0;
    }

    .itam-sidebar {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 1rem;
        padding: 0.9rem;
        overflow: auto;
        display: none;
        min-height: 0;
        position: relative;
    }

    .itam-sidebar-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.6rem;
        margin-bottom: 0.85rem;
        padding-right: 2.5rem;
    }

    .itam-sidebar-toggle {
        height: 2.35rem;
        width: 2.35rem;
        border-radius: 9999px;
        background: #94a3b8;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.14);
        flex-shrink: 0;
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
    }

    .itam-sidebar-toggle:hover {
        background: #64748b;
    }

    .itam-nav {
        display: grid;
        gap: 0.55rem;
    }

    .itam-nav-item {
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #1e293b;
        border-radius: 9999px;
        padding: 0.62rem 0.85rem;
        cursor: pointer;
        transition: 140ms ease;
        font-weight: 600;
        white-space: nowrap;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.7rem;
    }

    .itam-nav-item-main {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        min-width: 0;
    }

    .itam-nav-item-main i[data-lucide],
    .itam-nav-chevron i[data-lucide] {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
    }

    .itam-nav-label {
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .itam-nav-item:hover {
        background: #f1f5f9;
    }

    .itam-nav-item-active {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
    }

    .itam-mobile-subnav {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        overflow-x: auto;
        padding-bottom: 0.25rem;
    }

    .itam-mobile-subnav .itam-nav-item {
        flex: 0 0 auto;
    }

    .itam-mobile-subnav .itam-nav-chevron {
        display: none;
    }

    .itam-content {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 1rem;
        position: relative;
        overflow: auto;
        min-height: 0;
    }

    .itam-content-head {
        margin-bottom: 0.95rem;
    }

    .itam-content-title {
        font-size: 1.55rem;
        line-height: 1.2;
        font-weight: 700;
        color: #0f172a;
    }

    .itam-content-copy {
        margin-top: 0.25rem;
        color: #64748b;
    }

    .itam-toolbar {
        display: grid;
        gap: 0.75rem;
    }

    .itam-toolbar-top {
        display: block;
        gap: 0.75rem;
    }

    .itam-search-row {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        min-width: 0;
        flex-wrap: nowrap;
    }

    .itam-search-form {
        flex: 0 1 30rem;
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .itam-search-input {
        flex: 1 1 auto;
        min-width: 0;
        border-radius: 9999px;
        border: 1px solid #cbd5e1;
        padding: 0.58rem 1rem;
        box-shadow: 0 4px 10px rgba(15, 23, 42, 0.08);
    }

    .itam-circle-btn {
        height: 2.5rem;
        width: 2.5rem;
        border-radius: 9999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.14);
    }

    .itam-count-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .itam-count-left {
        display: inline-flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .itam-limit-wrap {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .itam-limit-select {
        border: 1px solid #cbd5e1;
        border-radius: 9999px;
        background: #ffffff;
        padding: 0.35rem 0.8rem;
    }

    .itam-table-wrap {
        overflow: auto;
        border-radius: 0.8rem;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
    }

    .itam-table {
        min-width: 100%;
        margin-bottom: 0;
    }

    .itam-table thead tr {
        background: #e2e8f0;
    }

    .itam-table th {
        font-weight: 700;
        color: #1e293b;
        white-space: nowrap;
    }

    .itam-table td {
        color: #475569;
    }

    .itam-progress-overlay {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        z-index: 30;
    }

    .itam-progress-card {
        width: min(34rem, 100%);
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 1rem;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.24);
        padding: 1rem;
        display: grid;
        gap: 0.75rem;
    }

    .itam-progress-title {
        font-weight: 700;
        color: #0f172a;
    }

    .itam-progress-copy {
        color: #475569;
        font-size: 0.95rem;
    }

    .itam-progress-track {
        width: 100%;
        height: 0.65rem;
        border-radius: 9999px;
        background: #e2e8f0;
        overflow: hidden;
    }

    .itam-progress-bar {
        height: 100%;
        width: 0%;
        border-radius: 9999px;
        background: linear-gradient(90deg, #2563eb 0%, #38bdf8 100%);
        transition: width 180ms ease;
    }

    .itam-item-group-helper {
        margin-top: 0.5rem;
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        border-radius: 0.85rem;
        padding: 0.65rem;
        display: grid;
        gap: 0.55rem;
    }

    .itam-item-group-helper-row {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .itam-item-group-helper select,
    .itam-item-group-helper button {
        border: 1px solid #cbd5e1;
        border-radius: 9999px;
        padding: 0.35rem 0.75rem;
        background: #ffffff;
    }

    .itam-item-group-helper button {
        font-weight: 600;
    }

    .itam-item-group-helper small {
        color: #64748b;
    }

    .itam-details-grid {
        display: grid;
        gap: 0.9rem;
        grid-template-columns: 1fr;
    }

    .itam-details-card {
        border: 1px solid #cbd5e1;
        border-radius: 0.9rem;
        background: #f8fafc;
        padding: 0.75rem;
    }

    .itam-details-title {
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: 0.55rem;
        color: #0f172a;
    }

    .itam-details-table {
        width: 100%;
        border-collapse: collapse;
    }

    .itam-details-table th,
    .itam-details-table td {
        padding: 0.45rem 0.5rem;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
        text-align: left;
        font-size: 0.85rem;
    }

    .itam-details-table th {
        width: 38%;
        color: #475569;
        font-weight: 600;
    }

    .itam-details-table td {
        color: #0f172a;
        word-break: break-word;
    }

    .itam-details-panels {
        display: grid;
        gap: 0.75rem;
    }

    .itam-details-empty {
        font-size: 0.85rem;
        color: #64748b;
    }

    .itam-details-link-list {
        display: grid;
        gap: 0.55rem;
        margin-top: 0.25rem;
    }

    .itam-details-link-item {
        border: 1px solid #e2e8f0;
        border-radius: 0.65rem;
        background: #f8fafc;
        padding: 0.5rem;
        display: grid;
        gap: 0.4rem;
    }

    .itam-details-link-item a {
        font-size: 0.82rem;
        color: #2563eb;
        word-break: break-all;
        text-decoration: underline;
    }

    .itam-details-link-preview {
        max-width: 100%;
        max-height: 180px;
        border: 1px solid #cbd5e1;
        border-radius: 0.45rem;
        object-fit: contain;
        background: #ffffff;
    }

    .itam-details-journal-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 0.6rem;
        flex-wrap: wrap;
    }

    .itam-details-journal-title {
        font-size: 0.82rem;
        font-weight: 700;
        color: #0f172a;
    }

    .itam-details-journal-meta {
        font-size: 0.75rem;
        color: #64748b;
    }

    .itam-details-journal-body {
        margin-top: 0.35rem;
        font-size: 0.8rem;
        color: #334155;
        white-space: pre-wrap;
    }

    .itam-details-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.75rem;
        flex-wrap: wrap;
    }

    .itam-details-action-btn {
        border: 1px solid #cbd5e1;
        border-radius: 9999px;
        background: #2563eb;
        color: #ffffff;
        padding: 0.4rem 0.8rem;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .itam-details-action-btn:hover {
        background: #1d4ed8;
    }

    .itam-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.7);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 50;
        padding: 1rem;
    }

    .itam-modal.active {
        display: flex;
    }

    .itam-modal-content {
        background: #ffffff;
        border-radius: 1rem;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.3);
        padding: 1.5rem;
        max-width: 600px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
    }

    .itam-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .itam-modal-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #0f172a;
    }

    .itam-modal-close {
        background: #94a3b8;
        color: #ffffff;
        border: none;
        border-radius: 9999px;
        width: 2rem;
        height: 2rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .itam-modal-close:hover {
        background: #64748b;
    }

    .itam-modal-body {
        display: grid;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .itam-modal-field {
        display: grid;
        gap: 0.4rem;
    }

    .itam-modal-label {
        font-weight: 600;
        color: #0f172a;
        font-size: 0.9rem;
    }

    .itam-modal-input,
    .itam-modal-textarea {
        border: 1px solid #cbd5e1;
        border-radius: 0.5rem;
        padding: 0.5rem 0.75rem;
        font-size: 0.9rem;
    }

    .itam-modal-textarea {
        resize: vertical;
        min-height: 100px;
    }

    .itam-modal-file-input {
        display: none;
    }

    .itam-modal-file-label {
        border: 2px dashed #cbd5e1;
        border-radius: 0.5rem;
        padding: 1rem;
        text-align: center;
        cursor: pointer;
        background: #f8fafc;
        transition: all 0.2s;
    }

    .itam-modal-file-label:hover {
        border-color: #2563eb;
        background: #eff6ff;
    }

    .itam-modal-footer {
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
    }

    .itam-modal-btn {
        border-radius: 9999px;
        padding: 0.5rem 1.2rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        font-size: 0.9rem;
        transition: all 0.2s;
    }

    .itam-modal-btn-primary {
        background: #2563eb;
        color: #ffffff;
    }

    .itam-modal-btn-primary:hover {
        background: #1d4ed8;
    }

    .itam-modal-btn-secondary {
        background: #e2e8f0;
        color: #0f172a;
    }

    .itam-modal-btn-secondary:hover {
        background: #cbd5e1;
    }

    .itam-device-mode-tabs {
        display: flex;
        gap: 0.5rem;
        padding-bottom: 0.75rem;
        flex-wrap: wrap;
    }

    .itam-device-mode-tab {
        border: 1px solid #cbd5e1;
        border-radius: 9999px;
        background: #ffffff;
        color: #334155;
        padding: 0.4rem 0.9rem;
        font-weight: 600;
    }

    .itam-device-mode-tab.active {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
    }

    .itam-template-picker {
        border: 1px solid #cbd5e1;
        border-radius: 0.85rem;
        background: #f8fafc;
        padding: 0.75rem;
        display: grid;
        gap: 0.55rem;
        margin-bottom: 0.75rem;
    }

    .itam-template-picker-row {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .itam-template-picker select,
    .itam-template-picker button {
        border: 1px solid #cbd5e1;
        border-radius: 9999px;
        background: #ffffff;
        padding: 0.35rem 0.8rem;
    }

    .itam-template-picker small {
        color: #64748b;
    }

    @media (min-width: 1024px) {
        .itam-shell {
            grid-template-columns: minmax(76px, 15.5rem) minmax(0, 1fr);
            align-items: stretch;
            height: calc(100vh - 7.2rem);
        }

        .itam-shell.sidebar-collapsed {
            grid-template-columns: 4.75rem minmax(0, 1fr);
        }

        .itam-sidebar {
            display: flex;
            flex-direction: column;
        }

        .itam-mobile-subnav {
            display: none;
        }

        .itam-nav {
            display: grid;
            gap: 0.7rem;
        }

        .itam-shell.sidebar-collapsed .itam-sidebar-copy,
        .itam-shell.sidebar-collapsed .itam-nav-label,
        .itam-shell.sidebar-collapsed .itam-nav-chevron {
            display: none;
        }

        .itam-shell.sidebar-collapsed .itam-nav-item {
            justify-content: center;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
            min-height: 3rem;
        }

        .itam-shell.sidebar-collapsed .itam-nav-item-main {
            justify-content: center;
            gap: 0;
            width: 100%;
        }

        .itam-shell.sidebar-collapsed .itam-nav-item-main i[data-lucide] {
            width: 1.25rem;
            height: 1.25rem;
        }

        .itam-toolbar-top {
            display: block;
        }

        .itam-count-row {
            justify-content: space-between;
        }

        .itam-details-grid {
            grid-template-columns: minmax(300px, 0.95fr) minmax(0, 1.35fr);
            align-items: start;
        }
    }
</style>
<div class="itam-shell" id="itamShell">
    <aside class="itam-sidebar" id="itamSidebar">  
        <div class="itam-sidebar-head">
            <div class="itam-sidebar-copy">
                <p class="text-lg font-bold"><?php echo $lang['it asset-management']; ?></p>
                <p class="text-sm text-slate-500"><?php echo $lang['navigation'] ?? 'Navigation'; ?></p>
            </div>
            <button id="itamSidebarToggle" class="itam-sidebar-toggle" type="button" title="Leiste verkleinern">
                <i data-lucide="panel-left"></i>
            </button>
        </div>
        <ul class="itam-nav" id="itam_nav">
            <li onclick="loadTable('location_details')" data-table="location_details" class="itam-nav-item itam-nav-item-active"><span class="itam-nav-item-main"><i data-lucide="map-pin"></i><span class="itam-nav-label"><?php echo $lang['location']; ?></span></span><span class="itam-nav-chevron"><i data-lucide="chevron-right"></i></span></li>
            <li onclick="loadTable('ip_range_join_metadata')" data-table="ip_range_join_metadata" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="network"></i><span class="itam-nav-label"><?php echo $lang['ipam']; ?></span></span><span class="itam-nav-chevron"><i data-lucide="chevron-right"></i></span></li>
            <li onclick="loadTable('vlan_details')" data-table="vlan_details" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="layers"></i><span class="itam-nav-label"><?php echo $lang['vlan']; ?></span></span><span class="itam-nav-chevron"><i data-lucide="chevron-right"></i></span></li>
            <li onclick="loadTable('device_details')" data-table="device_details" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="server"></i><span class="itam-nav-label"><?php echo $lang['devices']; ?></span></span><span class="itam-nav-chevron"><i data-lucide="chevron-right"></i></span></li>
            <li onclick="loadTable('device_port_details')" data-table="device_port_details" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="ethernet-port"></i><span class="itam-nav-label"><?php echo $lang['device ports']; ?></span></span><span class="itam-nav-chevron"><i data-lucide="chevron-right"></i></span></li>
            <li onclick="loadTable('connection_details')" data-table="connection_details" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="link-2"></i><span class="itam-nav-label"><?php echo $lang['connections']; ?></span></span><span class="itam-nav-chevron"><i data-lucide="chevron-right"></i></span></li>
        </ul>
    </aside>
    <div class="itam-content">
        <div class="h-fit w-full p-4">
            <div class="itam-content-head">
                <div class="itam-content-title"><?php echo $lang['it asset-management']; ?></div>
                <div class="itam-content-copy"><?php echo $lang['quick note'] ?? 'Kurze Erklaerung: Hier werden Locations, Devices, Ports und Verbindungen schnell erfasst und gepflegt.'; ?></div>
            </div>

            <ul class="itam-mobile-subnav" id="itam_nav_mobile">
                <li onclick="loadTable('location_details')" data-table="location_details" class="itam-nav-item itam-nav-item-active"><span class="itam-nav-item-main"><i data-lucide="map-pin"></i><span class="itam-nav-label"><?php echo $lang['location']; ?></span></span></li>
                <li onclick="loadTable('ip_range_join_metadata')" data-table="ip_range_join_metadata" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="network"></i><span class="itam-nav-label"><?php echo $lang['ipam']; ?></span></span></li>
                <li onclick="loadTable('vlan_details')" data-table="vlan_details" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="layers"></i><span class="itam-nav-label"><?php echo $lang['vlan']; ?></span></span></li>
                <li onclick="loadTable('device_details')" data-table="device_details" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="server"></i><span class="itam-nav-label"><?php echo $lang['devices']; ?></span></span></li>
                <li onclick="loadTable('device_port_details')" data-table="device_port_details" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="ethernet-port"></i><span class="itam-nav-label"><?php echo $lang['device ports']; ?></span></span></li>
                <li onclick="loadTable('connection_details')" data-table="connection_details" class="itam-nav-item"><span class="itam-nav-item-main"><i data-lucide="link-2"></i><span class="itam-nav-label"><?php echo $lang['connections']; ?></span></span></li>
            </ul>

            <div class="itam-toolbar mb-4">
                <div class="itam-toolbar-top">
                    <div class="itam-search-row">
                        <form id="searchForm" class="itam-search-form" enctype="multipart/form-data" onsubmit="searchTable(event)">
                            <input type="text" name="search" placeholder="Suchen ..." class="itam-search-input">
                            <div class="itam-circle-btn bg-blue-500 hover:bg-blue-700">
                                <button type="submit" class="text-2xl text-white"><i data-lucide="search"></i></button>
                            </div>
                        </form>
                        <div class="itam-circle-btn bg-green-500 hover:bg-green-700">
                            <button form="" onclick="openNewEntry()" class="new_entry_button text-2xl text-white"><i data-lucide="plus"></i></button>
                        </div>
                    </div>
                </div>
                <div class="itam-count-row">
                    <div class="itam-count-left">
                        <p id="count"></p>
                        <div id="pagination" class="flex flex-row"></div>
                    </div>
                    <div class="itam-limit-wrap">
                        <p><?php echo $lang['quantity']; ?>:</p>
                        <select id="table_limit_1" name="limit" class="itam-limit-select" onchange="setTableLimit(this.value)">
                            <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50</option>
                            <option value="100" <?php if ($limit == 100) echo 'selected'; ?>>100</option>
                            <option value="500" <?php if ($limit == 500) echo 'selected'; ?>>500</option>
                            <option value="1000" <?php if ($limit == 1000) echo 'selected'; ?>>1000</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="itam-table-wrap">
            <table class="static itam-table rounded-lg w-full text-sm text-left text-gray-500 shadow-md">
                <thead class="text-gray-800"></thead>
                <tbody></tbody>
            </table>
            </div>
        </div>
        
        <!-- Details Popup -->
        <div id="detailsPopup" class="absolute top-0 left-0 h-full w-full p-4 bg-white rounded-lg z-2 hidden overflow-y-auto">
            <div class="flex justify-between pb-6">
                <div class="text-xl font-bold">Details</div>
                <div class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md">
                    <button type="button" onclick="closeDetailsPopup()" class="text-2xl text-white"><i data-lucide="x"></i></button>
                </div>
            </div>
            <div id="detailsContent" class="space-y-2"></div>
        </div>

        <!-- New Location -->
        <div id="formContainer" class="absolute top-0 left-0 h-full w-full p-4 bg-white rounded-lg z-2 overflow-y-auto hidden newEntry"></div>

        <div id="itamProgressOverlay" class="itam-progress-overlay" aria-live="polite" aria-hidden="true">
            <div class="itam-progress-card">
                <div class="itam-progress-title">Eintrag wird gespeichert</div>
                <div id="itamProgressCopy" class="itam-progress-copy">Bitte warten ...</div>
                <div class="itam-progress-track">
                    <div id="itamProgressBar" class="itam-progress-bar"></div>
                </div>
                <div id="itamProgressCount" class="itam-progress-copy">0 / 0</div>
            </div>
        </div>

        <!-- Journal Entry Modal -->
        <div id="journalEntryModal" class="itam-modal">
            <div class="itam-modal-content">
                <div class="itam-modal-header">
                    <div class="itam-modal-title">Journaleintrag erstellen</div>
                    <button type="button" class="itam-modal-close" onclick="closeJournalEntryModal()"><i data-lucide="x"></i></button>
                </div>
                <div class="itam-modal-body">
                    <div class="itam-modal-field">
                        <label class="itam-modal-label">Titel</label>
                        <input type="text" id="journalEntryCaption" class="itam-modal-input" placeholder="Kurzer Titel des Eintrags">
                    </div>
                    <div class="itam-modal-field">
                        <label class="itam-modal-label">Beschreibung</label>
                        <textarea id="journalEntryDescription" class="itam-modal-textarea" placeholder="Detaillierte Beschreibung des Journaleintrags"></textarea>
                    </div>
                </div>
                <div class="itam-modal-footer">
                    <button type="button" class="itam-modal-btn itam-modal-btn-secondary" onclick="closeJournalEntryModal()">Abbrechen</button>
                    <button type="button" class="itam-modal-btn itam-modal-btn-primary" onclick="submitJournalEntry()">Speichern</button>
                </div>
            </div>
        </div>

        <!-- File Upload Modal -->
        <div id="fileUploadModal" class="itam-modal">
            <div class="itam-modal-content">
                <div class="itam-modal-header">
                    <div class="itam-modal-title">Datei hochladen</div>
                    <button type="button" class="itam-modal-close" onclick="closeFileUploadModal()"><i data-lucide="x"></i></button>
                </div>
                <div class="itam-modal-body">
                    <div class="itam-modal-field">
                        <label class="itam-modal-label">Datei</label>
                        <input type="file" id="fileUploadInput" class="itam-modal-file-input" onchange="updateFileSelection()">
                        <label for="fileUploadInput" class="itam-modal-file-label">
                            <div><i data-lucide="upload"></i></div>
                            <div>Datei zum Hochladen ausw&auml;hlen oder hier ablegen</div>
                        </label>
                        <div id="fileUploadFeedback" class="itam-modal-feedback" style="display:none; margin-top: 0.5rem; padding: 0.75rem; background-color: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 0.375rem;">
                            <div style="font-size: 0.875rem;"><strong>Ausgewählte Datei:</strong></div>
                            <div id="fileUploadFileName" style="font-size: 0.875rem; color: #0c4a6e; margin-top: 0.25rem;"></div>
                            <div id="fileUploadFileSize" style="font-size: 0.875rem; color: #0c4a6e;"></div>
                        </div>
                        <div id="fileUploadProgress" style="display:none; margin-top: 1rem;">
                            <div style="font-size: 0.875rem; margin-bottom: 0.5rem;">Upload läuft...</div>
                            <div style="width: 100%; height: 8px; background-color: #e5e7eb; border-radius: 0.25rem; overflow: hidden;">
                                <div id="fileUploadProgressBar" style="height: 100%; background-color: #0ea5e9; width: 0%; transition: width 0.3s ease;"></div>
                            </div>
                            <div id="fileUploadProgressPercent" style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem; text-align: center;">0%</div>
                        </div>
                    </div>
                    <div class="itam-modal-field">
                        <label class="itam-modal-label">Beschreibung (optional)</label>
                        <textarea id="fileUploadDescription" class="itam-modal-textarea" placeholder="Beschreibung der Datei"></textarea>
                    </div>
                </div>
                <div class="itam-modal-footer">
                    <button type="button" class="itam-modal-btn itam-modal-btn-secondary" onclick="closeFileUploadModal()" id="fileUploadCancelBtn">Abbrechen</button>
                    <button type="button" class="itam-modal-btn itam-modal-btn-primary" onclick="submitFileUpload()" id="fileUploadSubmitBtn">Hochladen</button>
                </div>
            </div>
        </div>

        <!-- Edit Journal Entry Modal -->
        <div id="editJournalModal" class="itam-modal">
            <div class="itam-modal-content">
                <div class="itam-modal-header">
                    <div class="itam-modal-title">Journaleintrag bearbeiten</div>
                    <button type="button" class="itam-modal-close" onclick="closeEditJournalModal()"><i data-lucide="x"></i></button>
                </div>
                <div class="itam-modal-body">
                    <div class="itam-modal-field">
                        <label class="itam-modal-label">Titel</label>
                        <input type="text" id="editJournalCaption" class="itam-modal-input" placeholder="Titel">
                    </div>
                    <div class="itam-modal-field">
                        <label class="itam-modal-label">Beschreibung</label>
                        <textarea id="editJournalDescription" class="itam-modal-textarea" placeholder="Beschreibung"></textarea>
                    </div>
                </div>
                <div class="itam-modal-footer">
                    <button type="button" class="itam-modal-btn itam-modal-btn-secondary" onclick="closeEditJournalModal()">Abbrechen</button>
                    <button type="button" class="itam-modal-btn itam-modal-btn-primary" onclick="submitEditJournal()">Speichern</button>
                </div>
            </div>
        </div>

        <!-- Edit File/Attachment Modal -->
        <div id="editFileModal" class="itam-modal">
            <div class="itam-modal-content">
                <div class="itam-modal-header">
                    <div class="itam-modal-title">Anlage bearbeiten</div>
                    <button type="button" class="itam-modal-close" onclick="closeEditFileModal()"><i data-lucide="x"></i></button>
                </div>
                <div class="itam-modal-body">
                    <div class="itam-modal-field">
                        <label class="itam-modal-label">Dateiname</label>
                        <input type="text" id="editFileName" class="itam-modal-input" placeholder="Dateiname" disabled>
                    </div>
                    <div class="itam-modal-field">
                        <label class="itam-modal-label">Beschreibung</label>
                        <textarea id="editFileDescription" class="itam-modal-textarea" placeholder="Beschreibung der Datei"></textarea>
                    </div>
                </div>
                <div class="itam-modal-footer">
                    <button type="button" class="itam-modal-btn itam-modal-btn-secondary" onclick="closeEditFileModal()">Abbrechen</button>
                    <button type="button" class="itam-modal-btn itam-modal-btn-primary" onclick="submitEditFile()">Speichern</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// search
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.querySelector('#searchForm input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            loadTable(currentTable, searchInput.value);
        });
    }

    const shell = document.getElementById('itamShell');
    const sidebarToggle = document.getElementById('itamSidebarToggle');

    if (shell && sidebarToggle) {
        const syncToggleIcon = () => {
            const collapsed = shell.classList.contains('sidebar-collapsed');
            sidebarToggle.innerHTML = collapsed
                ? '<i data-lucide="panel-right"></i>'
                : '<i data-lucide="panel-left"></i>';
            sidebarToggle.setAttribute('title', collapsed ? 'Leiste vergroessern' : 'Leiste verkleinern');
            lucide.createIcons();
        };

        sidebarToggle.addEventListener('click', () => {
            shell.classList.toggle('sidebar-collapsed');
            syncToggleIcon();
        });

        syncToggleIcon();
    }
});

let currentNavConfig = {};
let currentDetailsRowData = null;
let currentEditingJournalUuid = null;
let currentEditingMetadataUuid = null;

function searchTable(event) {
    event.preventDefault();
}

// Generate form
async function generateFormFromJSON(table = 'location_details', options = {}) {
    try {
        console.log('Loading form configuration for table:', table);
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>' + '/forms.json');
        const data = await response.json();
        const mode = options.mode === 'edit' ? 'edit' : 'create';
        const rowData = options.rowData || null;

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
            title.textContent = mode === 'edit' ? `${formConfig.formTitle} bearbeiten` : formConfig.formTitle;
            header.appendChild(title);

            const buttonContainer = document.createElement('div');
            buttonContainer.className = 'flex gap-4';

            const submitWrapper = document.createElement('div');
            submitWrapper.className = 'h-10 w-10 rounded-full bg-green-500 hover:bg-green-700 flex justify-center shadow-md';

            const submitButton = document.createElement('button');
            submitButton.type = 'button';
            submitButton.onclick = () => submitForms(table);
            submitButton.className = 'h-full w-full flex items-center justify-center text-2xl text-white';
            submitButton.innerHTML = '<i data-lucide="check"></i>';
            submitWrapper.appendChild(submitButton);

            const cancelWrapper = document.createElement('div');
            cancelWrapper.className = 'h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 flex justify-center shadow-md';

            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.onclick = () => closeNewEntry();
            cancelButton.className = 'h-full w-full flex items-center justify-center text-2xl text-white';
            cancelButton.innerHTML = '<i data-lucide="x"></i>';
            cancelWrapper.appendChild(cancelButton);

            buttonContainer.appendChild(submitWrapper);
            buttonContainer.appendChild(cancelWrapper);
            header.appendChild(buttonContainer);

            container.appendChild(header);
        }

        // Iterate over postOrder to generate fields
        const generatedForms = [];

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
            generatedForms.push(form);
        });

        itamFormState = {
            mode,
            table,
            rowData,
            uuids: mode === 'edit' ? mapEditUuidsFromRow(rowData, formConfig.postOrder, table) : {}
        };

        if (mode === 'edit') {
            populateFormsFromRow(table, formConfig, rowData);
        }

        if (table === 'device_details') {
            currentDeviceCreateMode = 'new';
            if (mode !== 'edit') {
                setupDeviceTemplateMode(container);
            }
            setupDevicePortAutomation();
            setupItemGroupHelper();
        }

        if (table === 'connection_details') {
            setupConnectionSuggestions(container, generatedForms);
        }

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    } catch (error) {
        console.error("Error loading or processing forms.json:", error);
    }
}

function getDefaultDevicePortConfig(deviceType) {
    const normalizedType = (deviceType || '').toLowerCase();

    if (normalizedType === 'patchpanel') {
        return { count: 24 };
    }

    if (normalizedType === 'net_outlet') {
        return { count: 2 };
    }

    if (normalizedType === 'switch') {
        return { count: 24 };
    }

    return { count: 0 };
}

let currentDeviceCreateMode = 'new';
let itamFormState = {
    mode: 'create',
    table: '',
    rowData: null,
    uuids: {}
};

function getCurrentBaseTableName(tableName) {
    return String(tableName || '').replace(/_details$/, '').replace(/_join_.+$/, '');
}

function findBestRowUuidForTable(rowData, tableName) {
    if (!rowData || !tableName) {
        return '';
    }

    const candidateKeys = Object.keys(rowData).filter(key => {
        if (!key.endsWith('_uuid') || !rowData[key]) {
            return false;
        }

        const keyWithoutSuffix = key.slice(0, -5);
        const parts = keyWithoutSuffix.split('_');
        return parts[parts.length - 1] === tableName;
    });

    if (candidateKeys.length === 0) {
        return '';
    }

    candidateKeys.sort((a, b) => a.length - b.length);
    return String(rowData[candidateKeys[0]] || '');
}

function mapEditUuidsFromRow(rowData, postOrder, detailsTableName) {
    const uuids = {};
    const baseTable = getCurrentBaseTableName(detailsTableName);

    postOrder.forEach(step => {
        const tableName = step.table;
        const uuid = findBestRowUuidForTable(rowData, tableName);
        if (uuid) {
            uuids[tableName] = uuid;
        }
    });

    if (!uuids[baseTable] && rowData && rowData.uuid) {
        uuids[baseTable] = String(rowData.uuid);
    }

    return uuids;
}

function getRowFieldValueForForm(rowData, detailsTableName, postTableName, fieldName) {
    if (!rowData) {
        return undefined;
    }

    const baseTable = getCurrentBaseTableName(detailsTableName);
    const candidates = [
        `${postTableName}_${fieldName}`,
        `${baseTable}_${postTableName}_${fieldName}`,
        `${baseTable}_${fieldName}`,
        fieldName
    ];

    for (const key of candidates) {
        if (Object.prototype.hasOwnProperty.call(rowData, key)) {
            return rowData[key];
        }
    }

    return undefined;
}

function getRowSearchLabel(rowData, detailsTableName, postTableName, fieldName) {
    const baseTable = getCurrentBaseTableName(detailsTableName);
    const candidates = [
        `${baseTable}_${fieldName}_metadata_caption`,
        `${postTableName}_${fieldName}_metadata_caption`,
        `${fieldName}_metadata_caption`,
        `${baseTable}_${fieldName}_caption`,
        `${postTableName}_${fieldName}_caption`,
        `${fieldName}_caption`
    ];

    for (const key of candidates) {
        if (Object.prototype.hasOwnProperty.call(rowData, key) && rowData[key]) {
            return String(rowData[key]);
        }
    }

    return '';
}

function populateFormsFromRow(detailsTableName, formConfig, rowData) {
    if (!formConfig || !Array.isArray(formConfig.postOrder) || !rowData) {
        return;
    }

    formConfig.postOrder.forEach(step => {
        const form = document.getElementById(step.table);
        if (!form || !Array.isArray(step.fields)) {
            return;
        }

        step.fields.forEach(fieldName => {
            const fieldConfig = formConfig.fields[fieldName] || {};
            const value = getRowFieldValueForForm(rowData, detailsTableName, step.table, fieldName);
            if (value === undefined) {
                return;
            }

            if (fieldConfig.type === 'searchDropdown') {
                const displayLabel = getRowSearchLabel(rowData, detailsTableName, step.table, fieldName);
                setFieldValue(form, fieldName, value, { displayLabel });
                return;
            }

            setFieldValue(form, fieldName, value);
        });
    });
}

function isTruthyTemplateValue(value) {
    if (value === true || value === 1) {
        return true;
    }

    const normalized = String(value || '').trim().toLowerCase();
    return normalized === '1' || normalized === 'true' || normalized === 't' || normalized === 'yes';
}

function getDeviceTemplateFieldValue(templateRow, fieldName) {
    const fallback = null;
    const valueByKey = (keys) => {
        for (const key of keys) {
            if (templateRow[key] !== undefined && templateRow[key] !== null) {
                return templateRow[key];
            }
        }
        return fallback;
    };

    const map = {
        status: ['device_metadata_status', 'metadata_status', 'status'],
        caption: ['device_metadata_caption', 'metadata_caption', 'caption'],
        description: ['device_metadata_description', 'metadata_description', 'description'],
        specification: ['device_metadata_specification', 'metadata_specification', 'specification'],
        tags: ['device_metadata_tags', 'metadata_tags', 'tags'],
        location: ['device_location', 'location'],
        expected_location: ['device_expected_location', 'expected_location'],
        serial: ['device_serial', 'serial'],
        asset: ['device_asset', 'asset'],
        manufacturer: ['device_manufacturer', 'manufacturer'],
        model: ['device_model', 'model'],
        type: ['device_type', 'type'],
        anc: ['device_anc', 'anc'],
        position: ['device_position', 'position'],
        rotation: ['device_rotation', 'rotation'],
        size: ['device_size', 'size'],
        item_group: ['device_item_group', 'item_group']
    };

    return valueByKey(map[fieldName] || [fieldName]);
}

function setFieldValue(form, fieldName, value, options = {}) {
    const field = form.querySelector(`[name="${fieldName}"]`);
    if (!field) {
        return;
    }

    if (field.type === 'hidden') {
        field.value = value == null ? '' : String(value);
        const wrapper = field.closest('.pb-6');
        if (wrapper) {
            const visibleInput = wrapper.querySelector('input[type="text"]:not([name])');
            if (visibleInput) {
                visibleInput.value = options.displayLabel || '';
            }
        }
        field.dispatchEvent(new Event('change', { bubbles: true }));
        return;
    }

    if (field.type === 'checkbox') {
        field.checked = isTruthyTemplateValue(value);
    } else {
        field.value = value == null ? '' : String(value);
    }

    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
}

function applyDeviceTemplateToForms(templateRow) {
    const metadataForm = document.getElementById('metadata');
    const deviceForm = document.getElementById('device');
    if (!metadataForm || !deviceForm || !templateRow) {
        return;
    }

    const metadataFields = ['status', 'caption', 'description', 'specification', 'tags'];
    const deviceFields = ['location', 'expected_location', 'serial', 'asset', 'manufacturer', 'model', 'type', 'anc', 'position', 'rotation', 'size', 'item_group'];

    metadataFields.forEach(fieldName => {
        setFieldValue(metadataForm, fieldName, getDeviceTemplateFieldValue(templateRow, fieldName));
    });

    const locationLabel = templateRow.device_location_metadata_caption || templateRow.location_metadata_caption || '';
    setFieldValue(deviceForm, 'location', getDeviceTemplateFieldValue(templateRow, 'location'), { displayLabel: locationLabel });

    deviceFields.filter(name => name !== 'location').forEach(fieldName => {
        setFieldValue(deviceForm, fieldName, getDeviceTemplateFieldValue(templateRow, fieldName));
    });

    // Saving from template should create a normal device by default.
    setFieldValue(deviceForm, 'template', false);
}

async function loadDeviceTemplates() {
    const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/device_details?limit=5000');
    const payload = await response.json();
    const rows = payload && payload.items ? payload.items : [];

    return rows
        .filter(row => isTruthyTemplateValue(row.device_template || row.template))
        .map(row => ({
            row,
            uuid: row.device_uuid || row.uuid || '',
            caption: row.device_metadata_caption || row.metadata_caption || 'Template',
            type: row.device_type || row.type || ''
        }))
        .filter(entry => !!entry.uuid)
        .sort((a, b) => a.caption.localeCompare(b.caption));
}

function setupDeviceTemplateMode(container) {
    const metadataForm = document.getElementById('metadata');
    const deviceForm = document.getElementById('device');
    if (!container || !metadataForm || !deviceForm) {
        return;
    }

    const insertionAnchor = container.children[1] || null;

    const tabs = document.createElement('div');
    tabs.className = 'itam-device-mode-tabs';

    const newButton = document.createElement('button');
    newButton.type = 'button';
    newButton.className = 'itam-device-mode-tab active';
    newButton.textContent = 'Neues Geraet';

    const templateButton = document.createElement('button');
    templateButton.type = 'button';
    templateButton.className = 'itam-device-mode-tab';
    templateButton.textContent = 'Aus Template';

    tabs.appendChild(newButton);
    tabs.appendChild(templateButton);
    container.insertBefore(tabs, insertionAnchor);

    const picker = document.createElement('div');
    picker.className = 'itam-template-picker hidden';

    const pickerInfo = document.createElement('small');
    pickerInfo.textContent = 'Template auswaehlen, Felder werden vorbefuellt und koennen danach angepasst werden.';

    const pickerRow = document.createElement('div');
    pickerRow.className = 'itam-template-picker-row';

    const pickerSelect = document.createElement('select');
    pickerSelect.innerHTML = '<option value="">Template waehlen ...</option>';

    const applyButton = document.createElement('button');
    applyButton.type = 'button';
    applyButton.textContent = 'Template anwenden';

    const reloadButton = document.createElement('button');
    reloadButton.type = 'button';
    reloadButton.textContent = 'Templates neu laden';

    pickerRow.appendChild(pickerSelect);
    pickerRow.appendChild(applyButton);
    pickerRow.appendChild(reloadButton);
    picker.appendChild(pickerInfo);
    picker.appendChild(pickerRow);
    container.insertBefore(picker, insertionAnchor);

    let templates = [];

    const setMode = (mode) => {
        currentDeviceCreateMode = mode;
        const templateMode = mode === 'template';

        newButton.classList.toggle('active', !templateMode);
        templateButton.classList.toggle('active', templateMode);
        picker.classList.toggle('hidden', !templateMode);

        // Keep template checkbox off when creating from template.
        if (templateMode) {
            setFieldValue(deviceForm, 'template', false);
        }
    };

    const fillTemplateSelect = async () => {
        pickerSelect.disabled = true;
        pickerSelect.innerHTML = '<option value="">Lade Templates ...</option>';

        try {
            templates = await loadDeviceTemplates();
            pickerSelect.innerHTML = '<option value="">Template waehlen ...</option>';

            templates.forEach(template => {
                const option = document.createElement('option');
                option.value = template.uuid;
                option.textContent = `${template.caption}${template.type ? ` (${template.type})` : ''}`;
                pickerSelect.appendChild(option);
            });

            if (templates.length === 0) {
                pickerSelect.innerHTML = '<option value="">Keine Device-Templates gefunden</option>';
            }
        } catch (error) {
            console.error('Device templates konnten nicht geladen werden:', error);
            pickerSelect.innerHTML = '<option value="">Fehler beim Laden</option>';
        }

        pickerSelect.disabled = false;
    };

    const applySelectedTemplate = () => {
        const selectedUuid = pickerSelect.value;
        if (!selectedUuid) {
            return;
        }

        const selectedTemplate = templates.find(template => template.uuid === selectedUuid);
        if (!selectedTemplate) {
            return;
        }

        applyDeviceTemplateToForms(selectedTemplate.row);
    };

    newButton.addEventListener('click', () => setMode('new'));
    templateButton.addEventListener('click', () => setMode('template'));
    applyButton.addEventListener('click', applySelectedTemplate);
    pickerSelect.addEventListener('change', applySelectedTemplate);
    reloadButton.addEventListener('click', fillTemplateSelect);

    setMode('new');
    fillTemplateSelect();
}

function isValidPostgresUuid(value) {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test((value || '').trim());
}

function generateUuidV4() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(char) {
        const randomNibble = Math.random() * 16 | 0;
        const nibble = char === 'x' ? randomNibble : ((randomNibble & 0x3) | 0x8);
        return nibble.toString(16);
    });
}

function setProgressOverlayState(visible) {
    const overlay = document.getElementById('itamProgressOverlay');
    if (!overlay) {
        return;
    }

    overlay.style.display = visible ? 'flex' : 'none';
    overlay.setAttribute('aria-hidden', visible ? 'false' : 'true');
}

function updateProgressOverlay(copy, current = 0, total = 0) {
    const copyElement = document.getElementById('itamProgressCopy');
    const countElement = document.getElementById('itamProgressCount');
    const barElement = document.getElementById('itamProgressBar');

    if (copyElement) {
        copyElement.textContent = copy;
    }

    if (countElement) {
        countElement.textContent = `${current} / ${total}`;
    }

    if (barElement) {
        const percent = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;
        barElement.style.width = `${percent}%`;
    }
}

function buildPortLabel(baseLabel, offset) {
    const input = (baseLabel || '').trim();
    if (!input) {
        return '';
    }

    const match = input.match(/^(.*?)(\d+)$/);
    if (!match) {
        return offset === 0 ? input : `${input}-${offset + 1}`;
    }

    const prefix = match[1];
    const startNumber = parseInt(match[2], 10);
    const nextNumber = String(startNumber + offset);
    return `${prefix}${nextNumber}`;
}

function formatSearchResultLabel(item, config) {
    const displayFields = config.displayFields || [];

    if (displayFields.length > 0) {
        const parts = displayFields
            .map(fieldName => (item[fieldName] || '').trim())
            .filter(Boolean);

        if (parts.length > 0) {
            return parts.join(' · ');
        }
    }

    const captionKey = Object.keys(item).find(k => k.endsWith('_metadata_caption'))
        || Object.keys(item).find(k => k.endsWith('_caption'))
        || Object.keys(item).find(k => k.endsWith('_name'))
        || Object.keys(item)[0];

    return item[captionKey] || item.uuid || '[kein Name]';
}

function toggleConnectionView(container, forms, suggestionsPanel, activeView) {
    const manualVisible = activeView === 'manual';
    forms.forEach(form => {
        form.classList.toggle('hidden', !manualVisible);
    });

    if (suggestionsPanel) {
        suggestionsPanel.classList.toggle('hidden', manualVisible);
    }

    const manualButton = container.querySelector('[data-connection-view="manual"]');
    const suggestionsButton = container.querySelector('[data-connection-view="suggestions"]');
    if (manualButton) {
        manualButton.classList.toggle('bg-blue-500', manualVisible);
        manualButton.classList.toggle('text-white', manualVisible);
    }
    if (suggestionsButton) {
        suggestionsButton.classList.toggle('bg-blue-500', !manualVisible);
        suggestionsButton.classList.toggle('text-white', !manualVisible);
    }
}

function renderSuggestionRow(suggestion) {
    const row = document.createElement('div');
    row.className = 'flex flex-col gap-2 p-4 border rounded-2xl bg-gray-50 shadow-sm';

    const title = document.createElement('div');
    title.className = 'flex items-center justify-between gap-4';

    const text = document.createElement('div');
    text.className = 'font-semibold';
    text.textContent = suggestion.label;

    const badge = document.createElement('div');
    badge.className = 'text-xs px-3 py-1 rounded-full bg-gray-200';
    badge.textContent = suggestion.room;

    title.appendChild(text);
    title.appendChild(badge);

    const details = document.createElement('div');
    details.className = 'text-sm text-gray-600';
    details.textContent = `${suggestion.source.deviceCaption} → ${suggestion.destination.deviceCaption}`;

    const buttonRow = document.createElement('div');
    buttonRow.className = 'flex justify-end';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'px-4 py-2 rounded-full bg-green-500 hover:bg-green-700 text-white text-sm font-semibold';
    button.textContent = 'Verbinden';
    button.onclick = () => createSuggestedConnection(suggestion, button);

    buttonRow.appendChild(button);
    row.appendChild(title);
    row.appendChild(details);
    row.appendChild(buttonRow);

    return row;
}

function setupConnectionSuggestions(container, forms) {
    const header = container.querySelector('.flex.justify-between.items-center.pb-6');
    if (!header) {
        return;
    }

    const tabBar = document.createElement('div');
    tabBar.className = 'flex gap-2 pb-4';

    const manualButton = document.createElement('button');
    manualButton.type = 'button';
    manualButton.dataset.connectionView = 'manual';
    manualButton.className = 'px-4 py-2 rounded-full bg-blue-500 text-white text-sm font-semibold';
    manualButton.textContent = 'Manuell';

    const suggestionsButton = document.createElement('button');
    suggestionsButton.type = 'button';
    suggestionsButton.dataset.connectionView = 'suggestions';
    suggestionsButton.className = 'px-4 py-2 rounded-full bg-gray-200 text-gray-700 text-sm font-semibold';
    suggestionsButton.textContent = 'Vorschläge';

    tabBar.appendChild(manualButton);
    tabBar.appendChild(suggestionsButton);
    container.insertBefore(tabBar, container.children[1] || null);

    const suggestionsPanel = document.createElement('div');
    suggestionsPanel.id = 'connectionSuggestionsPanel';
    suggestionsPanel.className = 'hidden mt-2 space-y-3';

    const suggestionHeader = document.createElement('div');
    suggestionHeader.className = 'flex items-center justify-between';
    suggestionHeader.innerHTML = '<div class="text-lg font-bold">Vorschläge</div><div class="text-sm text-gray-500">Gleiche Portnamen im gleichen Raum, unverbundene Paare</div>';

    const suggestionList = document.createElement('div');
    suggestionList.id = 'connectionSuggestionList';
    suggestionList.className = 'space-y-3';

    suggestionsPanel.appendChild(suggestionHeader);
    suggestionsPanel.appendChild(suggestionList);
    container.appendChild(suggestionsPanel);

    manualButton.onclick = () => toggleConnectionView(container, forms, suggestionsPanel, 'manual');
    suggestionsButton.onclick = async () => {
        toggleConnectionView(container, forms, suggestionsPanel, 'suggestions');
        if (!suggestionsPanel.dataset.loaded) {
            await loadConnectionSuggestions(suggestionList);
            suggestionsPanel.dataset.loaded = 'true';
        }
    };

    toggleConnectionView(container, forms, suggestionsPanel, 'manual');
}

function buildConnectionSuggestions(ports, connections) {
    const connectedPorts = new Set();
    connections.forEach(connection => {
        if (connection.connection_device_port_source) {
            connectedPorts.add(connection.connection_device_port_source);
        }
        if (connection.connection_device_port_destination) {
            connectedPorts.add(connection.connection_device_port_destination);
        }
    });

    const grouped = new Map();

    ports.forEach(port => {
        const room = (
            port.device_port_device_location_parent_location_metadata_caption ||
            port.device_port_device_location_metadata_caption ||
            port.device_port_device_location_caption ||
            ''
        ).trim();
        const label = (port.device_port_metadata_caption || '').trim();
        const deviceType = (port.device_port_device_type || '').trim().toLowerCase();
        const uuid = port.device_port_uuid;

        if (!room || !label || !uuid || connectedPorts.has(uuid)) {
            return;
        }

        const key = `${room}::${label}`;
        if (!grouped.has(key)) {
            grouped.set(key, []);
        }

        grouped.get(key).push({
            uuid,
            room,
            label,
            deviceType,
            deviceCaption: port.device_port_device_metadata_caption || port.device_port_device_type || 'Device'
        });
    });

    const suggestions = [];

    grouped.forEach((group) => {
        const patchPanels = group.filter(item => item.deviceType === 'patchpanel').sort((a, b) => a.deviceCaption.localeCompare(b.deviceCaption));
        const outlets = group.filter(item => item.deviceType === 'net_outlet').sort((a, b) => a.deviceCaption.localeCompare(b.deviceCaption));
        const count = Math.min(patchPanels.length, outlets.length);

        for (let index = 0; index < count; index++) {
            suggestions.push({
                label: group[index].label,
                room: group[index].room,
                source: patchPanels[index],
                destination: outlets[index]
            });
        }
    });

    return suggestions.sort((a, b) => {
        const roomCompare = a.room.localeCompare(b.room);
        if (roomCompare !== 0) {
            return roomCompare;
        }

        return a.label.localeCompare(b.label);
    });
}

async function loadConnectionSuggestions(container) {
    container.innerHTML = '<div class="text-sm text-gray-500">Lade Vorschläge ...</div>';

    try {
        const [portsResponse, connectionsResponse] = await Promise.all([
            fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/device_port_details?limit=5000'),
            fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/connection_details?limit=5000')
        ]);

        const portsData = await portsResponse.json();
        const connectionsData = await connectionsResponse.json();

        const suggestions = buildConnectionSuggestions(portsData.items || [], connectionsData.items || []);

        container.innerHTML = '';

        if (suggestions.length === 0) {
            const emptyState = document.createElement('div');
            emptyState.className = 'p-4 rounded-2xl bg-gray-50 text-gray-500';
            emptyState.textContent = 'Keine offenen Vorschläge gefunden.';
            container.appendChild(emptyState);
            return;
        }

        suggestions.forEach(suggestion => {
            container.appendChild(renderSuggestionRow(suggestion));
        });
    } catch (error) {
        console.error('Error loading connection suggestions:', error);
        container.innerHTML = '<div class="p-4 rounded-2xl bg-red-50 text-red-600">Vorschläge konnten nicht geladen werden.</div>';
    }
}

async function createSuggestedConnection(suggestion, buttonElement) {
    if (buttonElement) {
        buttonElement.disabled = true;
        buttonElement.textContent = 'Verbinde ...';
    }

    try {
        const metadataPayload = {
            status: '0',
            caption: suggestion.label,
            description: `${suggestion.room} | ${suggestion.source.deviceCaption} → ${suggestion.destination.deviceCaption}`,
            specification: '',
            tags: ''
        };

        const metadataResponse = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/metadata/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(metadataPayload)
        });
        const metadataResult = await metadataResponse.json();
        const metadataUuid = metadataResult && metadataResult[0] && metadataResult[0].uuid;

        if (!metadataUuid) {
            throw new Error('Metadata konnte nicht angelegt werden.');
        }

        const connectionPayload = {
            metadata: metadataUuid,
            device_port_source: suggestion.source.uuid,
            device_port_destination: suggestion.destination.uuid,
            type: suggestion.label,
            length: '',
            crossover: false,
            speed: '',
            item_group: ''
        };

        const connectionResponse = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/connection/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(connectionPayload)
        });
        const connectionResult = await connectionResponse.json();

        if (!connectionResult || !connectionResult[0] || !connectionResult[0].uuid) {
            throw new Error('Verbindung konnte nicht angelegt werden.');
        }

        loadTable('connection_details');
    } catch (error) {
        console.error('Fehler beim Erstellen der Vorschlagsverbindung:', error);
        if (buttonElement) {
            buttonElement.disabled = false;
            buttonElement.textContent = 'Verbinden';
        }
    }
}

function setupDevicePortAutomation() {
    const form = document.getElementById('device');
    if (!form) {
        return;
    }

    const typeField = form.querySelector('[name="type"]');
    const templateField = form.querySelector('[name="template"]');
    const portCountField = form.querySelector('[name="port_count"]');
    const portStartLabelField = form.querySelector('[name="port_start_label"]');

    const applyDefaults = () => {
        const defaults = getDefaultDevicePortConfig(typeField ? typeField.value : '');

        if (portCountField && (!portCountField.value || portCountField.dataset.autoFilled === 'true')) {
            portCountField.value = defaults.count;
            portCountField.dataset.autoFilled = 'true';
        }

    };

    const syncTemplatePortRules = () => {
        const templateChecked = !!(templateField && templateField.checked);

        if (portCountField) {
            if (templateChecked) {
                portCountField.value = '0';
            }
            portCountField.disabled = templateChecked;
        }

        if (portStartLabelField) {
            if (templateChecked) {
                portStartLabelField.value = '';
            }
            portStartLabelField.disabled = templateChecked;
        }
    };

    if (typeField) {
        typeField.addEventListener('change', applyDefaults);
        typeField.addEventListener('input', applyDefaults);
    }

    if (portCountField) {
        portCountField.addEventListener('input', () => {
            portCountField.dataset.autoFilled = 'false';
        });
    }

    if (portStartLabelField) {
        portStartLabelField.addEventListener('input', () => {
            portStartLabelField.dataset.autoFilled = 'false';
        });
    }

    if (templateField) {
        templateField.addEventListener('change', syncTemplatePortRules);
        templateField.addEventListener('input', syncTemplatePortRules);
    }

    applyDefaults();
    syncTemplatePortRules();
}

async function loadExistingSwitchItemGroups() {
    const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/device_details?limit=5000');
    const payload = await response.json();
    const rows = payload && payload.items ? payload.items : [];

    const uniqueGroups = new Map();
    rows.forEach(row => {
        const type = (row.device_type || row.type || '').toLowerCase();
        const groupUuid = (row.device_item_group || row.item_group || '').trim();
        if (type !== 'switch' || !groupUuid || !isValidPostgresUuid(groupUuid)) {
            return;
        }

        if (!uniqueGroups.has(groupUuid)) {
            const caption = row.device_metadata_caption || row.metadata_caption || row.device_caption || 'Switch';
            uniqueGroups.set(groupUuid, caption);
        }
    });

    return Array.from(uniqueGroups.entries()).map(([uuid, caption]) => ({ uuid, caption }));
}

function setupItemGroupHelper() {
    const form = document.getElementById('device');
    if (!form) {
        return;
    }

    const typeField = form.querySelector('[name="type"]');
    const itemGroupField = form.querySelector('[name="item_group"]');
    if (!itemGroupField) {
        return;
    }

    const itemGroupWrapper = itemGroupField.closest('.pb-6');
    if (!itemGroupWrapper || itemGroupWrapper.querySelector('.itam-item-group-helper')) {
        return;
    }

    const helper = document.createElement('div');
    helper.className = 'itam-item-group-helper hidden';

    const info = document.createElement('small');
    info.textContent = 'Fuer Switch-Stacks: vorhandene Group waehlen oder neue UUID erzeugen.';

    const controls = document.createElement('div');
    controls.className = 'itam-item-group-helper-row';

    const select = document.createElement('select');
    select.innerHTML = '<option value="">Vorhandene Item Group waehlen ...</option>';

    const generateButton = document.createElement('button');
    generateButton.type = 'button';
    generateButton.textContent = 'Neue UUID erzeugen';

    const refreshButton = document.createElement('button');
    refreshButton.type = 'button';
    refreshButton.textContent = 'Groups neu laden';

    controls.appendChild(select);
    controls.appendChild(generateButton);
    controls.appendChild(refreshButton);
    helper.appendChild(info);
    helper.appendChild(controls);
    itemGroupWrapper.appendChild(helper);

    const updateVisibility = () => {
        const isSwitch = typeField && typeField.value === 'switch';
        helper.classList.toggle('hidden', !isSwitch);
    };

    const syncFieldValidity = () => {
        const value = (itemGroupField.value || '').trim();
        if (!value || isValidPostgresUuid(value)) {
            itemGroupField.setCustomValidity('');
            return;
        }

        itemGroupField.setCustomValidity('Item Group muss eine gueltige UUID sein.');
    };

    const fillSelect = async () => {
        const currentValue = select.value;
        select.disabled = true;
        select.innerHTML = '<option value="">Lade Item Groups ...</option>';

        try {
            const groups = await loadExistingSwitchItemGroups();
            select.innerHTML = '<option value="">Vorhandene Item Group waehlen ...</option>';

            groups.forEach(group => {
                const option = document.createElement('option');
                option.value = group.uuid;
                option.textContent = `${group.uuid} (${group.caption})`;
                select.appendChild(option);
            });

            if (currentValue) {
                select.value = currentValue;
            }

            if (groups.length === 0) {
                select.innerHTML = '<option value="">Keine vorhandenen Switch-Groups gefunden</option>';
            }
        } catch (error) {
            console.error('Item Groups konnten nicht geladen werden:', error);
            select.innerHTML = '<option value="">Fehler beim Laden</option>';
        }

        select.disabled = false;
    };

    if (typeField) {
        typeField.addEventListener('change', updateVisibility);
        typeField.addEventListener('input', updateVisibility);
    }

    itemGroupField.addEventListener('input', syncFieldValidity);
    itemGroupField.addEventListener('change', syncFieldValidity);

    select.addEventListener('change', () => {
        itemGroupField.value = select.value || '';
        syncFieldValidity();
    });

    generateButton.addEventListener('click', () => {
        itemGroupField.value = generateUuidV4();
        syncFieldValidity();
    });

    refreshButton.addEventListener('click', () => {
        fillSelect();
    });

    updateVisibility();
    syncFieldValidity();
    fillSelect();
}

async function createAutoPortsForDevice(deviceUuid, options = {}, onProgress = null) {
    const count = parseInt(options.count || 0, 10);
    if (!deviceUuid || !count || count <= 0) {
        return;
    }

    const startLabel = (options.startLabel || '').trim();
    if (!startLabel) {
        return;
    }

    const metadataStatus = '6';

    if (typeof onProgress === 'function') {
        onProgress({ current: 0, total: count, label: 'Auto-Ports werden erstellt ...' });
    }

    for (let index = 1; index <= count; index++) {
        const label = buildPortLabel(startLabel, index - 1);
        const metadataPayload = {
            status: metadataStatus,
            caption: label,
            description: '',
            specification: '',
            tags: ''
        };

        const metadataResponse = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/metadata/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(metadataPayload)
        });
        const metadataResult = await metadataResponse.json();
        const metadataUuid = metadataResult && metadataResult[0] && metadataResult[0].uuid;

        if (!metadataUuid) {
            throw new Error(`Metadata fuer Port ${index} konnte nicht erstellt werden.`);
        }

        const devicePortResponse = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/device_port/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                metadata: metadataUuid,
                device: deviceUuid
            })
        });
        const devicePortResult = await devicePortResponse.json();

        if (!devicePortResult || !devicePortResult[0] || !devicePortResult[0].uuid) {
            throw new Error(`Device-Port fuer Port ${index} konnte nicht erstellt werden.`);
        }

        if (typeof onProgress === 'function') {
            onProgress({ current: index, total: count, label: `Port ${index} von ${count} erstellt (${label})` });
        }
    }
}

// generate form fields
function generateField(name, config) {
    const wrapper = document.createElement('div');
    wrapper.className = 'pb-6 h-fit w-full max-w-lg relative';
    let field;

    if (name.startsWith('expected_')) {
        wrapper.classList.add('hidden');

        field = document.createElement('input');
        field.type = 'hidden';
        field.name = name;
        wrapper.appendChild(field);

        setTimeout(() => {
            const sourceFieldName = name.substring('expected_'.length);
            const form = wrapper.closest('form');
            if (!form) return;

            const sourceField = form.querySelector(`[name="${sourceFieldName}"]`);

            if (sourceField) {
                const updateValue = () => {
                    field.value = sourceField.value;
                };
                
                updateValue();

                sourceField.addEventListener('input', updateValue);
                sourceField.addEventListener('change', updateValue);

                const observer = new MutationObserver(updateValue);
                observer.observe(sourceField, {
                    attributes: true,
                    attributeFilter: ['value']
                });
            }
        }, 0);

        return wrapper;
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
                if (!textInput.value) {
                    hiddenField.value = '';
                }
                else if (textInput.value !== lastSelectedText) {
                    hiddenField.value = '';
                }

                try {
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
                        let resourceBase = config.resource.replace(/_details$/, '');
                        let uuidKey = Object.keys(item).find(k => k === resourceBase + '_uuid') 
                            || Object.keys(item).find(k => k.endsWith('_uuid')) 
                            || 'uuid';
                        let displayLabel = formatSearchResultLabel(item, config);
                    
                        const entry = document.createElement('div');
                        entry.className = 'hover:bg-gray-100 cursor-pointer p-2';
                        entry.textContent = displayLabel || item[uuidKey] || '[kein Name]';
                        entry.onclick = () => {
                            textInput.value = displayLabel || '';
                            hiddenField.value = item[uuidKey] || '';
                            lastSelectedText = displayLabel || '';
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
    let autoPortConfig = null;
    let submitErrorMessage = '';

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

        if (postConfig.table === 'device') {
            const parsedCount = parseInt(postData.port_count || 0, 10) || 0;
            const parsedStartLabel = (postData.port_start_label || '').trim();
            const parsedItemGroup = (postData.item_group || '').trim();

            if (parsedItemGroup && !isValidPostgresUuid(parsedItemGroup)) {
                submitErrorMessage = 'Item Group muss eine gueltige UUID sein.';
                break;
            }

            postData.item_group = parsedItemGroup || null;

            if (currentDeviceCreateMode === 'template') {
                postData.template = false;
            }

            const isTemplateDevice = isTruthyTemplateValue(postData.template);

            autoPortConfig = {
                count: parsedCount,
                startLabel: parsedStartLabel
            };

            if (isTemplateDevice) {
                autoPortConfig.count = 0;
                autoPortConfig.startLabel = '';
            }

            // Do not block device creation if automatic port generation is incomplete.
            // Only run auto-port creation when both values are present.
            if (autoPortConfig.count > 0 && !autoPortConfig.startLabel) {
                console.warn('Auto port creation skipped: missing first port label.');
                autoPortConfig.count = 0;
            }

            delete postData.port_count;
            delete postData.port_start_label;
        }

        // UUIDs aus vorherigen POSTs einfügen, falls benötigt
        injectUuids(postData, postConfig);

        const editMode = itamFormState.mode === 'edit' && itamFormState.table === table;
        const targetUuid = editMode ? (itamFormState.uuids[postConfig.table] || '') : '';
        const httpMethod = editMode ? 'PATCH' : 'POST';
        const apiUrl = editMode
            ? `<?php echo PORTFLOW_HOSTNAME; ?>/api/${postConfig.table}/${targetUuid}`
            : `<?php echo PORTFLOW_HOSTNAME; ?>/api/${postConfig.table}/`;

        if (editMode && !targetUuid) {
            submitErrorMessage = `Keine UUID fuer Update in Tabelle ${postConfig.table} gefunden.`;
            break;
        }

        try {
            const response = await fetch(apiUrl, {
                method: httpMethod,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(postData)
            });

            let data = null;
            let rawBody = '';
            try {
                rawBody = await response.text();
                data = rawBody ? JSON.parse(rawBody) : null;
            } catch (parseError) {
                data = null;
            }

            if (!response.ok) {
                throw new Error(`API ${postConfig.table} failed (${response.status}): ${rawBody || 'no response body'}`);
            }

            const responseUuid = data && data[0] && data[0].uuid ? String(data[0].uuid) : '';
            const effectiveUuid = editMode ? String(targetUuid) : responseUuid;

            if (!effectiveUuid) {
                throw new Error(`API ${postConfig.table} returned no UUID.`);
            }

            responseUuids[postConfig.table] = effectiveUuid;
            if (postConfig.table === 'metadata') responseUuids.metadata = effectiveUuid;
            if (postConfig.table === 'device_port_vlan') responseUuids.device_port_vlan = effectiveUuid;
            if (postConfig.table === 'device_port_ip') responseUuids.device_port_ip = effectiveUuid;

            if (!editMode && postConfig.table === 'device' && autoPortConfig && autoPortConfig.count > 0) {
                setProgressOverlayState(true);
                updateProgressOverlay('Auto-Ports werden erstellt ...', 0, autoPortConfig.count);

                await createAutoPortsForDevice(effectiveUuid, autoPortConfig, (progress) => {
                    updateProgressOverlay(progress.label || 'Auto-Ports werden erstellt ...', progress.current || 0, progress.total || autoPortConfig.count);
                });
            }
        } catch (error) {
            console.error(`Fehler beim Senden der ${postConfig.table}-Daten:`, error);
            submitErrorMessage = (error && error.message) ? error.message : `Fehler beim Senden der ${postConfig.table}-Daten.`;
            break;
        }
    }

    if (submitErrorMessage) {
        setProgressOverlayState(false);
        alert('Eintrag konnte nicht vollstaendig gespeichert werden.\n\n' + submitErrorMessage);
        return;
    }

    setProgressOverlayState(false);
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
        currentNavConfig = config || {};
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
    const navScopes = ['#itam_nav li', '#itam_nav_mobile li'];

    navScopes.forEach(selector => {
        document.querySelectorAll(selector).forEach(item => {
            item.classList.remove('itam-nav-item-active');
        });
    });

    navScopes.forEach(selector => {
        const selectedItem = document.querySelector(`${selector}[data-table="${table}"]`);
        if (selectedItem) {
            selectedItem.classList.add('itam-nav-item-active');
        }
    });
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
                if (colKey === 'device_port_metadata_caption') {
                    td = $('<td class="p-2">').html(
                        `${row.device_port_device_metadata_caption || '--'} <br> <span class="text-xs text-gray-500">${row.device_port_metadata_caption || '--'}</span> <br> <span class="text-xs text-gray-500">${row.device_port_device_port_vlan_vlan_vlan || '--'}</span>`
                    );
                } else if (colKey === 'device_port_metadata_status') {
                    td = $('<td class="p-2">').html(createStatusIcon('ethernet-port', row.device_port_metadata_status));
                } else if (colKey === 'device_port_metadata_tags') {
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
                if (colKey === 'connection_metadata_status') {
                    td = $('<td class="p-2">').html(createStatusIcon('link', row.connection_metadata_status));
                } else if (colKey === 'connection_metadata_tags') {
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
        let editButton = $('<button class="h-10 w-10 rounded-full bg-blue-500 hover:bg-blue-700 text-white flex items-center justify-center">')
            .html('<i data-lucide="pencil"></i>')
            .click(() => openEditEntry(row));
        let detailsButton = $('<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center">')
            .html('<i data-lucide="info"></i>')
            .click(() => openDetailsPopup(row));
        let deleteButton = $('<button class="h-10 w-10 rounded-full bg-red-500 hover:bg-red-700 text-white flex items-center justify-center">')
            .html('<i data-lucide="trash"></i>')
            .click(() => deleteEntry(row.uuid, row));
        return $('<td class="p-2 flex flex-row gap-4">').append(editButton).append(detailsButton).append(deleteButton);
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
async function openNewEntry() {
    // Öffnet das Formular für einen neuen Eintrag
    console.log("Neuer Eintrag wird erstellt");
    await generateFormFromJSON(currentTable, { mode: 'create' });
    document.getElementById('formContainer').classList.remove('hidden');
}

async function openEditEntry(rowData) {
    if (!rowData) {
        return;
    }

    console.log('Eintrag wird bearbeitet', rowData);
    await generateFormFromJSON(currentTable, { mode: 'edit', rowData });
    document.getElementById('formContainer').classList.remove('hidden');
}

function closeNewEntry() {
    // Popup für neuen Eintrag ausblenden
    document.getElementById('formContainer').classList.add('hidden');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function resolveBaseTableFromCurrent() {
    return String(currentTable || '').replace(/_details$/, '').replace(/_join_.+$/, '');
}

function resolveDetailsLabel(fieldKey, tableConfig) {
    const columns = tableConfig && tableConfig.columns ? tableConfig.columns : {};
    return columns[fieldKey] || fieldKey;
}

function resolveDetailsValue(fieldKey, rowData) {
    const value = rowData[fieldKey];
    if (value === null || value === undefined) {
        return '--';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    const normalized = String(value).trim();
    if (normalized === '') {
        return '--';
    }

    if (normalized === 'true' || normalized === 't' || normalized === '1') {
        return 'Yes';
    }

    if (normalized === 'false' || normalized === 'f' || normalized === '0') {
        return 'No';
    }

    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/.test(normalized)) {
        const parsed = Date.parse(normalized);
        if (!Number.isNaN(parsed)) {
            return new Date(parsed).toLocaleString();
        }
    }

    return String(value);
}

function findFirstExistingKey(rowData, candidates) {
    for (const key of candidates) {
        if (Object.prototype.hasOwnProperty.call(rowData, key) && rowData[key] !== null && rowData[key] !== undefined && String(rowData[key]).trim() !== '') {
            return key;
        }
    }
    return '';
}

function getDisplayValue(rowData, candidates, fallback = '--') {
    const key = findFirstExistingKey(rowData, candidates);
    return key ? resolveDetailsValue(key, rowData) : fallback;
}

function detectMetadataPrefix(rowData, baseTable) {
    const preferred = [`${baseTable}_metadata_`, 'metadata_'];
    for (const prefix of preferred) {
        if (Object.keys(rowData).some((key) => key.startsWith(prefix))) {
            return prefix;
        }
    }

    const metadataCaptionKey = Object.keys(rowData)
        .filter((key) => key.endsWith('_metadata_caption'))
        .sort((a, b) => a.length - b.length)[0];

    if (metadataCaptionKey) {
        return metadataCaptionKey.replace(/caption$/, '');
    }

    return '';
}

function collectStringValuesDeep(input, collector) {
    if (typeof input === 'string') {
        collector.push(input);
        return;
    }

    if (Array.isArray(input)) {
        input.forEach((item) => collectStringValuesDeep(item, collector));
        return;
    }

    if (input && typeof input === 'object') {
        Object.values(input).forEach((item) => collectStringValuesDeep(item, collector));
    }
}

function parseStructuredCandidateStrings(rawValue) {
    const result = [];
    if (rawValue === null || rawValue === undefined) {
        return result;
    }

    const asString = String(rawValue).trim();
    if (asString === '') {
        return result;
    }

    result.push(asString);

    if ((asString.startsWith('{') && asString.endsWith('}')) || (asString.startsWith('[') && asString.endsWith(']'))) {
        try {
            const parsed = JSON.parse(asString);
            collectStringValuesDeep(parsed, result);
        } catch (error) {
            // Keep plain string fallback when metadata is not valid JSON.
        }
    }

    return result;
}

function extractAttachmentLinksFromRow(rowData) {
    const baseTable = resolveBaseTableFromCurrent();
    const metadataPrefix = detectMetadataPrefix(rowData, baseTable);
    const linkSources = [];

    if (metadataPrefix) {
        ['specification', 'description', 'tags'].forEach((suffix) => {
            const key = `${metadataPrefix}${suffix}`;
            if (Object.prototype.hasOwnProperty.call(rowData, key)) {
                linkSources.push(rowData[key]);
            }
        });
    }

    const candidates = [];
    linkSources.forEach((value) => {
        parseStructuredCandidateStrings(value).forEach((entry) => candidates.push(entry));
    });

    const urlPattern = /(https?:\/\/[^\s"'<>]+|\/[^\s"'<>]+\.(?:png|jpe?g|gif|webp|svg|pdf|txt|md|csv))/ig;
    const links = [];
    const seen = new Set();

    candidates.forEach((chunk) => {
        const matches = chunk.match(urlPattern) || [];
        matches.forEach((match) => {
            const normalized = String(match || '').trim();
            if (!normalized || seen.has(normalized)) {
                return;
            }

            seen.add(normalized);
            const isImage = /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(normalized);
            const href = normalized.startsWith('/')
                ? `<?php echo PORTFLOW_HOSTNAME; ?>${normalized}`
                : normalized;

            links.push({ href, label: normalized, isImage });
        });
    });

    return links;
}

async function loadAttachmentMetadataForRow(rowData) {
    const baseTable = resolveBaseTableFromCurrent();
    const baseUuid = String(rowData[`${baseTable}_uuid`] || rowData.uuid || '').trim();
    if (!baseTable || !baseUuid) {
        return [];
    }

    try {
        const query = `?reference_table=${encodeURIComponent(baseTable)}&reference_uuid=${encodeURIComponent(baseUuid)}&limit=50`;
        const response = await fetch(`<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=metadata${query}`);
        if (response.ok) {
            const payload = await response.json();
            return (payload && Array.isArray(payload.items)) ? payload.items : [];
        }
    } catch (error) {
        console.error('Fehler beim Laden der Anhänge:', error);
    }
    return [];
}

async function loadJournalEntriesForRow(rowData) {
    const baseTable = resolveBaseTableFromCurrent();
    const baseUuid = String(rowData[`${baseTable}_uuid`] || rowData.uuid || '').trim();
    if (!baseTable || !baseUuid) {
        return [];
    }

    const query = `?journal_reference_table=${encodeURIComponent(baseTable)}&journal_reference_uuid=${encodeURIComponent(baseUuid)}&limit=20`;
    const detailsResponse = await fetch(`<?php echo PORTFLOW_HOSTNAME; ?>/api/journal_details${query}`);
    if (detailsResponse.ok) {
        const detailsPayload = await detailsResponse.json();
        const detailItems = (detailsPayload && Array.isArray(detailsPayload.items)) ? detailsPayload.items : [];
        if (detailItems.length > 0) {
            return detailItems;
        }
    }

    const fallbackQuery = `?reference_table=${encodeURIComponent(baseTable)}&reference_uuid=${encodeURIComponent(baseUuid)}&limit=20`;
    const response = await fetch(`<?php echo PORTFLOW_HOSTNAME; ?>/api/journal${fallbackQuery}`);
    const payload = await response.json();
    return (payload && Array.isArray(payload.items)) ? payload.items : [];
}

function parseExecutionPayload(rawPayload) {
    try {
        const decoded = JSON.parse(rawPayload || '{}');
        return (decoded && typeof decoded === 'object') ? decoded : null;
    } catch (error) {
        return null;
    }
}

async function loadSwitchScriptHistory(deviceUuid, switchAliases = []) {
    const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/changelog?changed_table=script_execution&limit=200');
    const payload = await response.json();
    const items = (payload && Array.isArray(payload.items)) ? payload.items : [];
    const normalizedAliases = Array.isArray(switchAliases)
        ? switchAliases.map(alias => String(alias || '').trim().toLowerCase()).filter(Boolean)
        : [];
    const normalizeUuid = (value) => String(value || '').trim().toLowerCase();
    const normalizedDeviceUuid = normalizeUuid(deviceUuid);

    const filtered = items
        .map(item => {
            const execution = parseExecutionPayload(item.changed_data || '');
            if (!execution) {
                return null;
            }

            const payloadDeviceUuid = normalizeUuid(execution.device_uuid || execution.device_id || execution.uuid || '');
            const payloadSwitchName = String(execution.switch || execution.switch_name || '').trim().toLowerCase();
            const matchesDevice = normalizedDeviceUuid !== '' && payloadDeviceUuid !== '' && payloadDeviceUuid === normalizedDeviceUuid;
            const matchesSwitch = normalizedAliases.length > 0 && normalizedAliases.includes(payloadSwitchName);

            if (!matchesDevice && !matchesSwitch) {
                return null;
            }

            return {
                changedAt: item.changed || '',
                mode: String(execution.mode || ''),
                profile: String(execution.profile || ''),
                template: String(execution.template || ''),
                commandCount: Number(execution.command_count || 0),
                ok: !!execution.ok,
                warning: !!execution.warning,
                scriptContent: String(execution.script_content || '')
            };
        })
        .filter(Boolean)
        .sort((a, b) => {
            const aTime = Date.parse(a.changedAt || '') || 0;
            const bTime = Date.parse(b.changedAt || '') || 0;
            return bTime - aTime;
        });

    return filtered.slice(0, 8);
}

async function buildDetailsPanelContent(panel, rowData) {
    const panelType = String(panel.type || '').trim().toLowerCase();

    if (panelType === 'scripts') {
        const isSwitchDevice =
            currentTable === 'device_details'
            && String(rowData.device_type || '').toLowerCase() === 'switch'
            && String(rowData.device_uuid || '').trim() !== '';

        if (!isSwitchDevice) {
            return '<div class="itam-details-empty">Keine Skript-Ausfuehrungen fuer diesen Eintrag.</div>';
        }

        const entries = await loadSwitchScriptHistory(
            String(rowData.device_uuid || '').trim(),
            [
                String(rowData.device_metadata_caption || '').trim(),
                String(rowData.device_asset || '').trim(),
                String(rowData.device_serial || '').trim()
            ]
        );

        if (entries.length === 0) {
            return '<div class="itam-details-empty">Keine Ausfuehrungseintraege vorhanden.</div>';
        }

        return entries.map((entry) => {
            const changedAt = entry.changedAt ? new Date(entry.changedAt).toLocaleString() : '--';
            const statusText = entry.ok ? (entry.warning ? 'WARNUNG' : 'OK') : 'FEHLER';
            const scriptPreview = entry.scriptContent ? escapeHtml(entry.scriptContent) : '(kein Skriptinhalt gespeichert)';

            return `<div class="mt-2 p-2 rounded border border-slate-200 bg-slate-50">`
                + `<div class="text-xs font-semibold">${escapeHtml(changedAt)} | ${escapeHtml(statusText)} | ${escapeHtml(entry.mode)} | ${escapeHtml(entry.template)} | cmds=${escapeHtml(entry.commandCount)}</div>`
                + `<div class="text-xs text-slate-600">Profil: ${escapeHtml(entry.profile || '--')}</div>`
                + `<details class="mt-1"><summary class="cursor-pointer text-xs text-slate-700">Skriptinhalt</summary><pre class="mt-1 text-xs whitespace-pre-wrap">${scriptPreview}</pre></details>`
                + `</div>`;
        }).join('');
    }

    if (panelType === 'journal') {
        const entries = await loadJournalEntriesForRow(rowData);
        if (entries.length === 0) {
            return '<div class="itam-details-empty">Keine Journal-Eintraege vorhanden.</div>';
        }

        return entries.map((entry) => {
            const rowTitle = getDisplayValue(entry, ['journal_metadata_caption', 'metadata_caption', 'journal_uuid', 'uuid']);
            const rowStatus = getDisplayValue(entry, ['journal_metadata_status', 'metadata_status'], '--');
            const rowCreated = getDisplayValue(entry, ['journal_metadata_created', 'metadata_created', 'journal_created', 'created'], '--');
            const rowUser = getDisplayValue(entry, ['journal_metadata_users_username', 'metadata_users_username', 'journal_metadata_users', 'metadata_users'], '--');
            const rowDescription = getDisplayValue(entry, ['journal_metadata_description', 'metadata_description'], '');
            const journalUuid = entry.journal_uuid || entry.uuid || '';

            return `<div class="mt-2 p-2 rounded border border-slate-200 bg-slate-50 text-xs">`
                + `<div class="itam-details-journal-head">`
                + `<div class="itam-details-journal-title">${escapeHtml(rowTitle)}</div>`
                + `<div class="itam-details-journal-meta">Status ${escapeHtml(rowStatus)}</div>`
                + `</div>`
                + `<div class="itam-details-journal-meta">${escapeHtml(rowCreated)} | ${escapeHtml(rowUser)}</div>`
                + (rowDescription !== '--' && rowDescription !== '' ? `<div class="itam-details-journal-body">${escapeHtml(rowDescription)}</div>` : '')
                + `<div style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">`
                + `<button type="button" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; background-color: #3b82f6; color: white; border: none; border-radius: 0.25rem; cursor: pointer;" onclick="openEditJournalModal('${escapeHtml(journalUuid)}', '${escapeHtml(rowTitle).replace(/'/g, "\\'")}', '${escapeHtml(rowDescription).replace(/'/g, "\\'")}')" title="Bearbeiten"><i data-lucide="edit-2" style="width: 12px; height: 12px;"></i> Bearbeiten</button>`
                + `<button type="button" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; background-color: #ef4444; color: white; border: none; border-radius: 0.25rem; cursor: pointer;" onclick="deleteJournalEntry('${escapeHtml(journalUuid)}')" title="Löschen"><i data-lucide="trash-2" style="width: 12px; height: 12px;"></i> Löschen</button>`
                + `</div>`
                + `</div>`;
        }).join('');
    }

    if (panelType === 'attachments') {
        const attachments = await loadAttachmentMetadataForRow(rowData);
        if (attachments.length === 0) {
            return '<div class="itam-details-empty">Keine Anhaenge vorhanden.</div>';
        }

        return attachments.map((item) => {
            const fileName = item.caption || item.metadata_caption || 'Anlage';
            const description = item.description || item.metadata_description || '';
            const fileUrl = item.specification || '';
            const metadataUuid = item.uuid || item.metadata_uuid || '';
            const isImage = /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(fileUrl);
            const displayUrl = fileUrl.startsWith('/')
                ? `<?php echo PORTFLOW_HOSTNAME; ?>${fileUrl}`
                : fileUrl;
            const preview = isImage && fileUrl
                ? `<img class="itam-details-link-preview" loading="lazy" src="${escapeHtml(displayUrl)}" alt="Vorschau" />`
                : '';

            return `<div class="itam-details-link-item" style="margin-bottom: 0.5rem; padding: 0.5rem; border: 1px solid #e5e7eb; border-radius: 0.25rem;">`
                + (fileUrl ? `<a href="${escapeHtml(displayUrl)}" target="_blank" rel="noopener noreferrer" style="color: #0ea5e9; text-decoration: underline;">${escapeHtml(fileName)}</a>` : `<span>${escapeHtml(fileName)}</span>`)
                + (description ? `<div style="margin-top: 0.25rem; font-size: 0.75rem; color: #6b7280;">${escapeHtml(description)}</div>` : '')
                + preview
                + `<div style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">`
                + `<button type="button" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; background-color: #3b82f6; color: white; border: none; border-radius: 0.25rem; cursor: pointer;" onclick="openEditFileModal('${escapeHtml(metadataUuid)}', '${escapeHtml(fileName).replace(/'/g, "\\'")}', '${escapeHtml(description).replace(/'/g, "\\'")}')" title="Bearbeiten"><i data-lucide="edit-2" style="width: 12px; height: 12px;"></i> Bearbeiten</button>`
                + `<button type="button" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; background-color: #ef4444; color: white; border: none; border-radius: 0.25rem; cursor: pointer;" onclick="deleteFile('${escapeHtml(metadataUuid)}')" title="Löschen"><i data-lucide="trash-2" style="width: 12px; height: 12px;"></i> Löschen</button>`
                + `</div>`
                + `</div>`;
        }).join('');
    }

    return '<div class="itam-details-empty">Panel nicht konfiguriert.</div>';
}

async function renderDetailsGrid(rowData) {
    const tableConfig = currentNavConfig[currentTable] || {};
    const detailsLayout = tableConfig.details_layout || null;
    const $detailsContent = $('#detailsContent').empty();

    if (!detailsLayout || !Array.isArray(detailsLayout.primary_fields)) {
        Object.entries(rowData).forEach(([key, value]) => $detailsContent.append(`<p><strong>${escapeHtml(key)}:</strong> ${escapeHtml(value || '--')}</p>`));
        return;
    }

    const $grid = $('<div class="itam-details-grid"></div>');
    const $leftCard = $('<div class="itam-details-card"></div>');
    const $rightPanels = $('<div class="itam-details-panels"></div>');

    const primaryTitle = detailsLayout.primary_title || 'Stammdaten';
    $leftCard.append(`<div class="itam-details-title">${escapeHtml(primaryTitle)}</div>`);
    const $table = $('<table class="itam-details-table"><tbody></tbody></table>');
    const $tbody = $table.find('tbody');

    detailsLayout.primary_fields.forEach((fieldKey) => {
        const label = resolveDetailsLabel(fieldKey, tableConfig);
        const value = resolveDetailsValue(fieldKey, rowData);
        $tbody.append(`<tr><th>${escapeHtml(label)}</th><td>${escapeHtml(value)}</td></tr>`);
    });
    $leftCard.append($table);

    const panels = Array.isArray(detailsLayout.panels) ? detailsLayout.panels : [];
    for (const panel of panels) {
        const panelTitle = panel.title || panel.type || 'Panel';
        const $panelCard = $('<div class="itam-details-card"></div>');
        $panelCard.append(`<div class="itam-details-title">${escapeHtml(panelTitle)}</div>`);
        
        // Add action buttons to appropriate panels
        let panelType = panel.type?.toLowerCase() || '';
        if (panelType.includes('journal')) {
            const $actions = $('<div class="itam-details-actions" style="margin-bottom: 1rem;"></div>');
            const $journalBtn = $('<button type="button" class="itam-details-action-btn" onclick="openJournalEntryModal()" style="width: 100%;"><i data-lucide="message-square-plus"></i>Journaleintrag erstellen</button>');
            $actions.append($journalBtn);
            $panelCard.append($actions);
        } else if (panelType.includes('attachment') || panelType.includes('anhang') || panelType.includes('file')) {
            const $actions = $('<div class="itam-details-actions" style="margin-bottom: 1rem;"></div>');
            const $uploadBtn = $('<button type="button" class="itam-details-action-btn" onclick="openFileUploadModal()" style="width: 100%;"><i data-lucide="upload"></i>Datei hochladen</button>');
            $actions.append($uploadBtn);
            $panelCard.append($actions);
        }
        
        $panelCard.append('<div class="itam-details-empty">Lade Daten ...</div>');
        $rightPanels.append($panelCard);

        try {
            const html = await buildDetailsPanelContent(panel, rowData);
            $panelCard.find('.itam-details-empty').replaceWith(html);
        } catch (error) {
            $panelCard.find('.itam-details-empty').replaceWith('<div class="itam-details-empty">Daten konnten nicht geladen werden.</div>');
        }
    }

    $grid.append($leftCard).append($rightPanels);
    $detailsContent.append($grid);
    lucide.createIcons();
}

// Open and close details popup
async function openDetailsPopup(rowData) {
    currentDetailsRowData = rowData;
    await renderDetailsGrid(rowData);
    $('#detailsPopup').removeClass('hidden');
}

function closeDetailsPopup() {
    if (!$('#detailsPopup').hasClass('hidden')) {
        $('#detailsPopup').addClass('hidden');
    }
    currentDetailsRowData = null;
}

function openJournalEntryModal() {
    if (!currentDetailsRowData) {
        alert('Keine Zeile ausgewählt.');
        return;
    }
    document.getElementById('journalEntryCaption').value = '';
    document.getElementById('journalEntryDescription').value = '';
    document.getElementById('journalEntryModal').classList.add('active');
}

function closeJournalEntryModal() {
    document.getElementById('journalEntryModal').classList.remove('active');
}

function openFileUploadModal() {
    if (!currentDetailsRowData) {
        alert('Keine Zeile ausgewählt.');
        return;
    }
    document.getElementById('fileUploadInput').value = '';
    document.getElementById('fileUploadDescription').value = '';
    document.getElementById('fileUploadModal').classList.add('active');
}

function closeFileUploadModal() {
    document.getElementById('fileUploadModal').classList.remove('active');
    // Reset form
    document.getElementById('fileUploadInput').value = '';
    document.getElementById('fileUploadDescription').value = '';
    document.getElementById('fileUploadFeedback').style.display = 'none';
    document.getElementById('fileUploadProgress').style.display = 'none';
}

function updateFileSelection() {
    const fileInput = document.getElementById('fileUploadInput');
    const file = fileInput.files[0];
    const feedback = document.getElementById('fileUploadFeedback');
    
    if (file) {
        const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
        document.getElementById('fileUploadFileName').textContent = `Name: ${file.name}`;
        document.getElementById('fileUploadFileSize').textContent = `Größe: ${sizeMB} MB`;
        feedback.style.display = 'block';
    } else {
        feedback.style.display = 'none';
    }
}

// Delete entry
function deleteEntry(uuid, rowData) {
    if (confirm('Möchten Sie diesen Eintrag und alle zugehörigen Daten wirklich löschen?')) {
        fetch('<?php echo PORTFLOW_HOSTNAME; ?>' + '/forms.json')
        .then(response => response.json())
        .then(data => {
            const formConfig = data.forms[currentTable];
            if (!formConfig || !formConfig.postOrder) {
                console.error(`No form configuration found for table: ${currentTable}`);
                return;
            }

            // Tabellen in umgekehrter Reihenfolge des Erstellens zum Löschen vorbereiten
            const tablesInCreationOrder = formConfig.postOrder.map(item => item.table);
            const tablesToDeleteInReverse = [...tablesInCreationOrder].reverse();

            const uuidsForDeletion = {};

            // Für jede Tabelle aus der Konfiguration die korrekte UUID aus den Zeilendaten finden
            tablesInCreationOrder.forEach(table => {
                // Finde alle möglichen UUID-Schlüssel für diese Tabelle in den Zeilendaten
                const candidateKeys = Object.keys(rowData).filter(key => {
                    if (!key.endsWith('_uuid') || !rowData[key]) return false;
                    const keyWithoutSuffix = key.slice(0, -5); // "_uuid" entfernen
                    const parts = keyWithoutSuffix.split('_');
                    return parts[parts.length - 1] === table;
                });

                if (candidateKeys.length > 0) {
                    // Wähle den kürzesten Schlüssel -> dies ist die direkteste Beziehung
                    // z.B. 'device_metadata_uuid' wird vor 'device_location_metadata_uuid' bevorzugt
                    candidateKeys.sort((a, b) => a.length - b.length);
                    const bestKey = candidateKeys[0];
                    uuidsForDeletion[table] = rowData[bestKey];
                }
            });
            
            // Sicherstellen, dass die Haupt-UUID (der übergebene Parameter) auch enthalten ist
            const baseTable = currentTable.replace(/_details$/, '');
            if (!uuidsForDeletion[baseTable]) {
                uuidsForDeletion[baseTable] = uuid;
            }

            // Rekursive Funktion zum Löschen der Einträge
            function deleteNext(index) {
                if (index >= tablesToDeleteInReverse.length) {
                    console.log('Alle verknüpften Einträge wurden erfolgreich gelöscht.');
                    loadTable(currentTable); // Tabelle neu laden
                    return;
                }

                const table = tablesToDeleteInReverse[index];
                const uuidToDelete = uuidsForDeletion[table];

                if (!uuidToDelete) {
                    console.warn(`Keine UUID für Tabelle '${table}' gefunden, wird übersprungen.`);
                    deleteNext(index + 1);
                    return;
                }

                console.log(`Lösche Eintrag aus Tabelle '${table}' mit UUID: ${uuidToDelete}`);
                const url = `<?php echo PORTFLOW_HOSTNAME; ?>/api/${table}/${uuidToDelete}`;
                
                ajaxPost(url, 'DELETE', {}, () => {
                    console.log(`Eintrag aus '${table}' erfolgreich gelöscht.`);
                    deleteNext(index + 1);
                }, error => {
                    console.error(`Fehler beim Löschen des Eintrags aus '${table}':`, error);
                    // Optional: Hier den Prozess abbrechen oder trotzdem weitermachen
                    deleteNext(index + 1);
                });
            }

            // Starte den Löschvorgang
            deleteNext(0);
        })
        .catch(error => {
            console.error('Fehler beim Laden von forms.json:', error);
        });
    }
}

// Save user column preferences
function saveUserColumnPreferences(table, selectedColumns) {
    const settings = { [table]: { columns: selectedColumns } };
    ajaxPost(`${'<?php echo PORTFLOW_HOSTNAME; ?>'}/api/settings`, 'POST', settings, () => console.log('Preferences saved successfully'));
}

// Journal entry submission
async function submitJournalEntry() {
    if (!currentDetailsRowData) {
        alert('Keine Zeile ausgew\u00e4hlt.');
        return;
    }

    const caption = document.getElementById('journalEntryCaption').value.trim();
    const description = document.getElementById('journalEntryDescription').value.trim();

    if (!caption) {
        alert('Bitte geben Sie einen Titel ein.');
        return;
    }

    try {
        const baseTable = resolveBaseTableFromCurrent();
        const baseUuid = String(currentDetailsRowData[`${baseTable}_uuid`] || currentDetailsRowData.uuid || '').trim();

        if (!baseTable || !baseUuid) {
            alert('Konnte Basis-Tabelle oder UUID nicht bestimmen.');
            return;
        }

        // Create metadata entry for journal
        const metadataPayload = {
            status: '0',
            caption: caption,
            description: description,
            specification: '',
            tags: ''
        };

        const metadataResponse = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/metadata/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(metadataPayload)
        });

        const metadataResult = await metadataResponse.json();
        const metadataUuid = metadataResult && metadataResult[0] && metadataResult[0].uuid;

        if (!metadataUuid) {
            alert('Metadaten konnten nicht erstellt werden.');
            return;
        }

        // Create journal entry
        const journalPayload = {
            metadata: metadataUuid,
            reference_table: baseTable,
            reference_uuid: baseUuid
        };

        const journalResponse = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=journal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(journalPayload)
        });

        const journalResult = await journalResponse.json();

        if (!journalResult || !journalResult[0]) {
            alert('Journaleintrag konnte nicht erstellt werden.');
            return;
        }

        closeJournalEntryModal();
        // Reload details to show new journal entry
        await openDetailsPopup(currentDetailsRowData);
        alert('Journaleintrag erfolgreich erstellt!');
    } catch (error) {
        console.error('Fehler beim Erstellen des Journaleintrags:', error);
        alert('Ein Fehler ist aufgetreten: ' + error.message);
    }
}

// File upload submission
async function submitFileUpload() {
    if (!currentDetailsRowData) {
        alert('Keine Zeile ausgewählt.');
        return;
    }

    const fileInput = document.getElementById('fileUploadInput');
    const file = fileInput.files[0];
    const description = document.getElementById('fileUploadDescription').value.trim();

    if (!file) {
        alert('Bitte wählen Sie eine Datei aus.');
        return;
    }

    try {
        const submitBtn = document.getElementById('fileUploadSubmitBtn');
        const cancelBtn = document.getElementById('fileUploadCancelBtn');
        const progressDiv = document.getElementById('fileUploadProgress');
        const progressBar = document.getElementById('fileUploadProgressBar');
        const progressPercent = document.getElementById('fileUploadProgressPercent');

        submitBtn.disabled = true;
        cancelBtn.disabled = true;
        progressDiv.style.display = 'block';

        const baseTable = resolveBaseTableFromCurrent();
        const baseUuid = String(currentDetailsRowData[`${baseTable}_uuid`] || currentDetailsRowData.uuid || '').trim();

        if (!baseTable || !baseUuid) {
            alert('Konnte Basis-Tabelle oder UUID nicht bestimmen.');
            submitBtn.disabled = false;
            cancelBtn.disabled = false;
            progressDiv.style.display = 'none';
            return;
        }

        // Upload file with XMLHttpRequest for progress tracking
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percentComplete = Math.round((e.loaded / e.total) * 100);
                    progressBar.style.width = percentComplete + '%';
                    progressPercent.textContent = percentComplete + '%';
                }
            });

            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    try {
                        const responseText = xhr.responseText.trim();
                        console.log('Upload Response:', responseText);
                        const uploadResult = JSON.parse(responseText);

                        if (!uploadResult || !uploadResult.file_url) {
                            alert('Datei konnte nicht hochgeladen werden.');
                            submitBtn.disabled = false;
                            cancelBtn.disabled = false;
                            progressDiv.style.display = 'none';
                            return;
                        }

                        // Save file URL to metadata
                        const metadataData = {
                            reference_table: baseTable,
                            reference_uuid: baseUuid,
                            caption: uploadResult.file_name || 'Attachment',
                            description: description || uploadResult.description || '',
                            specification: uploadResult.file_url
                        };

                        fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=metadata', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(metadataData)
                        }).then(() => {
                            closeFileUploadModal();
                            // Reload details to show new attachment
                            openDetailsPopup(currentDetailsRowData);
                            alert('Datei erfolgreich hochgeladen!');
                            resolve();
                        }).catch((error) => {
                            console.error('Fehler beim Speichern der Metadaten:', error);
                            alert('Datei hochgeladen, aber Metadaten konnten nicht gespeichert werden.');
                            submitBtn.disabled = false;
                            cancelBtn.disabled = false;
                            progressDiv.style.display = 'none';
                            reject(error);
                        });
                    } catch (parseError) {
                        console.error('JSON Parse Error Response:', xhr.responseText);
                        alert('Fehler beim Verarbeiten der Antwort: ' + parseError.message + '\n\nServer antwort (siehe Konsole): ' + xhr.responseText.substring(0, 200));
                        submitBtn.disabled = false;
                        cancelBtn.disabled = false;
                        progressDiv.style.display = 'none';
                        reject(parseError);
                    }
                } else {
                    let errorMsg = 'Datei konnte nicht hochgeladen werden.';
                    try {
                        const errorResult = JSON.parse(xhr.responseText);
                        if (errorResult && errorResult.error) {
                            errorMsg = errorResult.error;
                        }
                    } catch (e) {
                        errorMsg = 'Server Error: ' + xhr.responseText.substring(0, 200);
                    }
                    alert(errorMsg);
                    submitBtn.disabled = false;
                    cancelBtn.disabled = false;
                    progressDiv.style.display = 'none';
                    reject(new Error(errorMsg));
                }
            });

            xhr.addEventListener('error', () => {
                alert('Ein Fehler ist aufgetreten beim Upload.');
                submitBtn.disabled = false;
                cancelBtn.disabled = false;
                progressDiv.style.display = 'none';
                reject(new Error('Upload failed'));
            });

            xhr.addEventListener('abort', () => {
                console.log('Upload abgebrochen');
                submitBtn.disabled = false;
                cancelBtn.disabled = false;
                progressDiv.style.display = 'none';
                reject(new Error('Upload aborted'));
            });

            const formData = new FormData();
            formData.append('file', file);
            formData.append('reference_table', baseTable);
            formData.append('reference_uuid', baseUuid);
            formData.append('description', description);

            xhr.open('POST', '<?php echo PORTFLOW_HOSTNAME; ?>/api/upload', true);
            xhr.send(formData);
        });
    } catch (error) {
        console.error('Fehler beim Hochladen der Datei:', error);
        alert('Ein Fehler ist aufgetreten: ' + error.message);
        const submitBtn = document.getElementById('fileUploadSubmitBtn');
        const cancelBtn = document.getElementById('fileUploadCancelBtn');
        submitBtn.disabled = false;
        cancelBtn.disabled = false;
        document.getElementById('fileUploadProgress').style.display = 'none';
    }
}

// Edit Journal Entry functions
function openEditJournalModal(journalUuid, caption, description) {
    currentEditingJournalUuid = journalUuid;
    document.getElementById('editJournalCaption').value = caption || '';
    document.getElementById('editJournalDescription').value = description || '';
    document.getElementById('editJournalModal').classList.add('active');
}

function closeEditJournalModal() {
    document.getElementById('editJournalModal').classList.remove('active');
    currentEditingJournalUuid = null;
}

async function submitEditJournal() {
    if (!currentEditingJournalUuid) {
        alert('Keine Journal-UUID gefunden.');
        return;
    }

    const caption = document.getElementById('editJournalCaption').value.trim();
    const description = document.getElementById('editJournalDescription').value.trim();

    try {
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=journal', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                uuid: currentEditingJournalUuid,
                caption: caption,
                description: description
            })
        });

        if (response.ok) {
            closeEditJournalModal();
            await openDetailsPopup(currentDetailsRowData);
            alert('Journaleintrag erfolgreich aktualisiert!');
        } else {
            alert('Fehler beim Aktualisieren des Journaleintrags.');
        }
    } catch (error) {
        console.error('Fehler beim Aktualisieren:', error);
        alert('Ein Fehler ist aufgetreten: ' + error.message);
    }
}

async function deleteJournalEntry(journalUuid) {
    if (!confirm('Möchten Sie diesen Journaleintrag wirklich löschen?')) {
        return;
    }

    try {
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=journal&uuid=' + journalUuid, {
            method: 'DELETE'
        });

        if (response.ok) {
            await openDetailsPopup(currentDetailsRowData);
            alert('Journaleintrag erfolgreich gelöscht!');
        } else {
            alert('Fehler beim Löschen des Journaleintrags.');
        }
    } catch (error) {
        console.error('Fehler beim Löschen:', error);
        alert('Ein Fehler ist aufgetreten: ' + error.message);
    }
}

// Edit File/Attachment functions
function openEditFileModal(metadataUuid, fileName, description) {
    currentEditingMetadataUuid = metadataUuid;
    document.getElementById('editFileName').value = fileName || '';
    document.getElementById('editFileDescription').value = description || '';
    document.getElementById('editFileModal').classList.add('active');
}

function closeEditFileModal() {
    document.getElementById('editFileModal').classList.remove('active');
    currentEditingMetadataUuid = null;
}

async function submitEditFile() {
    if (!currentEditingMetadataUuid) {
        alert('Keine Datei-UUID gefunden.');
        return;
    }

    const description = document.getElementById('editFileDescription').value.trim();

    try {
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=metadata', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                uuid: currentEditingMetadataUuid,
                description: description
            })
        });

        if (response.ok) {
            closeEditFileModal();
            await openDetailsPopup(currentDetailsRowData);
            alert('Anlage erfolgreich aktualisiert!');
        } else {
            alert('Fehler beim Aktualisieren der Anlage.');
        }
    } catch (error) {
        console.error('Fehler beim Aktualisieren:', error);
        alert('Ein Fehler ist aufgetreten: ' + error.message);
    }
}

async function deleteFile(metadataUuid) {
    if (!confirm('Möchten Sie diese Datei wirklich löschen?')) {
        return;
    }

    try {
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=metadata&uuid=' + metadataUuid, {
            method: 'DELETE'
        });

        if (response.ok) {
            await openDetailsPopup(currentDetailsRowData);
            alert('Datei erfolgreich gelöscht!');
        } else {
            alert('Fehler beim Löschen der Datei.');
        }
    } catch (error) {
        console.error('Fehler beim Löschen:', error);
        alert('Ein Fehler ist aufgetreten: ' + error.message);
    }
}
</script>
<?php
    include_once 'includes/footer.php';
?>
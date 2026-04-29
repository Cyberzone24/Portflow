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

    .itam-shell {
        align-items: stretch;
        height: calc(100vh - 7.2rem);
    }

    @media (min-width: 1024px) {
        .itam-shell {
            grid-template-columns: minmax(76px, 15.5rem) minmax(0, 1fr);
        }

        .itam-shell.sidebar-collapsed {
            grid-template-columns: 4.75rem minmax(0, 1fr);
        }

        .itam-details-grid {
            grid-template-columns: minmax(300px, 0.95fr) minmax(0, 1.35fr);
            align-items: start;
        }
    }

    #detailsPopup:not(.hidden) {
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    #detailsPopup.hidden {
        display: none;
    }

    #detailsPopupHeader {
        flex: 0 0 auto;
    }

    #detailsContent,
    #detailsContent3d {
        min-height: 0;
        overflow-y: auto;
    }
        #detailsContent3d {
            width: 100%;
        }
        #detailsContent3d > .viewer3d-layout {
            flex: 1 1 auto;
            min-height: 0;
            width: 100%;
        }

    .viewer-expert-menu summary {
        cursor: pointer;
        user-select: none;
        list-style: none;
    }

    .viewer-expert-menu summary::-webkit-details-marker {
        display: none;
    }
</style>
<div class="itam-shell mx-4 mb-4 mt-0 grid min-h-0 grid-cols-1 gap-4" id="itamShell">
    <aside class="itam-sidebar relative hidden min-h-0 overflow-auto rounded-2xl border border-slate-300 bg-white p-4 lg:flex lg:flex-col lg:justify-between" id="itamSidebar">  
        <div class="itam-sidebar-head mb-3 flex flex-col justify-between gap-2">
            <div class="itam-sidebar-copy mb-4">
                <p class="text-lg font-bold"><?php echo $lang['it asset-management']; ?></p>
            </div>
            <ul class="itam-nav grid gap-2.5 lg:gap-3" id="itam_nav">
                <li onclick="loadTable('location_details')" data-table="location_details" class="itam-nav-item flex cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-blue-600 bg-blue-600 px-3 py-2.5 font-semibold text-white transition hover:bg-slate-100 hover:text-slate-800"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="map-pin"></i><span class="itam-nav-label truncate"><?php echo $lang['location']; ?></span></span><span class="itam-nav-chevron"><i class="h-4 w-4 flex-shrink-0" data-lucide="chevron-right"></i></span></li>
                <li onclick="loadTable('ip_range_join_metadata')" data-table="ip_range_join_metadata" class="itam-nav-item flex cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="network"></i><span class="itam-nav-label truncate"><?php echo $lang['ipam']; ?></span></span><span class="itam-nav-chevron"><i class="h-4 w-4 flex-shrink-0" data-lucide="chevron-right"></i></span></li>
                <li onclick="loadTable('vlan_details')" data-table="vlan_details" class="itam-nav-item flex cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="layers"></i><span class="itam-nav-label truncate"><?php echo $lang['vlan']; ?></span></span><span class="itam-nav-chevron"><i class="h-4 w-4 flex-shrink-0" data-lucide="chevron-right"></i></span></li>
                <li onclick="loadTable('device_details')" data-table="device_details" class="itam-nav-item flex cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="server"></i><span class="itam-nav-label truncate"><?php echo $lang['devices']; ?></span></span><span class="itam-nav-chevron"><i class="h-4 w-4 flex-shrink-0" data-lucide="chevron-right"></i></span></li>
                <li onclick="loadTable('device_port_details')" data-table="device_port_details" class="itam-nav-item flex cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="ethernet-port"></i><span class="itam-nav-label truncate"><?php echo $lang['device ports']; ?></span></span><span class="itam-nav-chevron"><i class="h-4 w-4 flex-shrink-0" data-lucide="chevron-right"></i></span></li>
                <li onclick="loadTable('device_port_vlan_details')" data-table="device_port_vlan_details" class="itam-nav-item flex cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="tag"></i><span class="itam-nav-label truncate"><?php echo $lang['port vlans']; ?></span></span><span class="itam-nav-chevron"><i class="h-4 w-4 flex-shrink-0" data-lucide="chevron-right"></i></span></li>
                <li onclick="loadTable('connection_details')" data-table="connection_details" class="itam-nav-item flex cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="link-2"></i><span class="itam-nav-label truncate"><?php echo $lang['connections']; ?></span></span><span class="itam-nav-chevron"><i class="h-4 w-4 flex-shrink-0" data-lucide="chevron-right"></i></span></li>
            </ul>
        </div>
        <button id="itamSidebarToggle" class="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-slate-400 text-white shadow-md transition hover:bg-slate-500" type="button" title="Leiste verkleinern">
            <i data-lucide="panel-left"></i>
        </button>
    </aside>
    <div class="itam-content relative min-h-0 overflow-hidden rounded-2xl border border-slate-300 bg-white">
        <div class="flex h-full min-h-0 w-full flex-col p-4">
            <div class="mb-4">
                <div class="text-2xl font-bold text-slate-900"><?php echo $lang['it asset-management']; ?></div>
                <div class="mt-1 text-sm text-slate-500"><?php echo $lang['it asset-management note']; ?></div>
            </div>

            <ul class="itam-mobile-subnav flex items-center gap-2 overflow-x-auto pb-1 lg:hidden" id="itam_nav_mobile">
                <li onclick="loadTable('location_details')" data-table="location_details" class="itam-nav-item flex flex-none cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-blue-600 bg-blue-600 px-3 py-2.5 font-semibold text-white transition hover:bg-slate-100 hover:text-slate-800"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="map-pin"></i><span class="itam-nav-label truncate"><?php echo $lang['location']; ?></span></span></li>
                <li onclick="loadTable('ip_range_join_metadata')" data-table="ip_range_join_metadata" class="itam-nav-item flex flex-none cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="network"></i><span class="itam-nav-label truncate"><?php echo $lang['ipam']; ?></span></span></li>
                <li onclick="loadTable('vlan_details')" data-table="vlan_details" class="itam-nav-item flex flex-none cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="layers"></i><span class="itam-nav-label truncate"><?php echo $lang['vlan']; ?></span></span></li>
                <li onclick="loadTable('device_details')" data-table="device_details" class="itam-nav-item flex flex-none cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="server"></i><span class="itam-nav-label truncate"><?php echo $lang['devices']; ?></span></span></li>
                <li onclick="loadTable('device_port_details')" data-table="device_port_details" class="itam-nav-item flex flex-none cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="ethernet-port"></i><span class="itam-nav-label truncate"><?php echo $lang['device ports']; ?></span></span></li>
                <li onclick="loadTable('device_port_vlan_details')" data-table="device_port_vlan_details" class="itam-nav-item flex flex-none cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="tag"></i><span class="itam-nav-label truncate"><?php echo $lang['port vlans']; ?></span></span></li>
                <li onclick="loadTable('connection_details')" data-table="connection_details" class="itam-nav-item flex flex-none cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-full border border-slate-300 bg-white px-3 py-2.5 font-semibold text-slate-800 transition hover:bg-slate-100"><span class="itam-nav-item-main inline-flex min-w-0 items-center gap-2.5"><i class="h-4 w-4 flex-shrink-0" data-lucide="link-2"></i><span class="itam-nav-label truncate"><?php echo $lang['connections']; ?></span></span></li>
            </ul>

            <div class="mb-4 grid flex-shrink-0 gap-3">
                <div class="block">
                    <div class="flex min-w-0 flex-nowrap items-center gap-2.5">
                        <form id="searchForm" class="flex min-w-0 flex-[0_1_30rem] items-center gap-2" enctype="multipart/form-data" onsubmit="return searchTable();">
                            <input type="text" name="search" placeholder="<?php echo $lang['search']; ?> ..." class="min-w-0 flex-1 rounded-full border border-slate-300 px-4 py-2.5 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        </form>
                        <div class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-green-500 text-white shadow-md hover:bg-green-700">
                            <button form="" onclick="openNewEntry()" class="new_entry_button inline-flex h-full w-full items-center justify-center text-2xl text-white"><i data-lucide="plus"></i></button>
                        </div>
                        <div class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-500 text-white shadow-md hover:bg-slate-700" title="<?php echo $lang['columns_customize'] ?? 'Spalten anpassen'; ?>">
                            <button type="button" onclick="openColumnPicker()" class="inline-flex h-full w-full items-center justify-center text-2xl text-white"><i data-lucide="columns-3"></i></button>
                        </div>
                        <div class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-sky-600 text-white shadow-md hover:bg-sky-700" title="<?php echo $lang['transfer_csv'] ?? 'CSV Import/Export'; ?>">
                            <button type="button" onclick="openTransferDialog()" class="inline-flex h-full w-full items-center justify-center text-2xl text-white"><i data-lucide="file-up"></i></button>
                        </div>
                    </div>
                </div>
                <div id="tableFilterBar" class="hidden"></div>
                <div class="grid items-center gap-3 md:grid-cols-[1fr_auto_1fr]">
                    <div class="inline-flex flex-wrap items-center gap-4">
                        <p id="count"></p>
                    </div>
                    <div id="pagination" class="flex flex-row"></div>
                    <div class="inline-flex items-center justify-end gap-2">
                        <p><?php echo $lang['quantity']; ?>:</p>
                        <select id="table_limit_1" name="limit" class="rounded-full border border-slate-300 bg-white px-3 py-1.5" onchange="setTableLimit(this.value)">
                            <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50</option>
                            <option value="100" <?php if ($limit == 100) echo 'selected'; ?>>100</option>
                            <option value="500" <?php if ($limit == 500) echo 'selected'; ?>>500</option>
                            <option value="1000" <?php if ($limit == 1000) echo 'selected'; ?>>1000</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="flex min-h-0 flex-1 overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm">
                <div class="itam-table-scroll h-full w-full overflow-y-auto">
                    <table class="static w-full min-w-full table-auto rounded-lg text-left text-sm text-gray-500 shadow-md">
                        <thead class="bg-white text-gray-800 top-0 sticky z-1"></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Details Popup -->
        <div id="detailsPopup" class="absolute top-0 left-0 h-full w-full rounded-lg bg-white p-4 z-2 hidden">
            <div class="absolute right-4 top-4 z-20 h-10 w-10 rounded-full bg-red-500 shadow-md hover:bg-red-700 flex justify-center">
                <button type="button" onclick="closeDetailsPopup()" class="text-2xl text-white"><i data-lucide="x"></i></button>
            </div>

            <div id="detailsPopupHeader" class="mb-3 pr-14">
                <div class="flex items-center justify-between pb-2">
                    <div class="text-xl font-bold">Details</div>
                </div>
                <!-- Tab Navigation für Location/Rack Details -->
                <!-- Tab Navigation für Location/Rack Details -->
                <div id="detailsTabNav" class="flex gap-2 border-b border-slate-200 mt-2" style="display: none;">
                    <button 
                        type="button"
                        data-tab="info"
                        class="details-tab active px-3 py-2 text-sm font-semibold text-slate-600 border-b-2 border-blue-600 transition"
                        onclick="switchDetailsTab('info')"
                    >
                        <i data-lucide="info" class="inline mr-1 h-4 w-4"></i>Informationen
                    </button>
                    <button 
                        type="button"
                        data-tab="3d"
                        id="detailsTab3dBtn"
                        class="details-tab px-3 py-2 text-sm font-semibold text-slate-600 border-b-2 border-transparent transition hover:border-slate-300"
                        onclick="switchDetailsTab('3d')"
                        style="display: none;"
                    >
                        <i data-lucide="cube" class="inline mr-1 h-4 w-4"></i>3D Ansicht
                    </button>
                    <button 
                        type="button"
                        data-tab="topology"
                        id="detailsTabTopologyBtn"
                        class="details-tab px-3 py-2 text-sm font-semibold text-slate-600 border-b-2 border-transparent transition hover:border-slate-300"
                        onclick="switchDetailsTab('topology')"
                        style="display: none;"
                    >
                        <i data-lucide="route" class="inline mr-1 h-4 w-4"></i>Topologie
                    </button>
                </div>
            </div>
            
            <!-- Tab Content: Info (Standard) -->
            <div id="detailsContent" class="space-y-2 details-tab-content flex-1" data-tab="info" style="display: block;"></div>
            
            <!-- Tab Content: 3D View (wird per Include eingefügt) -->
            <div id="detailsContent3d" class="space-y-2 details-tab-content flex-1" data-tab="3d" style="display: none;"></div>

            <!-- Tab Content: Topology (Switch 2D / Cable Trace) -->
            <div id="detailsContentTopology" class="space-y-3 details-tab-content flex-1 overflow-auto" data-tab="topology" style="display: none;"></div>
        </div>

        <!-- New Location -->
        <div id="formContainer" class="absolute top-0 left-0 h-full w-full p-4 bg-white rounded-lg z-2 overflow-y-auto hidden newEntry"></div>

        <div id="itamProgressOverlay" class="absolute inset-0 z-30 hidden items-center justify-center bg-slate-900/50 p-4" aria-live="polite" aria-hidden="true">
            <div class="grid w-full max-w-2xl gap-3 rounded-2xl border border-slate-300 bg-white p-4 shadow-2xl">
                <div class="font-bold text-slate-900">Eintrag wird gespeichert</div>
                <div id="itamProgressCopy" class="text-[0.95rem] text-slate-600">Bitte warten ...</div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-200">
                    <div id="itamProgressBar" class="h-full w-0 rounded-full bg-gradient-to-r from-blue-600 to-sky-400 transition-[width] duration-200 ease-out"></div>
                </div>
                <div id="itamProgressCount" class="text-[0.95rem] text-slate-600">0 / 0</div>
            </div>
        </div>

        <div id="itamTransferModal" class="absolute inset-0 z-40 hidden items-center justify-center bg-slate-900/55 p-4">
            <div class="grid max-h-full w-full max-w-6xl gap-4 overflow-hidden rounded-2xl border border-slate-300 bg-white p-4 shadow-2xl lg:grid-cols-[minmax(0,1.5fr)_minmax(20rem,0.9fr)]">
                <div class="grid min-h-0 gap-3 overflow-hidden">
                    <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-3">
                        <div>
                            <div id="itamTransferTitle" class="text-xl font-bold text-slate-900"><?php echo $lang['transfer_csv'] ?? 'CSV Import/Export'; ?></div>
                            <div id="itamTransferSubtitle" class="mt-1 text-sm text-slate-500"><?php echo $lang['transfer_hint'] ?? 'Accepted and required fields for the current view.'; ?></div>
                        </div>
                        <button type="button" class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-500 text-white hover:bg-slate-700" onclick="closeTransferDialog()"><i data-lucide="x"></i></button>
                    </div>
                    <div class="min-h-0 overflow-auto rounded-xl border border-slate-200">
                        <table class="w-full min-w-full table-auto text-left text-sm text-slate-700">
                            <thead class="sticky top-0 bg-slate-100 text-slate-900">
                                <tr>
                                    <th class="p-2 font-semibold"><?php echo $lang['transfer_field'] ?? 'Field'; ?></th>
                                    <th class="p-2 font-semibold"><?php echo $lang['transfer_label'] ?? 'Label'; ?></th>
                                    <th class="p-2 font-semibold"><?php echo $lang['transfer_required'] ?? 'Required'; ?></th>
                                    <th class="p-2 font-semibold"><?php echo $lang['transfer_type'] ?? 'Type'; ?></th>
                                    <th class="p-2 font-semibold"><?php echo $lang['transfer_notes'] ?? 'Notes'; ?></th>
                                </tr>
                            </thead>
                            <tbody id="itamTransferPreviewBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="grid gap-4 overflow-auto rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div class="grid gap-2">
                        <div class="text-sm font-semibold text-slate-900"><?php echo $lang['transfer_sample_title'] ?? 'Sample file'; ?></div>
                        <button type="button" class="rounded-full bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow hover:bg-slate-100" onclick="downloadTransferSample()">
                            <?php echo $lang['transfer_download_sample'] ?? 'Download sample CSV'; ?>
                        </button>
                    </div>
                    <div class="grid gap-2">
                        <div class="text-sm font-semibold text-slate-900"><?php echo $lang['transfer_export_title'] ?? 'Export'; ?></div>
                        <button type="button" class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" onclick="exportCurrentTableCsv()">
                            <?php echo $lang['transfer_export_csv'] ?? 'Export CSV'; ?>
                        </button>
                    </div>
                    <div class="grid gap-2">
                        <div class="text-sm font-semibold text-slate-900"><?php echo $lang['transfer_import_title'] ?? 'Import'; ?></div>
                        <input type="file" id="itamTransferFile" accept=".csv,text/csv" class="hidden" onchange="handleTransferFileSelected(this)">
                        <label for="itamTransferFile" class="cursor-pointer rounded-xl border-2 border-dashed border-slate-300 bg-white px-4 py-5 text-center text-sm text-slate-700 transition hover:border-sky-500 hover:bg-sky-50">
                            <div class="mb-2 inline-flex h-10 w-10 items-center justify-center rounded-full bg-sky-100 text-sky-700"><i data-lucide="file-up"></i></div>
                            <div><?php echo $lang['transfer_choose_file'] ?? 'Choose CSV file'; ?></div>
                            <div id="itamTransferFileName" class="mt-1 text-xs text-slate-500"><?php echo $lang['transfer_no_file'] ?? 'No file selected'; ?></div>
                        </label>
                        <button type="button" id="itamTransferImportBtn" class="rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300" onclick="importTransferCsv()" disabled>
                            <?php echo $lang['transfer_import_csv'] ?? 'Import CSV'; ?>
                        </button>
                    </div>
                    <div id="itamTransferStatus" class="hidden rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700"></div>
                </div>
            </div>
        </div>

        <div id="itamTransferDuplicateModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4">
            <div class="grid w-full max-w-xl gap-4 rounded-2xl border border-slate-300 bg-white p-5 shadow-2xl">
                <div class="text-xl font-bold text-slate-900"><?php echo $lang['transfer_duplicate_title'] ?? 'Gleichnamiger Eintrag gefunden'; ?></div>
                <div id="itamTransferDuplicateCopy" class="text-sm text-slate-600"></div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" id="itamTransferDuplicateRemember" class="rounded border-slate-300">
                    <span><?php echo $lang['transfer_duplicate_apply_all'] ?? 'Entscheidung fuer weitere gleiche Treffer merken'; ?></span>
                </label>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" id="itamTransferDuplicateKeep" class="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"><?php echo $lang['transfer_duplicate_keep'] ?? 'Alten Eintrag behalten'; ?></button>
                    <button type="button" id="itamTransferDuplicateReplace" class="rounded-full bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600"><?php echo $lang['transfer_duplicate_replace'] ?? 'Alten Eintrag ueberschreiben'; ?></button>
                    <button type="button" id="itamTransferDuplicateCreate" class="rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"><?php echo $lang['transfer_duplicate_create'] ?? 'Zusaetzlichen Eintrag erstellen'; ?></button>
                </div>
            </div>
        </div>

        <!-- Journal Entry Modal -->
        <div id="journalEntryModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 p-4">
            <div class="max-h-[90vh] w-full max-w-[600px] overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-5 flex items-center justify-between border-b border-slate-200 pb-3">
                    <div class="text-xl font-bold text-slate-900">Journaleintrag erstellen</div>
                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-400 text-white hover:bg-slate-500" onclick="closeJournalEntryModal()"><i data-lucide="x"></i></button>
                </div>
                <div class="mb-5 grid gap-4">
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-slate-900">Titel</label>
                        <input type="text" id="journalEntryCaption" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Kurzer Titel des Eintrags">
                    </div>
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-slate-900">Beschreibung</label>
                        <textarea id="journalEntryDescription" class="min-h-[100px] resize-y rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Detaillierte Beschreibung des Journaleintrags"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded-full bg-slate-200 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-300" onclick="closeJournalEntryModal()">Abbrechen</button>
                    <button type="button" class="rounded-full bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700" onclick="submitJournalEntry()">Speichern</button>
                </div>
            </div>
        </div>

        <!-- File Upload Modal -->
        <div id="fileUploadModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 p-4">
            <div class="max-h-[90vh] w-full max-w-[600px] overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-5 flex items-center justify-between border-b border-slate-200 pb-3">
                    <div class="text-xl font-bold text-slate-900">Datei hochladen</div>
                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-400 text-white hover:bg-slate-500" onclick="closeFileUploadModal()"><i data-lucide="x"></i></button>
                </div>
                <div class="mb-5 grid gap-4">
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-slate-900">Datei</label>
                        <input type="file" id="fileUploadInput" class="hidden" onchange="updateFileSelection()">
                        <label for="fileUploadInput" class="cursor-pointer rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 p-4 text-center transition hover:border-blue-600 hover:bg-blue-50">
                            <div><i data-lucide="upload"></i></div>
                            <div>Datei zum Hochladen ausw&auml;hlen oder hier ablegen</div>
                        </label>
                        <div id="fileUploadFeedback" class="mt-2 hidden rounded-md border border-sky-500 bg-sky-50 p-3">
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
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-slate-900">Beschreibung (optional)</label>
                        <textarea id="fileUploadDescription" class="min-h-[100px] resize-y rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Beschreibung der Datei"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded-full bg-slate-200 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-300" onclick="closeFileUploadModal()" id="fileUploadCancelBtn">Abbrechen</button>
                    <button type="button" class="rounded-full bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700" onclick="submitFileUpload()" id="fileUploadSubmitBtn">Hochladen</button>
                </div>
            </div>
        </div>

        <!-- Edit Journal Entry Modal -->
        <div id="editJournalModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 p-4">
            <div class="max-h-[90vh] w-full max-w-[600px] overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-5 flex items-center justify-between border-b border-slate-200 pb-3">
                    <div class="text-xl font-bold text-slate-900">Journaleintrag bearbeiten</div>
                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-400 text-white hover:bg-slate-500" onclick="closeEditJournalModal()"><i data-lucide="x"></i></button>
                </div>
                <div class="mb-5 grid gap-4">
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-slate-900">Titel</label>
                        <input type="text" id="editJournalCaption" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Titel">
                    </div>
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-slate-900">Beschreibung</label>
                        <textarea id="editJournalDescription" class="min-h-[100px] resize-y rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Beschreibung"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded-full bg-slate-200 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-300" onclick="closeEditJournalModal()">Abbrechen</button>
                    <button type="button" class="rounded-full bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700" onclick="submitEditJournal()">Speichern</button>
                </div>
            </div>
        </div>

        <!-- Edit File/Attachment Modal -->
        <div id="editFileModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 p-4">
            <div class="max-h-[90vh] w-full max-w-[600px] overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-5 flex items-center justify-between border-b border-slate-200 pb-3">
                    <div class="text-xl font-bold text-slate-900">Anlage bearbeiten</div>
                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-400 text-white hover:bg-slate-500" onclick="closeEditFileModal()"><i data-lucide="x"></i></button>
                </div>
                <div class="mb-5 grid gap-4">
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-slate-900">Dateiname</label>
                        <input type="text" id="editFileName" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Dateiname" disabled>
                    </div>
                    <div class="grid gap-1.5">
                        <label class="text-sm font-semibold text-slate-900">Beschreibung</label>
                        <textarea id="editFileDescription" class="min-h-[100px] resize-y rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Beschreibung der Datei"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded-full bg-slate-200 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-300" onclick="closeEditFileModal()">Abbrechen</button>
                    <button type="button" class="rounded-full bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700" onclick="submitEditFile()">Speichern</button>
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
let itamFormsConfigCache = null;
let currentTransferFile = null;
let currentTable = 'location_details';

function searchTable() {
    return false;
}

// Generate form
async function generateFormFromJSON(table = 'location_details', options = {}) {
    try {
        console.log('Loading form configuration for table:', table);
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>' + '/includes/forms.json');
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

        setupSpecificationEditors(container);

        if (table === 'device_details') {
            currentDeviceCreateMode = 'new';
            if (mode !== 'edit') {
                setupDeviceTemplateMode(container);
            }
            setupDevicePortAutomation();
            setupItemGroupHelper();
            setupDevice3DMasks(container);
        }

        if (table === 'connection_details') {
            setupConnectionSuggestions(container, generatedForms);
        }

        if (table === 'location_details') {
            setupLocationTypeMasks(container);
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
    if (normalizedType === 'patchpanel') return { count: 24 };
    if (normalizedType === 'net_outlet') return { count: 2 };
    if (normalizedType === 'switch') return { count: 48 };
    return { count: 0 };
}

function getPortTypeDefinitions() {
    return {
        0: { key: 'power_c14', label: 'C14 (Power In)', family: 'power' },
        1: { key: 'power_c20', label: 'C20 (Power In)', family: 'power' },
        2: { key: 'power_c13', label: 'C13 (Power Out)', family: 'power' },
        3: { key: 'power_c19', label: 'C19 (Power Out)', family: 'power' },
        4: { key: 'power_schuko', label: 'Schuko', family: 'power' },
        5: { key: 'power_cee', label: 'CEE (3-Phase)', family: 'power' },
        10: { key: 'rj45', label: 'RJ45', family: 'copper' },
        11: { key: 'sfp', label: 'SFP / SFP+', family: 'sfp' },
        12: { key: 'qsfp', label: 'QSFP', family: 'qsfp' },
        13: { key: 'mpo', label: 'MPO', family: 'fiber' },
        20: { key: 'mgmt_rj45', label: 'Management (RJ45)', family: 'mgmt' },
        21: { key: 'console_rj45', label: 'Console (RJ45)', family: 'console' },
        30: { key: 'fiber_lc', label: 'Fiber LC', family: 'fiber' },
        31: { key: 'fiber_sc', label: 'Fiber SC', family: 'fiber' },
        40: { key: 'coax_bnc', label: 'Coax BNC', family: 'coax' },
        99: { key: 'other', label: 'Other', family: 'other' }
    };
}

function getPortTypeCodeByFamily(typeFamily) {
    const normalized = String(typeFamily || '').trim().toLowerCase();
    const map = {
        'copper': 10, 'sfp': 11, 'qsfp': 12, 'power': 0,
        'mgmt': 20, 'console': 21, 'fiber': 30, 'coax': 40
    };
    return map[normalized] ?? 10;
}

/**
 * Real-world port dimensions in mm per type code.
 */
function getPortTypeSizeMm(typeCode) {
    const sizes = {
        0:  { w: 25, h: 32 },  // C14 (Power In)
        1:  { w: 25, h: 32 },  // C20 (Power In)
        2:  { w: 25, h: 20 },  // C13 (Power Out)
        3:  { w: 30, h: 20 },  // C19 (Power Out)
        4:  { w: 20, h: 20 },  // Schuko
        5:  { w: 30, h: 30 },  // CEE (3-Phase)
        10: { w: 14, h: 14 },  // RJ45
        11: { w: 14, h:  8 },  // SFP / SFP+
        12: { w: 18, h:  9 },  // QSFP
        13: { w: 14, h: 10 },  // MPO
        20: { w: 14, h: 14 },  // Management RJ45
        21: { w: 14, h: 14 },  // Console RJ45
        30: { w:  8, h:  8 },  // Fiber LC
        31: { w: 10, h: 10 },  // Fiber SC
        40: { w: 12, h: 12 },  // Coax BNC
        99: { w: 14, h: 14 }   // Other
    };
    return sizes[typeCode] || sizes[10];
}

function getPortLayoutPreviewColor(typeCode) {
    const definitions = getPortTypeDefinitions();
    const typeFamily = (definitions[typeCode] || {}).family || 'other';
    const colors = {
        power: '#f59e0b', copper: '#60a5fa', sfp: '#22d3ee',
        qsfp: '#14b8a6', mgmt: '#84cc16', console: '#f97316',
        fiber: '#a78bfa', coax: '#fb923c', other: '#94a3b8'
    };
    return colors[typeFamily] || colors.other;
}

function buildPortLabelFromPattern(baseLabel, offset, pattern, layoutContext = {}) {
    const input = String(baseLabel || '').trim();
    if (!input) return '';
    const normalizedPattern = String(pattern || '').trim();
    if (!normalizedPattern) return buildPortLabel(input, offset);
    const match = input.match(/^(.*?)(\d+)$/);
    const prefix = match ? match[1] : input;
    const startNumber = match ? parseInt(match[2], 10) : 1;
    const nextNumber = startNumber + offset;
    return normalizedPattern
        .replace(/\{prefix\}/g, prefix)
        .replace(/\{index\}|\{n\}/g, String(nextNumber))
        .replace(/\{offset\}/g, String(offset + 1))
        .replace(/\{row\}/g, String(layoutContext.row != null ? layoutContext.row + 1 : 1))
        .replace(/\{col\}/g, String(layoutContext.col != null ? layoutContext.col + 1 : offset + 1));
}

/**
 * Device preset definitions for common switch/patchpanel configurations.
 */
function getDevicePresetDefinitions() {
    return {
        'none': { label: '-- Kein Preset --', groups: [] },
        'switch-24-l2': {
            label: 'Switch 24-Port L2 (24x RJ45, 4x SFP+)',
            deviceWidth: 440, deviceHeight: 44,
            groups: [
                { typeCode: 10, count: 24, rows: 2, startLabel: 'GE1/0/1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 20, offsetY: 5, gapX: 2, gapY: 2, numbering: 'column-first' },
                { typeCode: 11, count: 4, rows: 1, startLabel: 'SFP1/0/1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 340, offsetY: 14, gapX: 3, gapY: 2, numbering: 'column-first' },
                { typeCode: 0,  count: 1, rows: 1, startLabel: 'PSU1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 180, offsetY: 6, gapX: 10, gapY: 2, numbering: 'row-first' },
                { typeCode: 20, count: 1, rows: 1, startLabel: 'MGMT1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 230, offsetY: 15, gapX: 5, gapY: 2, numbering: 'row-first' },
                { typeCode: 21, count: 1, rows: 1, startLabel: 'CON1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 260, offsetY: 15, gapX: 5, gapY: 2, numbering: 'row-first' }
            ]
        },
        'switch-48-l3': {
            label: 'Switch 48-Port L3 (48x RJ45, 4x SFP+, 2x QSFP)',
            deviceWidth: 440, deviceHeight: 44,
            groups: [
                { typeCode: 10, count: 48, rows: 2, startLabel: 'MultiGE1/0/1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 18, offsetY: 3, gapX: 1, gapY: 2, numbering: 'column-first' },
                { typeCode: 11, count: 4,  rows: 1, startLabel: '25GE1/0/1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 370, offsetY: 5, gapX: 3, gapY: 2, numbering: 'column-first' },
                { typeCode: 12, count: 2,  rows: 1, startLabel: '100GE1/0/1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 370, offsetY: 24, gapX: 3, gapY: 2, numbering: 'column-first' },
                { typeCode: 0,  count: 2,  rows: 1, startLabel: 'PSU1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 140, offsetY: 4, gapX: 10, gapY: 2, numbering: 'row-first' },
                { typeCode: 20, count: 2,  rows: 1, startLabel: 'MGMT1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 260, offsetY: 15, gapX: 5, gapY: 2, numbering: 'row-first' }
            ]
        },
        'switch-24-poe': {
            label: 'Switch 24-Port PoE (24x RJ45, 4x SFP+, 2x PSU)',
            deviceWidth: 440, deviceHeight: 44,
            groups: [
                { typeCode: 10, count: 24, rows: 2, startLabel: 'GE1/0/1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 20, offsetY: 5, gapX: 2, gapY: 2, numbering: 'column-first' },
                { typeCode: 11, count: 4,  rows: 1, startLabel: 'SFP1/0/1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 340, offsetY: 14, gapX: 3, gapY: 2, numbering: 'column-first' },
                { typeCode: 0,  count: 2,  rows: 1, startLabel: 'PSU1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 140, offsetY: 4, gapX: 10, gapY: 2, numbering: 'row-first' },
                { typeCode: 20, count: 1,  rows: 1, startLabel: 'MGMT1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 260, offsetY: 15, gapX: 5, gapY: 2, numbering: 'row-first' },
                { typeCode: 21, count: 1,  rows: 1, startLabel: 'CON1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 290, offsetY: 15, gapX: 5, gapY: 2, numbering: 'row-first' }
            ]
        },
        'patchpanel-24': {
            label: 'Patchpanel 24-Port (24x RJ45)',
            deviceWidth: 440, deviceHeight: 44,
            groups: [
                { typeCode: 10, count: 24, rows: 2, startLabel: 'Port1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 20, offsetY: 5, gapX: 2, gapY: 2, numbering: 'column-first' }
            ]
        },
        'patchpanel-48': {
            label: 'Patchpanel 48-Port (48x RJ45)',
            deviceWidth: 440, deviceHeight: 44,
            groups: [
                { typeCode: 10, count: 48, rows: 2, startLabel: 'Port1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 18, offsetY: 3, gapX: 1, gapY: 2, numbering: 'column-first' }
            ]
        },
        'ups-1u-single': {
            label: 'USV 1HE 1-Phase (1× C14 In, 8× C13 Out, MGMT)',
            deviceWidth: 440, deviceHeight: 44,
            groups: [
                { typeCode: 20, count: 1, rows: 1, startLabel: 'MGMT1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 200, offsetY: 15, gapX: 5, gapY: 2, numbering: 'row-first' },
                { typeCode: 0,  count: 1, rows: 1, startLabel: 'Input1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 10, offsetY: 6, gapX: 5, gapY: 2, numbering: 'row-first' },
                { typeCode: 2,  count: 8, rows: 2, startLabel: 'Out1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 60, offsetY: 2, gapX: 3, gapY: 2, numbering: 'column-first' }
            ]
        },
        'ups-2u-3phase': {
            label: 'USV 2HE 3-Phase (1× CEE In, 12× C13, 4× C19 Out, MGMT)',
            deviceWidth: 440, deviceHeight: 88,
            groups: [
                { typeCode: 20, count: 1, rows: 1, startLabel: 'MGMT1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 200, offsetY: 37, gapX: 5, gapY: 2, numbering: 'row-first' },
                { typeCode: 5,  count: 1, rows: 1, startLabel: 'Input1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 10, offsetY: 29, gapX: 5, gapY: 2, numbering: 'row-first' },
                { typeCode: 2,  count: 12, rows: 2, startLabel: 'Out1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 60, offsetY: 5, gapX: 3, gapY: 2, numbering: 'column-first' },
                { typeCode: 3,  count: 4,  rows: 2, startLabel: 'OutHigh1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 340, offsetY: 5, gapX: 3, gapY: 2, numbering: 'column-first' }
            ]
        },
        'pdu-vertical-24': {
            label: 'PDU Vertikal 24-Port (1× C20 In, 24× C13 Out, MGMT)',
            deviceWidth: 60, deviceHeight: 1720,
            groups: [
                { typeCode: 20, count: 1, rows: 1, startLabel: 'MGMT1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 23, offsetY: 5, gapX: 5, gapY: 2, numbering: 'row-first' },
                { typeCode: 1,  count: 1, rows: 1, startLabel: 'Input1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 17, offsetY: 5, gapX: 5, gapY: 2, numbering: 'row-first' },
                { typeCode: 2,  count: 24, rows: 1, startLabel: 'Out1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 17, offsetY: 40, gapX: 2, gapY: 5, numbering: 'row-first' }
            ]
        },
        'pdu-horizontal-12': {
            label: 'PDU 1HE Horizontal (1× C20 In, 12× C13 Out)',
            deviceWidth: 440, deviceHeight: 44,
            groups: [
                { typeCode: 1,  count: 1, rows: 1, startLabel: 'Input1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 10, offsetY: 6, gapX: 5, gapY: 2, numbering: 'row-first' },
                { typeCode: 2,  count: 12, rows: 1, startLabel: 'Out1', labelPattern: '{prefix}{index}', side: 'rear', offsetX: 60, offsetY: 12, gapX: 3, gapY: 2, numbering: 'row-first' }
            ]
        }
    };
}

/**
 * Compute positions for all ports in a single group.
 * numbering: 'column-first' = top 1 bottom 2 then right; 'row-first' = left to right, top to bottom.
 */
function computeGroupPortPositions(group) {
    const typeSize = getPortTypeSizeMm(group.typeCode);
    const w = typeSize.w;
    const h = typeSize.h;
    const count = Math.max(0, parseInt(group.count || 0, 10));
    if (count <= 0) return [];

    const rows = Math.max(1, parseInt(group.rows || 1, 10));
    const cols = Math.ceil(count / rows);
    const gapX = getNumericOrDefault(group.gapX, 2);
    const gapY = getNumericOrDefault(group.gapY, 2);
    const offsetX = getNumericOrDefault(group.offsetX, 0);
    const offsetY = getNumericOrDefault(group.offsetY, 0);
    const numbering = (group.numbering || 'column-first').toLowerCase();
    const startLabel = (group.startLabel || 'Port1').trim();
    const labelPattern = group.labelPattern || '{prefix}{index}';

    const ports = [];
    for (let i = 0; i < count; i++) {
        let row, col;
        if (numbering === 'column-first') {
            col = Math.floor(i / rows);
            row = i % rows;
        } else {
            row = Math.floor(i / cols);
            col = i % cols;
        }
        const x = offsetX + col * (w + gapX);
        const y = offsetY + row * (h + gapY);
        const label = buildPortLabelFromPattern(startLabel, i, labelPattern, { row, col });
        ports.push({ x, y, w, h, label, typeCode: group.typeCode, side: group.side || 'front', row, col, groupIndex: 0 });
    }
    return ports;
}

/**
 * Compute all port positions from a groups config array.
 */
function computeAllPortPositions(groups) {
    const allPorts = [];
    (groups || []).forEach((group, gi) => {
        const ports = computeGroupPortPositions(group);
        ports.forEach(p => { p.groupIndex = gi; });
        allPorts.push(...ports);
    });
    return allPorts;
}

let currentDeviceCreateMode = 'new';
let itamFormState = {
    mode: 'create',
    table: '',
    rowData: null,
    uuids: {}
};

async function getItamFormsConfig() {
    if (itamFormsConfigCache) {
        return itamFormsConfigCache;
    }

    const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>' + '/includes/forms.json');
    if (!response.ok) {
        throw new Error('forms.json could not be loaded');
    }

    itamFormsConfigCache = await response.json();
    return itamFormsConfigCache;
}

function getItamFormConfig(configData, table) {
    return configData && configData.forms ? configData.forms[table] : null;
}

function getCurrentSearchTerm() {
    const searchEl = document.querySelector('#searchForm input[name="search"]');
    return searchEl ? String(searchEl.value || '').trim() : '';
}

function getItamTableFilterDefinitions(formConfig) {
    if (!formConfig || !Array.isArray(formConfig.filters)) {
        return [];
    }

    return formConfig.filters.map((rawFilter, index) => {
        const filter = rawFilter && typeof rawFilter === 'object' ? rawFilter : {};
        const fieldName = String(filter.field || filter.name || '').trim();
        const baseField = fieldName !== '' && formConfig.fields && typeof formConfig.fields === 'object'
            ? (formConfig.fields[fieldName] || {})
            : {};
        const operator = String(filter.operator || 'eq').trim().toLowerCase();
        const name = String(filter.name || fieldName || `filter_${index}`).trim();
        const column = String(filter.column || '').trim();
        const type = String(filter.type || baseField.type || (operator === 'range' ? 'number' : 'text')).trim();
        const label = String(filter.label || baseField.label || name).trim();
        const placeholder = String(filter.placeholder || '').trim();
        const options = Array.isArray(filter.options)
            ? filter.options
            : (Array.isArray(baseField.options) ? baseField.options : []);
        const multiple = Boolean(filter.multiple) || operator === 'in';

        if (name === '' || !['eq', 'in', 'range', 'search'].includes(operator)) {
            return null;
        }
        if (operator !== 'search' && column === '') {
            return null;
        }

        return {
            name,
            field: fieldName,
            label,
            column,
            operator,
            type,
            placeholder,
            options,
            multiple,
        };
    }).filter(Boolean);
}

function getTableFilters(table) {
    if (!window.__pfTableFilters) {
        try {
            const raw = sessionStorage.getItem('pf_table_filters');
            window.__pfTableFilters = raw ? (JSON.parse(raw) || {}) : {};
        } catch (e) {
            window.__pfTableFilters = {};
        }
    }

    return window.__pfTableFilters[table] || {};
}

function setTableFilters(table, filters) {
    if (!window.__pfTableFilters) {
        window.__pfTableFilters = {};
    }

    const nextFilters = filters && typeof filters === 'object' ? filters : {};
    if (Object.keys(nextFilters).length > 0) {
        window.__pfTableFilters[table] = nextFilters;
    } else {
        delete window.__pfTableFilters[table];
    }

    try {
        sessionStorage.setItem('pf_table_filters', JSON.stringify(window.__pfTableFilters));
    } catch (e) {}
}

function hasActiveTableFilters(filterDefinitions, filterState) {
    return filterDefinitions.some(def => {
        const currentValue = filterState ? filterState[def.name] : null;
        if (def.operator === 'range') {
            return Boolean(currentValue && (String(currentValue.min || '').trim() !== '' || String(currentValue.max || '').trim() !== ''));
        }
        if (Array.isArray(currentValue)) {
            return currentValue.length > 0;
        }
        return String(currentValue || '').trim() !== '';
    });
}

function buildCurrentTableFilterState(filterDefinitions) {
    const nextState = {};

    filterDefinitions.forEach(def => {
        if (def.operator === 'range') {
            const minEl = document.querySelector(`[name="filter_${def.name}_min"]`);
            const maxEl = document.querySelector(`[name="filter_${def.name}_max"]`);
            const minValue = minEl ? String(minEl.value || '').trim() : '';
            const maxValue = maxEl ? String(maxEl.value || '').trim() : '';
            if (minValue !== '' || maxValue !== '') {
                nextState[def.name] = { min: minValue, max: maxValue };
            }
            return;
        }

        const el = document.querySelector(`[name="filter_${def.name}"]`);
        if (!el) {
            return;
        }

        if (def.multiple && el instanceof HTMLSelectElement) {
            const values = Array.from(el.selectedOptions)
                .map(option => String(option.value || '').trim())
                .filter(Boolean);
            if (values.length > 0) {
                nextState[def.name] = values;
            }
            return;
        }

        const value = String(el.value || '').trim();
        if (value !== '') {
            nextState[def.name] = value;
        }
    });

    return nextState;
}

function appendTableFiltersToParams(params, filterDefinitions, filterState) {
    filterDefinitions.forEach(def => {
        const currentValue = filterState ? filterState[def.name] : null;
        if (currentValue === null || currentValue === undefined) {
            return;
        }

        if (def.operator === 'range') {
            const minValue = String((currentValue && currentValue.min) || '').trim();
            const maxValue = String((currentValue && currentValue.max) || '').trim();
            if (minValue !== '') {
                params.set(def.column + 'Min', minValue);
            }
            if (maxValue !== '') {
                params.set(def.column + 'Max', maxValue);
            }
            return;
        }

        if (Array.isArray(currentValue)) {
            const values = currentValue.map(value => String(value || '').trim()).filter(Boolean);
            if (values.length === 0) {
                return;
            }
            params.set(def.operator === 'in' ? (def.column + 'In') : def.column, values.join(','));
            return;
        }

        const scalarValue = String(currentValue || '').trim();
        if (scalarValue === '') {
            return;
        }

        if (def.operator === 'search') {
            params.set('search', scalarValue);
            return;
        }

        params.set(def.operator === 'in' ? (def.column + 'In') : def.column, scalarValue);
    });
}

function buildTableQueryParams(table, options = {}) {
    const params = new URLSearchParams();
    const search = options.search !== undefined ? String(options.search || '').trim() : '';
    const limit = options.limit !== undefined ? options.limit : null;
    const page = options.page !== undefined ? options.page : 1;
    const formConfig = options.formConfig || (itamFormsConfigCache ? getItamFormConfig(itamFormsConfigCache, table) : null);
    const filterDefinitions = getItamTableFilterDefinitions(formConfig);
    const filterState = options.filters || getTableFilters(table);

    if (search !== '') {
        params.set('search', search);
    }
    appendTableFiltersToParams(params, filterDefinitions, filterState);
    if (limit) {
        params.set('limit', String(limit));
    }
    if (page && page > 1) {
        params.set('page', String(page));
    }

    const sort = getTableSort(table);
    if (sort && sort.col && sort.dir) {
        params.set('sort', sort.col);
        params.set('dir', sort.dir);
    }

    return params;
}

function createFilterControl(def, filterState) {
    const wrapper = document.createElement('div');
    wrapper.className = 'flex min-w-[11rem] flex-col gap-1';

    const label = document.createElement('label');
    label.className = 'text-xs font-semibold uppercase tracking-wide text-slate-500';
    label.textContent = def.label;
    wrapper.appendChild(label);

    if (def.operator === 'range') {
        const rangeWrap = document.createElement('div');
        rangeWrap.className = 'grid min-w-[14rem] grid-cols-2 gap-2';
        const rangeValue = filterState && typeof filterState[def.name] === 'object' ? filterState[def.name] : {};

        ['min', 'max'].forEach(bound => {
            const input = document.createElement('input');
            input.type = def.type === 'number' ? 'number' : 'text';
            input.name = `filter_${def.name}_${bound}`;
            input.value = String(rangeValue[bound] || '');
            input.placeholder = bound === 'min' ? 'Min' : 'Max';
            input.className = 'rounded-full border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400';
            input.addEventListener('change', () => applyCurrentTableFilters());
            rangeWrap.appendChild(input);
        });

        wrapper.appendChild(rangeWrap);
        return wrapper;
    }

    let control;
    if (def.type === 'dropdown' || def.type === 'boolean' || def.multiple) {
        control = document.createElement('select');
        control.name = `filter_${def.name}`;
        control.className = 'rounded-full border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400';
        if (def.multiple) {
            control.multiple = true;
            control.className = 'min-h-[7.5rem] rounded-2xl border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400';
        }

        const currentValues = Array.isArray(filterState && filterState[def.name])
            ? filterState[def.name].map(value => String(value || ''))
            : [String((filterState && filterState[def.name]) || '')];

        if (!def.multiple) {
            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = 'Alle';
            control.appendChild(emptyOption);
        }

        const options = def.type === 'boolean'
            ? [
                { value: 'true', label: 'Ja' },
                { value: 'false', label: 'Nein' }
            ]
            : def.options;

        options.forEach(option => {
            const value = String((option && option.value) || '').trim();
            if (value === '' || value === '--') {
                return;
            }
            const optionEl = document.createElement('option');
            optionEl.value = value;
            optionEl.textContent = String((option && option.label) || value);
            optionEl.selected = currentValues.includes(value);
            control.appendChild(optionEl);
        });

        control.addEventListener('change', () => applyCurrentTableFilters());
    } else {
        control = document.createElement('input');
        control.type = def.type === 'number' ? 'number' : 'text';
        control.name = `filter_${def.name}`;
        control.value = String((filterState && filterState[def.name]) || '');
        control.placeholder = def.placeholder || def.label;
        control.className = 'rounded-full border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-400';
        control.addEventListener('change', () => applyCurrentTableFilters());
        control.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                applyCurrentTableFilters();
            }
        });
    }

    wrapper.appendChild(control);
    return wrapper;
}

function renderTableFilters(table, formConfig) {
    const host = document.getElementById('tableFilterBar');
    if (!host) {
        return;
    }

    host.innerHTML = '';
    const filterDefinitions = getItamTableFilterDefinitions(formConfig);
    if (filterDefinitions.length === 0) {
        host.classList.add('hidden');
        return;
    }

    const filterState = getTableFilters(table);
    const shell = document.createElement('div');
    shell.className = 'rounded-2xl border border-slate-300 bg-white p-3 shadow-sm';

    const header = document.createElement('div');
    header.className = 'mb-3 flex flex-wrap items-center justify-between gap-3';
    header.innerHTML = '<div class="text-sm font-semibold text-slate-900">Filter</div><div class="text-xs text-slate-500">Tabellenspezifische Filter aus forms.json</div>';
    shell.appendChild(header);

    const form = document.createElement('form');
    form.id = 'tableFilterForm';
    form.className = 'flex flex-wrap items-end gap-3';
    form.addEventListener('submit', event => {
        event.preventDefault();
        applyCurrentTableFilters();
    });

    filterDefinitions.forEach(def => {
        form.appendChild(createFilterControl(def, filterState));
    });

    const actions = document.createElement('div');
    actions.className = 'ml-auto flex items-center gap-2';

    const applyButton = document.createElement('button');
    applyButton.type = 'submit';
    applyButton.className = 'rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700';
    applyButton.textContent = 'Anwenden';
    actions.appendChild(applyButton);

    const resetButton = document.createElement('button');
    resetButton.type = 'button';
    resetButton.className = 'rounded-full bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-300';
    resetButton.textContent = 'Reset';
    resetButton.disabled = !hasActiveTableFilters(filterDefinitions, filterState);
    resetButton.addEventListener('click', () => resetCurrentTableFilters());
    actions.appendChild(resetButton);

    form.appendChild(actions);
    shell.appendChild(form);
    host.appendChild(shell);
    host.classList.remove('hidden');
}

function applyCurrentTableFilters() {
    if (!itamFormsConfigCache) {
        return;
    }

    const formConfig = getItamFormConfig(itamFormsConfigCache, currentTable);
    const filterDefinitions = getItamTableFilterDefinitions(formConfig);
    setTableFilters(currentTable, buildCurrentTableFilterState(filterDefinitions));
    loadTable(currentTable, getCurrentSearchTerm(), null, 1);
}

function resetCurrentTableFilters() {
    setTableFilters(currentTable, {});
    loadTable(currentTable, getCurrentSearchTerm(), null, 1);
}

function getItamImportSchema(formConfig) {
    if (!formConfig || !Array.isArray(formConfig.postOrder)) {
        return [];
    }

    const seen = new Set();
    const schema = [];
    formConfig.postOrder.forEach(step => {
        (step.fields || []).forEach(fieldName => {
            if (seen.has(fieldName)) {
                return;
            }
            seen.add(fieldName);
            const fieldConfig = formConfig.fields[fieldName] || {};
            schema.push({
                name: fieldName,
                label: fieldConfig.label || fieldName,
                type: fieldConfig.type || 'text',
                required: !!fieldConfig.required,
                resource: fieldConfig.resource || '',
                options: Array.isArray(fieldConfig.options) ? fieldConfig.options : [],
                sourceTable: step.table || ''
            });
        });
    });

    return schema;
}

function getImportFieldNote(schemaField) {
    if (!schemaField) {
        return '';
    }

    if (schemaField.type === 'searchDropdown') {
        return `UUID (${schemaField.resource || 'referenced table'})`;
    }
    if (schemaField.type === 'dropdown') {
        return schemaField.options.map(option => option.value).join(', ');
    }
    if (schemaField.type === 'boolean') {
        return 'true / false';
    }
    if (schemaField.type === 'number') {
        return 'numeric';
    }
    if (schemaField.type === 'textarea') {
        return 'text or JSON';
    }

    return '';
}

function getSampleValueForSchemaField(schemaField) {
    if (!schemaField) {
        return '';
    }

    if (schemaField.type === 'dropdown') {
        const firstUsable = schemaField.options.find(option => String(option.value || '').trim() !== '' && option.value !== '--');
        return firstUsable ? String(firstUsable.value) : '';
    }
    if (schemaField.type === 'boolean') {
        return schemaField.required ? 'false' : '';
    }
    if (schemaField.type === 'number') {
        return schemaField.required ? '1' : '';
    }
    if (schemaField.type === 'searchDropdown') {
        return schemaField.required ? '<uuid>' : '';
    }
    if (schemaField.name === 'caption') {
        return 'Example';
    }
    if (schemaField.name === 'status') {
        return '0';
    }

    return schemaField.required ? `example_${schemaField.name}` : '';
}

function escapeCsvValue(value) {
    const normalized = value == null ? '' : String(value);
    if (/[\"\r\n;]/.test(normalized)) {
        return '"' + normalized.replace(/"/g, '""') + '"';
    }
    return normalized;
}

function buildCsvText(headers, rows) {
    const lines = [];
    lines.push(headers.map(escapeCsvValue).join(';'));
    rows.forEach(row => {
        lines.push(headers.map(header => escapeCsvValue(row[header] ?? '')).join(';'));
    });
    return lines.join('\r\n');
}

function downloadTextFile(filename, content, mimeType = 'text/plain;charset=utf-8;') {
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function parseCsvText(rawText) {
    const text = String(rawText || '').replace(/^\uFEFF/, '');
    const rows = [];
    let row = [];
    let field = '';
    let inQuotes = false;

    for (let index = 0; index < text.length; index += 1) {
        const char = text[index];
        const next = text[index + 1];

        if (char === '"') {
            if (inQuotes && next === '"') {
                field += '"';
                index += 1;
            } else {
                inQuotes = !inQuotes;
            }
            continue;
        }

        if (!inQuotes && char === ';') {
            row.push(field);
            field = '';
            continue;
        }

        if (!inQuotes && (char === '\n' || char === '\r')) {
            if (char === '\r' && next === '\n') {
                index += 1;
            }
            row.push(field);
            rows.push(row);
            row = [];
            field = '';
            continue;
        }

        field += char;
    }

    if (field !== '' || row.length > 0) {
        row.push(field);
        rows.push(row);
    }

    return rows;
}

function parseBooleanValue(value) {
    if (value === true || value === false) {
        return value;
    }

    const normalized = String(value || '').trim().toLowerCase();
    if (normalized === '') {
        return null;
    }
    if (['1', 'true', 't', 'yes', 'y', 'on'].includes(normalized)) {
        return true;
    }
    if (['0', 'false', 'f', 'no', 'n', 'off'].includes(normalized)) {
        return false;
    }

    throw new Error(`Invalid boolean value: ${value}`);
}

function normalizeCsvRecord(rawRecord, schema) {
    const normalized = {};

    schema.forEach(field => {
        const rawValue = Object.prototype.hasOwnProperty.call(rawRecord, field.name) ? rawRecord[field.name] : undefined;
        const value = rawValue == null ? '' : String(rawValue).trim();

        if (value === '') {
            if (field.required) {
                throw new Error(`Missing required field: ${field.name}`);
            }
            normalized[field.name] = null;
            return;
        }

        if (field.type === 'boolean') {
            normalized[field.name] = parseBooleanValue(value);
            return;
        }

        if (field.type === 'number') {
            const parsed = Number(value);
            if (Number.isNaN(parsed)) {
                throw new Error(`Invalid number in field ${field.name}: ${value}`);
            }
            normalized[field.name] = parsed;
            return;
        }

        if (field.type === 'dropdown' && field.options.length > 0) {
            const allowed = new Set(field.options.map(option => String(option.value)));
            if (!allowed.has(value)) {
                throw new Error(`Invalid option in field ${field.name}: ${value}`);
            }
        }

        if (field.type === 'searchDropdown' && !isValidPostgresUuid(value)) {
            throw new Error(`Field ${field.name} expects a UUID: ${value}`);
        }

        normalized[field.name] = value;
    });

    return normalized;
}

function formatExportValue(value, type) {
    if (value == null) {
        return '';
    }
    if (type === 'boolean') {
        return isTruthyTemplateValue(value) ? 'true' : 'false';
    }
    if (typeof value === 'object') {
        return JSON.stringify(value);
    }
    return String(value);
}

function mapRowToImportRecord(detailsTableName, formConfig, rowData, schema) {
    const record = {};
    schema.forEach(field => {
        const value = getRowFieldValueForForm(rowData, detailsTableName, field.sourceTable, field.name);
        record[field.name] = formatExportValue(value, field.type);
    });
    return record;
}

function setTransferStatus(message, tone = 'info') {
    const status = document.getElementById('itamTransferStatus');
    if (!status) {
        return;
    }

    const toneClasses = {
        info: 'border-slate-200 bg-white text-slate-700',
        success: 'border-emerald-300 bg-emerald-50 text-emerald-800',
        error: 'border-rose-300 bg-rose-50 text-rose-800'
    };

    status.className = `rounded-xl border p-3 text-sm ${toneClasses[tone] || toneClasses.info}`;
    status.textContent = message;
    status.classList.remove('hidden');
}

function resetTransferStatus() {
    const status = document.getElementById('itamTransferStatus');
    if (!status) {
        return;
    }
    status.textContent = '';
    status.classList.add('hidden');
}

function normalizeDuplicateCaption(value) {
    return String(value || '').trim().toLowerCase();
}

function buildCaptionDuplicateEntry(caption, uuids = {}, rowData = null) {
    return {
        caption: String(caption || '').trim(),
        uuids: { ...(uuids || {}) },
        rowData: rowData || null
    };
}

async function fetchAllRowsForTable(table, extraParams = {}) {
    const limit = 1000;
    let page = 1;
    let totalPages = 1;
    const allRows = [];

    while (page <= totalPages) {
        const params = new URLSearchParams();
        params.set('limit', String(limit));
        params.set('page', String(page));
        Object.entries(extraParams || {}).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                params.set(key, String(value));
            }
        });

        const response = await fetch(`${'<?php echo PORTFLOW_HOSTNAME; ?>'}/api/${table}?${params.toString()}`);
        if (!response.ok) {
            throw new Error(`Duplicate check failed (${response.status})`);
        }

        const payload = await response.json();
        const items = Array.isArray(payload.items) ? payload.items : [];
        const pageInfo = payload.pageInfo || {};
        const totalResults = Math.max(parseInt(pageInfo.totalResults || items.length, 10), items.length);
        totalPages = Math.max(1, Math.ceil(totalResults / limit));
        allRows.push(...items);
        page += 1;
    }

    return allRows;
}

function buildCaptionDuplicateIndex(rows, formConfig, detailsTableName) {
    const index = new Map();
    const postOrder = Array.isArray(formConfig && formConfig.postOrder) ? formConfig.postOrder : [];

    (rows || []).forEach((row) => {
        const caption = getRowFieldValueForForm(row, detailsTableName, 'metadata', 'caption');
        const normalized = normalizeDuplicateCaption(caption);
        if (!normalized) {
            return;
        }

        const entry = buildCaptionDuplicateEntry(caption, mapEditUuidsFromRow(row, postOrder, detailsTableName), row);
        const bucket = index.get(normalized) || [];
        bucket.push(entry);
        index.set(normalized, bucket);
    });

    return index;
}

function updateCaptionDuplicateIndex(index, caption, entry, mode = 'append') {
    const normalized = normalizeDuplicateCaption(caption);
    if (!normalized || !index) {
        return;
    }

    const bucket = index.get(normalized) || [];
    if (mode === 'replace-first' && bucket.length > 0) {
        bucket[0] = entry;
        index.set(normalized, bucket);
        return;
    }

    bucket.push(entry);
    index.set(normalized, bucket);
}

function askDuplicateCaptionAction(caption, matchCount = 1) {
    return new Promise((resolve) => {
        const modal = document.getElementById('itamTransferDuplicateModal');
        const copy = document.getElementById('itamTransferDuplicateCopy');
        const remember = document.getElementById('itamTransferDuplicateRemember');
        const keepButton = document.getElementById('itamTransferDuplicateKeep');
        const replaceButton = document.getElementById('itamTransferDuplicateReplace');
        const createButton = document.getElementById('itamTransferDuplicateCreate');

        if (!modal || !copy || !remember || !keepButton || !replaceButton || !createButton) {
            resolve({ action: 'keep', remember: false });
            return;
        }

        copy.textContent = `<?php echo $lang['transfer_duplicate_copy'] ?? 'Es gibt bereits einen Eintrag mit dieser Caption'; ?>: "${caption}"${matchCount > 1 ? ` (${matchCount} Treffer)` : ''}`;
        remember.checked = false;
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        const finish = (action) => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            keepButton.removeEventListener('click', onKeep);
            replaceButton.removeEventListener('click', onReplace);
            createButton.removeEventListener('click', onCreate);
            resolve({ action, remember: !!remember.checked });
        };

        const onKeep = () => finish('keep');
        const onReplace = () => finish('replace');
        const onCreate = () => finish('create');

        keepButton.addEventListener('click', onKeep);
        replaceButton.addEventListener('click', onReplace);
        createButton.addEventListener('click', onCreate);
    });
}

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
        return keyWithoutSuffix === tableName || keyWithoutSuffix.endsWith(`_${tableName}`);
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
        if (value == null) {
            field.value = '';
        } else if (typeof value === 'object') {
            field.value = JSON.stringify(value);
        } else {
            field.value = String(value);
        }
    }

    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
}

function parseJsonObjectOrDefault(rawValue, fallback = {}) {
    if (rawValue && typeof rawValue === 'object' && !Array.isArray(rawValue)) {
        return { ...fallback, ...rawValue };
    }

    if (!rawValue || typeof rawValue !== 'string') {
        return { ...fallback };
    }

    const attempts = [];
    const source = String(rawValue || '').trim();
    attempts.push(source);

    const htmlDecoded = source
        .replace(/&quot;/g, '"')
        .replace(/&#34;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&apos;/g, "'")
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>');
    if (htmlDecoded !== source) {
        attempts.push(htmlDecoded);
    }

    const tolerant = htmlDecoded
        .replace(/'/g, '"')
        .replace(/([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)(\s*:)/g, '$1"$2"$3')
        .replace(/,\s*([}\]])/g, '$1');
    if (tolerant !== htmlDecoded) {
        attempts.push(tolerant);
    }

    for (const candidate of attempts) {
        if (!candidate) {
            continue;
        }
        try {
            const parsed = JSON.parse(candidate);
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return { ...fallback, ...parsed };
            }
        } catch (error) {
            // try next candidate
        }
    }

    return { ...fallback };
}

function normalizeSpecificationObject(rawValue) {
    return parseJsonObjectOrDefault(rawValue, {});
}

function serializeSpecificationObject(specification) {
    const normalized = specification && typeof specification === 'object' && !Array.isArray(specification)
        ? specification
        : {};

    const cleaned = {};
    Object.entries(normalized).forEach(([key, value]) => {
        const cleanKey = String(key || '').trim();
        if (!cleanKey) {
            return;
        }

        if (value === null || value === undefined) {
            return;
        }

        const cleanValue = typeof value === 'string' ? value.trim() : String(value);
        if (cleanValue === '') {
            return;
        }

        cleaned[cleanKey] = cleanValue;
    });

    return Object.keys(cleaned).length ? JSON.stringify(cleaned) : '';
}

function setupSpecificationEditors(container) {
    const root = container || document;
    const fields = Array.from(root.querySelectorAll('textarea[name="specification"], input[name="specification"]'));

    fields.forEach((field) => {
        const wrapper = field.closest('.pb-6') || field.parentElement;
        if (!wrapper || wrapper.querySelector('.itam-specification-preview')) {
            return;
        }

        const preview = document.createElement('div');
        preview.className = 'itam-specification-preview mt-2 rounded-xl border border-slate-200 bg-slate-50 p-3';

        const title = document.createElement('div');
        title.className = 'mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
        title.textContent = 'Specification Preview';

        const body = document.createElement('div');
        body.className = 'text-xs text-slate-700';

        preview.appendChild(title);
        preview.appendChild(body);
        wrapper.appendChild(preview);

        const renderPreview = () => {
            const parsed = normalizeSpecificationObject(field.value || '');
            const entries = Object.entries(parsed).filter(([key, value]) => String(key || '').trim() && String(value || '').trim());

            if (entries.length === 0) {
                const plain = String(field.value || '').trim();
                body.innerHTML = plain
                    ? '<div class="whitespace-pre-wrap text-slate-600">' + escapeHtml(plain) + '</div>'
                    : '<div class="text-slate-400">Keine strukturierten Werte erkannt.</div>';
                return;
            }

            const rows = entries.map(([key, value]) => {
                return '<tr>'
                    + '<th class="border-b border-slate-200 px-2 py-1 text-left font-semibold text-slate-600">' + escapeHtml(key) + '</th>'
                    + '<td class="border-b border-slate-200 px-2 py-1 text-left text-slate-900">' + escapeHtml(value) + '</td>'
                    + '</tr>';
            }).join('');

            body.innerHTML = '<div class="overflow-auto"><table class="w-full border-collapse"><tbody>' + rows + '</tbody></table></div>';
        };

        field.addEventListener('input', renderPreview);
        field.addEventListener('change', renderPreview);
        renderPreview();
    });
}

function collectRecordFromForms(formConfig, editMode) {
    const record = {};
    const schema = getItamImportSchema(formConfig);

    schema.forEach(field => {
        const input = document.querySelector(`form [name="${field.name}"]`);
        if (!input) {
            return;
        }

        if (field.type === 'boolean') {
            record[field.name] = input.checked;
            return;
        }

        const rawValue = input.value;
        if (rawValue === null || rawValue === undefined) {
            record[field.name] = null;
            return;
        }

        const value = typeof rawValue === 'string' ? rawValue.trim() : rawValue;
        if (value === '') {
            record[field.name] = null;
            return;
        }

        if (field.type === 'number') {
            const parsed = Number(value);
            record[field.name] = Number.isNaN(parsed) ? null : parsed;
            return;
        }

        record[field.name] = value;
    });

    if (editMode) {
        schema.filter(field => field.type === 'boolean').forEach(field => {
            if (!Object.prototype.hasOwnProperty.call(record, field.name)) {
                record[field.name] = false;
            }
        });
    }

    return record;
}

function buildPostDataFromRecord(record, postConfig, formConfig, editMode = false) {
    const postData = {};
    const fieldList = Array.isArray(postConfig.fields) ? postConfig.fields : [];

    fieldList.forEach((fieldName) => {
        const fieldConfig = (formConfig.fields && formConfig.fields[fieldName]) ? formConfig.fields[fieldName] : {};
        if (!Object.prototype.hasOwnProperty.call(record, fieldName)) {
            return;
        }

        const value = record[fieldName];

        if (fieldConfig.type === 'boolean') {
            if (value === true || value === false) {
                if (value || editMode) {
                    postData[fieldName] = value;
                }
            }
            return;
        }

        if (value === null || value === undefined || value === '') {
            postData[fieldName] = null;
            return;
        }

        if (fieldConfig.type === 'number') {
            const parsed = Number(value);
            postData[fieldName] = Number.isNaN(parsed) ? null : parsed;
            return;
        }

        postData[fieldName] = value;
    });

    return postData;
}

async function submitItamRecord(table, record, options = {}) {
    const responseUuids = {};
    const configData = await getItamFormsConfig();
    const formConfig = getItamFormConfig(configData, table);
    if (!formConfig || !Array.isArray(formConfig.postOrder)) {
        throw new Error(`No form configuration for ${table}`);
    }

    const postOrder = formConfig.postOrder;
    const editMode = !!options.editMode;
    const existingUuids = options.uuids || {};
    let autoPortConfig = null;

    function injectUuids(postData, postConfig) {
        if (postConfig.useMetadataUUID && responseUuids.metadata) {
            postData.metadata = responseUuids.metadata;
        }
        if (postConfig.useIpUUID && responseUuids.device_port_ip) {
            postData.device_port_ip = responseUuids.device_port_ip;
        }
    }

    function hasMeaningfulPostData(postData) {
        return Object.entries(postData || {}).some(([key, value]) => {
            if (key === 'uuid') {
                return false;
            }
            if (value === null || value === undefined) {
                return false;
            }
            if (value === false) {
                return false;
            }
            return String(value).trim() !== '';
        });
    }

    function extractUuidFromApiPayload(payload, rawBody = '') {
        if (!payload) {
            const rawMatch = String(rawBody || '').match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
            return rawMatch ? String(rawMatch[0]) : '';
        }

        if (Array.isArray(payload) && payload[0] && payload[0].uuid) {
            return String(payload[0].uuid);
        }

        if (payload.uuid) {
            return String(payload.uuid);
        }

        if (Array.isArray(payload.items) && payload.items[0] && payload.items[0].uuid) {
            return String(payload.items[0].uuid);
        }

        const rawMatch = String(rawBody || '').match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
        if (rawMatch) {
            return String(rawMatch[0]);
        }

        return '';
    }

    for (const postConfig of postOrder) {
        const postData = buildPostDataFromRecord(record, postConfig, formConfig, editMode);

        if (postConfig.table === 'device') {
            const parsedItemGroup = String(postData.item_group || '').trim();

            if (parsedItemGroup && !isValidPostgresUuid(parsedItemGroup)) {
                throw new Error('Item Group muss eine gueltige UUID sein.');
            }

            postData.item_group = parsedItemGroup || null;

            const isTemplateDevice = isTruthyTemplateValue(postData.template);
            let portLayoutConfig = null;
            const sizeObj = parseJsonObjectOrDefault(postData.size || '{}', {});
            if (sizeObj && sizeObj.portLayout) {
                portLayoutConfig = sizeObj.portLayout;
            }

            if (portLayoutConfig && Array.isArray(portLayoutConfig.groups) && portLayoutConfig.groups.length > 0) {
                autoPortConfig = { groups: portLayoutConfig.groups };
            } else {
                autoPortConfig = { groups: [] };
            }

            if (isTemplateDevice) {
                autoPortConfig = { groups: [] };
            }
        }

        injectUuids(postData, postConfig);

        const targetUuid = editMode ? (existingUuids[postConfig.table] || '') : '';
        let httpMethod = editMode ? 'PATCH' : 'POST';
        let apiUrl = editMode
            ? `<?php echo PORTFLOW_HOSTNAME; ?>/api/${postConfig.table}/${targetUuid}`
            : `<?php echo PORTFLOW_HOSTNAME; ?>/api/${postConfig.table}/`;
        const isOptionalRelationTable = postConfig.table === 'device_port_ip';
        const hasPayloadValues = hasMeaningfulPostData(postData);

        if (editMode && !targetUuid) {
            if (isOptionalRelationTable && !hasPayloadValues) {
                responseUuids[postConfig.table] = '';
                continue;
            }
            httpMethod = 'POST';
            apiUrl = `<?php echo PORTFLOW_HOSTNAME; ?>/api/${postConfig.table}/`;
            console.warn(`Keine UUID fuer ${postConfig.table} gefunden, lege Datensatz neu an.`);
        }

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
        } catch (error) {
            data = null;
        }

        if (!response.ok) {
            throw new Error(`API ${postConfig.table} failed (${response.status}): ${rawBody || 'no response body'}`);
        }

        const responseUuid = extractUuidFromApiPayload(data, rawBody);
        const usesPatchUpdate = editMode && !!targetUuid && httpMethod === 'PATCH';
        const effectiveUuid = usesPatchUpdate ? String(targetUuid) : responseUuid;

        if (!effectiveUuid) {
            if (isOptionalRelationTable) {
                console.warn(`Kein UUID aus ${postConfig.table}-Response ermittelbar. Schritt wird als optional behandelt.`, {
                    table: postConfig.table,
                    method: httpMethod,
                    apiUrl,
                    postData,
                    rawBody
                });
                responseUuids[postConfig.table] = '';
                continue;
            }
            throw new Error(`API ${postConfig.table} returned no UUID.`);
        }

        responseUuids[postConfig.table] = effectiveUuid;
        if (postConfig.table === 'metadata') responseUuids.metadata = effectiveUuid;
        if (postConfig.table === 'device_port_ip') responseUuids.device_port_ip = effectiveUuid;

        if (!editMode && postConfig.table === 'device' && autoPortConfig && autoPortConfig.groups && autoPortConfig.groups.length > 0) {
            const totalPorts = computeAllPortPositions(autoPortConfig.groups).length;
            setProgressOverlayState(true);
            updateProgressOverlay('Auto-Ports werden erstellt ...', 0, totalPorts);

            await createAutoPortsForDevice(effectiveUuid, autoPortConfig.groups, (progress) => {
                updateProgressOverlay(progress.label || 'Auto-Ports werden erstellt ...', progress.current || 0, progress.total || totalPorts);
            });
        }
    }

    return { responseUuids };
}

function createMaskNumberInput(id, labelText, defaultValue = 0, step = '1') {
    const wrapper = document.createElement('div');
    wrapper.className = 'grid gap-1';

    const label = document.createElement('label');
    label.className = 'text-xs font-semibold text-slate-600';
    label.setAttribute('for', id);
    label.textContent = labelText;

    const input = document.createElement('input');
    input.id = id;
    input.type = 'number';
    input.step = step;
    input.value = String(defaultValue);
    input.className = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm';

    wrapper.appendChild(label);
    wrapper.appendChild(input);
    return { wrapper, input };
}

function getNumericOrDefault(value, fallback = 0) {
    if (value === null || value === undefined || value === '') {
        return fallback;
    }

    const parsed = Number(String(value).replace(',', '.'));
    return Number.isFinite(parsed) ? parsed : fallback;
}

function createMaskGroup(title) {
    const group = document.createElement('div');
    group.className = 'rounded-xl border border-slate-300 bg-slate-50 p-3';

    const heading = document.createElement('div');
    heading.className = 'mb-2 text-sm font-bold text-slate-800';
    heading.textContent = title;

    const grid = document.createElement('div');
    grid.className = 'grid grid-cols-3 gap-2';

    group.appendChild(heading);
    group.appendChild(grid);

    return { group, grid };
}

function setupLocationTypeMasks(container) {
    const locationForm = container.querySelector('form#location');
    if (!locationForm) {
        return;
    }

    const typeField = locationForm.querySelector('[name="type"]');
    const positionField = locationForm.querySelector('[name="position"]');
    const sizeField = locationForm.querySelector('[name="size"]');
    const rotationField = locationForm.querySelector('[name="rotation"]');

    if (!typeField || !sizeField || !rotationField) {
        return;
    }

    const sizeWrapper = sizeField.closest('.pb-6');
    const rotationWrapper = rotationField.closest('.pb-6');
    const positionWrapper = positionField ? positionField.closest('.pb-6') : null;

    const maskContainer = document.createElement('div');
    maskContainer.id = 'locationTypeMaskContainer';
    maskContainer.className = 'pb-6';

    const maskTitle = document.createElement('div');
    maskTitle.className = 'mb-2 text-sm font-bold text-slate-800';
    maskTitle.textContent = 'Geometrie-Maske';

    const maskHint = document.createElement('div');
    maskHint.className = 'mb-3 text-xs text-slate-600';
    maskHint.textContent = 'Einfache Eingabe, Speicherung erfolgt automatisch als JSON.';

    const roomPanel = document.createElement('div');
    roomPanel.className = 'grid gap-3';

    const rackPanel = document.createElement('div');
    rackPanel.className = 'grid gap-3';

    const roomSize = createMaskGroup('Room Size (x/y/z)');
    const roomSizeX = createMaskNumberInput('room-size-x', 'X', 10000);
    const roomSizeY = createMaskNumberInput('room-size-y', 'Y', 3000);
    const roomSizeZ = createMaskNumberInput('room-size-z', 'Z', 8000);
    roomSize.grid.appendChild(roomSizeX.wrapper);
    roomSize.grid.appendChild(roomSizeY.wrapper);
    roomSize.grid.appendChild(roomSizeZ.wrapper);

    const roomRotation = createMaskGroup('Room Rotation (x/y/z)');
    const roomRotX = createMaskNumberInput('room-rot-x', 'X', 0, '0.1');
    const roomRotY = createMaskNumberInput('room-rot-y', 'Y', 0, '0.1');
    const roomRotZ = createMaskNumberInput('room-rot-z', 'Z', 0, '0.1');
    roomRotation.grid.appendChild(roomRotX.wrapper);
    roomRotation.grid.appendChild(roomRotY.wrapper);
    roomRotation.grid.appendChild(roomRotZ.wrapper);

    roomPanel.appendChild(roomSize.group);
    roomPanel.appendChild(roomRotation.group);

    const roomPosition = createMaskGroup('Room Position (x/y/z)');
    const roomPosX = createMaskNumberInput('room-pos-x', 'X', 0);
    const roomPosY = createMaskNumberInput('room-pos-y', 'Y', 0);
    const roomPosZ = createMaskNumberInput('room-pos-z', 'Z', 0);
    roomPosition.grid.appendChild(roomPosX.wrapper);
    roomPosition.grid.appendChild(roomPosY.wrapper);
    roomPosition.grid.appendChild(roomPosZ.wrapper);
    roomPanel.appendChild(roomPosition.group);

    const rackOuter = createMaskGroup('Rack Outer (x/y/z)');
    const rackOuterX = createMaskNumberInput('rack-outer-x', 'X', 600);
    const rackOuterY = createMaskNumberInput('rack-outer-y', 'Y', 2200);
    const rackOuterZ = createMaskNumberInput('rack-outer-z', 'Z', 1000);
    rackOuter.grid.appendChild(rackOuterX.wrapper);
    rackOuter.grid.appendChild(rackOuterY.wrapper);
    rackOuter.grid.appendChild(rackOuterZ.wrapper);

    const rackInner = createMaskGroup('Rack Inner (x/y/z)');
    const rackInnerX = createMaskNumberInput('rack-inner-x', 'X', 550);
    const rackInnerY = createMaskNumberInput('rack-inner-y', 'Y', 2080);
    const rackInnerZ = createMaskNumberInput('rack-inner-z', 'Z', 920);
    rackInner.grid.appendChild(rackInnerX.wrapper);
    rackInner.grid.appendChild(rackInnerY.wrapper);
    rackInner.grid.appendChild(rackInnerZ.wrapper);

    const rackBetween = createMaskGroup('Rack Between');
    const betweenXL = createMaskNumberInput('rack-between-xl', 'x_left', 25);
    const betweenXR = createMaskNumberInput('rack-between-xr', 'x_right', 25);
    const betweenYB = createMaskNumberInput('rack-between-yb', 'y_bottom', 60);
    const betweenYT = createMaskNumberInput('rack-between-yt', 'y_top', 60);
    const betweenZF = createMaskNumberInput('rack-between-zf', 'z_front', 40);
    const betweenZB = createMaskNumberInput('rack-between-zb', 'z_back', 40);
    rackBetween.grid.className = 'grid grid-cols-2 gap-2';
    rackBetween.grid.appendChild(betweenXL.wrapper);
    rackBetween.grid.appendChild(betweenXR.wrapper);
    rackBetween.grid.appendChild(betweenYB.wrapper);
    rackBetween.grid.appendChild(betweenYT.wrapper);
    rackBetween.grid.appendChild(betweenZF.wrapper);
    rackBetween.grid.appendChild(betweenZB.wrapper);

    const rackRotation = createMaskGroup('Rack Rotation (x/y/z)');
    const rackRotX = createMaskNumberInput('rack-rot-x', 'X', 0, '0.1');
    const rackRotY = createMaskNumberInput('rack-rot-y', 'Y', 0, '0.1');
    const rackRotZ = createMaskNumberInput('rack-rot-z', 'Z', 0, '0.1');
    rackRotation.grid.appendChild(rackRotX.wrapper);
    rackRotation.grid.appendChild(rackRotY.wrapper);
    rackRotation.grid.appendChild(rackRotZ.wrapper);

    rackPanel.appendChild(rackOuter.group);
    rackPanel.appendChild(rackInner.group);
    rackPanel.appendChild(rackBetween.group);
    rackPanel.appendChild(rackRotation.group);

    const rackPosition = createMaskGroup('Rack Position im Raum (x/y/z)');
    const rackPosX = createMaskNumberInput('rack-pos-x', 'X', 0);
    const rackPosY = createMaskNumberInput('rack-pos-y', 'Y', 0);
    const rackPosZ = createMaskNumberInput('rack-pos-z', 'Z', 0);
    rackPosition.grid.appendChild(rackPosX.wrapper);
    rackPosition.grid.appendChild(rackPosY.wrapper);
    rackPosition.grid.appendChild(rackPosZ.wrapper);
    rackPanel.appendChild(rackPosition.group);

    const rackLimits = createMaskGroup('Rack Limits');
    rackLimits.grid.className = 'grid grid-cols-3 gap-2';
    const rackLimitWeight = createMaskNumberInput('rack-limit-weight', 'Gewicht (kg)', 120);
    const rackLimitPower = createMaskNumberInput('rack-limit-power', 'Power (W)', 1200);
    const rackLimitThermal = createMaskNumberInput('rack-limit-thermal', 'Thermal (W)', 1100);
    rackLimits.grid.appendChild(rackLimitWeight.wrapper);
    rackLimits.grid.appendChild(rackLimitPower.wrapper);
    rackLimits.grid.appendChild(rackLimitThermal.wrapper);
    rackPanel.appendChild(rackLimits.group);

    maskContainer.appendChild(maskTitle);
    maskContainer.appendChild(maskHint);
    maskContainer.appendChild(roomPanel);
    maskContainer.appendChild(rackPanel);

    if (rotationWrapper && rotationWrapper.parentNode) {
        rotationWrapper.parentNode.insertBefore(maskContainer, rotationWrapper.nextSibling);
    } else {
        locationForm.appendChild(maskContainer);
    }

    const initialSize = parseJsonObjectOrDefault(sizeField.value, {});
    const initialRotation = parseJsonObjectOrDefault(rotationField.value, { x: 0, y: 0, z: 0 });
    const initialPosition = parseJsonObjectOrDefault(positionField ? positionField.value : '', { x: 0, y: 0, z: 0 });

    if (initialSize.outer || initialSize.inner || initialSize.between) {
        const outer = initialSize.outer || {};
        const inner = initialSize.inner || {};
        const between = initialSize.between || {};
        rackOuterX.input.value = String(getNumericOrDefault(outer.x, 600));
        rackOuterY.input.value = String(getNumericOrDefault(outer.y, 2200));
        rackOuterZ.input.value = String(getNumericOrDefault(outer.z, 1000));
        rackInnerX.input.value = String(getNumericOrDefault(inner.x, 550));
        rackInnerY.input.value = String(getNumericOrDefault(inner.y, 2080));
        rackInnerZ.input.value = String(getNumericOrDefault(inner.z, 920));
        betweenXL.input.value = String(getNumericOrDefault(between.x_left, 25));
        betweenXR.input.value = String(getNumericOrDefault(between.x_right, 25));
        betweenYB.input.value = String(getNumericOrDefault(between.y_bottom, 60));
        betweenYT.input.value = String(getNumericOrDefault(between.y_top, 60));
        betweenZF.input.value = String(getNumericOrDefault(between.z_front, 40));
        betweenZB.input.value = String(getNumericOrDefault(between.z_back, 40));
        const limits = initialSize.limits || {};
        rackLimitWeight.input.value = String(getNumericOrDefault(limits.weightKg, 120));
        rackLimitPower.input.value = String(getNumericOrDefault(limits.powerW, 1200));
        rackLimitThermal.input.value = String(getNumericOrDefault(limits.thermalW, 1100));
    } else {
        roomSizeX.input.value = String(getNumericOrDefault(initialSize.x, 10000));
        roomSizeY.input.value = String(getNumericOrDefault(initialSize.y, 3000));
        roomSizeZ.input.value = String(getNumericOrDefault(initialSize.z, 8000));
    }

    roomPosX.input.value = String(getNumericOrDefault(initialPosition.x, 0));
    roomPosY.input.value = String(getNumericOrDefault(initialPosition.y, 0));
    roomPosZ.input.value = String(getNumericOrDefault(initialPosition.z, 0));
    rackPosX.input.value = String(getNumericOrDefault(initialPosition.x, 0));
    rackPosY.input.value = String(getNumericOrDefault(initialPosition.y, 0));
    rackPosZ.input.value = String(getNumericOrDefault(initialPosition.z, 0));

    roomRotX.input.value = String(getNumericOrDefault(initialRotation.x, 0));
    roomRotY.input.value = String(getNumericOrDefault(initialRotation.y, 0));
    roomRotZ.input.value = String(getNumericOrDefault(initialRotation.z, 0));
    rackRotX.input.value = String(getNumericOrDefault(initialRotation.x, 0));
    rackRotY.input.value = String(getNumericOrDefault(initialRotation.y, 0));
    rackRotZ.input.value = String(getNumericOrDefault(initialRotation.z, 0));

    const writeRoomJsonToFields = () => {
        const roomSizeJson = {
            x: getNumericOrDefault(roomSizeX.input.value, 10000),
            y: getNumericOrDefault(roomSizeY.input.value, 3000),
            z: getNumericOrDefault(roomSizeZ.input.value, 8000)
        };

        const roomRotationJson = {
            x: getNumericOrDefault(roomRotX.input.value, 0),
            y: getNumericOrDefault(roomRotY.input.value, 0),
            z: getNumericOrDefault(roomRotZ.input.value, 0)
        };

        sizeField.value = JSON.stringify(roomSizeJson);
        rotationField.value = JSON.stringify(roomRotationJson);
        if (positionField) {
            positionField.value = JSON.stringify({
                x: getNumericOrDefault(roomPosX.input.value, 0),
                y: getNumericOrDefault(roomPosY.input.value, 0),
                z: getNumericOrDefault(roomPosZ.input.value, 0)
            });
        }
    };

    const writeRackJsonToFields = () => {
        const rackSizeJson = {
            outer: {
                x: getNumericOrDefault(rackOuterX.input.value, 600),
                y: getNumericOrDefault(rackOuterY.input.value, 2200),
                z: getNumericOrDefault(rackOuterZ.input.value, 1000)
            },
            inner: {
                x: getNumericOrDefault(rackInnerX.input.value, 550),
                y: getNumericOrDefault(rackInnerY.input.value, 2080),
                z: getNumericOrDefault(rackInnerZ.input.value, 920)
            },
            between: {
                x_left: getNumericOrDefault(betweenXL.input.value, 25),
                x_right: getNumericOrDefault(betweenXR.input.value, 25),
                y_bottom: getNumericOrDefault(betweenYB.input.value, 60),
                y_top: getNumericOrDefault(betweenYT.input.value, 60),
                z_front: getNumericOrDefault(betweenZF.input.value, 40),
                z_back: getNumericOrDefault(betweenZB.input.value, 40)
            },
            limits: {
                weightKg: getNumericOrDefault(rackLimitWeight.input.value, 120),
                powerW: getNumericOrDefault(rackLimitPower.input.value, 1200),
                thermalW: getNumericOrDefault(rackLimitThermal.input.value, 1100)
            }
        };

        const rackRotationJson = {
            x: getNumericOrDefault(rackRotX.input.value, 0),
            y: getNumericOrDefault(rackRotY.input.value, 0),
            z: getNumericOrDefault(rackRotZ.input.value, 0)
        };

        sizeField.value = JSON.stringify(rackSizeJson);
        rotationField.value = JSON.stringify(rackRotationJson);
        if (positionField) {
            positionField.value = JSON.stringify({
                x: getNumericOrDefault(rackPosX.input.value, 0),
                y: getNumericOrDefault(rackPosY.input.value, 0),
                z: getNumericOrDefault(rackPosZ.input.value, 0)
            });
        }
    };

    const syncMaskVisibilityAndJson = () => {
        const typeValue = String(typeField.value || '').trim();
        const isRoom = typeValue === '6';
        const isRack = typeValue === '8';

        if (sizeWrapper) {
            sizeWrapper.classList.toggle('hidden', true);
        }
        if (rotationWrapper) {
            rotationWrapper.classList.toggle('hidden', true);
        }
        if (positionWrapper) {
            positionWrapper.classList.toggle('hidden', true);
        }

        maskContainer.classList.toggle('hidden', !(isRoom || isRack));
        roomPanel.classList.toggle('hidden', !isRoom);
        rackPanel.classList.toggle('hidden', !isRack);

        if (isRoom) {
            writeRoomJsonToFields();
        } else if (isRack) {
            writeRackJsonToFields();
        }
    };

    [
        roomSizeX.input, roomSizeY.input, roomSizeZ.input,
        roomRotX.input, roomRotY.input, roomRotZ.input,
        rackOuterX.input, rackOuterY.input, rackOuterZ.input,
        rackInnerX.input, rackInnerY.input, rackInnerZ.input,
        betweenXL.input, betweenXR.input, betweenYB.input, betweenYT.input, betweenZF.input, betweenZB.input,
        rackRotX.input, rackRotY.input, rackRotZ.input,
        roomPosX.input, roomPosY.input, roomPosZ.input,
        rackPosX.input, rackPosY.input, rackPosZ.input,
        rackLimitWeight.input, rackLimitPower.input, rackLimitThermal.input
    ].forEach(input => {
        input.addEventListener('input', syncMaskVisibilityAndJson);
        input.addEventListener('change', syncMaskVisibilityAndJson);
    });

    typeField.addEventListener('change', syncMaskVisibilityAndJson);
    syncMaskVisibilityAndJson();
}

function setupDevice3DMasks(container) {
    const deviceForm = container.querySelector('form#device');
    if (!deviceForm) {
        return;
    }

    const sizeField = deviceForm.querySelector('[name="size"]');
    const positionField = deviceForm.querySelector('[name="position"]');
    const rotationField = deviceForm.querySelector('[name="rotation"]');

    if (!sizeField || !positionField || !rotationField) {
        return;
    }

    const sizeWrapper = sizeField.closest('.pb-6');
    const positionWrapper = positionField.closest('.pb-6');
    const rotationWrapper = rotationField.closest('.pb-6');

    const maskContainer = document.createElement('div');
    maskContainer.id = 'device3DMaskContainer';
    maskContainer.className = 'pb-6';

    const maskTitle = document.createElement('div');
    maskTitle.className = 'mb-2 text-sm font-bold text-slate-800';
    maskTitle.textContent = 'Device 3D-Maske';

    const maskHint = document.createElement('div');
    maskHint.className = 'mb-3 text-xs text-slate-600';
    maskHint.textContent = 'Gefuehrte Eingabe fuer RU-Position und Geometrie, Speicherung erfolgt automatisch als JSON.';

    const ruInfo = document.createElement('div');
    ruInfo.className = 'mb-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900';
    ruInfo.textContent = '1 RU = 44.45 mm';

    const ruGroup = createMaskGroup('RU Placement');
    const startRu = createMaskNumberInput('device-ru-start', 'Start RU', 1, '1');
    const heightRu = createMaskNumberInput('device-ru-height', 'Hoehe RU', 1, '0.5');
    ruGroup.grid.className = 'grid grid-cols-2 gap-2';
    ruGroup.grid.appendChild(startRu.wrapper);
    ruGroup.grid.appendChild(heightRu.wrapper);

    const sizeGroup = createMaskGroup('Device Size (mm)');
    const sizeX = createMaskNumberInput('device-size-x', 'X (Breite)', 445, '1');
    const sizeY = createMaskNumberInput('device-size-y', 'Y (Hoehe)', 44.45, '0.01');
    const sizeZ = createMaskNumberInput('device-size-z', 'Z (Tiefe)', 300, '1');
    const weightKg = createMaskNumberInput('device-weight-kg', 'Gewicht (kg)', 5, '0.1');
    sizeY.input.readOnly = true;
    sizeY.input.classList.add('bg-slate-100');
    sizeGroup.grid.className = 'grid grid-cols-2 gap-2';
    sizeGroup.grid.appendChild(sizeX.wrapper);
    sizeGroup.grid.appendChild(sizeY.wrapper);
    sizeGroup.grid.appendChild(sizeZ.wrapper);
    sizeGroup.grid.appendChild(weightKg.wrapper);

    const positionGroup = createMaskGroup('Device Position (mm)');
    const posX = createMaskNumberInput('device-pos-x', 'X', 0, '1');
    const posY = createMaskNumberInput('device-pos-y', 'Y (aus RU)', 0, '0.01');
    const posZ = createMaskNumberInput('device-pos-z', 'Z', 0, '1');
    posY.input.readOnly = true;
    posY.input.classList.add('bg-slate-100');
    positionGroup.grid.appendChild(posX.wrapper);
    positionGroup.grid.appendChild(posY.wrapper);
    positionGroup.grid.appendChild(posZ.wrapper);

    const rotationGroup = createMaskGroup('Device Rotation (x/y/z)');
    const rotX = createMaskNumberInput('device-rot-x', 'X', 0, '0.1');
    const rotY = createMaskNumberInput('device-rot-y', 'Y', 0, '0.1');
    const rotZ = createMaskNumberInput('device-rot-z', 'Z', 0, '0.1');
    rotationGroup.grid.appendChild(rotX.wrapper);
    rotationGroup.grid.appendChild(rotY.wrapper);
    rotationGroup.grid.appendChild(rotZ.wrapper);

    const panel = document.createElement('div');
    panel.className = 'grid gap-3';
    panel.appendChild(ruGroup.group);
    panel.appendChild(sizeGroup.group);
    panel.appendChild(positionGroup.group);
    panel.appendChild(rotationGroup.group);

    maskContainer.appendChild(maskTitle);
    maskContainer.appendChild(maskHint);
    maskContainer.appendChild(ruInfo);
    maskContainer.appendChild(panel);

    if (rotationWrapper && rotationWrapper.parentNode) {
        rotationWrapper.parentNode.insertBefore(maskContainer, rotationWrapper.nextSibling);
    } else {
        deviceForm.appendChild(maskContainer);
    }

    const mmPerRu = 44.45;
    let isSyncing = false;

    const roundTo = (value, decimals = 2) => {
        const factor = 10 ** decimals;
        return Math.round(value * factor) / factor;
    };

    const applyRuToDerivedFields = () => {
        const start = Math.max(1, getNumericOrDefault(startRu.input.value, 1));
        const height = Math.max(0.5, getNumericOrDefault(heightRu.input.value, 1));

        const mmY = roundTo((start - 1) * mmPerRu, 2);
        const mmHeight = roundTo(height * mmPerRu, 2);

        posY.input.value = String(mmY);
        sizeY.input.value = String(mmHeight);
    };

    const writeMaskToJsonFields = () => {
        applyRuToDerivedFields();

        // Preserve any extra properties (e.g. portLayout written by the Port Layout Builder)
        // by merging instead of replacing the size JSON.
        const existingSize = parseJsonObjectOrDefault(sizeField.value || '{}', {});
        existingSize.x = getNumericOrDefault(sizeX.input.value, 445);
        existingSize.y = getNumericOrDefault(sizeY.input.value, 44.45);
        existingSize.z = getNumericOrDefault(sizeZ.input.value, 300);
        existingSize.weightKg = getNumericOrDefault(weightKg.input.value, 5);
        sizeField.value = JSON.stringify(existingSize);

        positionField.value = JSON.stringify({
            x: getNumericOrDefault(posX.input.value, 0),
            y: getNumericOrDefault(posY.input.value, 0),
            z: getNumericOrDefault(posZ.input.value, 0)
        });

        rotationField.value = JSON.stringify({
            x: getNumericOrDefault(rotX.input.value, 0),
            y: getNumericOrDefault(rotY.input.value, 0),
            z: getNumericOrDefault(rotZ.input.value, 0)
        });
    };

    const syncMaskFromJsonFields = () => {
        if (isSyncing) {
            return;
        }

        const sizeJson = parseJsonObjectOrDefault(sizeField.value, {});
        const positionJson = parseJsonObjectOrDefault(positionField.value, {});
        const rotationJson = parseJsonObjectOrDefault(rotationField.value, {});

        const currentSizeY = getNumericOrDefault(sizeJson.y, 44.45);
        const currentPosY = getNumericOrDefault(positionJson.y, 0);

        sizeX.input.value = String(getNumericOrDefault(sizeJson.x, 445));
        sizeZ.input.value = String(getNumericOrDefault(sizeJson.z, 300));
        weightKg.input.value = String(getNumericOrDefault(sizeJson.weightKg ?? sizeJson.weight, 5));
        posX.input.value = String(getNumericOrDefault(positionJson.x, 0));
        posZ.input.value = String(getNumericOrDefault(positionJson.z, 0));
        rotX.input.value = String(getNumericOrDefault(rotationJson.x, 0));
        rotY.input.value = String(getNumericOrDefault(rotationJson.y, 0));
        rotZ.input.value = String(getNumericOrDefault(rotationJson.z, 0));

        const derivedHeightRu = Math.max(0.5, roundTo(currentSizeY / mmPerRu, 2));
        const derivedStartRu = Math.max(1, roundTo((currentPosY / mmPerRu) + 1, 2));
        heightRu.input.value = String(derivedHeightRu);
        startRu.input.value = String(derivedStartRu);

        applyRuToDerivedFields();
    };

    const syncAll = () => {
        if (isSyncing) {
            return;
        }

        isSyncing = true;
        try {
            writeMaskToJsonFields();
        } finally {
            isSyncing = false;
        }
    };

    [
        startRu.input,
        heightRu.input,
        sizeX.input,
        sizeZ.input,
        weightKg.input,
        posX.input,
        posZ.input,
        rotX.input,
        rotY.input,
        rotZ.input
    ].forEach(input => {
        input.addEventListener('input', syncAll);
        input.addEventListener('change', syncAll);
    });

    [sizeField, positionField, rotationField].forEach(field => {
        field.addEventListener('input', syncMaskFromJsonFields);
        field.addEventListener('change', syncMaskFromJsonFields);
    });

    if (sizeWrapper) {
        sizeWrapper.classList.add('hidden');
    }
    if (positionWrapper) {
        positionWrapper.classList.add('hidden');
    }
    if (rotationWrapper) {
        rotationWrapper.classList.add('hidden');
    }

    syncMaskFromJsonFields();
    syncAll();
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

    // Port layout config is embedded in the size field (portLayout property)
    // When size is applied above, the builder will pick it up on re-init
    setupDevicePortAutomation();

    // Do NOT touch the 'template' checkbox here. The user controls it explicitly
    // so they can also create a new template based on an existing template.
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
    tabs.className = 'flex flex-wrap gap-2 pb-3';

    const newButton = document.createElement('button');
    newButton.type = 'button';
    newButton.className = 'rounded-full border border-blue-600 bg-blue-600 px-4 py-1.5 font-semibold text-white';
    newButton.textContent = 'Neues Geraet';

    const templateButton = document.createElement('button');
    templateButton.type = 'button';
    templateButton.className = 'rounded-full border border-slate-300 bg-white px-4 py-1.5 font-semibold text-slate-700';
    templateButton.textContent = 'Aus Template';

    tabs.appendChild(newButton);
    tabs.appendChild(templateButton);
    container.insertBefore(tabs, insertionAnchor);

    const picker = document.createElement('div');
    picker.className = 'mb-3 grid hidden gap-2 rounded-xl border border-slate-300 bg-slate-50 p-3';

    const pickerInfo = document.createElement('small');
    pickerInfo.textContent = 'Template auswaehlen, Felder werden vorbefuellt und koennen danach angepasst werden.';

    const pickerRow = document.createElement('div');
    pickerRow.className = 'flex flex-wrap gap-2';

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

        newButton.classList.toggle('border-blue-600', !templateMode);
        newButton.classList.toggle('bg-blue-600', !templateMode);
        newButton.classList.toggle('text-white', !templateMode);
        newButton.classList.toggle('border-slate-300', templateMode);
        newButton.classList.toggle('bg-white', templateMode);
        newButton.classList.toggle('text-slate-700', templateMode);

        templateButton.classList.toggle('border-blue-600', templateMode);
        templateButton.classList.toggle('bg-blue-600', templateMode);
        templateButton.classList.toggle('text-white', templateMode);
        templateButton.classList.toggle('border-slate-300', !templateMode);
        templateButton.classList.toggle('bg-white', !templateMode);
        templateButton.classList.toggle('text-slate-700', !templateMode);
        picker.classList.toggle('hidden', !templateMode);

        // Do NOT force the template checkbox; the user decides whether the
        // newly created entry should itself be a template.
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

let __locationBreadcrumbCachePromise = null;
function getLocationBreadcrumbCache(forceReload = false) {
    if (forceReload) {
        __locationBreadcrumbCachePromise = null;
    }
    if (!__locationBreadcrumbCachePromise) {
        __locationBreadcrumbCachePromise = (async () => {
            try {
                const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/location_details?limit=10000');
                const payload = await response.json();
                const rows = (payload && payload.items) ? payload.items : [];
                const byUuid = new Map();
                rows.forEach(row => {
                    const uuid = String(row.location_uuid || row.uuid || '').trim();
                    if (!uuid) return;
                    byUuid.set(uuid, {
                        uuid,
                        caption: String(row.location_metadata_caption || '').trim(),
                        parent: String(row.location_parent_location || '').trim()
                    });
                });
                return byUuid;
            } catch (err) {
                console.warn('Location-Cache konnte nicht geladen werden:', err);
                return new Map();
            }
        })();
    }
    return __locationBreadcrumbCachePromise;
}

function buildLocationBreadcrumb(uuid, cache) {
    if (!uuid || !cache) return '';
    const parts = [];
    const seen = new Set();
    let cursor = String(uuid).trim();
    while (cursor && !seen.has(cursor) && cache.has(cursor)) {
        seen.add(cursor);
        const node = cache.get(cursor);
        if (node.caption) parts.unshift(node.caption);
        cursor = node.parent;
    }
    if (parts.length === 0) {
        const node = cache.get(String(uuid).trim());
        return node ? (node.caption || '') : '';
    }
    return parts.join(' › ');
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
    let scopeLabel = '';
    if (suggestion.scope === 'parent') scopeLabel = ' (übergeordneter Standort)';
    else if (suggestion.scope === 'ancestor') scopeLabel = ' (Gebäude)';
    badge.textContent = suggestion.room + scopeLabel;

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
    suggestionHeader.innerHTML = '<div class="text-lg font-bold">Vorschläge</div><div class="text-sm text-gray-500">Gleiche Portnamen im selben Standort, übergeordneten Standort oder Gebäude — unverbundene Paare</div>';

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

function buildConnectionSuggestions(ports, connections, locations = []) {
    const connectedPorts = new Set();
    connections.forEach(connection => {
        if (connection.connection_device_port_source) {
            connectedPorts.add(connection.connection_device_port_source);
        }
        if (connection.connection_device_port_destination) {
            connectedPorts.add(connection.connection_device_port_destination);
        }
    });

    // Lookup table for the location chain.
    const locById = new Map();
    locations.forEach(loc => {
        const uuid = loc.location_uuid;
        if (!uuid) return;
        locById.set(uuid, {
            uuid,
            parent: loc.location_parent_location || null,
            type: loc.location_type !== undefined && loc.location_type !== null ? String(loc.location_type) : '',
            caption: (loc.location_metadata_caption || '').trim()
        });
    });

    // Walk ancestors from start uuid up to (and including) the first building (type=4).
    function ancestorsOf(startUuid) {
        const chain = [];
        let cur = startUuid ? locById.get(startUuid) : null;
        const seen = new Set();
        let guard = 0;
        while (cur && guard++ < 32 && !seen.has(cur.uuid)) {
            seen.add(cur.uuid);
            chain.push(cur);
            if (cur.type === '4') break; // stop at building
            cur = cur.parent ? locById.get(cur.parent) : null;
        }
        return chain;
    }

    // For every port collect its scope chain (level 0 = own location, increasing
    // levels for each ancestor up to and including the building).
    const portInfo = new Map(); // portUuid -> {uuid,label,deviceType,deviceCaption,chain}
    ports.forEach(port => {
        const uuid = port.device_port_uuid;
        const label = (port.device_port_metadata_caption || '').trim();
        if (!uuid || !label || connectedPorts.has(uuid)) return;
        const ownLocUuid = port.device_port_device_location_uuid || port.device_port_device_location || null;
        const chain = ancestorsOf(ownLocUuid);
        if (chain.length === 0) return; // no location at all -> nothing to group on
        portInfo.set(uuid, {
            uuid,
            label,
            deviceType: (port.device_port_device_type || '').trim().toLowerCase(),
            deviceCaption: port.device_port_device_metadata_caption || port.device_port_device_type || 'Device',
            chain
        });
    });

    // Build buckets keyed by (location_uuid + label). For each bucket, remember the
    // shallowest (= most precise) match level per port -- that becomes the match depth.
    // bucketKey -> { caption, locType, entries: [ {port, level} ] }
    const buckets = new Map();
    portInfo.forEach(p => {
        p.chain.forEach((loc, level) => {
            const key = `${loc.uuid}::${p.label}`;
            if (!buckets.has(key)) {
                buckets.set(key, { caption: loc.caption || '(ohne Bezeichnung)', locType: loc.type, entries: [] });
            }
            buckets.get(key).entries.push({ port: p, level });
        });
    });

    // Generate candidate pairings: a "candidate" is one bucket where both a patchpanel
    // and a net_outlet exist. Score by max(level) of the involved ports -- lower is better.
    const candidates = [];
    buckets.forEach((bucket, key) => {
        const patchPanels = bucket.entries
            .filter(e => e.port.deviceType === 'patchpanel')
            .sort((a, b) => a.port.deviceCaption.localeCompare(b.port.deviceCaption));
        const outlets = bucket.entries
            .filter(e => e.port.deviceType === 'net_outlet')
            .sort((a, b) => a.port.deviceCaption.localeCompare(b.port.deviceCaption));
        const count = Math.min(patchPanels.length, outlets.length);
        for (let i = 0; i < count; i++) {
            const src = patchPanels[i];
            const dst = outlets[i];
            const depth = Math.max(src.level, dst.level);
            candidates.push({ key, depth, bucket, src: src.port, dst: dst.port });
        }
    });

    // Pair greedily, preferring shallower depth.
    candidates.sort((a, b) => a.depth - b.depth);
    const usedPortUuids = new Set();
    const suggestions = [];
    candidates.forEach(c => {
        if (usedPortUuids.has(c.src.uuid) || usedPortUuids.has(c.dst.uuid)) return;
        usedPortUuids.add(c.src.uuid);
        usedPortUuids.add(c.dst.uuid);
        let scope;
        if (c.depth === 0) scope = 'location';
        else if (c.depth === 1) scope = 'parent';
        else if (c.bucket.locType === '4') scope = 'building';
        else scope = 'ancestor';
        suggestions.push({
            label: c.src.label,
            room: c.bucket.caption,
            scope,
            source: { uuid: c.src.uuid, deviceCaption: c.src.deviceCaption },
            destination: { uuid: c.dst.uuid, deviceCaption: c.dst.deviceCaption }
        });
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
        const [portsResponse, connectionsResponse, locationsResponse] = await Promise.all([
            fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/device_port_details?limit=5000'),
            fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/connection_details?limit=5000'),
            fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/location_details?limit=5000')
        ]);

        const portsData = await portsResponse.json();
        const connectionsData = await connectionsResponse.json();
        const locationsData = await locationsResponse.json();

        const suggestions = buildConnectionSuggestions(
            portsData.items || [],
            connectionsData.items || [],
            locationsData.items || []
        );

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

        // Stay on the suggestions tab: remove the just-handled card and refresh the list.
        const card = buttonElement ? buttonElement.closest('.flex.flex-col.gap-2.p-4.border.rounded-2xl') : null;
        if (card) {
            card.remove();
        }
        const list = document.getElementById('connectionSuggestionList');
        const panel = document.getElementById('connectionSuggestionsPanel');
        if (panel) {
            // Force a reload next time the user toggles tabs, and refresh now too.
            delete panel.dataset.loaded;
        }
        if (list) {
            await loadConnectionSuggestions(list);
            if (panel) panel.dataset.loaded = 'true';
        }
    } catch (error) {
        console.error('Fehler beim Erstellen der Vorschlagsverbindung:', error);
        if (buttonElement) {
            buttonElement.disabled = false;
            buttonElement.textContent = 'Verbinden';
        }
    }
}

/**
 * Port Layout Builder - Multi-Group visual editor for device ports.
 * Supports drag & drop of groups, real mm sizing, presets, and column-first numbering.
 */
function setupDevicePortAutomation() {
    const form = document.getElementById('device');
    if (!form) return;

    const typeField = form.querySelector('[name="type"]');
    const templateField = form.querySelector('[name="template"]');
    const sizeField = form.querySelector('[name="size"]');
    const metadataForm = document.getElementById('metadata');
    const specificationField = metadataForm ? metadataForm.querySelector('[name="specification"]') : null;

    // Port groups state
    let portGroups = [];
    let builderContainer = null;
    // Guard: when the builder writes to sizeField via syncConfigField, the resulting
    // input/change event must NOT trigger reloadFromSize (which would re-render and steal focus).
    let writingFromBuilder = false;

    const getDeviceDimensions = () => {
        let dw = 440, dh = 44;
        const sizeVal = sizeField ? sizeField.value : '';
        if (sizeVal) {
            const parsed = parseJsonObjectOrDefault(sizeVal, {});
            if (parsed.x) dw = parseFloat(parsed.x) || 440;
            if (parsed.y) dh = parseFloat(parsed.y) || 44;
        }
        return { w: dw, h: dh };
    };

    const syncConfigField = () => {
        if (!sizeField) return;
        const dim = getDeviceDimensions();
        const parsed = parseJsonObjectOrDefault(sizeField.value || '{}', {});
        parsed.portLayout = { deviceWidth: dim.w, deviceHeight: dim.h, groups: portGroups };
        // Preserve power fields
        syncPowerFieldsToSize(parsed);
        writingFromBuilder = true;
        try {
            sizeField.value = JSON.stringify(parsed);
        } finally {
            writingFromBuilder = false;
        }
    };

    // --- Power fields sync via metadata specification ---
    const syncPowerFieldsToSize = (parsed) => {
        void parsed;
        const panel = document.getElementById('powerFieldsPanel');
        if (!panel || !specificationField) return;
        const get = (id) => {
            const el = panel.querySelector(`#${id}`);
            return el ? el.value : '';
        };
        const pConsumption = parseFloat(String(get('pwrConsumptionW')).replace(',', '.')) || 0;
        const pOutput = parseFloat(String(get('pwrOutputW')).replace(',', '.')) || 0;
        const pOutputVA = parseFloat(String(get('pwrOutputVA')).replace(',', '.')) || 0;
        const pPhases = parseInt(get('pwrPhases'), 10) || 1;

        const specification = normalizeSpecificationObject(specificationField.value || '');
        if (pConsumption > 0) specification.powerConsumptionW = String(pConsumption);
        else delete specification.powerConsumptionW;
        if (pOutput > 0) specification.powerOutputW = String(pOutput);
        else delete specification.powerOutputW;
        if (pOutputVA > 0) specification.powerOutputVA = String(pOutputVA);
        else delete specification.powerOutputVA;
        if (pPhases > 1) specification.phases = String(pPhases);
        else delete specification.phases;

        specificationField.value = serializeSpecificationObject(specification);
        specificationField.dispatchEvent(new Event('input', { bubbles: true }));
        specificationField.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const loadPowerFieldsFromSize = () => {
        const panel = document.getElementById('powerFieldsPanel');
        if (!panel) return;
        const specification = normalizeSpecificationObject(specificationField ? specificationField.value : '');
        const set = (id, val) => {
            const el = panel.querySelector(`#${id}`);
            if (el) el.value = val || '';
        };
        set('pwrConsumptionW', specification.powerConsumptionW || '');
        set('pwrOutputW', specification.powerOutputW || '');
        set('pwrOutputVA', specification.powerOutputVA || '');
        set('pwrPhases', specification.phases || '1');
        updatePhaseInfo();
    };

    const updatePhaseInfo = () => {
        const panel = document.getElementById('powerFieldsPanel');
        if (!panel) return;
        const phases = parseInt(panel.querySelector('#pwrPhases')?.value, 10) || 1;
        const info = panel.querySelector('#pwrPhaseInfo');
        if (info) {
            if (phases === 3) {
                info.textContent = '3-Phasen: Ausgänge werden gleichmäßig auf L1, L2, L3 verteilt.';
                info.classList.remove('hidden');
            } else {
                info.classList.add('hidden');
            }
        }
    };

    let powerFieldsPanel = null;

    const ensurePowerPanel = () => {
        if (powerFieldsPanel) return powerFieldsPanel;
        const existingPanel = document.getElementById('powerFieldsPanel');
        if (existingPanel) existingPanel.remove();

        powerFieldsPanel = document.createElement('div');
        powerFieldsPanel.id = 'powerFieldsPanel';
        powerFieldsPanel.className = 'col-span-2 pb-6 hidden';
        powerFieldsPanel.innerHTML = `
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4">
                <div class="mb-3">
                    <div class="text-lg font-bold text-amber-800"><i data-lucide="zap" class="inline w-5 h-5 mr-1"></i>Strom-Konfiguration</div>
                    <div class="text-xs text-amber-600">Werte werden in Metadata Specification gespeichert.</div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <label class="block">
                        <span class="text-xs text-amber-700 font-medium">Stromverbrauch (W)</span>
                        <input type="number" id="pwrConsumptionW" min="0" step="1" placeholder="z.B. 50" class="mt-0.5 w-full rounded border border-amber-200 bg-white px-2 py-1.5 text-xs">
                    </label>
                    <label class="block">
                        <span class="text-xs text-amber-700 font-medium">Ausgangsleistung (W)</span>
                        <input type="number" id="pwrOutputW" min="0" step="1" placeholder="z.B. 3000" class="mt-0.5 w-full rounded border border-amber-200 bg-white px-2 py-1.5 text-xs">
                    </label>
                    <label class="block">
                        <span class="text-xs text-amber-700 font-medium">Scheinleistung (VA)</span>
                        <input type="number" id="pwrOutputVA" min="0" step="1" placeholder="z.B. 3750" class="mt-0.5 w-full rounded border border-amber-200 bg-white px-2 py-1.5 text-xs">
                    </label>
                    <label class="block">
                        <span class="text-xs text-amber-700 font-medium">Phasen</span>
                        <select id="pwrPhases" class="mt-0.5 w-full rounded border border-amber-200 bg-white px-2 py-1.5 text-xs">
                            <option value="1">1-Phase</option>
                            <option value="3">3-Phasen</option>
                        </select>
                    </label>
                </div>
                <div id="pwrPhaseInfo" class="mt-2 text-xs text-amber-600 hidden"></div>
            </div>
        `;

        const sizeFieldWrapper = sizeField ? sizeField.closest('.pb-6') : null;
        if (sizeFieldWrapper && sizeFieldWrapper.parentNode) {
            sizeFieldWrapper.parentNode.insertBefore(powerFieldsPanel, sizeFieldWrapper.nextSibling);
        }

        // Wire up events
        powerFieldsPanel.querySelectorAll('input, select').forEach(el => {
            el.addEventListener('change', () => { syncConfigField(); });
            el.addEventListener('input', () => { syncConfigField(); });
        });
        powerFieldsPanel.querySelector('#pwrPhases')?.addEventListener('change', updatePhaseInfo);

        if (typeof lucide !== 'undefined') lucide.createIcons({ attrs: { class: ['lucide-icon'] }, nameAttr: 'data-lucide' });

        return powerFieldsPanel;
    };

    const updatePowerPanelVisibility = () => {
        const deviceType = typeField ? typeField.value : '';
        const isPowerDevice = ['ups', 'pdu'].includes(deviceType);
        ensurePowerPanel();
        if (powerFieldsPanel) {
            powerFieldsPanel.classList.toggle('hidden', !isPowerDevice);
        }
        if (isPowerDevice) {
            loadPowerFieldsFromSize();
        }
    };

    const typeOptions = Object.entries(getPortTypeDefinitions()).map(([code, def]) => {
        return `<option value="${code}">${def.label}</option>`;
    }).join('');

    const numberingOptions = `<option value="column-first">Column-first (Switch)</option><option value="row-first">Row-first</option>`;

    const sideOptions = `<option value="front">Front</option><option value="rear">Rear</option>`;

    const ensureBuilder = () => {
        if (builderContainer) return builderContainer;

        // Remove any existing builder from a previous call (e.g. template apply)
        const existing = document.getElementById('portLayoutBuilder');
        if (existing) existing.remove();

        builderContainer = document.createElement('div');
        builderContainer.id = 'portLayoutBuilder';
        builderContainer.className = 'col-span-2 pb-6';
        builderContainer.innerHTML = `
            <div class="rounded-xl border border-slate-300 bg-slate-50 p-4">
                <div class="mb-1 flex items-center justify-between">
                    <div>
                        <div class="text-lg font-bold text-slate-800">Port Layout Builder</div>
                        <div class="text-xs text-slate-500">Port-Gruppen definieren und visuell anordnen. Maße in mm.</div>
                    </div>
                    <div class="flex gap-2">
                        <select id="plbPresetSelect" class="rounded-full border border-slate-300 px-3 py-1.5 text-xs" title="Preset laden">
                            ${Object.entries(getDevicePresetDefinitions()).map(([k, v]) => `<option value="${k}">${v.label}</option>`).join('')}
                        </select>
                        <button type="button" id="plbApplyPreset" class="h-8 w-8 rounded-full bg-blue-500 hover:bg-blue-700 text-white flex items-center justify-center" title="Preset anwenden"><i data-lucide="download"></i></button>
                        <button type="button" id="plbAddGroup" class="h-8 w-8 rounded-full bg-green-500 hover:bg-green-700 text-white flex items-center justify-center" title="Gruppe hinzufügen"><i data-lucide="plus"></i></button>
                    </div>
                </div>
                <div id="plbGroupList" class="mt-3 space-y-2"></div>
                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                    <div class="rounded-lg border border-slate-300 bg-slate-900 p-3">
                        <div class="mb-1 text-xs font-semibold text-slate-400">Front</div>
                        <div id="plbCanvasFront" class="relative overflow-hidden rounded border border-slate-700 bg-slate-950" style="min-height:60px"></div>
                    </div>
                    <div class="rounded-lg border border-slate-300 bg-slate-900 p-3">
                        <div class="mb-1 text-xs font-semibold text-slate-400">Rear</div>
                        <div id="plbCanvasRear" class="relative overflow-hidden rounded border border-slate-700 bg-slate-950" style="min-height:60px"></div>
                    </div>
                </div>
                <div id="plbStats" class="mt-2 text-xs text-slate-500"></div>
            </div>
        `;

        const sizeFieldWrapper = sizeField ? sizeField.closest('.pb-6') : null;
        if (sizeFieldWrapper && sizeFieldWrapper.parentNode) {
            sizeFieldWrapper.parentNode.insertBefore(builderContainer, sizeFieldWrapper.nextSibling);
        } else {
            const deviceForm = document.getElementById('device');
            if (deviceForm) {
                const grid = deviceForm.querySelector('.grid');
                if (grid) grid.appendChild(builderContainer);
            }
        }

        // Events
        builderContainer.querySelector('#plbApplyPreset').addEventListener('click', applyPreset);
        builderContainer.querySelector('#plbAddGroup').addEventListener('click', () => {
            portGroups.push({ typeCode: 10, count: 1, rows: 1, startLabel: 'Port1', labelPattern: '{prefix}{index}', side: 'front', offsetX: 10, offsetY: 10, gapX: 2, gapY: 2, numbering: 'column-first' });
            renderGroupList();
            renderCanvas();
        });

        if (window.lucide) window.lucide.createIcons({ nodes: [builderContainer] });

        return builderContainer;
    };

    const applyPreset = () => {
        const select = builderContainer.querySelector('#plbPresetSelect');
        const presetKey = select ? select.value : 'none';
        const presets = getDevicePresetDefinitions();
        const preset = presets[presetKey];
        if (!preset || !preset.groups || preset.groups.length === 0) return;

        portGroups = JSON.parse(JSON.stringify(preset.groups));

        if (preset.deviceWidth && preset.deviceHeight && sizeField) {
            const parsed = parseJsonObjectOrDefault(sizeField.value || '{}', {});
            parsed.x = preset.deviceWidth;
            parsed.y = preset.deviceHeight;
            if (!parsed.z) parsed.z = 300;
            sizeField.value = JSON.stringify(parsed);
        }

        renderGroupList();
        renderCanvas();
    };

    const renderGroupList = () => {
        const list = builderContainer.querySelector('#plbGroupList');
        if (!list) return;
        list.innerHTML = '';

        portGroups.forEach((group, idx) => {
            const typeDefs = getPortTypeDefinitions();
            const typeLabel = (typeDefs[group.typeCode] || {}).label || 'Unknown';
            const color = getPortLayoutPreviewColor(group.typeCode);
            const portSize = getPortTypeSizeMm(group.typeCode);

            const row = document.createElement('div');
            row.className = 'rounded-lg border border-slate-200 bg-white p-3 text-xs';
            row.setAttribute('data-group-index', idx);
            row.innerHTML = `
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-3 w-3 rounded-sm" style="background:${color}"></span>
                        <span class="font-bold text-slate-700">Gruppe ${idx + 1}: ${typeLabel}</span>
                        <span class="text-slate-400">(${portSize.w}×${portSize.h} mm)</span>
                    </div>
                    <div class="flex gap-1">
                        ${idx > 0 ? `<button type="button" data-action="move-up" data-idx="${idx}" class="h-6 w-6 rounded bg-slate-100 hover:bg-slate-200 flex items-center justify-center" title="Nach oben"><i data-lucide="chevron-up" class="w-3 h-3"></i></button>` : ''}
                        ${idx < portGroups.length - 1 ? `<button type="button" data-action="move-down" data-idx="${idx}" class="h-6 w-6 rounded bg-slate-100 hover:bg-slate-200 flex items-center justify-center" title="Nach unten"><i data-lucide="chevron-down" class="w-3 h-3"></i></button>` : ''}
                        <button type="button" data-action="remove" data-idx="${idx}" class="h-6 w-6 rounded bg-red-100 hover:bg-red-200 text-red-600 flex items-center justify-center" title="Entfernen"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2">
                    <label class="block"><span class="text-slate-500">Typ</span>
                        <select data-field="typeCode" data-idx="${idx}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs">${typeOptions}</select></label>
                    <label class="block"><span class="text-slate-500">Anzahl</span>
                        <input type="number" data-field="count" data-idx="${idx}" min="1" max="200" value="${group.count}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs"></label>
                    <label class="block"><span class="text-slate-500">Reihen</span>
                        <input type="number" data-field="rows" data-idx="${idx}" min="1" max="10" value="${group.rows}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs"></label>
                    <label class="block"><span class="text-slate-500">Start-Label</span>
                        <input type="text" data-field="startLabel" data-idx="${idx}" value="${group.startLabel}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs"></label>
                    <label class="block"><span class="text-slate-500">Label-Pattern</span>
                        <input type="text" data-field="labelPattern" data-idx="${idx}" value="${group.labelPattern || '{prefix}{index}'}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs"></label>
                    <label class="block"><span class="text-slate-500">Seite</span>
                        <select data-field="side" data-idx="${idx}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs">${sideOptions}</select></label>
                    <label class="block"><span class="text-slate-500">Offset X (mm)</span>
                        <input type="number" data-field="offsetX" data-idx="${idx}" min="0" step="1" value="${group.offsetX}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs"></label>
                    <label class="block"><span class="text-slate-500">Offset Y (mm)</span>
                        <input type="number" data-field="offsetY" data-idx="${idx}" min="0" step="1" value="${group.offsetY}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs"></label>
                    <label class="block"><span class="text-slate-500">Gap X (mm)</span>
                        <input type="number" data-field="gapX" data-idx="${idx}" min="0" step="0.5" value="${group.gapX}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs"></label>
                    <label class="block"><span class="text-slate-500">Gap Y (mm)</span>
                        <input type="number" data-field="gapY" data-idx="${idx}" min="0" step="0.5" value="${group.gapY}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs"></label>
                    <label class="block"><span class="text-slate-500">Nummerierung</span>
                        <select data-field="numbering" data-idx="${idx}" class="mt-0.5 w-full rounded border border-slate-200 px-1.5 py-1 text-xs">${numberingOptions}</select></label>
                </div>
            `;

            // Set select values after DOM creation
            list.appendChild(row);
            const typeSelect = row.querySelector(`[data-field="typeCode"][data-idx="${idx}"]`);
            if (typeSelect) typeSelect.value = String(group.typeCode);
            const sideSelect = row.querySelector(`[data-field="side"][data-idx="${idx}"]`);
            if (sideSelect) sideSelect.value = group.side || 'front';
            const numSelect = row.querySelector(`[data-field="numbering"][data-idx="${idx}"]`);
            if (numSelect) numSelect.value = group.numbering || 'column-first';
        });

        // Attach events
        list.querySelectorAll('[data-action="remove"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.idx, 10);
                portGroups.splice(idx, 1);
                renderGroupList();
                renderCanvas();
            });
        });

        list.querySelectorAll('[data-action="move-up"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.idx, 10);
                if (idx > 0) { [portGroups[idx - 1], portGroups[idx]] = [portGroups[idx], portGroups[idx - 1]]; }
                renderGroupList();
                renderCanvas();
            });
        });

        list.querySelectorAll('[data-action="move-down"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.idx, 10);
                if (idx < portGroups.length - 1) { [portGroups[idx + 1], portGroups[idx]] = [portGroups[idx], portGroups[idx + 1]]; }
                renderGroupList();
                renderCanvas();
            });
        });

        list.querySelectorAll('[data-field]').forEach(field => {
            const handler = () => {
                const idx = parseInt(field.dataset.idx, 10);
                const key = field.dataset.field;
                if (!portGroups[idx]) return;
                const numFields = ['count', 'rows', 'offsetX', 'offsetY', 'gapX', 'gapY', 'typeCode'];
                portGroups[idx][key] = numFields.includes(key) ? parseFloat(field.value) || 0 : field.value;
                renderCanvas();
                syncConfigField();
            };
            field.addEventListener('input', handler);
            field.addEventListener('change', handler);
        });

        if (window.lucide) window.lucide.createIcons({ nodes: [list] });
        syncConfigField();
    };

    // --- 2D Canvas Rendering ---
    const renderCanvas = () => {
        const dim = getDeviceDimensions();
        const frontCanvas = builderContainer.querySelector('#plbCanvasFront');
        const rearCanvas = builderContainer.querySelector('#plbCanvasRear');
        if (!frontCanvas || !rearCanvas) return;

        const allPorts = computeAllPortPositions(portGroups);
        const frontPorts = allPorts.filter(p => p.side === 'front');
        const rearPorts = allPorts.filter(p => p.side === 'rear');

        renderSide(frontCanvas, frontPorts, dim, 'front');
        renderSide(rearCanvas, rearPorts, dim, 'rear');

        // Stats
        const stats = builderContainer.querySelector('#plbStats');
        if (stats) {
            const totalPorts = allPorts.length;
            const byType = {};
            allPorts.forEach(p => {
                const def = getPortTypeDefinitions()[p.typeCode] || {};
                const l = def.label || 'Unknown';
                byType[l] = (byType[l] || 0) + 1;
            });
            const typeInfo = Object.entries(byType).map(([l, c]) => `${c}× ${l}`).join(', ');
            stats.textContent = `Gesamt: ${totalPorts} Ports` + (typeInfo ? ` (${typeInfo})` : '') + ` | Gerät: ${dim.w}×${dim.h} mm`;
        }
    };

    const renderSide = (container, ports, dim, sideName) => {
        // Use parent width for scale calculation before clearing content
        const parentWidth = container.parentElement ? container.parentElement.clientWidth - 24 : 400;
        container.innerHTML = '';
        const scale = Math.min(parentWidth / dim.w, 200 / dim.h, 6);
        const canvasW = dim.w * scale;
        const canvasH = dim.h * scale;
        container.style.width = canvasW + 'px';
        container.style.height = canvasH + 'px';
        container.style.position = 'relative';
        const isRear = sideName === 'rear';

        // Device outline
        const outline = document.createElement('div');
        outline.className = 'absolute inset-0 rounded-sm border border-slate-600';
        outline.style.background = 'linear-gradient(180deg, rgba(71,85,105,0.15) 0%, rgba(51,65,85,0.08) 100%)';
        container.appendChild(outline);

        // Dimension labels
        const wLabel = document.createElement('div');
        wLabel.className = 'absolute text-[9px] text-slate-500 select-none';
        wLabel.style.cssText = `bottom:-14px;left:50%;transform:translateX(-50%)`;
        wLabel.textContent = `${dim.w} mm`;
        container.appendChild(wLabel);

        const hLabel = document.createElement('div');
        hLabel.className = 'absolute text-[9px] text-slate-500 select-none';
        hLabel.style.cssText = `right:-28px;top:50%;transform:translateY(-50%) rotate(90deg)`;
        hLabel.textContent = `${dim.h} mm`;
        container.appendChild(hLabel);

        // Group drag state
        let dragState = null;

        ports.forEach((port, portIdx) => {
            const color = getPortLayoutPreviewColor(port.typeCode);
            const el = document.createElement('div');
            el.className = 'absolute flex items-center justify-center text-[7px] font-bold leading-none select-none';
            const drawX = isRear ? (dim.w - port.x - port.w) * scale : port.x * scale;
            el.style.cssText = `
                left:${drawX}px; top:${port.y * scale}px;
                width:${port.w * scale}px; height:${port.h * scale}px;
                background:${color}33; border:1px solid ${color}; color:${color};
                border-radius:2px; cursor:grab; box-sizing:border-box;
            `;
            el.title = `${port.label} | Gruppe ${port.groupIndex + 1} | ${port.side} | ${port.x.toFixed(1)}, ${port.y.toFixed(1)} mm`;

            // Show short label
            const labelText = port.label.replace(/^.*?(\d+)$/, '$1') || port.label;
            if (port.w * scale > 10 && port.h * scale > 8) {
                el.textContent = labelText.length > 4 ? labelText.slice(-3) : labelText;
            }

            // Drag & Drop for whole group
            el.addEventListener('mousedown', (e) => {
                e.preventDefault();
                const gi = port.groupIndex;
                dragState = { groupIndex: gi, startMouseX: e.clientX, startMouseY: e.clientY, startOffsetX: portGroups[gi].offsetX, startOffsetY: portGroups[gi].offsetY };
                document.body.style.cursor = 'grabbing';
            });

            container.appendChild(el);
        });

        const onMouseMove = (e) => {
            if (!dragState) return;
            const dx = (e.clientX - dragState.startMouseX) / scale;
            const dy = (e.clientY - dragState.startMouseY) / scale;
            const gi = dragState.groupIndex;
            if (!portGroups[gi]) return;
            portGroups[gi].offsetX = Math.max(0, Math.round(dragState.startOffsetX + dx));
            portGroups[gi].offsetY = Math.max(0, Math.round(dragState.startOffsetY + dy));
            renderCanvas();
        };

        const onMouseUp = () => {
            if (!dragState) return;
            dragState = null;
            document.body.style.cursor = '';
            // Update input fields
            renderGroupList();
        };

        container.addEventListener('mousemove', onMouseMove);
        container.addEventListener('mouseleave', onMouseUp);
        document.addEventListener('mouseup', onMouseUp);
    };

    // --- Template sync ---
    const syncTemplatePortRules = () => {
        // Builder stays visible for templates so the layout can be configured
    };

    // --- Init ---
    const initBuilder = () => {
        ensureBuilder();

        // Load existing config from size field's portLayout property
        if (sizeField && sizeField.value) {
            const parsed = parseJsonObjectOrDefault(sizeField.value, {});
            if (parsed.portLayout && Array.isArray(parsed.portLayout.groups)) {
                portGroups = parsed.portLayout.groups;
            }
        }

        renderGroupList();
        renderCanvas();

        // Edit mode: if size has no portLayout but the device already has ports
        // in the database (legacy devices created before portLayout was persisted),
        // reconstruct portGroups from the existing device_port rows so the builder
        // shows the actual layout and the device can be turned into a template.
        if (portGroups.length === 0
            && itamFormState && itamFormState.mode === 'edit'
            && itamFormState.uuids && itamFormState.uuids.device) {
            reconstructGroupsFromExistingPorts(itamFormState.uuids.device);
        }
    };

    const reconstructGroupsFromExistingPorts = async (deviceUuid) => {
        try {
            const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/device_port_details?limit=5000');
            const payload = await response.json();
            const rows = (payload && payload.items) ? payload.items : [];
            const ports = rows
                .filter(r => String(r.device_port_device || r.device || '') === String(deviceUuid))
                .map(r => {
                    const positionRaw = r.device_port_position || r.position || '{}';
                    const position = parseJsonObjectOrDefault(positionRaw, {});
                    return {
                        typeCode: parseInt(r.device_port_type || r.type || 0, 10) || 0,
                        label: String(r.device_port_metadata_caption || r.metadata_caption || r.caption || '').trim(),
                        side: String(position.side || 'front'),
                        x: parseFloat(position.x) || 0,
                        y: parseFloat(position.y) || 0
                    };
                });

            if (ports.length === 0) return;

            // Sort by side, then by y (rows top→bottom), then by x (columns left→right)
            ports.sort((a, b) => {
                if (a.side !== b.side) return a.side < b.side ? -1 : 1;
                if (Math.abs(a.y - b.y) > 0.5) return a.y - b.y;
                return a.x - b.x;
            });

            // Group consecutive ports sharing typeCode + side.
            const groups = [];
            let current = null;
            ports.forEach(p => {
                if (!current || current.typeCode !== p.typeCode || current.side !== p.side) {
                    current = {
                        typeCode: p.typeCode,
                        side: p.side,
                        startLabel: p.label || '1',
                        labelPattern: '{prefix}{index}',
                        offsetX: p.x,
                        offsetY: p.y,
                        gapX: 2,
                        gapY: 2,
                        rows: 1,
                        numbering: 'row-first',
                        _ys: [p.y],
                        count: 0
                    };
                    groups.push(current);
                }
                current.count += 1;
                if (!current._ys.includes(p.y)) current._ys.push(p.y);
            });

            // Estimate rows from distinct y-positions (round to 1mm to ignore noise).
            groups.forEach(g => {
                const distinctRows = new Set(g._ys.map(y => Math.round(y))).size;
                if (distinctRows > 1) g.rows = distinctRows;
                delete g._ys;
            });

            portGroups = groups;
            // Persist into size so the user can save the device as template.
            syncConfigField();
            renderGroupList();
            renderCanvas();
        } catch (err) {
            console.warn('Port-Layout konnte nicht aus vorhandenen Ports rekonstruiert werden:', err);
        }
    };

    if (typeField) {
        typeField.addEventListener('change', () => { renderCanvas(); updatePowerPanelVisibility(); });
    }

    // External writes to sizeField (e.g. template apply, edit-mode population)
    // must repopulate the builder. syncConfigField sets writingFromBuilder to skip.
    const reloadFromSize = () => {
        if (writingFromBuilder) {
            renderCanvas();
            return;
        }
        if (!sizeField) return;
        const parsed = parseJsonObjectOrDefault(sizeField.value || '{}', {});
        if (parsed.portLayout && Array.isArray(parsed.portLayout.groups)) {
            portGroups = parsed.portLayout.groups;
            renderGroupList();
        }
        renderCanvas();
    };

    if (sizeField) {
        sizeField.addEventListener('input', reloadFromSize);
        sizeField.addEventListener('change', reloadFromSize);
    }

    if (templateField) {
        templateField.addEventListener('change', syncTemplatePortRules);
        templateField.addEventListener('input', syncTemplatePortRules);
    }

    initBuilder();
    updatePowerPanelVisibility();
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
    helper.className = 'itam-item-group-helper mt-2 grid hidden gap-2 rounded-xl border border-slate-300 bg-slate-50 p-3';

    const info = document.createElement('small');
    info.textContent = 'Fuer Switch-Stacks: vorhandene Group waehlen oder neue UUID erzeugen.';

    const controls = document.createElement('div');
    controls.className = 'flex flex-wrap gap-2';

    const select = document.createElement('select');
    select.className = 'rounded-full border border-slate-300 bg-white px-3 py-1.5';
    select.innerHTML = '<option value="">Vorhandene Item Group waehlen ...</option>';

    const generateButton = document.createElement('button');
    generateButton.type = 'button';
    generateButton.className = 'rounded-full border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700';
    generateButton.textContent = 'Neue UUID erzeugen';

    const refreshButton = document.createElement('button');
    refreshButton.type = 'button';
    refreshButton.className = 'rounded-full border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700';
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

async function createAutoPortsForDevice(deviceUuid, groups = [], onProgress = null) {
    if (!deviceUuid || !Array.isArray(groups) || groups.length === 0) return;

    const allPorts = computeAllPortPositions(groups);
    const total = allPorts.length;
    if (total === 0) return;

    if (typeof onProgress === 'function') {
        onProgress({ current: 0, total, label: 'Auto-Ports werden erstellt ...' });
    }

    for (let i = 0; i < total; i++) {
        const port = allPorts[i];
        const portSize = getPortTypeSizeMm(port.typeCode);
        const depthMm = 2;

        const metadataPayload = {
            status: '6',
            caption: port.label,
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
            throw new Error(`Metadata für Port ${i + 1} (${port.label}) konnte nicht erstellt werden.`);
        }

        const devicePortResponse = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/device_port/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                metadata: metadataUuid,
                device: deviceUuid,
                type: port.typeCode,
                size: JSON.stringify({ x: portSize.w, y: portSize.h, z: depthMm }),
                position: JSON.stringify({ x: port.x, y: port.y, z: 0, side: port.side }),
                rotation: JSON.stringify({ x: 0, y: 0, z: 0 })
            })
        });
        const devicePortResult = await devicePortResponse.json();

        if (!devicePortResult || !devicePortResult[0] || !devicePortResult[0].uuid) {
            throw new Error(`Device-Port für Port ${i + 1} (${port.label}) konnte nicht erstellt werden.`);
        }

        if (typeof onProgress === 'function') {
            onProgress({ current: i + 1, total, label: `Port ${i + 1} von ${total} erstellt (${port.label})` });
        }
    }
}

// generate form fields
function generateField(name, config) {
    const wrapper = document.createElement('div');
    wrapper.className = 'pb-6 h-fit w-full max-w-lg relative';
    let field;

    if (name.startsWith('expected_') && config.autoMirrorFromSource === true) {
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
    if (config.required) {
        label.textContent += ' *';
    }
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
        case 'hidden':
            wrapper.classList.add('hidden');
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = name;
            wrapper.appendChild(field);
            return wrapper;
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

                    // For location resources: build a parent breadcrumb (Building › Room › Rack)
                    // by walking the parent chain via a one-time loaded location cache.
                    const isLocationResource = config.resource === 'location_details'
                        || config.resource === 'location_join_metadata';
                    const locationCache = isLocationResource ? await getLocationBreadcrumbCache() : null;

                    results.items.forEach(item => {
                        let resourceBase = config.resource.replace(/_details$/, '');
                        let uuidKey = Object.keys(item).find(k => k === resourceBase + '_uuid')
                            || Object.keys(item).find(k => k.endsWith('_uuid'))
                            || 'uuid';
                        let displayLabel = isLocationResource
                            ? buildLocationBreadcrumb(item[uuidKey], locationCache)
                            : formatSearchResultLabel(item, config);

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
    const configResponse = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/includes/forms.json');
    const configData = await configResponse.json();
    const postOrder = configData.forms[table].postOrder;
    const formConfig = configData.forms[table];
    const editMode = itamFormState.mode === 'edit' && itamFormState.table === table;

    // Hilfsfunktion, um die UUIDs in die richtigen Felder einzutragen
    function injectUuids(postData, postConfig) {
        if (postConfig.useMetadataUUID && responseUuids.metadata) {
            postData.metadata = responseUuids.metadata;
        }
        if (postConfig.useIpUUID && responseUuids.device_port_ip) {
            postData.device_port_ip = responseUuids.device_port_ip;
        }
    }

    function hasMeaningfulPostData(postData) {
        return Object.entries(postData || {}).some(([key, value]) => {
            if (key === 'uuid') {
                return false;
            }
            if (value === null || value === undefined) {
                return false;
            }
            if (value === false) {
                return false;
            }
            return String(value).trim() !== '';
        });
    }

    function buildPostDataFromConfig(form, postConfig) {
        const postData = {};
        const fieldList = Array.isArray(postConfig.fields) ? postConfig.fields : [];

        fieldList.forEach((fieldName) => {
            const fieldConfig = (formConfig.fields && formConfig.fields[fieldName]) ? formConfig.fields[fieldName] : {};
            const input = form.querySelector(`[name="${fieldName}"]`);
            if (!input) {
                return;
            }

            if (fieldConfig.type === 'boolean') {
                if (input.checked) {
                    postData[fieldName] = true;
                } else if (editMode) {
                    postData[fieldName] = false;
                }
                return;
            }

            const rawValue = input.value;
            if (rawValue === null || rawValue === undefined) {
                return;
            }

            const value = typeof rawValue === 'string' ? rawValue.trim() : rawValue;
            if (value === '') {
                postData[fieldName] = null;
                return;
            }

            if (fieldConfig.type === 'number') {
                const parsed = Number(value);
                postData[fieldName] = Number.isNaN(parsed) ? null : parsed;
                return;
            }

            postData[fieldName] = value;
        });

        return postData;
    }

    function extractUuidFromApiPayload(payload, rawBody = '') {
        if (!payload) {
            const rawMatch = String(rawBody || '').match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
            return rawMatch ? String(rawMatch[0]) : '';
        }

        if (Array.isArray(payload) && payload[0] && payload[0].uuid) {
            return String(payload[0].uuid);
        }

        if (payload.uuid) {
            return String(payload.uuid);
        }

        if (Array.isArray(payload.items) && payload.items[0] && payload.items[0].uuid) {
            return String(payload.items[0].uuid);
        }

        // Fallback: some responses might include extra output around JSON or use different shapes.
        const rawMatch = String(rawBody || '').match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
        if (rawMatch) {
            return String(rawMatch[0]);
        }

        return '';
    }

    // Reihenfolge gemäß postOrder abarbeiten
    for (const postConfig of postOrder) {
        const form = forms.find(f => f.id === postConfig.table);
        if (!form) continue;

        const postData = buildPostDataFromConfig(form, postConfig);

        if (postConfig.table === 'device') {
            const parsedItemGroup = (postData.item_group || '').trim();

            if (parsedItemGroup && !isValidPostgresUuid(parsedItemGroup)) {
                submitErrorMessage = 'Item Group muss eine gueltige UUID sein.';
                break;
            }

            postData.item_group = parsedItemGroup || null;

            const isTemplateDevice = isTruthyTemplateValue(postData.template);

            // Parse port layout config from size field's portLayout property
            let portLayoutConfig = null;
            const sizeObj = parseJsonObjectOrDefault(postData.size || '{}', {});
            if (sizeObj && sizeObj.portLayout) {
                portLayoutConfig = sizeObj.portLayout;
            }

            if (portLayoutConfig && Array.isArray(portLayoutConfig.groups) && portLayoutConfig.groups.length > 0) {
                autoPortConfig = { groups: portLayoutConfig.groups };
            } else {
                autoPortConfig = { groups: [] };
            }

            if (isTemplateDevice) {
                // Templates: save config in size but don't create ports
                autoPortConfig = { groups: [] };
            }
        }

        // UUIDs aus vorherigen POSTs einfügen, falls benötigt
        injectUuids(postData, postConfig);

        const targetUuid = editMode ? (itamFormState.uuids[postConfig.table] || '') : '';
        let httpMethod = editMode ? 'PATCH' : 'POST';
        let apiUrl = editMode
            ? `<?php echo PORTFLOW_HOSTNAME; ?>/api/${postConfig.table}/${targetUuid}`
            : `<?php echo PORTFLOW_HOSTNAME; ?>/api/${postConfig.table}/`;
        const isOptionalRelationTable = postConfig.table === 'device_port_ip';
        const hasPayloadValues = hasMeaningfulPostData(postData);

        // In edit mode, related rows (e.g. device_port_ip) might not exist yet.
        // Fall back to create for that step instead of aborting the full save.
        if (editMode && !targetUuid) {
            if (isOptionalRelationTable && !hasPayloadValues) {
                responseUuids[postConfig.table] = '';
                continue;
            }
            httpMethod = 'POST';
            apiUrl = `<?php echo PORTFLOW_HOSTNAME; ?>/api/${postConfig.table}/`;
            console.warn(`Keine UUID fuer ${postConfig.table} gefunden, lege Datensatz neu an.`);
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

            const responseUuid = extractUuidFromApiPayload(data, rawBody);
            const usesPatchUpdate = editMode && !!targetUuid && httpMethod === 'PATCH';
            const effectiveUuid = usesPatchUpdate ? String(targetUuid) : responseUuid;

            if (!effectiveUuid) {
                if (isOptionalRelationTable) {
                    console.warn(`Kein UUID aus ${postConfig.table}-Response ermittelbar. Schritt wird als optional behandelt.`, {
                        table: postConfig.table,
                        method: httpMethod,
                        apiUrl,
                        postData,
                        rawBody
                    });
                    responseUuids[postConfig.table] = '';
                    continue;
                }
                throw new Error(`API ${postConfig.table} returned no UUID.`);
            }

            responseUuids[postConfig.table] = effectiveUuid;
            if (postConfig.table === 'metadata') responseUuids.metadata = effectiveUuid;
            if (postConfig.table === 'device_port_ip') responseUuids.device_port_ip = effectiveUuid;

            if (!editMode && postConfig.table === 'device' && autoPortConfig && autoPortConfig.groups && autoPortConfig.groups.length > 0) {
                const totalPorts = computeAllPortPositions(autoPortConfig.groups).length;
                setProgressOverlayState(true);
                updateProgressOverlay('Auto-Ports werden erstellt ...', 0, totalPorts);

                await createAutoPortsForDevice(effectiveUuid, autoPortConfig.groups, (progress) => {
                    updateProgressOverlay(progress.label || 'Auto-Ports werden erstellt ...', progress.current || 0, progress.total || totalPorts);
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

function resolveCurrentTableLabel() {
    const navItem = document.querySelector(`#itam_nav [data-table="${currentTable}"], #itam_nav_mobile [data-table="${currentTable}"]`);
    if (navItem) {
        const label = navItem.querySelector('.itam-nav-label');
        if (label && label.textContent) {
            return label.textContent.trim();
        }
        if (navItem.textContent) {
            return navItem.textContent.trim();
        }
    }
    return currentTable;
}

async function openTransferDialog() {
    try {
        const configData = await getItamFormsConfig();
        const formConfig = getItamFormConfig(configData, currentTable);
        if (!formConfig) {
            alert('<?php echo $lang['transfer_not_available'] ?? 'Import/Export is not available for this view.'; ?>');
            return;
        }

        const schema = getItamImportSchema(formConfig);
        const body = document.getElementById('itamTransferPreviewBody');
        const title = document.getElementById('itamTransferTitle');
        const subtitle = document.getElementById('itamTransferSubtitle');
        if (!body || !title || !subtitle) {
            return;
        }

        title.textContent = `${resolveCurrentTableLabel()} - <?php echo $lang['transfer_csv'] ?? 'CSV Import/Export'; ?>`;
        subtitle.textContent = '<?php echo $lang['transfer_hint'] ?? 'Accepted and required fields for the current view.'; ?>';
        body.innerHTML = '';

        schema.forEach(field => {
            const row = document.createElement('tr');
            row.className = 'border-t border-slate-200 align-top';
            row.innerHTML = `
                <td class="p-2 font-mono text-xs text-slate-900">${escapeHtml(field.name)}</td>
                <td class="p-2">${escapeHtml(field.label)}</td>
                <td class="p-2">${field.required ? '<?php echo $lang['yes'] ?? 'Ja'; ?>' : '<?php echo $lang['no'] ?? 'Nein'; ?>'}</td>
                <td class="p-2">${escapeHtml(field.type)}</td>
                <td class="p-2 text-xs text-slate-500">${escapeHtml(getImportFieldNote(field))}</td>
            `;
            body.appendChild(row);
        });

        currentTransferFile = null;
        const fileInput = document.getElementById('itamTransferFile');
        const fileName = document.getElementById('itamTransferFileName');
        const importBtn = document.getElementById('itamTransferImportBtn');
        if (fileInput) fileInput.value = '';
        if (fileName) fileName.textContent = '<?php echo $lang['transfer_no_file'] ?? 'No file selected'; ?>';
        if (importBtn) importBtn.disabled = true;
        resetTransferStatus();

        const modal = document.getElementById('itamTransferModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons({ nodes: [document.getElementById('itamTransferModal')] });
        }
    } catch (error) {
        console.error('Transfer dialog could not be opened', error);
        alert('<?php echo $lang['transfer_not_available'] ?? 'Import/Export is not available for this view.'; ?>');
    }
}

function closeTransferDialog() {
    const modal = document.getElementById('itamTransferModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function handleTransferFileSelected(inputEl) {
    currentTransferFile = inputEl && inputEl.files ? inputEl.files[0] : null;
    const fileName = document.getElementById('itamTransferFileName');
    const importBtn = document.getElementById('itamTransferImportBtn');
    if (fileName) {
        fileName.textContent = currentTransferFile ? `${currentTransferFile.name} (${Math.round(currentTransferFile.size / 1024)} KB)` : '<?php echo $lang['transfer_no_file'] ?? 'No file selected'; ?>';
    }
    if (importBtn) {
        importBtn.disabled = !currentTransferFile;
    }
}

async function downloadTransferSample() {
    try {
        const configData = await getItamFormsConfig();
        const formConfig = getItamFormConfig(configData, currentTable);
        if (!formConfig) {
            throw new Error('No form config for current table');
        }

        const schema = getItamImportSchema(formConfig);
        const headers = schema.map(field => field.name);
        const sampleRow = {};
        schema.forEach(field => {
            sampleRow[field.name] = getSampleValueForSchemaField(field);
        });

        const csv = buildCsvText(headers, [sampleRow]);
        downloadTextFile(`${currentTable}-sample.csv`, csv, 'text/csv;charset=utf-8;');
        setTransferStatus('<?php echo $lang['transfer_sample_ready'] ?? 'Sample CSV downloaded.'; ?>', 'success');
    } catch (error) {
        setTransferStatus((error && error.message) ? error.message : '<?php echo $lang['columns_save_failed'] ?? 'Saving failed'; ?>', 'error');
    }
}

async function exportCurrentTableCsv() {
    try {
        const configData = await getItamFormsConfig();
        const formConfig = getItamFormConfig(configData, currentTable);
        if (!formConfig) {
            throw new Error('No form config for current table');
        }

        const schema = getItamImportSchema(formConfig);
        const headers = schema.map(field => field.name);
        const pageInfo = (window.__pfTablePageInfo && window.__pfTablePageInfo[currentTable]) || {};
        const totalResults = Math.max(parseInt(pageInfo.totalResults || 0, 10), 0);
        const params = buildTableQueryParams(currentTable, {
            search: getCurrentSearchTerm(),
            limit: totalResults > 0 ? totalResults : null,
            page: 1,
            formConfig
        });

        let rows = (window.__pfTableRows && window.__pfTableRows[currentTable]) || [];
        if (totalResults > rows.length) {
            const response = await fetch(`${'<?php echo PORTFLOW_HOSTNAME; ?>'}/api/${currentTable}?${params.toString()}`);
            if (!response.ok) {
                throw new Error(`Export fetch failed (${response.status})`);
            }
            const payload = await response.json();
            rows = Array.isArray(payload.items) ? payload.items : rows;
        }

        const csvRows = rows.map(row => mapRowToImportRecord(currentTable, formConfig, row, schema));
        const csv = buildCsvText(headers, csvRows);
        downloadTextFile(`${currentTable}-export.csv`, csv, 'text/csv;charset=utf-8;');
        setTransferStatus('<?php echo $lang['transfer_export_ready'] ?? 'CSV export downloaded.'; ?>', 'success');
    } catch (error) {
        setTransferStatus((error && error.message) ? error.message : '<?php echo $lang['columns_save_failed'] ?? 'Saving failed'; ?>', 'error');
    }
}

async function importTransferCsv() {
    if (!currentTransferFile) {
        setTransferStatus('<?php echo $lang['transfer_no_file'] ?? 'No file selected'; ?>', 'error');
        return;
    }

    const importButton = document.getElementById('itamTransferImportBtn');
    if (importButton) {
        importButton.disabled = true;
    }

    let imported = 0;
    let skipped = 0;
    try {
        const configData = await getItamFormsConfig();
        const formConfig = getItamFormConfig(configData, currentTable);
        if (!formConfig) {
            throw new Error('No form config for current table');
        }

        const schema = getItamImportSchema(formConfig);
        const acceptedFields = new Set(schema.map(field => field.name));
        const csvText = await currentTransferFile.text();
        const parsedRows = parseCsvText(csvText);
        if (!Array.isArray(parsedRows) || parsedRows.length === 0) {
            throw new Error('<?php echo $lang['transfer_import_empty'] ?? 'The CSV file is empty.'; ?>');
        }

        const headers = (parsedRows[0] || []).map(header => String(header || '').trim()).filter(Boolean);
        const unknownHeaders = headers.filter(header => !acceptedFields.has(header));
        if (unknownHeaders.length > 0) {
            throw new Error(`<?php echo $lang['transfer_import_unknown'] ?? 'Unknown CSV columns'; ?>: ${unknownHeaders.join(', ')}`);
        }

        const missingRequired = schema.filter(field => field.required && !headers.includes(field.name));
        if (missingRequired.length > 0) {
            throw new Error(`<?php echo $lang['transfer_import_missing_required'] ?? 'Missing required columns'; ?>: ${missingRequired.map(field => field.name).join(', ')}`);
        }

        const rawRecords = parsedRows.slice(1).map(values => {
            const record = {};
            headers.forEach((header, index) => {
                record[header] = values[index] ?? '';
            });
            return record;
        }).filter(record => Object.values(record).some(value => String(value || '').trim() !== ''));

        if (rawRecords.length === 0) {
            throw new Error('<?php echo $lang['transfer_import_empty'] ?? 'The CSV file is empty.'; ?>');
        }

        const existingRows = await fetchAllRowsForTable(currentTable);
        const duplicateIndex = buildCaptionDuplicateIndex(existingRows, formConfig, currentTable);
        const rememberedDuplicateActions = {};

        for (let index = 0; index < rawRecords.length; index += 1) {
            setTransferStatus(`<?php echo $lang['transfer_import_progress'] ?? 'Import row'; ?> ${index + 1} / ${rawRecords.length}`);
            const record = normalizeCsvRecord(rawRecords[index], schema);
            const caption = String(record.caption || '').trim();
            const normalizedCaption = normalizeDuplicateCaption(caption);
            const matches = normalizedCaption ? (duplicateIndex.get(normalizedCaption) || []) : [];

            let action = 'create';
            if (matches.length > 0) {
                if (rememberedDuplicateActions[normalizedCaption]) {
                    action = rememberedDuplicateActions[normalizedCaption];
                } else {
                    const choice = await askDuplicateCaptionAction(caption, matches.length);
                    action = choice.action;
                    if (choice.remember) {
                        rememberedDuplicateActions[normalizedCaption] = action;
                    }
                }
            }

            if (action === 'keep') {
                skipped += 1;
                continue;
            }

            const submitResult = await submitItamRecord(currentTable, record, {
                editMode: action === 'replace' && matches.length > 0,
                uuids: action === 'replace' && matches.length > 0 ? (matches[0].uuids || {}) : {}
            });

            if (normalizedCaption) {
                const entry = buildCaptionDuplicateEntry(caption, submitResult.responseUuids || {}, null);
                if (action === 'replace' && matches.length > 0) {
                    updateCaptionDuplicateIndex(duplicateIndex, caption, entry, 'replace-first');
                } else {
                    updateCaptionDuplicateIndex(duplicateIndex, caption, entry, 'append');
                }
            }

            setProgressOverlayState(false);
            imported += 1;
        }

        setTransferStatus(`<?php echo $lang['transfer_import_done'] ?? 'Import completed'; ?>: ${imported}${skipped > 0 ? ` | <?php echo $lang['transfer_import_skipped'] ?? 'übersprungen'; ?>: ${skipped}` : ''}`, 'success');
        const searchEl = document.querySelector('#searchForm input[name="search"]');
        loadTable(currentTable, searchEl ? searchEl.value : '');
    } catch (error) {
        setProgressOverlayState(false);
        setTransferStatus((error && error.message) ? error.message : '<?php echo $lang['columns_save_failed'] ?? 'Saving failed'; ?>', 'error');
    } finally {
        if (importButton) {
            importButton.disabled = !currentTransferFile;
        }
    }
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

// Set table page size: store in cookie and reload table from page 1.
function setTableLimit(value) {
    const limit = parseInt(value, 10) || 100;
    document.cookie = 'table_limit=' + limit + '; path=/; max-age=' + (60 * 60 * 24 * 365);
    loadTable(currentTable, getCurrentSearchTerm(), limit, 1);
}

// Load table data
function loadTable(table = 'location_details', search = '', limit = null, page = 1) {
    currentTable = table;
    const configUrl = `${'<?php echo PORTFLOW_HOSTNAME; ?>'}/includes/lang.php?nav`;

    // Resolve effective limit: explicit arg > select > cookie > 100.
    if (limit === null || limit === undefined || limit === '') {
        const sel = document.getElementById('table_limit_1');
        if (sel && sel.value) {
            limit = parseInt(sel.value, 10);
        }
    }
    if (!limit) {
        const m = document.cookie.match(/(?:^|; )table_limit=([^;]+)/);
        limit = m ? parseInt(m[1], 10) : 100;
    }

    // Tabellenhervorhebung aktualisieren
    updateActiveTab(table);

    const searchInput = document.querySelector('#searchForm input[name="search"]');
    if (searchInput && searchInput.value !== search) {
        searchInput.value = search;
    }

    // Close Details Popup
    closeDetailsPopup();

    ajaxGet(configUrl, config => {
        currentNavConfig = config || {};
        let { columns, default: defaultColumns } = config[table];
        // Make the picker-allowed columns available to loadUserColumns so it
        // can defensively strip any blocked entries from a stored preference.
        if (!window.__pfTableAllowed) window.__pfTableAllowed = {};
        const pickerCols = (config[table] && Array.isArray(config[table].picker_columns)) ? config[table].picker_columns : Object.keys(columns || {});
        window.__pfTableAllowed[table] = pickerCols;
        let userColumns = loadUserColumns(table, defaultColumns);
        // Remember the active selection for the column picker.
        window.__pfTableUserColumns = window.__pfTableUserColumns || {};
        window.__pfTableUserColumns[table] = userColumns;

        const requestTableData = (formConfig) => {
            renderTableFilters(table, formConfig);
            const params = buildTableQueryParams(table, { search, limit, page, formConfig });
            const query = params.toString() ? ('?' + params.toString()) : '';

            ajaxGet(`${'<?php echo PORTFLOW_HOSTNAME; ?>'}/api/${table}` + query, data => {
                window.__pfTablePageInfo = window.__pfTablePageInfo || {};
                window.__pfTablePageInfo[table] = data.pageInfo || {};
                window.__pfTableRows = window.__pfTableRows || {};
                window.__pfTableRows[table] = Array.isArray(data.items) ? data.items : [];
                $('#count').text('<?php echo $lang['datasets']; ?>: ' + parseInt(data.pageInfo.totalResults));
                displayTable(columns, userColumns, data.items);
                generatePagination(Math.ceil(data.pageInfo.totalResults / data.pageInfo.resultsPerPage), data.pageInfo.currentPage, search, limit);
            });
        };

        getItamFormsConfig()
            .then(configData => requestTableData(getItamFormConfig(configData, table)))
            .catch(error => {
                console.warn('ITAM filter config could not be loaded', error);
                requestTableData(null);
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
            item.classList.remove('border-blue-600', 'bg-blue-600', 'text-white');
            item.classList.add('border-slate-300', 'bg-white', 'text-slate-800');
        });
    });

    navScopes.forEach(selector => {
        const selectedItem = document.querySelector(`${selector}[data-table="${table}"]`);
        if (selectedItem) {
            selectedItem.classList.remove('border-slate-300', 'bg-white', 'text-slate-800');
            selectedItem.classList.add('border-blue-600', 'bg-blue-600', 'text-white');
        }
    });
}

function displayTable(columnsConfig, userColumns, rows) {
    var $tableHead = $('.static thead').empty();
    var $tableBody = $('.static tbody').empty();

    // Standard Farben für Status
    const STATUS_COLORS = {
        0: '#22c55e',  // Aktiv - Grün
        2: '#ef4444',  // Deaktiviert - Rot
        4: '#eab308',  // Offline - Gelb
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
    const sortState = getTableSort(currentTable) || { col: null, dir: null };
    let trHead = $('<tr class="border-b bg-gray-200">');
    userColumns.forEach(colKey => {
        const isActive = sortState.col === colKey;
        const arrow = isActive
            ? (sortState.dir === 'asc' ? '<i data-lucide="arrow-up" class="inline-block h-4 w-4 ml-1 align-middle"></i>'
                                       : '<i data-lucide="arrow-down" class="inline-block h-4 w-4 ml-1 align-middle"></i>')
            : '<i data-lucide="chevrons-up-down" class="inline-block h-4 w-4 ml-1 align-middle text-slate-400"></i>';
        const $th = $('<th class="p-2 cursor-pointer select-none hover:bg-gray-300" data-col-key="' + colKey + '"></th>')
            .html('<span>' + $('<div>').text(columnsConfig[colKey] || colKey).html() + '</span>' + arrow)
            .on('click', function () { toggleColumnSort(colKey); });
        trHead.append($th);
    });
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
        return `<span class="h-10 w-10 rounded-full flex items-center justify-center" title="${statusTitle}">
                    <i data-lucide="${iconName}" style="color:${color};vertical-align:middle"></i>
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
                        `${row.device_port_device_metadata_caption || '--'} <br> <span class="text-xs text-gray-500">${row.device_port_metadata_caption || '--'}</span>`
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

// Toggle per-table sort state and reload. Cycle: none → asc → desc → none.
function getTableSort(table) {
    if (!window.__pfTableSort) {
        // Hydrate from sessionStorage so the sort state survives navigation
        // within the page (search input, pagination, table picker, etc.).
        try {
            const raw = sessionStorage.getItem('pf_table_sort');
            window.__pfTableSort = raw ? (JSON.parse(raw) || {}) : {};
        } catch (e) {
            window.__pfTableSort = {};
        }
    }
    return window.__pfTableSort[table] || null;
}

function setTableSort(table, sort) {
    if (!window.__pfTableSort) window.__pfTableSort = {};
    if (sort && sort.col && sort.dir) {
        window.__pfTableSort[table] = { col: sort.col, dir: sort.dir };
    } else {
        delete window.__pfTableSort[table];
    }
    try { sessionStorage.setItem('pf_table_sort', JSON.stringify(window.__pfTableSort)); } catch (e) {}
}

function toggleColumnSort(colKey) {
    if (!currentTable || !colKey) return;
    const cur = getTableSort(currentTable) || { col: null, dir: null };
    let next;
    if (cur.col !== colKey) {
        next = { col: colKey, dir: 'asc' };
    } else if (cur.dir === 'asc') {
        next = { col: colKey, dir: 'desc' };
    } else {
        next = { col: null, dir: null };
    }
    setTableSort(currentTable, next);
    // Reload first page so the new ordering applies across the dataset.
    const searchEl = document.querySelector('#searchForm input[name="search"]');
    const search = searchEl ? searchEl.value : '';
    loadTable(currentTable, search, null, 1);
}

// Load user column preferences
function loadUserColumns(table, defaultColumns) {
    const allowed = (window.__pfTableAllowed && window.__pfTableAllowed[table]) || null;
    const allowedSet = allowed ? new Set(allowed) : null;
    const sanitize = (cols) => {
        if (!allowedSet) return cols.slice();
        const filtered = cols.filter(c => allowedSet.has(c));
        return filtered.length ? filtered : defaultColumns.slice();
    };

    // 1. Live cache wins — it reflects the most recent save in this session.
    const live = window.__pfTableUserColumns && window.__pfTableUserColumns[table];
    if (Array.isArray(live) && live.length > 0) {
        return sanitize(live);
    }

    // 1b. If the user explicitly reset this table in this session, skip the
    // page-load snapshot and use defaults — otherwise the stale snapshot
    // would re-apply the previous selection until the next page reload.
    if (window.__pfTableUserColumnsReset && window.__pfTableUserColumnsReset[table]) {
        return defaultColumns.slice();
    }

    // 2. Fall back to the snapshot embedded at page load.
    const userSettings = <?php
        // $_SESSION['settings'] is stored as a JSON string by saveUserSettings();
        // decode it once on the server so the JS gets a real object.
        $__pf_raw = $_SESSION['settings'] ?? null;
        $__pf_settings = is_array($__pf_raw)
            ? $__pf_raw
            : (is_string($__pf_raw) && $__pf_raw !== '' ? (json_decode($__pf_raw, true) ?: []) : []);
        echo json_encode($__pf_settings);
    ?>;
    const stored = userSettings && userSettings.tables && userSettings.tables[table];
    if (!Array.isArray(stored) || stored.length === 0) {
        return defaultColumns.slice();
    }
    return sanitize(stored);
}

// ============================================================
// Column picker — lets the user choose which columns of the
// current itam table should be visible. Persists per-user via
// POST /api/user_table_columns and stores the result in the
// user profile (users.settings JSON).
// ============================================================

function openColumnPicker() {
    const table = currentTable;
    const cfg = currentNavConfig && currentNavConfig[table];
    if (!cfg || !cfg.columns) {
        alert('<?php echo $lang['columns_not_available'] ?? 'Spaltenkonfiguration nicht verfuegbar.'; ?>');
        return;
    }
    const allowed = Array.isArray(cfg.picker_columns) && cfg.picker_columns.length
        ? cfg.picker_columns
        : Object.keys(cfg.columns);
    const labels  = cfg.columns;
    const current = (window.__pfTableUserColumns && window.__pfTableUserColumns[table]) || cfg.default || [];

    // Order: selected columns first (in their stored order), then the
    // remaining allowed columns in their config order.
    const selectedSet = new Set(current);
    const ordered = current.filter(k => allowed.includes(k))
        .concat(allowed.filter(k => !selectedSet.has(k)));

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
                        <div class="text-sm font-bold text-slate-900"><?php echo $lang['columns_customize'] ?? 'Spalten anpassen'; ?></div>
                        <div class="text-xs text-slate-500">${escapeHtml(table)}</div>
                    </div>
                </div>
                <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-300 text-slate-800 hover:bg-slate-400" onclick="closeColumnPicker()" aria-label="Schliessen"><i data-lucide="x" class="h-4 w-4"></i></button>
            </div>
            <div class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-600"><?php echo $lang['columns_hint'] ?? 'Aktivieren oder deaktivieren Sie Spalten und ziehen Sie sie zum Sortieren.'; ?></div>
            <div id="pf-column-picker-list" class="flex-1 overflow-auto p-3 space-y-1"></div>
            <div class="flex items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                <button type="button" onclick="resetColumnPicker()" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100"><i data-lucide="rotate-ccw" class="mr-1 inline-block h-3.5 w-3.5"></i><?php echo $lang['columns_reset'] ?? 'Auf Standard zuruecksetzen'; ?></button>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="closeColumnPicker()" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100"><?php echo $lang['cancel'] ?? 'Abbrechen'; ?></button>
                    <button type="button" onclick="saveColumnPicker()" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700"><?php echo $lang['save'] ?? 'Speichern'; ?></button>
                </div>
            </div>
        </div>
    `;
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    const list = document.getElementById('pf-column-picker-list');
    list.innerHTML = '';
    ordered.forEach(key => list.appendChild(buildColumnPickerRow(key, labels[key] || key, selectedSet.has(key))));

    if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
}

function buildColumnPickerRow(key, label, checked) {
    const row = document.createElement('div');
    row.className = 'pf-col-row flex items-center gap-2 rounded-md border border-slate-200 bg-white px-2 py-1.5 hover:bg-slate-50';
    row.draggable = true;
    row.dataset.colKey = key;
    row.innerHTML = `
        <span class="cursor-grab text-slate-400 hover:text-slate-600" title="ziehen"><i data-lucide="grip-vertical" class="h-4 w-4"></i></span>
        <input type="checkbox" class="pf-col-check h-4 w-4 rounded border-slate-300" ${checked ? 'checked' : ''} />
        <code class="text-[10px] text-slate-400">${escapeHtml(key)}</code>
        <span class="ml-1 flex-1 text-sm text-slate-800">${escapeHtml(label)}</span>
    `;
    // HTML5 drag & drop reordering
    row.addEventListener('dragstart', (ev) => {
        row.classList.add('opacity-50');
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', key);
    });
    row.addEventListener('dragend', () => row.classList.remove('opacity-50'));
    row.addEventListener('dragover', (ev) => { ev.preventDefault(); ev.dataTransfer.dropEffect = 'move'; });
    row.addEventListener('drop', (ev) => {
        ev.preventDefault();
        const srcKey = ev.dataTransfer.getData('text/plain');
        if (!srcKey || srcKey === key) return;
        const list = row.parentElement;
        const srcEl = list.querySelector('.pf-col-row[data-col-key="' + CSS.escape(srcKey) + '"]');
        if (!srcEl) return;
        const rect = row.getBoundingClientRect();
        const before = (ev.clientY - rect.top) < (rect.height / 2);
        list.insertBefore(srcEl, before ? row : row.nextSibling);
    });
    return row;
}

function closeColumnPicker() {
    const m = document.getElementById('pf-column-picker');
    if (m) { m.classList.add('hidden'); m.classList.remove('flex'); }
}

async function saveColumnPicker() {
    const table = currentTable;
    const list = document.getElementById('pf-column-picker-list');
    if (!list) return closeColumnPicker();
    const picked = [];
    list.querySelectorAll('.pf-col-row').forEach(row => {
        const cb = row.querySelector('.pf-col-check');
        if (cb && cb.checked) picked.push(row.dataset.colKey);
    });
    await persistColumnPicker(table, picked);
}

async function resetColumnPicker() {
    const table = currentTable;
    await persistColumnPicker(table, null);
}

async function persistColumnPicker(table, columns) {
    try {
        const r = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/user_table_columns', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ table: table, columns: columns })
        });
        const j = await r.json();
        if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
        // Update local cache + re-render the table without a full reload.
        if (!window.__pfTableUserColumns) window.__pfTableUserColumns = {};
        if (!window.__pfTableUserColumnsReset) window.__pfTableUserColumnsReset = {};
        if (Array.isArray(j.columns) && j.columns.length) {
            window.__pfTableUserColumns[table] = j.columns;
            delete window.__pfTableUserColumnsReset[table];
        } else {
            // Reset → drop the cache and remember the reset so loadUserColumns
            // ignores the stale page-load snapshot and falls back to defaults.
            delete window.__pfTableUserColumns[table];
            window.__pfTableUserColumnsReset[table] = true;
        }
        closeColumnPicker();
        loadTable(table);
    } catch (e) {
        alert('<?php echo $lang['columns_save_failed'] ?? 'Speichern fehlgeschlagen'; ?>: ' + (e.message || e));
    }
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
    // reload table
    loadTable(currentTable);
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

function formatSpecificationValueForDetails(value) {
    const specification = normalizeSpecificationObject(value);
    const entries = Object.entries(specification).filter(([key, val]) => String(key || '').trim() && String(val || '').trim());

    if (entries.length === 0) {
        const plain = String(value || '').trim();
        return plain ? `<span class="whitespace-pre-wrap">${escapeHtml(plain)}</span>` : '--';
    }

    const rows = entries.map(([key, val]) => {
        return `<tr>`
            + `<th class="border-b border-slate-200 px-2 py-1 text-left text-xs font-semibold text-slate-600">${escapeHtml(key)}</th>`
            + `<td class="border-b border-slate-200 px-2 py-1 text-left text-xs text-slate-900">${escapeHtml(val)}</td>`
            + `</tr>`;
    }).join('');

    return `<details class="rounded border border-slate-200 bg-white" open>`
        + `<summary class="cursor-pointer px-2 py-1 text-xs font-semibold text-slate-700">${entries.length} technische Werte</summary>`
        + `<div class="overflow-auto px-2 pb-2 pt-1"><table class="w-full border-collapse"><tbody>${rows}</tbody></table></div>`
        + `</details>`;
}

function getSpecificationRawFromRow(rowData) {
    return rowData?.device_metadata_specification || rowData?.metadata_specification || rowData?.specification || '';
}

function getSpecificationNumberFromObject(specification, candidateKeys = []) {
    const map = specification && typeof specification === 'object' ? specification : {};
    for (const key of candidateKeys) {
        if (!Object.prototype.hasOwnProperty.call(map, key)) {
            continue;
        }
        const parsed = Number(String(map[key] || '').replace(',', '.'));
        if (Number.isFinite(parsed)) {
            return parsed;
        }
    }
    return 0;
}

async function loadUpsDataset() {
    const [devicesResponse, portsResponse, connectionsResponse] = await Promise.all([
        fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/device_details?limit=5000'),
        fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/device_port_details?limit=5000'),
        fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/connection_details?limit=5000')
    ]);

    const [devicesPayload, portsPayload, connectionsPayload] = await Promise.all([
        devicesResponse.json(),
        portsResponse.json(),
        connectionsResponse.json()
    ]);

    return {
        devices: Array.isArray(devicesPayload?.items) ? devicesPayload.items : [],
        ports: Array.isArray(portsPayload?.items) ? portsPayload.items : [],
        connections: Array.isArray(connectionsPayload?.items) ? connectionsPayload.items : []
    };
}

function isPowerConnection(connectionRow) {
    const descriptor = [
        connectionRow?.connection_type,
        connectionRow?.type,
        connectionRow?.connection_metadata_caption,
        connectionRow?.metadata_caption,
        connectionRow?.connection_metadata_specification,
        connectionRow?.metadata_specification,
        connectionRow?.connection_metadata_description,
        connectionRow?.metadata_description,
        connectionRow?.connection_metadata_tags,
        connectionRow?.metadata_tags,
        connectionRow?.cable_name
    ].filter(Boolean).join(' ').toLowerCase();
    return /power|strom|c13|c14|c19|c20|pdu|usv|ups|schuko|cee/.test(descriptor);
}

function getDevicePowerFromRowSpec(rowData) {
    const specification = normalizeSpecificationObject(getSpecificationRawFromRow(rowData));
    return getSpecificationNumberFromObject(specification, ['powerConsumptionW', 'powerW', 'stromverbrauchW', 'leistungsaufnahmeW']);
}

function getDeviceTypeFromRow(rowData) {
    return String(rowData?.device_type || rowData?.type || '').trim().toLowerCase();
}

async function computeUpsUtilizationFromDetailsRow(rowData) {
    const upsUuid = String(rowData?.device_uuid || rowData?.uuid || '').trim();
    if (!upsUuid) {
        return null;
    }

    const dataset = await loadUpsDataset();
    const ports = dataset.ports || [];
    const connections = dataset.connections || [];
    const devices = dataset.devices || [];

    const portOwner = new Map();
    ports.forEach((port) => {
        const portUuid = String(port?.device_port_uuid || port?.uuid || '').trim();
        const owner = String(port?.device_port_device || port?.device || '').trim();
        if (portUuid && owner) {
            portOwner.set(portUuid, owner);
        }
    });

    const adjacency = new Map();
    const connect = (left, right) => {
        if (!left || !right || left === right) {
            return;
        }
        if (!adjacency.has(left)) {
            adjacency.set(left, new Set());
        }
        adjacency.get(left).add(right);
    };

    connections.forEach((connection) => {
        if (!isPowerConnection(connection)) {
            return;
        }

        const sourcePort = String(connection?.connection_device_port_source || connection?.device_port_source || connection?.connection_expected_device_port_source || connection?.expected_device_port_source || '').trim();
        const destinationPort = String(connection?.connection_device_port_destination || connection?.device_port_destination || connection?.connection_expected_device_port_destination || connection?.expected_device_port_destination || '').trim();

        const sourceDevice = String(portOwner.get(sourcePort) || '').trim();
        const destinationDevice = String(portOwner.get(destinationPort) || '').trim();
        connect(sourceDevice, destinationDevice);
        connect(destinationDevice, sourceDevice);
    });

    const deviceByUuid = new Map();
    const deviceTypeByUuid = new Map();
    const devicePowerByUuid = new Map();
    devices.forEach((device) => {
        const uuid = String(device?.device_uuid || device?.uuid || '').trim();
        if (uuid) {
            deviceByUuid.set(uuid, device);
            deviceTypeByUuid.set(uuid, getDeviceTypeFromRow(device));
            devicePowerByUuid.set(uuid, getDevicePowerFromRowSpec(device));
        }
    });

    const sourceType = deviceTypeByUuid.get(upsUuid) || getDeviceTypeFromRow(rowData);
    const visited = new Set([upsUuid]);
    const queue = [upsUuid];

    while (queue.length > 0) {
        const current = queue.shift();
        const neighbors = adjacency.get(current) || new Set();
        neighbors.forEach((neighbor) => {
            if (!neighbor || visited.has(neighbor)) {
                return;
            }

            const neighborType = deviceTypeByUuid.get(neighbor) || '';
            if (sourceType === 'pdu' && neighborType === 'ups') {
                return;
            }

            visited.add(neighbor);
            queue.push(neighbor);
        });
    }

    let totalLoadW = 0;
    let consumerCount = 0;
    visited.forEach((uuid) => {
        if (!uuid || uuid === upsUuid) {
            return;
        }

        const type = deviceTypeByUuid.get(uuid) || '';
        const power = Number(devicePowerByUuid.get(uuid) || 0);
        if (power > 0) {
            totalLoadW += power;
        }
        if (type !== 'ups' && type !== 'pdu') {
            consumerCount += 1;
        }
    });

    const ownSpecification = normalizeSpecificationObject(getSpecificationRawFromRow(rowData));
    const capacityW = getSpecificationNumberFromObject(ownSpecification, ['powerOutputW', 'capacityW', 'maxPowerW']);
    const utilizationPct = capacityW > 0 ? (totalLoadW / capacityW) * 100 : null;

    return {
        connectedDevices: consumerCount,
        totalLoadW,
        capacityW,
        utilizationPct
    };
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
        const response = await fetch(`<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=metadata&search=${encodeURIComponent(baseUuid)}&limit=200`);
        if (response.ok) {
            const payload = await response.json();
            const items = (payload && Array.isArray(payload.items)) ? payload.items : [];
            const expectedPrefix = `/data/attachments/${baseTable}/${baseUuid}/`;
            return items.filter((item) => {
                const spec = String(item.specification || '');
                return spec.includes(expectedPrefix);
            });
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
            return '<div class="itam-details-empty text-sm text-slate-500">Keine Skript-Ausfuehrungen fuer diesen Eintrag.</div>';
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
            return '<div class="itam-details-empty text-sm text-slate-500">Keine Ausfuehrungseintraege vorhanden.</div>';
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
            return '<div class="itam-details-empty text-sm text-slate-500">Keine Journal-Eintraege vorhanden.</div>';
        }

        return entries.map((entry) => {
            const rowTitle = getDisplayValue(entry, ['journal_metadata_caption', 'metadata_caption', 'journal_uuid', 'uuid']);
            const rowStatus = getDisplayValue(entry, ['journal_metadata_status', 'metadata_status'], '--');
            const rowCreated = getDisplayValue(entry, ['journal_metadata_created', 'metadata_created', 'journal_created', 'created'], '--');
            const rowUser = getDisplayValue(entry, ['journal_metadata_users_username', 'metadata_users_username', 'journal_metadata_users', 'metadata_users'], '--');
            const rowDescription = getDisplayValue(entry, ['journal_metadata_description', 'metadata_description'], '');
            const journalUuid = entry.journal_uuid || entry.uuid || '';
            const journalMetadataUuid = entry.journal_metadata_uuid || entry.metadata_uuid || '';

            return `<div class="mt-2 p-2 rounded border border-slate-200 bg-slate-50 text-xs">`
                + `<div class="flex flex-wrap items-baseline justify-between gap-2">`
                + `<div class="text-xs font-bold text-slate-900">${escapeHtml(rowTitle)}</div>`
                + `<div class="text-xs text-slate-500">Status ${escapeHtml(rowStatus)}</div>`
                + `</div>`
                + `<div class="text-xs text-slate-500">${escapeHtml(rowCreated)} | ${escapeHtml(rowUser)}</div>`
                + (rowDescription !== '--' && rowDescription !== '' ? `<div class="mt-1 whitespace-pre-wrap text-xs text-slate-700">${escapeHtml(rowDescription)}</div>` : '')
                + `<div class="mt-2 flex gap-2">`
                + `<button type="button" class="inline-flex items-center gap-1 rounded-md bg-blue-500 px-2 py-1 text-xs text-white hover:bg-blue-600" onclick="openEditJournalModal('${escapeHtml(journalMetadataUuid)}', '${escapeHtml(rowTitle).replace(/'/g, "\\'")}', '${escapeHtml(rowDescription).replace(/'/g, "\\'")}')" title="Bearbeiten"><i data-lucide="edit-2" class="h-3 w-3"></i> Bearbeiten</button>`
                + `<button type="button" class="inline-flex items-center gap-1 rounded-md bg-red-500 px-2 py-1 text-xs text-white hover:bg-red-600" onclick="deleteJournalEntry('${escapeHtml(journalUuid)}')" title="Löschen"><i data-lucide="trash-2" class="h-3 w-3"></i> Löschen</button>`
                + `</div>`
                + `</div>`;
        }).join('');
    }

    if (panelType === 'attachments') {
        const attachments = await loadAttachmentMetadataForRow(rowData);
        if (attachments.length === 0) {
            return '<div class="itam-details-empty text-sm text-slate-500">Keine Anhaenge vorhanden.</div>';
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
                ? `<img class="max-h-[180px] max-w-full rounded-md border border-slate-300 bg-white object-contain" loading="lazy" src="${escapeHtml(displayUrl)}" alt="Vorschau" />`
                : '';

            return `<div class="mb-2 grid gap-2 rounded-md border border-slate-200 bg-white p-2">`
                + (fileUrl ? `<a href="${escapeHtml(displayUrl)}" target="_blank" rel="noopener noreferrer" class="break-all text-xs text-blue-600 underline">${escapeHtml(fileName)}</a>` : `<span>${escapeHtml(fileName)}</span>`)
                + (description ? `<div class="text-xs text-slate-500">${escapeHtml(description)}</div>` : '')
                + preview
                + `<div class="mt-1 flex gap-2">`
                + `<button type="button" class="inline-flex items-center gap-1 rounded-md bg-blue-500 px-2 py-1 text-xs text-white hover:bg-blue-600" onclick="openEditFileModal('${escapeHtml(metadataUuid)}', '${escapeHtml(fileName).replace(/'/g, "\\'")}', '${escapeHtml(description).replace(/'/g, "\\'")}')" title="Bearbeiten"><i data-lucide="edit-2" class="h-3 w-3"></i> Bearbeiten</button>`
                + `<button type="button" class="inline-flex items-center gap-1 rounded-md bg-red-500 px-2 py-1 text-xs text-white hover:bg-red-600" onclick="deleteFile('${escapeHtml(metadataUuid)}')" title="Löschen"><i data-lucide="trash-2" class="h-3 w-3"></i> Löschen</button>`
                + `</div>`
                + `</div>`;
        }).join('');
    }

    return '<div class="itam-details-empty text-sm text-slate-500">Panel nicht konfiguriert.</div>';
}

async function renderDetailsGrid(rowData) {
    const tableConfig = currentNavConfig[currentTable] || {};
    const detailsLayout = tableConfig.details_layout || null;
    const $detailsContent = $('#detailsContent').empty();

    if (!detailsLayout || !Array.isArray(detailsLayout.primary_fields)) {
        Object.entries(rowData).forEach(([key, value]) => $detailsContent.append(`<p><strong>${escapeHtml(key)}:</strong> ${escapeHtml(value || '--')}</p>`));
        return;
    }

    const $grid = $('<div class="itam-details-grid grid gap-4"></div>');
    const $leftCard = $('<div class="rounded-xl border border-slate-300 bg-slate-50 p-3"></div>');
    const $rightPanels = $('<div class="grid gap-3"></div>');

    const primaryTitle = detailsLayout.primary_title || 'Stammdaten';
    $leftCard.append(`<div class="mb-2 text-base font-bold text-slate-900">${escapeHtml(primaryTitle)}</div>`);
    const $table = $('<table class="w-full border-collapse"><tbody></tbody></table>');
    const $tbody = $table.find('tbody');

    detailsLayout.primary_fields.forEach((fieldKey) => {
        const label = resolveDetailsLabel(fieldKey, tableConfig);
        const value = resolveDetailsValue(fieldKey, rowData);
        const isSpecification = /specification$/i.test(String(fieldKey || ''));
        const renderedValue = isSpecification ? formatSpecificationValueForDetails(value) : escapeHtml(value);
        $tbody.append(`<tr><th class="w-[38%] border-b border-slate-200 px-2 py-2 text-left text-sm font-semibold text-slate-600">${escapeHtml(label)}</th><td class="break-words border-b border-slate-200 px-2 py-2 text-left text-sm text-slate-900">${renderedValue}</td></tr>`);
    });
    $leftCard.append($table);

    const deviceType = String(rowData.device_type || rowData.type || '').trim().toLowerCase();
    if (currentTable === 'device_details' && (deviceType === 'ups' || deviceType === 'pdu')) {
        try {
            const utilization = await computeUpsUtilizationFromDetailsRow(rowData);
            if (utilization) {
                const pct = Number.isFinite(utilization.utilizationPct) ? Math.max(0, utilization.utilizationPct) : null;
                const width = pct === null ? 0 : Math.min(pct, 100);
                const text = pct === null ? 'Kapazitaet fehlt in Specification' : `${pct.toFixed(1)}%`;

                $leftCard.append(`
                    <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3">
                        <div class="mb-1 text-sm font-bold text-amber-900">USV/PDU Auslastung</div>
                        <div class="grid grid-cols-3 gap-2 text-xs text-amber-800">
                            <div>Last: <strong>${escapeHtml(utilization.totalLoadW.toFixed(1))} W</strong></div>
                            <div>Kapazitaet: <strong>${utilization.capacityW > 0 ? `${escapeHtml(utilization.capacityW.toFixed(1))} W` : '--'}</strong></div>
                            <div>Verbraucher: <strong>${escapeHtml(utilization.connectedDevices)}</strong></div>
                        </div>
                        <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-amber-200"><div class="h-full ${pct !== null && pct > 100 ? 'bg-red-600' : 'bg-amber-500'}" style="width:${width}%"></div></div>
                        <div class="mt-1 text-xs text-amber-700">Auslastung: ${escapeHtml(text)}</div>
                    </div>
                `);
            }
        } catch (error) {
            console.warn('USV-Auslastung konnte nicht berechnet werden:', error);
        }
    }
    
    // DEBUG: Für Locations type=8 (Racks) einen 3D-Button hinzufügen
    // Achtung: Bei location_details sind die Felder als "location_type" vorhanden, nicht "type"!
    let locationType = rowData.location_type || rowData.type;
    
    console.log('[3D-VIEW DEBUG] renderDetailsGrid Location:', {
        currentTable,
        location_type: rowData.location_type,
        type: rowData.type,
        resolvedType: locationType,
        isLocationTable: currentTable === 'location_details'
    });
    
    // Zeige 3D-Button für Location-Racks an
    if (currentTable === 'location_details' && (locationType == 8 || locationType === '8' || Number(locationType) === 8)) {
        console.log('[3D-VIEW] ✓ Rack erkannt - Füge 3D-Button hinzu');
        const $actionDiv = $('<div class="mt-4 flex gap-2 flex-wrap"></div>');
        const $3dBtn = $('<button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" onclick="openLocationIn3D(\'' + escapeHtml(rowData.location_uuid || rowData.uuid) + '\')"><i data-lucide="cube" class="h-4 w-4"></i>3D Ansicht öffnen</button>');
        $actionDiv.append($3dBtn);
        $leftCard.append($actionDiv);
    }

    const panels = Array.isArray(detailsLayout.panels) ? detailsLayout.panels : [];
    for (const panel of panels) {
        const panelTitle = panel.title || panel.type || 'Panel';
        const $panelCard = $('<div class="rounded-xl border border-slate-300 bg-slate-50 p-3"></div>');
        $panelCard.append(`<div class="mb-2 text-base font-bold text-slate-900">${escapeHtml(panelTitle)}</div>`);
        
        // Add action buttons to appropriate panels
        let panelType = panel.type?.toLowerCase() || '';
        if (panelType.includes('journal')) {
            const $actions = $('<div class="mb-4 flex flex-wrap gap-2"></div>');
            const $journalBtn = $('<button type="button" class="inline-flex w-full items-center justify-center gap-1 rounded-full border border-slate-300 bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700" onclick="openJournalEntryModal()"><i data-lucide="message-square-plus"></i>Journaleintrag erstellen</button>');
            $actions.append($journalBtn);
            $panelCard.append($actions);
        } else if (panelType.includes('attachment') || panelType.includes('anhang') || panelType.includes('file')) {
            const $actions = $('<div class="mb-4 flex flex-wrap gap-2"></div>');
            const $uploadBtn = $('<button type="button" class="inline-flex w-full items-center justify-center gap-1 rounded-full border border-slate-300 bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700" onclick="openFileUploadModal()"><i data-lucide="upload"></i>Datei hochladen</button>');
            $actions.append($uploadBtn);
            $panelCard.append($actions);
        }
        
        $panelCard.append('<div class="itam-details-empty text-sm text-slate-500">Lade Daten ...</div>');
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
    if (!rowData || typeof rowData !== 'object' || Object.keys(rowData).length === 0) {
        closeDetailsPopup();
        return;
    }

    // Always reopen on info tab first to avoid stale 3D-only state.
    switchDetailsTab('info');
    const details3d = document.getElementById('detailsContent3d');
    if (details3d) {
        details3d.innerHTML = '';
    }

    currentDetailsRowData = rowData;
    await renderDetailsGrid(rowData);
    
    // Prüfe ob Location ein Rack (type=8) oder Raum (type=6) ist und zeige 3D-Tab an
    const isLocationTable = currentTable === 'location_details';
    const resolvedType = rowData.location_type ?? rowData.type;
    const isRack = resolvedType == 8 || resolvedType === '8'; // Flexible Typ-Prüfung
    const isRoom = resolvedType == 6 || resolvedType === '6';
    
    console.log('[3D-DEBUG] openDetailsPopup:', {
        currentTable,
        isLocationTable,
        type: rowData.type,
        location_type: rowData.location_type,
        resolvedType,
        isRack,
        isRoom,
        rowData: rowData
    });
    
    if (isLocationTable && (isRack || isRoom)) {
        console.log('[3D-DEBUG] Zeige 3D-Tab an');
        const tabNavEl = document.getElementById('detailsTabNav');
        const tab3dBtn = document.getElementById('detailsTab3dBtn');
        if (tabNavEl) tabNavEl.style.display = 'flex'; // Nested flex für Tab-Container
        if (tab3dBtn) tab3dBtn.style.display = 'block'; // Block für Tab-Button
    } else {
        console.log('[3D-DEBUG] Verstecke 3D-Tab');
        const tabNavEl = document.getElementById('detailsTabNav');
        const tab3dBtn = document.getElementById('detailsTab3dBtn');
        if (tabNavEl) tabNavEl.style.display = 'none';
        if (tab3dBtn) tab3dBtn.style.display = 'none';
    }

    // Topology / Cable-Trace tab visibility ---------------------------------
    // Show "Topologie" for: a switch/router/patchpanel device,
    // any device_port row, and any connection row.
    const topoBtn = document.getElementById('detailsTabTopologyBtn');
    const topoContext = resolveTopologyContext(rowData);
    if (topoContext) {
        const tabNavEl = document.getElementById('detailsTabNav');
        if (tabNavEl) tabNavEl.style.display = 'flex';
        if (topoBtn) topoBtn.style.display = 'block';
    } else if (topoBtn) {
        topoBtn.style.display = 'none';
    }
    
    $('#detailsPopup').removeClass('hidden');
}

function closeDetailsPopup() {
    if (!$('#detailsPopup').hasClass('hidden')) {
        $('#detailsPopup').addClass('hidden');
    }

    const details3d = document.getElementById('detailsContent3d');
    if (details3d) {
        details3d.innerHTML = '';
    }

    const detailsTopology = document.getElementById('detailsContentTopology');
    if (detailsTopology) {
        detailsTopology.innerHTML = '';
    }

    const tabNavEl = document.getElementById('detailsTabNav');
    const tab3dBtn = document.getElementById('detailsTab3dBtn');
    const topoBtn  = document.getElementById('detailsTabTopologyBtn');
    if (tabNavEl) tabNavEl.style.display = 'none';
    if (tab3dBtn) tab3dBtn.style.display = 'none';
    if (topoBtn)  topoBtn.style.display  = 'none';

    // Reset to info tab
    switchDetailsTab('info');
    currentDetailsRowData = null;
}

/**
 * Wechselt zwischen Details-Tabs (info / 3d)
 */
function switchDetailsTab(tabName) {
    // Deaktiviere alle Tabs
    document.querySelectorAll('.details-tab').forEach(btn => {
        btn.classList.remove('border-blue-600');
        btn.classList.add('border-transparent');
    });
    document.querySelectorAll('.details-tab-content').forEach(el => {
        el.style.display = 'none';
    });
    
    // Aktiviere gewählten Tab
    const activeBtn = document.querySelector(`[data-tab="${tabName}"]`);
    if (activeBtn) {
        activeBtn.classList.remove('border-transparent');
        activeBtn.classList.add('border-blue-600');
    }
    
    const activeContent = document.querySelector(`.details-tab-content[data-tab="${tabName}"]`);
    if (activeContent) {
        activeContent.style.display = tabName === '3d' ? 'flex' : 'block';
    }
    
    // Wenn 3D-Tab gewählt, initialisiere den Viewer
    if (tabName === '3d' && currentDetailsRowData) {
        const resolvedUuid = currentDetailsRowData.location_uuid || currentDetailsRowData.uuid || null;
        if (resolvedUuid) {
            initiate3DViewer(resolvedUuid, currentDetailsRowData);
        } else {
            console.warn('[3D-VIEW] Keine UUID für 3D-Initialisierung gefunden', currentDetailsRowData);
        }
    }

    // Wenn Topologie-Tab gewählt -> Switch 2D + Trace rendern
    if (tabName === 'topology' && currentDetailsRowData) {
        renderTopologyTab(currentDetailsRowData);
    }
}

/**
 * Decide which kind of topology view applies to the current details row.
 * Returns null if the topology tab should stay hidden.
 */
function resolveTopologyContext(rowData) {
    if (!rowData) return null;
    if (currentTable === 'device_details') {
        const dt = String(rowData.device_type || rowData.type || '').trim().toLowerCase();
        if (['switch', 'router', 'patchpanel', 'firewall'].includes(dt)) {
            return { mode: 'device', deviceUuid: rowData.device_uuid || rowData.uuid };
        }
        return null;
    }
    if (currentTable === 'device_port_details') {
        return {
            mode: 'port',
            portUuid: rowData.device_port_uuid || rowData.uuid,
            deviceUuid: rowData.device_port_device || rowData.device_port_device_uuid || null,
            portCaption: rowData.device_port_metadata_caption || null,
        };
    }
    if (currentTable === 'connection_details') {
        return { mode: 'connection', connectionUuid: rowData.connection_uuid || rowData.uuid };
    }
    return null;
}

/**
 * Render content of the Topology tab. Reuses the standalone modules
 * window.PortflowSwitch2D and window.PortflowCableTrace, both loaded
 * globally via includes/header.php.
 */
function renderTopologyTab(rowData) {
    const container = document.getElementById('detailsContentTopology');
    if (!container) return;
    const ctx = resolveTopologyContext(rowData);
    if (!ctx) {
        container.innerHTML = '<div class="text-sm text-slate-500">Keine Topologie verfuegbar.</div>';
        return;
    }
    container.innerHTML = '';

    if (ctx.mode === 'connection') {
        // Render the trace inline (no separate modal needed since this IS the trace view).
        const wrap = document.createElement('div');
        wrap.className = 'rounded-xl border border-slate-200 bg-white p-3';
        wrap.innerHTML = '<div class="mb-2 text-sm font-bold text-slate-900">Kabelverlauf</div><div id="topologyInlineTrace" class="text-sm text-slate-600">Lade ...</div>';
        container.appendChild(wrap);
        loadInlineTrace('connection', ctx.connectionUuid, '#topologyInlineTrace');
        return;
    }

    if (ctx.mode === 'port') {
        // Show the parent switch faceplate + the trace from this port below.
        const top = document.createElement('div');
        top.className = 'space-y-3';
        const header = document.createElement('div');
        header.className = 'rounded-xl border border-slate-200 bg-white p-3';
        header.innerHTML = '<div class="mb-2 text-sm font-bold text-slate-900">Switch-Ansicht</div><div id="topologySwitchView"></div>';
        top.appendChild(header);
        const trace = document.createElement('div');
        trace.className = 'rounded-xl border border-slate-200 bg-white p-3';
        trace.innerHTML = '<div class="mb-2 text-sm font-bold text-slate-900">Kabelverlauf ab diesem Port</div><div id="topologyInlineTrace" class="text-sm text-slate-600">Lade ...</div>';
        top.appendChild(trace);
        container.appendChild(top);
        if (ctx.deviceUuid) {
            window.PortflowSwitch2D.render('#topologySwitchView', {
                deviceUuid: ctx.deviceUuid,
                onPortClick: (portRow) => {
                    const pUuid = String(portRow.device_port_uuid || portRow.uuid || '');
                    loadInlineTrace('device_port', pUuid, '#topologyInlineTrace');
                }
            });
        } else {
            document.getElementById('topologySwitchView').innerHTML = '<div class="text-xs text-slate-500">Geraet nicht aufloesbar.</div>';
        }
        loadInlineTrace('device_port', ctx.portUuid, '#topologyInlineTrace');
        return;
    }

    // mode: 'device' (switch / router / patchpanel)
    const wrap = document.createElement('div');
    wrap.className = 'space-y-3';
    const card = document.createElement('div');
    card.className = 'rounded-xl border border-slate-200 bg-white p-3';
    card.innerHTML = '<div class="mb-2 text-sm font-bold text-slate-900">Switch-Ansicht</div><div id="topologySwitchView"></div>';
    wrap.appendChild(card);
    const trace = document.createElement('div');
    trace.className = 'rounded-xl border border-slate-200 bg-white p-3';
    trace.innerHTML = '<div class="mb-2 text-sm font-bold text-slate-900">Klicke einen Port fuer den Kabelverlauf</div><div id="topologyInlineTrace" class="text-sm text-slate-500">Noch kein Port gewaehlt.</div>';
    wrap.appendChild(trace);
    container.appendChild(wrap);

    window.PortflowSwitch2D.render('#topologySwitchView', {
        deviceUuid: ctx.deviceUuid,
        onPortClick: (portRow) => {
            const pUuid = String(portRow.device_port_uuid || portRow.uuid || '');
            loadInlineTrace('device_port', pUuid, '#topologyInlineTrace');
        }
    });
}

async function loadInlineTrace(kind, uuid, selector) {
    const target = document.querySelector(selector);
    if (!target || !uuid) return;
    target.innerHTML = '<div class="text-sm text-slate-500">Lade Kabelverlauf ...</div>';
    try {
        const r = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/cable_trace?from=' + encodeURIComponent(uuid) + '&kind=' + encodeURIComponent(kind), {
            credentials: 'same-origin', headers: { 'Accept': 'application/json' }
        });
        const result = await r.json();
        // Re-use the rendering logic from PortflowCableTrace by populating a temporary element.
        const tmp = document.createElement('div');
        tmp.id = 'pf-cable-trace-body';
        target.innerHTML = '';
        target.appendChild(tmp);
        // The PortflowCableTrace module renders into #pf-cable-trace-body, but
        // here we want inline rendering — duplicate the simple chain renderer.
        target.innerHTML = renderTraceInlineHtml(result);
        if (window.lucide) window.lucide.createIcons();
    } catch (e) {
        target.innerHTML = '<div class="rounded-lg border border-red-300 bg-red-50 p-2 text-xs text-red-700">Fehler: ' + escapeHtml(e.message || e) + '</div>';
    }
}

function renderTraceInlineHtml(result) {
    if (!result || result.error) return '<div class="rounded-lg border border-red-300 bg-red-50 p-2 text-xs text-red-700">' + escapeHtml(result && result.error || 'Fehler') + '</div>';
    if (result.kind === 'device') {
        const ports = Array.isArray(result.ports) ? result.ports : [];
        if (!ports.length) return '<div class="text-xs text-slate-500">Keine verbundenen Ports.</div>';
        return ports.map(p => '<div class="mb-3">' + renderTraceInlineHtml(p) + '</div>').join('');
    }
    const branches = Array.isArray(result.branches) ? result.branches : [];
    if (!branches.length) return '<div class="text-xs text-slate-500">Keine Verbindungen ab diesem Punkt.</div>';
    return branches.map((branch, idx) => {
        const items = [];
        if (result.kind === 'device_port' && result.start) items.push(traceNodePill(result.start));
        for (const hop of branch) {
            items.push(traceCableArrow(hop.cable || {}));
            items.push(traceNodePill(hop.port));
        }
        return '<div class="mb-2"><div class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Pfad ' + (idx + 1) + '</div>' +
               '<div class="flex flex-wrap items-stretch gap-1">' + items.join('') + '</div></div>';
    }).join('');
}

function traceNodePill(node) {
    if (!node || node.type !== 'port') return '<div class="rounded-lg border border-slate-300 bg-slate-100 p-2 text-xs text-slate-700">Unbekannt</div>';
    const dev = node.device || {};
    const loc = node.location || {};
    const status = node.snmp && node.snmp.oper_status === 1 ? 'up' : node.snmp && node.snmp.admin_status === 2 ? 'admin_down' : node.snmp && node.snmp.oper_status === 2 ? 'down' : 'unknown';
    const sc = { up: 'bg-emerald-100 text-emerald-800', down: 'bg-amber-100 text-amber-800', admin_down: 'bg-red-100 text-red-800', unknown: 'bg-slate-100 text-slate-700' }[status];
    const endpointBadge = node.endpoint ? '<span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-blue-700">Endpunkt</span>' : '';
    const truncated = node.truncated_reason ? '<div class="mt-1 text-[10px] text-amber-700">⚠ ' + escapeHtml(node.truncated_reason) + '</div>' : '';
    return '<div class="min-w-[170px] rounded-lg border border-slate-300 bg-white p-2 text-xs shadow-sm">' +
        '<div class="flex items-center justify-between gap-1"><div class="font-bold text-slate-900">' + escapeHtml(dev.caption || 'Device') + endpointBadge + '</div>' +
        '<span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase ' + sc + '">' + escapeHtml(dev.type || '') + '</span></div>' +
        (loc.caption ? '<div class="text-[10px] text-slate-500">📍 ' + escapeHtml(loc.caption) + '</div>' : '') +
        '<div class="mt-1 rounded bg-slate-50 px-1.5 py-0.5"><span class="font-semibold">Port:</span> ' + escapeHtml(node.caption || '—') + '</div>' +
        (node.ip ? '<div class="text-[10px] text-slate-600">IP: ' + escapeHtml(node.ip) + '</div>' : '') +
        truncated +
        '</div>';
}

function traceCableArrow(edge) {
    const parts = [];
    if (edge.cable_name) parts.push(escapeHtml(edge.cable_name));
    if (edge.cable_type) parts.push(escapeHtml(edge.cable_type));
    if (edge.length) parts.push(escapeHtml(edge.length) + ' m');
    const label = parts.length ? parts.join(' · ') : 'Kabel';
    return '<div class="flex flex-col items-center justify-center px-1 text-slate-500"><div class="text-[9px] uppercase tracking-wider">' + label + '</div>' +
        '<svg viewBox="0 0 60 12" width="60" height="12" class="my-0.5"><line x1="2" y1="6" x2="58" y2="6" stroke="#64748b" stroke-width="2" stroke-dasharray="4 3"/><polygon points="58,6 52,3 52,9" fill="#64748b"/></svg></div>';
}

/**
 * Öffnet einen Rack in der 3D-Ansicht
 */
async function openLocationIn3D(locationUuid) {
    console.log('[3D-VIEW] openLocationIn3D called with UUID:', locationUuid);
    
    // Tab wechseln
    switchDetailsTab('3d');
    
    // Fallback: falls keine aktuelle Detailzeile gesetzt ist
    if (!currentDetailsRowData && locationUuid) {
        try {
            await initiate3DViewer(locationUuid, null);
        } catch (err) {
            console.error('[3D-VIEW] Error:', err);
            alert('Fehler beim Laden der 3D-Ansicht: ' + err.message);
        }
    }
}

async function initiate3DViewer(locationUuid, rackSeedRow = null) {
    const container = document.getElementById('detailsContent3d');
    const resolvedType = (rackSeedRow?.location_type ?? rackSeedRow?.type ?? currentDetailsRowData?.location_type ?? currentDetailsRowData?.type ?? '').toString();
    const isRoomMode = resolvedType === '6';
    container.innerHTML = `
        <div class="viewer3d-layout grid h-full min-h-0 gap-3 lg:grid-cols-[280px_minmax(0,1fr)]">
            <div class="min-h-0 overflow-y-auto rounded-lg border border-slate-300 bg-white p-3 shadow-sm">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Steuerung (Basis)</div>
                <div class="mb-3 text-xs text-slate-600">Links: drehen | Mitte: verschieben | Rad: zoomen</div>

                <div class="mb-3 grid grid-cols-2 gap-2">
                    <button type="button" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" onclick="set3DCameraPreset('front')">Front</button>
                    <button type="button" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" onclick="set3DCameraPreset('rear')">Rear</button>
                    <button type="button" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" onclick="set3DCameraPreset('left')">Left</button>
                    <button type="button" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" onclick="set3DCameraPreset('right')">Right</button>
                    <button type="button" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" onclick="set3DCameraPreset('top')">Top</button>
                    <button type="button" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" onclick="set3DCameraPreset('iso')">Iso</button>
                </div>

                <div class="grid gap-3">

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-slate-700">Kabel-Preset</span>
                <select id="viewer3dCablePreset" class="rounded-lg border border-slate-300 bg-white px-3 py-2" onchange="set3DCablePreset(this.value)">
                    <option value="all">Alle</option>
                    <option value="power">Nur Power</option>
                    <option value="fiber">Nur Fiber</option>
                    <option value="copper">Nur Copper</option>
                    <option value="minimal">Minimal Fokus</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-slate-700">Achsen-Lock</span>
                <select id="viewer3dAxisLock" class="rounded-lg border border-slate-300 bg-white px-3 py-2" onchange="set3DAxisLock(this.value)">
                    <option value="free">Frei</option>
                    <option value="horizontal">Horizontal Orbit</option>
                </select>
            </label>

            ${isRoomMode ? `
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-slate-700">Rack-Fokus (Room)</span>
                <select id="viewer3dRoomFocusRack" class="rounded-lg border border-slate-300 bg-white px-3 py-2" onchange="set3DRoomFocusRack(this.value)">
                    <option value="">Automatisch (alle Racks)</option>
                </select>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dRoomFocusDevicesOnlyToggle" onchange="toggle3DFeature('roomFocusDevicesOnly', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Nur fokussiertes Rack mit Geraeten</span>
            </label>
            ` : ''}
            
            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dPortsToggle" checked onchange="toggle3DFeature('ports', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Ports anzeigen</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dPortLabelsToggle" checked onchange="toggle3DFeature('portLabels', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Port-Beschriftung</span>
            </label>
            
            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dCablesToggle" checked onchange="toggle3DFeature('cables', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Kabel anzeigen</span>
            </label>
            
            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dCableLabelsToggle" checked onchange="toggle3DFeature('cableLabels', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Kabel-Beschriftung</span>
            </label>

            <details class="viewer-expert-menu rounded-lg border border-slate-200 bg-slate-50/80 px-3 py-2">
                <summary class="text-xs font-bold uppercase tracking-wide text-slate-600">Expertenmenue</summary>
                <div class="mt-3 grid gap-3">

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dHoverLabelsOnlyToggle" checked onchange="toggle3DFeature('hoverLabelsOnly', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Beschriftung nur bei Hover</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dRenderAllCablesToggle" checked onchange="toggle3DFeature('renderAllCables', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Alle Kabel rendern</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dRackEarsToggle" checked onchange="toggle3DFeature('rackEars', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Rackohren anzeigen</span>
            </label>
            
            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dLoadOverlayToggle" onchange="toggle3DFeature('loadOverlay', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Last-Overlay anzeigen</span>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-slate-700">Overlay-Metrik</span>
                <select id="viewer3dMetric" class="rounded-lg border border-slate-300 bg-white px-3 py-2" onchange="change3DMetric(this.value)">
                    <option value="weight">Gewicht (kg)</option>
                    <option value="power">Power (W)</option>
                    <option value="thermal">Thermal (W)</option>
                </select>
            </label>

            ${isRoomMode ? `
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-slate-700">Chunk Size</span>
                <input type="number" id="viewer3dFetchChunkSize" min="10" step="10" value="80" class="rounded-lg border border-slate-300 bg-white px-3 py-2" onchange="set3DFetchTuning()">
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-slate-700">Target Chunks</span>
                <input type="number" id="viewer3dFetchTargetChunks" min="1" step="1" value="8" class="rounded-lg border border-slate-300 bg-white px-3 py-2" onchange="set3DFetchTuning()">
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-slate-700">Max Concurrency</span>
                <input type="number" id="viewer3dFetchConcurrency" min="1" step="1" value="4" class="rounded-lg border border-slate-300 bg-white px-3 py-2" onchange="set3DFetchTuning()">
            </label>
            ` : ''}

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold text-slate-700">Kabel nur fuer Geraete</span>
                <select id="viewer3dSelectedDevices" multiple size="6" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" onchange="set3DSelectedDevices()"></select>
                <span class="text-xs text-slate-500">Mehrfachauswahl moeglich. Alternativ im Viewer auf ein Geraet klicken, wenn nicht alle Kabel gerendert werden.</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dCableMetaLabelsToggle" checked onchange="toggle3DFeature('cableMetaLabels', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Kabel-Metadaten</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dCableFiberToggle" checked onchange="toggle3DFeature('cableFiber', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Fiber</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dCableCopperToggle" checked onchange="toggle3DFeature('cableCopper', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Copper/CAT</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dCablePowerToggle" checked onchange="toggle3DFeature('cablePower', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Power</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dCableTrunkToggle" checked onchange="toggle3DFeature('cableTrunk', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Trunks</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dCableRearAwareToggle" onchange="toggle3DFeature('cableRearAware', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Rear-aware Routing</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dComMarkerToggle" onchange="toggle3DFeature('comMarker', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Last-Schwerpunktmarker</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dTransparentRackToggle" onchange="toggle3DFeature('rackTransparent', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Rack halbtransparent</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dDoorsToggle" checked onchange="toggle3DFeature('doorsOpen', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Türen geöffnet</span>
            </label>

            <label class="flex items-end gap-2">
                <input type="checkbox" id="viewer3dSidePanelsToggle" onchange="toggle3DFeature('sidePanelsOpen', this.checked)" class="h-4 w-4">
                <span class="text-sm font-semibold text-slate-700">Seitenwände geöffnet</span>
            </label>

                </div>
            </details>

                </div>

                <div id="viewer3dStatus" class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm text-slate-600">
                    3D-Viewer wird geladen...
                </div>
                <div id="viewer3dUpsSummary" class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm text-slate-600">
                    USV/PDU Auslastung wird geladen...
                </div>
            </div>

            <div id="viewer3dContainer" class="h-[60vh] min-h-[520px] w-full rounded-lg border border-slate-300 bg-slate-50 shadow-sm lg:h-full lg:min-h-0"></div>
        </div>
    `;
    
    // Importiere und starte den 3D-Viewer
    try {
        // Errechne absoluten Pfad zum Modul
        const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')) + '/';
        const modulePath = baseUrl + 'includes/js/PortflowViewer3D.js?v=' + Date.now();
        console.log('[3D-VIEW] Importing from:', modulePath);
        
        const module = await import(modulePath);
        const PortflowViewer3D = module.PortflowViewer3D;

        const syncSelectedDevicesUi = (selectedUuids = []) => {
            const selectEl = document.getElementById('viewer3dSelectedDevices');
            if (!selectEl) return;
            const selectedSet = new Set((selectedUuids || []).map(uuid => String(uuid || '')));
            Array.from(selectEl.options).forEach((option) => {
                option.selected = selectedSet.has(option.value);
            });
        };

        const refreshSelectedDevicesAvailability = () => {
            const selectEl = document.getElementById('viewer3dSelectedDevices');
            const renderAllToggle = document.getElementById('viewer3dRenderAllCablesToggle');
            if (!selectEl || !renderAllToggle) return;
            selectEl.disabled = renderAllToggle.checked;
            selectEl.classList.toggle('opacity-60', renderAllToggle.checked);
        };

        const populateDeviceSelection = (devices = []) => {
            const selectEl = document.getElementById('viewer3dSelectedDevices');
            if (!selectEl) return;
            const selectedSet = new Set(window.current3DViewer?.state?.selectedDeviceUuids || []);
            const rackNameByUuid = new Map((window.current3DViewer?.state?.liveRacks || []).map((rack) => [String(rack?.uuid || ''), String(rack?.name || '').trim()]));
            selectEl.innerHTML = '';

            const sortedDevices = [...devices].sort((left, right) => String(left?.name || left?.uuid || '').localeCompare(String(right?.name || right?.uuid || '')));
            sortedDevices.forEach((device) => {
                const uuid = String(device?.uuid || '').trim();
                if (!uuid) return;
                const deviceName = String(device?.name || '').trim() || `Geraet ${uuid.slice(0, 8)}`;
                const locationLabel = String(device?.locationName || rackNameByUuid.get(String(device?.location || '').trim()) || '').trim();
                const option = document.createElement('option');
                option.value = uuid;
                option.textContent = locationLabel ? `${deviceName} | ${locationLabel}` : deviceName;
                option.selected = selectedSet.has(uuid);
                selectEl.appendChild(option);
            });

            refreshSelectedDevicesAvailability();
        };
        
        const viewer = new PortflowViewer3D('#viewer3dContainer', {
            apiUrl: './api/',
            debug: true,
            width: document.getElementById('viewer3dContainer').clientWidth,
            height: document.getElementById('viewer3dContainer').clientHeight || 720,
            onSelectedDevicesChange: (selectedUuids) => {
                syncSelectedDevicesUi(selectedUuids || []);
            },
            onStatus: (msg, isError) => {
                const statusEl = document.getElementById('viewer3dStatus');
                if (statusEl) {
                    statusEl.textContent = msg;
                    statusEl.className = `rounded-lg border p-3 text-sm ${isError 
                        ? 'border-red-300 bg-red-50 text-red-700' 
                        : 'border-slate-200 bg-slate-50 text-slate-600'}`;
                }
            }
        });

        const applyFetchTuningFromInputs = () => {
            const chunkSizeEl = document.getElementById('viewer3dFetchChunkSize');
            const targetChunksEl = document.getElementById('viewer3dFetchTargetChunks');
            const concurrencyEl = document.getElementById('viewer3dFetchConcurrency');

            viewer.setFetchTuning?.({
                minChunkSize: Number(chunkSizeEl?.value || 80),
                maxChunkSize: Number(chunkSizeEl?.value || 80),
                targetChunks: Number(targetChunksEl?.value || 8),
                maxConcurrency: Number(concurrencyEl?.value || 4)
            });
        };

        if (isRoomMode) {
            applyFetchTuningFromInputs();
        }
        
        window.current3DViewer = viewer;
        
        if (isRoomMode) {
            await viewer.loadRoom(locationUuid);
        } else {
            await viewer.loadRack(locationUuid, rackSeedRow || currentDetailsRowData || null);
        }

        populateDeviceSelection(viewer.state?.liveDevices || []);
        refreshSelectedDevicesAvailability();

        if (viewer && viewer.state && viewer.state.activeRack) {
            const rack = viewer.state.activeRack;
            const statusEl = document.getElementById('viewer3dStatus');
            if (statusEl) {
                const outer = rack.geometry?.outer || {};
                const inner = rack.geometry?.inner || {};
                if (isRoomMode) {
                    statusEl.textContent = `Raum geladen | Racks: ${viewer.state.liveRacks?.length || 0} | Devices: ${viewer.state.liveDevices?.length || 0} | Cables: ${viewer.state.liveConnections?.length || 0}`;
                } else {
                    statusEl.textContent = `Rack geladen | UUID: ${rack.uuid} | Outer: ${outer.x || '-'}x${outer.y || '-'}x${outer.z || '-'} | Inner: ${inner.x || '-'}x${inner.y || '-'}x${inner.z || '-'} | Cables: ${viewer.state.liveConnections?.length || 0}`;
                }
            }
        }

        const upsSummaryEl = document.getElementById('viewer3dUpsSummary');
        if (upsSummaryEl) {
            const devices = Array.isArray(viewer.state?.liveDevices) ? viewer.state.liveDevices : [];
            const connections = Array.isArray(viewer.state?.liveConnections) ? viewer.state.liveConnections : [];
            const deviceByUuid = new Map();
            const portToDeviceUuid = new Map();
            const adjacency = new Map();

            const connect = (left, right) => {
                if (!left || !right || left === right) {
                    return;
                }
                if (!adjacency.has(left)) {
                    adjacency.set(left, new Set());
                }
                adjacency.get(left).add(right);
            };

            devices.forEach((device) => {
                const uuid = String(device?.uuid || '').trim();
                if (uuid) {
                    deviceByUuid.set(uuid, device);
                }

                const ports = Array.isArray(device?.ports) ? device.ports : [];
                ports.forEach((port) => {
                    const portUuid = String(port?.uuid || '').trim();
                    if (portUuid && uuid) {
                        portToDeviceUuid.set(portUuid, uuid);
                    }
                });
            });

            const powerSources = devices.filter((device) => {
                const type = String(device?.deviceType || '').toLowerCase();
                return type === 'ups' || type === 'pdu';
            });

            if (powerSources.length === 0) {
                upsSummaryEl.textContent = 'Keine USV/PDU im aktuellen 3D-Ausschnitt.';
            } else {
                const html = powerSources.map((source) => {
                    const sourceUuid = String(source?.uuid || '').trim();
                    connections.forEach((connection) => {
                        if (String(connection?.cableKind || '').toLowerCase() !== 'power') {
                            return;
                        }

                        const srcPort = String(connection?.sourcePortUuid || '').trim();
                        const dstPort = String(connection?.destinationPortUuid || '').trim();
                        const srcFromPort = srcPort ? String(portToDeviceUuid.get(srcPort) || '').trim() : '';
                        const dstFromPort = dstPort ? String(portToDeviceUuid.get(dstPort) || '').trim() : '';

                        const src = String(connection?.sourceDeviceUuid || srcFromPort || '').trim();
                        const dst = String(connection?.destinationDeviceUuid || dstFromPort || '').trim();
                        connect(src, dst);
                        connect(dst, src);
                    });

                    const sourceType = String(source?.deviceType || '').toLowerCase();
                    const visited = new Set([sourceUuid]);
                    const queue = [sourceUuid];

                    while (queue.length > 0) {
                        const current = queue.shift();
                        const neighbors = adjacency.get(current) || new Set();
                        neighbors.forEach((neighbor) => {
                            if (!neighbor || visited.has(neighbor)) {
                                return;
                            }
                            const neighborType = String(deviceByUuid.get(neighbor)?.deviceType || '').toLowerCase();
                            if (sourceType === 'pdu' && neighborType === 'ups') {
                                return;
                            }
                            visited.add(neighbor);
                            queue.push(neighbor);
                        });
                    }

                    let consumerCount = 0;
                    let totalLoadW = 0;
                    visited.forEach((uuid) => {
                        if (!uuid || uuid === sourceUuid) {
                            return;
                        }
                        const device = deviceByUuid.get(uuid);
                        const type = String(device?.deviceType || '').toLowerCase();
                        const power = Number(device?.powerW || 0);
                        if (power > 0) {
                            totalLoadW += power;
                        }
                        if (type !== 'ups' && type !== 'pdu') {
                            consumerCount += 1;
                        }
                    });

                    const capacityW = Number(source?.powerOutputW || 0);
                    const pct = capacityW > 0 ? (totalLoadW / capacityW) * 100 : null;
                    const width = pct === null ? 0 : Math.min(Math.max(pct, 0), 100);
                    const pctText = pct === null ? 'n/a' : `${pct.toFixed(1)}%`;

                    return `<div class="mb-2 rounded border border-slate-200 bg-white p-2">`
                        + `<div class="text-xs font-semibold text-slate-800">${escapeHtml(String(source?.name || sourceUuid))}</div>`
                        + `<div class="mt-1 grid grid-cols-3 gap-2 text-[11px] text-slate-600">`
                        + `<div>Last: <strong>${escapeHtml(totalLoadW.toFixed(1))} W</strong></div>`
                        + `<div>Kap.: <strong>${capacityW > 0 ? `${escapeHtml(capacityW.toFixed(1))} W` : '--'}</strong></div>`
                        + `<div>Verbraucher: <strong>${escapeHtml(consumerCount)}</strong></div>`
                        + `</div>`
                        + `<div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-200"><div class="h-full ${pct !== null && pct > 100 ? 'bg-red-600' : 'bg-blue-600'}" style="width:${width}%"></div></div>`
                        + `<div class="mt-1 text-[11px] text-slate-600">Auslastung: ${escapeHtml(pctText)}</div>`
                        + `</div>`;
                }).join('');

                upsSummaryEl.innerHTML = `<div class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">USV/PDU Auslastung (3D)</div>${html}`;
            }
        }

        if (isRoomMode) {
            const roomFocusSelect = document.getElementById('viewer3dRoomFocusRack');
            if (roomFocusSelect && Array.isArray(viewer.state?.liveRacks)) {
                roomFocusSelect.innerHTML = '<option value="">Automatisch (alle Racks)</option>';
                viewer.state.liveRacks.forEach(rack => {
                    const option = document.createElement('option');
                    option.value = String(rack.uuid || '');
                    option.textContent = `${rack.name || 'Rack'} (${String(rack.uuid || '').slice(0, 8)})`;
                    roomFocusSelect.appendChild(option);
                });
            }
        }
        
        // Speichere die Event-Handler im Window für Zugriff durch HTML-Attribute
        window.change3DMetric = (metric) => {
            if (window.current3DViewer) {
                window.current3DViewer.setOverlayMetric(metric);
            }
        };

        window.set3DCablePreset = (preset) => {
            if (!window.current3DViewer) return;
            window.current3DViewer.setCablePreset?.(preset);

            const cableToggle = document.getElementById('viewer3dCablesToggle');
            if (cableToggle) {
                cableToggle.checked = window.current3DViewer.state.showCables !== false;
            }

            const fiberToggle = document.getElementById('viewer3dCableFiberToggle');
            const copperToggle = document.getElementById('viewer3dCableCopperToggle');
            const powerToggle = document.getElementById('viewer3dCablePowerToggle');
            const trunkToggle = document.getElementById('viewer3dCableTrunkToggle');
            if (fiberToggle) fiberToggle.checked = !!window.current3DViewer.state.cableFilterFiber;
            if (copperToggle) copperToggle.checked = !!window.current3DViewer.state.cableFilterCopper;
            if (powerToggle) powerToggle.checked = !!window.current3DViewer.state.cableFilterPower;
            if (trunkToggle) trunkToggle.checked = !!window.current3DViewer.state.cableFilterTrunk;
        };

        window.set3DAxisLock = (mode) => {
            if (!window.current3DViewer) return;
            window.current3DViewer.setAxisLock?.(mode);
        };

        const initialCablePresetEl = document.getElementById('viewer3dCablePreset');
        if (initialCablePresetEl) {
            window.set3DCablePreset(initialCablePresetEl.value);
        }

        const initialAxisLockEl = document.getElementById('viewer3dAxisLock');
        if (initialAxisLockEl) {
            window.set3DAxisLock(initialAxisLockEl.value);
        }

        window.set3DCameraPreset = (preset) => {
            if (window.current3DViewer) {
                window.current3DViewer.setCameraPreset?.(preset);
            }
        };

        window.set3DRoomFocusRack = (rackUuid) => {
            if (!window.current3DViewer) return;
            window.current3DViewer.setRoomFocusRack?.(rackUuid || '');
        };

        window.set3DFetchTuning = async () => {
            if (!window.current3DViewer) return;
            applyFetchTuningFromInputs();

            if (isRoomMode && window.current3DViewer.state?.currentSceneUuid) {
                await window.current3DViewer.loadRoom(window.current3DViewer.state.currentSceneUuid);
                populateDeviceSelection(window.current3DViewer.state?.liveDevices || []);
            }
        };

        window.set3DSelectedDevices = () => {
            if (!window.current3DViewer) return;
            const selectEl = document.getElementById('viewer3dSelectedDevices');
            if (!selectEl) return;
            const selectedUuids = Array.from(selectEl.selectedOptions).map((option) => option.value);
            window.current3DViewer.setSelectedDevices?.(selectedUuids);
        };
        
        window.toggle3DFeature = (feature, enabled) => {
            if (!window.current3DViewer) return;
            switch (feature) {
                case 'ports':
                    window.current3DViewer.setPortsVisible?.(enabled);
                    break;
                case 'cables':
                    window.current3DViewer.setCablesVisible?.(enabled);
                    break;
                case 'rackEars':
                    window.current3DViewer.setRackEarsVisible?.(enabled);
                    break;
                case 'loadOverlay':
                    window.current3DViewer.setLoadOverlayVisible?.(enabled);
                    break;
                case 'portLabels':
                    window.current3DViewer.setPortLabelsVisible?.(enabled);
                    break;
                case 'cableLabels':
                    window.current3DViewer.setCableLabelsVisible?.(enabled);
                    break;
                case 'cableMetaLabels':
                    window.current3DViewer.setCableMetaLabelsVisible?.(enabled);
                    break;
                case 'cableFiber':
                    window.current3DViewer.setFiberVisible?.(enabled);
                    break;
                case 'cableCopper':
                    window.current3DViewer.setCopperVisible?.(enabled);
                    break;
                case 'cablePower':
                    window.current3DViewer.setPowerCableVisible?.(enabled);
                    break;
                case 'cableTrunk':
                    window.current3DViewer.setTrunkVisible?.(enabled);
                    break;
                case 'cableRearAware':
                    window.current3DViewer.setRearAware?.(enabled);
                    break;
                case 'hoverLabelsOnly':
                    window.current3DViewer.setHoverLabelsOnly?.(enabled);
                    break;
                case 'renderAllCables':
                    window.current3DViewer.setRenderAllCables?.(enabled);
                    if (!enabled && (window.current3DViewer.state?.selectedDeviceUuids || []).length === 0) {
                        const allVisibleDevices = (window.current3DViewer.state?.liveDevices || []).map((device) => String(device?.uuid || '').trim()).filter(Boolean);
                        window.current3DViewer.setSelectedDevices?.(allVisibleDevices);
                    }
                    refreshSelectedDevicesAvailability();
                    break;
                case 'comMarker':
                    window.current3DViewer.setComMarkerVisible?.(enabled);
                    break;
                case 'rackTransparent':
                    window.current3DViewer.setRackTransparent?.(enabled);
                    break;
                case 'doorsOpen':
                    window.current3DViewer.setDoorsOpen?.(enabled);
                    break;
                case 'sidePanelsOpen':
                    window.current3DViewer.setSidePanelsOpen?.(enabled);
                    break;
                case 'roomFocusDevicesOnly':
                    window.current3DViewer.setRoomFocusDevicesOnly?.(enabled);
                    break;
                default:
                    window.current3DViewer.state[`show${feature.charAt(0).toUpperCase() + feature.slice(1)}`] = enabled;
                    window.current3DViewer._invalidateCache?.();
                    break;
            }
        };
        
    } catch (err) {
        console.error('Failed to initialize 3D viewer:', err);
        const statusEl = document.getElementById('viewer3dStatus');
        if (statusEl) {
            statusEl.textContent = `Fehler: ${err.message}`;
            statusEl.className = 'rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700';
        }
    }
}

function closeJournalEntryModal() {
    if (!currentDetailsRowData) {
        alert('Keine Zeile ausgewählt.');
        return;
    }
    document.getElementById('journalEntryCaption').value = '';
    document.getElementById('journalEntryDescription').value = '';
    const modal = document.getElementById('journalEntryModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeJournalEntryModal() {
    const modal = document.getElementById('journalEntryModal');
    modal.classList.remove('flex');
    modal.classList.add('hidden');
}

function openFileUploadModal() {
    if (!currentDetailsRowData) {
        alert('Keine Zeile ausgewählt.');
        return;
    }
    document.getElementById('fileUploadInput').value = '';
    document.getElementById('fileUploadDescription').value = '';
    const modal = document.getElementById('fileUploadModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeFileUploadModal() {
    const modal = document.getElementById('fileUploadModal');
    modal.classList.remove('flex');
    modal.classList.add('hidden');
    // Reset form
    document.getElementById('fileUploadInput').value = '';
    document.getElementById('fileUploadDescription').value = '';
    document.getElementById('fileUploadFeedback').classList.add('hidden');
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
        feedback.classList.remove('hidden');
    } else {
        feedback.classList.add('hidden');
    }
}

// Delete entry
function deleteEntry(uuid, rowData) {
    if (confirm('Möchten Sie diesen Eintrag und alle zugehörigen Daten wirklich löschen?')) {
        fetch('<?php echo PORTFLOW_HOSTNAME; ?>' + '/includes/forms.json')
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
    const modal = document.getElementById('editJournalModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditJournalModal() {
    const modal = document.getElementById('editJournalModal');
    modal.classList.remove('flex');
    modal.classList.add('hidden');
    currentEditingJournalUuid = null;
}

async function submitEditJournal() {
    if (!currentEditingJournalUuid) {
        alert('Keine Journal-Metadaten-UUID gefunden.');
        return;
    }

    const caption = document.getElementById('editJournalCaption').value.trim();
    const description = document.getElementById('editJournalDescription').value.trim();

    try {
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=metadata&uuid=' + encodeURIComponent(currentEditingJournalUuid), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
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
    const modal = document.getElementById('editFileModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditFileModal() {
    const modal = document.getElementById('editFileModal');
    modal.classList.remove('flex');
    modal.classList.add('hidden');
    currentEditingMetadataUuid = null;
}

async function submitEditFile() {
    if (!currentEditingMetadataUuid) {
        alert('Keine Datei-UUID gefunden.');
        return;
    }

    const description = document.getElementById('editFileDescription').value.trim();

    try {
        const response = await fetch('<?php echo PORTFLOW_HOSTNAME; ?>/api/?table=metadata&uuid=' + encodeURIComponent(currentEditingMetadataUuid), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
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
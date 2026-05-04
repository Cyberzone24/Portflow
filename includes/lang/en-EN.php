<?php
if (isset($_GET['nav'])) {
    $nav = [];
    $nav['location_details'] = [
        'default' => ['location_type', 'location_parent_location_metadata_caption', 'location_metadata_tags'],
        'details_layout' => [
            'primary_title' => 'Core Data',
            'primary_fields' => [
                'location_metadata_caption',
                'location_metadata_status',
                'location_type',
                'location_parent_location_metadata_caption',
                'location_metadata_tags',
                'location_metadata_description',
                'location_metadata_specification'
            ],
            'panels' => [
                ['type' => 'journal', 'title' => 'Journal'],
                ['type' => 'attachments', 'title' => 'Attachments & Images']
            ]
        ],
        'columns' => [
            'location_metadata_caption' => 'Name',
            'location_metadata_status' => 'Status',
            'location_type' => 'Location Type',
            'location_parent_location_metadata_caption' => 'Parent Location',
            'location_metadata_tags' => 'Tags',
            'location_metadata_description' => 'Description',
            'location_metadata_specification' => 'Specification'
        ]
    ];
    $nav['location_join_metadata_join_location'] = [
        'uuid' => 'Location UUID',
        'type' => 'Location Type',
        'size' => 'Location Size',
        'rotation' => 'Location Rotation',
        'metadata_uuid_0' => 'Metadata UUID',
        'metadata_users_0' => 'Metadata User',
        'metadata_status_0' => 'Metadata Status',
        'metadata_created_0' => 'Metadata Created',
        'metadata_changed_0' => 'Metadata Changed',
        'metadata_specification_0' => 'Metadata Specification',
        'metadata_tags_0' => 'Metadata Tags',
        'metadata_caption_0' => 'Metadata Caption',
        'metadata_description_0' => 'Metadata Description',
        'location_uuid_1' => 'Parent Location UUID',
        'location_metadata_1' => 'Parent Location Metadata',
        'location_parent_location_1' => 'Parent Location Parent Location',
        'location_type_1' => 'Parent Location Type',
        'location_size_1' => 'Parent Location Size',
        'location_rotation_1' => 'Parent Location Rotation',
    ];
    $nav['ip_range_join_metadata'] = [
        'uuid' => 'IP-Range UUID',
        'ip_range' => 'IP-Range',
        'subnet' => 'IP-Range Subnet',
        'gateway' => 'IP-Range Gateway',
        'broadcast' => 'IP-Range Broadcast',
        'dns_server' => 'IP-Range DNS-Server',
        'dns_zone' => 'IP-Range DNS-Zone',
        'dhcp_server' => 'IP-Range DHCP-Server',
        'metadata_uuid_0' => 'Metadata UUID',
        'metadata_users_0' => 'Metadata User',
        'metadata_status_0' => 'Metadata Status',
        'metadata_created_0' => 'Metadata Created',
        'metadata_changed_0' => 'Metadata Changed',
        'metadata_specification_0' => 'Metadata Specification',
        'metadata_tags_0' => 'Metadata Tags',
        'metadata_caption_0' => 'Metadata Caption',
        'metadata_description_0' => 'Metadata Description',
        'details_layout' => [
            'primary_title' => 'Core Data',
            'primary_fields' => [
                'ip_range_metadata_caption',
                'ip_range_metadata_status',
                'ip_range_ip_range',
                'ip_range_gateway',
                'ip_range_broadcast',
                'ip_range_dns_server',
                'ip_range_dns_zone',
                'ip_range_dhcp_server',
                'ip_range_metadata_tags',
                'ip_range_metadata_description',
                'ip_range_metadata_specification'
            ],
            'panels' => [
                ['type' => 'journal', 'title' => 'Journal'],
                ['type' => 'attachments', 'title' => 'Attachments & Images']
            ]
        ],
        'columns' => [
            'ip_range_metadata_caption' => 'Name',
            'ip_range_metadata_status' => 'Status',
            'ip_range_ip_range' => 'IP Range',
            'ip_range_gateway' => 'Gateway',
            'ip_range_broadcast' => 'Broadcast',
            'ip_range_dns_server' => 'DNS Server',
            'ip_range_dns_zone' => 'DNS Zone',
            'ip_range_dhcp_server' => 'DHCP Server',
            'ip_range_metadata_tags' => 'Tags',
            'ip_range_metadata_description' => 'Description',
            'ip_range_metadata_specification' => 'Specification'
        ]
    ];
    $nav['vlan_details'] = [
        'default' => ['vlan_vlan', 'vlan_metadata_status', 'vlan_metadata_caption', 'vlan_metadata_tags', 'vlan_ip_range_ip_range', 'vlan_ip_range_metadata_caption'],
        'details_layout' => [
            'primary_title' => 'Core Data',
            'primary_fields' => [
                'vlan_metadata_caption',
                'vlan_metadata_status',
                'vlan_vlan',
                'vlan_ip_range_ip_range',
                'vlan_ip_range_metadata_caption',
                'vlan_metadata_tags',
                'vlan_metadata_description',
                'vlan_metadata_specification'
            ],
            'panels' => [
                ['type' => 'journal', 'title' => 'Journal'],
                ['type' => 'attachments', 'title' => 'Attachments & Images']
            ]
        ],
        'columns' => [
            'vlan_metadata_caption' => 'Name',
            'vlan_metadata_status' => 'Status',
            'vlan_vlan' => 'VLAN',
            'vlan_ip_range_ip_range' => 'IP Range',
            'vlan_ip_range_metadata_caption' => 'IP Range Name',
            'vlan_metadata_tags' => 'Tags',
            'vlan_metadata_description' => 'Description',
            'vlan_metadata_specification' => 'Specification'
        ]
    ];
    $nav['vlan_join_metadata_join_ip_range'] = [
        'uuid' => 'VLAN UUID',
        'vlan' => 'VLAN',
        'metadata_uuid_0' => 'Metadata UUID',
        'metadata_users_0' => 'Metadata User',
        'metadata_status_0' => 'Metadata Status',
        'metadata_created_0' => 'Metadata Created',
        'metadata_changed_0' => 'Metadata Changed',
        'metadata_specification_0' => 'Metadata Specification',
        'metadata_tags_0' => 'Metadata Tags',
        'metadata_caption_0' => 'Metadata Caption',
        'metadata_description_0' => 'Metadata Description',
        'ip_range_uuid_1' => 'IP-Range UUID',
        'ip_range_metadata_1' => 'IP-Range Metadata',
        'ip_range_ip_range_1' => 'IP-Range',
        'ip_range_subnet_1' => 'IP-Range Subnet',
        'ip_range_gateway_1' => 'IP-Range Gateway',
        'ip_range_broadcast_1' => 'IP-Range Broadcast',
        'ip_range_dns_server_1' => 'IP-Range DNS-Server',
        'ip_range_dns_zone_1' => 'IP-Range DNS-Zone',
        'ip_range_dhcp_server_1' => 'IP-Range DHCP-Server',
    ];
    $nav['device_join_location_join_device_model'] = [
        'type' => 'Type',
        'caption' => 'Caption',
        'location_type_0' => 'Location Type',
        'location_caption_0' => 'Location',
        'manufacturer' => 'Manufacturer',
        'model' => 'Model',
        'serial' => 'Serial',
        'hostname' => 'Hostname',
        'mac_address' => 'MAC-Address',
        'item_group' => 'Group'
    ];
    $nav['device_port_join_device'] = [
        'type' => 'Type',
        'status' => 'Status',
        'speed' => 'Speed',
        'caption' => 'Caption',
        'device_caption_0' => 'Device',
        'tags' => 'Tags'
    ];
    $nav['device_port_details'] = [
        'default' => ['device_port_metadata_status', 'device_port_metadata_caption', 'device_port_metadata_tags', 'device_port_device_location_parent_location_metadata_caption', 'device_port_device_metadata_caption', 'device_port_device_type'],
        'details_layout' => [
            'primary_title' => 'Port Core Data',
            'primary_fields' => [
                'device_port_metadata_caption',
                'device_port_metadata_status',
                'device_port_device_metadata_caption',
                'device_port_device_type',
                'device_port_device_location_parent_location_metadata_caption',
                'device_port_device_port_ip_ip',
                'device_port_speed',
                'device_port_expected_speed',
                'device_port_mac_address',
                'device_port_type',
                'device_port_poe',
                'device_port_coupling',
                'device_port_metadata_tags',
                'device_port_metadata_description',
                'device_port_metadata_specification'
            ],
            'panels' => [
                ['type' => 'journal', 'title' => 'Journal'],
                ['type' => 'attachments', 'title' => 'Attachments & Images']
            ]
        ],
        'columns' => [
            'device_port_uuid' => 'Device Port UUID',
            'device_port_metadata' => 'Device Port Metadata',
            'device_port_device' => 'Device Port Device',
            'device_port_device_port_ip' => 'Device Port IP',
            'device_port_poe' => 'Device Port PoE',
            'device_port_mac_address' => 'Device Port MAC Address',
            'device_port_speed' => 'Device Port Speed',
            'device_port_expected_speed' => 'Device Port Expected Speed',
            'device_port_type' => 'Device Port Type',
            'device_port_size' => 'Device Port Size',
            'device_port_position' => 'Device Port Position',
            'device_port_rotation' => 'Device Port Rotation',
            'device_port_coupling' => 'Device Port Coupling',
            'device_port_metadata_uuid' => 'Metadata UUID',
            'device_port_metadata_users' => 'Metadata User',
            'device_port_metadata_status' => 'Metadata Status',
            'device_port_metadata_caption' => 'Metadata Caption',
            'device_port_metadata_description' => 'Metadata Description',
            'device_port_metadata_specification' => 'Metadata Specification',
            'device_port_metadata_tags' => 'Metadata Tags',
            'device_port_metadata_created' => 'Metadata Created',
            'device_port_metadata_changed' => 'Metadata Changed',
            'device_port_device_uuid' => 'Device UUID',
            'device_port_device_metadata' => 'Device Metadata',
            'device_port_device_location' => 'Device Location',
            'device_port_device_expected_location' => 'Device Expected Location',
            'device_port_device_serial' => 'Device Serial',
            'device_port_device_asset' => 'Device Asset',
            'device_port_device_manufacturer' => 'Device Manufacturer',
            'device_port_device_model' => 'Device Model',
            'device_port_device_type' => 'Device Type',
            'device_port_device_anc' => 'Device ANC',
            'device_port_device_position' => 'Device Position',
            'device_port_device_rotation' => 'Device Rotation',
            'device_port_device_size' => 'Device Size',
            'device_port_device_item_group' => 'Device Item Group',
            'device_port_device_template' => 'Device Template',
            'device_port_device_location_parent_location_metadata_caption' => 'Room',
            'device_port_device_port_ip_ip' => 'IP Address'
        ]
    ];
    $nav['device_port_vlan_details'] = [
        'default' => ['device_port_vlan_device_port_device_metadata_caption', 'device_port_vlan_device_port_metadata_caption', 'device_port_vlan_vlan_vlan', 'device_port_vlan_expected_vlan_vlan', 'device_port_vlan_tagged', 'device_port_vlan_expected_tagged'],
        'details_layout' => [
            'primary_title' => 'Port VLAN Core Data',
            'primary_fields' => [
                'device_port_vlan_device_port_device_metadata_caption',
                'device_port_vlan_device_port_metadata_caption',
                'device_port_vlan_vlan_vlan',
                'device_port_vlan_expected_vlan_vlan',
                'device_port_vlan_tagged',
                'device_port_vlan_expected_tagged'
            ],
            'panels' => [
                ['type' => 'journal', 'title' => 'Journal']
            ]
        ],
        'columns' => [
            'device_port_vlan_uuid' => 'Port VLAN UUID',
            'device_port_vlan_device_port' => 'Device Port',
            'device_port_vlan_vlan' => 'VLAN (current)',
            'device_port_vlan_expected_vlan' => 'VLAN (expected)',
            'device_port_vlan_tagged' => 'Tagged (current)',
            'device_port_vlan_expected_tagged' => 'Tagged (expected)',
            'device_port_vlan_device_port_metadata_caption' => 'Port',
            'device_port_vlan_device_port_device_metadata_caption' => 'Device',
            'device_port_vlan_vlan_vlan' => 'VLAN ID (current)',
            'device_port_vlan_vlan_metadata_caption' => 'VLAN Caption (current)',
            'device_port_vlan_expected_vlan_vlan' => 'VLAN ID (expected)',
            'device_port_vlan_expected_vlan_metadata_caption' => 'VLAN Caption (expected)'
        ]
    ];
    $nav['device_details'] = [
        'default' => ['device_serial', 'device_asset', 'device_manufacturer', 'device_model', 'device_type', 'device_template', 'device_metadata_status', 'device_metadata_caption', 'device_metadata_tags'],
        'details_layout' => [
            'primary_title' => 'Core Data',
            'primary_fields' => [
                'device_metadata_caption',
                'device_metadata_status',
                'device_type',
                'device_manufacturer',
                'device_model',
                'device_serial',
                'device_asset',
                'device_location_metadata_caption',
                'device_item_group',
                'device_template',
                'device_metadata_tags',
                'device_metadata_description',
                'device_metadata_specification'
            ],
            'panels' => [
                ['type' => 'scripts', 'title' => 'Recent Script Executions'],
                ['type' => 'journal', 'title' => 'Journal'],
                ['type' => 'lifecycle', 'title' => 'Lifecycle'],
                ['type' => 'attachments', 'title' => 'Attachments & Images']
            ]
        ],
        'columns' => [
            'device_uuid' => 'Device UUID',
            'device_metadata_caption' => 'Name',
            'device_metadata_status' => 'Status',
            'device_metadata_tags' => 'Tags',
            'device_metadata_description' => 'Description',
            'device_metadata_specification' => 'Specification',
            'device_type' => 'Type',
            'device_manufacturer' => 'Manufacturer',
            'device_model' => 'Model',
            'device_serial' => 'Serial',
            'device_asset' => 'Asset',
            'device_location_metadata_caption' => 'Location',
            'device_item_group' => 'Item Group',
            'device_template' => 'Template'
        ]
    ];
    $nav['connection_details'] = [
        'default' => ['connection_metadata_status', 'connection_metadata_caption', 'connection_metadata_tags', 'connection_device_port_source_device_metadata_caption', 'connection_device_port_source_metadata_caption', 'connection_device_port_destination_device_metadata_caption', 'connection_device_port_destination_metadata_caption'],
        'details_layout' => [
            'primary_title' => 'Connection Core Data',
            'primary_fields' => [
                'connection_metadata_caption',
                'connection_metadata_status',
                'connection_type',
                'connection_cable_name',
                'connection_length',
                'connection_speed',
                'connection_crossover',
                'connection_device_port_source_device_metadata_caption',
                'connection_device_port_source_metadata_caption',
                'connection_device_port_destination_device_metadata_caption',
                'connection_device_port_destination_metadata_caption',
                'connection_metadata_tags',
                'connection_metadata_description',
                'connection_metadata_specification'
            ],
            'panels' => [
                ['type' => 'journal', 'title' => 'Journal'],
                ['type' => 'attachments', 'title' => 'Attachments & Images']
            ]
        ],
        'columns' => [
            'connection_metadata_caption' => 'Name',
            'connection_metadata_status' => 'Status',
            'connection_type' => 'Type',
            'connection_cable_name' => 'Cable Name',
            'connection_length' => 'Length',
            'connection_speed' => 'Speed',
            'connection_crossover' => 'Crossover',
            'connection_device_port_source_device_metadata_caption' => 'Source Device',
            'connection_device_port_source_metadata_caption' => 'Source Port',
            'connection_device_port_destination_device_metadata_caption' => 'Destination Device',
            'connection_device_port_destination_metadata_caption' => 'Destination Port',
            'connection_metadata_tags' => 'Tags',
            'connection_metadata_description' => 'Description',
            'connection_metadata_specification' => 'Specification'
        ]
    ];
    $nav['connection_join_device_port_join_device_port'] = [
        'type' => 'Type',
        'status' => 'Status',
        'speed' => 'Speed',
        'caption' => 'Caption',
        'device_port_caption_0' => 'Source',
        'device_port_caption_1' => 'Destination',
        'length' => 'Length'
    ];
    require_once __DIR__ . '/../core/table_columns.php';
    foreach ($nav as $__pf_table => &$__pf_cfg) {
        if (is_array($__pf_cfg) && isset($__pf_cfg['columns']) && is_array($__pf_cfg['columns'])) {
            $__pf_cfg['picker_columns'] = \Portflow\Core\TableColumns::pickerKeys($__pf_cfg['columns'], $__pf_cfg);
        }
    }
    unset($__pf_cfg);
    echo json_encode($nav);

} else {

    function lang_en() {
        $lang = array();

        $lang['lang'] = 'English';
        $lang['portflow'] = 'Portflow';
        $lang['itam'] = 'ITAM';
        $lang['automation'] = 'Automation';
        $lang['portview'] = 'Portview';
        $lang['reports'] = 'Reports';
        $lang['login'] = 'Login';
        $lang['logout'] = 'Logout';
        $lang['register'] = 'Register';
        $lang['settings'] = 'Settings';
        $lang['account'] = 'Account';
        $lang['appearance'] = 'Appearance';
        $lang['notifications'] = 'Notifications';
        $lang['configuration'] = 'Configuration';
        $lang['scripts'] = 'Scripts';
        $lang['access_management'] = 'Access Management';
        $lang['search'] = 'Search';
        $lang['it asset-management'] = 'IT Asset-Management';
        $lang['location'] = 'Standort';
        $lang['ipam'] = 'IPAM';
        $lang['vlan'] = 'VLAN';
        $lang['devices'] = 'Devices';
        $lang['device ports'] = 'Device Ports';
        $lang['port vlans'] = 'Port VLANs';
        $lang['connections'] = 'Connections';
        $lang['quantity'] = 'Quantity';
        $lang['save'] = 'Save';
        $lang['cancel'] = 'Cancel';
        $lang['columns_customize'] = 'Customize columns';
        $lang['columns_hint'] = 'Toggle columns and drag them to reorder.';
        $lang['columns_reset'] = 'Reset to default';
        $lang['columns_save_failed'] = 'Saving failed';
        $lang['columns_not_available'] = 'Column configuration not available.';
        $lang['transfer_csv'] = 'CSV import/export';
        $lang['transfer_hint'] = 'Accepted and required fields for the current view.';
        $lang['transfer_field'] = 'Field';
        $lang['transfer_label'] = 'Label';
        $lang['transfer_required'] = 'Required';
        $lang['transfer_type'] = 'Type';
        $lang['transfer_notes'] = 'Notes';
        $lang['transfer_sample_title'] = 'Sample file';
        $lang['transfer_download_sample'] = 'Download sample CSV';
        $lang['transfer_sample_ready'] = 'Sample CSV downloaded.';
        $lang['transfer_export_title'] = 'Export';
        $lang['transfer_export_csv'] = 'Export CSV';
        $lang['transfer_export_ready'] = 'CSV export downloaded.';
        $lang['transfer_import_title'] = 'Import';
        $lang['transfer_choose_file'] = 'Choose CSV file';
        $lang['transfer_no_file'] = 'No file selected';
        $lang['transfer_import_csv'] = 'Import CSV';
        $lang['transfer_not_available'] = 'Import/export is not available for this view.';
        $lang['transfer_import_empty'] = 'The CSV file is empty.';
        $lang['transfer_import_unknown'] = 'Unknown CSV columns';
        $lang['transfer_import_missing_required'] = 'Missing required columns';
        $lang['transfer_import_progress'] = 'Import row';
        $lang['transfer_import_done'] = 'Import completed';
        $lang['transfer_import_skipped'] = 'skipped';
        $lang['transfer_duplicate_title'] = 'Duplicate caption found';
        $lang['transfer_duplicate_copy'] = 'An entry with this caption already exists';
        $lang['transfer_duplicate_apply_all'] = 'Remember decision for this caption';
        $lang['transfer_duplicate_keep'] = 'Keep existing entry';
        $lang['transfer_duplicate_replace'] = 'Overwrite existing entry';
        $lang['transfer_duplicate_create'] = 'Create additional new entry';
        $lang['snmp_reports_title'] = 'SNMP Reports';
        $lang['snmp_reports_subtitle'] = 'Discovery status, drift, and scan history.';
        $lang['snmp_button_inventory'] = 'Inventory';
        $lang['snmp_tab_drift'] = 'Drift';
        $lang['snmp_tab_unknown_ports'] = 'Unknown Ports';
        $lang['snmp_tab_stale_ports'] = 'Stale Ports';
        $lang['snmp_tab_nodes'] = 'Nodes';
        $lang['snmp_tab_topology'] = 'Topology';
        $lang['snmp_tab_scan_runs'] = 'Scan Runs';
        $lang['snmp_filter_all_switches'] = 'All switches';
        $lang['snmp_filter_all_drift_classes'] = 'All drift classes';
        $lang['snmp_filter_all_severities'] = 'All severities';
        $lang['snmp_severity_warn'] = 'Warn';
        $lang['snmp_severity_info'] = 'Info';
        $lang['snmp_status_open'] = 'Open';
        $lang['snmp_status_ignored'] = 'Ignored';
        $lang['snmp_status_ok'] = 'OK';
        $lang['snmp_status_all'] = 'All';
        $lang['snmp_status_drift'] = 'Drift';
        $lang['snmp_button_filter'] = 'Filter';
        $lang['snmp_button_reset'] = 'Reset';
        $lang['snmp_badge_temp_ignored'] = 'Temporarily ignored';
        $lang['snmp_button_show_ignored'] = 'Show ignored';
        $lang['snmp_button_reset_ignore'] = 'Reset ignore';
        $lang['snmp_button_align_l2'] = 'Align L2';
        $lang['snmp_button_align_neighbor'] = 'Align neighbor';
        $lang['snmp_button_fix_pvid'] = 'Fix PVID';
        $lang['snmp_button_fix_trunk'] = 'Fix trunk';
        $lang['snmp_button_align_expected'] = 'Align expected';
        $lang['snmp_button_fix'] = 'Fix';
        $lang['snmp_button_ignore'] = 'Ignore';
        $lang['snmp_button_unignore'] = 'Show';
        $lang['snmp_empty_drift'] = 'No SNMP data available yet. Run "Scan now" from the switch inventory.';
        $lang['snmp_drift_oper_down'] = 'Oper down';
        $lang['snmp_drift_name_mismatch'] = 'Name';
        $lang['snmp_drift_speed_mismatch'] = 'Speed';
        $lang['snmp_drift_pvid_mismatch'] = 'PVID';
        $lang['snmp_drift_tagged_vlan_mismatch'] = 'Tagged VLANs';
        $lang['snmp_drift_orphaned_port'] = 'Orphaned';
        $lang['snmp_drift_ip_mismatch'] = 'IP';
        $lang['snmp_drift_ip_missing'] = 'Missing IP';
        $lang['snmp_drift_hostname_mismatch'] = 'Hostname';
        $lang['snmp_drift_dhcp_mismatch'] = 'DHCP';
        $lang['snmp_drift_neighbor_mismatch'] = 'Neighbor';
        $lang['snmp_drift_unexpected_neighbor'] = 'Unexpected neighbor';
        $lang['snmp_drift_neighbor_missing'] = 'Missing neighbor';
        $lang['snmp_drift_missing_scan_state'] = 'No SNMP state';

        return $lang;
    }
    $lang = lang_en();
}
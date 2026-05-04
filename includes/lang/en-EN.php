<?php
if (isset($_GET['nav'])) {
    $nav = [];
    $nav['location_details'] = [
        'default' => ['location_type', 'location_parent_location_metadata_caption', 'location_metadata_tags'],
        'blocked' => ['location_parent_location', 'location_parent_location_parent_location'],
        'unblocked' => [],
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
            'location_uuid' => 'Location UUID',
            'location_metadata' => 'Location Metadata',
            'location_parent_location' => 'Parent Location',
            'location_metadata_caption' => 'Name',
            'location_metadata_status' => 'Status',
            'location_type' => 'Location Type',
            'location_parent_location_metadata_caption' => 'Parent Location',
            'location_metadata_tags' => 'Tags',
            'location_metadata_description' => 'Description',
            'location_metadata_specification' => 'Specification',
            'location_position' => 'Location Position',
            'location_rotation' => 'Location Rotation',
            'location_size' => 'Location Size',
            'location_metadata_uuid' => 'Metadata UUID',
            'location_metadata_users' => 'User',
            'location_metadata_created' => 'Created',
            'location_metadata_changed' => 'Changed',
            'location_parent_location_uuid' => 'Parent Location UUID',
            'location_parent_location_metadata' => 'Parent Location Metadata',
            'location_parent_location_parent_location' => 'Parent of Parent Location',
            'location_parent_location_type' => 'Parent Location Type',
            'location_parent_location_position' => 'Parent Location Position',
            'location_parent_location_rotation' => 'Parent Location Rotation',
            'location_parent_location_size' => 'Parent Location Size',
            'location_parent_location_metadata_uuid' => 'Parent Location Metadata UUID',
            'location_parent_location_metadata_users' => 'Parent Location User',
            'location_parent_location_metadata_status' => 'Parent Location Status',
            'location_parent_location_metadata_description' => 'Parent Location Description',
            'location_parent_location_metadata_specification' => 'Parent Location Specification',
            'location_parent_location_metadata_tags' => 'Parent Location Tags',
            'location_parent_location_metadata_created' => 'Parent Location Created',
            'location_parent_location_metadata_changed' => 'Parent Location Changed'
        ]
    ];
    $nav['ip_range_join_metadata'] = [
        'default' => ['ip_range_metadata_status', 'ip_range_metadata_caption', 'ip_range_ip_range', 'ip_range_gateway', 'ip_range_dns_zone', 'ip_range_metadata_tags'],
        'blocked' => [],
        'unblocked' => [],
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
            'ip_range_uuid' => 'IP Range UUID',
            'ip_range_metadata' => 'IP Range Metadata',
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
            'ip_range_metadata_specification' => 'Specification',
            'ip_range_metadata_uuid' => 'Metadata UUID',
            'ip_range_metadata_users' => 'User',
            'ip_range_metadata_created' => 'Created',
            'ip_range_metadata_changed' => 'Changed'
        ]
    ];
    $nav['vlan_details'] = [
        'default' => ['vlan_vlan', 'vlan_metadata_status', 'vlan_metadata_caption', 'vlan_metadata_tags', 'vlan_ip_range_ip_range', 'vlan_ip_range_metadata_caption'],
        'blocked' => ['vlan_ip_range'],
        'unblocked' => [],
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
            'vlan_uuid' => 'VLAN UUID',
            'vlan_metadata' => 'VLAN Metadata',
            'vlan_ip_range' => 'VLAN IP Range',
            'vlan_metadata_caption' => 'Name',
            'vlan_metadata_status' => 'Status',
            'vlan_vlan' => 'VLAN',
            'vlan_ip_range_ip_range' => 'IP Range',
            'vlan_ip_range_metadata_caption' => 'IP Range Name',
            'vlan_metadata_tags' => 'Tags',
            'vlan_metadata_description' => 'Description',
            'vlan_metadata_specification' => 'Specification',
            'vlan_metadata_uuid' => 'Metadata UUID',
            'vlan_metadata_users' => 'User',
            'vlan_metadata_created' => 'Created',
            'vlan_metadata_changed' => 'Changed',
            'vlan_ip_range_uuid' => 'IP Range UUID',
            'vlan_ip_range_metadata' => 'IP Range Metadata',
            'vlan_ip_range_gateway' => 'IP Range Gateway',
            'vlan_ip_range_broadcast' => 'IP Range Broadcast',
            'vlan_ip_range_dns_server' => 'IP Range DNS Server',
            'vlan_ip_range_dns_zone' => 'IP Range DNS Zone',
            'vlan_ip_range_dhcp_server' => 'IP Range DHCP Server',
            'vlan_ip_range_metadata_uuid' => 'IP Range Metadata UUID',
            'vlan_ip_range_metadata_users' => 'IP Range User',
            'vlan_ip_range_metadata_status' => 'IP Range Status',
            'vlan_ip_range_metadata_description' => 'IP Range Description',
            'vlan_ip_range_metadata_specification' => 'IP Range Specification',
            'vlan_ip_range_metadata_tags' => 'IP Range Tags',
            'vlan_ip_range_metadata_created' => 'IP Range Created',
            'vlan_ip_range_metadata_changed' => 'IP Range Changed'
        ]
    ];
    $nav['device_port_details'] = [
        'default' => ['device_port_metadata_status', 'device_port_metadata_caption', 'device_port_metadata_tags', 'device_port_device_location_metadata_caption', 'device_port_device_metadata_caption', 'device_port_device_type'],
        'blocked' => [],
        'unblocked' => [],
        'details_layout' => [
            'primary_title' => 'Port Core Data',
            'primary_fields' => [
                'device_port_metadata_caption',
                'device_port_metadata_status',
                'device_port_device_metadata_caption',
                'device_port_device_type',
                'device_port_device_location_metadata_caption',
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
            'device_port_device_metadata_uuid' => 'Device Metadata UUID',
            'device_port_device_metadata_users' => 'Device Metadata User',
            'device_port_device_metadata_status' => 'Device Metadata Status',
            'device_port_device_metadata_caption' => 'Device Metadata Caption',
            'device_port_device_metadata_description' => 'Device Metadata Description',
            'device_port_device_metadata_specification' => 'Device Metadata Specification',
            'device_port_device_metadata_tags' => 'Device Metadata Tags',
            'device_port_device_metadata_created' => 'Device Metadata Created',
            'device_port_device_metadata_changed' => 'Device Metadata Changed',
            'device_port_device_location_metadata_caption' => 'Room',
            'device_port_device_port_ip_uuid' => 'Device Port IP UUID',
            'device_port_device_port_ip_ip' => 'IP Address',
            'device_port_device_port_ip_expected_ip' => 'Expected IP Address',
            'device_port_device_port_ip_hostname' => 'Hostname',
            'device_port_device_port_ip_expected_hostname' => 'Expected Hostname',
            'device_port_device_port_ip_dhcp_address' => 'DHCP Address',
            'device_port_device_port_ip_expected_dhcp_address' => 'Expected DHCP Address'
        ]
    ];
    $nav['device_port_vlan_details'] = [
        'default' => ['device_port_vlan_device_port_device_metadata_caption', 'device_port_vlan_device_port_metadata_caption', 'device_port_vlan_vlan_vlan', 'device_port_vlan_expected_vlan_vlan', 'device_port_vlan_tagged', 'device_port_vlan_expected_tagged'],
        'blocked' => [],
        'unblocked' => [],
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
        'default' => ['device_metadata_status', 'device_metadata_caption', 'device_location_metadata_caption', 'device_manufacturer', 'device_model', 'device_type', 'device_template', 'device_metadata_tags'],
        'blocked' => ['device_location', 'device_expected_location', 'device_location_parent_location', 'device_expected_location_parent_location'],
        'unblocked' => [],
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
            'device_metadata' => 'Device Metadata',
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
            'device_location' => 'Location',
            'device_expected_location' => 'Expected Location',
            'device_anc' => 'ANC',
            'device_position' => 'Device Position',
            'device_rotation' => 'Device Rotation',
            'device_size' => 'Device Size',
            'device_location_metadata_caption' => 'Location',
            'device_item_group' => 'Item Group',
            'device_template' => 'Template',
            'device_metadata_uuid' => 'Metadata UUID',
            'device_metadata_users' => 'User',
            'device_metadata_created' => 'Created',
            'device_metadata_changed' => 'Changed',
            'device_location_uuid' => 'Location UUID',
            'device_location_metadata' => 'Location Metadata',
            'device_location_parent_location' => 'Parent Location',
            'device_location_type' => 'Location Type',
            'device_location_position' => 'Location Position',
            'device_location_rotation' => 'Location Rotation',
            'device_location_size' => 'Location Size',
            'device_location_metadata_uuid' => 'Location Metadata UUID',
            'device_location_metadata_users' => 'Location User',
            'device_location_metadata_status' => 'Location Status',
            'device_location_metadata_description' => 'Location Description',
            'device_location_metadata_specification' => 'Location Specification',
            'device_location_metadata_tags' => 'Location Tags',
            'device_location_metadata_created' => 'Location Created',
            'device_location_metadata_changed' => 'Location Changed',
            'device_expected_location_uuid' => 'Expected Location UUID',
            'device_expected_location_metadata' => 'Expected Location Metadata',
            'device_expected_location_parent_location' => 'Expected Parent Location',
            'device_expected_location_type' => 'Expected Location Type',
            'device_expected_location_position' => 'Expected Location Position',
            'device_expected_location_rotation' => 'Expected Location Rotation',
            'device_expected_location_size' => 'Expected Location Size',
            'device_expected_location_metadata_uuid' => 'Expected Location Metadata UUID',
            'device_expected_location_metadata_users' => 'Expected Location User',
            'device_expected_location_metadata_status' => 'Expected Location Status',
            'device_expected_location_metadata_caption' => 'Expected Location Name',
            'device_expected_location_metadata_description' => 'Expected Location Description',
            'device_expected_location_metadata_specification' => 'Expected Location Specification',
            'device_expected_location_metadata_tags' => 'Expected Location Tags',
            'device_expected_location_metadata_created' => 'Expected Location Created',
            'device_expected_location_metadata_changed' => 'Expected Location Changed'
        ]
    ];
    $nav['connection_details'] = [
        'default' => ['connection_metadata_status', 'connection_metadata_caption', 'connection_metadata_tags', 'connection_device_port_source_device_metadata_caption', 'connection_device_port_source_metadata_caption', 'connection_device_port_destination_device_metadata_caption', 'connection_device_port_destination_metadata_caption'],
        'blocked' => [],
        'unblocked' => [],
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
            'connection_uuid' => 'Connection UUID',
            'connection_metadata' => 'Connection Metadata',
            'connection_metadata_caption' => 'Name',
            'connection_metadata_status' => 'Status',
            'connection_device_port_source' => 'Source Port',
            'connection_expected_device_port_source' => 'Expected Source Port',
            'connection_device_port_destination' => 'Destination Port',
            'connection_expected_device_port_destination' => 'Expected Destination Port',
            'connection_type' => 'Type',
            'connection_cable_name' => 'Cable Name',
            'connection_length' => 'Length',
            'connection_speed' => 'Speed',
            'connection_crossover' => 'Crossover',
            'connection_item_group' => 'Connection Item Group',
            'connection_metadata_uuid' => 'Metadata UUID',
            'connection_metadata_users' => 'Metadata User',
            'connection_device_port_source_device_metadata_caption' => 'Source Device',
            'connection_device_port_source_metadata_caption' => 'Source Port',
            'connection_device_port_destination_device_metadata_caption' => 'Destination Device',
            'connection_device_port_destination_metadata_caption' => 'Destination Port',
            'connection_metadata_tags' => 'Tags',
            'connection_metadata_description' => 'Description',
            'connection_metadata_specification' => 'Specification',
            'connection_metadata_created' => 'Metadata Created',
            'connection_metadata_changed' => 'Metadata Changed',
            'connection_device_port_source_uuid' => 'Source Port UUID',
            'connection_device_port_source_metadata' => 'Source Port Metadata',
            'connection_device_port_source_device' => 'Source Port Device',
            'connection_device_port_source_device_port_vlan' => 'Source Port VLAN',
            'connection_device_port_source_device_port_ip' => 'Source Port IP',
            'connection_device_port_source_poe' => 'Source Port PoE',
            'connection_device_port_source_mac_address' => 'Source Port MAC Address',
            'connection_device_port_source_speed' => 'Source Port Speed',
            'connection_device_port_source_expected_speed' => 'Expected Source Port Speed',
            'connection_device_port_source_type' => 'Source Port Type',
            'connection_device_port_source_size' => 'Source Port Size',
            'connection_device_port_source_position' => 'Source Port Position',
            'connection_device_port_source_rotation' => 'Source Port Rotation',
            'connection_device_port_source_coupling' => 'Source Port Coupling',
            'connection_device_port_source_metadata_uuid' => 'Source Port Metadata UUID',
            'connection_device_port_source_metadata_users' => 'Source Port Metadata User',
            'connection_device_port_source_metadata_status' => 'Source Port Metadata Status',
            'connection_device_port_source_metadata_description' => 'Source Port Metadata Description',
            'connection_device_port_source_metadata_specification' => 'Source Port Metadata Specification',
            'connection_device_port_source_metadata_tags' => 'Source Port Metadata Tags',
            'connection_device_port_source_metadata_created' => 'Source Port Metadata Created',
            'connection_device_port_source_metadata_changed' => 'Source Port Metadata Changed',
            'connection_device_port_source_device_uuid' => 'Source Device UUID',
            'connection_device_port_source_device_metadata' => 'Source Device Metadata',
            'connection_device_port_source_device_location' => 'Source Device Location',
            'connection_device_port_source_device_expected_location' => 'Expected Source Device Location',
            'connection_device_port_source_device_serial' => 'Source Device Serial',
            'connection_device_port_source_device_asset' => 'Source Device Asset',
            'connection_device_port_source_device_manufacturer' => 'Source Device Manufacturer',
            'connection_device_port_source_device_model' => 'Source Device Model',
            'connection_device_port_source_device_type' => 'Source Device Type',
            'connection_device_port_source_device_anc' => 'Source Device ANC',
            'connection_device_port_source_device_position' => 'Source Device Position',
            'connection_device_port_source_device_rotation' => 'Source Device Rotation',
            'connection_device_port_source_device_size' => 'Source Device Size',
            'connection_device_port_source_device_item_group' => 'Source Device Item Group',
            'connection_device_port_source_device_template' => 'Source Device Template',
            'connection_device_port_source_device_metadata_uuid' => 'Source Device Metadata UUID',
            'connection_device_port_source_device_metadata_users' => 'Source Device Metadata User',
            'connection_device_port_source_device_metadata_status' => 'Source Device Metadata Status',
            'connection_device_port_source_device_metadata_description' => 'Source Device Metadata Description',
            'connection_device_port_source_device_metadata_specification' => 'Source Device Metadata Specification',
            'connection_device_port_source_device_metadata_tags' => 'Source Device Metadata Tags',
            'connection_device_port_source_device_metadata_created' => 'Source Device Metadata Created',
            'connection_device_port_source_device_metadata_changed' => 'Source Device Metadata Changed',
            'connection_device_port_source_device_location_uuid' => 'Source Device Location UUID',
            'connection_device_port_source_device_location_metadata' => 'Source Device Location Metadata',
            'connection_device_port_source_device_location_parent_location' => 'Source Device Parent Location',
            'connection_device_port_source_device_location_type' => 'Source Device Location Type',
            'connection_device_port_source_device_location_position' => 'Source Device Location Position',
            'connection_device_port_source_device_location_rotation' => 'Source Device Location Rotation',
            'connection_device_port_source_device_location_size' => 'Source Device Location Size',
            'connection_device_port_source_device_location_metadata_uuid' => 'Source Device Location Metadata UUID',
            'connection_device_port_source_device_location_metadata_users' => 'Source Device Location Metadata User',
            'connection_device_port_source_device_location_metadata_status' => 'Source Device Location Metadata Status',
            'connection_device_port_source_device_location_metadata_caption' => 'Source Device Location Name',
            'connection_device_port_source_device_location_metadata_description' => 'Source Device Location Description',
            'connection_device_port_source_device_location_metadata_specification' => 'Source Device Location Specification',
            'connection_device_port_source_device_location_metadata_tags' => 'Source Device Location Tags',
            'connection_device_port_source_device_location_metadata_created' => 'Source Device Location Created',
            'connection_device_port_source_device_location_metadata_changed' => 'Source Device Location Changed',
            'connection_device_port_destination_uuid' => 'Destination Port UUID',
            'connection_device_port_destination_metadata' => 'Destination Port Metadata',
            'connection_device_port_destination_device' => 'Destination Port Device',
            'connection_device_port_destination_device_port_vlan' => 'Destination Port VLAN',
            'connection_device_port_destination_device_port_ip' => 'Destination Port IP',
            'connection_device_port_destination_poe' => 'Destination Port PoE',
            'connection_device_port_destination_mac_address' => 'Destination Port MAC Address',
            'connection_device_port_destination_speed' => 'Destination Port Speed',
            'connection_device_port_destination_expected_speed' => 'Expected Destination Port Speed',
            'connection_device_port_destination_type' => 'Destination Port Type',
            'connection_device_port_destination_size' => 'Destination Port Size',
            'connection_device_port_destination_position' => 'Destination Port Position',
            'connection_device_port_destination_rotation' => 'Destination Port Rotation',
            'connection_device_port_destination_coupling' => 'Destination Port Coupling',
            'connection_device_port_destination_metadata_uuid' => 'Destination Port Metadata UUID',
            'connection_device_port_destination_metadata_users' => 'Destination Port Metadata User',
            'connection_device_port_destination_metadata_status' => 'Destination Port Metadata Status',
            'connection_device_port_destination_metadata_description' => 'Destination Port Metadata Description',
            'connection_device_port_destination_metadata_specification' => 'Destination Port Metadata Specification',
            'connection_device_port_destination_metadata_tags' => 'Destination Port Metadata Tags',
            'connection_device_port_destination_metadata_created' => 'Destination Port Metadata Created',
            'connection_device_port_destination_metadata_changed' => 'Destination Port Metadata Changed',
            'connection_device_port_destination_device_uuid' => 'Destination Device UUID',
            'connection_device_port_destination_device_metadata' => 'Destination Device Metadata',
            'connection_device_port_destination_device_location' => 'Destination Device Location',
            'connection_device_port_destination_device_expected_location' => 'Expected Destination Device Location',
            'connection_device_port_destination_device_serial' => 'Destination Device Serial',
            'connection_device_port_destination_device_asset' => 'Destination Device Asset',
            'connection_device_port_destination_device_manufacturer' => 'Destination Device Manufacturer',
            'connection_device_port_destination_device_model' => 'Destination Device Model',
            'connection_device_port_destination_device_type' => 'Destination Device Type',
            'connection_device_port_destination_device_anc' => 'Destination Device ANC',
            'connection_device_port_destination_device_position' => 'Destination Device Position',
            'connection_device_port_destination_device_rotation' => 'Destination Device Rotation',
            'connection_device_port_destination_device_size' => 'Destination Device Size',
            'connection_device_port_destination_device_item_group' => 'Destination Device Item Group',
            'connection_device_port_destination_device_template' => 'Destination Device Template',
            'connection_device_port_destination_device_metadata_uuid' => 'Destination Device Metadata UUID',
            'connection_device_port_destination_device_metadata_users' => 'Destination Device Metadata User',
            'connection_device_port_destination_device_metadata_status' => 'Destination Device Metadata Status',
            'connection_device_port_destination_device_metadata_description' => 'Destination Device Metadata Description',
            'connection_device_port_destination_device_metadata_specification' => 'Destination Device Metadata Specification',
            'connection_device_port_destination_device_metadata_tags' => 'Destination Device Metadata Tags',
            'connection_device_port_destination_device_metadata_created' => 'Destination Device Metadata Created',
            'connection_device_port_destination_device_metadata_changed' => 'Destination Device Metadata Changed',
            'connection_device_port_destination_device_location_uuid' => 'Destination Device Location UUID',
            'connection_device_port_destination_device_location_metadata' => 'Destination Device Location Metadata',
            'connection_device_port_destination_device_location_parent_location' => 'Destination Device Parent Location',
            'connection_device_port_destination_device_location_type' => 'Destination Device Location Type',
            'connection_device_port_destination_device_location_position' => 'Destination Device Location Position',
            'connection_device_port_destination_device_location_rotation' => 'Destination Device Location Rotation',
            'connection_device_port_destination_device_location_size' => 'Destination Device Location Size',
            'connection_device_port_destination_device_location_metadata_uuid' => 'Destination Device Location Metadata UUID',
            'connection_device_port_destination_device_location_metadata_users' => 'Destination Device Location Metadata User',
            'connection_device_port_destination_device_location_metadata_status' => 'Destination Device Location Metadata Status',
            'connection_device_port_destination_device_location_metadata_caption' => 'Destination Device Location Name',
            'connection_device_port_destination_device_location_metadata_description' => 'Destination Device Location Description',
            'connection_device_port_destination_device_location_metadata_specification' => 'Destination Device Location Specification',
            'connection_device_port_destination_device_location_metadata_tags' => 'Destination Device Location Tags',
            'connection_device_port_destination_device_location_metadata_created' => 'Destination Device Location Created',
            'connection_device_port_destination_device_location_metadata_changed' => 'Destination Device Location Changed'
        ]
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
        $lang['portview_page_title'] = 'Port View';
        $lang['portview_page_subtitle'] = 'Compact port overview from switch to endpoint';
        $lang['portview_result_summary'] = 'Showing {start}-{end} of {total}';
        $lang['portview_filters_hint'] = 'Filter port chains by room, endpoint, or VLAN.';
        $lang['portview_reset_filters'] = 'Reset filters';
        $lang['portview_filter_room'] = 'Room';
        $lang['portview_all_rooms'] = 'All rooms';
        $lang['portview_filter_endpoint_type'] = 'Endpoint type';
        $lang['portview_all_endpoint_types'] = 'All endpoint types';
        $lang['portview_filter_vlan'] = 'VLAN';
        $lang['portview_all_vlans'] = 'All VLANs';
        $lang['portview_detail_title'] = 'Detail view';
        $lang['portview_detail_subtitle'] = 'Connection and involved devices';
        $lang['portview_no_details'] = 'No details available.';
        $lang['portview_trace_title'] = 'Cable trace';
        $lang['portview_trace_subtitle'] = 'Tracks the current connection path using the available trace endpoint.';
        $lang['portview_reload_trace'] = 'Reload';
        $lang['portview_trace_loading'] = 'Loading cable trace ...';
        $lang['portview_connection_add_title'] = 'Add connection';
        $lang['portview_source_port'] = 'Source port';
        $lang['portview_source_port_placeholder'] = 'Search source port ...';
        $lang['portview_destination_port'] = 'Destination port';
        $lang['portview_destination_port_placeholder'] = 'Search destination port ...';
        $lang['portview_cable'] = 'Cable';
        $lang['portview_optional'] = 'optional';
        $lang['portview_speed'] = 'Speed';
        $lang['portview_speed_placeholder'] = 'e.g. 1000';
        $lang['portview_length'] = 'Length';
        $lang['portview_length_placeholder'] = 'e.g. 12.5';
        $lang['portview_crossover'] = 'Crossover';
        $lang['portview_type'] = 'Type';
        $lang['portview_type_placeholder'] = 'e.g. copper, fiber, trunk';
        $lang['portview_add_connection'] = 'Add connection';
        $lang['portview_edit_subtitle'] = 'Search rooms, devices, and ports, then save changes precisely.';
        $lang['portview_connection_cable'] = 'Cable / Connection';
        $lang['portview_delete_connection'] = 'Delete connection';
        $lang['portview_delete_blocked'] = 'Patch panel to outlet is core cabling and cannot be deleted here.';
        $lang['portview_source_room'] = 'Source room';
        $lang['portview_destination_room'] = 'Destination room';
        $lang['portview_source_device'] = 'Source device';
        $lang['portview_destination_device'] = 'Destination device';
        $lang['portview_source_port_caption'] = 'Source port caption';
        $lang['portview_destination_port_caption'] = 'Destination port caption';
        $lang['portview_source_hostname'] = 'Source hostname';
        $lang['portview_destination_hostname'] = 'Destination hostname';
        $lang['portview_source_mac'] = 'Source MAC';
        $lang['portview_destination_mac'] = 'Destination MAC';
        $lang['portview_source_label'] = 'Source';
        $lang['portview_destination_label'] = 'Destination';
        $lang['portview_status_label'] = 'Status';
        $lang['portview_tagged_label'] = 'VLAN tagged';
        $lang['portview_untagged_label'] = 'VLAN untagged';
        $lang['portview_trace_error'] = 'Error';
        $lang['portview_no_connected_ports'] = 'No connected ports.';
        $lang['portview_trace_start'] = 'Start';
        $lang['portview_trace_no_further'] = 'No further connections from this point.';
        $lang['portview_trace_path'] = 'Path {index}';
        $lang['portview_trace_endpoint'] = 'Endpoint';
        $lang['portview_trace_note'] = 'Note';
        $lang['portview_trace_location'] = 'Room';
        $lang['portview_trace_port'] = 'Port';
        $lang['portview_trace_device_fallback'] = 'Device';
        $lang['portview_trace_ip'] = 'IP';
        $lang['portview_trace_cable_fallback'] = 'Cable';
        $lang['portview_update_failed'] = 'Update failed';
        $lang['portview_create_failed'] = 'Creation failed';
        $lang['portview_fetch_failed'] = 'Fetch failed';
        $lang['portview_full_port_search_failed'] = 'Failed to load complete port search index';
        $lang['portview_no_connection_to_save'] = 'No connection found to save.';
        $lang['portview_saving_changes'] = 'Saving changes ...';
        $lang['portview_changes_saved'] = 'Changes saved.';
        $lang['portview_save_failed'] = 'Saving failed.';
        $lang['portview_select_source_destination'] = 'Please select source and destination via port search.';
        $lang['portview_identical_ports'] = 'Source and destination must not be identical.';
        $lang['portview_creating_connection'] = 'Creating new connection ...';
        $lang['portview_create_metadata_failed'] = 'Metadata for the new connection could not be created';
        $lang['portview_connection_added'] = 'Connection added.';
        $lang['portview_connection_add_failed'] = 'Connection could not be added.';
        $lang['portview_delete_missing'] = 'Connection not found for deletion.';
        $lang['portview_delete_confirm'] = 'Do you want to permanently delete this connection?';
        $lang['portview_deleting_connection'] = 'Deleting connection ...';
        $lang['portview_connection_deleted'] = 'Connection deleted.';
        $lang['portview_delete_failed'] = 'Connection could not be deleted.';
        $lang['portview_no_search_hits'] = 'No matches';
        $lang['portview_clear_field'] = 'Clear field';
        $lang['portview_no_entries'] = 'No entries found';
        $lang['portview_new_connection_caption'] = 'Portview connection';
        $lang['portview_new_connection_description'] = 'Created via Portview';
        $lang['portview_picker_kind_location'] = 'Location';
        $lang['portview_picker_kind_device'] = 'Device';
        $lang['portview_picker_kind_port'] = 'Port';
        $lang['portview_column_status'] = 'Status';
        $lang['portview_column_switch'] = 'Switch';
        $lang['portview_column_switch_port'] = 'Switch Port';
        $lang['portview_column_patchpanel'] = 'Patch Panel';
        $lang['portview_column_patchpanel_port'] = 'PP Port';
        $lang['portview_column_cable'] = 'Cable';
        $lang['portview_column_room'] = 'Room';
        $lang['portview_column_wallplate'] = 'Wall Plate';
        $lang['portview_column_wallplate_port'] = 'WP Port';
        $lang['portview_column_endpoint'] = 'Endpoint';
        $lang['portview_column_endpoint_port'] = 'EP Port';
        $lang['portview_column_hostname'] = 'Hostname';
        $lang['portview_column_mac'] = 'MAC';
        $lang['portview_column_vlan_tagged'] = 'VLAN (tagged)';
        $lang['portview_column_vlan_untagged'] = 'VLAN (untagged)';
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
        $lang['it asset-management note'] = 'Manage locations, devices, ports, and connections';
        $lang['location'] = 'Locations';
        $lang['ipam'] = 'IPAM';
        $lang['vlan'] = 'VLAN';
        $lang['devices'] = 'Devices';
        $lang['device ports'] = 'Device Ports';
        $lang['port vlans'] = 'Port VLANs';
        $lang['connections'] = 'Connections';
        $lang['quantity'] = 'Quantity';
        $lang['datasets'] = 'Datasets';
        $lang['save'] = 'Save';
        $lang['cancel'] = 'Cancel';
        $lang['button_delete'] = 'Delete';
        $lang['button_edit'] = 'Edit';
        $lang['button_upload'] = 'Upload';
        $lang['confirm_delete_entry'] = 'Do you really want to delete this entry and all related data?';
        $lang['confirm_delete_file'] = 'Do you really want to delete this file?';
        $lang['confirm_delete_journal_entry'] = 'Do you really want to delete this journal entry?';
        $lang['confirm_delete_lifecycle_entry'] = 'Do you really want to delete this lifecycle entry?';
        $lang['error_file_delete_failed'] = 'Failed to delete file.';
        $lang['error_file_update_failed'] = 'Failed to update attachment.';
        $lang['error_file_upload_failed'] = 'File could not be uploaded.';
        $lang['error_journal_create_failed'] = 'Journal entry could not be created.';
        $lang['error_journal_delete_failed'] = 'Failed to delete journal entry.';
        $lang['error_journal_update_failed'] = 'Failed to update journal entry.';
        $lang['error_lifecycle_create_failed'] = 'Lifecycle entry could not be created.';
        $lang['error_lifecycle_delete_failed'] = 'Failed to delete lifecycle entry.';
        $lang['error_lifecycle_metadata_create_failed'] = 'Lifecycle metadata could not be created.';
        $lang['error_lifecycle_update_failed'] = 'Failed to update lifecycle entry.';
        $lang['error_metadata_create_failed'] = 'Metadata could not be created.';
        $lang['error_metadata_save_failed_after_upload'] = 'File uploaded, but metadata could not be saved.';
        $lang['error_no_file_uuid'] = 'No file UUID found.';
        $lang['error_no_journal_uuid'] = 'No journal metadata UUID found.';
        $lang['error_no_lifecycle_selected'] = 'No lifecycle entry selected.';
        $lang['error_resolve_base_reference'] = 'Could not resolve base table or UUID.';
        $lang['error_resolve_device_uuid'] = 'Could not resolve device UUID.';
        $lang['error_response_processing'] = 'Error processing response';
        $lang['error_upload_aborted'] = 'Upload aborted';
        $lang['error_upload_failed'] = 'An error occurred during upload.';
        $lang['error_upload_request'] = 'An error occurred';
        $lang['file_description_placeholder'] = 'File description';
        $lang['file_edit_title'] = 'Edit attachment';
        $lang['file_selected_label'] = 'Selected file';
        $lang['file_size_label'] = 'Size';
        $lang['file_upload_placeholder'] = 'Choose a file to upload or drop it here';
        $lang['file_upload_progress'] = 'Uploading...';
        $lang['file_upload_title'] = 'Upload file';
        $lang['form_date_label'] = 'Date';
        $lang['form_description_label'] = 'Description';
        $lang['form_description_optional_label'] = 'Description (optional)';
        $lang['form_description_placeholder'] = 'Description';
        $lang['form_edit_suffix'] = 'edit';
        $lang['form_event_type_label'] = 'Event type';
        $lang['form_file_label'] = 'File';
        $lang['form_filename_label'] = 'Filename';
        $lang['form_title_label'] = 'Title';
        $lang['form_title_placeholder'] = 'Title';
        $lang['journal_create_title'] = 'Create journal entry';
        $lang['journal_edit_title'] = 'Edit journal entry';
        $lang['journal_entry_description_placeholder'] = 'Detailed description of the journal entry';
        $lang['journal_entry_title_placeholder'] = 'Short title of the entry';
        $lang['lifecycle_create_title'] = 'Create lifecycle entry';
        $lang['lifecycle_edit_title'] = 'Edit lifecycle entry';
        $lang['lifecycle_entry_description_placeholder'] = 'Detailed description of the lifecycle entry';
        $lang['lifecycle_entry_title_placeholder'] = 'Short title of the lifecycle entry';
        $lang['lifecycle_select_placeholder'] = 'Please choose';
        $lang['lifecycle_type_change'] = 'Change';
        $lang['lifecycle_type_decommission'] = 'Decommission';
        $lang['lifecycle_type_incident'] = 'Incident';
        $lang['lifecycle_type_inspection'] = 'Inspection';
        $lang['lifecycle_type_installation'] = 'Installation';
        $lang['lifecycle_type_maintenance'] = 'Maintenance';
        $lang['lifecycle_type_other'] = 'Other';
        $lang['lifecycle_type_repair'] = 'Repair';
        $lang['lifecycle_type_replacement'] = 'Replacement';
        $lang['no_row_selected'] = 'No row selected.';
        $lang['prompt_choose_event_type'] = 'Please choose an event type.';
        $lang['prompt_choose_file'] = 'Please choose a file.';
        $lang['prompt_enter_date'] = 'Please provide a date.';
        $lang['prompt_enter_title'] = 'Please provide a title.';
        $lang['prompt_lifecycle_required_fields'] = 'Please provide type, date, and title.';
        $lang['specification_preview_empty'] = 'No structured values detected.';
        $lang['specification_preview_title'] = 'Specification preview';
        $lang['success_file_deleted'] = 'File deleted successfully!';
        $lang['success_file_updated'] = 'Attachment updated successfully!';
        $lang['success_file_uploaded'] = 'File uploaded successfully!';
        $lang['success_journal_created'] = 'Journal entry created successfully!';
        $lang['success_journal_deleted'] = 'Journal entry deleted successfully!';
        $lang['success_journal_updated'] = 'Journal entry updated successfully!';
        $lang['sidebar_collapse'] = 'Collapse sidebar';
        $lang['sidebar_expand'] = 'Expand sidebar';
        $lang['details_popup_title'] = 'Details';
        $lang['tab_information'] = 'Information';
        $lang['tab_3d_view'] = '3D View';
        $lang['tab_topology'] = 'Topology';
        $lang['entry_saving'] = 'Saving entry';
        $lang['please_wait'] = 'Please wait ...';
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
        $lang['transfer_duplicate_matches'] = 'matches';
        $lang['yes'] = 'Yes';
        $lang['no'] = 'No';
        $lang['page'] = 'Page';
        $lang['actions'] = 'Actions';
        $lang['button_close'] = 'Close';
        $lang['drag_label'] = 'drag';
        $lang['status_active'] = 'Active';
        $lang['status_disabled'] = 'Disabled';
        $lang['status_offline'] = 'Offline';
        $lang['status_unused'] = 'Unused';
        $lang['status_unknown'] = 'Unknown';
        $lang['usable_addresses'] = 'Usable addresses';
        $lang['subnet_mask'] = 'Subnet mask';
        $lang['network_private'] = 'Private network';
        $lang['network_public'] = 'Public network';
        $lang['technical_values'] = 'technical values';
        $lang['location_type_region'] = 'Region';
        $lang['location_type_complex'] = 'Complex';
        $lang['location_type_building'] = 'Building';
        $lang['location_type_room'] = 'Room';
        $lang['location_type_rack'] = 'Rack';
        $lang['geometry_mask'] = 'Geometry mask';
        $lang['geometry_mask_hint'] = 'Simple input, stored automatically as JSON.';
        $lang['room_size_xyz'] = 'Room size (x/y/z)';
        $lang['room_rotation_xyz'] = 'Room rotation (x/y/z)';
        $lang['room_position_xyz'] = 'Room position (x/y/z)';
        $lang['rack_outer_xyz'] = 'Rack outer (x/y/z)';
        $lang['rack_inner_xyz'] = 'Rack inner (x/y/z)';
        $lang['rack_between'] = 'Rack spacing';
        $lang['rack_rotation_xyz'] = 'Rack rotation (x/y/z)';
        $lang['rack_position_room_xyz'] = 'Rack position in room (x/y/z)';
        $lang['rack_limits'] = 'Rack limits';
        $lang['weight_kg'] = 'Weight (kg)';
        $lang['power_w'] = 'Power (W)';
        $lang['thermal_w'] = 'Thermal (W)';
        $lang['device_3d_mask'] = 'Device 3D mask';
        $lang['device_3d_mask_hint'] = 'Guided input for RU placement and geometry, stored automatically as JSON.';
        $lang['ru_unit_hint'] = '1 RU = 44.45 mm';
        $lang['ru_placement'] = 'RU placement';
        $lang['start_ru'] = 'Start RU';
        $lang['height_ru'] = 'Height RU';
        $lang['device_size_mm'] = 'Device size (mm)';
        $lang['axis_x_width'] = 'X (width)';
        $lang['axis_y_height'] = 'Y (height)';
        $lang['axis_z_depth'] = 'Z (depth)';
        $lang['device_position_mm'] = 'Device position (mm)';
        $lang['axis_y_from_ru'] = 'Y (from RU)';
        $lang['device_rotation_xyz'] = 'Device rotation (x/y/z)';
        $lang['template_label'] = 'Template';
        $lang['new_device'] = 'New device';
        $lang['from_template'] = 'From template';
        $lang['template_picker_info'] = 'Choose a template; fields will be prefilled and can still be adjusted afterwards.';
        $lang['template_choose'] = 'Choose template ...';
        $lang['template_apply'] = 'Apply template';
        $lang['templates_reload'] = 'Reload templates';
        $lang['templates_loading'] = 'Loading templates ...';
        $lang['no_device_templates'] = 'No device templates found';
        $lang['loading_error'] = 'Error while loading';
        $lang['unnamed'] = '[unnamed]';
        $lang['suggestion_scope_parent'] = 'parent location';
        $lang['suggestion_scope_building'] = 'building';
        $lang['connect'] = 'Connect';
        $lang['manual'] = 'Manual';
        $lang['suggestions'] = 'Suggestions';
        $lang['suggestions_hint'] = 'Matching port names in the same location, parent location, or building - unconnected pairs';
        $lang['device_label'] = 'Device';
        $lang['without_label'] = '(without label)';
        $lang['suggestions_loading'] = 'Loading suggestions ...';
        $lang['no_open_suggestions'] = 'No open suggestions found.';
        $lang['suggestions_load_failed'] = 'Suggestions could not be loaded.';
        $lang['connecting'] = 'Connecting ...';
        $lang['no_script_executions_for_entry'] = 'No script executions for this entry.';
        $lang['no_executions_available'] = 'No executions available.';
        $lang['warning'] = 'WARNING';
        $lang['ok'] = 'OK';
        $lang['error'] = 'Error';
        $lang['no_script_content_saved'] = '(no script content saved)';
        $lang['profile_label'] = 'Profile';
        $lang['script_content'] = 'Script content';
        $lang['no_journal_entries'] = 'No journal entries available.';
        $lang['status_label'] = 'Status';
        $lang['no_lifecycle_entries'] = 'No lifecycle entries available.';
        $lang['no_attachments'] = 'No attachments available.';
        $lang['attachment_fallback'] = 'Attachment';
        $lang['preview_alt'] = 'Preview';
        $lang['panel_not_configured'] = 'Panel is not configured.';
        $lang['master_data'] = 'Master data';
        $lang['capacity_missing_in_specification'] = 'Capacity missing in specification';
        $lang['ups_pdu_utilization'] = 'UPS/PDU utilization';
        $lang['load_label'] = 'Load';
        $lang['capacity_label'] = 'Capacity';
        $lang['consumers_label'] = 'Consumers';
        $lang['utilization_label'] = 'Utilization';
        $lang['open_3d_view'] = 'Open 3D view';
        $lang['create_journal_entry'] = 'Create journal entry';
        $lang['create_lifecycle_entry'] = 'Create lifecycle entry';
        $lang['upload_file'] = 'Upload file';
        $lang['loading_data'] = 'Loading data ...';
        $lang['data_could_not_be_loaded'] = 'Data could not be loaded.';
        $lang['no_topology_available'] = 'No topology available.';
        $lang['cable_trace'] = 'Cable trace';
        $lang['loading_short'] = 'Loading ...';
        $lang['switch_view'] = 'Switch view';
        $lang['cable_trace_from_port'] = 'Cable trace from this port';
        $lang['device_not_resolvable'] = 'Device cannot be resolved.';
        $lang['no_port_selected'] = 'No port selected yet.';
        $lang['loading_cable_trace'] = 'Loading cable trace ...';
        $lang['no_connected_ports'] = 'No connected ports.';
        $lang['no_connections_from_this_point'] = 'No connections from this point.';
        $lang['path_label'] = 'Path';
        $lang['endpoint'] = 'Endpoint';
        $lang['port_label'] = 'Port';
        $lang['ip_label'] = 'IP';
        $lang['cable_label'] = 'Cable';
        $lang['error_loading_3d_view'] = 'Error while loading the 3D view';
        $lang['controls_basic'] = 'Controls (basic)';
        $lang['controls_hint'] = 'Left: rotate | Middle: pan | Wheel: zoom';
        $lang['camera_front'] = 'Front';
        $lang['camera_rear'] = 'Rear';
        $lang['camera_left'] = 'Left';
        $lang['camera_right'] = 'Right';
        $lang['camera_top'] = 'Top';
        $lang['camera_iso'] = 'Iso';
        $lang['cable_preset'] = 'Cable preset';
        $lang['all'] = 'All';
        $lang['only_power'] = 'Power only';
        $lang['only_fiber'] = 'Fiber only';
        $lang['only_copper'] = 'Copper only';
        $lang['minimal_focus'] = 'Minimal focus';
        $lang['axis_lock'] = 'Axis lock';
        $lang['free'] = 'Free';
        $lang['horizontal_orbit'] = 'Horizontal orbit';
        $lang['rack_focus_room'] = 'Rack focus (room)';
        $lang['automatic_all_racks'] = 'Automatic (all racks)';
        $lang['only_focused_rack_with_devices'] = 'Only focused rack with devices';
        $lang['show_ports'] = 'Show ports';
        $lang['show_rack_ears'] = 'Show rack ears';
        $lang['show_load_overlay'] = 'Show load overlay';
        $lang['overlay_metric'] = 'Overlay metric';
        $lang['chunk_size'] = 'Chunk size';
        $lang['target_chunks'] = 'Target chunks';
        $lang['max_concurrency'] = 'Max concurrency';
        $lang['cables_only_for_devices'] = 'Cables only for devices';
        $lang['cables_only_for_devices_hint'] = 'Multiple selection supported. Alternatively click a device in the viewer if not all cables are rendered.';
        $lang['cable_metadata'] = 'Cable metadata';
        $lang['fiber'] = 'Fiber';
        $lang['copper_cat'] = 'Copper/CAT';
        $lang['power'] = 'Power';
        $lang['trunks'] = 'Trunks';
        $lang['rear_aware_routing'] = 'Rear-aware routing';
        $lang['load_center_marker'] = 'Load center marker';
        $lang['rack_semi_transparent'] = 'Rack semi-transparent';
        $lang['doors_open'] = 'Doors open';
        $lang['side_panels_open'] = 'Side panels open';
        $lang['viewer_loading'] = '3D viewer is loading...';
        $lang['ups_pdu_utilization_loading'] = 'UPS/PDU utilization is loading...';
        $lang['room_loaded'] = 'Room loaded';
        $lang['devices_label'] = 'Devices';
        $lang['cables_label'] = 'Cables';
        $lang['rack_loaded'] = 'Rack loaded';
        $lang['outer_label'] = 'Outer';
        $lang['inner_label'] = 'Inner';
        $lang['no_ups_pdu_in_current_3d_view'] = 'No UPS/PDU in the current 3D view.';
        $lang['not_available_short'] = 'n/a';
        $lang['capacity_short_label'] = 'Cap.';
        $lang['ups_pdu_utilization_3d'] = 'UPS/PDU utilization (3D)';
        $lang['power_phase_distribution_hint'] = '3-phase: outputs are distributed evenly across L1, L2, and L3.';
        $lang['power_configuration'] = 'Power configuration';
        $lang['power_saved_in_metadata_spec'] = 'Values are stored in metadata specification.';
        $lang['power_consumption_w'] = 'Power consumption (W)';
        $lang['output_power_w'] = 'Output power (W)';
        $lang['apparent_power_va'] = 'Apparent power (VA)';
        $lang['phases'] = 'Phases';
        $lang['single_phase'] = 'Single-phase';
        $lang['three_phase'] = '3-phase';
        $lang['example_50'] = 'e.g. 50';
        $lang['example_3000'] = 'e.g. 3000';
        $lang['example_3750'] = 'e.g. 3750';
        $lang['port_layout_builder'] = 'Port layout builder';
        $lang['port_layout_builder_hint'] = 'Define port groups and arrange them visually. Dimensions in mm.';
        $lang['load_preset'] = 'Load preset';
        $lang['apply_preset'] = 'Apply preset';
        $lang['add_group'] = 'Add group';
        $lang['numbering_column_first_switch'] = 'Column-first (switch)';
        $lang['numbering_row_first'] = 'Row-first';
        $lang['side_front'] = 'Front';
        $lang['side_rear'] = 'Rear';
        $lang['group_label'] = 'Group';
        $lang['move_up'] = 'Move up';
        $lang['move_down'] = 'Move down';
        $lang['remove'] = 'Remove';
        $lang['type_label'] = 'Type';
        $lang['count_label'] = 'Count';
        $lang['rows_label'] = 'Rows';
        $lang['start_label'] = 'Start label';
        $lang['label_pattern'] = 'Label pattern';
        $lang['side_label'] = 'Side';
        $lang['offset_x_mm'] = 'Offset X (mm)';
        $lang['offset_y_mm'] = 'Offset Y (mm)';
        $lang['gap_x_mm'] = 'Gap X (mm)';
        $lang['gap_y_mm'] = 'Gap Y (mm)';
        $lang['numbering_label'] = 'Numbering';
        $lang['total_ports'] = 'Total';
        $lang['ports_label'] = 'Ports';
        $lang['unit_mm'] = 'mm';
        $lang['filters'] = 'Filters';
        $lang['table_specific_filters_from_forms'] = 'Table-specific filters from forms.json';
        $lang['apply'] = 'Apply';
        $lang['reset'] = 'Reset';
        $lang['switch_stack_group_hint'] = 'For switch stacks: choose an existing group or generate a new UUID.';
        $lang['choose_existing_item_group'] = 'Choose existing item group ...';
        $lang['generate_new_uuid'] = 'Generate new UUID';
        $lang['reload_groups'] = 'Reload groups';
        $lang['item_group_must_be_valid_uuid'] = 'Item group must be a valid UUID.';
        $lang['loading_item_groups'] = 'Loading item groups ...';
        $lang['no_existing_switch_groups_found'] = 'No existing switch groups found';
        $lang['forms_config_load_failed'] = 'forms.json could not be loaded';
        $lang['invalid_boolean_value'] = 'Invalid boolean value: {value}';
        $lang['missing_required_field'] = 'Missing required field: {field}';
        $lang['invalid_number_in_field'] = 'Invalid number in field {field}: {value}';
        $lang['invalid_option_in_field'] = 'Invalid option in field {field}: {value}';
        $lang['field_expects_uuid'] = 'Field {field} expects a UUID: {value}';
        $lang['duplicate_check_failed'] = 'Duplicate check failed ({status})';
        $lang['api_request_failed'] = 'API {table} failed ({status}): {body}';
        $lang['no_response_body'] = 'no response body';
        $lang['api_returned_no_uuid'] = 'API {table} returned no UUID.';
        $lang['auto_ports_creating'] = 'Auto-ports are being created ...';
        $lang['error_metadata_for_port_create_failed'] = 'Metadata for port {index} ({label}) could not be created.';
        $lang['error_device_port_for_port_create_failed'] = 'Device port for port {index} ({label}) could not be created.';
        $lang['port_created_progress'] = 'Port {index} of {total} created ({label})';
        $lang['connection_could_not_be_created'] = 'Connection could not be created.';
        $lang['port_labels'] = 'Port labels';
        $lang['show_cables'] = 'Show cables';
        $lang['cable_labels'] = 'Cable labels';
        $lang['expert_menu'] = 'Expert menu';
        $lang['labels_on_hover_only'] = 'Labels only on hover';
        $lang['render_all_cables'] = 'Render all cables';

        return $lang;
    }
    $lang = lang_en();
}
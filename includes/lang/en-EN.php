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
        $lang['location'] = 'Standort';
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

        return $lang;
    }
    $lang = lang_en();
}
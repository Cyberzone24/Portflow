<?php
if (isset($_GET['nav'])) {
    $nav = [];
    $nav['location_details'] = [
        'default' => ['location_type', 'location_parent_location_metadata_caption', 'location_metadata_tags'],
        'blocked' => ['location_parent_location', 'location_parent_location_parent_location'],
        'unblocked' => [],
        'details_layout' => [
            'primary_title' => 'Stammdaten',
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
                ['type' => 'attachments', 'title' => 'Anhänge & Bilder']
            ]
        ],
        'columns' => [
            'location_uuid' => 'Standort UUID',
            'location_metadata' => 'Standort Metadata',
            'location_parent_location' => 'Standort übergeordneter Standort',
            'location_type' => 'Standort Typ',
            'location_position' => 'Standort Position',
            'location_rotation' => 'Standort Rotation',
            'location_size' => 'Standort Größe',
            'location_metadata_uuid' => 'Metadata UUID',
            'location_metadata_users' => 'Nutzer',
            'location_metadata_status' => 'Status',
            'location_metadata_caption' => 'Bezeichnung',
            'location_metadata_description' => 'Beschreibung',
            'location_metadata_specification' => 'Spezifikation',
            'location_metadata_tags' => 'Tags',
            'location_metadata_created' => 'Erstellt',
            'location_metadata_changed' => 'Geändert',
            'location_parent_location_uuid' => 'übergeordneter Standort UUID',
            'location_parent_location_metadata' => 'übergeordneter Standort Metadata',
            'location_parent_location_parent_location' => 'übergeordneter Standort übergeordneter Standort',
            'location_parent_location_type' => 'übergeordneter Standort Typ',
            'location_parent_location_position' => 'übergeordneter Standort Position',
            'location_parent_location_rotation' => 'übergeordneter Standort Rotation',
            'location_parent_location_size' => 'übergeordneter Standort Größe',
            'location_parent_location_metadata_uuid' => 'übergeordneter Standort Metadata UUID',
            'location_parent_location_metadata_users' => 'übergeordneter Standort Nutzer',
            'location_parent_location_metadata_status' => 'übergeordneter Standort Status',
            'location_parent_location_metadata_caption' => 'übergeordneter Standort Bezeichnung',
            'location_parent_location_metadata_description' => 'übergeordneter Standort Beschreibung',
            'location_parent_location_metadata_specification' => 'übergeordneter Standort Spezifikation',
            'location_parent_location_metadata_tags' => 'übergeordneter Standort Tags',
            'location_parent_location_metadata_created' => 'übergeordneter Standort Erstellt',
            'location_parent_location_metadata_changed' => 'übergeordneter Standort Geändert'
        ]
    ];
    $nav['ip_range_join_metadata'] = [
        'default' => ['ip_range_metadata_status', 'ip_range_metadata_caption', 'ip_range_ip_range', 'ip_range_gateway', 'ip_range_dns_zone', 'ip_range_metadata_tags'],
        'blocked' => [],
        'unblocked' => [],
        'details_layout' => [
            'primary_title' => 'Stammdaten',
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
                ['type' => 'attachments', 'title' => 'Anhänge & Bilder']
            ]
        ],
        'columns' => [
            'ip_range_uuid' => 'IP-Range UUID',
            'ip_range_metadata' => 'IP-Range Metadata',
            'ip_range_ip_range' => 'IP-Range',
            'ip_range_gateway' => 'Gateway',
            'ip_range_broadcast' => 'Broadcast',
            'ip_range_dns_server' => 'DNS-Server',
            'ip_range_dns_zone' => 'DNS-Zone',
            'ip_range_dhcp_server' => 'DHCP-Server',
            'ip_range_metadata_uuid' => 'Metadata UUID',
            'ip_range_metadata_users' => 'Nutzer',
            'ip_range_metadata_status' => 'Status',
            'ip_range_metadata_caption' => 'Bezeichnung',
            'ip_range_metadata_description' => 'Beschreibung',
            'ip_range_metadata_specification' => 'Spezifikation',
            'ip_range_metadata_tags' => 'Tags',
            'ip_range_metadata_created' => 'Erstellt',
            'ip_range_metadata_changed' => 'Geändert'
        ]
    ];
    $nav['vlan_details'] = [
        'default' => ['vlan_metadata_status', 'vlan_metadata_caption', 'vlan_vlan', 'vlan_ip_range_ip_range', 'vlan_ip_range_metadata_caption', 'vlan_metadata_tags'],
        'blocked' => ['vlan_ip_range'],
        'unblocked' => [],
        'details_layout' => [
            'primary_title' => 'Stammdaten',
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
                ['type' => 'attachments', 'title' => 'Anhänge & Bilder']
            ]
        ],
        'columns' => [
            'vlan_uuid' => 'VLAN UUID',
            'vlan_metadata' => 'VLAN Metadata',
            'vlan_ip_range' => 'VLAN IP-Range',
            'vlan_vlan' => 'VLAN ID',
            'vlan_metadata_uuid' => 'Metadata UUID',
            'vlan_metadata_users' => 'Nutzer',
            'vlan_metadata_status' => 'Status',
            'vlan_metadata_caption' => 'Bezeichnung',
            'vlan_metadata_description' => 'Beschreibung',
            'vlan_metadata_specification' => 'Spezifikation',
            'vlan_metadata_tags' => 'Tags',
            'vlan_metadata_created' => 'Erstellt',
            'vlan_metadata_changed' => 'Geändert',
            'vlan_ip_range_uuid' => 'IP-Range UUID',
            'vlan_ip_range_metadata' => 'IP-Range Metadata',
            'vlan_ip_range_ip_range' => 'IP-Range',
            'vlan_ip_range_gateway' => 'Gateway',
            'vlan_ip_range_broadcast' => 'Broadcast',
            'vlan_ip_range_dns_server' => 'DNS-Server',
            'vlan_ip_range_dns_zone' => 'DNS-Zone',
            'vlan_ip_range_dhcp_server' => 'DHCP-Server',
            'vlan_ip_range_metadata_uuid' => 'IP-Range Metadata UUID',
            'vlan_ip_range_metadata_users' => 'IP-Range Nutzer',
            'vlan_ip_range_metadata_status' => 'IP-Range Status',
            'vlan_ip_range_metadata_caption' => 'IP-Range Bezeichnung',
            'vlan_ip_range_metadata_description' => 'IP-Range Beschreibung',
            'vlan_ip_range_metadata_specification' => 'IP-Range Spezifikation',
            'vlan_ip_range_metadata_tags' => 'IP-Range Tags',
            'vlan_ip_range_metadata_created' => 'IP-Range Erstellt',
            'vlan_ip_range_metadata_changed' => 'IP-Range Geändert'
        ]
    ];
    $nav['device_details'] = [
        'default' => ['device_metadata_status', 'device_metadata_caption', 'device_location_metadata_caption', 'device_manufacturer', 'device_model', 'device_type', 'device_template', 'device_metadata_tags'],
        'blocked' => ['device_location', 'device_expected_location', 'device_location_parent_location', 'device_expected_location_parent_location'],
        'unblocked' => [],
        'details_layout' => [
            'primary_title' => 'Stammdaten',
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
                ['type' => 'scripts', 'title' => 'Letzte Skript-Ausführungen'],
                ['type' => 'journal', 'title' => 'Journal'],
                ['type' => 'lifecycle', 'title' => 'Lifecycle'],
                ['type' => 'attachments', 'title' => 'Anhänge & Bilder']
            ]
        ],
        'columns' => [
            'device_uuid' => 'Device UUID',
            'device_metadata' => 'Device Metadata',
            'device_location' => 'Device Location',
            'device_expected_location' => 'Device Expected Location',
            'device_serial' => 'Seriennummer',
            'device_asset' => 'Inventarnummer',
            'device_manufacturer' => 'Hersteller',
            'device_model' => 'Modell',
            'device_type' => 'Gerätetyp',
            'device_anc' => 'ANK',
            'device_position' => 'Device Position',
            'device_rotation' => 'Device Rotation',
            'device_size' => 'Device Size',
            'device_item_group' => 'Gerätegruppe',
            'device_template' => 'Vorlage',
            'device_metadata_uuid' => 'Metadata UUID',
            'device_metadata_users' => 'Nutzer',
            'device_metadata_status' => 'Status',
            'device_metadata_created' => 'Erstellt',
            'device_metadata_changed' => 'Geändert',
            'device_metadata_specification' => 'Spezifikation',
            'device_metadata_tags' => 'Tags',
            'device_metadata_caption' => 'Bezeichnung',
            'device_metadata_description' => 'Beschreibung',
            'device_location_uuid' => 'Standort UUID',
            'device_location_metadata' => 'Standort Metadata',
            'device_location_parent_location' => 'Standort übergeordneter Standort',
            'device_location_type' => 'Standort Typ',
            'device_location_position' => 'Standort Position',
            'device_location_rotation' => 'Standort Rotation',
            'device_location_size' => 'Standort Größe',
            'device_location_metadata_uuid' => 'Standort Metadata UUID',
            'device_location_metadata_users' => 'Standort Nutzer',
            'device_location_metadata_status' => 'Standort Status',
            'device_location_metadata_caption' => 'Standort Bezeichnung',
            'device_location_metadata_description' => 'Standort Beschreibung',
            'device_location_metadata_specification' => 'Standort Spezifikation',
            'device_location_metadata_tags' => 'Standort Tags',
            'device_location_metadata_created' => 'Standort Erstellt',
            'device_location_metadata_changed' => 'Standort Geändert',
            'device_expected_location_uuid' => 'Erwarteter Standort UUID',
            'device_expected_location_metadata' => 'Erwarteter Standort Metadata',
            'device_expected_location_parent_location' => 'Erwarteter Standort Parent Location',
            'device_expected_location_type' => 'Erwarteter Standort Typ',
            'device_expected_location_position' => 'Erwarteter Standort Position',
            'device_expected_location_rotation' => 'Erwarteter Standort Rotation',
            'device_expected_location_size' => 'Erwarteter Standort Größe',
            'device_expected_location_metadata_uuid' => 'Erwarteter Standort Metadata UUID',
            'device_expected_location_metadata_users' => 'Erwarteter Standort Nutzer',
            'device_expected_location_metadata_status' => 'Erwarteter Standort Status',
            'device_expected_location_metadata_caption' => 'Erwarteter Standort Bezeichnung',
            'device_expected_location_metadata_description' => 'Erwarteter Standort Beschreibung',
            'device_expected_location_metadata_specification' => 'Erwarteter Standort Spezifikation',
            'device_expected_location_metadata_tags' => 'Erwarteter Standort Tags',
            'device_expected_location_metadata_created' => 'Erwarteter Standort Erstellt',
            'device_expected_location_metadata_changed' => 'Erwarteter Standort Geändert'
        ]
    ];
    $nav['device_port_details'] = [
        'default' => ['device_port_metadata_status', 'device_port_metadata_caption', 'device_port_metadata_tags', 'device_port_device_location_metadata_caption', 'device_port_device_metadata_caption', 'device_port_device_type'],
        'blocked' => [],
        'unblocked' => [],
        'details_layout' => [
            'primary_title' => 'Port-Stammdaten',
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
                ['type' => 'attachments', 'title' => 'Anhänge & Bilder']
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
            'device_port_device_location_metadata_caption' => 'Raum',
            'device_port_device_port_ip_uuid' => 'Device Port IP UUID',
            'device_port_device_port_ip_ip' => 'Device Port IP',
            'device_port_device_port_ip_expected_ip' => 'Device Port Expected IP',
            'device_port_device_port_ip_hostname' => 'Device Port Hostname',
            'device_port_device_port_ip_expected_hostname' => 'Device Port Expected Hostname',
            'device_port_device_port_ip_dhcp_address' => 'Device Port DHCP Address',
            'device_port_device_port_ip_expected_dhcp_address' => 'Device Port Expected DHCP Address'
        ]
    ];
    $nav['device_port_vlan_details'] = [
        'default' => ['device_port_vlan_device_port_device_metadata_caption', 'device_port_vlan_device_port_metadata_caption', 'device_port_vlan_vlan_vlan', 'device_port_vlan_expected_vlan_vlan', 'device_port_vlan_tagged', 'device_port_vlan_expected_tagged'],
        'blocked' => [],
        'unblocked' => [],
        'details_layout' => [
            'primary_title' => 'Port-VLAN-Stammdaten',
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
            'device_port_vlan_uuid' => 'Port-VLAN UUID',
            'device_port_vlan_device_port' => 'Device-Port',
            'device_port_vlan_vlan' => 'VLAN (Ist)',
            'device_port_vlan_expected_vlan' => 'VLAN (Soll)',
            'device_port_vlan_tagged' => 'Tagged (Ist)',
            'device_port_vlan_expected_tagged' => 'Tagged (Soll)',
            'device_port_vlan_device_port_metadata_caption' => 'Port',
            'device_port_vlan_device_port_device_metadata_caption' => 'Gerät',
            'device_port_vlan_vlan_vlan' => 'VLAN-ID (Ist)',
            'device_port_vlan_vlan_metadata_caption' => 'VLAN Bezeichnung (Ist)',
            'device_port_vlan_expected_vlan_vlan' => 'VLAN-ID (Soll)',
            'device_port_vlan_expected_vlan_metadata_caption' => 'VLAN Bezeichnung (Soll)'
        ]
    ];
    $nav['connection_details'] = [
        'default' => ['connection_metadata_status', 'connection_metadata_caption', 'connection_metadata_tags', 'connection_device_port_source_device_metadata_caption', 'connection_device_port_source_metadata_caption', 'connection_device_port_destination_device_metadata_caption', 'connection_device_port_destination_metadata_caption'],
        'blocked' => [],
        'unblocked' => [],
        'details_layout' => [
            'primary_title' => 'Verbindungs-Stammdaten',
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
                ['type' => 'attachments', 'title' => 'Anhänge & Bilder']
            ]
        ],
        'columns' => [
            // Connection
            'connection_uuid' => 'Verbindungs UUID',
            'connection_metadata' => 'Verbindungs Metadata',
            'connection_device_port_source' => 'Verbindungs Quellport',
            'connection_expected_device_port_source' => 'Verbindungs Erwarteter Quellport',
            'connection_device_port_destination' => 'Verbindungs Zielport',
            'connection_expected_device_port_destination' => 'Verbindungs Erwarteter Zielport',
            'connection_cable_name' => 'Verbindungs Kabelname',
            'connection_type' => 'Verbindungs Typ',
            'connection_length' => 'Verbindungs Länge',
            'connection_crossover' => 'Verbindungs Crossover',
            'connection_speed' => 'Verbindungs Geschwindigkeit',
            'connection_item_group' => 'Verbindungs Item Gruppe',
            'connection_metadata_uuid' => 'Metadata UUID',
            'connection_metadata_users' => 'Metadata User',
            'connection_metadata_status' => 'Metadata Status',
            'connection_metadata_caption' => 'Metadata Titel',
            'connection_metadata_description' => 'Metadata Beschreibung',
            'connection_metadata_specification' => 'Metadata Spezifikation',
            'connection_metadata_tags' => 'Metadata Tags',
            'connection_metadata_created' => 'Metadata Erstellt',
            'connection_metadata_changed' => 'Metadata Geändert',
            // Source Port
            'connection_device_port_source_uuid' => 'Quellport UUID',
            'connection_device_port_source_metadata' => 'Quellport Metadata',
            'connection_device_port_source_device' => 'Quellport Gerät',
            'connection_device_port_source_device_port_vlan' => 'Quellport VLAN',
            'connection_device_port_source_device_port_ip' => 'Quellport IP-Adresse',
            'connection_device_port_source_poe' => 'Quellport PoE',
            'connection_device_port_source_mac_address' => "Quellport MAC-Adresse",
            'connection_device_port_source_speed' => 'Quellport Geschwindigkeit',
            'connection_device_port_source_expected_speed' => 'Quellport Erwartete Geschwindigkeit',
            'connection_device_port_source_type' => 'Quellport Typ',
            'connection_device_port_source_size' => 'Quellport Größe',
            'connection_device_port_source_position' => 'Quellport Position',
            'connection_device_port_source_rotation' => 'Quellport Rotation',
            'connection_device_port_source_coupling' => 'Quellport Kupplung',
            'connection_device_port_source_metadata_uuid' => 'Quellport Metadata UUID',
            'connection_device_port_source_metadata_users' => 'Quellport Metadata User',
            'connection_device_port_source_metadata_status' => 'Quellport Metadata Status',
            'connection_device_port_source_metadata_caption' => 'Quellport Metadata Titel',
            'connection_device_port_source_metadata_description' => 'Quellport Metadata Beschreibung',
            'connection_device_port_source_metadata_specification' => 'Quellport Metadata Spezifikation',
            'connection_device_port_source_metadata_tags' => 'Quellport Metadata Tags',
            'connection_device_port_source_metadata_created' => 'Quellport Metadata Erstellt',
            'connection_device_port_source_metadata_changed' => 'Quellport Metadata Geändert',
            // Source Device
            'connection_device_port_source_device_uuid' => 'Quellport Geräte UUID',
            'connection_device_port_source_device_metadata' => 'Quellport Geräte Metadata',
            'connection_device_port_source_device_location' => 'Quellport Geräte Standort',
            'connection_device_port_source_device_expected_location' => 'Quellport Geräte Erwarteter Standort',
            'connection_device_port_source_device_serial' => 'Quellport Geräte Seriennummer',
            'connection_device_port_source_device_asset' => 'Quellport Geräte Asset',
            'connection_device_port_source_device_manufacturer' => 'Quellport Geräte Hersteller',
            'connection_device_port_source_device_model' => 'Quellport Geräte Modell',
            'connection_device_port_source_device_type' => 'Quellport Geräte Typ',
            'connection_device_port_source_device_anc' => 'Quellport Geräte ANC',
            'connection_device_port_source_device_position' => 'Quellport Geräte Position',
            'connection_device_port_source_device_rotation' => 'Quellport Geräte Rotation',
            'connection_device_port_source_device_size' => 'Quellport Geräte Größe',
            'connection_device_port_source_device_item_group' => 'Quellport Geräte Item Gruppe',
            'connection_device_port_source_device_template' => 'Quellport Geräte Template',
            'connection_device_port_source_device_metadata_uuid' => 'Quellport Geräte Metadata UUID',
            'connection_device_port_source_device_metadata_users' => 'Quellport Geräte Metadata User',
            'connection_device_port_source_device_metadata_status' => 'Quellport Geräte Metadata Status',
            'connection_device_port_source_device_metadata_caption' => 'Quellport Geräte Metadata Titel',
            'connection_device_port_source_device_metadata_description' => 'Quellport Geräte Metadata Beschreibung',
            'connection_device_port_source_device_metadata_specification' => 'Quellport Geräte Metadata Spezifikation',
            'connection_device_port_source_device_metadata_tags' => 'Quellport Geräte Metadata Tags',
            'connection_device_port_source_device_metadata_created' => 'Quellport Geräte Metadata Erstellt',
            'connection_device_port_source_device_metadata_changed' => 'Quellport Geräte Metadata Geändert',
            // Source Device Location
            'connection_device_port_source_device_location_uuid' => 'Quellport Geräte Standort UUID',
            'connection_device_port_source_device_location_metadata' => 'Quellport Geräte Standort Metadata',
            'connection_device_port_source_device_location_parent_location' => 'Quellport Geräte Standort Übergeordneten Standort',
            'connection_device_port_source_device_location_type' => 'Quellport Geräte Standort Typ',
            'connection_device_port_source_device_location_position' => 'Quellport Geräte Standort Position',
            'connection_device_port_source_device_location_rotation' => 'Quellport Geräte Standort Rotation',
            'connection_device_port_source_device_location_size' => 'Quellport Geräte Standort Größe',
            'connection_device_port_source_device_location_metadata_uuid' => 'Quellport Geräte Standort Metadata UUID',
            'connection_device_port_source_device_location_metadata_users' => 'Quellport Geräte Standort Metadata User',
            'connection_device_port_source_device_location_metadata_status' => 'Quellport Geräte Standort Metadata Status',
            'connection_device_port_source_device_location_metadata_caption' => 'Quellport Geräte Standort Metadata Titel',
            'connection_device_port_source_device_location_metadata_description' => 'Quellport Geräte Standort Metadata Beschreibung',
            'connection_device_port_source_device_location_metadata_specification' => 'Quellport Geräte Standort Metadata Spezifikation',
            'connection_device_port_source_device_location_metadata_tags' => 'Quellport Geräte Standort Metadata Tags',
            'connection_device_port_source_device_location_metadata_created' => 'Quellport Geräte Standort Metadata Erstellt',
            'connection_device_port_source_device_location_metadata_changed' => 'Quellport Geräte Standort Metadata Geändert',
            // Destination Port
            'connection_device_port_destination_uuid' => 'Zielport UUID',
            'connection_device_port_destination_metadata' => 'Zielport Metadata',
            'connection_device_port_destination_device' => 'Zielport Gerät',
            'connection_device_port_destination_device_port_vlan' => 'Zielport VLAN',
            'connection_device_port_destination_device_port_ip' => 'Zielport IP-Adresse',
            'connection_device_port_destination_poe' => 'Zielport PoE',
            'connection_device_port_destination_mac_address' => "Zielport MAC-Adresse",
            'connection_device_port_destination_speed' => 'Zielport Geschwindigkeit',
            'connection_device_port_destination_expected_speed' => 'Zielport Erwartete Geschwindigkeit',
            'connection_device_port_destination_type' => 'Zielport Typ',
            'connection_device_port_destination_size' => 'Zielport Größe',
            'connection_device_port_destination_position' => 'Zielport Position',
            'connection_device_port_destination_rotation' => 'Zielport Rotation',
            'connection_device_port_destination_coupling' => 'Zielport Kupplung',
            'connection_device_port_destination_metadata_uuid' => 'Zielport Metadata UUID',
            'connection_device_port_destination_metadata_users' => 'Zielport Metadata User',
            'connection_device_port_destination_metadata_status' => 'Zielport Metadata Status',
            'connection_device_port_destination_metadata_caption' => 'Zielport Metadata Titel',
            'connection_device_port_destination_metadata_description' => 'Zielport Metadata Beschreibung',
            'connection_device_port_destination_metadata_specification' => 'Zielport Metadata Spezifikation',
            'connection_device_port_destination_metadata_tags' => 'Zielport Metadata Tags',
            'connection_device_port_destination_metadata_created' => 'Zielport Metadata Erstellt',
            'connection_device_port_destination_metadata_changed' => 'Zielport Metadata Geändert',
            // Destination Device
            'connection_device_port_destination_device_uuid' => 'Zielport Geräte UUID',
            'connection_device_port_destination_device_metadata' => 'Zielport Geräte Metadata',
            'connection_device_port_destination_device_location' => 'Zielport Geräte Standort',
            'connection_device_port_destination_device_expected_location' => 'Zielport Geräte Erwarteter Standort',
            'connection_device_port_destination_device_serial' => 'Zielport Geräte Seriennummer',
            'connection_device_port_destination_device_asset' => 'Zielport Geräte Asset',
            'connection_device_port_destination_device_manufacturer' => 'Zielport Geräte Hersteller',
            'connection_device_port_destination_device_model' => 'Zielport Geräte Modell',
            'connection_device_port_destination_device_type' => 'Zielport Geräte Typ',
            'connection_device_port_destination_device_anc' => 'Zielport Geräte ANC',
            'connection_device_port_destination_device_position' => 'Zielport Geräte Position',
            'connection_device_port_destination_device_rotation' => 'Zielport Geräte Rotation',
            'connection_device_port_destination_device_size' => 'Zielport Geräte Größe',
            'connection_device_port_destination_device_item_group' => 'Zielport Geräte Item Gruppe',
            'connection_device_port_destination_device_template' => 'Zielport Geräte Template',
            'connection_device_port_destination_device_metadata_uuid' => 'Zielport Geräte Metadata UUID',
            'connection_device_port_destination_device_metadata_users' => 'Zielport Geräte Metadata User',
            'connection_device_port_destination_device_metadata_status' => 'Zielport Geräte Metadata Status',
            'connection_device_port_destination_device_metadata_caption' => 'Zielport Geräte Metadata Titel',
            'connection_device_port_destination_device_metadata_description' => 'Zielport Geräte Metadata Beschreibung',
            'connection_device_port_destination_device_metadata_specification' => 'Zielport Geräte Metadata Spezifikation',
            'connection_device_port_destination_device_metadata_tags' => 'Zielport Geräte Metadata Tags',
            'connection_device_port_destination_device_metadata_created' => 'Zielport Geräte Metadata Erstellt',
            'connection_device_port_destination_device_metadata_changed' => 'Zielport Geräte Metadata Geändert',
            // Destination Device Location
            'connection_device_port_destination_device_location_uuid' => 'Zielport Geräte Standort UUID',
            'connection_device_port_destination_device_location_metadata' => 'Zielport Geräte Standort Metadata',
            'connection_device_port_destination_device_location_parent_location' => 'Zielport Geräte Standort Übergeordneten Standort',
            'connection_device_port_destination_device_location_type' => 'Zielport Geräte Standort Typ',
            'connection_device_port_destination_device_location_position' => 'Zielport Geräte Standort Position',
            'connection_device_port_destination_device_location_rotation' => 'Zielport Geräte Standort Rotation',
            'connection_device_port_destination_device_location_size' => 'Zielport Geräte Standort Größe',
            'connection_device_port_destination_device_location_metadata_uuid' => 'Zielport Geräte Standort Metadata UUID',
            'connection_device_port_destination_device_location_metadata_users' => 'Zielport Geräte Standort Metadata User',
            'connection_device_port_destination_device_location_metadata_status' => 'Zielport Geräte Standort Metadata Status',
            'connection_device_port_destination_device_location_metadata_caption' => 'Zielport Geräte Standort Metadata Titel',
            'connection_device_port_destination_device_location_metadata_description' => 'Zielport Geräte Standort Metadata Beschreibung',
            'connection_device_port_destination_device_location_metadata_specification' => 'Zielport Geräte Standort Metadata Spezifikation',
            'connection_device_port_destination_device_location_metadata_tags' => 'Zielport Geräte Standort Metadata Tags',
            'connection_device_port_destination_device_location_metadata_created' => 'Zielport Geräte Standort Metadata Erstellt',
            'connection_device_port_destination_device_location_metadata_changed' => 'Zielport Geräte Standort Metadata Geändert'
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

    function lang_de() {
        $lang = array();

        $lang['lang'] = 'Deutsch';
        $lang['portflow'] = 'Portflow';
        $lang['itam'] = 'ITAM';
        $lang['automation'] = 'Automatisierung';
        $lang['portview'] = 'Portview';
        $lang['portview_page_title'] = 'Port View';
        $lang['portview_page_subtitle'] = 'Kompakte Portübersicht von Switch bis Endgerät';
        $lang['portview_result_summary'] = 'Zeige {start}-{end} von {total}';
        $lang['portview_filters_hint'] = 'Portketten nach Raum, Endgerät oder VLAN eingrenzen.';
        $lang['portview_reset_filters'] = 'Filter zurücksetzen';
        $lang['portview_filter_room'] = 'Ort';
        $lang['portview_all_rooms'] = 'Alle Orte';
        $lang['portview_filter_endpoint_type'] = 'Gerätetyp';
        $lang['portview_all_endpoint_types'] = 'Alle Gerätetypen';
        $lang['portview_filter_vlan'] = 'VLAN';
        $lang['portview_all_vlans'] = 'Alle VLANs';
        $lang['portview_detail_title'] = 'Detailansicht';
        $lang['portview_detail_subtitle'] = 'Verbindung und beteiligte Geräte';
        $lang['portview_no_details'] = 'Keine Details vorhanden.';
        $lang['portview_trace_title'] = 'Kabel-Trace';
        $lang['portview_trace_subtitle'] = 'Verfolgt den aktuellen Verbindungsweg über den vorhandenen Trace-Endpunkt.';
        $lang['portview_reload_trace'] = 'Neu laden';
        $lang['portview_trace_loading'] = 'Lade Kabelverlauf ...';
        $lang['portview_connection_add_title'] = 'Verbindung hinzufügen';
        $lang['portview_source_port'] = 'Quelle Port';
        $lang['portview_source_port_placeholder'] = 'Quell-Port suchen ...';
        $lang['portview_destination_port'] = 'Ziel Port';
        $lang['portview_destination_port_placeholder'] = 'Ziel-Port suchen ...';
        $lang['portview_cable'] = 'Kabel';
        $lang['portview_optional'] = 'optional';
        $lang['portview_speed'] = 'Geschwindigkeit';
        $lang['portview_speed_placeholder'] = 'z.B. 1000';
        $lang['portview_length'] = 'Länge';
        $lang['portview_length_placeholder'] = 'z.B. 12.5';
        $lang['portview_crossover'] = 'Crossover';
        $lang['portview_type'] = 'Typ';
        $lang['portview_type_placeholder'] = 'z.B. copper, fiber, trunk';
        $lang['portview_add_connection'] = 'Verbindung hinzufügen';
        $lang['portview_edit_subtitle'] = 'Suche nach Räumen, Geräten und Ports, dann gezielt speichern.';
        $lang['portview_connection_cable'] = 'Kabel / Verbindung';
        $lang['portview_delete_connection'] = 'Verbindung löschen';
        $lang['portview_delete_blocked'] = 'Patchpanel zu Dose ist Kernverkabelung und kann hier nicht gelöscht werden.';
        $lang['portview_source_room'] = 'Quelle Ort';
        $lang['portview_destination_room'] = 'Ziel Ort';
        $lang['portview_source_device'] = 'Quelle Gerät';
        $lang['portview_destination_device'] = 'Ziel Gerät';
        $lang['portview_source_port_caption'] = 'Quelle Port-Caption';
        $lang['portview_destination_port_caption'] = 'Ziel Port-Caption';
        $lang['portview_source_hostname'] = 'Quelle Hostname';
        $lang['portview_destination_hostname'] = 'Ziel Hostname';
        $lang['portview_source_mac'] = 'Quelle MAC';
        $lang['portview_destination_mac'] = 'Ziel MAC';
        $lang['portview_source_label'] = 'Quelle';
        $lang['portview_destination_label'] = 'Ziel';
        $lang['portview_status_label'] = 'Status';
        $lang['portview_tagged_label'] = 'VLAN tagged';
        $lang['portview_untagged_label'] = 'VLAN untagged';
        $lang['portview_trace_error'] = 'Fehler';
        $lang['portview_no_connected_ports'] = 'Keine verbundenen Ports.';
        $lang['portview_trace_start'] = 'Start';
        $lang['portview_trace_no_further'] = 'Keine weiteren Verbindungen ab diesem Punkt.';
        $lang['portview_trace_path'] = 'Pfad {index}';
        $lang['portview_trace_endpoint'] = 'Endpunkt';
        $lang['portview_trace_note'] = 'Hinweis';
        $lang['portview_trace_location'] = 'Ort';
        $lang['portview_trace_port'] = 'Port';
        $lang['portview_trace_device_fallback'] = 'Gerät';
        $lang['portview_trace_ip'] = 'IP';
        $lang['portview_trace_cable_fallback'] = 'Kabel';
        $lang['portview_update_failed'] = 'Update fehlgeschlagen';
        $lang['portview_create_failed'] = 'Erstellen fehlgeschlagen';
        $lang['portview_fetch_failed'] = 'Abruf fehlgeschlagen';
        $lang['portview_full_port_search_failed'] = 'Vollständiger Portsuchindex konnte nicht geladen werden';
        $lang['portview_no_connection_to_save'] = 'Keine Verbindung zum Speichern gefunden.';
        $lang['portview_saving_changes'] = 'Speichere Änderungen ...';
        $lang['portview_changes_saved'] = 'Änderungen gespeichert.';
        $lang['portview_save_failed'] = 'Speichern fehlgeschlagen.';
        $lang['portview_select_source_destination'] = 'Bitte Quelle und Ziel über die Portsuche auswählen.';
        $lang['portview_identical_ports'] = 'Quelle und Ziel dürfen nicht identisch sein.';
        $lang['portview_creating_connection'] = 'Erstelle neue Verbindung ...';
        $lang['portview_create_metadata_failed'] = 'Metadata für neue Verbindung konnte nicht erstellt werden';
        $lang['portview_connection_added'] = 'Verbindung hinzugefügt.';
        $lang['portview_connection_add_failed'] = 'Verbindung konnte nicht hinzugefügt werden.';
        $lang['portview_delete_missing'] = 'Verbindung zum Löschen nicht gefunden.';
        $lang['portview_delete_confirm'] = 'Möchten Sie diese Verbindung vollständig löschen?';
        $lang['portview_deleting_connection'] = 'Lösche Verbindung ...';
        $lang['portview_connection_deleted'] = 'Verbindung gelöscht.';
        $lang['portview_delete_failed'] = 'Verbindung konnte nicht gelöscht werden.';
        $lang['portview_no_search_hits'] = 'Keine Treffer';
        $lang['portview_clear_field'] = 'Feld leeren';
        $lang['portview_no_entries'] = 'Keine Einträge gefunden';
        $lang['portview_new_connection_caption'] = 'Portview Verbindung';
        $lang['portview_new_connection_description'] = 'Erstellt über Portview';
        $lang['portview_picker_kind_location'] = 'Ort';
        $lang['portview_picker_kind_device'] = 'Gerät';
        $lang['portview_picker_kind_port'] = 'Port';
        $lang['portview_column_status'] = 'Status';
        $lang['portview_column_switch'] = 'Switch';
        $lang['portview_column_switch_port'] = 'Switch-Port';
        $lang['portview_column_patchpanel'] = 'Patchpanel';
        $lang['portview_column_patchpanel_port'] = 'PP-Port';
        $lang['portview_column_cable'] = 'Kabel';
        $lang['portview_column_room'] = 'Raum';
        $lang['portview_column_wallplate'] = 'Wallplate';
        $lang['portview_column_wallplate_port'] = 'WP-Port';
        $lang['portview_column_endpoint'] = 'Endgerät';
        $lang['portview_column_endpoint_port'] = 'EP-Port';
        $lang['portview_column_hostname'] = 'Hostname';
        $lang['portview_column_mac'] = 'MAC';
        $lang['portview_column_vlan_tagged'] = 'VLAN (tagged)';
        $lang['portview_column_vlan_untagged'] = 'VLAN (untagged)';
        $lang['reports'] = 'Reports';
        $lang['login'] = 'Anmelden';
        $lang['logout'] = 'Abmelden';
        $lang['register'] = 'Registrieren';
        $lang['settings'] = 'Einstellungen';
        $lang['account'] = 'Benutzerkonto';
        $lang['appearance'] = 'Darstellung';
        $lang['notifications'] = 'Benachrichtigungen';
        $lang['configuration'] = 'Konfiguration';
        $lang['scripts'] = 'Skripte';
        $lang['access_management'] = 'Zugangsverwaltung';
        $lang['search'] = 'Suchen';
        $lang['it asset-management'] = 'IT Asset-Management';
        $lang['it asset-management note'] = 'Verwaltung von Standorten, Geräten, Ports und Verbindungen';
        $lang['location'] = 'Standorte';
        $lang['ipam'] = 'IPAM';
        $lang['vlan'] = 'VLAN';
        $lang['devices'] = 'Geräte';
        $lang['device ports'] = 'Geräteports';
        $lang['port vlans'] = 'Port-VLANs';
        $lang['connections'] = 'Verbindungen';
        $lang['quantity'] = 'Anzahl';
        $lang['datasets'] = 'Datensätze';
        $lang['save'] = 'Speichern';
        $lang['cancel'] = 'Abbrechen';
        $lang['button_delete'] = 'Löschen';
        $lang['button_edit'] = 'Bearbeiten';
        $lang['button_upload'] = 'Hochladen';
        $lang['confirm_delete_entry'] = 'Möchten Sie diesen Eintrag und alle zugehörigen Daten wirklich löschen?';
        $lang['confirm_delete_file'] = 'Möchten Sie diese Datei wirklich löschen?';
        $lang['confirm_delete_journal_entry'] = 'Möchten Sie diesen Journaleintrag wirklich löschen?';
        $lang['confirm_delete_lifecycle_entry'] = 'Möchten Sie diesen Lifecycle-Eintrag wirklich löschen?';
        $lang['error_file_delete_failed'] = 'Fehler beim Löschen der Datei.';
        $lang['error_file_update_failed'] = 'Fehler beim Aktualisieren der Anlage.';
        $lang['error_file_upload_failed'] = 'Datei konnte nicht hochgeladen werden.';
        $lang['error_journal_create_failed'] = 'Journaleintrag konnte nicht erstellt werden.';
        $lang['error_journal_delete_failed'] = 'Fehler beim Löschen des Journaleintrags.';
        $lang['error_journal_update_failed'] = 'Fehler beim Aktualisieren des Journaleintrags.';
        $lang['error_lifecycle_create_failed'] = 'Lifecycle-Eintrag konnte nicht erstellt werden.';
        $lang['error_lifecycle_delete_failed'] = 'Fehler beim Löschen des Lifecycle-Eintrags.';
        $lang['error_lifecycle_metadata_create_failed'] = 'Lifecycle-Metadaten konnten nicht erstellt werden.';
        $lang['error_lifecycle_update_failed'] = 'Fehler beim Aktualisieren des Lifecycle-Eintrags.';
        $lang['error_metadata_create_failed'] = 'Metadaten konnten nicht erstellt werden.';
        $lang['error_metadata_save_failed_after_upload'] = 'Datei hochgeladen, aber Metadaten konnten nicht gespeichert werden.';
        $lang['error_no_file_uuid'] = 'Keine Datei-UUID gefunden.';
        $lang['error_no_journal_uuid'] = 'Keine Journal-Metadaten-UUID gefunden.';
        $lang['error_no_lifecycle_selected'] = 'Kein Lifecycle-Eintrag ausgewählt.';
        $lang['error_resolve_base_reference'] = 'Konnte Basis-Tabelle oder UUID nicht bestimmen.';
        $lang['error_resolve_device_uuid'] = 'Konnte Geräte-UUID nicht bestimmen.';
        $lang['error_response_processing'] = 'Fehler beim Verarbeiten der Antwort';
        $lang['error_upload_aborted'] = 'Upload abgebrochen';
        $lang['error_upload_failed'] = 'Ein Fehler ist beim Upload aufgetreten.';
        $lang['error_upload_request'] = 'Ein Fehler ist aufgetreten';
        $lang['file_description_placeholder'] = 'Beschreibung der Datei';
        $lang['file_edit_title'] = 'Anlage bearbeiten';
        $lang['file_selected_label'] = 'Ausgewählte Datei';
        $lang['file_size_label'] = 'Größe';
        $lang['file_upload_placeholder'] = 'Datei zum Hochladen auswählen oder hier ablegen';
        $lang['file_upload_progress'] = 'Upload läuft...';
        $lang['file_upload_title'] = 'Datei hochladen';
        $lang['form_date_label'] = 'Datum';
        $lang['form_description_label'] = 'Beschreibung';
        $lang['form_description_optional_label'] = 'Beschreibung (optional)';
        $lang['form_description_placeholder'] = 'Beschreibung';
        $lang['form_edit_suffix'] = 'bearbeiten';
        $lang['form_event_type_label'] = 'Event-Typ';
        $lang['form_file_label'] = 'Datei';
        $lang['form_filename_label'] = 'Dateiname';
        $lang['form_title_label'] = 'Titel';
        $lang['form_title_placeholder'] = 'Titel';
        $lang['journal_create_title'] = 'Journaleintrag erstellen';
        $lang['journal_edit_title'] = 'Journaleintrag bearbeiten';
        $lang['journal_entry_description_placeholder'] = 'Detaillierte Beschreibung des Journaleintrags';
        $lang['journal_entry_title_placeholder'] = 'Kurzer Titel des Eintrags';
        $lang['lifecycle_create_title'] = 'Lifecycle-Eintrag erstellen';
        $lang['lifecycle_edit_title'] = 'Lifecycle-Eintrag bearbeiten';
        $lang['lifecycle_entry_description_placeholder'] = 'Detaillierte Beschreibung des Lifecycle-Eintrags';
        $lang['lifecycle_entry_title_placeholder'] = 'Kurzer Titel des Lifecycle-Eintrags';
        $lang['lifecycle_select_placeholder'] = 'Bitte wählen';
        $lang['lifecycle_type_change'] = 'Veränderung';
        $lang['lifecycle_type_decommission'] = 'Außerbetriebnahme';
        $lang['lifecycle_type_incident'] = 'Störung';
        $lang['lifecycle_type_inspection'] = 'Inspektion';
        $lang['lifecycle_type_installation'] = 'Installation';
        $lang['lifecycle_type_maintenance'] = 'Wartung';
        $lang['lifecycle_type_other'] = 'Sonstiges';
        $lang['lifecycle_type_repair'] = 'Reparatur';
        $lang['lifecycle_type_replacement'] = 'Austausch';
        $lang['no_row_selected'] = 'Keine Zeile ausgewählt.';
        $lang['prompt_choose_event_type'] = 'Bitte wählen Sie einen Event-Typ.';
        $lang['prompt_choose_file'] = 'Bitte wählen Sie eine Datei aus.';
        $lang['prompt_enter_date'] = 'Bitte geben Sie ein Datum an.';
        $lang['prompt_enter_title'] = 'Bitte geben Sie einen Titel ein.';
        $lang['prompt_lifecycle_required_fields'] = 'Bitte Typ, Datum und Titel angeben.';
        $lang['specification_preview_empty'] = 'Keine strukturierten Werte erkannt.';
        $lang['specification_preview_title'] = 'Vorschau Spezifikation';
        $lang['success_file_deleted'] = 'Datei erfolgreich gelöscht!';
        $lang['success_file_updated'] = 'Anlage erfolgreich aktualisiert!';
        $lang['success_file_uploaded'] = 'Datei erfolgreich hochgeladen!';
        $lang['success_journal_created'] = 'Journaleintrag erfolgreich erstellt!';
        $lang['success_journal_deleted'] = 'Journaleintrag erfolgreich gelöscht!';
        $lang['success_journal_updated'] = 'Journaleintrag erfolgreich aktualisiert!';
        $lang['sidebar_collapse'] = 'Leiste verkleinern';
        $lang['sidebar_expand'] = 'Leiste vergrößern';
        $lang['details_popup_title'] = 'Details';
        $lang['tab_information'] = 'Informationen';
        $lang['tab_3d_view'] = '3D Ansicht';
        $lang['tab_topology'] = 'Topologie';
        $lang['entry_saving'] = 'Eintrag wird gespeichert';
        $lang['please_wait'] = 'Bitte warten ...';
        $lang['columns_customize'] = 'Spalten anpassen';
        $lang['columns_hint'] = 'Aktivieren oder deaktivieren Sie Spalten und ziehen Sie sie zum Sortieren.';
        $lang['columns_reset'] = 'Auf Standard zurücksetzen';
        $lang['columns_save_failed'] = 'Speichern fehlgeschlagen';
        $lang['columns_not_available'] = 'Spaltenkonfiguration nicht verfügbar.';
        $lang['transfer_csv'] = 'CSV Import/Export';
        $lang['transfer_hint'] = 'Akzeptierte und erforderliche Felder für die aktuelle Ansicht.';
        $lang['transfer_field'] = 'Feld';
        $lang['transfer_label'] = 'Bezeichnung';
        $lang['transfer_required'] = 'Pflicht';
        $lang['transfer_type'] = 'Typ';
        $lang['transfer_notes'] = 'Hinweise';
        $lang['transfer_sample_title'] = 'Sample-Datei';
        $lang['transfer_download_sample'] = 'Sample-CSV herunterladen';
        $lang['transfer_sample_ready'] = 'Sample-CSV heruntergeladen.';
        $lang['transfer_export_title'] = 'Export';
        $lang['transfer_export_csv'] = 'CSV exportieren';
        $lang['transfer_export_ready'] = 'CSV-Export heruntergeladen.';
        $lang['transfer_import_title'] = 'Import';
        $lang['transfer_choose_file'] = 'CSV-Datei auswählen';
        $lang['transfer_no_file'] = 'Keine Datei ausgewählt';
        $lang['transfer_import_csv'] = 'CSV importieren';
        $lang['transfer_not_available'] = 'Import/Export ist für diese Ansicht nicht verfügbar.';
        $lang['transfer_import_empty'] = 'Die CSV-Datei ist leer.';
        $lang['transfer_import_unknown'] = 'Unbekannte CSV-Spalten';
        $lang['transfer_import_missing_required'] = 'Fehlende Pflichtspalten';
        $lang['transfer_import_progress'] = 'Importiere Zeile';
        $lang['transfer_import_done'] = 'Import abgeschlossen';
        $lang['transfer_import_skipped'] = 'übersprungen';
        $lang['transfer_duplicate_title'] = 'Gleichnamiger Eintrag gefunden';
        $lang['transfer_duplicate_copy'] = 'Es gibt bereits einen Eintrag mit dieser Caption';
        $lang['transfer_duplicate_apply_all'] = 'Entscheidung für diese Caption merken';
        $lang['transfer_duplicate_keep'] = 'Alten Eintrag behalten';
        $lang['transfer_duplicate_replace'] = 'Alten Eintrag überschreiben';
        $lang['transfer_duplicate_create'] = 'Zusätzlichen Eintrag erstellen';
        $lang['transfer_duplicate_matches'] = 'Treffer';
        $lang['yes'] = 'Ja';
        $lang['no'] = 'Nein';
        $lang['page'] = 'Seite';
        $lang['actions'] = 'Aktionen';
        $lang['button_close'] = 'Schließen';
        $lang['drag_label'] = 'ziehen';
        $lang['status_active'] = 'Aktiv';
        $lang['status_disabled'] = 'Deaktiviert';
        $lang['status_offline'] = 'Offline';
        $lang['status_unused'] = 'Ungenutzt';
        $lang['status_unknown'] = 'Unbekannt';
        $lang['usable_addresses'] = 'Nutzbare Adressen';
        $lang['subnet_mask'] = 'Subnetz-Maske';
        $lang['network_private'] = 'Privates Netz';
        $lang['network_public'] = 'Öffentliches Netz';
        $lang['technical_values'] = 'technische Werte';
        $lang['location_type_region'] = 'Region';
        $lang['location_type_complex'] = 'Komplex';
        $lang['location_type_building'] = 'Gebäude';
        $lang['location_type_room'] = 'Raum';
        $lang['location_type_rack'] = 'Rack';
        $lang['geometry_mask'] = 'Geometrie-Maske';
        $lang['geometry_mask_hint'] = 'Einfache Eingabe, Speicherung erfolgt automatisch als JSON.';
        $lang['room_size_xyz'] = 'Raumgröße (x/y/z)';
        $lang['room_rotation_xyz'] = 'Raumrotation (x/y/z)';
        $lang['room_position_xyz'] = 'Raumposition (x/y/z)';
        $lang['rack_outer_xyz'] = 'Rack außen (x/y/z)';
        $lang['rack_inner_xyz'] = 'Rack innen (x/y/z)';
        $lang['rack_between'] = 'Rack-Abstände';
        $lang['rack_rotation_xyz'] = 'Rackrotation (x/y/z)';
        $lang['rack_position_room_xyz'] = 'Rackposition im Raum (x/y/z)';
        $lang['rack_limits'] = 'Rack-Limits';
        $lang['weight_kg'] = 'Gewicht (kg)';
        $lang['power_w'] = 'Leistung (W)';
        $lang['thermal_w'] = 'Thermik (W)';
        $lang['device_3d_mask'] = 'Device-3D-Maske';
        $lang['device_3d_mask_hint'] = 'Geführte Eingabe für RU-Position und Geometrie, Speicherung erfolgt automatisch als JSON.';
        $lang['ru_unit_hint'] = '1 RU = 44.45 mm';
        $lang['ru_placement'] = 'RU-Platzierung';
        $lang['start_ru'] = 'Start-RU';
        $lang['height_ru'] = 'Höhe RU';
        $lang['device_size_mm'] = 'Gerätegröße (mm)';
        $lang['axis_x_width'] = 'X (Breite)';
        $lang['axis_y_height'] = 'Y (Höhe)';
        $lang['axis_z_depth'] = 'Z (Tiefe)';
        $lang['device_position_mm'] = 'Geräteposition (mm)';
        $lang['axis_y_from_ru'] = 'Y (aus RU)';
        $lang['device_rotation_xyz'] = 'Geräterotation (x/y/z)';
        $lang['template_label'] = 'Template';
        $lang['new_device'] = 'Neues Gerät';
        $lang['from_template'] = 'Aus Template';
        $lang['template_picker_info'] = 'Template auswählen, Felder werden vorbefüllt und können danach angepasst werden.';
        $lang['template_choose'] = 'Template wählen ...';
        $lang['template_apply'] = 'Template anwenden';
        $lang['templates_reload'] = 'Templates neu laden';
        $lang['templates_loading'] = 'Lade Templates ...';
        $lang['no_device_templates'] = 'Keine Device-Templates gefunden';
        $lang['loading_error'] = 'Fehler beim Laden';
        $lang['unnamed'] = '[kein Name]';
        $lang['suggestion_scope_parent'] = 'übergeordneter Standort';
        $lang['suggestion_scope_building'] = 'Gebäude';
        $lang['connect'] = 'Verbinden';
        $lang['manual'] = 'Manuell';
        $lang['suggestions'] = 'Vorschläge';
        $lang['suggestions_hint'] = 'Gleiche Portnamen im selben Standort, übergeordneten Standort oder Gebäude - unverbundene Paare';
        $lang['device_label'] = 'Gerät';
        $lang['without_label'] = '(ohne Bezeichnung)';
        $lang['suggestions_loading'] = 'Lade Vorschläge ...';
        $lang['no_open_suggestions'] = 'Keine offenen Vorschläge gefunden.';
        $lang['suggestions_load_failed'] = 'Vorschläge konnten nicht geladen werden.';
        $lang['connecting'] = 'Verbinde ...';
        $lang['no_script_executions_for_entry'] = 'Keine Skript-Ausführungen für diesen Eintrag.';
        $lang['no_executions_available'] = 'Keine Ausführungen vorhanden.';
        $lang['warning'] = 'WARNUNG';
        $lang['ok'] = 'OK';
        $lang['error'] = 'Fehler';
        $lang['no_script_content_saved'] = '(kein Skriptinhalt gespeichert)';
        $lang['profile_label'] = 'Profil';
        $lang['script_content'] = 'Skriptinhalt';
        $lang['no_journal_entries'] = 'Keine Journal-Einträge vorhanden.';
        $lang['status_label'] = 'Status';
        $lang['no_lifecycle_entries'] = 'Keine Lifecycle-Einträge vorhanden.';
        $lang['no_attachments'] = 'Keine Anhänge vorhanden.';
        $lang['attachment_fallback'] = 'Anlage';
        $lang['preview_alt'] = 'Vorschau';
        $lang['panel_not_configured'] = 'Panel nicht konfiguriert.';
        $lang['master_data'] = 'Stammdaten';
        $lang['capacity_missing_in_specification'] = 'Kapazität fehlt in Specification';
        $lang['ups_pdu_utilization'] = 'USV/PDU Auslastung';
        $lang['load_label'] = 'Last';
        $lang['capacity_label'] = 'Kapazität';
        $lang['consumers_label'] = 'Verbraucher';
        $lang['utilization_label'] = 'Auslastung';
        $lang['open_3d_view'] = '3D Ansicht öffnen';
        $lang['create_journal_entry'] = 'Journaleintrag erstellen';
        $lang['create_lifecycle_entry'] = 'Lifecycle-Eintrag erstellen';
        $lang['upload_file'] = 'Datei hochladen';
        $lang['loading_data'] = 'Lade Daten ...';
        $lang['data_could_not_be_loaded'] = 'Daten konnten nicht geladen werden.';
        $lang['no_topology_available'] = 'Keine Topologie verfügbar.';
        $lang['cable_trace'] = 'Kabelverlauf';
        $lang['loading_short'] = 'Lade ...';
        $lang['switch_view'] = 'Switch-Ansicht';
        $lang['cable_trace_from_port'] = 'Kabelverlauf ab diesem Port';
        $lang['device_not_resolvable'] = 'Gerät nicht auflösbar.';
        $lang['no_port_selected'] = 'Noch kein Port gewählt.';
        $lang['loading_cable_trace'] = 'Lade Kabelverlauf ...';
        $lang['no_connected_ports'] = 'Keine verbundenen Ports.';
        $lang['no_connections_from_this_point'] = 'Keine Verbindungen ab diesem Punkt.';
        $lang['path_label'] = 'Pfad';
        $lang['endpoint'] = 'Endpunkt';
        $lang['port_label'] = 'Port';
        $lang['ip_label'] = 'IP';
        $lang['cable_label'] = 'Kabel';
        $lang['error_loading_3d_view'] = 'Fehler beim Laden der 3D-Ansicht';
        $lang['controls_basic'] = 'Steuerung (Basis)';
        $lang['controls_hint'] = 'Links: drehen | Mitte: verschieben | Rad: zoomen';
        $lang['camera_front'] = 'Front';
        $lang['camera_rear'] = 'Hinten';
        $lang['camera_left'] = 'Links';
        $lang['camera_right'] = 'Rechts';
        $lang['camera_top'] = 'Oben';
        $lang['camera_iso'] = 'Iso';
        $lang['cable_preset'] = 'Kabel-Preset';
        $lang['all'] = 'Alle';
        $lang['only_power'] = 'Nur Power';
        $lang['only_fiber'] = 'Nur Fiber';
        $lang['only_copper'] = 'Nur Copper';
        $lang['minimal_focus'] = 'Minimaler Fokus';
        $lang['axis_lock'] = 'Achsen-Lock';
        $lang['free'] = 'Frei';
        $lang['horizontal_orbit'] = 'Horizontal Orbit';
        $lang['rack_focus_room'] = 'Rack-Fokus (Raum)';
        $lang['automatic_all_racks'] = 'Automatisch (alle Racks)';
        $lang['only_focused_rack_with_devices'] = 'Nur fokussiertes Rack mit Geräten';
        $lang['show_ports'] = 'Ports anzeigen';
        $lang['show_rack_ears'] = 'Rackohren anzeigen';
        $lang['show_load_overlay'] = 'Last-Overlay anzeigen';
        $lang['overlay_metric'] = 'Overlay-Metrik';
        $lang['chunk_size'] = 'Chunk Size';
        $lang['target_chunks'] = 'Target Chunks';
        $lang['max_concurrency'] = 'Max Concurrency';
        $lang['cables_only_for_devices'] = 'Kabel nur für Geräte';
        $lang['cables_only_for_devices_hint'] = 'Mehrfachauswahl möglich. Alternativ im Viewer auf ein Gerät klicken, wenn nicht alle Kabel gerendert werden.';
        $lang['cable_metadata'] = 'Kabel-Metadaten';
        $lang['fiber'] = 'Fiber';
        $lang['copper_cat'] = 'Copper/CAT';
        $lang['power'] = 'Power';
        $lang['trunks'] = 'Trunks';
        $lang['rear_aware_routing'] = 'Rear-aware Routing';
        $lang['load_center_marker'] = 'Last-Schwerpunktmarker';
        $lang['rack_semi_transparent'] = 'Rack halbtransparent';
        $lang['doors_open'] = 'Türen geöffnet';
        $lang['side_panels_open'] = 'Seitenwände geöffnet';
        $lang['viewer_loading'] = '3D-Viewer wird geladen...';
        $lang['ups_pdu_utilization_loading'] = 'USV/PDU Auslastung wird geladen...';
        $lang['room_loaded'] = 'Raum geladen';
        $lang['devices_label'] = 'Geräte';
        $lang['cables_label'] = 'Kabel';
        $lang['rack_loaded'] = 'Rack geladen';
        $lang['outer_label'] = 'Außen';
        $lang['inner_label'] = 'Innen';
        $lang['no_ups_pdu_in_current_3d_view'] = 'Keine USV/PDU im aktuellen 3D-Ausschnitt.';
        $lang['not_available_short'] = 'n/a';
        $lang['capacity_short_label'] = 'Kap.';
        $lang['ups_pdu_utilization_3d'] = 'USV/PDU Auslastung (3D)';
        $lang['power_phase_distribution_hint'] = '3-Phasen: Ausgänge werden gleichmäßig auf L1, L2, L3 verteilt.';
        $lang['power_configuration'] = 'Strom-Konfiguration';
        $lang['power_saved_in_metadata_spec'] = 'Werte werden in Metadata Specification gespeichert.';
        $lang['power_consumption_w'] = 'Stromverbrauch (W)';
        $lang['output_power_w'] = 'Ausgangsleistung (W)';
        $lang['apparent_power_va'] = 'Scheinleistung (VA)';
        $lang['phases'] = 'Phasen';
        $lang['single_phase'] = '1-Phase';
        $lang['three_phase'] = '3-Phasen';
        $lang['example_50'] = 'z.B. 50';
        $lang['example_3000'] = 'z.B. 3000';
        $lang['example_3750'] = 'z.B. 3750';
        $lang['port_layout_builder'] = 'Port Layout Builder';
        $lang['port_layout_builder_hint'] = 'Port-Gruppen definieren und visuell anordnen. Maße in mm.';
        $lang['load_preset'] = 'Preset laden';
        $lang['apply_preset'] = 'Preset anwenden';
        $lang['add_group'] = 'Gruppe hinzufügen';
        $lang['numbering_column_first_switch'] = 'Column-first (Switch)';
        $lang['numbering_row_first'] = 'Row-first';
        $lang['side_front'] = 'Front';
        $lang['side_rear'] = 'Rear';
        $lang['group_label'] = 'Gruppe';
        $lang['move_up'] = 'Nach oben';
        $lang['move_down'] = 'Nach unten';
        $lang['remove'] = 'Entfernen';
        $lang['type_label'] = 'Typ';
        $lang['count_label'] = 'Anzahl';
        $lang['rows_label'] = 'Reihen';
        $lang['start_label'] = 'Start-Label';
        $lang['label_pattern'] = 'Label-Pattern';
        $lang['side_label'] = 'Seite';
        $lang['offset_x_mm'] = 'Offset X (mm)';
        $lang['offset_y_mm'] = 'Offset Y (mm)';
        $lang['gap_x_mm'] = 'Gap X (mm)';
        $lang['gap_y_mm'] = 'Gap Y (mm)';
        $lang['numbering_label'] = 'Nummerierung';
        $lang['total_ports'] = 'Gesamt';
        $lang['ports_label'] = 'Ports';
        $lang['unit_mm'] = 'mm';
        $lang['filters'] = 'Filter';
        $lang['table_specific_filters_from_forms'] = 'Tabellenspezifische Filter aus forms.json';
        $lang['apply'] = 'Anwenden';
        $lang['reset'] = 'Reset';
        $lang['switch_stack_group_hint'] = 'Für Switch-Stacks: vorhandene Group wählen oder neue UUID erzeugen.';
        $lang['choose_existing_item_group'] = 'Vorhandene Item Group wählen ...';
        $lang['generate_new_uuid'] = 'Neue UUID erzeugen';
        $lang['reload_groups'] = 'Groups neu laden';
        $lang['item_group_must_be_valid_uuid'] = 'Item Group muss eine gültige UUID sein.';
        $lang['loading_item_groups'] = 'Lade Item Groups ...';
        $lang['no_existing_switch_groups_found'] = 'Keine vorhandenen Switch-Groups gefunden';
        $lang['forms_config_load_failed'] = 'forms.json konnte nicht geladen werden';
        $lang['invalid_boolean_value'] = 'Ungültiger Boolean-Wert: {value}';
        $lang['missing_required_field'] = 'Fehlendes Pflichtfeld: {field}';
        $lang['invalid_number_in_field'] = 'Ungültige Zahl in Feld {field}: {value}';
        $lang['invalid_option_in_field'] = 'Ungültige Option in Feld {field}: {value}';
        $lang['field_expects_uuid'] = 'Feld {field} erwartet eine UUID: {value}';
        $lang['duplicate_check_failed'] = 'Duplikatsprüfung fehlgeschlagen ({status})';
        $lang['api_request_failed'] = 'API {table} fehlgeschlagen ({status}): {body}';
        $lang['no_response_body'] = 'kein Response-Body';
        $lang['api_returned_no_uuid'] = 'API {table} hat keine UUID zurückgegeben.';
        $lang['auto_ports_creating'] = 'Auto-Ports werden erstellt ...';
        $lang['error_metadata_for_port_create_failed'] = 'Metadata für Port {index} ({label}) konnte nicht erstellt werden.';
        $lang['error_device_port_for_port_create_failed'] = 'Device-Port für Port {index} ({label}) konnte nicht erstellt werden.';
        $lang['port_created_progress'] = 'Port {index} von {total} erstellt ({label})';
        $lang['connection_could_not_be_created'] = 'Verbindung konnte nicht angelegt werden.';
        $lang['port_labels'] = 'Port-Beschriftung';
        $lang['show_cables'] = 'Kabel anzeigen';
        $lang['cable_labels'] = 'Kabel-Beschriftung';
        $lang['expert_menu'] = 'Expertenmenü';
        $lang['labels_on_hover_only'] = 'Beschriftung nur bei Hover';
        $lang['render_all_cables'] = 'Alle Kabel rendern';

        return $lang;
    }
    $lang = lang_de();
}
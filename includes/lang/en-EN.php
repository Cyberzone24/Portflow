<?php
if (isset($_GET['nav'])) {
    $nav = [];
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
    $nav['connection_join_device_port_join_device_port'] = [
        'type' => 'Type',
        'status' => 'Status',
        'speed' => 'Speed',
        'caption' => 'Caption',
        'device_port_caption_0' => 'Source',
        'device_port_caption_1' => 'Destination',
        'length' => 'Length'
    ];
    echo json_encode($nav);

} else {

    function lang_en() {
        $lang = array();

        $lang['lang'] = 'English';
        $lang['portflow'] = 'Portflow';
        $lang['itam'] = 'ITAM';
        $lang['portview'] = 'Portview';
        $lang['login'] = 'Login';
        $lang['logout'] = 'Logout';
        $lang['register'] = 'Register';
        $lang['settings'] = 'Settings';
        $lang['search'] = 'Search';
        $lang['it asset-management'] = 'IT Asset-Management';
        $lang['location'] = 'Standort';
        $lang['ipam'] = 'IPAM';
        $lang['vlan'] = 'VLAN';
        $lang['devices'] = 'Devices';
        $lang['device ports'] = 'Device Ports';
        $lang['connections'] = 'Connections';
        $lang['quantity'] = 'Quantity';

        return $lang;
    }
    $lang = lang_en();
}
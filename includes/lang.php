<?php
if (isset($_GET['nav'])) {
    $nav = [];
    $nav['location_join_location'] = [
        'type' => 'Type',
        'caption' => 'Caption',
        'location_type_0' => 'Parent Type',
        'location_caption_0' => 'Parent Location'
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
    $nav['vlan'] = [
        'caption' => 'Caption',
        'vlan' => 'VLAN ID',
    ];
    echo json_encode($nav);
} else {
    if (isset($_SESSION['settings'])) {
        $settings = json_decode($_SESSION['settings'], true);
        $language = $settings['language'];
        if($language == 'de-DE' || $language == 'de'){              include_once __DIR__ . '/lang/de-DE.php';   }
        elseif($language == 'en-EN' || $language == 'en'){          include_once __DIR__ . '/lang/en-EN.php';   }
        elseif($language == 'en-US' || $language == 'en'){          include_once __DIR__ . '/lang/en-EN.php';   }
        else{                                                       include_once __DIR__ . '/lang/en-EN.php';   }

    } elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $language = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        if($language[0] == 'de-DE' || $language[0] == 'de'){        include_once __DIR__ . '/lang/de-DE.php';   }
        elseif($language[0] == 'en-EN' || $language[0] == 'en'){    include_once __DIR__ . '/lang/en-EN.php';   }
        elseif($language[0] == 'en-US' || $language[0] == 'en'){    include_once __DIR__ . '/lang/en-EN.php';   }
        else{                                                       include_once __DIR__ . '/lang/en-EN.php';   }

    } else {
        include_once __DIR__ . '/lang/en-EN.php';
    }
}
<?php
if (isset($_GET['nav'])) {
    $nav = [];
    $nav['location'] = [
        'type' => 'Type',
        'caption' => 'Caption',
        'parent_location' => 'Parent Location'
    ];
    $nav['location_join_location'] = [
        'type' => 'Type',
        'caption' => 'Caption',
        'location_type_0' => 'Parent Type',
        'location_caption_0' => 'Parent Location'
    ];
    $nav['device'] = [
        'type' => 'Type',
        'caption' => 'Caption',
        'location' => 'Location',
        'manufacturer' => 'Manufacturer',
        'model' => 'Model',
        'serial' => 'Serial',
        'hostname' => 'Hostname',
        'mac_address' => 'MAC-Address'
    ];
    $nav['device_join_location_device_model'] = [
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
    $nav['device_port'] = [
        'caption' => 'Caption',
        'device' => 'Device',
        'tags' => 'Tags'
    ];
    $nav['device_port_join_device'] = [
        'type' => 'Type',
        'status' => 'Status',
        'speed' => 'Speed',
        'caption' => 'Caption',
        'device_caption_0' => 'Device',
        'tags' => 'Tags'
    ];
    $nav['connection'] = [
        'type' => 'Type',
        'status' => 'Status',
        'speed' => 'Speed',
        'caption' => 'Caption',
        'device_port_source' => 'Source',
        'device_port_destination' => 'Destination',
        'length' => 'Length'
    ];
    $nav['vlan'] = [
        'caption' => 'Caption',
        'vlan' => 'VLAN ID',
        'tagged' => 'Tagged'
    ];
    echo json_encode($nav);
} else {
    if(isset($_SESSION['language'])){
        $language = $_SESSION['language'];
        if($language == 'de-DE' || $language == 'de'){              include_once __DIR__ . '/lang/de-DE.php';   }
        elseif($language == 'en-EN' || $language == 'en'){          include_once __DIR__ . '/lang/en-EN.php';   }
        elseif($language == 'en-US' || $language == 'en'){          include_once __DIR__ . '/lang/en-EN.php';   }
        else{                                                       include_once __DIR__ . '/lang/en-EN.php';   }

    }elseif(isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])){
        $language = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        if($language[0] == 'de-DE' || $language[0] == 'de'){        include_once __DIR__ . '/lang/de-DE.php';   }
        elseif($language[0] == 'en-EN' || $language[0] == 'en'){    include_once __DIR__ . '/lang/en-EN.php';   }
        elseif($language[0] == 'en-US' || $language[0] == 'en'){    include_once __DIR__ . '/lang/en-EN.php';   }
        else{                                                       include_once __DIR__ . '/lang/en-EN.php';   }

    }else{
        include_once __DIR__ . '/lang/en-EN.php';
    }
}
<?php
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
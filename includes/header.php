<?php
    $active_page = basename($_SERVER['PHP_SELF'], ".php");
    
    if (isset($_GET['signout'])) {
        // destroy session
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['loggedin'] = FALSE;
        session_unset();
        session_destroy();
        $signout = defined('PORTFLOW_HOSTNAME') ? PORTFLOW_HOSTNAME : $_SERVER['HTTP_HOST'];
        header('Location: ' . $signout);
        die();
    }

    // import alert function
    include_once __DIR__ . '/alert.php';

    // import lanuage file
    include_once __DIR__ . '/lang.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <link href='https://fonts.googleapis.com/css?family=JetBrains Mono' rel='stylesheet'>
    <style>
    html * {
        font-family: 'JetBrains Mono';
    }
    </style>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['portflow']; ?></title>
    <link rel="icon" href="./includes/img/portflow.ico" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="./includes/js/lucide.min.js"></script>
</head>
<body class="static flex flex-col w-full h-screen item-center bg-gray-300">
<header class="w-full flex justify-between px-4">
    <div class="basis-1/6 py-4 flex items-center">
        <img class="h-full" src="./includes/img/portflow.png" alt="Portflow">
    </div>
    <ul class="basis-1/6 flex justify-center gap-8">
        <li class="flex items-end">
            <a class="<?= ($active_page == 'itam') ? 'bg-gray-100 rounded-t-xl py-4 px-8 text-blue-900 font-semibold' : 'bg-white rounded-xl inline-block py-2 my-2 px-8 text-gray-500 hover:text-gray-800'; ?>"
                href="itam.php" title="<?php echo $lang['it asset-management']; ?>"><?php echo $lang['itam']; ?></a>
        </li>
        <li class="flex items-end">
            <a class="<?= ($active_page == 'portview') ? 'bg-gray-100 rounded-t-xl py-4 px-8 text-blue-900 font-semibold' : 'bg-white rounded-xl inline-block py-2 my-2 px-8 text-gray-500 hover:text-gray-800'; ?>"
                href="portview.php" title="<?php echo $lang['portview']; ?>"><?php echo $lang['portview']; ?></a>
        </li>
    </ul>
    <div class="basis-1/6 py-4 flex flex-row items-center justify-end">
        <a href="?signout" title="<?php echo $lang['logout']; ?>" class="h-10 w-10 ml-4 rounded-full bg-gray-500 hover:bg-red-500 text-white text-2xl flex items-center justify-center shadow-md">
            <i data-lucide="log-out"></i>
        </a>
        <a href="settings.php" title="<?php echo $lang['settings']; ?>" class="h-10 w-10 ml-4 rounded-full bg-gray-500 hover:bg-gray-700 text-white text-2xl flex items-center justify-center shadow-md duration-500 hover:rotate-180">
            <i data-lucide="settings"></i>
        </a>
    </div>
</header>
<?php
    $active_page = basename($_SERVER['PHP_SELF'], ".php");

    function signout() {
        // destroy session
        session_start();
        $_SESSION['loggedin'] = FALSE;
        session_destroy();
        header('Location: ' . defined('PORTFLOW_HOSTNAME') ? PORTFLOW_HOSTNAME : $_SERVER['HTTP_HOST']);
    }
    
    if (isset($_GET['signout'])) {
        signout();
    }

    // Import alert function
    include_once __DIR__ . '/alert.php';
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
    <title>Portflow</title>
    <script src="https://cdn.tailwindcss.com"></script></head>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
<header>
    <div class="w-full flex justify-between bg-gray-300 px-4">
        <div class="basis-1/6 py-4 flex items-center">
            <img class="h-full" src="./includes/img/portflow.png" alt="Portflow">
        </div>
        <ul class="basis-1/6 flex justify-center">
            <li class="flex items-end">
                <a class="<?= ($active_page == 'portview') ? 'bg-gray-100 rounded-t-lg py-2 px-4 text-blue-900 font-semibold' : 'bg-white rounded-xl inline-block py-1.5 px-4 text-gray-500 hover:text-gray-800'; ?>"
                    href="portview.php">Portview</a>
            </li>
        </ul>
        <div class="basis-1/6 py-4 flex flex-row items-center justify-end">
            <div class="h-10 w-10 ml-4 rounded-full bg-gray-500 hover:bg-red-500 text-white text-2xl flex items-center justify-center shadow-md">
                <a href="?signout"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
            </div>
            <div class="h-10 w-10 ml-4 rounded-full bg-gray-500 hover:bg-gray-700 text-white text-2xl flex items-center justify-center shadow-md duration-500 hover:rotate-180">
                <i class="fa-solid fa-gear"></i>
            </div>
        </div>
    </div>
</header>
<body class="static flex flex-col w-full h-screen item-center bg-gray-100">
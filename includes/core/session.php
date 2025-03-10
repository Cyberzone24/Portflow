<?php
function uri() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $position = strrpos($_SERVER['SERVER_ADDR'] . $_SERVER['REQUEST_URI'], basename($_SERVER['PHP_SELF']));
    return $position !== false ? $protocol . substr($_SERVER['SERVER_ADDR'] . $_SERVER['REQUEST_URI'], 0, $position) : null;
}

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: ' . uri());
    exit;
} else {
    include_once __DIR__ . '/config.php';
}

// Start the session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if ($_SESSION['loggedin'] != TRUE) {
    header('Location: ' . PORTFLOW_HOSTNAME);
    exit();
}
<?php
function uri() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $requestUri = $_SERVER['REQUEST_URI'];
    return $protocol . $host . $requestUri;
}

if (!file_exists(__DIR__ . '/../../.env')) {
    header('Location: ' . uri());
    exit;
} else {
    include_once __DIR__ . '/config.php';
}

// Start the session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['uuid']) || empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== TRUE) {
    $_SESSION['referrer'] = uri();
    header('Location: ' . PORTFLOW_HOSTNAME);
    exit();
}
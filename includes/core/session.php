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
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $isApiRequest = preg_match('#(?:^|/)api(?:/|$)#', $requestPath) === 1;

    if ($isApiRequest) {
        return;
    }

    $_SESSION['referrer'] = uri();
    header('Location: ' . PORTFLOW_HOSTNAME);
    exit();
}
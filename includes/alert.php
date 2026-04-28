<?php
function portflowAlertEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portflowAlertColorClass(string $level): string {
    switch ($level) {
        case '0':
            return 'bg-gray-500';
        case '1':
            return 'bg-green-500';
        case '2':
            return 'bg-yellow-500';
        case '3':
            return 'bg-red-500';
        case '4':
            return 'bg-blue-500';
        default:
            return 'bg-slate-500';
    }
}

$alerts = [];
$cookieAlertNames = [];

foreach ($_COOKIE as $name => $value) {
    if (strpos($name, 'alert_') !== 0) {
        continue;
    }

    $parts = explode('_', $name);
    $alerts[] = [
        'level' => (string)($parts[1] ?? '4'),
        'message' => (string)$value,
    ];
    $cookieAlertNames[] = $name;
}

if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['portflow_alerts']) && is_array($_SESSION['portflow_alerts'])) {
    foreach ($_SESSION['portflow_alerts'] as $sessionAlert) {
        if (!is_array($sessionAlert)) {
            continue;
        }

        $alerts[] = [
            'level' => (string)($sessionAlert['level'] ?? '4'),
            'message' => (string)($sessionAlert['message'] ?? ''),
        ];
    }

    unset($_SESSION['portflow_alerts']);
}

foreach ($cookieAlertNames as $cookieName) {
    unset($_COOKIE[$cookieName]);
    if (!headers_sent()) {
        setcookie($cookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => defined('PORTFLOW_SECURE') ? PORTFLOW_SECURE : false,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }
}

if (!empty($alerts)) {
    echo <<<HTML
    <div class='max-w-lg w-full fixed top-0 left-1/2 transform -translate-x-1/2 z-50'>
        <div class="flex flex-col justify-center">
    HTML;

    foreach ($alerts as $alert) {
        $bgColor = portflowAlertColorClass((string)($alert['level'] ?? '4'));
        $escapedValue = portflowAlertEscape((string)($alert['message'] ?? ''));

        echo <<<HTML
        <div class='w-full my-2 rounded-3xl shadow-lg $bgColor bg-opacity-80 text-white text-center py-2 relative group' onclick='this.remove()'>
            $escapedValue
            <span class='h-full flex items-center justify-center rounded-3xl $bgColor text-white text-center left-1/2 transform -translate-x-1/2 absolute top-0 py-2 group w-full opacity-0 group-hover:opacity-100 transition duration-200 ease-in-out'>
                Click to remove
            </span>
        </div>
        HTML;
    }

    echo '</div></div>';
}
?>
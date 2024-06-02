<?php
// Check if alert cookie exists
$alertCookieExists = false;
foreach ($_COOKIE as $name => $value) {
    if (strpos($name, 'alert') === 0) {
        $alertCookieExists = true;
        break;
    }
}

// Display alerts if cookie exists
if ($alertCookieExists) {
    echo <<<HTML
    <div class='fixed top-0 z-50 w-full max-w-lg'>
    HTML;

    // Iterate through alert cookies
    foreach ($_COOKIE as $name => $value) {
        if (strpos($name, 'alert') === 0) {
            $level = explode('_', $name)[1];
            $bgColor = '';

            // Set background color based on alert level
            switch ($level) {
                case '0':
                    $bgColor = 'bg-gray-500';
                    break;
                case '1':
                    $bgColor = 'bg-green-500';
                    break;
                case '2':
                    $bgColor = 'bg-yellow-500';
                    break;
                case '3':
                    $bgColor = 'bg-red-500';
                    break;
                case '4':
                    $bgColor = 'bg-blue-500';
                    break;
            }

            // Display alert div
            echo <<<HTML
            <div class='my-2 rounded-3xl shadow-lg $bgColor bg-opacity-80 text-white text-center py-2 relative group' onclick='this.remove()'>
                $value
                <span class='rounded-3xl $bgColor text-white text-center left-1/2 transform -translate-x-1/2 absolute top-0 py-2 group w-full opacity-0 group-hover:opacity-100 transition duration-200 ease-in-out'>
                    Click to remove
                </span>
            </div>
            HTML;

            // Remove and delete the alert cookie
            unset($_COOKIE[$name]);
            setcookie($name, '', time() - 3600, '/');
        }
    }

    echo '</div>';
}
?>
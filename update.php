<?php
    const APP_NAME = 'Portflow';
    
    if (!file_exists(__DIR__ . '/.env')) {
        header('Location: setup.php');
        exit;
    }
    
    include_once __DIR__ . '/includes/core/config.php';
    include_once __DIR__ . '/includes/core/automation.php';
    
    // import alert function
    include_once __DIR__ . '/includes/alert.php';
    
    // import auth
    include_once __DIR__ . '/includes/core/auth.php';
    use Portflow\Core\Auth;
    
    // Update database schema
    include_once __DIR__ . '/includes/core/db_adapter.php';
    use Portflow\Core\DatabaseAdapter;
    $db_adapter = new DatabaseAdapter();
    $db_adapter->db_update_schema();

    echo "Database schema updated successfully.";
?>
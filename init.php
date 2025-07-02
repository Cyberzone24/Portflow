<?php

const APP_NAME = 'Portflow';

// import db_adapter
include_once __DIR__ . '/includes/core/db_adapter.php';
use Portflow\Core\DatabaseAdapter;

$db = new DatabaseAdapter();

$db->db_init();

echo "Database init";

?>
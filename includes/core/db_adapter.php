<?php
namespace Portflow\Core;

// check if APP_NAME is defined
if (!defined('APP_NAME')) {
    die('Access denied');
}

// import config
include_once __DIR__ . '/config.php';

// import logger
include_once __DIR__ . '/logger.php';
use Portflow\Core\Logger;

use Exception;
use PDO;
use PDOException;

class DatabaseAdapter {
    private $pdo;
    private $logger;

    public function __construct() {
        $this->logger = new Logger();
        $this->db_conn();
    }

    private function db_conn(){
        // server settings
        $db_type = DB_TYPE;
        $db_server = DB_SERVER;
        $db_port = DB_PORT;
        $db_dbname = DB_NAME;
        $db_username = DB_USER;
        $db_password = DB_PASSWORD;

        // create and check connection
        try {
            $this->pdo = new PDO("$db_type:host=$db_server;port=$db_port;dbname=$db_dbname", $db_username, $db_password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->logger->log('pdo connection established');
        } catch (PDOException $e) {
            $this->logger->log('pdo connection error: ' . $e->getMessage());
            throw new \Exception('pdo connection error ' . $e->getMessage());
        }
    }

    public function checkDatabaseAndTableExistence($tableName) {
        try {
            $stmt = $this->pdo->prepare("SELECT to_regclass('public.$tableName')");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
            if ($result && $result['to_regclass'] === null) {
                $this->logger->log('table ' . $tableName . ' does not exist.', 3, echoToWeb: true);
                // Die Tabelle existiert nicht
                return false;
            }
            // Die Tabelle existiert
            return true;
        } catch (PDOException $e) {
            $this->logger->log('error checking table existence: ' . $e->getMessage(), 3, echoToWeb: true);
            return false;
        }
    }


    public function db_query($query, $params = []){
        $this->logger->log('starting query');
    
        // prepare query
        $stmt = $this->pdo->prepare($query);
    
        // bind parameters and execute query
        foreach ($params as $param => $value) {
            if (is_array($value)) {
                throw new \Exception("Parameter '$param' is an array, but it should be a string or a number.");
            }
            $stmt->bindValue(':'.$param, $value);
        }

        $stmt->execute();

        // fetch results and return
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $results;
    }

    public function db_init() {
        // get content of db_tables.json, convert to array
        $db_tables = json_decode(file_get_contents(__DIR__ . '/db_tables.json'), true);
    
        // iterate over array and create tables
        foreach ($db_tables as $db_table => $columns) {
            $query = "CREATE TABLE IF NOT EXISTS $db_table (";
            foreach ($columns as $column => $column_type) {
                $query .= "$column $column_type, ";
            }
            $query = rtrim($query, ', ') . ');';

            try {
                // start transaction
                $this->pdo->beginTransaction();

                // execute query
                $this->db_query($query, []);

                // commit transaction
                $this->pdo->commit();

                $this->logger->log("finished for $db_table");
            } catch (\Exception $e) {
                // roll back transaction if there was an error
                $this->pdo->rollBack();
                $this->logger->log('error during initialization of database: ' . $e->getMessage());
            }
        }
    }        
}

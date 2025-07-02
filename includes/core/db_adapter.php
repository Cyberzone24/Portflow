<?php
namespace Portflow\Core;

// check if APP_NAME is defined
if (!defined('APP_NAME')) {
    die('Access denied');
}

// import config
if (file_exists(__DIR__ . '/config.php')) {
    include_once __DIR__ . '/config.php';
}

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

        if (file_exists(__DIR__ . '/config.php')) {
            $this->db_conn();
        } else {
            $this->logger->log('config.php not found', 3);
        }
    }

    private function db_conn(){
        // server settings
        $dbType = DB_TYPE;
        $dbServer = DB_SERVER;
        $dbPort = DB_PORT;
        $dbName = DB_NAME;
        $dbUsername = DB_USER;
        $dbPassword = DB_PASSWORD;
        $dbType = DB_TYPE;
        $dbServer = DB_SERVER;
        $dbPort = DB_PORT;
        $dbName = DB_NAME;
        $dbUsername = DB_USER;
        $dbPassword = DB_PASSWORD;

        // create and check connection
        try {
            $this->pdo = new PDO("$dbType:host=$dbServer;port=$dbPort;dbname=$dbName", $dbUsername, $dbPassword);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->logger->log('pdo connection established', 0);
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
        $this->logger->log("starting query: $query", 0);
    
        // prepare query
        $stmt = $this->pdo->prepare($query);
    
        // bind parameters and execute query
        foreach ($params as $param => $value) {
            if (is_array($value)) {
                throw new \Exception("Parameter '$param' is an array, but it should be a string or a number.");
            }
            $stmt->bindValue(':'.$param, $value);
        }
    
        try {
            $stmt->execute();
            // Fetch results and return
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $results;
        } catch (\Exception $e) {
            $this->logger->log('error during query execution: ' . $query . ' - ' . $e->getMessage(), 1);
            throw $e;
        }

        // ============================================================= HIER CHANGELOG =============================================================
    }
    
    public function db_init() {
        // get content of db_tables.json, convert to array
        $dbTables = json_decode(file_get_contents(__DIR__ . '/db_tables.json'), true);

        // Store foreign keys for view creation
        $foreignKeys = [];

        // iterate over array and create tables
        foreach ($dbTables as $dbTable => $columns) {
            $query = "CREATE TABLE IF NOT EXISTS $dbTable (";
            foreach ($columns as $column => $columnType) {
                $query .= "$column $columnType, ";
                $this->logger->log("Column $column with type $columnType added to table $dbTable", 0);

                // Check for foreign key definition
                if (strpos($columnType, 'REFERENCES') !== false) {
                    preg_match('/([a-zA-Z0-9_]+) REFERENCES ([a-zA-Z0-9_]+)\(([^)]+)\)/i', $columnType, $matches);
                    if ($matches) {
                        $foreignKeys[$dbTable][] = [
                            'column' => $column,
                            'referenced_table' => $matches[2],
                            'referenced_column' => $matches[3]
                        ];
                    }
                }
            }
            $query = rtrim($query, ', ') . ');';

            try {
                // start transaction
                $this->pdo->beginTransaction();

                // execute query
                $this->db_query($query, []);

                // commit transaction
                $this->pdo->commit();

                $this->logger->log("Created table $dbTable");
            } catch (\Exception $e) {
                // roll back transaction if there was an error
                $this->pdo->rollBack();
                $this->logger->log('Error during initialization of database: ' . $e->getMessage());
            }

            try {
                // create folder for each database table
                $excludedTables = ['role', 'users', 'changelog', 'metadata', 'access', 'device_port_vlan', 'device_port_ip', 'device_lifecycle'];
                if (!file_exists(__DIR__ . '/../../data/' . $dbTable) && !in_array($dbTable, $excludedTables, true)) {
                    mkdir(__DIR__ . '/../../data/' . $dbTable, 0755, true);
                    $this->logger->log("Created folder for table $dbTable");
                }
            } catch (\Exception $e) {
                $this->logger->log('Error during creation of folder for table: ' . $e->getMessage());
            }
        }
    
        // Create views based on foreign keys
        $viewsFile = __DIR__ . '/db_views.txt';
        if (!file_exists($viewsFile)) {
            $this->logger->log("View definition file not found: $viewsFile");
            return;
        }

        $viewLines = file($viewsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($viewLines as $line) {
            $joins = array_map('trim', explode(',', $line));
            $selectClause = [];
            $joinClause = '';
            $aliasMap = []; // [table] => alias
            $aliasCounter = 0;
            $fromSet = false;
        
            // Aliase für jede Tabelle in der Reihenfolge ihres ersten Auftretens
            foreach ($joins as $join) {
                if (!preg_match('/^([\w.]+)\s+join\s+([\w.]+)$/i', $join, $match)) {
                    $this->logger->log("Invalid join syntax: $join");
                    continue 2;
                }
                [$left, $right] = [$match[1], $match[2]];
                list($table1, ) = explode('.', $left);
                list($table2, ) = explode('.', $right);
        
                if (!isset($aliasMap[$table1])) $aliasMap[$table1] = 't' . ($aliasCounter++);
                if (!isset($aliasMap[$table2])) $aliasMap[$table2] = 't' . ($aliasCounter++);
            }
        
            foreach ($joins as $i => $join) {
                preg_match('/^([\w.]+)\s+join\s+([\w.]+)$/i', $join, $match);
                [$left, $right] = [$match[1], $match[2]];
                list($table1, $column1) = explode('.', $left);
                list($table2, $column2) = explode('.', $right);
        
                $alias1 = $aliasMap[$table1];
                $alias2 = $aliasMap[$table2];
        
                if (!$fromSet) {
                    $joinClause .= "FROM $table1 $alias1 ";
                    $fromSet = true;
                }
                $joinClause .= "LEFT JOIN $table2 $alias2 ON $alias1.$column1 = $alias2.$column2 ";
            }
        
            // SELECT für alle Aliase (nur einmal pro Alias)
            $seen = [];
            foreach ($aliasMap as $table => $alias) {
                if (isset($seen[$alias])) continue;
                $seen[$alias] = true;
                $columns = $this->db_query("SELECT column_name FROM information_schema.columns WHERE table_name = '$table'");
                foreach ($columns as $col) {
                    $colname = $col['column_name'];
                    $selectClause[] = "$alias.$colname AS {$table}_$colname";
                }
            }
        
            // View-Name aus allen Tabellennamen (unique, Reihenfolge wie im Join)
            $viewName = implode('_join_', array_keys($aliasMap));
            $selectSQL = implode(", ", $selectClause);
            $viewSQL = "CREATE OR REPLACE VIEW $viewName AS SELECT $selectSQL $joinClause;";
        
            try {
                $this->pdo->beginTransaction();
                $this->db_query($viewSQL, []);
                $this->pdo->commit();
                $this->logger->log("Created view: $viewName");
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                $this->logger->log("Error creating view $viewName: " . $e->getMessage());
            }
        }
        $this->logger->log("DB initialized");
    }    
}
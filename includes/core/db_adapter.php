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
            $this->logger->log('error during query execution: ' . $query . ' - ' . $e->getMessage());
            throw $e;
        }
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
        }
    
        // Create changelog trigger function ================================================================================================= USER ID muss noch irgendwo her kommen ================================================================================================
        $triggerFunctionSQL = "
            CREATE OR REPLACE FUNCTION log_changes()
            RETURNS TRIGGER AS $$
            DECLARE
                user_id UUID;
            BEGIN
                -- Assumes current_setting('app.current_user') is set with the user's UUID
                user_id := current_setting('app.current_user')::UUID;
    
                -- Insert into changelog table
                INSERT INTO changelog (users, operation, changed_table, changed_row, changed_data, changed)
                VALUES (
                    user_id,
                    TG_OP,
                    TG_TABLE_NAME,
                    COALESCE(NEW.uuid, OLD.uuid),
                    CASE WHEN TG_OP = 'DELETE' THEN row_to_json(OLD)::text ELSE row_to_json(NEW)::text END,
                    CURRENT_TIMESTAMP
                );
    
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        ";
    
        try {
            $this->pdo->exec($triggerFunctionSQL);
            $this->logger->log("Created changelog trigger function");
        } catch (\Exception $e) {
            $this->logger->log('Error creating changelog trigger function: ' . $e->getMessage());
        }
    
        // Add triggers to each table for INSERT, UPDATE, DELETE
        foreach ($dbTables as $dbTable => $columns) {
            $triggerSQL = "
                CREATE TRIGGER {$dbTable}_change_log
                AFTER INSERT OR UPDATE OR DELETE ON {$dbTable}
                FOR EACH ROW EXECUTE FUNCTION log_changes();
            ";
    
            try {
                $this->pdo->exec($triggerSQL);
                $this->logger->log("Created changelog trigger for table $dbTable");
            } catch (\Exception $e) {
                $this->logger->log("Error creating changelog trigger for table $dbTable: " . $e->getMessage());
            }
        }
    
        // Create views based on foreign keys
        foreach ($foreignKeys as $mainTable => $fks) {
            // Initialize the base SELECT clause and the JOIN clauses
            $selectClause = [];
            $joinClauses = [];
            $mainTableAlias = 'm';

            // Get columns of the main table
            $mainColumnsQuery = $this->db_query("SELECT column_name FROM information_schema.columns WHERE table_name = '$mainTable'");
            $mainColumns = array_column($mainColumnsQuery, 'column_name');

            // Add main table columns to the select clause
            foreach ($mainColumns as $column) {
                $selectClause[] = "$mainTableAlias.$column AS $column";
            }

            // Process each foreign key and create join clauses
            foreach ($fks as $index => $fk) {
                $referencedTable = $fk['referenced_table'];
                $referencedTableAlias = 'r' . $index;

                // Get columns of the referenced table
                $referencedColumnsQuery = $this->db_query("SELECT column_name FROM information_schema.columns WHERE table_name = '$referencedTable'");
                $referencedColumns = array_column($referencedColumnsQuery, 'column_name');

                // Add referenced table columns to the select clause with aliases
                foreach ($referencedColumns as $column) {
                    $selectClause[] = "$referencedTableAlias.$column AS {$referencedTable}_{$column}_$index";
                }

                // Add join clause for the foreign key
                $joinClauses[] = "LEFT JOIN $referencedTable $referencedTableAlias ON $mainTableAlias.{$fk['column']} = $referencedTableAlias.{$fk['referenced_column']}";
            }

            // Combine all parts to create the view query
            $selectClause = implode(', ', $selectClause);
            $joinClauses = implode(' ', $joinClauses);

            // Create the view name by adding '_join_' before each table
            $viewNameParts = array_column($fks, 'referenced_table');
            array_unshift($viewNameParts, $mainTable);
            $viewName = implode('_join_', $viewNameParts);

            $createViewQuery = "
                CREATE OR REPLACE VIEW $viewName AS
                SELECT $selectClause
                FROM $mainTable $mainTableAlias
                $joinClauses;
            ";

            try {
                // start transaction
                $this->pdo->beginTransaction();

                // execute view creation query
                $this->db_query($createViewQuery, []);

                // commit transaction
                $this->pdo->commit();

                $this->logger->log("Created view $viewName");
            } catch (\Exception $e) {
                // roll back transaction if there was an error
                $this->pdo->rollBack();
                $this->logger->log('Error during creation of view: ' . $e->getMessage());
            }
        }
        $this->logger->log("DB initialized");
    }    
}
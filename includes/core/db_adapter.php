<?php
namespace Portflow\Core;

// check if APP_NAME is defined
if (!defined('APP_NAME')) {
    die('Access denied');
}

// import config
if (file_exists(__DIR__ . '/../../.env')) {
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

    private array $excludedDataFolders = ['role', 'users', 'changelog', 'metadata', 'access', 'device_port_vlan', 'device_port_ip', 'device_lifecycle'];

    public function __construct() {
        $this->logger = new Logger();

        if (file_exists(__DIR__ . '/../../.env')) {
            $this->db_conn();
        } else {
            $this->logger->log('.env not found', 3);
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

            $pdoType = PDO::PARAM_STR;
            if (is_int($value)) {
                $pdoType = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $pdoType = PDO::PARAM_BOOL;
            } elseif (is_null($value)) {
                $pdoType = PDO::PARAM_NULL;
            }

            $stmt->bindValue(':' . $param, $value, $pdoType);
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

    private function getDbTablesConfiguration(): array {
        $dbTablesPath = __DIR__ . '/db_tables.json';
        $dbTables = json_decode(file_get_contents($dbTablesPath), true);

        if (!is_array($dbTables)) {
            throw new Exception('Invalid db_tables.json configuration');
        }

        return $dbTables;
    }

    private function buildCreateTableQuery(string $dbTable, array $columns): string {
        $query = "CREATE TABLE IF NOT EXISTS $dbTable (";

        foreach ($columns as $column => $columnType) {
            $query .= "$column $columnType, ";
            $this->logger->log("Column $column with type $columnType added to table $dbTable", 0);
        }

        return rtrim($query, ', ') . ');';
    }

    private function ensureDataFolderForTable(string $dbTable): void {
        if (!file_exists(__DIR__ . '/../../data/' . $dbTable) && !in_array($dbTable, $this->excludedDataFolders, true)) {
            mkdir(__DIR__ . '/../../data/' . $dbTable, 0755, true);
            $this->logger->log("Created folder for table $dbTable");
        }
    }

    private function getExistingColumns(string $tableName): array {
        $query = "
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = :table_name
        ";

        $results = $this->db_query($query, ['table_name' => $tableName]);
        return array_column($results, 'column_name');
    }

    private function createOrReplaceViews(array $dbTables): void {
        $this->logger->log("Starting view creation...");
        $viewDefinitions = file(__DIR__ . '/db_views.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($viewDefinitions as $definition) {
            try {
                $this->pdo->beginTransaction();

                $trimmed_definition = trim($definition);
                $joins = explode(', ', $trimmed_definition);
                $firstJoinParts = explode(' ', $joins[0]);
                $baseTable = explode('.', $firstJoinParts[0])[0];

                // naming logic for views
                $viewName = '';
                if (strpos($trimmed_definition, ',') !== false) {
                    // Complex view with multiple joins gets '_details' suffix
                    $viewName = $baseTable . '_details';
                } else {
                    // Simple view with a single join gets 'table1_join_table2' name
                    preg_match('/join\s+([a-zA-Z0-9_]+)\./', $trimmed_definition, $targetMatches);
                    $targetTable = $targetMatches[1] ?? null;
                    if ($baseTable && $targetTable) {
                        $viewName = "{$baseTable}_join_{$targetTable}";
                    } else {
                        // Fallback or error if the simple view name cannot be determined
                        throw new \Exception("Could not determine simple view name from definition: '$trimmed_definition'");
                    }
                }

                $selects = [];
                $joinClauses = [];
                $aliases = [$baseTable => 't0'];

                foreach (array_keys($dbTables[$baseTable]) as $column) {
                    if ($column === 'PRIMARY KEY') continue;
                    $selects[] = "t0.\"$column\" AS \"{$baseTable}_{$column}\"";
                }

                $aliasCounter = 1;
                foreach ($joins as $join) {
                    preg_match('/(.+?)\s+join\s+([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)/', $join, $matches);
                    if (count($matches) !== 4) continue;

                    // vars
                    $sourcePathString = $matches[1];
                    $targetTable = $matches[2];
                    $targetColumn = $matches[3];
                    $sourceTableAlias = null;
                    $sourceColumn = null;
                    $pathParts = explode('.', $sourcePathString);

                    // Find the longest prefix of the source path that we have an alias for.
                    for ($i = count($pathParts); $i >= 1; $i--) {
                        $potentialTablePath = implode('.', array_slice($pathParts, 0, $i));
                        if (isset($aliases[$potentialTablePath])) {
                            $sourceTableAlias = $aliases[$potentialTablePath];
                            // The source column is the next part of the path, if it exists.
                            $sourceColumn = $pathParts[$i] ?? null;
                            break;
                        }
                    }

                    // If no prefix path was found, assume it's a column on the base table.
                    if ($sourceTableAlias === null) {
                        $sourceTableAlias = $aliases[$baseTable];
                        $sourceColumn = $sourcePathString;
                    }

                    if ($sourceTableAlias === null || $sourceColumn === null) {
                        throw new \Exception("Could not resolve join path for '$sourcePathString' in view '$viewName'");
                    }

                    $newAlias = 't' . $aliasCounter++;
                    $aliases[$sourcePathString] = $newAlias;

                    $joinClauses[] = "LEFT JOIN \"$targetTable\" AS $newAlias ON $sourceTableAlias.\"$sourceColumn\" = $newAlias.\"$targetColumn\"";

                    $columnPrefix = str_replace('.', '_', $sourcePathString);
                    if (!empty($dbTables[$targetTable])) {
                        foreach (array_keys($dbTables[$targetTable]) as $column) {
                            if ($column === 'PRIMARY KEY') continue;
                            $selects[] = "$newAlias.\"$column\" AS \"{$columnPrefix}_{$column}\"";
                        }
                    }
                }

                $selectClause = "SELECT\n    " . implode(",\n    ", $selects);
                $fromClause = "\nFROM \"$baseTable\" AS t0";
                $joinClauseStr = "\n" . implode("\n", $joinClauses);

                // Note the added quotes around the view name for safety
                $query = "CREATE OR REPLACE VIEW \"$viewName\" AS $selectClause$fromClause$joinClauseStr;";

                $this->db_query($query);
                $this->logger->log("Successfully created or replaced view: \"$viewName\"");

                $this->pdo->commit();
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                $this->logger->log("Error creating view from definition '$definition': " . $e->getMessage());
            }
        }
    }

    public function db_update_schema() {
        $dbTables = $this->getDbTablesConfiguration();

        foreach ($dbTables as $dbTable => $columns) {
            try {
                $tableExists = $this->checkDatabaseAndTableExistence($dbTable);

                if (!$tableExists) {
                    $this->pdo->beginTransaction();
                    $query = $this->buildCreateTableQuery($dbTable, $columns);
                    $this->db_query($query, []);
                    $this->pdo->commit();
                    $this->logger->log("Created missing table $dbTable during schema update");
                } else {
                    $existingColumns = $this->getExistingColumns($dbTable);

                    foreach ($columns as $column => $columnType) {
                        if ($column === 'PRIMARY KEY') {
                            continue;
                        }

                        if (!in_array($column, $existingColumns, true)) {
                            $this->pdo->beginTransaction();
                            $alterQuery = "ALTER TABLE \"$dbTable\" ADD COLUMN \"$column\" $columnType";
                            $this->db_query($alterQuery, []);
                            $this->pdo->commit();
                            $this->logger->log("Added missing column $column to table $dbTable");
                        }
                    }
                }
            } catch (\Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->logger->log("Error while updating schema for table $dbTable: " . $e->getMessage(), 1);
            }

            try {
                $this->ensureDataFolderForTable($dbTable);
            } catch (\Exception $e) {
                $this->logger->log('Error during creation of folder for table: ' . $e->getMessage());
            }
        }

        $this->createOrReplaceViews($dbTables);
        $this->logger->log('Database schema update finished');
    }
    
    public function db_init() {
        // get content of db_tables.json, convert to array
        $dbTables = $this->getDbTablesConfiguration();
    
        // iterate over array and create tables
        foreach ($dbTables as $dbTable => $columns) {
            $query = $this->buildCreateTableQuery($dbTable, $columns);
    
            try {
                $this->pdo->beginTransaction();
                $this->db_query($query, []);
                $this->pdo->commit();
                $this->logger->log("Created table $dbTable");
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                $this->logger->log('Error during initialization of database: ' . $e->getMessage());
            }
    
            try {
                $this->ensureDataFolderForTable($dbTable);
            } catch (\Exception $e) {
                $this->logger->log('Error during creation of folder for table: ' . $e->getMessage());
            }
        }

        $this->createOrReplaceViews($dbTables);
        $this->logger->log("DB initialized");
    }
}
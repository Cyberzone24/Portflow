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
    private array $auditExcludedTables = ['changelog'];
    private array $auditUserNoiseColumns = ['last_login', 'last_login_attempt', 'login_attempts', 'ip_address', 'changed'];

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
            $affectedRows = $stmt->rowCount();
            // Fetch results and return
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->writeAuditTrail($query, $params, $results, $affectedRows);
            return $results;
        } catch (\Exception $e) {
            $this->logger->log('error during query execution: ' . $query . ' - ' . $e->getMessage(), 1);
            throw $e;
        }

        // ============================================================= HIER CHANGELOG =============================================================
    }

    private function writeAuditTrail(string $query, array $params, array $results, int $affectedRows): void {
        $parsed = $this->parseAuditOperation($query);
        if ($parsed === null) {
            return;
        }

        $operation = $parsed['operation'];
        $table = $parsed['table'];

        if (in_array($table, $this->auditExcludedTables, true)) {
            return;
        }

        if ($table === 'users' && $operation === 'UPDATE') {
            $updatedColumns = $this->extractUpdatedColumns($query);
            if (!empty($updatedColumns) && $this->isUserNoiseUpdate($updatedColumns)) {
                return;
            }
        }

        $context = $this->buildAuditContext();
        $changedRow = $this->extractChangedRowUuid($results, $params);

        $payload = [
            'source' => $context['source'],
            'request_method' => $context['method'],
            'request_uri' => $context['uri'],
            'ip' => $context['ip'],
            'user_agent' => $context['user_agent'],
            'affected_rows' => $affectedRows,
            'params' => $this->sanitizeAuditParams($params)
        ];

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encodedPayload)) {
            $encodedPayload = '{"error":"audit_payload_encoding_failed"}';
        }

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO changelog (users, operation, changed_table, changed_row, changed_data)
                 VALUES (:users, :operation, :changed_table, :changed_row, :changed_data)"
            );
            $stmt->bindValue(':users', $context['users'], $context['users'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':operation', $operation, PDO::PARAM_STR);
            $stmt->bindValue(':changed_table', $table, PDO::PARAM_STR);
            $stmt->bindValue(':changed_row', $changedRow, PDO::PARAM_STR);
            $stmt->bindValue(':changed_data', $encodedPayload, PDO::PARAM_STR);
            $stmt->execute();
        } catch (\Throwable $e) {
            // Audit logging must never break business operations.
            $this->logger->log('audit write skipped: ' . $e->getMessage(), 0);
        }
    }

    private function parseAuditOperation(string $query): ?array {
        $trimmed = ltrim($query);
        if (!preg_match('/^(INSERT|UPDATE|DELETE)\s+/i', $trimmed, $operationMatch)) {
            return null;
        }

        $operation = strtoupper((string)$operationMatch[1]);
        $table = '';

        if ($operation === 'INSERT' && preg_match('/^INSERT\s+INTO\s+"?([a-zA-Z0-9_]+)"?/i', $trimmed, $tableMatch)) {
            $table = (string)$tableMatch[1];
        } elseif ($operation === 'UPDATE' && preg_match('/^UPDATE\s+"?([a-zA-Z0-9_]+)"?/i', $trimmed, $tableMatch)) {
            $table = (string)$tableMatch[1];
        } elseif ($operation === 'DELETE' && preg_match('/^DELETE\s+FROM\s+"?([a-zA-Z0-9_]+)"?/i', $trimmed, $tableMatch)) {
            $table = (string)$tableMatch[1];
        }

        if ($table === '') {
            return null;
        }

        return [
            'operation' => $operation,
            'table' => $table
        ];
    }

    private function extractUpdatedColumns(string $query): array {
        $trimmed = ltrim($query);
        if (!preg_match('/^UPDATE\s+"?[a-zA-Z0-9_]+"?\s+SET\s+(.+?)\s+WHERE\s+/is', $trimmed, $setMatch)) {
            return [];
        }

        $setClause = (string)$setMatch[1];
        $parts = preg_split('/\s*,\s*/', $setClause) ?: [];
        $columns = [];

        foreach ($parts as $part) {
            if (preg_match('/^"?([a-zA-Z0-9_]+)"?\s*=/', trim($part), $columnMatch)) {
                $columns[] = strtolower((string)$columnMatch[1]);
            }
        }

        return array_values(array_unique($columns));
    }

    private function isUserNoiseUpdate(array $updatedColumns): bool {
        if (empty($updatedColumns)) {
            return false;
        }

        foreach ($updatedColumns as $column) {
            if (!in_array($column, $this->auditUserNoiseColumns, true)) {
                return false;
            }
        }

        return true;
    }

    private function buildAuditContext(): array {
        $userUuid = null;
        if (isset($_SESSION) && is_array($_SESSION) && !empty($_SESSION['uuid'])) {
            $userUuid = (string)$_SESSION['uuid'];
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'CLI';
        $source = (strpos($uri, '/api/') !== false) ? 'api' : 'web';
        if (PHP_SAPI === 'cli') {
            $source = 'cli';
        }

        return [
            'users' => $userUuid,
            'source' => $source,
            'method' => $method,
            'uri' => $uri,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : ''
        ];
    }

    private function extractChangedRowUuid(array $results, array $params): string {
        if (!empty($results) && is_array($results[0]) && !empty($results[0]['uuid'])) {
            $candidate = (string)$results[0]['uuid'];
            if ($this->isValidUuid($candidate)) {
                return $candidate;
            }
        }

        if (!empty($params['uuid'])) {
            $candidate = (string)$params['uuid'];
            if ($this->isValidUuid($candidate)) {
                return $candidate;
            }
        }

        return $this->generateUuidV4();
    }

    private function sanitizeAuditParams(array $params): array {
        $sanitized = [];
        foreach ($params as $key => $value) {
            $lowerKey = strtolower((string)$key);
            if (strpos($lowerKey, 'password') !== false || strpos($lowerKey, 'secret') !== false || strpos($lowerKey, 'token') !== false) {
                $sanitized[$key] = '***';
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $text = (string)$value;
                if (strlen($text) > 512) {
                    $text = substr($text, 0, 512) . '...';
                }
                $sanitized[$key] = $text;
            } else {
                $sanitized[$key] = '[non-scalar]';
            }
        }

        return $sanitized;
    }

    private function isValidUuid(string $value): bool {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    private function generateUuidV4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
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

    private function isAllowedSqlViewDefinition(string $sql): bool {
        return (bool)preg_match('/^CREATE\s+OR\s+REPLACE\s+VIEW\s+/i', ltrim($sql));
    }

    private function extractViewNameFromSql(string $sql): string {
        if (preg_match('/^CREATE\s+OR\s+REPLACE\s+VIEW\s+"?([a-zA-Z0-9_]+)"?/i', ltrim($sql), $matches)) {
            return (string)$matches[1];
        }

        return 'custom_sql_view';
    }

    private function createOrReplaceViews(array $dbTables): void {
        $this->logger->log("Starting view creation...");
        $viewDefinitions = file(__DIR__ . '/db_views.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($viewDefinitions as $definition) {
            try {
                $this->pdo->beginTransaction();

                $trimmed_definition = trim($definition);
                if ($trimmed_definition === '' || strpos($trimmed_definition, '#') === 0) {
                    $this->pdo->commit();
                    continue;
                }

                if (stripos($trimmed_definition, 'SQL:') === 0) {
                    $sql = trim(substr($trimmed_definition, 4));
                    if ($sql === '') {
                        throw new \Exception('Empty SQL view definition');
                    }

                    if (!$this->isAllowedSqlViewDefinition($sql)) {
                        throw new \Exception('Unsupported SQL definition. Only CREATE OR REPLACE VIEW is allowed.');
                    }

                    $viewName = $this->extractViewNameFromSql($sql);
                    $this->db_query($sql);
                    $this->logger->log("Successfully created or replaced SQL view: \"$viewName\"");
                    $this->pdo->commit();
                    continue;
                }

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
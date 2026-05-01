<?php
namespace Portflow\Core;

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// define APP_NAME (----------- Why tf is const not working??? -----------)
define('APP_NAME', 'Portflow');
#const APP_NAME = 'Portflow';

include_once __DIR__ . '/../includes/core/system_state.php';
portflow_enforce_maintenance_mode('json', ['include_state' => true]);

# ================================================================================================= .htaccess config has to be replicated for lighttpd conf, just for testing with apache

// check if session exists
@include_once __DIR__ . '/../includes/core/session.php';

// Ensure session is initialized
if (!isset($_SESSION)) {
    $_SESSION = [];
}


// import dbAdapter
include_once __DIR__ . '/../includes/core/db_adapter.php';
include_once __DIR__ . '/../includes/core/automation_store.php';
include_once __DIR__ . '/../includes/core/snmp_scanner.php';
use Portflow\Core\DatabaseAdapter;

$api = new API();
$api->route();

class API {
    private $logger;
    private $dbAdapter;
    private $allowedContentTypes;
    private $allowedAcceptTypes;

    public function __construct() {
        $this->logger = new Logger();
        $this->dbAdapter = new DatabaseAdapter();

        $this->initializeHeaders();
        $this->defineAllowedTypes();
    }

    private function initializeHeaders() {
        header("Content-Type: application/json");
        $allowedOrigin = $this->resolveAllowedOrigin();
        if ($allowedOrigin !== null) {
            header("Access-Control-Allow-Origin: " . $allowedOrigin);
            header("Vary: Origin");
        }
        header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
    }

    private function defineAllowedTypes() {
        $this->allowedContentTypes = [
            'text/plain; charset=utf-8',
            'application/json',
            'application/vnd.github+json',
            'application/vnd.github.v3+json',
            'application/vnd.github.v3.raw+json',
            'application/vnd.github.v3.text+json',
            'application/vnd.github.v3.html+json',
            'application/vnd.github.v3.full+json',
            'application/vnd.github.v3.diff',
            'application/vnd.github.v3.patch'
        ];
        $this->allowedAcceptTypes = $this->allowedContentTypes;
    }

    private function resolveAllowedOrigin(): ?string
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            return null;
        }

        $configured = trim((string)(defined('PORTFLOW_HOSTNAME') ? PORTFLOW_HOSTNAME : ''));
        if ($configured === '') {
            return null;
        }

        $configuredParts = parse_url($configured);
        $originParts = parse_url($origin);
        if (!is_array($configuredParts) || !is_array($originParts)) {
            return null;
        }

        $configuredScheme = strtolower((string)($configuredParts['scheme'] ?? ''));
        $configuredHost = strtolower((string)($configuredParts['host'] ?? ''));
        $configuredPort = (int)($configuredParts['port'] ?? ($configuredScheme === 'https' ? 443 : 80));

        $originScheme = strtolower((string)($originParts['scheme'] ?? ''));
        $originHost = strtolower((string)($originParts['host'] ?? ''));
        $originPort = (int)($originParts['port'] ?? ($originScheme === 'https' ? 443 : 80));

        if ($configuredScheme === $originScheme && $configuredHost === $originHost && $configuredPort === $originPort) {
            return $origin;
        }

        return null;
    }

    private function respondServerError(\Throwable $exception, string $context): void
    {
        $this->logger->log($context . ': ' . $exception->getMessage(), 3);
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function getResourceColumns(string $resource): array
    {
        $rows = $this->dbAdapter->db_query(
            'SELECT column_name, data_type FROM information_schema.columns WHERE table_name = :table_name ORDER BY ordinal_position',
            ['table_name' => $resource]
        );

        return is_array($rows) ? $rows : [];
    }

    private function normalizeScalarFilterValue(mixed $value, string $dataType): mixed
    {
        if (is_array($value) || is_object($value)) {
            throw new \InvalidArgumentException('Invalid filter value type.');
        }

        if ($dataType === 'boolean') {
            $normalized = strtolower(trim((string)$value));
            if (in_array($normalized, ['1', 'true', 't', 'yes', 'y'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'f', 'no', 'n'], true)) {
                return false;
            }
            throw new \InvalidArgumentException('Invalid boolean filter value.');
        }

        if (in_array($dataType, ['smallint', 'integer', 'bigint'], true)) {
            if (!is_numeric($value) || (string)(int)$value !== (string)$value && (string)(int)$value !== trim((string)$value)) {
                throw new \InvalidArgumentException('Invalid integer filter value.');
            }
            return (int)$value;
        }

        if (in_array($dataType, ['real', 'double precision', 'numeric', 'float'], true)) {
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException('Invalid numeric filter value.');
            }
            return (string)(0 + $value);
        }

        return (string)$value;
    }

    private function filterWritablePayload(string $resource, array $data): array
    {
        $columns = $this->getResourceColumns($resource);
        $allowedKeys = array_flip(array_map(static function(array $column): string {
            return (string)($column['column_name'] ?? '');
        }, $columns));

        $filtered = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || $key === '' || !isset($allowedKeys[$key])) {
                throw new \InvalidArgumentException('Invalid column in payload: ' . (string)$key);
            }
            $filtered[$key] = $value;
        }

        if (empty($filtered)) {
            throw new \InvalidArgumentException('No valid columns provided.');
        }

        return $filtered;
    }

    private function validateBaseTableForWrite(string $resource): void
    {
        $dbTables = json_decode(file_get_contents(__DIR__ . '/../includes/core/db_tables.json'), true);
        $blacklist = ['access', 'api', 'users'];

        if (!preg_match('/^[a-z_]+$/', $resource) || !is_array($dbTables) || !isset($dbTables[$resource]) || in_array($resource, $blacklist, true)) {
            throw new \InvalidArgumentException('Invalid resource.');
        }
    }

    private function assertReferenceExists(string $resource, string $uuid): void
    {
        $query = 'SELECT uuid FROM ' . $this->quoteIdentifier($resource) . ' WHERE uuid = :uuid LIMIT 1';
        $rows = $this->dbAdapter->db_query($query, ['uuid' => $uuid]);
        if (!is_array($rows) || empty($rows[0]['uuid'])) {
            throw new \RuntimeException('Referenced object not found.');
        }
    }

    private function extractBasicAuthCredentials(): array
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? null;
        $pass = $_SERVER['PHP_AUTH_PW']   ?? null;
        if ($user === null || $pass === null) {
            $hdr = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? (function_exists('apache_request_headers')
                    ? (apache_request_headers()['Authorization'] ?? '')
                    : '');
            if (stripos($hdr, 'Basic ') === 0) {
                $decoded = base64_decode(substr($hdr, 6), true);
                if ($decoded !== false && str_contains($decoded, ':')) {
                    [$user, $pass] = explode(':', $decoded, 2);
                }
            }
        }

        return [$user, $pass];
    }

    /**
     * Resolve HTTP Basic credentials against the shared Auth core.
     * Returns true only when the session was established successfully.
     */
    private function tryBasicAuth(): bool
    {
        [$user, $pass] = $this->extractBasicAuthCredentials();
        if (!is_string($user) || !is_string($pass) || $user === '' || $pass === '') {
            return false;
        }

        try {
            include_once __DIR__ . '/../includes/core/auth.php';
            $auth = new \Portflow\Core\Auth();
            return $auth->apiSignin($user, $pass);
        } catch (\Throwable $e) {
            $this->logger->log('tryBasicAuth fallback failed: ' . $e->getMessage(), 2);
            return false;
        }
    }

    private function requireAuthenticatedSession(string $realm = 'Portflow API'): void
    {
        if (!empty($_SESSION['uuid']) && !empty($_SESSION['loggedin'])) {
            return;
        }

        if ($this->tryBasicAuth()) {
            return;
        }

        header('WWW-Authenticate: Basic realm="' . addslashes($realm) . '"');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        die;
    }

    private function getAccessRights($resource) {
        // API requests may authenticate via existing PHP session or HTTP Basic.
        if (empty($_SESSION['uuid'])) {
            $this->requireAuthenticatedSession('Portflow API');
        }

        if (empty($_SESSION['uuid'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized', 'message' => 'No authenticated user available.']);
            die;
        }

        $query = "SELECT role FROM users WHERE uuid = :uuid";
        $params = ['uuid' => $_SESSION['uuid']];
        $result = $this->dbAdapter->db_query($query, $params);
        $role = $result[0]['role'] ?? FALSE;

        if (empty($role)) {
            return 0;
        }

        $query = "SELECT resource, access_right FROM access WHERE resource iLIKE :resource AND role = :role";
        $params = ['resource' => 'api/%', 'role' => $role];
        $result = $this->dbAdapter->db_query($query, $params);

        if (!empty($result[0]) && ($result[0]['resource'] === 'api/*' || $result[0]['resource'] === 'api/' . $resource)) {
            return $result[0]['access_right'];
        }
        return 0;
    }
    private function checkAccessRights($resource) {
        $accessRight = $this->getAccessRights($resource);

        switch ($_SERVER['REQUEST_METHOD']) { // CRUD
            case 'POST': // CREATE
                return ($accessRight & 2) == 2; // Write permission
            case 'GET': // READ
                return ($accessRight & 4) == 4; // Read permission
            case 'PUT': // UPDATE
                return ($accessRight & 2) == 2; // Write permission
            case 'PATCH': // UPDATE
                return ($accessRight & 2) == 2; // Write permission
            case 'DELETE': // DELETE
                return ($accessRight & 1) == 1; // Delete permission
            default:
                return false;
        }
    }

    /**
     * @return array<int,string>
     */
    private function getApiPathSegments(): array
    {
        $paths = [];

        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        if (is_string($requestPath) && $requestPath !== '') {
            $paths[] = $requestPath;
        }

        foreach (['PATH_INFO', 'ORIG_PATH_INFO'] as $serverKey) {
            $candidate = trim((string)($_SERVER[$serverKey] ?? ''));
            if ($candidate !== '') {
                $paths[] = $candidate;
            }
        }

        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== ''));
            if (empty($segments)) {
                continue;
            }

            if (($segments[0] ?? '') === 'api') {
                array_shift($segments);
            }
            if (($segments[0] ?? '') === 'index.php') {
                array_shift($segments);
            }
            if (!empty($segments)) {
                return array_values($segments);
            }
        }

        return [];
    }

    public function route() {
        $pathSegments = $this->getApiPathSegments();
        $firstSegment = $pathSegments[0] ?? null;
        $secondSegment = $pathSegments[1] ?? null;

        // Handle file uploads separately (before media type check)
        if (isset($_FILES['file']) && $_SERVER['REQUEST_METHOD'] === 'POST' && $firstSegment === 'upload') {
            $this->uploadFile();
            return;
        }

        // CLI tool endpoint (handled in its own file with HTTP Basic Auth).
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $firstSegment === 'cli' && $secondSegment === 'record_link') {
            $this->requireAuthenticatedSession('Portflow CLI');
            require __DIR__ . '/cli_record_link.php';
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $firstSegment === 'snmp_scan') {
            $this->handleSnmpScanRequest();
            return;
        }

        // Cable trace endpoint — reusable backend module so multiple detail
        // views (switch port, connection, cable, etc.) can share one path.
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $firstSegment === 'cable_trace') {
            require __DIR__ . '/cable_trace.php';
            return;
        }

        // Persist per-user table column visibility/order.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $firstSegment === 'user_table_columns') {
            require __DIR__ . '/user_table_columns.php';
            return;
        }

        // check media types
        $this->checkMediaTypes($this->allowedContentTypes, $this->allowedAcceptTypes);

        // Parse resource and uuid.
        // Support both query style (/api/?table=metadata&uuid=...) and path style (/api/metadata/<uuid>).
        $resource = $_GET['table'] ?? NULL;
        $uuid = $_GET['uuid'] ?? NULL;

        if (!$resource) {
            $resource = $pathSegments[0] ?? NULL;
            $uuid = $pathSegments[1] ?? NULL;
        }

        $this->logger->log("Request URI: {$resource}", 0);

        // Prüfen, ob der Tabellenname vorhanden ist
        if ($resource) {
            // Regex für 'table', 'table_details' und 'table1_join_table2'
            $resourcePattern = '/^([a-z_]+?)(?:(_details)|(?:_join_([a-z_]+?)))?$/';
            if (!preg_match($resourcePattern, $resource, $matches)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid resource name format']);
                return;
            }

            // Lade die Liste der erlaubten Tabellen
            $dbTables = json_decode(file_get_contents(__DIR__ . '/../includes/core/db_tables.json'), true);

            // --- Gültigkeitsprüfung für den ERSTEN Tabellennamen ---
            $tableName1 = $matches[1];
            $allowedCustomViews = ['portview'];
            if (!isset($dbTables[$tableName1]) && !in_array($tableName1, $allowedCustomViews, true)) {
                http_response_code(404);
                echo json_encode(['error' => "Resource '$tableName1' not found"]);
                return;
            }

            // --- Gültigkeitsprüfung für den ZWEITEN Tabellennamen (nur bei Joins) ---
            if (isset($matches[3]) && $matches[3]) {
                $tableName2 = $matches[3];
                if (!isset($dbTables[$tableName2])) {
                    http_response_code(404);
                    echo json_encode(['error' => "Joined resource '$tableName2' not found"]);
                    return;
                }
            }

            // --- Blacklist-Prüfung für ALLE beteiligten Tabellen ---
            $involvedTables = [$tableName1];
            if (isset($tableName2)) {
                $involvedTables[] = $tableName2;
            }
            $blacklist = ['access', 'api', 'users'];

            foreach ($involvedTables as $table) {
                if (in_array($table, $blacklist)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden']);
                    return;
                }
            }

            // Prüfen, ob die UUID (falls vorhanden) dem korrekten Format entspricht
            $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
            if ($uuid && !preg_match($uuidPattern, $uuid)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid UUID format']);
                return;
            }

            // Rechteprüfung und Request-Handling wie gehabt...
            if ($this->checkAccessRights($resource)) {
                // Hier könntest du $involvedTables an die handle-Methode übergeben
                $this->handleTableRequest($resource, $uuid ?? NULL);
            } else {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
            }
        }
    }

    private function handleTableRequest($resource, $uuid = NULL) {
        // Bestimmen der Datenquelle basierend auf der Request-Methode
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
            case 'PUT':
            case 'PATCH':
                // Content-Type der Anfrage ermitteln
                $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
                $inputData = file_get_contents('php://input');

                // Verarbeitung basierend auf Content-Type
                if (strpos($contentType, 'json') !== false) {
                    // Behandlung von JSON Content-Types
                    $data = json_decode($inputData, true);
                    if (!is_array($data)) {
                        http_response_code(400); // Bad Request
                        echo json_encode(['error' => 'Bad Request', 'details' => 'Invalid JSON format.']);
                        return;
                    }
                } elseif ($contentType === 'text/plain; charset=utf-8') {
                    // Behandlung von text/plain Content-Type
                    $data = $_GET;
                } else {
                    // Behandlung anderer Content-Types
                    // Hier können Sie spezifische Verarbeitungslogiken für andere Content-Types implementieren
                } 
                break;
            case 'GET':
            case 'DELETE':
                // Daten aus $_GET verwenden für GET und DELETE
                $data = $_GET;
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method Not Allowed']);
                return;
        }

            // Remove routing control parameters from payload/filter data.
            // They are used to resolve resource/uuid and must not be treated as table columns.
            if (is_array($data)) {
                unset($data['table'], $data['uuid']);
            }

        // Sanitize data
        $data = array_filter(array_map(function($value) {
            if (is_string($value)) {
                $value = trim($value);
                $value = strip_tags($value);
                $value = htmlspecialchars($value);
            }
            return $value; // Für nicht-String-Werte keine Sanitization durchführen
        }, $data), function($value) {
            // Entfernen Sie nur Werte, wenn sie leere Strings sind
            return !is_string($value) || ($value !== '');
        });

        // Aufrufen der entsprechenden Methode basierend auf der Request-Methode
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST': // CREATE
                $this->post($resource, $data);
                break;
            case 'GET': // READ
                $this->get($resource, $data);
                break;
            case 'PUT': // UPDATE
                $this->put($resource, $uuid, $data);
                break;
            case 'PATCH': // UPDATE
                $this->patch($resource, $uuid, $data);
                break;
            case 'DELETE': // DELETE
                $this->delete($resource, $uuid);
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method Not Allowed']);
                break;
        }
    }

    private function handleSnmpScanRequest(): void
    {
        $this->requireAuthenticatedSession('Portflow SNMP API');

        if (!$this->checkAccessRights('snmp_scan')) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        $payload = [];
        $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
        if (str_contains($contentType, 'json')) {
            $decoded = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if ($payload === []) {
            $payload = is_array($_POST) ? $_POST : [];
        }

        $scanAll = !empty($payload['all']);
        $switchName = trim((string)($payload['switch_name'] ?? ''));

        try {
            $store = new AutomationStore();
            $scanner = new SnmpScanner($this->dbAdapter, $store, $this->logger);
            $userUuid = !empty($_SESSION['uuid']) ? (string)$_SESSION['uuid'] : null;

            if ($scanAll) {
                $settings = $store->getSettings();
                $inventory = json_decode((string)($settings['switch_inventory_json'] ?? ''), true);
                $switches = is_array($inventory['switches'] ?? null) ? $inventory['switches'] : [];
                $results = [];
                foreach ($switches as $switchEntry) {
                    $inventorySwitchName = trim((string)($switchEntry['name'] ?? ''));
                    if ($inventorySwitchName === '') {
                        continue;
                    }
                    $results[] = [
                        'switch_name' => $inventorySwitchName,
                        'result' => $scanner->scanSwitch($inventorySwitchName, 'api', $userUuid),
                    ];
                }
                http_response_code(200);
                echo json_encode(['ok' => true, 'results' => $results]);
                return;
            }

            if ($switchName === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Bad Request', 'message' => 'switch_name or all=true is required.']);
                return;
            }

            $result = $scanner->scanSwitch($switchName, 'api', $userUuid);
            http_response_code(!empty($result['ok']) ? 200 : 502);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->respondServerError($e, 'API SNMP scan failed');
        }
    }

    private function post($resource, $data) {
        // add users uuid to data if not provided
        if ($resource === 'metadata' && empty($data['users']) && !empty($_SESSION['uuid'])) {
            $data['users'] = $_SESSION['uuid'];
        }
        try {
            $this->validateBaseTableForWrite((string)$resource);
            $data = $this->filterWritablePayload((string)$resource, (array)$data);
            $quotedColumns = array_map(fn($key) => $this->quoteIdentifier((string)$key), array_keys($data));
            $query = 'INSERT INTO ' . $this->quoteIdentifier((string)$resource)
                . ' (' . implode(', ', $quotedColumns) . ') VALUES (:' . implode(', :', array_keys($data)) . ') RETURNING *';
            $results = $this->dbAdapter->db_query($query, $data);
            http_response_code(200);
            echo json_encode($results);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request', 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->respondServerError($e, 'API POST failed');
        }
    }

    private function get($resource, $data = NULL) {
        try {
            // Explicit ?limit= in the query string wins over the per-user cookie default.
            $limit = max(1, min(500, (int)($data['limit'] ?? $_COOKIE['table_limit'] ?? 100)));
            $page = max(1, (int)($data['page'] ?? 1));
            $offset = ($page - 1) * $limit;

            // Resolve available columns for the target table/view and ignore unknown filter keys.
            $columns = $this->getResourceColumns((string)$resource);
            $validColumns = array_map(static function($column) {
                return $column['column_name'];
            }, $columns);
            $validColumnSet = array_flip($validColumns);
            $columnTypeMap = [];
            foreach ($columns as $column) {
                if (isset($column['column_name'])) {
                    $columnTypeMap[$column['column_name']] = $column['data_type'] ?? 'text';
                }
            }

            // Initialisiere Bedingungsliste
            $conditions = [];
            $params = [];
            $paramIndex = 0;

            // Überprüfe auf Suchparameter
            if (isset($data['search']) && !empty($data['search'])) {
                $textColumns = array_filter($columns, function($column) {
                    return in_array($column['data_type'], ['character varying', 'text', 'inet', 'smallserial']);
                });
                // Bedingung für die Suchabfrage erstellen
                $searchConditions = [];
                foreach ($textColumns as $column) {
                    $paramKey = 'search_' . $paramIndex++;
                    $params[$paramKey] = '%' . (string)$data['search'] . '%';
                    $columnSql = $this->quoteIdentifier((string)$column['column_name']);
                    if ($column['data_type'] === 'inet') {
                        $searchConditions[] = $columnSql . '::text ILIKE :' . $paramKey;
                    } else {
                        $searchConditions[] = $columnSql . ' ILIKE :' . $paramKey;
                    }
                }
                if (!empty($searchConditions)) {
                    $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
                }
            }

            // Überprüfe auf zusätzliche WHERE-Parameter
            foreach ($data as $key => $value) {
                if (!in_array($key, ['limit', 'page', 'search', 'sort', 'dir'])) {
                    if (preg_match('/^(.*)In$/', $key, $inMatches)) {
                        $column = $inMatches[1];
                        if (!isset($validColumnSet[$column])) {
                            continue;
                        }

                        $rawValues = array_map('trim', explode(',', (string)$value));
                        $rawValues = array_values(array_filter($rawValues, static function($entry) {
                            return $entry !== '';
                        }));

                        if (empty($rawValues)) {
                            continue;
                        }

                        $dataType = $columnTypeMap[$column] ?? 'text';
                        $isNumericType = in_array($dataType, ['smallint', 'integer', 'bigint', 'real', 'double precision', 'numeric'], true);
                        $isBooleanType = $dataType === 'boolean';

                        $inPlaceholders = [];
                        foreach ($rawValues as $rawValue) {
                            if ($isNumericType && !is_numeric($rawValue)) {
                                continue;
                            }

                            if ($isBooleanType && !in_array(strtolower($rawValue), ['1', 'true', 't', 'yes', 'y', '0', 'false', 'f', 'no', 'n'], true)) {
                                continue;
                            }

                            $paramKey = 'in_' . $paramIndex++;
                            $params[$paramKey] = $this->normalizeScalarFilterValue($rawValue, $dataType);
                            $inPlaceholders[] = ':' . $paramKey;
                        }

                        if (empty($inPlaceholders)) {
                            continue;
                        }

                        $conditions[] = $this->quoteIdentifier($column) . ' IN (' . implode(', ', $inPlaceholders) . ')';
                        continue;
                    }

                    // Überprüfe auf Vergleichsparameter
                    if (preg_match('/^(.*?)(Min|Max)$/', $key, $matches)) {
                        $column = $matches[1];
                        if (!isset($validColumnSet[$column])) {
                            continue;
                        }
                        $operator = ($matches[2] === 'Min') ? '>' : '<';
                        $paramKey = 'cmp_' . $paramIndex++;
                        $params[$paramKey] = $this->normalizeScalarFilterValue($value, $columnTypeMap[$column] ?? 'text');
                        $conditions[] = $this->quoteIdentifier($column) . ' ' . $operator . ' :' . $paramKey;
                    } else {
                        if (!isset($validColumnSet[$key])) {
                            continue;
                        }
                        // Standardgleichheitsbedingung
                        $paramKey = 'eq_' . $paramIndex++;
                        $params[$paramKey] = $this->normalizeScalarFilterValue($value, $columnTypeMap[$key] ?? 'text');
                        $conditions[] = $this->quoteIdentifier($key) . ' = :' . $paramKey;
                    }
                }
            }

            // Erstelle WHERE-Klausel
            $whereClause = '';
            if (!empty($conditions)) {
                $whereClause = 'WHERE ' . implode(' AND ', $conditions);
            }

            // ORDER BY (whitelisted column + direction).
            $orderClause = '';
            if (!empty($data['sort']) && isset($validColumnSet[$data['sort']])) {
                $sortCol = $data['sort'];
                $dirRaw = strtolower((string)($data['dir'] ?? 'asc'));
                $sortDir = ($dirRaw === 'desc') ? 'DESC' : 'ASC';
                $orderClause = 'ORDER BY ' . $this->quoteIdentifier((string)$sortCol) . ' ' . $sortDir . ' NULLS LAST';
            }

            // Erstelle die Abfragen
            $resourceSql = $this->quoteIdentifier((string)$resource);
            $query = 'SELECT * FROM ' . $resourceSql . ' ' . $whereClause . ' ' . $orderClause . ' LIMIT :limit OFFSET :offset';
            $queryTotal = 'SELECT COUNT(*) AS count FROM ' . $resourceSql . ' ' . $whereClause;

            $queryParams = $params;
            $queryParams['limit'] = $limit;
            $queryParams['offset'] = $offset;

            $results = $this->dbAdapter->db_query($query, $queryParams);
            $totalResults = $this->dbAdapter->db_query($queryTotal, $params);

            $response = [
                'pageInfo' => [
                    'totalResults' => $totalResults[0]['count'] ?? 0,
                    'resultsPerPage' => $limit,
                    'currentPage' => $page
                ],
                'items' => $results
            ];

            http_response_code(200);
            echo json_encode($response);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request', 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->respondServerError($e, 'API GET failed');
        }
    }    

    private function put($resource, $uuid, $data) {
        try {
            $this->validateBaseTableForWrite((string)$resource);
            $data = $this->filterWritablePayload((string)$resource, (array)$data);
            $query = 'UPDATE ' . $this->quoteIdentifier((string)$resource) . ' SET ' . implode(', ', array_map(function($key) {
                return $this->quoteIdentifier((string)$key) . ' = :' . $key;
            }, array_keys($data))) . ' WHERE uuid = :uuid RETURNING *';
            $params = $data;
            $params['uuid'] = $uuid;
            $results = $this->dbAdapter->db_query($query, $params);
            http_response_code(200);
            echo json_encode($results);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request', 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->respondServerError($e, 'API PUT failed');
        }
    }

    private function patch($resource, $uuid, $data) {
        try {
            $this->validateBaseTableForWrite((string)$resource);
            $data = $this->filterWritablePayload((string)$resource, (array)$data);
            $query = 'UPDATE ' . $this->quoteIdentifier((string)$resource) . ' SET ' . implode(', ', array_map(function($key) {
                return $this->quoteIdentifier((string)$key) . ' = :' . $key;
            }, array_keys($data))) . ' WHERE uuid = :uuid RETURNING *';
            $params = $data;
            $params['uuid'] = $uuid;
            $results = $this->dbAdapter->db_query($query, $params);
            http_response_code(200);
            echo json_encode($results);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request', 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->respondServerError($e, 'API PATCH failed');
        }
    }

    private function collectImpactedDevicePortUuids(string $resource, string $uuid): array {
        if ($uuid === '') {
            return [];
        }

        if ($resource === 'device_port') {
            return [$uuid];
        }

        if ($resource === 'metadata') {
            $rows = $this->dbAdapter->db_query(
                'SELECT uuid FROM device_port WHERE metadata = :uuid',
                ['uuid' => $uuid]
            );
            return array_values(array_filter(array_map(static function($row) {
                return isset($row['uuid']) ? (string)$row['uuid'] : '';
            }, $rows)));
        }

        if ($resource === 'device') {
            $rows = $this->dbAdapter->db_query(
                'SELECT uuid FROM device_port WHERE device = :uuid',
                ['uuid' => $uuid]
            );
            return array_values(array_filter(array_map(static function($row) {
                return isset($row['uuid']) ? (string)$row['uuid'] : '';
            }, $rows)));
        }

        return [];
    }

    private function cleanupConnectionsForDevicePorts(array $portUuids): void {
        $portUuids = array_values(array_filter(array_unique(array_map('strval', $portUuids))));
        if (empty($portUuids)) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach ($portUuids as $index => $portUuid) {
            $paramKey = 'port_' . $index;
            $placeholders[] = ':' . $paramKey;
            $params[$paramKey] = $portUuid;
        }
        $inClause = implode(', ', $placeholders);

        $query = "DELETE FROM connection
                  WHERE device_port_source IN ($inClause)
                     OR device_port_destination IN ($inClause)
                     OR expected_device_port_source IN ($inClause)
                     OR expected_device_port_destination IN ($inClause)";

        $this->dbAdapter->db_query($query, $params);
    }

    private function delete($resource, $uuid) {
        try {
            // First fetch the record before deletion
            $this->validateBaseTableForWrite((string)$resource);
            $fetchQuery = 'SELECT * FROM ' . $this->quoteIdentifier((string)$resource) . ' WHERE uuid = :uuid';
            $fetchResults = $this->dbAdapter->db_query($fetchQuery, ['uuid' => $uuid]);
            
            $this->cleanupConnectionsForDevicePorts(
                $this->collectImpactedDevicePortUuids((string)$resource, (string)$uuid)
            );

            $query = 'DELETE FROM ' . $this->quoteIdentifier((string)$resource) . ' WHERE uuid = :uuid';
            $params['uuid'] = $uuid;
            $this->dbAdapter->db_query($query, $params);
            http_response_code(200);
            echo json_encode($fetchResults);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request', 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->respondServerError($e, 'API DELETE failed');
        }
    }

    private function uploadFile() {
        // File upload handling
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded or upload error']);
            return;
        }

        $file = $_FILES['file'];
        $reference_table = trim((string)($_POST['reference_table'] ?? ''));
        $reference_uuid = $_POST['reference_uuid'] ?? '';
        $description = $_POST['description'] ?? '';

        $this->requireAuthenticatedSession('Portflow Upload');

        // Validate inputs
        if (!$reference_table || !$reference_uuid) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing reference_table or reference_uuid']);
            return;
        }

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid UUID format']);
            return;
        }

        try {
            $this->validateBaseTableForWrite($reference_table);
            if (!$this->checkAccessRights($reference_table)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            $this->assertReferenceExists($reference_table, (string)$reference_uuid);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request', 'message' => $e->getMessage()]);
            return;
        } catch (\RuntimeException $e) {
            http_response_code(404);
            echo json_encode(['error' => 'Not Found']);
            return;
        } catch (\Throwable $e) {
            $this->respondServerError($e, 'Upload authorization failed');
            return;
        }

        // Create attachment directory
        $base_dir = __DIR__ . '/../data/attachments';
        $ref_dir = $base_dir . '/' . $reference_table . '/' . $reference_uuid;

        if (!is_dir($ref_dir)) {
            if (!mkdir($ref_dir, 0755, true)) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create attachment directory']);
                return;
            }
        }

        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $file_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($file_type, $allowed_types)) {
            http_response_code(400);
            echo json_encode(['error' => 'File type not allowed: ' . $file_type]);
            return;
        }

        // Check file size (50 MB)
        $max_size = 50 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            http_response_code(400);
            echo json_encode(['error' => 'File size exceeds 50 MB limit']);
            return;
        }

        // Generate safe filename
        $original_name = basename($file['name']);
        $mimeExtensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv' => 'csv'
        ];
        $ext = $mimeExtensions[$file_type] ?? strtolower((string)pathinfo($original_name, PATHINFO_EXTENSION));
        $safe_filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $target_path = $ref_dir . '/' . $safe_filename;

        // Move file
        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save uploaded file']);
            return;
        }

        // Generate file URL
        $file_url = '/data/attachments/' . $reference_table . '/' . $reference_uuid . '/' . $safe_filename;

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'file_url' => $file_url,
            'file_name' => $original_name,
            'description' => $description
        ]);
    }

    private function checkMediaTypes($allowedContentTypes, $allowedAcceptTypes) {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? trim($_SERVER['CONTENT_TYPE']) : '';
        $acceptType = isset($_SERVER['HTTP_ACCEPT']) ? trim($_SERVER['HTTP_ACCEPT']) : '';

        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH'])) {
            $isValidContentType = false;
            foreach ($allowedContentTypes as $type) {
                if (strpos($contentType, $type) === 0) {
                    $isValidContentType = true;
                    break;
                }
            }
            if (!$isValidContentType) {
                http_response_code(415);
                echo json_encode(['error' => 'Unsupported Media Type']);
                exit;
            }
        }

        if (!empty($acceptType) && $acceptType !== '*/*') {
            $acceptTypes = explode(',', $acceptType);
            $acceptMatch = false;
            foreach ($acceptTypes as $type) {
                $type = trim($type);
                foreach ($allowedAcceptTypes as $allowedType) {
                    if (strpos($type, $allowedType) === 0 || $type == '*/*') {
                        $acceptMatch = true;
                        break 2;
                    }
                }
            }
            if (!$acceptMatch) {
                http_response_code(406);
                echo json_encode(['error' => 'Not Acceptable']);
                exit;
            }
        }
    }
}
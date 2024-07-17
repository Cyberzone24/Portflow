<?php
namespace Portflow\Core;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// define APP_NAME (----------- Why tf is const not working??? -----------)
define('APP_NAME', 'Portflow');
#const APP_NAME = 'Portflow';

# ================================================================================================= .htaccess config has to be replicated for lighttpd conf, just for testing with apache

// check if session exists
/*
include_once __DIR__ . '/../includes/core/session.php';
if (!in_array(__DIR__ . '/../includes/core/session.php', get_included_files())) {
    die('could not verify session');
}
*/

// import dbAdapter
include_once __DIR__ . '/../includes/core/db_adapter.php';
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
        header("Access-Control-Allow-Origin: *");
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

    private function getAccessRights($resource) {
        // get the current user's role
        $_SESSION['uuid'] = '33af4acf-7e6c-484a-a49b-ccd135ef172d'; // ====================================== JUST FOR TESTING

        if (empty($_SESSION['uuid'])) {
            http_response_code(400); 
            echo json_encode(['error' => 'Bad Request', 'message' => 'No UUID provided in session.']);
            die;
        }

        $query = "SELECT role FROM users WHERE uuid = :uuid";
        $params = ['uuid' => $_SESSION['uuid']];
        $result = $this->dbAdapter->db_query($query, $params);
        $role = $result[0]['role'] ?? 'NULL';

        if (empty($role)) {
            return 0;
        }

        $query = "SELECT resource, access_right FROM access WHERE resource iLIKE :resource AND role = :role";
        $params = ['resource' => 'api/%', 'role' => $role];
        $result = $this->dbAdapter->db_query($query, $params);
        /* ============================================================================================== TABLE HAS TO LOOK LIKE THIS
            resource      |                 role                 | access_right 
            -------------------+--------------------------------------+--------------
            api/users | 27bc522e-b6cb-4fde-ba53-95501e284fac |            7
        */

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

    public function route() {
        // check media types
        $this->checkMediaTypes($this->allowedContentTypes, $this->allowedAcceptTypes);

        // Parse the request URI
        $requestUri = trim(strtok($_SERVER['REQUEST_URI'], '?'), '/');
        $requestUri = explode('/', explode('/api', $requestUri)[1] ?? $requestUri);

        $resource = $requestUri[1] ?? NULL;
        $uuid = $requestUri[2] ?? NULL;

        $this->logger->log("Request URI: {$resource}", 0);

        // Prüfen, ob der Tabellenname vorhanden ist
        if ($resource) {
            // Optional: Prüfen, ob der Tabellenname einem bestimmten Pattern entspricht
            $resourcePattern = '/^[a-z]+(_join_[a-z]+)*$/'; // Beispiel für ein einfaches Pattern
            if (!preg_match($resourcePattern, $resource)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid resource name']);
                return;
            }

            function isValidResourceName($resource) {
                $dbTables = json_decode(file_get_contents(__DIR__ . '/../includes/core/db_tables.json'), true);
                if (strpos($resource, '_join_') !== false) {
                    list($table1, $table2) = explode('_join_', $resource);
                    return isset($dbTables[$table1]) && isset($dbTables[$table2]);
                }
                return isset($dbTables[$resource]);
            }
            
            // Überprüfe, ob der Tabellenname in den Schlüsseln des dekodierten Arrays vorhanden ist und nicht 'access' oder 'api' ist
            if (in_array($resource, ['access', 'api', 'users'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            } elseif (!isValidResourceName($resource)) {
                var_dump();
                http_response_code(400);
                echo json_encode(['error' => 'Invalid resource name']);
                return;
            }

            // Prüfen, ob die UUID (falls vorhanden) dem korrekten Format entspricht
            $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
            if ($uuid && !preg_match($uuidPattern, $uuid)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid UUID format']);
                return;
            }

            // Verwenden von tableName für die Rechteprüfung
            if ($this->checkAccessRights($resource)) {
                $this->handleTableRequest($resource, $uuid ?? NULL);
            } else {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
            }
        } else {
            http_response_code(200);
            echo file_get_contents(__DIR__ . '/openapi.json');
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

    private function post($resource, $data) {
        try {
            $query = "INSERT INTO $resource (" . implode(', ', array_keys($data)) . ") VALUES (:" . implode(', :', array_keys($data)) . ") RETURNING *";
            $results = $this->dbAdapter->db_query($query, $data);
            http_response_code(200);
            echo json_encode($results);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'details' => $e->getMessage()]);
        }
    }

    private function get($resource, $data = NULL) {
        try {
            $limit = $_COOKIE['table_limit'] ?? ($data['limit'] ?? 100);
            $page = $data['page'] ?? 1;
            $offset = ($page - 1) * $limit;

            // Initialisiere Bedingungsliste
            $conditions = [];

            // Überprüfe auf Suchparameter
            if (isset($data['search']) && !empty($data['search'])) {
                // Tabellenspalten abfragen
                $columns = $this->dbAdapter->db_query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = '$resource'");
                $textColumns = array_filter($columns, function($column) {
                    return in_array($column['data_type'], ['text', 'character varying']);
                });
                // Bedingung für die Suchabfrage erstellen
                $searchConditions = [];
                foreach ($textColumns as $column) {
                    $searchConditions[] = "{$column['column_name']} iLIKE '%{$data['search']}%'";
                }
                $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
            }

            // Überprüfe auf zusätzliche WHERE-Parameter
            foreach ($data as $key => $value) {
                if (!in_array($key, ['limit', 'page', 'search'])) {
                    // Überprüfe auf Vergleichsparameter
                    if (preg_match('/^(.*?)(Min|Max)$/', $key, $matches)) {
                        $column = $matches[1];
                        $operator = ($matches[2] === 'Min') ? '>' : '<';
                        $conditions[] = "$column $operator '{$value}'";
                    } else {
                        // Standardgleichheitsbedingung
                        $conditions[] = "$key = '{$value}'";
                    }
                }
            }

            // Erstelle WHERE-Klausel
            $whereClause = '';
            if (!empty($conditions)) {
                $whereClause = 'WHERE ' . implode(' AND ', $conditions);
            }

            // Erstelle die Abfragen
            $query = "SELECT * FROM $resource $whereClause LIMIT $limit OFFSET $offset";
            $queryTotal = "SELECT COUNT(*) FROM $resource $whereClause";

            $results = $this->dbAdapter->db_query($query);
            $totalResults = $this->dbAdapter->db_query($queryTotal);

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
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'details' => $e->getMessage()]);
        }
    }    

    private function put($resource, $uuid, $data) {
        try {
            $query = "UPDATE $resource SET " . implode(', ', array_map(function($key) {
                return $key . ' = :' . $key;
            }, array_keys($data))) . " WHERE uuid = :uuid RETURNING *";
            $params = $data;
            $params['uuid'] = $uuid;
            $results = $this->dbAdapter->db_query($query, $params);
            http_response_code(200);
            echo json_encode($results);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'details' => $e->getMessage()]);
        }
    }

    private function patch($resource, $uuid, $data) {
        try {
            $query = "UPDATE $resource SET " . implode(', ', array_map(function($key) {
                return $key . ' = :' . $key;
            }, array_keys($data))) . " WHERE uuid = :uuid RETURNING " . implode(', ', array_keys($data));
            $params = $data;
            $params['uuid'] = $uuid;
            $results = $this->dbAdapter->db_query($query, $params);
            http_response_code(200);
            echo json_encode($results);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'details' => $e->getMessage()]);
        }
    }

    private function delete($resource, $uuid) {
        try {
            $query = "DELETE FROM $resource WHERE uuid = :uuid RETURNING *";
            $params['uuid'] = $uuid;
            $results = $this->dbAdapter->db_query($query, $params);
            http_response_code(200);
            echo json_encode($results);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'details' => $e->getMessage()]);
        }
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
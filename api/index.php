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

// import db_adapter
include_once __DIR__ . '/../includes/core/db_adapter.php';
use Portflow\Core\DatabaseAdapter;

$api = new API();
$api->handleRequest();

class API {
    private $logger;
    private $db_adapter;
    private $allowedContentTypes;
    private $allowedAcceptTypes;

    public function __construct() {
        $this->logger = new Logger();
        $this->db_adapter = new DatabaseAdapter();

        $this->initializeHeaders();
        $this->defineAllowedTypes();
    }

    private function initializeHeaders() {
        header("Content-Type: application/json");
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE");
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

    private function checkAccessRights($resource) {
        $userRole = $this->getUserRole(); // Implement this to get the current user's role
        $accessRight = $this->getAccessRight($resource, $userRole);

        switch ($_SERVER['REQUEST_METHOD']) { // CRUD
            case 'POST': // CREATE
                return ($accessRight & 2) == 2; // Write permission
            case 'GET': // READ
                return ($accessRight & 4) == 4; // Read permission
            case 'PATCH': // UPDATE
                return ($accessRight & 2) == 2; // Write permission
            case 'DELETE': // DELETE
                return ($accessRight & 1) == 1; // Delete permission
            default:
                return false;
        }
    }

    private function getUserRole() {
        $_SESSION['uuid'] = '05e2bbe5-d3e5-48e9-a8a8-0023ff315ab9'; // ====================================== JUST FOR TESTING
        $query = "SELECT role FROM users WHERE uuid = :uuid";
        $params = ['uuid' => $_SESSION['uuid']];
        $result = $this->db_adapter->db_query($query, $params);
        return $result[0]['role'] ?? 'NULL';
    }

    private function getAccessRight($resource, $role) {
        $query = "SELECT access_right FROM access WHERE resource = :resource AND role = :role";
        $params = ['resource' => 'api/' . $resource, 'role' => $role];
        $result = $this->db_adapter->db_query($query, $params);
        return $result[0]['access_right'] ?? 0;
        /* ============================================================================================== TABLE HAS TO LOOK LIKE THIS
            resource      |                 role                 | access_right 
            -------------------+--------------------------------------+--------------
            api/users | 27bc522e-b6cb-4fde-ba53-95501e284fac |            7
        */
    }

    public function handleRequest() {
        $this->checkMediaTypes($this->allowedContentTypes, $this->allowedAcceptTypes);
        $this->routeRequest();
    }

    private function routeRequest() {
        $requestUri = trim(strtok($_SERVER['REQUEST_URI'], '?'), '/');
        $requestUri = explode('/', explode('/api/', $requestUri)[1] ?? $requestUri);
    
        $resource = $requestUri[0] ?? NULL;
        $uuid = $requestUri[1] ?? NULL;
    
        $this->logger->log("Request URI: {$resource}", 0);
    
        // Prüfen, ob der Tabellenname vorhanden ist
        if ($resource) {
            // Optional: Prüfen, ob der Tabellenname einem bestimmten Pattern entspricht
            $resourcePattern = '/^[a-zA-Z_][a-zA-Z0-9_]*$/'; // Beispiel für ein einfaches Pattern
            if (!preg_match($resourcePattern, $resource)) {
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
            echo json_encode(file_get_contents(__DIR__ . '/openapi.json'));
        }
    }

    private function handleTableRequest($resource, $uuid = NULL) {
        if (isset($_GET)) {
            $data = $_GET;
            // sanitize data
            $data = array_map(function($value) {
                return htmlspecialchars($value);
            }, $data);
            $data = array_map(function($value) {
                return strip_tags($value);
            }, $data);
            $data = array_map(function($value) {
                return trim($value);
            }, $data);
            var_dump($data); // ===================================== JUST FOR TESTING
        }

        switch ($_SERVER['REQUEST_METHOD']) { // CRUD
            case 'POST': // CREATE
                $this->post($resource, $data);
                break;
            case 'GET': // READ
                $this->get($resource);
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
            $results = $this->db_adapter->db_query($query, $data);
            http_response_code(200);
            echo json_encode($results);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'details' => $e->getMessage()]);
        }
    }

    private function get($resource) {
        try {
            $query = "SELECT * FROM $resource";
            $results = $this->db_adapter->db_query($query);
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
            }, array_keys($data))) . " WHERE uuid = '$uuid' RETURNING " . implode(', ', array_keys($data));
            $params = $data;
            $results = $this->db_adapter->db_query($query, $params);
            http_response_code(200);
            echo json_encode($results);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'details' => $e->getMessage()]);
        }
    }

    private function delete($resource, $uuid) {
        try {
            $query = "DELETE FROM $resource WHERE uuid = '$uuid' RETURNING *";
            $results = $this->db_adapter->db_query($query);
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
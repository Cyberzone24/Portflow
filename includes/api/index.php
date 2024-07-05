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
include_once __DIR__ . '/../core/session.php';
if (!in_array(__DIR__ . '/../core/session.php', get_included_files())) {
    die('could not verify session');
}
*/

// import db_adapter
include_once __DIR__ . '/../core/db_adapter.php';
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
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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

    private function checkAccessRights($resource, $method) {
        $userRole = $this->getUserRole(); // Implement this to get the current user's role
        $accessRight = $this->getAccessRight($resource, $userRole);

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                return ($accessRight & 4) == 4; // Read permission
            case 'POST':
                return ($accessRight & 2) == 2; // Write permission
            case 'PUT':
                return false; // ====================================== Implement this
            case 'PATCH':
                return ($accessRight & 2) == 2; // Write permission
            case 'DELETE':
                return ($accessRight & 1) == 1; // Delete permission
            default:
                return false;
        }
    }

    private function getUserRole() {
        $_SESSION['uuid'] = 'b0640677-630f-4a8f-800e-75976263f220'; // ====================================== JUST FOR TESTING
        $query = "SELECT role FROM users WHERE uuid = :uuid";
        $params = ['uuid' => $_SESSION['uuid']];
        $result = $this->db_adapter->db_query($query, $params);
        return $result[0]['role'] ?? 'NULL';
    }

    private function getAccessRight($resource, $role) {
        $query = "SELECT access_right FROM access WHERE resource = :resource AND role = :role";
        $params = ['resource' => 'includes/api/' . $resource, 'role' => $role];
        $result = $this->db_adapter->db_query($query, $params);
        return $result[0]['access_right'] ?? 0;
        /* ============================================================================================== TABLE HAS TO LOOK LIKE THIS
            resource      |                 role                 | access_right 
            -------------------+--------------------------------------+--------------
            includes/core/api | 27bc522e-b6cb-4fde-ba53-95501e284fac |            7
        */
    }

    public function handleRequest() {
        $this->checkMediaTypes($this->allowedContentTypes, $this->allowedAcceptTypes);
        $this->routeRequest();
    }

    private function routeRequest() {
        $requestUri = trim(strtok($_SERVER['REQUEST_URI'], '?'), '/');
        $requestUri = explode('/includes/api/', $requestUri)[1] ?? $requestUri;

        $this->logger->log("Request URI: $requestUri", 0);

        $routes = [
            'resource/{id}' => 'handleResourceById',
            '{table}' => 'handleTableRequest'
        ];

        foreach ($routes as $route => $method) {
            $pattern = '@^' . preg_replace('/\{[^\}]+\}/', '([^/]+)', $route) . '$@';
            $this->logger->log("Checking pattern: $pattern", 0);

            if (preg_match($pattern, $requestUri, $matches)) {
                array_shift($matches);
                if ($this->checkAccessRights($requestUri, $method)) {
                    $this->$method($matches);
                } else {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden']);
                }
                return;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
    }

    private function handleTableRequest($params) {
        $table = $params[0];

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                $this->getTableData($table);
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method Not Allowed']);
                break;
        }
    }

    private function getTableData($table) {
        try {
            $query = "SELECT * FROM " . preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $results = $this->db_adapter->db_query($query);
            http_response_code(200);
            echo json_encode($results);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error', 'details' => $e->getMessage()]);
        }
    }

    private function handleResourceById($params) {
        $id = $params[0];

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
                http_response_code(201);
                echo json_encode(['message' => 'Resource created', 'id' => $id]);
                break;
            case 'GET':
                http_response_code(200);
                echo json_encode(['message' => 'Resource fetched', 'id' => $id]);
                break;
            case 'PUT':
                http_response_code(200);
                echo json_encode(['message' => 'Resource updated', 'id' => $id]);
                break;
            case 'DELETE':
                http_response_code(200);
                echo json_encode(['message' => 'Resource deleted', 'id' => $id]);
                break;
            case 'OPTIONS':
                http_response_code(200);
                echo json_encode(['message' => 'Resource options', 'id' => $id]);
                break;
            case 'PATCH':
                http_response_code(418); // I'm a teapot
                echo json_encode(['error' => "I'm a teapot"]);
                break;
            case 'HEAD':
                http_response_code(418); // I'm a teapot
                echo json_encode(['error' => "I'm a teapot"]);
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method Not Allowed']);
                break;
        }
    }

    private function checkMediaTypes($allowedContentTypes, $allowedAcceptTypes) {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? trim($_SERVER['CONTENT_TYPE']) : '';
        $acceptType = isset($_SERVER['HTTP_ACCEPT']) ? trim($_SERVER['HTTP_ACCEPT']) : '';

        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'])) {
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
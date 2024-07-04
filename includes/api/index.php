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

// use API
$api = new API();
$api->handleRequest();

// class API
class API {
    // define class variables
    private $logger;
    private $db_adapter;
    private $allowedContentTypes;
    private $allowedAcceptTypes;

    public function __construct() {
        // create logger and db_adapter
        $this->logger = new Logger();
        $this->db_adapter = new DatabaseAdapter();

        // initialize headers and define allowed types
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
        $this->allowedAcceptTypes = $this->allowedContentTypes; // Da alle Content Types auch als Accept Types gültig sind
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
            'users' => 'handleUsers',
            'other-endpoint' => 'handleOtherEndpoint'
        ];

        foreach ($routes as $route => $method) {
            $pattern = '@^' . preg_replace('/\{[^\}]+\}/', '([^/]+)', $route) . '$@';
            $this->logger->log("Checking pattern: $pattern", 0);

            if (preg_match($pattern, $requestUri, $matches)) {
                array_shift($matches);
                $this->$method($matches);
                return;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
    }

    private function handleUsers() {
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                $this->getUsers();
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method Not Allowed']);
                break;
        }
    }

    private function getUsers() {
        try {
            $users = $this->db_adapter->db_query("SELECT * FROM users");
            http_response_code(200);
            echo json_encode($users);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error']);
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
    
        // Überprüfung für Content-Type bei POST und PUT Anfragen
        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'])) {
            $isValidContentType = false;
            foreach ($allowedContentTypes as $type) {
                if (strpos($contentType, $type) === 0) { // Prüft, ob der Content-Type mit einem der erlaubten Typen beginnt
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
    
        // Überprüfung für Accept Header
        if (!empty($acceptType) && $acceptType !== '*/*') { // Ignoriert die Überprüfung, wenn '*/*' gesendet wird
            $acceptTypes = explode(',', $acceptType);
            $acceptMatch = false;
            foreach ($acceptTypes as $type) {
                $type = trim($type);
                foreach ($allowedAcceptTypes as $allowedType) {
                    if (strpos($type, $allowedType) === 0 || $type == '*/*') {
                        $acceptMatch = true;
                        break 2; // Beendet beide Schleifen
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
<?php
namespace Portflow\Core;

// check if APP_NAME is defined
if (!defined('APP_NAME')) {
    die('Access denied');
}

// Start the session if not already started
if (session_status() == PHP_SESSION_NONE) {
    // set cookie parameters
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => defined('PORTFLOW_SECURE') ? PORTFLOW_SECURE : FALSE, // Ensure the cookie is only sent over HTTPS
        'httponly' => TRUE, // Make the cookie accessible only through the HTTP protocol
        'samesite' => 'Strict' // Optional: Add the SameSite attribute for additional security
    ]);

    session_start();
}

class Logger {
    private $logFile;
    private $logLevel;

    // Definiere die Log-Levels
    private $logLevels = [
        0 => 'DEBUG',
        1 => 'INFO',
        2 => 'WARN',
        3 => 'ERROR',
        4 => 'NONE'
    ];

    public function __construct($logLevel = 1) {
        $this->logFile = '/var/log/portflow/portflow.log'; // Pfad zur Log-Datei
        // Überprüfe, ob LOG_LEVEL definiert ist; wenn nicht, verwende den Standardwert oder den übergebenen Wert
        $this->logLevel = defined('LOG_LEVEL') ? constant('LOG_LEVEL') : $logLevel;
    }

    public function log($msg, $level = 1, $echoToWeb = false) {
        if ($this->logLevel <= $level) {
            $logDate = date('Y-m-d H:i:s');

            if (!array_key_exists($level, $this->logLevels)) {
                $level = 4;
                $this->log("no valid log level specified, 'NONE' will be used.", 2);
            }

            $lvl = $this->logLevels[$level];

            // Ruft debug_backtrace() auf, um die Informationen des Aufrufers zu erhalten
            $backtrace = debug_backtrace();
            if (!empty($backtrace[1])) {
                $caller = $backtrace[1];
                $functionName = $caller['function'];
                $lineNumber = $caller['line'];
                $fileName = basename($caller['file']);

                $formattedMsg = "[$lvl] - [$logDate] - [$fileName - $functionName ($lineNumber)] | $msg";
            } else {
                $formattedMsg = "[$lvl] - [$logDate] | $msg";
            }

            // Nachricht in die Konsole schreiben
            file_put_contents('php://stderr', "[portflow] $formattedMsg\n");

            // Nachricht in die Datei schreiben
            file_put_contents($this->logFile, "$formattedMsg\n", FILE_APPEND);
            if ($echoToWeb) {
                $cookieName = 'alert_' . $level . '_' . time();
                setcookie($cookieName, $msg, time() + 60, "/");
            }
        }
    }
}

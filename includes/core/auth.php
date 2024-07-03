<?php
namespace Portflow\Core;

ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

// check if APP_NAME is defined
if (!defined('APP_NAME')) {
    die('Access denied');
}

// import db_adapter
include_once __DIR__ . '/db_adapter.php';
use Portflow\Core\DatabaseAdapter;

// import mail
include_once __DIR__ . '/mail.php';
use Portflow\Core\Mail;

class Auth {
    // define class variables
    private $logger;
    private $db_adapter;
    private $mail;
    // form data
    private $csrf;
    private $username;
    private $password;
    private $email;
    private $role;
    // local_signin / local_signup
    private $uuid;
    private $password_db;
    private $language;
    private $login_attempts;
    // ldap_signin
    private $ldap_server;
    private $ldap_port;
    private $ldap_basedn;
    private $ldap_userdn;
    private $ldap_found_user_dn;
    private $ldap_binduser_dn;

    public function __construct() {
        // define post variables
        $this->csrf = $_POST['csrf'] ?? NULL;
        $this->username = $_POST['username'] ?? NULL;
        $this->password = $_POST['password'] ?? NULL;
        $this->email = $_POST['email'] ?? NULL;
        $this->role = $_POST['role'] ?? NULL;

        // create logger and db_adapter
        $this->logger = new Logger();
        $this->db_adapter = new DatabaseAdapter();
        $this->mail = new Mail();
    }

    public function csrf($token = NULL) {    
        // Create CSRF token
        if ($token == NULL) {
            // Current timestamp
            $timestamp = time();
            // Generate random bytes
            $randomBytes = random_bytes(32);
            // Combine timestamp and random bytes
            $token = bin2hex($randomBytes) . ':' . $timestamp;
            // Store token in session
            $_SESSION['csrf'] = $token;
            return $token;
        }
    }

    public function csrf_check() {
        // Check CSRF token
        if (!isset($this->csrf) || empty($this->csrf)) {
            $this->logger->log('csrf token not present in post request', 2, echoToWeb: true);
            return false;
        }

        // Split token and timestamp from POST data
        list($tokenValue, $tokenTimestamp) = explode(':', $this->csrf);
        
        // Check if session token is set and split token and timestamp from session data
        if (!isset($_SESSION['csrf']) || !strpos($_SESSION['csrf'], ':')) {
            $this->logger->log('csrf token not set in session or invalid format', 2, echoToWeb: true);
            return false;
        }
        list($sessionTokenValue, $sessionTokenTimestamp) = explode(':', $_SESSION['csrf']);

        // Check if the token matches and is not expired
        if ($tokenValue !== $sessionTokenValue) {
            $this->logger->log("csrf token incorrect", 2, echoToWeb: true);
            return false;
        }
        if ($tokenTimestamp !== $sessionTokenTimestamp) {
            $this->logger->log("csrf token doesn't match session timestamp", 2, echoToWeb: true);
            return false;
        }

        // Check if the token is expired (5 minutes = 300 seconds)
        if (time() - $tokenTimestamp > 300) {
            $this->logger->log('csrf token expired', 2, echoToWeb: true);
            return false;
        }

        return true;
    }

    private function local_signon($usage) {
        try {
            // check post data
            if (mb_strlen($this->password) > 128 || mb_strlen($this->password) < 8) {
                $this->logger->log('password length not correct', 2, echoToWeb: true);
                throw new \Exception('password length not correct');
            }
            if (mb_strlen($this->username) > 255 || mb_strlen($this->username) < 2) {
                $this->logger->log('username length not correct', 2, echoToWeb: true);
                throw new \Exception('username length not correct');
            }

            if ($usage != 'signup') {
                if (!isset($this->username, $this->password)) {
                    $this->logger->log('username or password not set', 2, echoToWeb: true);
                    throw new \Exception('username or password not set');
                }
                if (empty($this->username) || empty($this->password)) {
                    $this->logger->log('username or password empty', 2, echoToWeb: true);
                    throw new \Exception('username or password empty');
                }
            } else {
                if (!isset($this->username, $this->password, $this->email)) {
                    $this->logger->log('username, password or email not set', 2, echoToWeb: true);
                    throw new \Exception('username, password or email not set');
                }
                if (empty($this->username) || empty($this->password) || empty($this->email)) {
                    $this->logger->log('username, password or email empty', 2, echoToWeb: true);
                    throw new \Exception('username, password or email empty');
                }
                if(!filter_var($this->email, FILTER_VALIDATE_EMAIL)){
                    $this->logger->log('email not valid', 2, echoToWeb: true);
                    throw new \Exception('email not valid');
                }
            }
            $this->logger->log('submitted post data correct', 1);
        } catch (\Exception $e) {
            // Log the exception message with ERROR level
            $this->logger->log('' . $e->getMessage(), 3);
            // Here you can handle the exception as needed, for example:
            // - Redirect the user to an error page
            // - Show a specific error message to the user
            // Make sure to not directly output the Exception message if it contains sensitive information
        }
    }

    private function single_signon() {
        try {
            // check post data
            if (mb_strlen($this->password) > 1024 || mb_strlen($this->password) < 1) {
                $this->logger->log('password length not correct', 2, echoToWeb: true);
                throw new \Exception('password length not correct');
            }
            if (mb_strlen($this->username) > 1024 || mb_strlen($this->username) < 1) {
                $this->logger->log('username length not correct', 2, echoToWeb: true);
                throw new \Exception('username length not correct');
            }
            if (!isset($this->username, $this->password)) {
                $this->logger->log('username or password not set', 2, echoToWeb: true);
                throw new \Exception('username or password not set');
            }
            if (empty($this->username) || empty($this->password)) {
                $this->logger->log('username or password empty', 2, echoToWeb: true);
                throw new \Exception('username or password empty');
            }
            $this->logger->log('submitted post data correct', 1);
        } catch (\Exception $e) {
            // Log the exception message with ERROR level
            $this->logger->log('' . $e->getMessage(), 3);
            // Here you can handle the exception as needed, for example:
            // - Redirect the user to an error page
            // - Show a specific error message to the user
            // Make sure to not directly output the Exception message if it contains sensitive information
        }
    }

    private function ip(){
        // get user ip
        $ip = (isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : isset($_SERVER['HTTP_X_FORWARDED_FOR'])) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
        return $ip;
    }

    private function random_string($length) {
        // generate random string
        return substr(str_shuffle(MD5(microtime())), 0, $length);
    }

    public function signin() {
        // check csrf token
        if ($this->csrf_check($_POST['csrf'])) {
            $this->logger->log('CSRF token correct', 1);
        } else {
            $this->logger->log('CSRF token not correct', 2, echoToWeb: true);
            throw new \Exception('CSRF token not correct');
        }

        // try local_signin
        $local_signin_result = $this->local_signin();
        if ($local_signin_result) {
            return true;
        } else {
            // Log the failure of local_signin
            $this->logger->log("local_signin of '$this->username' failed", 3);
        }

        // Try ldap_signin
        if (LDAP_ENABLED == TRUE) {
            $ldap_signin_result = $this->ldap_signin();
            if ($ldap_signin_result) {
                return true;
            } else {
                // Log the failure of ldap_signin
                $this->logger->log("ldap_signin of '$this->username' failed", 3);
            }
        }

        // If both methods fail, redirect to the URI returned by PORTFLOW_HOSTNAME
        $this->logger->log('all available signin methods failed', 0, echoToWeb: true);
        header("Location: " . PORTFLOW_HOSTNAME);
        exit();
    }
    private function local_signin() {
        try {
            if (!$this->db_adapter->checkDatabaseAndTableExistence('users')) {
                die("the database table 'users' doesn't exist. please run the init script.");
            }

            // check post data
            $this->local_signon('signin');
        
            // check if user exists
            $query = "SELECT uuid, password, email, notification_setting, language, login_attempts FROM users WHERE username = :username AND activation_code = :activation_code";
            # for testing
            # $query = "SELECT uuid, password, email, notification_setting, language, login_attempts FROM users WHERE username = :username";

            // execute query
            $result = $this->db_adapter->db_query($query, ['username' => $this->username, 'activation_code' => 'activated']);
            # for testing
            # $result = $this->db_adapter->db_query($query, ['username' => $this->username]);
            $result = !empty($result) ? $result[0] : null;
            $this->logger->log('checking if user exists and account is activated');
        
            if (!empty($result)) {
                $this->logger->log('user exists and account is activated', 1);

                $this->uuid = $result['uuid'];
                $this->password_db = $result['password'];
                $this->language = $result['language'];
                $this->login_attempts = $result['login_attempts'];

                // check if login attempts exceeded
                if ($this->login_attempts <= 3) {

                    // check if password is correct
                    if (password_verify($this->password, $this->password_db)) {

                        // create session
                        session_regenerate_id();
                        $_SESSION['loggedin'] = TRUE;
                        $_SESSION['name'] = $this->username;
                        $_SESSION['uuid'] = $this->uuid;
                        $_SESSION['language'] = $this->language;

                        // update database
                        $query = "UPDATE users SET last_login = NOW(), login_attempts = :login_attempts, ip_address = :ip_address WHERE uuid = :uuid";

                        // execute query
                        $result = $this->db_adapter->db_query($query, ['login_attempts' => NULL, 'ip_address' => $this->ip(), 'uuid' => $this->uuid]);

                        if (!empty($result)) {
                            $this->logger->log("user '$this->username' logged in. database updated", 1);
                            if (isset($_SESSION['referrer']) && strpos($_SESSION['referrer'], PORTFLOW_HOSTNAME)) {
                                header('Location: ' . $_SESSION['referrer']);
                            } else {
                                header('Location: ' . PORTFLOW_HOSTNAME . '/portview.php');
                                #header('Location: '. '/portview.php');
                            }
                        } else {
                            // database could not update
                            $this->logger->log("user logged in, but database couldn't update", 3);
                            throw new \Exception("user logged in, but database couldn't update");
                        }
                        return true;
                    } else {
                        // incorrect password -> update database
                        $query = "UPDATE users SET last_login_attempt = NOW(), login_attempts = :login_attempts, ip_address = :ip_address WHERE uuid = :uuid";

                        // execute query
                        $result = $this->db_adapter->db_query($query, ['login_attempts' => $this->login_attempts + 1, 'ip_address' => $this->ip(), 'uuid' => $this->uuid]);
                        $this->logger->log('updating database');

                        if (!empty($result)) {
                            $this->logger->log('incorrect password', 1);
                            throw new \Exception('incorrect password');
                        } else {
                            // database couldn't update
                            $this->logger->log("incorrect password is. database couldn't update", 3);
                            throw new \Exception("incorrect password is. database couldn't update");
                        }
                    }

                } else {
                    // update database
                    $query = "UPDATE users SET activation_code = :activation_code, last_login_attempt = NOW(), login_attempts = :login_attempts, ip_address = :ip_address WHERE uuid = :uuid";

                    // execute query
                    $result = $this->db_adapter->db_query($query, ['activation_code' => $this->random_string(10), 'login_attempts' => $this->login_attempts + 1, 'ip_address' => $this->ip(), 'uuid' => $this->uuid]);
                    $this->logger->log('updating database');

                    if (!empty($result)) {
                        $this->logger->log('login attempts exceeded', 2);
                        throw new \Exception('login attempts exceeded');
                    } else {
                        // database couldn't update
                        $this->logger->log("login attempts exceeded. database couldn't update");
                        throw new \Exception("login attempts exceeded. database couldn't update");
                    }
                }

            }else {
                $this->logger->log('user does not exist or account is not activated', 2, echoToWeb: true);
                throw new \Exception('user does not exist or account is not activated');
            }
        } catch (\Exception $e) {
            // Log the exception message with ERROR level
            $this->logger->log($e->getMessage(), 3);
            return false;
            // Here you can handle the exception as needed, for example:
            // - Redirect the user to an error page
            // - Show a specific error message to the user
            // Make sure to not directly output the Exception message if it contains sensitive information
        }
    }

    private function ldap_signin() {
        try {
            $this->single_signon();

            // LDAP parameters
            $this->ldap_server = LDAP_SERVER;
            $this->ldap_port = LDAP_PORT;
            $this->ldap_basedn = LDAP_BASEDN;
            $this->ldap_userdn = LDAP_USERDN;
            $ldap_configFilter = LDAP_FILTER;
            $this->ldap_binduser_dn = "uid=" . $this->username . "," . $this->ldap_userdn . "," . $this->ldap_basedn;

            // Connect to LDAP server
            $ldap_connection = @ldap_connect($this->ldap_server, $this->ldap_port);
            if (!$ldap_connection) {
                throw new \Exception("ldap_signin no connection to '$this->ldap_server'");
            }
            if (empty($this->password)) {
                throw new \Exception('Password field cannot be empty');
            }

            // Set LDAP options
            ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap_connection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ldap_connection, LDAP_OPT_NETWORK_TIMEOUT, 10);

            // Bind to LDAP server
            if (defined('LDAP_BIND') && LDAP_BIND) {
                $ldap_bind = @ldap_bind($ldap_connection, LDAP_BIND_USER, LDAP_BIND_PASSWORD);
                $this->logger->log('trying to bind with ' . LDAP_BIND_USER, 0);
            } else {
                $ldap_bind = @ldap_bind($ldap_connection, $this->ldap_binduser_dn, $this->password);
                $this->logger->log('trying to bind with ' . $this->username, 0);
            }
            if (!$ldap_bind) {
                throw new \Exception('bind failed');
            }

            // Search for user
            $filter = "(&(|(sAMAccountName=$this->username)(uid=$this->username))$ldap_configFilter)";  # filtering for ldap attributes
            $attributes = array("displayname", "mail", "samaccountname", "title", "telephoneNumber", "initials", "physicalDeliveryOfficeName", "department", "accountExpires", "lastLogonTimestamp", "memberOf"); # get these ldap attributes
            $res_id = ldap_search($ldap_connection, $this->ldap_basedn, $filter, $attributes);
            if (!$res_id) {
                throw new \Exception('no user with ldap filter: \'' . $filter . '\' found');
            }

            // Get user entries
            $user_entries = ldap_get_entries($ldap_connection, $res_id);
            
            #var_dump($user_entries);

            $this->logger->log("user entrys count: '" . $user_entries["count"] . "'", 0);

            if ($user_entries["count"] == 1) {
                $this->logger->log("user '$this->username' matched with filter: '" . $filter . "'", 0);
               
                # Get User dn  
                if (isset($user_entries[0]["dn"])) {
                    // Zugriff auf den 'dn' Wert des aktuellen Eintrags
                    $this->ldap_found_user_dn = $user_entries[0]["dn"]; // Direkter Zugriff auf den 'dn' Wert
                    
                    # Attempt to bind with user DN and provided password to verify credentials
                    $this->logger->log("trying to bind with: " . $this->ldap_found_user_dn, 0);
                    if (@ldap_bind($ldap_connection, $this->ldap_found_user_dn, $this->password)) {
                        // If the bind is successful, the user's credentials are valid
                        $this->logger->log("password verification successful", 0);

                        // create session
                        session_regenerate_id();
                        $_SESSION['loggedin'] = TRUE;
                        $_SESSION['name'] = $this->username; // Oder $user_entries[0]["displayname"][0] für den vollständigen Namen
                        $_SESSION['uuid'] = $this->uuid;
                    
                        if (isset($_SESSION['referrer']) && strpos($_SESSION['referrer'], PORTFLOW_HOSTNAME)) {
                            header('Location: ' . $_SESSION['referrer']);
                        } else {
                            header('Location: ' . PORTFLOW_HOSTNAME . '/portview.php');
                        }
                        $this->logger->log("user '$this->username' logged in", 1);
                        return true;

                    } else {
                        // If the bind fails, the user's credentials are invalid
                        throw new \Exception('password verification failed');
                    }

                } else {
                    $this->logger->log("user_dn not found", 3);
                }
                
            
            } else {
                $this->logger->log("no entrys found for user: '$this->username' with filter: " . $filter, 0);
            }
            



        } catch (\Exception $e) {
            // Log the exception message with ERROR level
            $this->logger->log($e->getMessage(), 3);
            return false;
         } finally {
            // Dieser Block wird ausgeführt, egal ob eine Ausnahme aufgetreten ist oder nicht.
            // Schließen Sie hier die LDAP-Verbindung
            if (isset($ldap_connection)) {
                @ldap_close($ldap_connection);
            }
        }
    }

    public function signup() {
        try {
            if (!$this->db_adapter->checkDatabaseAndTableExistence('users')) {
                die("the database table 'users' doesn't exist. please run the init script.");
            }
            // check post data
            $this->local_signon('signup');

            // check if first user
            $query = "SELECT uuid FROM users";
            $result = $this->db_adapter->db_query($query);
            if (empty($result)) {
                $query = "INSERT INTO role (caption, description) VALUES (:caption, :description)";
                $result = $this->db_adapter->db_query($query, ['caption' => 'admin', 'description' => 'administrator role created by portflow']);
                $this->logger->log('creating admin role', 1);

                $query = "SELECT uuid FROM role WHERE caption = :caption";
                $result = $this->db_adapter->db_query($query, ['caption' => 'admin']);
                $this->role = $result[0]['uuid'];
            }

            // check if user exists
            $query = "SELECT uuid FROM users WHERE username = :username OR email = :email";
        
            // execute query
            $result = $this->db_adapter->db_query($query, ['username' => $this->username, 'email' => $this->email]);
            $this->logger->log("checking if user '$this->username' exists");
        
            foreach ($result as $row) {
                if (!empty($row['uuid'])) {
                    $this->logger->log('user already exists', 2, echoToWeb: true);
                    throw new \Exception('user exists');
                }
            }
            $this->logger->log('user does not exist', 1);

            // create user account
            $query = "INSERT INTO users (login_provider, role, username, password, email, activation_code, language, ip_address, created, last_changed) VALUES (:login_provider, :role, :username, :password, :email, :activation_code, :language, :ip_address, :created, :last_changed)"; 

            // prepare vars for query
            $activation_code = $this->random_string(10);
            $language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0] : "en-EN";
            
            // hash password
            $this->password = password_hash($this->password, PASSWORD_DEFAULT);
            $this->logger->log('password hashed', 1);

            // execute query
            $params = [
                'login_provider' => 'local',
                'role' => $this->role,
                'username' => $this->username,
                'password' => $this->password,
                'email' => $this->email,
                'activation_code' => $activation_code,
                'language' => $language,
                'ip_address' => $this->ip(),
                'created' => 'NOW()',
                'last_changed' => 'NOW()'
            ];
            $result = $this->db_adapter->db_query($query, $params);
            $this->logger->log('creating user account');

            if ($result) {
                $this->logger->log('user account created successfully', 1);

                // send activation mail
                $activate_link = PORTFLOW_HOSTNAME . '?code=' . $activation_code . '&email=' . $this->email; 
                $subject = 'Portflow: Activate your account';
                $message = 'To activate your account, please click the following link: <a href="' . $activate_link . '">Activate</a>';
                $mail_to = ['email' => $this->email, 'username' => $this->username];
                if ($this->mail->send($mail_to, $subject, $message)) {
                    $this->logger->log('activation code sent successfully', 1);
                    header('Location: ' . PORTFLOW_HOSTNAME);
                } else {
                    $this->logger->log('could not send activation code', 3, echoToWeb: true);
                    throw new \Exception('could not send activation code');
                }
            } else {
                $this->logger->log('failed to create user account', 3, echoToWeb: true);
                throw new \Exception('failed to create user account');
            }
        } catch (\Exception $e) {
            // Log the exception message with ERROR level and optionally echo to web
            $this->logger->log($e->getMessage(), 3);
            // Additional exception handling logic here
        }
    }

    public function verify($code, $email) {
        try {
            // check if code and email are set
            if (!isset($code, $email)) {
                $this->logger->log('code or email not set', 1);
                throw new \Exception('code or email not set');
            }
            if (empty($code) || empty($email)) {
                $this->logger->log('code or email empty', 1);
                throw new \Exception('code or email empty');
            }

            // check if account exists or is already verified
            $query = "SELECT uuid, activation_code FROM users WHERE email = :email";
            $result = $this->db_adapter->db_query($query , ['email' => $email]);
            if ($result) {
                $this->logger->log('account with mail exists', 1);
                if ($result[0]['activation_code'] == $code) {
                    $this->logger->log("verified account with mail: '$email'", 1);
                    $query = "UPDATE users SET activation_code = :activation_code WHERE uuid = :uuid";
                    $result = $this->db_adapter->db_query($query, ['activation_code' => 'activated', 'uuid' => $result[0]['uuid']]);
                    $this->logger->log('updated database', 1);
                    header('Location: ' . PORTFLOW_HOSTNAME);
                } elseif ($result[0]['activation_code'] == 'activated') {
                    $this->logger->log("account with mail: '$email' already verified", 1);
                    throw new \Exception('account already verified');
                }
            }
        } catch (\Exception $e) {
            // Log the exception message with ERROR level
            $this->logger->log($e->getMessage(), 3);
            // Here you can handle the exception as needed, for example:
            // - Redirect the user to an error page
            // - Show a specific error message to the user
            // Make sure to not directly output the Exception message if it contains sensitive information
        }
    }
}
?>

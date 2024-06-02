<?php
namespace Portflow\Core;

// check if APP_NAME is defined
if (!defined('APP_NAME')) {
    die('Access denied');
}

// import PHPMailer
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';


class Mail {
    private $mail;
    private $logger;

    public function __construct() {
        // create logger
        $this->logger = new Logger();

        // create mail
        $this->mail = new PHPMailer();
    }
    
    public function send($mail_to, $subject, $message) {
        //SMTP needs accurate times, and the PHP time zone MUST be set
        //This should be done in your php.ini, but this is how to do it if you don't have access to that
        date_default_timezone_set('Etc/UTC');
        
        //Tell PHPMailer to use SMTP
        $this->mail->isSMTP();
        //Enable SMTP debugging
        //SMTP::DEBUG_OFF = off (for production use)
        //SMTP::DEBUG_CLIENT = client messages
        //SMTP::DEBUG_SERVER = client and server messages
        $this->mail->SMTPDebug = SMTP::DEBUG_OFF;
        //Set the hostname of the mail server
        $this->mail->Host = MAIL_HOST;
        //Set the SMTP port number - likely to be 25, 465 or 587
        $this->mail->Port = MAIL_PORT;
        //Whether to use SMTP authentication
        $this->mail->SMTPAuth = MAIL_SMTPAUTH;
        //Set the encryption mechanism to use - STARTTLS or SMTPS
        $this->mail->SMTPSecure = MAIL_SMTPSECURE;
        //Username to use for SMTP authentication
        $this->mail->Username = MAIL_USER;
        //Password to use for SMTP authentication
        $this->mail->Password = MAIL_PASSWORD;
        //Set who the message is to be sent from
        $this->mail->setFrom(MAIL_USER, APP_NAME);
        //Set an alternative reply-to address
        $this->mail->addReplyTo(MAIL_USER, APP_NAME);
        //Set who the message is to be sent to
        $this->mail->addAddress($mail_to['email'], $mail_to['username']);
        //Set the subject line
        $this->mail->Subject = $subject;
        //Read an HTML message body from an external file, convert referenced images to embedded,
        //convert HTML into a basic plain-text alternative body
        $this->mail->msgHTML($message);
        //Replace the plain text body with one created manually
        //$this->mail->AltBody = 'This is a plain-text message body';
        //Attach an image file
        //$this->mail->addAttachment('images/phpmailer_mini.png');
        
        //SMTP XCLIENT attributes can be passed with setSMTPXclientAttribute method
        //$mail->setSMTPXclientAttribute('LOGIN', 'yourname@example.com');
        //$mail->setSMTPXclientAttribute('ADDR', '10.10.10.10');
        //$mail->setSMTPXclientAttribute('HELO', 'test.example.com');
        
        //send the message, check for errors
        if (!$this->mail->send()) {
            // log error
            $this->logger->log('error while sending mail: ' . $this->mail->ErrorInfo);
            return false;
        } else {
            // log success
            $this->logger->log('e-mail sent');
            return true;
        }
    }

    public function check() {
        // check if mail is set
        if (!isset($this->mail)) {
            return false;
        }

        // check if mail is connected
        if ($this->mail->isConnected()) {
            return true;
        }

        return false;   
    }
}
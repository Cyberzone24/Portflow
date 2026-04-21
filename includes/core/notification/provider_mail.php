<?php
namespace Portflow\Core\Notification;

use Portflow\Core\Mail;

if (!defined('APP_NAME')) {
    die('Access denied');
}

class MailProvider implements ProviderInterface {
    private Mail $mail;

    public function __construct(Mail $mail) {
        $this->mail = $mail;
    }

    public function getChannel(): string {
        return 'mail';
    }

    public function send(array $entry): array {
        $recipient = is_array($entry['recipient'] ?? null) ? $entry['recipient'] : [];
        $email = trim((string)($recipient['email'] ?? ''));
        $username = trim((string)($recipient['username'] ?? 'User'));

        if ($email === '') {
            return ['ok' => false, 'error' => 'recipient email missing'];
        }

        $subject = '[Portflow] ' . (string)($entry['title'] ?? 'Benachrichtigung');
        $message = (string)($entry['message'] ?? '');
        $body = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        if (!empty($meta)) {
            $metaJson = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($metaJson)) {
                $metaJson = '{}';
            }
            $body .= '<br><br><pre style="font-family:monospace;white-space:pre-wrap;">'
                . htmlspecialchars($metaJson, ENT_QUOTES, 'UTF-8')
                . '</pre>';
        }

        try {
            $ok = $this->mail->send(['email' => $email, 'username' => $username], $subject, $body);
            if ($ok) {
                return ['ok' => true];
            }
            return ['ok' => false, 'error' => 'mail send failed'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

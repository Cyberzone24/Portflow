<?php
namespace Portflow\Core\Notification;

if (!defined('APP_NAME')) {
    die('Access denied');
}

class SlackStubProvider implements ProviderInterface {
    public function getChannel(): string {
        return 'slack';
    }

    private function isValidWebhookUrl(string $webhook): bool {
        $parts = parse_url(trim($webhook));
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');

        if ($scheme !== 'https') {
            return false;
        }
        if (!in_array($host, ['hooks.slack.com', 'hooks.slack-gov.com'], true)) {
            return false;
        }
        if (!preg_match('#^/services/[A-Za-z0-9/_-]+$#', $path)) {
            return false;
        }
        if (isset($parts['user'], $parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return true;
    }

    public function send(array $entry): array {
        if (!defined('NOTIFICATION_SLACK_ENABLED') || NOTIFICATION_SLACK_ENABLED !== true) {
            return ['ok' => false, 'error' => 'slack channel disabled'];
        }

        $recipient = is_array($entry['recipient'] ?? null) ? $entry['recipient'] : [];
        $recipientWebhook = trim((string)($recipient['slack_webhook_url'] ?? ''));
        $globalWebhook = defined('NOTIFICATION_SLACK_WEBHOOK_URL') ? trim((string)NOTIFICATION_SLACK_WEBHOOK_URL) : '';
        $webhook = $recipientWebhook !== '' ? $recipientWebhook : $globalWebhook;
        if ($webhook === '') {
            return ['ok' => false, 'error' => 'slack webhook missing'];
        }
        if (!$this->isValidWebhookUrl($webhook)) {
            return ['ok' => false, 'error' => 'slack webhook invalid'];
        }

        $title = trim((string)($entry['title'] ?? 'Benachrichtigung'));
        $message = trim((string)($entry['message'] ?? ''));
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];

        $text = "[Portflow] " . $title;
        if ($message !== '') {
            $text .= "\n" . $message;
        }
        if (!empty($meta)) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
            if (is_string($metaJson) && $metaJson !== '') {
                $text .= "\n\nmeta: " . $metaJson;
            }
        }

        $payload = json_encode(['text' => $text], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $payload === '') {
            return ['ok' => false, 'error' => 'slack payload encode failed'];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($webhook, false, $context);
        if ($response === false) {
            return ['ok' => false, 'error' => 'slack request failed'];
        }

        $responseBody = trim((string)$response);
        if ($responseBody !== '' && strtolower($responseBody) !== 'ok') {
            return ['ok' => false, 'error' => 'slack response: ' . substr($responseBody, 0, 200)];
        }

        return ['ok' => true];
    }
}

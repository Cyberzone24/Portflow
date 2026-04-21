<?php
namespace Portflow\Core\Notification;

if (!defined('APP_NAME')) {
    die('Access denied');
}

class TelegramProvider implements ProviderInterface {
    public function getChannel(): string {
        return 'telegram';
    }

    public function send(array $entry): array {
        if (!defined('NOTIFICATION_TELEGRAM_ENABLED') || NOTIFICATION_TELEGRAM_ENABLED !== true) {
            return ['ok' => false, 'error' => 'telegram channel disabled'];
        }

        $botToken = defined('NOTIFICATION_TELEGRAM_BOT_TOKEN') ? trim((string)NOTIFICATION_TELEGRAM_BOT_TOKEN) : '';
        $chatId = defined('NOTIFICATION_TELEGRAM_CHAT_ID') ? trim((string)NOTIFICATION_TELEGRAM_CHAT_ID) : '';

        if ($botToken === '' || $chatId === '') {
            return ['ok' => false, 'error' => 'telegram config incomplete'];
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

        $url = 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/sendMessage';
        $payload = json_encode([
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($payload) || $payload === '') {
            return ['ok' => false, 'error' => 'telegram payload encode failed'];
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

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['ok' => false, 'error' => 'telegram request failed'];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['ok']) || $decoded['ok'] !== true) {
            $description = is_array($decoded) ? (string)($decoded['description'] ?? 'unknown telegram error') : 'invalid telegram response';
            return ['ok' => false, 'error' => $description];
        }

        return ['ok' => true];
    }
}

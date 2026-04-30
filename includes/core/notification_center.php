<?php
namespace Portflow\Core;

include_once __DIR__ . '/notification/provider_interface.php';
include_once __DIR__ . '/notification/provider_mail.php';
include_once __DIR__ . '/notification/provider_slack_stub.php';
include_once __DIR__ . '/notification/provider_telegram.php';

use Portflow\Core\Notification\ProviderInterface;
use Portflow\Core\Notification\MailProvider;
use Portflow\Core\Notification\SlackStubProvider;
use Portflow\Core\Notification\TelegramProvider;

if (!defined('APP_NAME')) {
    die('Access denied');
}

class NotificationCenter {
    private DatabaseAdapter $db;
    private Logger $logger;
    private Mail $mail;
    /** @var array<string, ProviderInterface> */
    private array $providers;
    private string $queueFile;
    private string $stateFile;

    public function __construct(?DatabaseAdapter $db = null, ?Logger $logger = null, ?Mail $mail = null) {
        $this->db = $db ?? new DatabaseAdapter();
        $this->logger = $logger ?? new Logger();
        $this->mail = $mail ?? new Mail();
        $this->providers = $this->buildProviders();
        $baseDir = __DIR__ . '/../../data/notifications';
        $this->queueFile = $baseDir . '/queue.json';
        $this->stateFile = $baseDir . '/state.json';
        $this->ensureStorage($baseDir);
    }

    public function enqueueGlobal(string $eventType, string $level, string $title, string $message, array $meta = []): int {
        $queue = $this->readQueue();
        $users = $this->loadUsersForLevel($level);
        $enqueued = 0;

        foreach ($users as $user) {
            $queue[] = [
                'id' => $this->uuidV4(),
                'created_at' => gmdate('c'),
                'next_attempt_at' => gmdate('c'),
                'attempts' => 0,
                'status' => 'pending',
                'event_type' => $eventType,
                'level' => $level,
                'title' => $title,
                'message' => $message,
                'meta' => $meta,
                'recipient' => [
                    'uuid' => (string)$user['uuid'],
                    'username' => (string)$user['username'],
                    'email' => (string)$user['email'],
                    'channel' => (string)$user['channel'],
                    'slack_webhook_url' => (string)($user['slack_webhook_url'] ?? ''),
                    'telegram_chat_id' => (string)($user['telegram_chat_id'] ?? '')
                ]
            ];
            $enqueued++;
        }

        if ($enqueued > 0) {
            $this->writeQueue($queue);
        }

        $this->logger->log('notifications enqueued: event=' . $eventType . ' level=' . $level . ' recipients=' . $enqueued, 1);
        return $enqueued;
    }

    public function enqueueForUsers(array $userUuids, string $eventType, string $level, string $title, string $message, array $meta = []): int {
        $normalized = [];
        foreach ($userUuids as $uuid) {
            $candidate = trim((string)$uuid);
            if ($candidate === '') {
                continue;
            }
            $normalized[$candidate] = true;
        }

        if (empty($normalized)) {
            return 0;
        }

        $queue = $this->readQueue();
        $users = $this->loadUsersForLevel($level, array_keys($normalized));
        $enqueued = 0;

        foreach ($users as $user) {
            $queue[] = [
                'id' => $this->uuidV4(),
                'created_at' => gmdate('c'),
                'next_attempt_at' => gmdate('c'),
                'attempts' => 0,
                'status' => 'pending',
                'event_type' => $eventType,
                'level' => $level,
                'title' => $title,
                'message' => $message,
                'meta' => $meta,
                'recipient' => [
                    'uuid' => (string)$user['uuid'],
                    'username' => (string)$user['username'],
                    'email' => (string)$user['email'],
                    'channel' => (string)$user['channel'],
                    'slack_webhook_url' => (string)($user['slack_webhook_url'] ?? ''),
                    'telegram_chat_id' => (string)($user['telegram_chat_id'] ?? '')
                ]
            ];
            $enqueued++;
        }

        if ($enqueued > 0) {
            $this->writeQueue($queue);
        }

        $this->logger->log('notifications enqueued (targeted): event=' . $eventType . ' level=' . $level . ' recipients=' . $enqueued, 1);
        return $enqueued;
    }

    public function enqueueEvent(string $eventType, string $level, string $title, string $message, array $meta = []): int {
        $recipientUuids = $this->resolveRecipientUuidsForEvent($eventType, $meta);
        if (!empty($recipientUuids)) {
            return $this->enqueueForUsers($recipientUuids, $eventType, $level, $title, $message, $meta);
        }

        if ($this->shouldBroadcastEvent($eventType, $meta)) {
            return $this->enqueueGlobal($eventType, $level, $title, $message, $meta);
        }

        $this->logger->log('notification event skipped: no scoped recipients resolved for event=' . $eventType, 1);
        return 0;
    }

    public function processQueue(int $maxEntries = 100): array {
        $queue = $this->readQueue();
        if (empty($queue)) {
            return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $processed = 0;
        $sent = 0;
        $failed = 0;

        foreach ($queue as $idx => $entry) {
            if ($processed >= $maxEntries) {
                break;
            }

            if (!is_array($entry) || (string)($entry['status'] ?? '') !== 'pending') {
                continue;
            }

            $nextAttemptRaw = (string)($entry['next_attempt_at'] ?? '');
            $nextAttemptAt = $this->safeDate($nextAttemptRaw);
            if ($nextAttemptAt !== null && $nextAttemptAt > $now) {
                continue;
            }

            $processed++;
            $recipient = is_array($entry['recipient'] ?? null) ? $entry['recipient'] : [];
            $channel = strtolower(trim((string)($recipient['channel'] ?? 'mail')));

            $provider = $this->providers[$channel] ?? null;
            if (!$provider instanceof ProviderInterface) {
                $queue[$idx]['status'] = 'failed';
                $queue[$idx]['error'] = 'unsupported channel';
                $failed++;
                continue;
            }

            $result = $provider->send($entry);
            $ok = !empty($result['ok']);
            if (!$ok) {
                $queue[$idx]['error'] = (string)($result['error'] ?? 'provider send failed');
            }

            if ($ok) {
                $queue[$idx]['status'] = 'sent';
                $queue[$idx]['sent_at'] = gmdate('c');
                $sent++;
                continue;
            }

            $attempts = (int)($entry['attempts'] ?? 0) + 1;
            $queue[$idx]['attempts'] = $attempts;
            if ($attempts >= 3) {
                $queue[$idx]['status'] = 'failed';
                $failed++;
            } else {
                $queue[$idx]['next_attempt_at'] = gmdate('c', time() + ($attempts * 300));
            }
        }

        $this->writeQueue($queue);
        $remaining = 0;
        foreach ($queue as $entry) {
            if (is_array($entry) && (string)($entry['status'] ?? '') === 'pending') {
                $remaining++;
            }
        }

        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'remaining' => $remaining
        ];
    }

    /**
     * Send a direct test message through a specific channel provider.
     *
     * @return array{ok: bool, error?: string}
     */
    public function sendChannelTest(string $channel, array $recipient, string $title, string $message, array $meta = []): array {
        $normalizedChannel = strtolower(trim($channel));
        $provider = $this->providers[$normalizedChannel] ?? null;
        if (!$provider instanceof ProviderInterface) {
            return ['ok' => false, 'error' => 'unsupported channel'];
        }

        $entry = [
            'title' => $title,
            'message' => $message,
            'meta' => $meta,
            'recipient' => $recipient
        ];

        return $provider->send($entry);
    }

    /**
     * @return array<string, ProviderInterface>
     */
    private function buildProviders(): array {
        $providers = [];

        $mailProvider = new MailProvider($this->mail);
        $providers[$mailProvider->getChannel()] = $mailProvider;

        $slackProvider = new SlackStubProvider();
        $providers[$slackProvider->getChannel()] = $slackProvider;

        $telegramProvider = new TelegramProvider();
        $providers[$telegramProvider->getChannel()] = $telegramProvider;

        return $providers;
    }

    public function enqueueDailySummaryIfDue(): bool {
        $timezone = defined('NOTIFICATION_TIMEZONE') ? (string)NOTIFICATION_TIMEZONE : 'UTC';
        $dailyTime = defined('NOTIFICATION_DAILY_TIME') ? (string)NOTIFICATION_DAILY_TIME : '08:00';
        $tz = $this->safeTimezone($timezone);
        $nowLocal = new \DateTimeImmutable('now', $tz);

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $dailyTime)) {
            $dailyTime = '08:00';
        }

        [$hour, $minute] = explode(':', $dailyTime, 2);
        $targetToday = $nowLocal->setTime((int)$hour, (int)$minute, 0);

        $state = $this->readState();
        $lastDailyDate = (string)($state['last_daily_date'] ?? '');
        $today = $nowLocal->format('Y-m-d');

        if ($nowLocal < $targetToday || $lastDailyDate === $today) {
            return false;
        }

        $summary = $this->buildDailySummary();
        $this->enqueueEvent(
            'daily_summary',
            'progress',
            'Tageszusammenfassung',
            $summary['message'],
            array_merge($summary['meta'], ['recipient_role' => 'admin'])
        );

        $state['last_daily_date'] = $today;
        $state['last_daily_at'] = gmdate('c');
        $this->writeState($state);

        return true;
    }

    public function getQueueOverview(int $recentLimit = 5): array {
        $queue = $this->readQueue();
        $state = $this->readState();

        $counts = [
            'total' => 0,
            'pending' => 0,
            'sent' => 0,
            'failed' => 0
        ];

        $latestSentAt = null;
        $recentSent = [];
        $recentFailed = [];
        $byChannel = [];
        $lastSentByChannel = [];
        $errorsByChannel = [];

        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $counts['total']++;
            $status = strtolower(trim((string)($entry['status'] ?? 'pending')));
            if (!isset($counts[$status])) {
                $status = 'pending';
            }
            $counts[$status]++;

            $recipient = is_array($entry['recipient'] ?? null) ? $entry['recipient'] : [];
            $channel = strtolower(trim((string)($recipient['channel'] ?? 'mail')));
            if ($channel === '') {
                $channel = 'mail';
            }
            if (!isset($byChannel[$channel])) {
                $byChannel[$channel] = ['total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0];
            }
            $byChannel[$channel]['total']++;
            if (isset($byChannel[$channel][$status])) {
                $byChannel[$channel][$status]++;
            }

            if ($status !== 'sent') {
                if ($status === 'failed') {
                    $errorText = (string)($entry['error'] ?? 'unknown error');
                    if (!isset($errorsByChannel[$channel])) {
                        $errorsByChannel[$channel] = [];
                    }
                    if (!isset($errorsByChannel[$channel][$errorText])) {
                        $errorsByChannel[$channel][$errorText] = 0;
                    }
                    $errorsByChannel[$channel][$errorText]++;

                    $recentFailed[] = [
                        'id' => (string)($entry['id'] ?? ''),
                        'updated_at' => (string)($entry['sent_at'] ?? $entry['next_attempt_at'] ?? $entry['created_at'] ?? ''),
                        'event_type' => (string)($entry['event_type'] ?? ''),
                        'title' => (string)($entry['title'] ?? ''),
                        'recipient_username' => (string)($recipient['username'] ?? ''),
                        'recipient_email' => (string)($recipient['email'] ?? ''),
                        'channel' => $channel,
                        'error' => $errorText,
                        'attempts' => (int)($entry['attempts'] ?? 0)
                    ];
                }
                continue;
            }

            $sentAtRaw = (string)($entry['sent_at'] ?? '');
            if ($sentAtRaw === '') {
                continue;
            }

            $sentAt = $this->safeDate($sentAtRaw);
            if ($sentAt === null) {
                continue;
            }

            if ($latestSentAt === null || $sentAt > $latestSentAt) {
                $latestSentAt = $sentAt;
            }

            if (!isset($lastSentByChannel[$channel]) || strcmp((string)($lastSentByChannel[$channel]['sent_at'] ?? ''), $sentAtRaw) < 0) {
                $lastSentByChannel[$channel] = [
                    'sent_at' => $sentAtRaw,
                    'event_type' => (string)($entry['event_type'] ?? ''),
                    'title' => (string)($entry['title'] ?? ''),
                    'recipient_username' => (string)($recipient['username'] ?? ''),
                    'recipient_email' => (string)($recipient['email'] ?? '')
                ];
            }

            $recentSent[] = [
                'sent_at' => $sentAtRaw,
                'title' => (string)($entry['title'] ?? ''),
                'event_type' => (string)($entry['event_type'] ?? ''),
                'recipient_username' => (string)($recipient['username'] ?? ''),
                'recipient_email' => (string)($recipient['email'] ?? ''),
                'channel' => $channel,
                'attempts' => (int)($entry['attempts'] ?? 0)
            ];
        }

        usort($recentSent, static function (array $a, array $b): int {
            return strcmp((string)($b['sent_at'] ?? ''), (string)($a['sent_at'] ?? ''));
        });
        usort($recentFailed, static function (array $a, array $b): int {
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });

        $recentLimit = max(1, $recentLimit);
        $recentSent = array_slice($recentSent, 0, $recentLimit);
        $recentFailed = array_slice($recentFailed, 0, $recentLimit);

        return [
            'counts' => $counts,
            'by_channel' => $byChannel,
            'last_sent_by_channel' => $lastSentByChannel,
            'errors_by_channel' => $errorsByChannel,
            'recent_sent' => $recentSent,
            'recent_failed' => $recentFailed,
            'last_sent_at' => $latestSentAt?->format('c'),
            'last_daily_date' => (string)($state['last_daily_date'] ?? ''),
            'last_daily_at' => (string)($state['last_daily_at'] ?? ''),
            'configured_daily_time' => defined('NOTIFICATION_DAILY_TIME') ? (string)NOTIFICATION_DAILY_TIME : '08:00',
            'configured_timezone' => defined('NOTIFICATION_TIMEZONE') ? (string)NOTIFICATION_TIMEZONE : 'UTC'
        ];
    }

    public function retryQueueEntry(string $entryId): array {
        $targetId = trim($entryId);
        if ($targetId === '') {
            return ['ok' => false, 'message' => 'queue entry id missing'];
        }

        $queue = $this->readQueue();
        foreach ($queue as $idx => $entry) {
            if (!is_array($entry) || (string)($entry['id'] ?? '') !== $targetId) {
                continue;
            }

            if ((string)($entry['status'] ?? '') !== 'failed') {
                return ['ok' => false, 'message' => 'queue entry is not failed'];
            }

            $queue[$idx]['status'] = 'pending';
            $queue[$idx]['attempts'] = 0;
            $queue[$idx]['next_attempt_at'] = gmdate('c');
            unset($queue[$idx]['error'], $queue[$idx]['sent_at']);
            $this->writeQueue($queue);

            return ['ok' => true, 'message' => 'queue entry retried', 'id' => $targetId];
        }

        return ['ok' => false, 'message' => 'queue entry not found'];
    }

    public function cleanupQueue(int $retentionDays = 30): array {
        $retentionDays = max(0, $retentionDays);
        $queue = $this->readQueue();
        if (empty($queue)) {
            return ['removed' => 0, 'remaining' => 0, 'retention_days' => $retentionDays];
        }

        $cutoffTs = time() - ($retentionDays * 86400);
        $kept = [];
        $removed = 0;

        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = strtolower(trim((string)($entry['status'] ?? 'pending')));
            if ($status !== 'sent' && $status !== 'failed') {
                $kept[] = $entry;
                continue;
            }

            $referenceRaw = (string)($entry['sent_at'] ?? $entry['next_attempt_at'] ?? $entry['created_at'] ?? '');
            $referenceDate = $this->safeDate($referenceRaw);
            if ($referenceDate === null) {
                $kept[] = $entry;
                continue;
            }

            if ($referenceDate->getTimestamp() < $cutoffTs) {
                $removed++;
                continue;
            }

            $kept[] = $entry;
        }

        if ($removed > 0) {
            $this->writeQueue($kept);
        }

        return [
            'removed' => $removed,
            'remaining' => count($kept),
            'retention_days' => $retentionDays
        ];
    }

    private function buildDailySummary(): array {
        $failedLogins = 0;
        $pendingChanges = 0;
        $failedChanges = 0;

        try {
            $since = gmdate('Y-m-d H:i:s', time() - 86400);
            $rows = $this->db->db_query(
                "SELECT COUNT(*) AS cnt FROM changelog WHERE changed_table = 'users' AND operation = 'UPDATE' AND changed_data::text ILIKE :marker AND changed > :since",
                ['marker' => '%last_login_attempt%', 'since' => $since]
            );
            $failedLogins = (int)($rows[0]['cnt'] ?? 0);
        } catch (\Throwable $ignored) {
            $failedLogins = 0;
        }

        try {
            $rowsPending = $this->db->db_query("SELECT COUNT(*) AS cnt FROM pending_changes WHERE status = 'pending'");
            $pendingChanges = (int)($rowsPending[0]['cnt'] ?? 0);
            $rowsFailed = $this->db->db_query("SELECT COUNT(*) AS cnt FROM pending_changes WHERE status = 'failed'");
            $failedChanges = (int)($rowsFailed[0]['cnt'] ?? 0);
        } catch (\Throwable $ignored) {
            $pendingChanges = 0;
            $failedChanges = 0;
        }

        $message = "Tageszusammenfassung Portflow\n";
        $message .= "- Fehlgeschlagene Loginversuche (24h): " . $failedLogins . "\n";
        $message .= "- Pending Changes: " . $pendingChanges . "\n";
        $message .= "- Fehlgeschlagene Changes: " . $failedChanges;

        return [
            'message' => $message,
            'meta' => [
                'failed_logins_24h' => $failedLogins,
                'pending_changes' => $pendingChanges,
                'failed_changes' => $failedChanges
            ]
        ];
    }

    private function loadUsersForLevel(string $eventLevel, ?array $onlyUuids = null): array {
        $rows = $this->db->db_query(
            "SELECT uuid, username, email, settings, activation_code FROM users"
        ) ?: [];

        $filter = null;
        if (is_array($onlyUuids) && !empty($onlyUuids)) {
            $filter = [];
            foreach ($onlyUuids as $uuid) {
                $candidate = trim((string)$uuid);
                if ($candidate !== '') {
                    $filter[$candidate] = true;
                }
            }
            if (empty($filter)) {
                return [];
            }
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (is_array($filter) && !isset($filter[(string)($row['uuid'] ?? '')])) {
                continue;
            }
            if ((string)($row['activation_code'] ?? '') !== 'activated') {
                continue;
            }

            $settingsRaw = (string)($row['settings'] ?? '');
            $settings = json_decode($settingsRaw, true);
            if (!is_array($settings)) {
                $settings = [];
            }
            $notifications = is_array($settings['notifications'] ?? null) ? $settings['notifications'] : [];
            $userLevel = strtolower(trim((string)($notifications['level'] ?? 'minimal')));
            $userChannel = strtolower(trim((string)($notifications['channel'] ?? 'mail')));
            $userSlackWebhook = trim((string)($notifications['slack_webhook_url'] ?? ''));
            $userTelegramChatId = trim((string)($notifications['telegram_chat_id'] ?? ''));

            if (!$this->isLevelAllowed($userLevel, $eventLevel)) {
                continue;
            }

            $result[] = [
                'uuid' => (string)$row['uuid'],
                'username' => (string)$row['username'],
                'email' => (string)$row['email'],
                'channel' => $this->normalizeChannelForDelivery($userChannel, $userTelegramChatId, $userSlackWebhook),
                'slack_webhook_url' => $userSlackWebhook,
                'telegram_chat_id' => $userTelegramChatId
            ];
        }

        return $result;
    }

    private function resolveRecipientUuidsForEvent(string $eventType, array $meta): array {
        $resolved = $this->collectRecipientUuidsFromMeta($meta);

        switch ($eventType) {
            case 'daily_summary':
                if (empty($resolved)) {
                    $resolved = $this->resolveUserUuidsByRoleCaption((string)($meta['recipient_role'] ?? 'admin'));
                }
                break;
            case 'admin_test_event':
            case 'login_success':
            case 'login_failed':
            case 'documentation_deviation':
                // Explicit or inferred recipient scoping only.
                break;
            default:
                if (empty($resolved) && isset($meta['recipient_role'])) {
                    $resolved = $this->resolveUserUuidsByRoleCaption((string)$meta['recipient_role']);
                }
                break;
        }

        return array_values(array_keys($resolved));
    }

    private function collectRecipientUuidsFromMeta(array $meta): array {
        $resolved = [];

        $singularKeys = [
            'user_uuid',
            'recipient_user_uuid',
            'affected_user_uuid',
            'owner_user_uuid',
            'actor_user_uuid',
            'triggered_by_uuid'
        ];
        foreach ($singularKeys as $key) {
            $candidate = trim((string)($meta[$key] ?? ''));
            if ($candidate !== '') {
                $resolved[$candidate] = true;
            }
        }

        $arrayKeys = [
            'user_uuids',
            'recipient_user_uuids',
            'affected_user_uuids',
            'owner_user_uuids'
        ];
        foreach ($arrayKeys as $key) {
            $values = $meta[$key] ?? null;
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                $candidate = trim((string)$value);
                if ($candidate !== '') {
                    $resolved[$candidate] = true;
                }
            }
        }

        $usernameKeys = ['triggered_by_username', 'recipient_username'];
        foreach ($usernameKeys as $key) {
            $username = trim((string)($meta[$key] ?? ''));
            if ($username === '') {
                continue;
            }
            $uuid = $this->resolveUserUuidByUsername($username);
            if ($uuid !== null) {
                $resolved[$uuid] = true;
            }
        }

        return $resolved;
    }

    private function shouldBroadcastEvent(string $eventType, array $meta): bool {
        if (!empty($meta['force_global'])) {
            return true;
        }

        return !in_array($eventType, ['daily_summary', 'admin_test_event', 'login_success', 'login_failed', 'documentation_deviation'], true);
    }

    private function resolveUserUuidsByRoleCaption(string $roleCaption): array {
        $caption = trim($roleCaption);
        if ($caption === '') {
            return [];
        }

        try {
            $rows = $this->db->db_query(
                "SELECT users.uuid
                 FROM users
                 INNER JOIN role ON users.role = role.uuid
                 WHERE users.activation_code = 'activated' AND LOWER(role.caption) = LOWER(:caption)",
                ['caption' => $caption]
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $uuid = trim((string)($row['uuid'] ?? ''));
            if ($uuid !== '') {
                $result[$uuid] = true;
            }
        }

        return $result;
    }

    private function resolveUserUuidByUsername(string $username): ?string {
        $candidate = trim($username);
        if ($candidate === '') {
            return null;
        }

        try {
            $rows = $this->db->db_query(
                "SELECT uuid FROM users WHERE activation_code = 'activated' AND username = :username LIMIT 1",
                ['username' => $candidate]
            ) ?: [];
        } catch (\Throwable $e) {
            return null;
        }

        $uuid = trim((string)($rows[0]['uuid'] ?? ''));
        return $uuid !== '' ? $uuid : null;
    }

    private function normalizeChannelForDelivery(string $requestedChannel, string $userTelegramChatId = '', string $userSlackWebhook = ''): string {
        $channel = strtolower(trim($requestedChannel));
        if ($channel === 'slack') {
            $enabled = defined('NOTIFICATION_SLACK_ENABLED') && NOTIFICATION_SLACK_ENABLED === true;
            $webhook = defined('NOTIFICATION_SLACK_WEBHOOK_URL') ? trim((string)NOTIFICATION_SLACK_WEBHOOK_URL) : '';
            $candidateWebhook = $userSlackWebhook !== '' ? $userSlackWebhook : $webhook;
            if ($enabled && $this->isValidSlackWebhookUrl($candidateWebhook)) {
                return 'slack';
            }
            return 'mail';
        }

        if ($channel === 'telegram') {
            $enabled = defined('NOTIFICATION_TELEGRAM_ENABLED') && NOTIFICATION_TELEGRAM_ENABLED === true;
            $botToken = defined('NOTIFICATION_TELEGRAM_BOT_TOKEN') ? trim((string)NOTIFICATION_TELEGRAM_BOT_TOKEN) : '';
            $globalChatId = defined('NOTIFICATION_TELEGRAM_CHAT_ID') ? trim((string)NOTIFICATION_TELEGRAM_CHAT_ID) : '';
            if ($enabled && $botToken !== '' && ($userTelegramChatId !== '' || $globalChatId !== '')) {
                return 'telegram';
            }
            return 'mail';
        }

        return 'mail';
    }

    private function isValidSlackWebhookUrl(string $webhook): bool {
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

        return preg_match('#^/services/[A-Za-z0-9/_-]+$#', $path) === 1;
    }

    private function isLevelAllowed(string $userLevel, string $eventLevel): bool {
        $weights = [
            'off' => 0,
            'minimal' => 1,
            'progress' => 2,
            'all' => 3
        ];

        $eventWeights = [
            'minimal' => 1,
            'progress' => 2,
            'all' => 3
        ];

        $userWeight = $weights[$userLevel] ?? 1;
        $eventWeight = $eventWeights[$eventLevel] ?? 1;
        if ($userWeight === 0) {
            return false;
        }

        return $userWeight >= $eventWeight;
    }

    private function ensureStorage(string $baseDir): void {
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0750, true);
        }

        if (!file_exists($this->queueFile)) {
            @file_put_contents($this->queueFile, "[]\n", LOCK_EX);
        }

        if (!file_exists($this->stateFile)) {
            @file_put_contents($this->stateFile, "{}\n", LOCK_EX);
        }
    }

    private function readQueue(): array {
        $raw = @file_get_contents($this->queueFile);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeQueue(array $queue): void {
        $encoded = json_encode(array_values($queue), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }
        @file_put_contents($this->queueFile, $encoded . "\n", LOCK_EX);
        @chmod($this->queueFile, 0640);
    }

    private function readState(): array {
        $raw = @file_get_contents($this->stateFile);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(array $state): void {
        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }
        @file_put_contents($this->stateFile, $encoded . "\n", LOCK_EX);
        @chmod($this->stateFile, 0640);
    }

    private function safeDate(string $raw): ?\DateTimeImmutable {
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function safeTimezone(string $raw): \DateTimeZone {
        try {
            return new \DateTimeZone($raw);
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    private function uuidV4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

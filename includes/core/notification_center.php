<?php
namespace Portflow\Core;

if (!defined('APP_NAME')) {
    die('Access denied');
}

class NotificationCenter {
    private DatabaseAdapter $db;
    private Logger $logger;
    private Mail $mail;
    private string $queueFile;
    private string $stateFile;

    public function __construct(?DatabaseAdapter $db = null, ?Logger $logger = null, ?Mail $mail = null) {
        $this->db = $db ?? new DatabaseAdapter();
        $this->logger = $logger ?? new Logger();
        $this->mail = $mail ?? new Mail();
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
                    'channel' => (string)$user['channel']
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

            if ($channel !== 'mail') {
                $queue[$idx]['status'] = 'failed';
                $queue[$idx]['error'] = 'unsupported channel';
                $failed++;
                continue;
            }

            $subject = '[Portflow] ' . (string)($entry['title'] ?? 'Benachrichtigung');
            $body = nl2br(htmlspecialchars((string)($entry['message'] ?? ''), ENT_QUOTES, 'UTF-8'));
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (!empty($meta)) {
                $body .= '<br><br><pre style="font-family:monospace;white-space:pre-wrap;">' . htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</pre>';
            }

            $mailTo = [
                'email' => (string)($recipient['email'] ?? ''),
                'username' => (string)($recipient['username'] ?? 'User')
            ];

            $ok = false;
            try {
                if ($mailTo['email'] !== '') {
                    $ok = $this->mail->send($mailTo, $subject, $body);
                }
            } catch (\Throwable $e) {
                $ok = false;
                $queue[$idx]['error'] = $e->getMessage();
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
        $this->enqueueGlobal(
            'daily_summary',
            'progress',
            'Tageszusammenfassung',
            $summary['message'],
            $summary['meta']
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

            if ($status !== 'sent') {
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

            $recipient = is_array($entry['recipient'] ?? null) ? $entry['recipient'] : [];
            $recentSent[] = [
                'sent_at' => $sentAtRaw,
                'title' => (string)($entry['title'] ?? ''),
                'event_type' => (string)($entry['event_type'] ?? ''),
                'recipient_username' => (string)($recipient['username'] ?? ''),
                'recipient_email' => (string)($recipient['email'] ?? ''),
                'attempts' => (int)($entry['attempts'] ?? 0)
            ];
        }

        usort($recentSent, static function (array $a, array $b): int {
            return strcmp((string)($b['sent_at'] ?? ''), (string)($a['sent_at'] ?? ''));
        });

        $recentLimit = max(1, $recentLimit);
        $recentSent = array_slice($recentSent, 0, $recentLimit);

        return [
            'counts' => $counts,
            'recent_sent' => $recentSent,
            'last_sent_at' => $latestSentAt?->format('c'),
            'last_daily_date' => (string)($state['last_daily_date'] ?? ''),
            'last_daily_at' => (string)($state['last_daily_at'] ?? ''),
            'configured_daily_time' => defined('NOTIFICATION_DAILY_TIME') ? (string)NOTIFICATION_DAILY_TIME : '08:00',
            'configured_timezone' => defined('NOTIFICATION_TIMEZONE') ? (string)NOTIFICATION_TIMEZONE : 'UTC'
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

    private function loadUsersForLevel(string $eventLevel): array {
        $rows = $this->db->db_query(
            "SELECT uuid, username, email, settings, activation_code FROM users WHERE email IS NOT NULL AND email <> ''"
        ) ?: [];

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
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

            if (!$this->isLevelAllowed($userLevel, $eventLevel)) {
                continue;
            }

            $result[] = [
                'uuid' => (string)$row['uuid'],
                'username' => (string)$row['username'],
                'email' => (string)$row['email'],
                'channel' => $userChannel !== '' ? $userChannel : 'mail'
            ];
        }

        return $result;
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

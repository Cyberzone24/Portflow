<?php

if (!defined('APP_NAME')) {
    die('Access denied');
}

if (!function_exists('portflow_system_state_directory')) {
    function portflow_system_state_directory(): string {
        return dirname(__DIR__, 2) . '/data/system';
    }

    function portflow_ensure_system_state_directory(): bool {
        $directory = portflow_system_state_directory();
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        return @mkdir($directory, 0755, true) || is_dir($directory);
    }

    function portflow_maintenance_state_path(): string {
        return portflow_system_state_directory() . '/maintenance.json';
    }

    function portflow_updater_state_path(): string {
        return portflow_system_state_directory() . '/updater-state.json';
    }

    function portflow_read_state_file(string $path): array {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    function portflow_write_state_file(string $path, array $payload): bool {
        if (!portflow_ensure_system_state_directory()) {
            return false;
        }

        $tempPath = $path . '.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        if (@file_put_contents($tempPath, $json . PHP_EOL, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            return false;
        }

        return true;
    }

    function portflow_delete_state_file(string $path): bool {
        return !is_file($path) || @unlink($path);
    }

    function portflow_enable_maintenance_mode(array $context = []): bool {
        $payload = [
            'active' => true,
            'started_at' => date('Y-m-d H:i:s'),
            'reason' => 'system-update',
        ] + $context;

        return portflow_write_state_file(portflow_maintenance_state_path(), $payload);
    }

    function portflow_disable_maintenance_mode(): bool {
        return portflow_delete_state_file(portflow_maintenance_state_path());
    }

    function portflow_get_maintenance_state(): array {
        $state = portflow_read_state_file(portflow_maintenance_state_path());
        if (!is_array($state)) {
            return [];
        }

        return $state;
    }

    function portflow_get_updater_state(): array {
        $state = portflow_read_state_file(portflow_updater_state_path());
        return is_array($state) ? $state : [];
    }

    function portflow_render_maintenance_page(array $maintenanceState = []): void {
        http_response_code(503);
        header('Retry-After: 60');
        $maintenanceMessage = trim((string)($maintenanceState['message'] ?? 'Portflow wird gerade aktualisiert. Bitte in wenigen Augenblicken erneut versuchen.'));
        $maintenanceStartedAt = trim((string)($maintenanceState['started_at'] ?? ''));
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portflow Wartungsmodus</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #e2e8f0;
            font-family: "JetBrains Mono", monospace;
            padding: 24px;
        }
        .pf-maintenance-card {
            max-width: 640px;
            width: 100%;
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 20px;
            background: rgba(15, 23, 42, 0.88);
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.35);
            padding: 32px;
        }
        .pf-maintenance-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 6px 12px;
            background: rgba(245, 158, 11, 0.18);
            color: #fbbf24;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        h1 {
            margin: 18px 0 10px;
            font-size: 30px;
            line-height: 1.1;
        }
        p {
            margin: 0;
            color: #cbd5e1;
            line-height: 1.6;
        }
        .pf-maintenance-meta {
            margin-top: 20px;
            font-size: 14px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <main class="pf-maintenance-card">
        <div class="pf-maintenance-label">Wartungsmodus aktiv</div>
        <h1>Portflow wird aktualisiert</h1>
        <p><?php echo htmlspecialchars($maintenanceMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if ($maintenanceStartedAt !== '') { ?>
            <div class="pf-maintenance-meta">Gestartet: <?php echo htmlspecialchars($maintenanceStartedAt, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php } ?>
    </main>
</body>
</html>
        <?php
    }

    function portflow_enforce_maintenance_mode(string $responseType = 'html', array $options = []): void {
        $maintenanceState = portflow_get_maintenance_state();
        if (empty($maintenanceState['active'])) {
            return;
        }

        $responseType = strtolower(trim($responseType));
        if ($responseType === 'json') {
            http_response_code(503);
            header('Retry-After: 60');
            header('Content-Type: application/json; charset=utf-8');
            $payload = [
                'error' => 'Service Unavailable',
                'message' => (string)($maintenanceState['message'] ?? 'Portflow wird gerade aktualisiert.'),
                'maintenance' => true,
            ];
            if (!empty($options['include_state'])) {
                $payload['state'] = $maintenanceState;
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        portflow_render_maintenance_page($maintenanceState);
        exit;
    }
}
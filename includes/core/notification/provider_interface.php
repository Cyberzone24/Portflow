<?php
namespace Portflow\Core\Notification;

if (!defined('APP_NAME')) {
    die('Access denied');
}

interface ProviderInterface {
    public function getChannel(): string;

    /**
     * @return array{ok: bool, error?: string}
     */
    public function send(array $entry): array;
}

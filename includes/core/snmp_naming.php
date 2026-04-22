<?php

namespace Portflow\Core;

/**
 * Extension point: translate an SNMP `ifName` to a Portflow port caption.
 *
 * Default behaviour is identity. Profiles can later inject normalization tables
 * (e.g. Cisco short forms "Gi1/0/1" <-> long forms "GigabitEthernet1/0/1").
 *
 * The caller is expected to pass `$profile` from automation.json. The MVP only
 * inspects an optional `port_name_map` array of {pattern, replacement} entries
 * applied with preg_replace in order.
 */
class SnmpNaming
{
    public static function normalizePortName(string $ifName, array $profile = []): string
    {
        $name = trim($ifName);
        if ($name === '') {
            return '';
        }

        $map = $profile['port_name_map'] ?? null;
        if (is_array($map)) {
            foreach ($map as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $pattern = (string)($entry['pattern'] ?? '');
                $replacement = (string)($entry['replacement'] ?? '');
                if ($pattern === '') {
                    continue;
                }
                $next = @preg_replace($pattern, $replacement, $name);
                if (is_string($next)) {
                    $name = $next;
                }
            }
        }

        return $name;
    }
}

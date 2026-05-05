<?php
namespace Portflow\Core;

if (!defined('APP_NAME')) {
    die('Access denied');
}

class PortviewChains
{
    public static function fetch(DatabaseAdapter $db): array
    {
        $rows = $db->db_query('SELECT * FROM "portview" ORDER BY "connection_uuid"');
        return self::build(is_array($rows) ? $rows : []);
    }

    public static function build(array $results): array
    {
        $chains = [];
        $usedUuids = [];

        foreach ($results as $conn) {
            if (!self::isCoreChain($conn) || isset($usedUuids[(string)($conn['connection_uuid'] ?? '')])) {
                continue;
            }

            $chain = [];
            $patchpanelPortUuid = self::deviceTypeOf($conn, 'src') === 'patchpanel'
                ? self::portUuidOf($conn, 'src')
                : self::portUuidOf($conn, 'dst');
            $wallplatePortUuid = self::deviceTypeOf($conn, 'src') === 'net_outlet'
                ? self::portUuidOf($conn, 'src')
                : self::portUuidOf($conn, 'dst');

            $switchConn = null;
            $endpointConn = null;

            foreach ($results as $candidate) {
                $candidateUuid = (string)($candidate['connection_uuid'] ?? '');
                if ($candidateUuid !== '' && isset($usedUuids[$candidateUuid])) {
                    continue;
                }

                $candidateSrcType = self::deviceTypeOf($candidate, 'src');
                $candidateDstType = self::deviceTypeOf($candidate, 'dst');
                $candidateSrcPort = self::portUuidOf($candidate, 'src');
                $candidateDstPort = self::portUuidOf($candidate, 'dst');

                if (
                    $switchConn === null
                    && $patchpanelPortUuid !== null
                    && (($candidateSrcPort === $patchpanelPortUuid && $candidateDstType === 'switch')
                        || ($candidateDstPort === $patchpanelPortUuid && $candidateSrcType === 'switch'))
                ) {
                    $switchConn = $candidate;
                    continue;
                }

                if (
                    $endpointConn === null
                    && $wallplatePortUuid !== null
                    && (($candidateSrcPort === $wallplatePortUuid && !in_array($candidateDstType, ['patchpanel', 'net_outlet', 'outlet', 'coupler'], true))
                        || ($candidateDstPort === $wallplatePortUuid && !in_array($candidateSrcType, ['patchpanel', 'net_outlet', 'outlet', 'coupler'], true)))
                ) {
                    $endpointConn = $candidate;
                }
            }

            if ($switchConn !== null) {
                $chain[] = $switchConn;
                $switchUuid = (string)($switchConn['connection_uuid'] ?? '');
                if ($switchUuid !== '') {
                    $usedUuids[$switchUuid] = true;
                }
            }

            $chain[] = $conn;
            $connUuid = (string)($conn['connection_uuid'] ?? '');
            if ($connUuid !== '') {
                $usedUuids[$connUuid] = true;
            }

            if ($endpointConn !== null) {
                $chain[] = $endpointConn;
                $endpointUuid = (string)($endpointConn['connection_uuid'] ?? '');
                if ($endpointUuid !== '') {
                    $usedUuids[$endpointUuid] = true;
                }
            }

            $chains[] = $chain;
        }

        foreach ($results as $conn) {
            $connUuid = (string)($conn['connection_uuid'] ?? '');
            if ($connUuid !== '' && isset($usedUuids[$connUuid])) {
                continue;
            }

            $chains[] = [$conn];
            if ($connUuid !== '') {
                $usedUuids[$connUuid] = true;
            }
        }

        return $chains;
    }

    private static function isCoreChain(array $conn): bool
    {
        $srcType = self::deviceTypeOf($conn, 'src');
        $dstType = self::deviceTypeOf($conn, 'dst');
        return ($srcType === 'patchpanel' && $dstType === 'net_outlet') || ($srcType === 'net_outlet' && $dstType === 'patchpanel');
    }

    private static function deviceTypeOf(array $conn, string $side): string
    {
        return strtolower((string)($conn[$side . '_device_type'] ?? ''));
    }

    private static function portUuidOf(array $conn, string $side): ?string
    {
        $value = $conn[$side . '_port_uuid'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        return (string)$value;
    }
}
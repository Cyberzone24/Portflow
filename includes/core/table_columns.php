<?php
/**
 * Portflow — table column visibility helper.
 *
 * Builds the list of columns that should be offered to the user when
 * customising the visible columns of a table view (e.g. itam.php).
 *
 * Two layers of filtering are applied:
 *   1. A global pattern blocklist that hides technical / non-display
 *      columns by default (UUIDs, raw JSON geometry, FK columns ...).
 *   2. An optional per-table override in the language config:
 *        'blocked'   => [ 'col_a', 'col_b', ... ]   -> additionally hidden
 *        'unblocked' => [ 'col_c', ... ]            -> force-visible even
 *                                                      if matching a global pattern
 */

namespace Portflow\Core;

class TableColumns
{
    /**
     * Suffix patterns. A column is hidden if its key ends with any of
     * these (case-insensitive). Keys present in the per-table 'unblocked'
     * list bypass this check.
     */
    public const GLOBAL_BLOCK_SUFFIXES = [
        '_uuid',
        '_metadata',
        '_position',
        '_rotation',
        '_size',
        '_specification',
        '_users',
        '_template',
        '_anc',
    ];

    /**
     * Returns the column keys that should be offered in the picker UI.
     * Order is preserved from $columns.
     *
     * @param array $columns       columns config: ['key' => 'label', ...]
     * @param array $tableConfig   the full per-table lang config (may contain 'blocked'/'unblocked')
     * @return string[]
     */
    public static function pickerKeys(array $columns, array $tableConfig = []): array
    {
        $blocked   = isset($tableConfig['blocked'])   && is_array($tableConfig['blocked'])   ? $tableConfig['blocked']   : [];
        $unblocked = isset($tableConfig['unblocked']) && is_array($tableConfig['unblocked']) ? $tableConfig['unblocked'] : [];

        $blockedSet   = array_flip($blocked);
        $unblockedSet = array_flip($unblocked);

        $out = [];
        foreach ($columns as $key => $_label) {
            if (isset($unblockedSet[$key])) { $out[] = $key; continue; }
            if (isset($blockedSet[$key]))   { continue; }
            if (self::matchesGlobalBlock((string)$key)) { continue; }
            $out[] = $key;
        }
        return $out;
    }

    /**
     * Filter an arbitrary list of column keys, removing any that are
     * blocked. Used defensively when applying a stored user preference
     * to make sure newly-blocked columns don't sneak through.
     *
     * @param string[] $keys
     * @param array    $columns        complete columns config (used to verify keys exist)
     * @param array    $tableConfig
     * @return string[]
     */
    public static function filterStored(array $keys, array $columns, array $tableConfig = []): array
    {
        $allowedSet = array_flip(self::pickerKeys($columns, $tableConfig));
        $out = [];
        foreach ($keys as $k) {
            if (isset($allowedSet[$k])) {
                $out[] = $k;
            }
        }
        return $out;
    }

    private static function matchesGlobalBlock(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::GLOBAL_BLOCK_SUFFIXES as $suffix) {
            $len = strlen($suffix);
            if ($len > 0 && substr($lower, -$len) === $suffix) {
                return true;
            }
        }
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Saola\Admin\Resources;

/**
 * Checkbox matrix ("matrix" input template): ONE table, fixed operation columns, nested group rows and item rows.
 * The value stays a plain list of cell values, so storage and validation are those of a multiselect.
 *
 * Host spec (Resource::fieldMatrix):
 *   ['columns' => ['read' => 'View', 'create' => ['label' => 'Add']],
 *    'groups'  => [['label' => 'Content',
 *                   'rows'   => [['label' => 'Articles', 'cells' => ['read' => 'a:read', 'create' => 'a:create']]],
 *                   'groups' => [['label' => 'Taxonomy', 'rows' => [['label' => 'Tags', 'cells' => ['read' => 't:read']]]]]]]]
 * A group lists its own rows first, then its sub-groups (max depth 3). A label is a translation key or a literal string.
 * A missing cell is an empty slot. Group rows get select-all boxes per column and for the whole group.
 */
final class Matrix
{
    private const MAX_COLUMNS = 12, MAX_NODES = 300, MAX_CELLS = 1000, MAX_DEPTH = 3;

    public static function normalize(array $spec): array
    {
        $columns = [];
        foreach ($spec['columns'] ?? [] as $key => $label) $columns[(string) $key] = self::label($label);
        $nodes = []; $seen = []; $cells = 0;
        $walk = function (array $groups, int $depth) use (&$walk, &$nodes, &$seen, &$cells, $columns): void {
            if ($depth > self::MAX_DEPTH) throw new \LogicException('Matrix groups are nested too deep.');
            foreach (array_values($groups) as $group) {
                $nodes[] = ['key' => 'n'.count($nodes), 'kind' => 'group', 'depth' => $depth, ...self::label($group['label'] ?? ''), 'cells' => []];
                foreach (array_values($group['rows'] ?? []) as $row) {
                    $slots = [];
                    foreach ($columns as $key => $_) {
                        $value = $row['cells'][$key] ?? null;
                        if ($value !== null && (!is_string($value) || $value === '' || strlen($value) > 100 || isset($seen[$value]))) throw new \LogicException('Invalid or duplicate matrix cell value.');
                        if ($value !== null) { $seen[$value] = true; ++$cells; }
                        $slots[] = $value;
                    }
                    $nodes[] = ['key' => 'n'.count($nodes), 'kind' => 'item', 'depth' => $depth + 1, ...self::label($row['label'] ?? ''), 'cells' => $slots];
                }
                $walk($group['groups'] ?? [], $depth + 1);
            }
        };
        $walk($spec['groups'] ?? [], 0);
        if (!$columns || count($columns) > self::MAX_COLUMNS || count($nodes) > self::MAX_NODES || $cells > self::MAX_CELLS) throw new \LogicException('Invalid matrix size.');
        return ['columns' => array_map(fn ($key, $label) => ['key' => $key, ...$label], array_keys($columns), $columns), 'nodes' => $nodes];
    }

    /** Flat value => label list for Input::options() (validation allowlist). */
    public static function options(array $matrix): array
    {
        $options = [];
        foreach ($matrix['nodes'] as $node) foreach ($node['cells'] as $value) if ($value !== null) $options[$value] = ['label' => $value];
        return $options;
    }

    /**
     * Client structure: every cell is {values, enabled, selected} (an item cell holds one value, a group cell the values of that column
     * below it), plus select-all aggregates per row, per header column and for the whole matrix.
     */
    public static function project(array $matrix, array $selected): array
    {
        $on = array_flip(array_map('strval', $selected));
        $nodes = $matrix['nodes']; $columns = count($matrix['columns']);
        $summary = fn (array $values) => ['values' => $values, 'any' => (bool) $values, 'all' => $values && !array_diff_key(array_flip($values), $on)];
        $rows = []; $headerValues = array_fill(0, $columns, []); $allValues = [];
        foreach ($nodes as $i => $node) {
            $perColumn = array_fill(0, $columns, []);
            if ($node['kind'] === 'item') {
                foreach ($node['cells'] as $c => $value) if ($value !== null) $perColumn[$c][] = $value;
            } else {
                for ($j = $i + 1; $j < count($nodes) && $nodes[$j]['depth'] > $node['depth']; ++$j)
                    foreach ($nodes[$j]['cells'] as $c => $value) if ($value !== null) $perColumn[$c][] = $value;
            }
            if ($node['kind'] === 'item') foreach ($perColumn as $c => $values) { array_push($headerValues[$c], ...$values); array_push($allValues, ...$values); }
            $cells = array_map(fn ($values) => ['enabled' => (bool) $values] + $summary($values) + ['selected' => $values && !array_diff_key(array_flip($values), $on)], $perColumn);
            $rows[] = ['key' => $node['key'], 'kind' => $node['kind'], 'depth' => $node['depth'], 'label' => $node['label'], 'labelKey' => $node['labelKey'], 'cells' => $cells]
                + $summary(array_merge(...$perColumn));
        }
        $headers = array_map(fn ($column, $values) => $column + $summary($values), $matrix['columns'], $headerValues);
        return ['columns' => $matrix['columns'], 'headers' => $headers, 'rows' => $rows] + $summary($allValues);
    }

    private static function label(mixed $label): array
    {
        if (is_array($label)) $label = $label['label'] ?? ($label['labelKey'] ?? '');
        if (!is_string($label)) throw new \LogicException('Invalid matrix label.');
        // Translation keys are dotted identifiers; anything else is a literal label supplied by the host.
        return preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_-]+)+$/D', $label) ? ['labelKey' => $label, 'label' => ''] : ['labelKey' => '', 'label' => $label];
    }
}

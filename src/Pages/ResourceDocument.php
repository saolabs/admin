<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

use InvalidArgumentException;

/** Minimal read-only renderer contract. Mutation/validation belongs to the next Resource layer. */
final class ResourceDocument
{
    public function __construct(
        private readonly string $titleKey,
        private readonly string $descriptionKey,
        private readonly array $fields = [],
        private readonly array $columns = [],
        private readonly array $rows = [],
        private readonly ?ResourceListing $listing = null,
    ) {}

    public function toArray(): array
    {
        $fields = []; $columns = []; $seen = [];
        foreach ($this->fields as $field) {
            $this->key($field['key'] ?? null);
            if (isset($seen[$field['key']])) throw new InvalidArgumentException('Duplicate resource field.');
            $seen[$field['key']] = true;
            if (!is_string($field['labelKey'] ?? null) || !is_string($field['value'] ?? null)) throw new InvalidArgumentException('Fields require a label key and text value.');
            $fields[] = ['key' => $field['key'], 'labelKey' => $field['labelKey'], 'value' => $field['value'], 'renderer' => 'text', 'readOnly' => true];
        }
        $seen = [];
        foreach ($this->columns as $column) {
            $this->key($column['key'] ?? null);
            if (isset($seen[$column['key']]) || !is_string($column['labelKey'] ?? null)
                || !in_array($column['renderer'] ?? 'text', ['text', 'badge'], true)) throw new InvalidArgumentException('Invalid resource column.');
            $seen[$column['key']] = true;
            $columns[] = ['key' => $column['key'], 'labelKey' => $column['labelKey'], 'renderer' => $column['renderer'] ?? 'text'];
        }
        $rows = [];
        foreach ($this->rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row[$column['key']] ?? '';
                if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('Resource cells must be scalar.');
                $values[$column['key']] = (string) $value;
            }
            $rows[] = $values;
        }
        return ['schema' => 1, 'titleKey' => $this->titleKey, 'descriptionKey' => $this->descriptionKey,
            'fields' => $fields, 'columns' => $columns, 'rows' => $rows, 'listing' => $this->listing?->toArray()];
    }

    private function key(mixed $key): void
    {
        if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $key)) throw new InvalidArgumentException('Invalid resource property key.');
    }
}

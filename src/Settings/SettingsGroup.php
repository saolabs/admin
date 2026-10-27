<?php

declare(strict_types=1);

namespace Saola\Admin\Settings;

use InvalidArgumentException;

/** Stateless schema. Secrets and uploads belong to dedicated adapters. */
final readonly class SettingsGroup
{
    public function __construct(
        public string $key,
        public string $titleKey,
        public string $readAbility,
        public string $writeAbility,
        public array $fields,
        public int $version = 1,
        public string $category = 'website',
        public string $categoryTitleKey = 'admin.settings.categories.website',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $key) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $category)
            || $titleKey === '' || $categoryTitleKey === '' || $readAbility === '' || $writeAbility === '' || $version < 1 || !$fields) {
            throw new InvalidArgumentException('Invalid settings group.');
        }
        foreach ($fields as $name => $field) {
            $type = $field['type'] ?? null;
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/D', $name)
                || !in_array($type, ['text', 'textarea', 'url', 'select', 'boolean', 'integer'], true)
                || !is_string($field['labelKey'] ?? null) || $field['labelKey'] === ''
                || !is_bool($field['localized'] ?? false) || !is_bool($field['public'] ?? false)
                || !is_bool($field['hidden'] ?? false) || !is_bool($field['relative'] ?? false) || !is_bool($field['media'] ?? false)
                || ($field['media'] ?? false) && $type !== 'url'
                || !array_key_exists('default', $field)) throw new InvalidArgumentException('Invalid settings field: '.(string) $name);
            if (isset($field['maxLength']) && (!is_int($field['maxLength']) || $field['maxLength'] < 1 || $field['maxLength'] > 16384)
                || isset($field['min']) && !is_int($field['min']) || isset($field['max']) && !is_int($field['max'])
                || isset($field['min'], $field['max']) && $field['min'] > $field['max']) throw new InvalidArgumentException('Invalid settings constraints: '.$name);
            if (array_key_exists('options', $field) && !is_array($field['options'])) throw new InvalidArgumentException('Invalid select options.');
            if ($type === 'select') {
                if (empty($field['options']) || !is_array($field['options'])) throw new InvalidArgumentException('Select options are required.');
                foreach ($field['options'] as $value => $label) if (!is_string($value) || !is_string($label)) throw new InvalidArgumentException('Select options must use string values and labels.');
            }
            if (!$this->validValue($field, $field['default'])) throw new InvalidArgumentException('Default violates settings constraints: '.$name);
        }
    }

    public function hasLocalizedFields(): bool
    {
        foreach ($this->fields as $field) if ($field['localized'] ?? false) return true;
        return false;
    }

    public function validType(string $type, mixed $value): bool
    {
        return match ($type) {
            'text', 'textarea', 'url', 'select' => is_string($value), 'boolean' => is_bool($value), 'integer' => is_int($value), default => false,
        };
    }

    public function validValue(array $field, mixed $value): bool
    {
        if (!$this->validType($field['type'], $value)) return false;
        if (is_string($value) && mb_strlen($value) > ($field['maxLength'] ?? 2048)) return false;
        if (is_int($value) && (isset($field['min']) && $value < $field['min'] || isset($field['max']) && $value > $field['max'])) return false;
        if ($field['type'] === 'select' && !array_key_exists($value, $field['options'])) return false;
        if ($field['type'] === 'url' && $value !== '') {
            if (preg_match('/[\x00-\x20\\\\]/', $value)) return false;
            if (($field['relative'] ?? false) && str_starts_with($value, '/') && !str_starts_with($value, '//')) return true;
            return filter_var($value, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['https', 'http'], true);
        }
        return true;
    }
}

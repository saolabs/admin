<?php

declare(strict_types=1);

namespace Saola\Admin\Settings;

use InvalidArgumentException;

/** Stateless schema. Secret values and uploads need a dedicated adapter/action. */
final readonly class SettingsGroup
{
    public function __construct(
        public string $key,
        public string $titleKey,
        public string $readAbility,
        public string $writeAbility,
        public array $fields,
        public int $version = 1,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $key) || $titleKey === '' || $readAbility === '' || $writeAbility === '' || $version < 1 || !$fields) {
            throw new InvalidArgumentException('Invalid settings group.');
        }
        foreach ($fields as $name => $field) {
            $type = $field['type'] ?? null;
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/D', $name)
                || !in_array($type, ['text', 'boolean', 'integer'], true)
                || !is_string($field['labelKey'] ?? null) || $field['labelKey'] === ''
                || !is_bool($field['localized'] ?? false) || !is_bool($field['public'] ?? false)
                || !array_key_exists('default', $field) || !$this->validType($type, $field['default'])) {
                throw new InvalidArgumentException('Invalid settings field: '.(string) $name);
            }
            if (isset($field['maxLength']) && (!is_int($field['maxLength']) || $field['maxLength'] < 1 || $field['maxLength'] > 16384)
                || isset($field['min']) && !is_int($field['min']) || isset($field['max']) && !is_int($field['max'])
                || isset($field['min'], $field['max']) && $field['min'] > $field['max']) {
                throw new InvalidArgumentException('Invalid settings constraints: '.$name);
            }
            if ($type === 'text' && mb_strlen($field['default']) > ($field['maxLength'] ?? 2048)
                || $type === 'integer' && (isset($field['min']) && $field['default'] < $field['min'] || isset($field['max']) && $field['default'] > $field['max'])) {
                throw new InvalidArgumentException('Default violates settings constraints: '.$name);
            }
        }
    }

    public function validType(string $type, mixed $value): bool
    {
        return match ($type) { 'text' => is_string($value), 'boolean' => is_bool($value), 'integer' => is_int($value), default => false };
    }
}

<?php

declare(strict_types=1);

namespace Saola\Admin\Resources;

use Illuminate\Validation\Rule;

/** Semantic input presets. Themes implement their markup; value rules live here. */
final class Input
{
    public const TEMPLATES = [
        'text' => 'text', 'email' => 'email', 'url' => 'url', 'tel' => 'tel', 'password' => 'password',
        'textarea' => 'textarea', 'markdown' => 'markdown', 'richtext' => 'richtext', 'json' => 'json',
        'integer' => 'integer', 'number' => 'number', 'range' => 'number',
        'date' => 'date', 'datetime' => 'datetime', 'time' => 'time', 'color' => 'color',
        'boolean' => 'boolean', 'switch' => 'boolean', 'select' => 'select', 'radio' => 'select', 'relation' => 'select',
        'multiselect' => 'multiselect', 'checkboxes' => 'multiselect', 'matrix' => 'multiselect', 'tags' => 'tags', 'image' => 'image', 'media' => 'media',
    ];

    public static function make(string $template, string $labelKey, array $attributes = []): array
    {
        return self::normalize(array_replace($attributes, ['type' => self::TEMPLATES[$template] ?? $attributes['type'] ?? $template, 'template' => $template, 'labelKey' => $labelKey]));
    }

    public static function normalize(array $field): array
    {
        $template = $field['template'] ?? $field['type'] ?? 'text';
        // A template the package does not know is the project's own view (admin.inputs.{template}); its declared type keeps validation.
        $custom = !isset(self::TEMPLATES[$template]);
        $type = $custom ? (in_array($field['type'] ?? null, self::TEMPLATES, true) && preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $template) ? $field['type'] : null) : self::TEMPLATES[$template];
        if (!$type || !$custom && !in_array($field['type'] ?? $type, [$type, $template], true)) throw new \LogicException('Invalid Resource input template/type.');
        if ($custom) $field['view'] = \Saola\Admin\Themes\HostViews::name('inputs', $template);
        $field = array_replace(['type' => $type, 'template' => $template, 'maxLength' => in_array($type, ['textarea', 'markdown', 'richtext', 'json'], true) ? 20000 : 2048,
            'rows' => 6, 'maxItems' => 50, 'itemMaxLength' => 100, 'section' => 'main', 'span' => in_array($type, ['textarea', 'markdown', 'richtext', 'json', 'image', 'media'], true) ? 'full' : 'half',
            'placeholderKey' => '', 'helpKey' => '', 'options' => []], $field, ['type' => $type, 'template' => $template]);
        if (!is_int($field['maxLength']) || $field['maxLength'] < 1 || $field['maxLength'] > 1000000
            || !is_int($field['rows']) || $field['rows'] < 2 || $field['rows'] > 40
            || !is_int($field['maxItems']) || $field['maxItems'] < 1 || $field['maxItems'] > 200
            || !is_int($field['itemMaxLength']) || $field['itemMaxLength'] < 1 || $field['itemMaxLength'] > 1000
            || !in_array($field['span'], ['half', 'full'], true) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $field['section'])
            || !is_string($field['placeholderKey']) || !is_string($field['helpKey']) || !is_array($field['options'])) throw new \LogicException('Invalid Resource input attributes.');
        foreach (['min', 'max', 'step'] as $key) if (isset($field[$key]) && (!is_scalar($field[$key]) || is_bool($field[$key]))) throw new \LogicException('Invalid input constraint.');
        if (in_array($type, ['number', 'integer'], true)) foreach (['min', 'max'] as $bound) if (isset($field[$bound]) && (!is_numeric($field[$bound]) || !is_finite((float) $field[$bound]))) throw new \LogicException('Numeric inputs require finite numeric bounds.');
        if (isset($field['min'], $field['max']) && $field['min'] > $field['max']) throw new \LogicException('Invalid input bounds.');
        if (isset($field['step']) && (!is_numeric($field['step']) || (float) $field['step'] <= 0)) throw new \LogicException('Invalid input step.');
        return $field;
    }

    public static function multiple(string $type): bool { return in_array($type, ['multiselect', 'tags'], true); }

    /** Options may use translated label keys or literal labels returned by a scoped host query. */
    public static function options(array $options): array
    {
        if (count($options) > 1000) throw new \LogicException('Use a dedicated picker for more than 1000 options.');
        $result = [];
        foreach ($options as $value => $label) {
            if (!is_string($label) && !(is_array($label) && is_string($label['label'] ?? null))) throw new \LogicException('Invalid Resource option.');
            $result[] = ['value' => (string) $value, 'labelKey' => is_string($label) ? $label : '', 'label' => is_array($label) ? $label['label'] : ''];
        }
        return $result;
    }

    public static function rules(array $field, array $options): array
    {
        $rules = match ($field['type']) {
            'boolean' => ['boolean'], 'integer' => ['integer'], 'number' => ['numeric'],
            'email' => ['string', 'email'], 'url', 'image', 'media' => ['string', new SafeUrlRule()],
            'select' => ['string', Rule::in(array_column($options, 'value'))],
            'multiselect', 'tags' => ['array', 'list', 'max:'.$field['maxItems']],
            'json' => ['string', 'json', function ($attribute, $value, $fail) {
                if (!is_string($value)) return;
                try { json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { $fail('The :attribute must contain valid JSON with at most 32 levels.'); }
            }],
            'date' => ['date_format:Y-m-d'], 'datetime' => ['date_format:Y-m-d\TH:i'], 'time' => ['date_format:H:i'],
            'color' => ['regex:/^#[a-fA-F0-9]{6}$/D'],
            default => ['string'],
        };
        if (!in_array($field['type'], ['boolean', 'integer', 'number', 'select', 'multiselect', 'tags'], true)) $rules[] = 'max:'.$field['maxLength'];
        if (in_array($field['type'], ['integer', 'number'], true)) foreach (['min', 'max'] as $bound) if (isset($field[$bound])) $rules[] = $bound.':'.$field[$bound];
        if (in_array($field['type'], ['integer', 'number'], true) && isset($field['step'])) $rules[] = 'multiple_of:'.$field['step'];
        return $rules;
    }
}

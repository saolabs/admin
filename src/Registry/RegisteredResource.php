<?php

declare(strict_types=1);

namespace Saola\Admin\Registry;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Http\Request;
use Saola\Admin\Resources\{Input, Resource};
use Saola\Core\Services\ModuleService;

/**
 * Internal: turns a list + form registered with Admin::list()/Admin::form() into the definition the package's CRUD
 * engine and theme run on. Reads use the module's repository model (narrowed by the list's 'query' callable),
 * writes go through the module service (core CRUD, so its events fire) or the form's 'save' callable.
 */
final class RegisteredResource extends Resource
{
    public function __construct(private string $key, private array $list, private ?array $form, private ?array $menu) {}

    public function key(): string { return $this->key; }
    public function titleKey(): string { return $this->list['title'] ?? $this->menu['title'] ?? $this->key; }
    public function descriptionKey(): string { return $this->list['description'] ?? ''; }
    public function ability(): string { return $this->list['ability'] ?? $this->menu['ability'] ?? ''; }
    /** Operation abilities "{prefix}.view|create|update|delete|restore" derive from a "….view" ability. */
    public function abilityPrefix(): string { return preg_replace('/\.view$/', '', $this->ability()) === $this->ability() ? '' : preg_replace('/\.view$/', '', $this->ability()); }

    private function service(?array $from = null): ModuleService { return app(($from ?? $this->list)['service']); }

    public function model(): string { return get_class($this->service()->getRepository()->model()); }

    public function query(Request $request): Builder
    {
        if (isset($this->list['query'])) {
            [$class, $method] = $this->list['query'];
            return app($class)->{$method}($request);
        }
        return $this->service()->getRepository()->model()->newQuery();
    }

    public function path(): string { return route('admin.'.$this->key.'.index', [], false); }
    public function endpoint(): string { return route('admin.'.$this->key.'.api', [], false); }

    public function searchable(): array { return $this->list['search'] ?? []; }
    public function sortable(): array { return $this->list['sort'] ?? []; }
    public function columns(): array
    {
        $columns = [];
        foreach ($this->list['columns'] as $key => $spec) {
            $columns[] = ['key' => $key, 'labelKey' => $spec['label']] + (isset($spec['type']) ? ['renderer' => $spec['type']] : []) + (isset($spec['decimals']) ? ['decimals' => $spec['decimals']] : []);
        }
        return $columns;
    }
    public function columnValue(string $key, Model $record, Request $request): mixed
    {
        if (isset($this->list['columns'][$key]['value'])) {
            [$class, $method] = $this->list['columns'][$key]['value'];
            return app($class)->{$method}($record, $request);
        }
        return parent::columnValue($key, $record, $request);
    }

    public function sections(): array
    {
        return $this->form['sections'] ?? ['main' => ['labelKey' => '']];
    }
    public function formLayout(): string { return $this->form['layout'] ?? 'sections'; }

    /** Registered fields in the engine's shape. List-only columns that are not form fields become read-only fields. */
    public function fields(): array
    {
        $fields = [];
        foreach ($this->form['fields'] ?? [] as $key => $field) $fields[$key] = $this->engineField($field);
        foreach ($this->list['columns'] as $key => $spec) {
            if (!isset($fields[$key])) $fields[$key] = ['labelKey' => $spec['label'], 'type' => 'text', 'readOnly' => true, 'persist' => false]
                + (isset($spec['ability']) ? ['readAbility' => $spec['ability']] : []);
        }
        return $fields;
    }

    private function engineField(array $field): array
    {
        $template = $field['type'];
        // A type the package does not ship is the project's own input view; its data validates as text.
        $type = Input::TEMPLATES[$template] ?? 'text';
        $rules = $field['rules'] ?? [];
        $engine = ['labelKey' => $field['label'], 'template' => $template, 'type' => $type, 'required' => in_array('required', $rules, true)];
        foreach ($rules as $rule) {
            if (!is_string($rule) || !preg_match('/^(max|min):(\d+(?:\.\d+)?)$/', $rule, $m)) continue;
            if (in_array($type, ['integer', 'number'], true)) $engine[$m[1]] = $m[2] + 0;
            elseif ($m[1] === 'max') $engine['maxLength'] = (int) $m[2];
        }
        foreach (['placeholder' => 'placeholderKey', 'help' => 'helpKey'] as $from => $to) if (isset($field[$from])) $engine[$to] = $field[$from];
        foreach (['default', 'section', 'span', 'readOnly', 'writeOnly', 'persist', 'optionalOnUpdate', 'rows', 'maxItems', 'itemMaxLength', 'step'] as $key) {
            if (array_key_exists($key, $field)) $engine[$key] = $field[$key];
        }
        if (isset($field['ability'])) $engine['readAbility'] = $engine['writeAbility'] = $field['ability'];
        if (isset($field['options']) && !$this->isCallable($field['options'])) $engine['options'] = $field['options'];
        if (in_array('nullable', $rules, true)) $engine['nullable'] = true;
        // Front end: the rules a browser can check itself, and the message keys, travel with the field.
        $engine['clientRules'] = array_values(array_filter($rules, fn ($r) => is_string($r) && preg_match('/^(required|email|url|numeric|integer|boolean|date|confirmed|nullable|(min|max|between|size|in|not_in|regex|digits|digits_between):.+)$/', $r)));
        $engine['messageKeys'] = [...($this->form['messages'] ?? []), ...($field['messages'] ?? [])];
        return $engine;
    }

    private function isCallable(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) === 2 && is_string($value[0]) && class_exists($value[0]) && is_string($value[1]) && method_exists($value[0], $value[1]);
    }

    public function fieldOptions(string $key, Request $request, ?Model $record): array
    {
        $options = $this->form['fields'][$key]['options'] ?? [];
        if ($this->isCallable($options)) return app($options[0])->{$options[1]}($request, $record);
        return parent::fieldOptions($key, $request, $record);
    }
    public function fieldMatrix(string $key, Request $request, ?Model $record): array
    {
        $options = $this->form['fields'][$key]['options'] ?? [];
        return $this->isCallable($options) ? app($options[0])->{$options[1]}($request, $record) : [];
    }

    /** The registered Laravel rules, minus what the engine already derives ({id} becomes the edited record's key). */
    public function rules(Request $request, ?Model $record): array
    {
        $rules = [];
        foreach ($this->form['fields'] ?? [] as $key => $field) {
            $rules[$key] = array_values(array_map(
                fn ($rule) => is_string($rule) ? str_replace('{id}', (string) ($record?->getKey() ?? 'NULL'), $rule) : $rule,
                array_filter($field['rules'] ?? [], fn ($rule) => !in_array($rule, ['required', 'nullable'], true)),
            ));
        }
        return $rules;
    }

    /** Message keys → translated text for the validator: field-level "field.rule", form-level "rule". */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->form['messages'] ?? [] as $rule => $key) $messages[$rule] = text($key);
        foreach ($this->form['fields'] ?? [] as $name => $field) foreach ($field['messages'] ?? [] as $rule => $key) $messages[$name.'.'.$rule] = text($key);
        return $messages;
    }
    public function attributes(): array
    {
        return array_map(fn ($field) => text($field['label']), $this->form['fields'] ?? []);
    }

    /** The form's 'prepare' callable normalises input before validation (trim, lowercase, …): ($values, $request, $record|null) => $values. */
    public function prepareValues(array $values, Request $request, ?Model $record): array
    {
        if (!isset($this->form['prepare'])) return $values;
        [$class, $method] = $this->form['prepare'];
        return app($class)->{$method}($values, $request, $record);
    }

    public function save(Model $record, array $values, Request $request): void
    {
        if (isset($this->form['save'])) {
            [$class, $method] = $this->form['save'];
            $saved = app($class)->{$method}($record->exists ? $record : null, $values, $request);
        } else {
            $service = $this->service($this->form);
            $saved = $record->exists ? $service->update($record, $values) : $service->create([...$record->getAttributes(), ...$values]);
        }
        if (!$saved instanceof Model) throw new \LogicException("Admin form '{$this->key}' save must return the saved model.");
        $record->setRawAttributes($saved->getAttributes(), true);
        $record->exists = true;
    }
    public function trash(Model $record, Request $request): void { $this->service()->moveToTrash($record->getKey()); }
    public function restore(Model $record, Request $request): void { $this->service()->restoreFromTrash($record->getKey()); }
}

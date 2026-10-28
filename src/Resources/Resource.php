<?php

declare(strict_types=1);

namespace Saola\Admin\Resources;

use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Http\Request;
use Saola\Admin\Pages\ResourceDocument;

/**
 * Internal CRUD definition the list/form engine and theme run on. Projects do not extend it: they register arrays with
 * Admin::list()/Admin::form(), which become a RegisteredResource. (Package tests drive the engine directly.)
 */
abstract class Resource
{
    /** Built-in list cells; any other renderer name is the project's view admin.columns.{renderer} (HostViews). */
    public const RENDERERS = ['text', 'badge', 'image', 'link', 'boolean', 'number', 'date'];

    abstract public function key(): string;
    abstract public function titleKey(): string;
    abstract public function model(): string;
    abstract public function fields(): array;
    abstract public function query(Request $request): Builder;

    public function sections(): array { return ['main' => ['labelKey' => '']]; }
    public function formLayout(): string { return 'sections'; }
    /** Return value => translation key, or value => ['label' => literal label]. Apply host visibility scope here. */
    public function fieldOptions(string $key, Request $request, ?Model $record): array { return $this->fields()[$key]['options'] ?? []; }
    /** Spec for a "matrix" field (see Matrix): fixed columns, groups of rows, cells holding option values. */
    public function fieldMatrix(string $key, Request $request, ?Model $record): array { return []; }
    public function columnValue(string $key, Model $record, Request $request): mixed { return $record->getRawOriginal($key); }

    public function store(): string { return EloquentResourceStore::class; }
    public function allows(Request $request): bool { return $this->can($request, 'viewAny', $this->model()); }

    /**
     * Abilities "{prefix}.view|create|update|delete|restore" stand in for a model Policy, so a plain CRUD needs no Policy class.
     * A registered Policy for the model always wins (custom rules such as "staff cannot edit owners").
     */
    public function abilityPrefix(): string { return ''; }
    public function ability(): string { return $this->abilityPrefix() === '' ? '' : $this->abilityPrefix().'.view'; }
    /** $operation is a Policy method name: viewAny, viewTrash, view, create, update, delete, restore, forceDelete. */
    public function can(Request $request, string $operation, Model|string $target): bool
    {
        $gate = \Illuminate\Support\Facades\Gate::forUser($request->user());
        if ($this->abilityPrefix() === '' || $gate->getPolicyFor($target) !== null) return $gate->allows($operation, $target);
        $ability = ['viewAny' => 'view', 'viewTrash' => 'view', 'view' => 'view', 'create' => 'create', 'update' => 'update', 'delete' => 'delete', 'restore' => 'restore'][$operation] ?? null;
        return $ability !== null && $gate->allows($this->abilityPrefix().'.'.$ability);
    }
    public function descriptionKey(): string { return ''; }
    public function searchable(): array { return []; }
    public function sortable(): array { return array_values(array_filter(array_column($this->columns(), 'key'), fn ($key) => $this->fields()[$key]['persist'] ?? true)); }
    public function columns(): array
    {
        $columns = [];
        foreach ($this->fields() as $key => $field) {
            if (!($field['writeOnly'] ?? false) && ($field['persist'] ?? true)) $columns[] = ['key' => $key, 'labelKey' => $field['labelKey']];
        }
        return $columns;
    }
    public function rules(Request $request, ?Model $record): array { return []; }
    /** Validator messages ('field.rule' or 'rule' => text) and attribute names (field => label), already translated. */
    public function messages(): array { return []; }
    public function attributes(): array { return []; }
    public function prepareValues(array $values, Request $request, ?Model $record): array { return $values; }
    /** Saola core models always carry SoftDeletes; their deleteMode decides whether it is on. */
    public function supportsTrash(): bool
    {
        if (!in_array(SoftDeletes::class, class_uses_recursive($this->model()), true)) return false;
        $model = app($this->model());
        return !method_exists($model, 'isSoftDeleteMode') || $model->isSoftDeleteMode();
    }
    public function supportsPurge(): bool { return false; }
    public function newRecord(Request $request): Model { return app($this->model()); }
    public function save(Model $record, array $values, Request $request): void { $record->forceFill($values)->save(); }
    public function trash(Model $record, Request $request): void { $record->delete(); }
    public function restore(Model $record, Request $request): void { $record->restore(); }
    public function purge(Model $record, Request $request): void { $record->forceDelete(); }

    /** Hosts may lock business invariants here, inside the same transaction as the write. */
    public function beforeMutation(string $operation, Model $record, Request $request): void {}

    public function path(): string { return '/'.trim(config('saola-admin.panel.prefix', 'admin'), '/').'/'.$this->key(); }
    public function endpoint(): string { return $this->path().'/_api'; }
    public function document(Request $request): ResourceDocument { return new ResourceDocument($this->titleKey(), $this->descriptionKey()); }

    /** Schema stays declarative; never execute class names, SQL or HTML from a request. */
    final public function schema(): array
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $this->key())) throw new \LogicException('Resource key must be a route-safe identifier of at most 64 characters.');
        $model = $this->model();
        if (!is_subclass_of($model, Model::class)) throw new \LogicException('Resource requires an Eloquent model.');
        $fields = $this->fields(); $sections = $this->sections();
        if (!in_array($this->formLayout(), ['sections', 'tabs'], true) || !$sections) throw new \LogicException('Invalid Resource form layout.');
        foreach ($sections as $key => $section) if (!preg_match('/^[a-z][a-z0-9_-]*$/D', (string) $key) || !is_array($section)
            || !is_string($section['labelKey'] ?? null) || !is_string($section['descriptionKey'] ?? '')) throw new \LogicException('Invalid Resource section.');
        foreach ($fields as $key => &$field) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', (string) $key) || !is_array($field)
                || !is_string($field['labelKey'] ?? null) || ($field['labelKey'] ?? '') === '') throw new \LogicException('Invalid Resource field.');
            $field = Input::normalize($field);
            if (!isset($sections[$field['section']])) throw new \LogicException('Resource field references an unknown section.');
            if (($field['persist'] ?? true) && (Input::multiple($field['type']) || $field['type'] === 'json')
                && !in_array(app($model)->getCasts()[$key] ?? '', $field['type'] === 'json' ? ['array', 'json', 'object'] : ['array', 'json'], true)) throw new \LogicException('Array/JSON inputs require a compatible Eloquent cast: '.$key);
            if (($field['type'] ?? '') === 'password' && !($field['writeOnly'] ?? false)) throw new \LogicException('Password fields must be write-only.');
            Input::options($field['options']);
        }
        unset($field);
        $keys = array_merge(array_keys($fields), [app($model)->getKeyName()]);
        $columns = $this->columns();
        if (!$columns || count(array_unique(array_column($columns, 'key'))) !== count($columns)) throw new \LogicException('Resource needs distinct list columns.');
        foreach ($columns as $column) {
            $key = $column['key'] ?? '';
            if (!in_array($key, $keys, true) || ($fields[$key]['writeOnly'] ?? false) || !is_string($column['labelKey'] ?? null)
                || !in_array($column['renderer'] ?? 'text', self::RENDERERS, true) && !preg_match('/^[a-z][a-z0-9-]{0,40}$/D', (string) $column['renderer'])) throw new \LogicException('Invalid Resource column.');
            if (isset($column['decimals']) && (!is_int($column['decimals']) || $column['decimals'] < 0 || $column['decimals'] > 6)) throw new \LogicException('Invalid Resource number precision.');
        }
        foreach (array_merge($this->searchable(), $this->sortable()) as $key) {
            if (!in_array($key, $keys, true) || ($fields[$key]['writeOnly'] ?? false)) throw new \LogicException('Search/sort cannot expose a hidden Resource field.');
        }
        if (!$this->sortable()) throw new \LogicException('Resource needs a sort key.');
        return $fields;
    }
}

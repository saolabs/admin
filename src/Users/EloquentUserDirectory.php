<?php

declare(strict_types=1);

namespace Saola\Admin\Users;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Saola\Admin\Pages\{ListingQuery, ResourceDocument, ResourceListing};

/** A read-only adapter over the host identity; no User table, role or credential mutation. */
final class EloquentUserDirectory implements UserDirectory
{
    private function model(): Model
    {
        $guard = config('saola-admin.panel.guard') ?: config('auth.defaults.guard');
        $provider = config('auth.guards.'.$guard.'.provider');
        $class = config('saola-admin.users.model') ?: ($provider ? config('auth.providers.'.$provider.'.model') : null);
        if (!is_string($class) || !is_subclass_of($class, Model::class) || !is_subclass_of($class, Authenticatable::class)) {
            throw new \LogicException('Configure an Eloquent authenticatable model for the admin guard or bind UserDirectory.');
        }
        return app($class);
    }

    private function scope(): UserQueryScope
    {
        $class = config('saola-admin.users.query_scope');
        if (!is_string($class) || !is_subclass_of($class, UserQueryScope::class)) throw new \LogicException('Configure saola-admin.users.query_scope with a host UserQueryScope before enabling the user directory.');
        return app($class);
    }

    private function columns(Model $model): array
    {
        $columns = ['id' => $model->getKeyName(), 'name' => config('saola-admin.users.columns.name', 'name'), 'email' => config('saola-admin.users.columns.email', 'email')];
        foreach ($columns as $column) {
            if (!is_string($column) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) throw new \LogicException('User directory columns must be plain SQL column names.');
        }
        if (count(array_unique($columns)) !== 3) throw new \LogicException('User directory columns must be distinct.');
        return $columns;
    }

    public function allows(Request $request): bool
    {
        return Gate::forUser($request->user())->allows('viewAny', get_class($this->model()));
    }

    public function document(Request $request, ListingQuery $query): ResourceDocument
    {
        abort_unless($this->allows($request), 403);
        $model = $this->model(); $columns = $this->columns($model);
        $builder = $this->scope()->apply($model->newQuery(), $request);
        if (get_class($builder->getModel()) !== get_class($model)) throw new \LogicException('User scope changed the identity model.');
        if ($query->search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query->search).'%';
            $builder->where(function ($search) use ($model, $columns, $pattern) {
                $grammar = $search->getQuery()->getGrammar();
                foreach (['name', 'email'] as $key) {
                    $column = $grammar->wrap($model->qualifyColumn($columns[$key]));
                    $search->orWhereRaw("LOWER($column) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                }
            });
        }
        // Reapply an explicit order and projection after host scopes (including global scopes).
        $builder = $builder->applyScopes()->withoutGlobalScopes();
        $builder->reorder($model->qualifyColumn($columns[$query->sort]), $query->direction);
        if ($query->sort !== 'id') $builder->orderBy($model->qualifyColumn($columns['id']), $query->direction);
        $page = $builder->withoutEagerLoads()->select(array_map($model->qualifyColumn(...), array_values($columns)))
            ->paginate($query->perPage, ['*'], 'page', $query->page);
        $rows = [];
        foreach ($page->items() as $record) {
            $row = [];
            foreach ($columns as $key => $column) {
                $value = $record->getRawOriginal($column);
                if (!is_scalar($value) && $value !== null) throw new \LogicException('User identity values must be scalar.');
                $row[$key] = $value === null ? '' : (string) $value;
            }
            $rows[] = $row;
        }
        $definitions = array_map(fn ($key) => ['key' => $key, 'labelKey' => 'admin.users.'.$key], array_keys($columns));
        return new ResourceDocument('admin.users.title', 'admin.users.description', [], $definitions, $rows,
            new ResourceListing($query, $page->total(), route('saola.admin.page', ['page' => 'users'], false), app()->getLocale(), $definitions));
    }

    public function diagnostics(): array
    {
        try {
            $model = $this->model(); $columns = $this->columns($model); $this->scope();
            $schema = $model->getConnection()->getSchemaBuilder();
            if (!$schema->hasTable($model->getTable())) return ['User directory table is missing: '.$model->getTable()];
            $errors = [];
            foreach ($columns as $column) if (!$schema->hasColumn($model->getTable(), $column)) $errors[] = 'User directory column is missing: '.$column;
            return $errors;
        } catch (\Throwable $error) { return [$error->getMessage()]; }
    }
}

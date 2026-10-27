<?php

declare(strict_types=1);

namespace Saola\Admin\Users;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Saola\Admin\Resources\Resource;

/**
 * Base list/form/trash for the host's user table: name, email and password.
 * A host extends it in app/Modules/Users/Resources (discovered automatically), adds its own columns
 * and keeps `users.enabled=false`; nothing here needs to be edited or copied.
 */
abstract class UserResource extends Resource
{
    public function key(): string { return 'users'; }
    public function titleKey(): string { return 'admin.users.title'; }
    public function ability(): string { return config('saola-admin.users.read_ability', 'view-admin-users'); }
    public function searchable(): array { return ['name', 'email']; }
    public function sortable(): array { return ['name', 'email']; }
    protected function passwordMin(): int { return 8; }

    /** Model of the admin guard, or `users.model` when set. */
    public function model(): string
    {
        $guard = config('saola-admin.panel.guard') ?: config('auth.defaults.guard');
        $provider = config('auth.guards.'.$guard.'.provider');
        $class = config('saola-admin.users.model') ?: ($provider ? config('auth.providers.'.$provider.'.model') : null);
        if (!is_string($class) || !is_subclass_of($class, Model::class) || !is_subclass_of($class, Authenticatable::class)) {
            throw new \LogicException('Configure an Eloquent authenticatable model for the admin guard or saola-admin.users.model.');
        }
        return $class;
    }

    /** Visibility comes from `users.query_scope` (a host UserQueryScope), never guessed. */
    public function query(Request $request): Builder
    {
        $scope = config('saola-admin.users.query_scope');
        if (!is_string($scope) || !is_subclass_of($scope, UserQueryScope::class)) throw new \LogicException('Configure saola-admin.users.query_scope with a host UserQueryScope.');
        return app($scope)->apply(app($this->model())->newQuery(), $request);
    }

    public function columns(): array
    {
        return array_map(fn ($key) => ['key' => $key, 'labelKey' => 'admin.users.'.$key], ['name', 'email']);
    }

    public function fields(): array
    {
        return [
            'name' => ['labelKey' => 'admin.users.name', 'type' => 'text', 'required' => true, 'maxLength' => 100],
            'email' => ['labelKey' => 'admin.users.email', 'type' => 'email', 'required' => true, 'maxLength' => 200],
            'password' => ['labelKey' => 'admin.resource.password', 'type' => 'password', 'writeOnly' => true, 'required' => true, 'optionalOnUpdate' => true, 'maxLength' => 200],
            'password_confirmation' => ['labelKey' => 'admin.resource.password_confirmation', 'type' => 'password', 'writeOnly' => true, 'persist' => false, 'required' => true, 'optionalOnUpdate' => true, 'maxLength' => 200],
        ];
    }

    public function rules(Request $request, ?Model $record): array
    {
        $sometimes = $record ? ['sometimes'] : [];
        return [
            'name' => [...$sometimes, 'required', 'string', 'max:100'],
            'email' => [...$sometimes, 'required', 'email', 'max:200', Rule::unique($this->model(), 'email')->ignore($record?->getKey())],
            'password' => [$record ? 'sometimes' : 'required', 'string', 'min:'.$this->passwordMin(), 'max:200', 'confirmed'],
            'password_confirmation' => ['sometimes', 'string', 'max:200'],
        ];
    }

    public function prepareValues(array $values, Request $request, ?Model $record): array
    {
        if (is_string($values['email'] ?? null)) $values['email'] = mb_strtolower(trim($values['email']));
        if (is_string($values['name'] ?? null)) $values['name'] = trim($values['name']);
        return $values;
    }

    public function save(Model $record, array $values, Request $request): void
    {
        if (isset($values['email']) && $record->getRawOriginal('email') !== $values['email']) $record->email_verified_at = null;
        if (isset($values['password'])) $record->setRememberToken(Str::random(60));
        parent::save($record, $values, $request);
    }
}

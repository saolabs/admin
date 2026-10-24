<?php

namespace Saola\Admin\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Saola\Admin\Users\UserQueryScope;

final class TenantUserScope implements UserQueryScope
{
    public function apply(Builder $query, Request $request): Builder
    {
        return $query->where('tenant_id', $request->user()->tenant_id);
    }
}

<?php

declare(strict_types=1);

namespace Saola\Admin\Users;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Host-owned visibility scope. It must constrain the query before search, count and pagination. */
interface UserQueryScope
{
    public function apply(Builder $query, Request $request): Builder;
}

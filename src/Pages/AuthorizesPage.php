<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

use Illuminate\Http\Request;

/** Additional request-local authorization, applied to navigation and direct requests alike. */
interface AuthorizesPage
{
    public function allows(Request $request): bool;
}

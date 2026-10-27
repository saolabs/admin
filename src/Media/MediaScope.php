<?php

declare(strict_types=1);

namespace Saola\Admin\Media;

use Illuminate\Http\Request;

/** Bind this contract to partition libraries by the authenticated host tenant. */
interface MediaScope
{
    public function key(Request $request): string;
}

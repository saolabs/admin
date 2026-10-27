<?php

declare(strict_types=1);

namespace Saola\Admin\Media;

use Illuminate\Http\Request;

final class GlobalMediaScope implements MediaScope
{
    public function key(Request $request): string { return 'global'; }
}

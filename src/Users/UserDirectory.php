<?php

declare(strict_types=1);

namespace Saola\Admin\Users;

use Illuminate\Http\Request;
use Saola\Admin\Pages\{ListingQuery, ResourceDocument};

interface UserDirectory
{
    /** Additional host authorization; the page's read ability is also required. */
    public function allows(Request $request): bool;
    public function document(Request $request, ListingQuery $query): ResourceDocument;
    /** Read-only configuration/storage diagnostics. Never seed, alter schema or grant permissions. */
    public function diagnostics(): array;
}

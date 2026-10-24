<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Gate};
use Saola\Core\Language\ClientCatalogs;

/** Shared authorization boundary for dictionary HTTP responses and initial SSR projections. */
final class DictionaryAccess
{
    public function authorize(Request $request, ClientCatalogs $catalogs, string $scope): void
    {
        try { $definition = $catalogs->definition($scope); }
        catch (\InvalidArgumentException) { abort(404); }
        if (($definition['public'] ?? false) === true) return;
        $user = !empty($definition['guard']) ? Auth::guard($definition['guard'])->user() : $request->user();
        $ability = $definition['ability'] ?? null;
        abort_unless($user && is_string($ability) && $ability !== '' && Gate::forUser($user)->allows($ability, [$scope]), 403);
    }
}

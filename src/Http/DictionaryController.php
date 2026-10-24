<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Saola\Core\Language\ClientCatalogs;

final class DictionaryController
{
    public function __invoke(Request $request, ClientCatalogs $catalogs, DictionaryAccess $access, string $scope, string $locale)
    {
        $access->authorize($request, $catalogs, $scope);
        $snapshot = $catalogs->snapshot($scope, $locale);
        abort_if($snapshot['revision'] === 0, 404, 'Locale has no published translations.');
        $response = response()->json($snapshot, 200, [
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'Cookie, Authorization',
            'X-Language-Revision' => (string) $snapshot['revision'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->setEtag(hash('sha256', $response->getContent()));
        // Check authorization before conditional requests, including a previously obtained ETag.
        $response->isNotModified($request);
        return $response;
    }
}

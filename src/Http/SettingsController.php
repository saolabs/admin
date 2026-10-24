<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Saola\Admin\Settings\{SettingsRegistry, SettingsStore};

final class SettingsController
{
    public function __invoke(Request $request, SettingsRegistry $registry, SettingsStore $store, string $group)
    {
        $schema = $registry->get($group);
        $gate = Gate::forUser($request->user());
        abort_unless($gate->allows($schema->readAbility), 403);
        if ($request->isMethod('PUT')) {
            abort_unless($gate->allows($schema->writeAbility), 403);
            abort_if(strlen($request->getContent()) > 65536, 413);
            abort_unless($request->isJson(), 415);
            // Read the JSON body directly: Laravel's global string middleware may
            // otherwise turn an intentional empty translation into null.
            try { $body = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { abort(422, 'Invalid JSON.'); }
            abort_unless(is_array($body) && !array_diff(array_keys($body), ['revision', 'patch']), 422);
            $data = app('validator')->make($body, ['revision' => ['required', 'integer', 'min:0'], 'patch' => ['required', 'array']])->validate();
            abort_unless(is_int($data['revision']), 422);
            $document = $store->update($schema, $data['revision'], $data['patch']);
        } else $document = $store->read($schema);
        return response()->json(['data' => $document, 'schema' => ['titleKey' => $schema->titleKey, 'fields' => $schema->fields],
            'canUpdate' => $gate->allows($schema->writeAbility)]);
    }
}

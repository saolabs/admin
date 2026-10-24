<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Saola\Admin\Settings\{SettingsRegistry, SettingsStore};
use Saola\Core\Http\Controllers\BaseWebController;

final class SettingsEditorController extends BaseWebController
{
    protected $context = 'admin';

    public function __invoke(Request $request, SettingsRegistry $registry, SettingsStore $store, PanelData $panel)
    {
        $groups = $panel->groups($request);
        abort_unless($groups, 403);
        $key = $request->query('group', $groups[0]['key']);
        abort_unless(is_string($key), 422);
        $schema = $registry->get($key);
        $gate = Gate::forUser($request->user());
        abort_unless($gate->allows($schema->readAbility), 403);
        $document = $store->read($schema);
        $locale = $request->query('content_locale', $document['defaultLocale']);
        abort_unless(is_string($locale) && in_array($locale, $document['contentLocales'], true), 422);
        $fields = [];
        foreach ($schema->fields as $name => $field) {
            $localized = $field['localized'] ?? false;
            $values = $localized ? ($document['localized'][$locale] ?? []) : $document['values'];
            $fallback = $localized ? ($document['localized'][$document['defaultLocale']][$name] ?? $field['default']) : $field['default'];
            $present = array_key_exists($name, $values);
            $fields[] = ['key' => $name, 'labelKey' => $field['labelKey'], 'type' => $field['type'], 'localized' => $localized,
                'value' => $present ? $values[$name] : $fallback, 'present' => $present,
                'maxLength' => $field['maxLength'] ?? 2048, 'min' => $field['min'] ?? '', 'max' => $field['max'] ?? ''];
        }
        return $this->response(array_replace($panel->prepare($request, 'settings'), ['editor' => [
            'titleKey' => $schema->titleKey, 'groups' => $groups, 'group' => $schema->key,
            'document' => $document, 'fields' => $fields, 'contentLocale' => $locale,
            'canUpdate' => $gate->allows($schema->writeAbility), 'csrfToken' => csrf_token(),
            'endpoint' => route('saola.admin.settings', ['group' => $key]),
        ]]), '@RAW:pages.settings');
    }
}

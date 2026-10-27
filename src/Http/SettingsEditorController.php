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
        $localizedGroup = $schema->hasLocalizedFields();
        $locale = $localizedGroup ? $request->query('content_locale', $document['defaultLocale']) : '';
        abort_unless(is_string($locale) && (!$localizedGroup || in_array($locale, $document['contentLocales'], true)), 422);
        if ($localizedGroup) foreach ($groups as &$item) {
            if ($registry->get($item['key'])->hasLocalizedFields()) $item['url'] = route('saola.admin.settings.editor', ['group' => $item['key'], 'content_locale' => $locale]);
        }
        unset($item);
        $categories = [];
        foreach ($groups as $group) {
            $categories[$group['category']] ??= ['key' => $group['category'], 'titleKey' => $group['categoryTitleKey'], 'groups' => []];
            $categories[$group['category']]['groups'][] = $group;
        }
        $fields = [];
        foreach ($schema->fields as $name => $field) {
            if ($field['hidden'] ?? false) continue;
            $localized = $field['localized'] ?? false;
            $values = $localized ? ($document['localized'][$locale] ?? []) : $document['values'];
            $fallback = $localized ? ($document['localized'][$document['defaultLocale']][$name] ?? $field['default']) : $field['default'];
            $present = array_key_exists($name, $values);
            $fields[] = ['key' => $name, 'labelKey' => $field['labelKey'], 'placeholderKey' => $field['placeholderKey'] ?? '', 'type' => $field['type'], 'localized' => $localized,
                'value' => $field['type'] === 'secret' ? '' : ($present ? $values[$name] : $fallback), 'present' => $present, 'media' => $field['media'] ?? false,
                'options' => array_map(fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($field['options'] ?? []), array_values($field['options'] ?? [])),
                'maxLength' => $field['maxLength'] ?? 2048, 'min' => $field['min'] ?? '', 'max' => $field['max'] ?? ''];
        }
        return $this->response(array_replace($panel->prepare($request, 'settings.'.$schema->category), ['editor' => [
            'categories' => array_values($categories), 'localizedGroup' => $localizedGroup,
            'category' => $schema->category, 'tabs' => $categories[$schema->category]['groups'],
            'titleKey' => $schema->titleKey, 'groups' => $groups, 'group' => $schema->key,
            'document' => $document, 'fields' => $fields, 'contentLocale' => $locale,
            'canUpdate' => $gate->allows($schema->writeAbility), 'csrfToken' => csrf_token(),
            'endpoint' => route('saola.admin.settings', ['group' => $key]),
            'mediaEditor' => config('saola-admin.media.enabled', true) && $gate->allows(config('saola-admin.media.abilities.view')) ? app(MediaController::class)->editor($request) : null,
        ]]), '@RAW:pages.settings');
    }
}

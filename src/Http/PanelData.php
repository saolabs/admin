<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Saola\Admin\Pages\PageRegistry;
use Saola\Admin\Settings\SettingsRegistry;
use Saola\Core\Language\ClientCatalogs;

/** Request-local shell projection shared by resource and settings pages. */
final class PanelData
{
    public function prepare(Request $request, string $currentPage): array
    {
        $locale = app(\Saola\Admin\Language\LocaleManager::class)->resolveUi($request);
        app()->setLocale($locale);
        $scope = config('saola-admin.panel.language_scope', 'admin');
        $catalogs = app(ClientCatalogs::class);
        app(DictionaryAccess::class)->authorize($request, $catalogs, $scope);
        $request->attributes->set('saola.i18n', $catalogs->clientConfig($scope, $locale));
        $navigation = app(PageRegistry::class)->navigation($request);
        if (config('saola-admin.panel.ui_catalog', false) && config('saola-admin.panel.theme') === 'material') {
            $navigation[] = ['key' => 'ui-library', 'titleKey' => 'admin.ui.title', 'url' => route('saola.admin.ui.catalog')];
        }
        if (config('saola-admin.media.enabled', true) && Gate::forUser($request->user())->allows(config('saola-admin.media.abilities.view'))) $navigation[] = ['key' => 'media', 'titleKey' => 'admin.media.title', 'url' => route('saola.admin.media.editor')];
        if (Gate::forUser($request->user())->allows(config('saola-admin.language.abilities.view'))) $navigation[] = ['key' => 'languages', 'titleKey' => 'admin.languages.title', 'url' => route('saola.admin.languages.editor')];
        $groups = $this->groups($request);
        if ($groups) {
            $children = [];
            foreach ($groups as $group) {
                $children[$group['category']] ??= ['key' => 'settings.'.$group['category'], 'titleKey' => $group['categoryTitleKey'], 'url' => $group['url']];
            }
            $navigation[] = ['key' => 'settings', 'titleKey' => 'admin.settings.title', 'url' => $groups[0]['url'], 'children' => array_values($children)];
        }
        foreach ($navigation as &$item) {
            $icons = config('saola-admin.panel.icons', []);
            $defaultIcon = ['workspace' => 'dashboard', 'users' => 'users', 'categories' => 'list', 'media' => 'media', 'languages' => 'language', 'settings' => 'settings'][$item['key']] ?? 'folder';
            $icon = $icons[$item['key']] ?? $defaultIcon;
            $item['iconPath'] = NavigationIcons::path(is_string($icon) ? $icon : $defaultIcon);
            // A host may name any Remix icon class directly (ri-*); everything else uses the small named set.
            $item['iconClass'] = is_string($icon) && preg_match('/^ri-[a-z0-9-]{2,40}$/D', $icon) ? $icon : match ($icon) {
                'dashboard' => 'ri-dashboard-2-line', 'users' => 'ri-group-line', 'media' => 'ri-image-line',
                'language' => 'ri-global-line', 'settings' => 'ri-settings-3-line', 'list' => 'ri-list-check', default => 'ri-folder-line',
            };
            $item['children'] ??= [];
            foreach ($item['children'] as &$child) $child['active'] = $child['key'] === $currentPage;
            unset($child);
            $item['active'] = $item['key'] === $currentPage || in_array($currentPage, array_column($item['children'], 'key'), true);
            $item['open'] = $item['active'];
        }
        unset($item);
        $locales = app(\Saola\Admin\Language\LocaleManager::class)->uiLocales();
        return [...app(PanelBranding::class)->project($locale), 'currentPage' => $currentPage, 'navigation' => $navigation,
            'locales' => array_map(fn ($row) => array_replace($row, ['name' => $row['name'] !== $row['code'] ? $row['name'] : (['en' => 'English', 'vi' => 'Tiếng Việt'][$row['code']] ?? $row['code'])]), $locales), 'uiLocale' => $locale,
            'account' => app(HeaderAccount::class)->project($request),
            'dictionaryPattern' => route('saola.language.dictionary', ['scope' => $scope, 'locale' => '_locale_'])];
    }

    public function groups(Request $request): array
    {
        $groups = [];
        foreach (app(SettingsRegistry::class)->all() as $group) {
            if (Gate::forUser($request->user())->allows($group->readAbility)) {
                $groups[] = ['key' => $group->key, 'titleKey' => $group->titleKey, 'category' => $group->category, 'categoryTitleKey' => $group->categoryTitleKey, 'url' => route('saola.admin.settings.editor', ['group' => $group->key])];
            }
        }
        return $groups;
    }
}

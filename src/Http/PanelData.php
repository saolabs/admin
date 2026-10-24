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
        $locale = $request->query('locale', $request->getPreferredLanguage() ?: config('app.locale'));
        if ($request->header('X-Sao-Response') === 'json' && $request->header('Accept-Language')) $locale = $request->getPreferredLanguage();
        abort_unless(is_string($locale) && preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/D', $locale), 422);
        app()->setLocale($locale);
        $scope = config('saola-admin.panel.language_scope', 'admin');
        $catalogs = app(ClientCatalogs::class);
        app(DictionaryAccess::class)->authorize($request, $catalogs, $scope);
        $request->attributes->set('saola.i18n', $catalogs->clientConfig($scope, $locale));
        $navigation = app(PageRegistry::class)->navigation($request);
        $groups = $this->groups($request);
        if ($groups) $navigation[] = ['key' => 'settings', 'titleKey' => 'admin.settings.title', 'url' => $groups[0]['url']];
        $locales = app('db')->connection(config('saola-admin.language.connection'))
            ->table(config('saola-admin.language.table_prefix', 'saola_').'locales')->where('published_revision', '>', 0)
            ->orderBy('code')->pluck('code')->map(fn ($code) => ['code' => $code])->all();
        return ['currentPage' => $currentPage, 'navigation' => $navigation, 'brand' => (string) config('saola-admin.panel.brand', 'Saola Admin'),
            'locales' => $locales, 'dictionaryPattern' => route('saola.language.dictionary', ['scope' => $scope, 'locale' => '_locale_'])];
    }

    public function groups(Request $request): array
    {
        $groups = [];
        foreach (app(SettingsRegistry::class)->all() as $group) {
            if (Gate::forUser($request->user())->allows($group->readAbility)) {
                $groups[] = ['key' => $group->key, 'titleKey' => $group->titleKey, 'url' => route('saola.admin.settings.editor', ['group' => $group->key])];
            }
        }
        return $groups;
    }
}

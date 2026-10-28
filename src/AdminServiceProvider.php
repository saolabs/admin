<?php

declare(strict_types=1);

namespace Saola\Admin;

use Illuminate\Support\ServiceProvider;
use Saola\Admin\Console\ExportLanguageCommand;
use Saola\Admin\Console\SyncLanguageCommand;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Language\LanguageStore;
use Saola\Core\Providers\LanguageServiceProvider;
use Illuminate\Support\Facades\Route;
use Saola\Admin\Http\DictionaryController;
use Saola\Admin\Language\LanguageExchange;
use Saola\Admin\Console\ImportLanguageCommand;
use Saola\Admin\Console\ApplyLanguageCommand;
use Saola\Admin\Registry\AdminRegistry;
use Saola\Admin\Http\AdminAccess;
use Saola\Admin\Http\HomeController;
use Saola\Core\Support\SPA;
use Saola\Core\Providers\SaolaServiceProvider;
use Saola\Core\Engines\ViewContextManager;
use Saola\Admin\Console\PublishAdminCommand;
use Saola\Admin\Console\InstallAdminCommand;
use Saola\Admin\Console\DoctorAdminCommand;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/saola-admin.php', 'saola-admin');
        $this->app->register(LanguageServiceProvider::class);
        $this->app->register(SaolaServiceProvider::class);
        $this->app->register(\Saola\Core\Providers\BladeDirectiveServiceProvider::class);
        $this->app->singleton(AdminRegistry::class);
        \Saola\Admin\Registry\ModuleMenus::register();
        $this->app->scoped(\Saola\Admin\Media\MediaLibrary::class);
        $this->app->bind(\Saola\Admin\Media\MediaScope::class, \Saola\Admin\Media\GlobalMediaScope::class);
        $this->app->singleton(\Saola\Admin\Settings\SettingsRegistry::class);
        $this->app->scoped(\Saola\Admin\Language\LocaleManager::class, fn ($app) => new \Saola\Admin\Language\LocaleManager(
            $app['db']->connection(config('saola-admin.language.connection')), config('saola-admin.language.table_prefix', 'saola_'),
            ['uiDefault' => config('app.locale', 'en'), 'uiFallback' => config('app.fallback_locale', 'en'),
                'contentDefault' => config('saola-admin.settings.default_locale', 'en'), 'contentLocales' => config('saola-admin.settings.content_locales', ['en', 'vi']), 'uiDisabled' => []],
            (bool) config('saola-admin.language.detect_browser', false),
        ));
        $this->app->scoped(\Saola\Core\Language\TextResolver::class, fn ($app) => new \Saola\Core\Language\TextResolver(
            $app->make(LanguageStore::class), fn () => $app->getLocale(), fn () => $app->make(\Saola\Admin\Language\LocaleManager::class)->uiFallback(),
        ));
        $this->app->scoped(\Saola\Admin\Settings\SettingsStore::class, fn ($app) => new \Saola\Admin\Settings\DatabaseSettingsStore(
            $app['db']->connection(config('saola-admin.settings.connection')),
            config('saola-admin.settings.table', 'saola_settings'),
            config('saola-admin.settings.content_locales', ['en', 'vi']),
            config('saola-admin.settings.default_locale', 'en'),
            fn () => $app->make(\Saola\Admin\Language\LocaleManager::class)->contentPolicy(),
        ));
        $this->app->scoped(DatabaseLanguageStore::class, fn ($app) => new DatabaseLanguageStore(
            $app['db']->connection($app['config']->get('saola-admin.language.connection')),
            $app['config']->get('saola-admin.language.table_prefix', 'saola_'),
        ));
        // Installing admin selects its DB store; a host provider may bind another adapter afterwards.
        $this->app->bind(LanguageStore::class, fn ($app) => $app->make(DatabaseLanguageStore::class));
        $this->app->scoped(LanguageExchange::class, fn ($app) => new LanguageExchange(
            $app['db']->connection($app['config']->get('saola-admin.language.connection')),
            $app->make(DatabaseLanguageStore::class),
            $app['config']->get('saola-admin.language.table_prefix', 'saola_'),
            $app['config']->get('saola-admin.language.source_locale', 'en'),
            (int) $app['config']->get('saola-admin.language.exchange.max_bytes', 2097152),
            (int) $app['config']->get('saola-admin.language.exchange.max_keys', 10000),
            (int) $app['config']->get('saola-admin.language.exchange.ttl', 604800),
        ));
    }

    public function boot(): void
    {
        // Website assets must remain deliverable even when the admin panel is disabled.
        $this->app->booted(fn () => $this->registerMediaDelivery());
        $this->app['router']->aliasMiddleware('saola.locale', \Saola\Admin\Http\ResolveUiLocale::class);
        $this->app->make(LanguageSources::class)->register(
            'saola/admin', __DIR__.'/../lang', ['admin', 'common'],
            $this->app->langPath('vendor/admin'),
        );
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/admin')], 'saola-admin-lang');
        $this->publishes([__DIR__.'/../config/saola-admin.php' => config_path('saola-admin.php')], 'saola-admin-config');
        $this->commands([SyncLanguageCommand::class, ExportLanguageCommand::class, ImportLanguageCommand::class, ApplyLanguageCommand::class,
            PublishAdminCommand::class, InstallAdminCommand::class, DoctorAdminCommand::class]);
        $scope = config('saola-admin.panel.language_scope', 'admin');
        if (config('sao.i18n.client_scopes.'.$scope) === null) {
            config(['sao.i18n.client_scopes.'.$scope => ['keys' => ['admin'], 'ability' => config('saola-admin.panel.ability'), 'guard' => config('saola-admin.panel.guard')]]);
        }
        $this->app->afterResolving(\Saola\Admin\Settings\SettingsRegistry::class, function ($registry) {
            if (!config('saola-admin.settings.register_defaults', true)) return;
            \Saola\Admin\Settings\DefaultSettings::register($registry);
        });
        // Modules register menus/lists/forms while booting; cross-entry checks run once all of them have.
        $this->app->booted(fn () => app(AdminRegistry::class)->verify());
        if (config('saola-admin.panel.enabled', false)) {
            config(['sao.assets.css.admin' => app(\Saola\Admin\Themes\AdminTheme::class)->definition()['css']]);
            $this->app->booted(fn () => $this->registerPanel());
        }
        if (config('saola-admin.language.dictionary.enabled', true) && !$this->app->routesAreCached()) {
            Route::middleware(config('saola-admin.language.dictionary.middleware', ['web', 'throttle:60,1']))
                ->prefix(config('saola-admin.language.dictionary.prefix', 'saola/languages'))
                ->get('{scope}/{locale}', DictionaryController::class)
                ->where(['scope' => '[A-Za-z0-9_-]+', 'locale' => '[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*'])
                ->name('saola.language.dictionary');
        }
    }

    private function registerMediaDelivery(): void
    {
        if ($this->app->routesAreCached()) return;
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'saola/media/{media}' || $route->getName() === 'saola.media.content') throw new \RuntimeException('Media delivery route collision.');
        }
        Route::get('saola/media/{media}', [\Saola\Admin\Http\MediaController::class, 'content'])->whereUuid('media')->name('saola.media.content');
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    private function registerPanel(): void
    {
        $manager = $this->app->make(ViewContextManager::class);
        app(\Saola\Admin\Themes\AdminTheme::class)->apply();
        $prefix = trim((string) config('saola-admin.panel.prefix', 'admin'), '/');
        if ($prefix === '' || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $prefix)) throw new \InvalidArgumentException('Invalid admin prefix.');
        if (!$this->app->routesAreCached()) {
            foreach (Route::getRoutes() as $route) {
                $methods = $route->uri() === $prefix.'/_api/languages/{locale}/{operation}' ? ['POST']
                    : ($route->uri() === $prefix.'/_api/languages/batches/{batch}' ? ['GET', 'POST'] : ['GET', 'PUT']);
                if (array_intersect($methods, $route->methods()) && in_array($route->uri(), [$prefix, $prefix.'/settings', $prefix.'/settings/{group}', $prefix.'/languages', $prefix.'/_api/languages', $prefix.'/_api/languages/{locale}/{operation}', $prefix.'/_api/languages/batches/{batch}'], true)
                    || in_array($route->uri(), [$prefix.'/media', $prefix.'/_api/media', $prefix.'/_api/media/{media}', $prefix.'/_api/media/{media}/{operation}',
                        $prefix.'/_ui'], true)) {
                    throw new \RuntimeException('Admin route collision at '.$route->uri().'; choose another panel prefix before enabling.');
                }
            }
            Route::middleware(['web', AdminAccess::class])->group(function () use ($prefix) {
                Route::get($prefix.'/_ui', \Saola\Admin\Http\UiCatalogController::class)->name('saola.admin.ui.catalog');
                Route::get($prefix.'/media', [\Saola\Admin\Http\MediaController::class, 'page'])->name('saola.admin.media.editor');
                Route::get($prefix.'/_api/media', [\Saola\Admin\Http\MediaController::class, 'index'])->name('saola.admin.media.api');
                Route::post($prefix.'/_api/media', [\Saola\Admin\Http\MediaController::class, 'upload'])->middleware('throttle:30,1');
                Route::match(['GET', 'PUT'], $prefix.'/_api/media/{media}', [\Saola\Admin\Http\MediaController::class, 'item'])->whereUuid('media')->name('saola.admin.media.item');
                Route::post($prefix.'/_api/media/{media}/{operation}', [\Saola\Admin\Http\MediaController::class, 'state'])->whereUuid('media')->where('operation', 'trash|restore')->name('saola.admin.media.state');
                Route::match(['GET', 'PUT'], $prefix.'/settings/{group}', \Saola\Admin\Http\SettingsController::class)->where('group', '[a-z][a-z0-9_-]*')->name('saola.admin.settings');
                Route::get($prefix.'/settings', \Saola\Admin\Http\SettingsEditorController::class)->name('saola.admin.settings.editor');
                Route::get($prefix.'/languages', [\Saola\Admin\Http\LanguagesController::class, 'page'])->name('saola.admin.languages.editor');
                Route::match(['GET', 'PUT'], $prefix.'/_api/languages', [\Saola\Admin\Http\LanguagesController::class, 'index'])->name('saola.admin.languages.api');
                Route::post($prefix.'/_api/languages/{locale}/{operation}', [\Saola\Admin\Http\LanguagesController::class, 'exchange'])
                    ->where(['locale' => '[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*', 'operation' => 'export|preview'])->name('saola.admin.languages.exchange');
                Route::match(['GET', 'POST'], $prefix.'/_api/languages/batches/{batch}', [\Saola\Admin\Http\LanguagesController::class, 'batch'])
                    ->whereUuid('batch')->name('saola.admin.languages.batch');
                Route::get($prefix, HomeController::class)->name('saola.admin.home');
            });
        }
        // Panel routes are added from booted(), after Laravel may have refreshed
        // route lookups already. Refresh names/actions for real HTTP hosts too.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
        // Without a home menu of the project, the package's overview opens the panel and leads the sidebar.
        if (!config('saola-admin.panel.home_page') && !app(AdminRegistry::class)->menuOf('workspace')) {
            app(AdminRegistry::class)->menu('workspace', ['title' => 'admin.workspace.title', 'route' => 'saola.admin.home', 'icon' => 'dashboard',
                'ability' => (string) config('saola-admin.panel.ability', 'access-admin'), 'order' => 0]);
        }
        $active = SPA::isActive(); SPA::active();
        SPA::addRoute('admin', 'saola.admin.settings.editor', '/'.$prefix.'/settings', ['kind' => 'explicit', 'logical' => '@BASE:pages.settings']);
        SPA::addRoute('admin', 'saola.admin.languages.editor', '/'.$prefix.'/languages', ['kind' => 'explicit', 'logical' => '@BASE:pages.languages']);
        SPA::addRoute('admin', 'saola.admin.media.editor', '/'.$prefix.'/media', ['kind' => 'explicit', 'logical' => '@BASE:pages.media']);
        SPA::addRoute('admin', 'saola.admin.ui.catalog', '/'.$prefix.'/_ui', ['kind' => 'explicit', 'logical' => '@BASE:pages.ui-library']);
        if (!$active) SPA::inactive();
    }
}

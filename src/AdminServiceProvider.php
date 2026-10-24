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
use Saola\Admin\Pages\PageRegistry;
use Saola\Admin\Http\AdminAccess;
use Saola\Admin\Http\PageController;
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
        $this->app->singleton(PageRegistry::class);
        $this->app->scoped(\Saola\Admin\Users\UserDirectory::class, \Saola\Admin\Users\EloquentUserDirectory::class);
        $this->app->singleton(\Saola\Admin\Settings\SettingsRegistry::class);
        $this->app->scoped(\Saola\Admin\Settings\SettingsStore::class, fn ($app) => new \Saola\Admin\Settings\DatabaseSettingsStore(
            $app['db']->connection(config('saola-admin.settings.connection')),
            config('saola-admin.settings.table', 'saola_settings'),
            config('saola-admin.settings.content_locales', ['en', 'vi']),
            config('saola-admin.settings.default_locale', 'en'),
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
            $registry->register(new \Saola\Admin\Settings\SettingsGroup('general', 'admin.settings.general',
                config('saola-admin.settings.read_ability', 'view-site-settings'), config('saola-admin.settings.write_ability', 'update-site-settings'), [
                    'website_name' => ['labelKey' => 'admin.settings.website_name', 'type' => 'text', 'localized' => true, 'public' => true, 'default' => (string) config('app.name', 'Saola'), 'maxLength' => 200],
                    'description' => ['labelKey' => 'admin.settings.description', 'type' => 'text', 'localized' => true, 'public' => true, 'default' => ''],
                    'contact' => ['labelKey' => 'admin.settings.contact', 'type' => 'text', 'public' => false, 'default' => ''],
                ]));
        });
        $this->app->afterResolving(PageRegistry::class, function (PageRegistry $registry) {
            foreach (config('saola-admin.panel.pages', []) as $class) $registry->register($class);
            if (config('saola-admin.users.enabled', false)) $registry->register(\Saola\Admin\Users\UsersPage::class);
        });
        if (config('saola-admin.panel.enabled', false)) {
            config(['sao.assets.css.admin' => config('saola-admin.panel.css')]);
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

    private function registerPanel(): void
    {
        $manager = $this->app->make(ViewContextManager::class);
        if (!$manager->hasContext('admin')) $manager->registerContext('admin', ['base' => 'admin']);
        $partials = $manager->getBaseDirectory('admin', 'partials') ?? 'admin.partials';
        if (!view()->exists($partials.'.head')) {
            $manager->registerContext('admin', ['base' => $manager->getBaseDirectory('admin', 'base'), 'partials' => 'admin.partials']);
        }
        $prefix = trim((string) config('saola-admin.panel.prefix', 'admin'), '/');
        if ($prefix === '' || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $prefix)) throw new \InvalidArgumentException('Invalid admin prefix.');
        if (!$this->app->routesAreCached()) {
            foreach (Route::getRoutes() as $route) {
                if (array_intersect(['GET', 'PUT'], $route->methods()) && in_array($route->uri(), [$prefix, $prefix.'/{page}', $prefix.'/settings', $prefix.'/settings/{group}'], true)) {
                    throw new \RuntimeException('Admin route collision at '.$route->uri().'; choose another panel prefix before enabling.');
                }
            }
            Route::middleware(['web', AdminAccess::class])->group(function () use ($prefix) {
                Route::match(['GET', 'PUT'], $prefix.'/settings/{group}', \Saola\Admin\Http\SettingsController::class)->where('group', '[a-z][a-z0-9_-]*')->name('saola.admin.settings');
                Route::get($prefix.'/settings', \Saola\Admin\Http\SettingsEditorController::class)->name('saola.admin.settings.editor');
                Route::get($prefix, PageController::class)->name('saola.admin.home');
                Route::get($prefix.'/{page}', PageController::class)->where('page', '[a-z][a-z0-9_-]*')->name('saola.admin.page');
            });
        }
        // Panel routes are added from booted(), after Laravel may have refreshed
        // route lookups already. Refresh names/actions for real HTTP hosts too.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
        $active = SPA::isActive(); SPA::active();
        SPA::addRoute('admin', 'saola.admin.settings.editor', '/'.$prefix.'/settings', ['kind' => 'explicit', 'logical' => '@BASE:pages.settings']);
        foreach (['saola.admin.home' => '/'.$prefix, 'saola.admin.page' => '/'.$prefix.'/{page}'] as $name => $path) {
            SPA::addRoute('admin', $name, $path, ['kind' => 'explicit', 'logical' => '@BASE:pages.resource']);
        }
        if (!$active) SPA::inactive();
    }
}

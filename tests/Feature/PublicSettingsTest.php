<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Admin\Settings\PublicSettings;
use Saola\Core\Engines\ViewContextManager;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Support\SPA;

/** `__site` mặc định: nhóm thiết lập công khai, cache theo phiên bản, làm mới khi admin lưu. */
class PublicSettingsTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('saola-admin.panel.enabled', true);
        $app['config']->set('saola-admin.panel.prefix', 'control');
    }
    protected function setUp(): void
    {
        SPA::resetRoutes(); parent::setUp();
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        app(DatabaseLanguageStore::class)->sync(app(LanguageSources::class)->catalog(), 'en');
        Gate::define('access-admin', fn () => true);
        Gate::define('view-site-settings', fn () => true);
        Gate::define('update-site-settings', fn () => true);
        $this->actingAs(new GenericUser(['id' => 1]));
    }
    protected function tearDown(): void { SPA::resetRoutes(); parent::tearDown(); }

    public function test_site_is_public_groups_only_cached_and_refreshed_on_save(): void
    {
        $views = app(ViewContextManager::class);
        $this->assertTrue($views->hasSiteProvider(), 'Admin đăng ký PublicSettings làm nguồn __site.');
        $settings = app(PublicSettings::class);

        $site = $settings->data('en');
        $this->assertArrayHasKey('website_name', $site['general']);
        $this->assertArrayHasKey('logo_url', $site['branding']);
        $this->assertArrayHasKey('icon_192_url', $site['pwa']);
        // Nhóm không có field public, và field private, không bao giờ lọt ra.
        foreach (['system', 'admin-appearance', 'system-display'] as $private) $this->assertArrayNotHasKey($private, $site);
        $this->assertArrayNotHasKey('contact', $site['general']);

        // Lần đọc sau: không query nào (memo worker + cache).
        DB::enableQueryLog();
        $settings->data('en');
        $views->site();
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        $before = $settings->version();
        $revision = $views->getContextViewRevision('web');
        $this->putJson('/control/settings/general', ['revision' => 0, 'patch' => ['localized' => ['en' => ['website_name' => 'Mới']]]])->assertOk();
        $this->assertNotSame($before, $settings->version());
        $this->assertSame('Mới', $settings->data('en')['general']['website_name']);
        $this->assertSame('Mới', $views->site()['general']['website_name'], 'Cùng request: VCM không giữ bản cũ.');
        $this->assertNotSame($revision, app(ViewContextManager::class)->getContextViewRevision('web'));

        // Locale không bật cho nội dung ⇒ rơi về locale mặc định, không ném lỗi.
        $this->assertSame('Mới', $settings->data('xx')['general']['website_name']);
    }

    public function test_saving_a_private_group_keeps_the_version(): void
    {
        Gate::define('view-system-settings', fn () => true);
        Gate::define('update-system-settings', fn () => true);
        $settings = app(PublicSettings::class);
        $before = $settings->version();
        $this->putJson('/control/settings/system', ['revision' => 0, 'patch' => ['values' => ['maintenance_mode' => true]]])->assertOk();
        $this->assertSame($before, $settings->version());
    }

    public function test_missing_table_falls_back_to_defaults_without_caching(): void
    {
        \Illuminate\Support\Facades\Schema::drop(config('saola-admin.settings.table', 'saola_settings'));
        $settings = new PublicSettings(app('cache.store'));
        $this->assertSame(config('app.name'), $settings->data('en')['general']['website_name']);
        $this->assertFalse(app('cache.store')->has('saola.site.1.en'));
    }

    public function test_refresh_command(): void
    {
        $before = app(PublicSettings::class)->version();
        $this->artisan('saola:site-refresh')->assertSuccessful();
        $this->assertNotSame($before, app(PublicSettings::class)->version());
    }
}

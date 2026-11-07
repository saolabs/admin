<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Admin\Pwa;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Support\SPA;

class PwaTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
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

    public function test_pwa_is_off_until_enabled_then_serves_manifest_from_settings(): void
    {
        $this->get('/manifest.webmanifest')->assertNotFound();
        $this->get('/sw.js')->assertNotFound();
        $this->assertSame('', Pwa::headTags());

        $this->putJson('/control/settings/general', ['revision' => 0, 'patch' => ['localized' => ['en' => ['website_name' => 'Site', 'short_name' => 'S']]]])->assertOk();
        $this->putJson('/control/settings/branding', ['revision' => 0, 'patch' => ['values' => ['favicon_url' => '/fav.png']]])->assertOk();
        $this->putJson('/control/settings/pwa', ['revision' => 0, 'patch' => [
            'values' => ['enabled' => true, 'theme_color' => '#112233', 'background_color' => 'bad', 'display' => 'minimal-ui', 'start_url' => '/app', 'icon_512_url' => '/i512.png'],
            'localized' => ['en' => ['name' => 'My App']],
        ]])->assertOk();

        $m = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();
        $this->assertSame('My App', $m['name']);
        $this->assertSame('S', $m['short_name']);
        $this->assertSame('/app', $m['start_url']);
        $this->assertSame('minimal-ui', $m['display']);
        $this->assertSame('#112233', $m['theme_color']);
        $this->assertSame('#ffffff', $m['background_color']);
        $this->assertSame(['/fav.png', '/i512.png'], array_column($m['icons'], 'src'));
        $this->get('/sw.js')->assertOk()->assertSee('skipWaiting', false);
        $this->assertStringContainsString('rel="manifest"', Pwa::headTags());
    }
}

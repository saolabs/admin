<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Route};
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Http\HeaderAccount;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Support\SPA;

class HeaderAccountTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('h', 32)));
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
    }
    protected function tearDown(): void { SPA::resetRoutes(); parent::tearDown(); }

    public function test_shell_projects_only_the_current_account_allowlist_and_named_language_choices(): void
    {
        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Đoàn Lê', 'email' => 'operator@example.test', 'password' => 'never-send-password', 'api_token' => 'never-send-token', 'role' => 'Unconfigured role']));
        $response = $this->getJson('/control?locale=vi')->assertOk()->assertJsonPath('data.account.name', 'Đoàn Lê')
            ->assertJsonPath('data.account.initials', 'ĐL')->assertJsonPath('data.account.email', 'operator@example.test')
            ->assertJsonPath('data.account.subtitle', '')->assertJsonPath('data.account.logoutUrl', '')
            ->assertJsonPath('data.uiLocale', 'vi')->assertJsonPath('data.locales.0.name', 'English')->assertJsonPath('data.locales.1.name', 'Tiếng Việt');
        $this->assertStringNotContainsString('never-send', $response->getContent());
        $this->assertStringNotContainsString('Unconfigured role', $response->getContent());
        $this->actingAs(new GenericUser(['id' => 2, 'name' => 'Other Operator']));
        $this->getJson('/control')->assertOk()->assertJsonPath('data.account.name', 'Other Operator')->assertJsonPath('data.account.email', '');
    }

    public function test_only_configured_valid_host_routes_and_write_logout_are_rendered(): void
    {
        Route::get('/my-profile', fn () => 'profile')->name('host.profile');
        Route::get('/account-settings', fn () => 'settings')->name('host.settings');
        Route::post('/session/logout', fn () => 'logout')->name('host.logout');
        config(['saola-admin.account.profile_route' => 'host.profile', 'saola-admin.account.settings_route' => 'host.settings', 'saola-admin.account.logout_route' => 'host.logout']);
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->getJson('/control')->assertOk()->assertJsonPath('data.account.links.0.url', '/my-profile')
            ->assertJsonPath('data.account.links.1.url', '/account-settings')->assertJsonPath('data.account.logoutUrl', '/session/logout')
            ->assertJsonPath('data.account.logoutMethod', 'POST');
        Route::get('/unsafe-logout', fn () => 'do not use')->name('host.get-logout');
        Route::getRoutes()->refreshNameLookups();
        config(['saola-admin.account.logout_route' => 'host.get-logout', 'saola-admin.account.profile_route' => 'missing.route']);
        $this->getJson('/control')->assertOk()->assertJsonPath('data.account.logoutUrl', '')->assertJsonCount(1, 'data.account.links');
        Route::delete('/session/delete', fn () => 'logout')->name('host.delete-logout');
        Route::getRoutes()->refreshNameLookups();
        config(['saola-admin.account.logout_route' => 'host.delete-logout']);
        $this->getJson('/control')->assertOk()->assertJsonPath('data.account.logoutMethod', 'DELETE')->assertJsonPath('data.account.logoutUrl', '/session/delete');
    }

    public function test_avatar_mapping_is_explicit_and_script_or_network_relative_urls_are_rejected(): void
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => new GenericUser(['name' => '<script>name</script>', 'photo' => 'javascript:alert(1)', 'position' => 'Editor']));
        config(['saola-admin.account.avatar_attribute' => 'photo', 'saola-admin.account.subtitle_attribute' => 'position']);
        $account = app(HeaderAccount::class)->project($request);
        $this->assertSame('', $account['avatar']); $this->assertSame('Editor', $account['subtitle']);
        foreach (['//outside.example/avatar.png', "https://example.test/space here.png", 'data:image/svg+xml,bad'] as $avatar) {
            $request->setUserResolver(fn () => new GenericUser(['photo' => $avatar]));
            $this->assertSame('', app(HeaderAccount::class)->project($request)['avatar']);
        }
        $request->setUserResolver(fn () => new GenericUser(['photo' => '/saola/media/avatar', 'name' => 'Operator']));
        $this->assertSame('/saola/media/avatar', app(HeaderAccount::class)->project($request)['avatar']);
    }
}

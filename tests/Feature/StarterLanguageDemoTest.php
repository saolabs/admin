<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Core\Engines\ViewContextManager;
use Saola\Core\Language\ClientCatalogs;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Providers\SaolaServiceProvider;

/** Host integration fixture, independent of sibling application checkouts. */
class StarterLanguageDemoTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('sao.i18n.demo_enabled', true);
        $app['config']->set('sao.i18n.client_scopes.language-demo', ['keys' => ['save', 'auth.keep_login', 'language_demo'], 'public' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new SaolaServiceProvider($this->app))->register();
        $this->app->make(ViewContextManager::class)->registerContext('web', ['base' => 'web']);
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        $sources = $this->app->make(LanguageSources::class);
        $sources->register('starter', __DIR__.'/../Fixtures/host/lang', ['saola']);
        $this->app->make(DatabaseLanguageStore::class)->sync($sources->catalog(), 'en');
        Route::get('/language-demo', [\Saola\Admin\Tests\Fixtures\LanguageDemoController::class, 'languageDemo'])->name('web.home.language-demo');
    }

    public function test_starter_page_data_and_dictionary_use_the_same_published_scope_and_locale(): void
    {
        $response = $this->getJson('/language-demo?locale=vi')->assertOk();
        $response->assertJsonPath('i18n.locale', 'vi')->assertJsonPath('i18n.catalogs.vi.messages.save', 'Lưu')
            ->assertJsonMissingPath('i18n.catalogs.vi.messages.admin');
        $dictionary = $this->getJson('/saola/languages/language-demo/vi')->assertOk();
        $this->assertSame($response->json('i18n.catalogs.vi'), $dictionary->json());
        $this->assertStringEndsWith('/saola/languages/language-demo/', $response->json('data.dictionaryBase'));
        $this->app->setLocale('vi');
        $this->assertSame('Lưu', text('save'));
        $this->assertSame('Bản dịch từ cơ sở dữ liệu', text('language_demo.title'));
        $this->assertSame('save', __('save'));
    }

    public function test_prepared_ssr_config_matches_text_and_accept_language_works_for_spa(): void
    {
        $response = $this->getJson('/language-demo', ['Accept-Language' => 'vi'])->assertOk();
        $this->assertSame('vi', $response->json('i18n.locale'));
        $prepared = $this->app->make(ClientCatalogs::class)->clientConfig('language-demo', 'vi');
        $this->assertSame($response->json('i18n'), json_decode(json_encode($prepared), true));
        $this->getJson('/language-demo?locale=../../private')->assertStatus(422);
        config(['sao.i18n.demo_enabled' => false]);
        $this->getJson('/language-demo')->assertNotFound();
    }
}

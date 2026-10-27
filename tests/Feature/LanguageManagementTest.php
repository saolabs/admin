<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\{Gate, Route};
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\{DatabaseLanguageStore, LocaleManager};
use Saola\Admin\Settings\{SettingsRegistry, SettingsStore};
use Saola\Core\Language\{LanguageSources, TextResolver};
use Saola\Core\Support\SPA;

class LanguageManagementTest extends TestCase
{
    private string $api = '/control/_api/languages';
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('l', 32)));
        $app['config']->set('app.locale', 'en'); $app['config']->set('app.fallback_locale', 'en');
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
        Gate::define('access-admin', fn ($user) => in_array($user->id, [1, 3], true));
        foreach (['view', 'manage', 'export', 'import', 'publish'] as $operation) Gate::define(config('saola-admin.language.abilities.'.$operation), fn ($user) => $user->id === 1);
        $this->actingAs(new GenericUser(['id' => 1]));
    }
    protected function tearDown(): void { SPA::resetRoutes(); parent::tearDown(); }
    private function configuration(array $patch = []): array
    {
        $state = app(LocaleManager::class)->state();
        $state['locales'] = array_map(fn ($row) => array_intersect_key($row, array_flip(['code', 'name', 'uiEnabled', 'contentEnabled'])), $state['locales']);
        return array_replace($state, $patch);
    }
    private function addJapanese(): void
    {
        $body = $this->configuration();
        $body['locales'][] = ['code' => 'ja', 'name' => '日本語', 'uiEnabled' => true, 'contentEnabled' => true];
        $this->putJson($this->api, $body)->assertOk()->assertJsonPath('editor.document.revision', 1);
    }

    public function test_locale_country_and_currency_are_stored_and_validated(): void
    {
        $body = $this->configuration();
        $body['locales'][0] += ['country' => 'VN', 'currency' => 'VND'];
        $code = $body['locales'][0]['code'];
        $this->putJson($this->api, $body)->assertOk()->assertJsonPath('editor.document.locales.0.country', 'VN')->assertJsonPath('editor.document.locales.0.currency', 'VND');
        $body['revision'] = 1; $body['locales'][0]['currency'] = 'vnd';
        $this->putJson($this->api, $body)->assertUnprocessable();
        $this->assertSame('VN', collect(app(LocaleManager::class)->state()['locales'])->firstWhere('code', $code)['country']);
    }

    public function test_permissions_apply_to_menu_and_each_http_operation(): void
    {
        $this->actingAs(new GenericUser(['id' => 3]));
        $this->getJson('/control/languages')->assertForbidden();
        $this->getJson($this->api)->assertForbidden();
        Gate::define('view-admin-languages', fn () => true);
        $this->getJson($this->api)->assertOk()->assertJsonPath('editor.permissions.manage', false);
        $this->putJson($this->api, $this->configuration())->assertForbidden();
        $this->postJson($this->api.'/en/export', [])->assertForbidden();
        $this->postJson($this->api.'/en/preview', [])->assertForbidden();
        $this->getJson('/control')->assertOk()->assertJsonPath('data.navigation.1.key', 'languages');
        $this->assertSame(0, app('db')->table('saola_language_settings')->count());
    }

    public function test_new_locale_and_db_defaults_apply_to_ui_session_and_content_independently(): void
    {
        $this->addJapanese();
        $body = $this->configuration(['uiDefault' => 'vi', 'uiFallback' => 'en', 'contentDefault' => 'ja']);
        $this->putJson($this->api, $body)->assertOk()->assertJsonPath('editor.document.revision', 2);
        $this->getJson('/control', ['Accept-Language' => 'en'])->assertOk()->assertJsonPath('i18n.locale', 'vi');
        $this->getJson('/control?locale=en')->assertOk()->assertJsonPath('i18n.locale', 'en');
        $this->getJson('/control')->assertOk()->assertJsonPath('i18n.locale', 'en');
        $this->assertSame('en', app()->getLocale());
        $group = app(SettingsRegistry::class)->get('general'); $settings = app(SettingsStore::class);
        $settings->update($group, 0, ['localized' => ['ja' => ['website_name' => '日本のサイト'], 'vi' => ['website_name' => 'Tên Việt']]]);
        $this->assertSame('ja', $settings->read($group)['defaultLocale']);
        $this->assertSame('日本のサイト', $settings->values($group, 'en')['website_name']);
        $this->assertSame('Tên Việt', $settings->values($group, 'vi')['website_name']);
        $this->getJson('/control?locale=ja')->assertNotFound();
        $this->putJson($this->api, $this->configuration(['uiDefault' => 'ja']))->assertUnprocessable();
        $this->assertSame(2, app(LocaleManager::class)->state()['revision']);
        // Optional website middleware uses the same persisted policy and restores worker locale.
        Route::middleware(['web', 'saola.locale'])->get('/locale-check', fn () => response()->json(['locale' => app()->getLocale()]));
        $this->getJson('/locale-check?locale=vi')->assertOk()->assertJsonPath('locale', 'vi');
        $this->assertSame('en', app()->getLocale());
    }

    public function test_invalid_or_stale_configuration_rolls_back_and_cannot_disable_default_languages(): void
    {
        $base = $this->configuration(); $invalid = $base;
        $invalid['locales'][0]['uiEnabled'] = false;
        $this->putJson($this->api, $invalid)->assertUnprocessable();
        $this->assertSame(0, app('db')->table('saola_language_settings')->count());
        $invalid = $base; $invalid['locales'][] = ['code' => 'EN', 'name' => 'Duplicate', 'uiEnabled' => true, 'contentEnabled' => false];
        $this->putJson($this->api, $invalid)->assertUnprocessable();
        $invalid = $base; $invalid['locales'][0]['contentEnabled'] = 'false';
        $this->putJson($this->api, $invalid)->assertUnprocessable();
        $this->putJson($this->api, $base)->assertOk();
        $this->putJson($this->api, $base)->assertConflict();
        $this->assertSame(1, app(LocaleManager::class)->state()['revision']);
        $base = $this->configuration();
        foreach ($base['locales'] as &$row) if ($row['code'] === 'vi') { $row['uiEnabled'] = false; $row['contentEnabled'] = false; }
        unset($row);
        $this->putJson($this->api, $base)->assertOk();
        $this->getJson('/saola/languages/admin/vi')->assertNotFound();
        $this->assertSame('Người dùng', app(DatabaseLanguageStore::class)->snapshot('vi')['messages']['admin']['users']['title']);
    }

    public function test_export_preview_draft_publish_is_actor_bound_and_does_not_build_or_seed_files(): void
    {
        $this->addJapanese();
        $export = $this->postJson($this->api.'/ja/export', [])->assertOk()->json('export');
        $this->assertArrayHasKey('admin', $export['tree']); $this->assertArrayHasKey('save', $export['tree']['admin']['common']);
        $json = '{"admin":{"users":{"title":"ユーザー"}}}';
        $preview = $this->postJson($this->api.'/ja/preview', ['json' => $json, 'exportBatch' => $export['batch']])->assertOk()->json('preview');
        $this->assertSame('preview', $preview['status']);
        $this->assertSame(0, app(DatabaseLanguageStore::class)->snapshot('ja')['revision']);
        $path = $this->api.'/batches/'.$preview['batch'];
        Gate::define('publish-admin-languages', fn () => false);
        $this->postJson($path, ['publish' => true])->assertForbidden();
        $this->postJson($path, ['publish' => false])->assertOk()->assertJsonPath('preview.status', 'draft');
        $this->actingAs(new GenericUser(['id' => 3]));
        foreach (['view', 'import'] as $permission) Gate::define(config('saola-admin.language.abilities.'.$permission), fn () => true);
        $this->getJson($path)->assertNotFound();
        $this->postJson($path, ['publish' => false])->assertNotFound();
        $this->actingAs(new GenericUser(['id' => 1])); Gate::define('publish-admin-languages', fn () => true);
        $this->postJson($path, ['publish' => true])->assertOk()->assertJsonPath('preview.status', 'published');
        $this->postJson($path, ['publish' => true])->assertOk()->assertJsonPath('preview.result_revision', 1);
        $this->getJson('/saola/languages/admin/ja')->assertOk()->assertJsonPath('messages.admin.users.title', 'ユーザー');
        $this->putJson($this->api, $this->configuration(['uiDefault' => 'ja']))->assertOk();
        app(TextResolver::class)->forget();
        $this->getJson('/control?locale=ja')->assertOk()->assertJsonPath('i18n.locale', 'ja')->assertJsonPath('i18n.fallbackLocale', 'en');
    }

    public function test_json_errors_expiry_and_concurrent_translation_changes_preserve_runtime_values(): void
    {
        $this->addJapanese();
        $export = $this->postJson($this->api.'/ja/export', [])->assertOk()->json('export');
        foreach (['{"unknown":"value"}', '{"admin":{"users":{"title":"A","title":"B"}}}', '{"admin":{"users":{"title":3}}}'] as $json) {
            $this->postJson($this->api.'/ja/preview', ['json' => $json, 'exportBatch' => $export['batch']])->assertUnprocessable();
        }
        $this->postJson($this->api.'/ja/preview', ['json' => '{}', 'exportBatch' => $export['batch'], 'actor' => 'console'])->assertUnprocessable();
        $batch = $this->postJson($this->api.'/ja/preview', ['json' => '{"admin":{"users":{"title":"日本語"}}}', 'exportBatch' => $export['batch']])->assertOk()->json('preview.batch');
        app('db')->table('saola_language_batches')->where('id', $batch)->update(['expires_at' => time() - 1]);
        $this->postJson($this->api.'/batches/'.$batch, ['publish' => true])->assertStatus(410);
        $this->assertSame(0, app(DatabaseLanguageStore::class)->snapshot('ja')['revision']);
        app(DatabaseLanguageStore::class)->publish('ja', ['admin' => ['users' => ['title' => 'Manual']]], 0);
        $this->postJson($this->api.'/ja/preview', ['json' => '{"admin":{"users":{"title":"日本語"}}}', 'exportBatch' => $export['batch']])->assertConflict();
        $this->assertSame('Manual', app(DatabaseLanguageStore::class)->snapshot('ja')['messages']['admin']['users']['title']);
    }

    public function test_db_fallback_and_spa_language_choice_are_consistent_with_ssr_and_do_not_rewrite_laravel_helpers(): void
    {
        $this->addJapanese();
        app(DatabaseLanguageStore::class)->publish('ja', ['admin' => ['users' => ['title' => 'ユーザー']]], 0);
        $this->putJson($this->api, $this->configuration(['uiDefault' => 'ja', 'uiFallback' => 'vi']))->assertOk();
        app(TextResolver::class)->forget();
        $this->getJson('/control')->assertOk()->assertJsonPath('i18n.locale', 'ja')->assertJsonPath('i18n.fallbackLocale', 'vi')
            ->assertJsonPath('i18n.catalogs.vi.messages.admin.workspace.title', 'Tổng quan');
        $this->assertSame('Tổng quan', text('admin.workspace.title', [], null, 'ja'));
        $this->assertSame('admin.workspace.title', __('admin.workspace.title', [], 'ja'));
        app('db')->connection()->enableQueryLog(); app('db')->connection()->flushQueryLog();
        foreach (range(1, 20) as $_) text('admin.workspace.title', [], null, 'ja');
        $this->assertCount(0, app('db')->connection()->getQueryLog(), 'Repeated fallback labels must use request-scoped snapshots/policy.');
        app('db')->connection()->disableQueryLog();
        $this->getJson('/control?locale=ja', ['X-Sao-Response' => 'json', 'Accept-Language' => 'en'])->assertOk()->assertJsonPath('i18n.locale', 'en');
        $this->getJson('/control')->assertOk()->assertJsonPath('i18n.locale', 'en');
    }

    public function test_additive_migration_preserves_existing_values_and_can_roll_back_only_management_schema(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_10_24_000004_add_locale_management.php';
        $before = app(DatabaseLanguageStore::class)->snapshot('vi');
        $migration->down();
        $this->assertFalse(app('db')->getSchemaBuilder()->hasColumn('saola_locales', 'name'));
        $this->assertSame($before, app(DatabaseLanguageStore::class)->snapshot('vi'));
        $migration->up();
        $this->assertSame(0, app(LocaleManager::class)->state()['revision']);
        $this->assertSame($before, app(DatabaseLanguageStore::class)->snapshot('vi'));
    }
}

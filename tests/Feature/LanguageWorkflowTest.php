<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Admin\Language\LanguageExchange;
use Saola\Core\Language\ClientCatalogs;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Language\TextResolver;

class LanguageWorkflowTest extends TestCase
{
    private DatabaseLanguageStore $store;
    private LanguageExchange $exchange;
    private array $catalog;

    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.fallback_locale', 'en');
        $app['config']->set('sao.i18n.client_scopes', [
            'web' => ['keys' => ['save', 'welcome'], 'public' => true],
            'admin' => ['keys' => ['admin'], 'ability' => 'read-language-admin'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        $this->store = $this->app->make(DatabaseLanguageStore::class);
        $this->exchange = $this->app->make(LanguageExchange::class);
        $this->catalog = $this->app->make(LanguageSources::class)->catalog();
        foreach (['save' => 'Save', 'welcome' => 'Hello :name'] as $path => $value) {
            $this->catalog['en'][$path] = ['value' => $value, 'owner' => 'test/app', 'file' => 'common'];
        }
        $this->store->sync($this->catalog, 'en');
    }

    public function test_export_preview_draft_publish_and_retry_preserve_missing_values(): void
    {
        $export = $this->exchange->export('ja', 'user:1');
        $this->assertSame('Save', $export['tree']->save);
        $file = (array) $export['tree']; $file['save'] = '保存';
        $preview = $this->exchange->preview('ja', json_encode($file), 'user:1', $export['batch']);
        $this->assertSame(['save' => '保存'], $preview['changes']);
        $this->assertContains('welcome', $preview['untranslated']);
        $repeat = $this->exchange->preview('ja', json_encode($file), 'user:1', $export['batch']);
        $this->assertSame($preview['batch'], $repeat['batch']);
        $this->assertSame('draft', $this->exchange->apply($preview['batch'], 'user:1')['status']);
        $this->assertSame([], $this->store->snapshot('ja')['messages']);
        $result = $this->exchange->apply($preview['batch'], 'user:1', true);
        $this->assertSame('published', $result['status']);
        $this->assertSame(['save' => '保存'], $this->store->snapshot('ja')['messages']);
        $this->assertSame($result, $this->exchange->apply($preview['batch'], 'user:1', true));
        $this->assertSame($result, $this->exchange->preview('ja', json_encode($file), 'user:1', $export['batch']));
        $this->assertSame(1, $this->store->snapshot('ja')['revision']);
    }

    public function test_same_as_source_requires_explicit_confirmation_only_for_missing_values(): void
    {
        $preview = $this->exchange->preview('ja', '{"save":"Save"}', 'user:1');
        $this->assertSame([], $preview['changes']);
        $this->assertSame(['save'], $preview['untranslated']);
        $confirmed = $this->exchange->preview('ja', '{"save":"Save"}', 'user:1', null, ['save']);
        $this->assertSame(['save' => 'Save'], $confirmed['changes']);
        $this->exchange->apply($confirmed['batch'], 'user:1', true);
        $this->assertSame('Save', $this->store->snapshot('ja')['messages']['save']);
    }

    public function test_source_and_target_changes_conflict_and_keep_existing_translations(): void
    {
        $preview = $this->exchange->preview('ja', '{"save":"保存"}', 'user:1');
        $this->store->publish('ja', ['save' => 'Hand edited'], 0);
        try { $this->exchange->apply($preview['batch'], 'user:1', true); $this->fail('Revision conflict expected'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('conflict', $error->getMessage()); }
        $this->assertSame('Hand edited', $this->store->snapshot('ja')['messages']['save']);
        $new = $this->exchange->preview('ja', '{"save":"新しい"}', 'user:1');
        $this->catalog['en']['save']['value'] = 'Save changes';
        $this->store->sync($this->catalog, 'en');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflict');
        $this->exchange->apply($new['batch'], 'user:1', true);
    }

    public function test_foreign_actor_expired_batch_unknown_key_and_placeholder_fail_before_writes(): void
    {
        foreach (['{"new":"x"}', '{"welcome":"Lost parameter"}', '{"save":"a","save":"b"}'] as $json) {
            try { $this->exchange->preview('ja', $json, 'user:1'); $this->fail('Invalid import must fail'); }
            catch (\InvalidArgumentException) { $this->assertSame([], $this->store->snapshot('ja')['messages']); }
        }
        $preview = $this->exchange->preview('ja', '{"save":"保存"}', 'user:1');
        try { $this->exchange->apply($preview['batch'], 'user:2', true); $this->fail('Actor mismatch must fail'); }
        catch (\InvalidArgumentException) { $this->assertSame([], $this->store->snapshot('ja')['messages']); }
        $this->app['db']->table('saola_language_batches')->where('id', $preview['batch'])->update(['expires_at' => 0]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');
        $this->exchange->apply($preview['batch'], 'user:1', true);
    }

    public function test_dictionary_and_ssr_projection_do_not_expose_private_keys_and_etags_revalidate(): void
    {
        $config = $this->app->make(ClientCatalogs::class)->clientConfig('web', 'en');
        $this->assertObjectNotHasProperty('admin', $config['catalogs']['en']['messages']);
        $this->assertSame('Save', $config['catalogs']['en']['messages']->save);
        $response = $this->getJson('/saola/languages/web/en')->assertOk()->assertJsonPath('messages.save', 'Save')->assertJsonMissingPath('messages.admin');
        $this->getJson('/saola/languages/web/en', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
        $this->getJson('/saola/languages/admin/en')->assertForbidden();
        $this->getJson('/saola/languages/unknown/en')->assertNotFound();
        $this->getJson('/saola/languages/web/ja')->assertNotFound();
        Gate::define('read-language-admin', fn ($user) => $user->getAuthIdentifier() === 1);
        $this->actingAs(new GenericUser(['id' => 1]));
        $private = $this->getJson('/saola/languages/admin/en')->assertOk()->assertJsonPath('messages.admin.common.save', 'Save');
        $this->actingAs(new GenericUser(['id' => 2]));
        $this->getJson('/saola/languages/admin/en', ['If-None-Match' => $private->headers->get('ETag')])->assertForbidden();
    }

    public function test_cli_import_only_previews_until_apply_and_publish_are_requested(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'saola-import-');
        try {
            file_put_contents($file, '{"save":"保存"}');
            $this->artisan('admin:lang:import', ['locale' => 'ja', 'file' => $file])->assertSuccessful();
            $id = $this->app['db']->table('saola_language_batches')->where('kind', 'import')->value('id');
            $this->assertSame([], $this->store->snapshot('ja')['messages']);
            $this->artisan('admin:lang:apply', ['batch' => $id])->assertSuccessful();
            $this->assertSame([], $this->store->snapshot('ja')['messages']);
            $this->artisan('admin:lang:apply', ['batch' => $id, '--publish' => true])->assertSuccessful();
            $this->assertSame('保存', $this->store->snapshot('ja')['messages']['save']);
        } finally { unlink($file); }
    }
}

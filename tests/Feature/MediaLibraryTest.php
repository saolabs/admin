<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Gate, Storage};
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Admin\Media\MediaScope;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Support\SPA;

class MediaLibraryTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3e0AAAAASUVORK5CYII=';
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('saola-admin.panel.enabled', true);
        $app['config']->set('saola-admin.panel.prefix', 'control');
    }
    protected function setUp(): void
    {
        SPA::resetRoutes(); parent::setUp();
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        app(DatabaseLanguageStore::class)->sync(app(LanguageSources::class)->catalog(), 'en');
        Storage::fake('local');
        Gate::define('access-admin', fn () => true);
        foreach (['view', 'upload', 'update', 'delete'] as $ability) Gate::define($ability.'-media', fn ($user) => $user->id === 1);
        $this->actingAs(new GenericUser(['id' => 1]));
    }
    protected function tearDown(): void { SPA::resetRoutes(); parent::tearDown(); }
    private function png(string $name = 'logo.png'): UploadedFile { return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG)); }
    private function upload(string $name = 'logo.png'): array { return $this->post('/control/_api/media', ['file' => $this->png($name)], ['Accept' => 'application/json'])->assertCreated()->json('item'); }

    public function test_upload_has_stable_identity_detected_mime_private_storage_and_public_content(): void
    {
        $item = $this->upload('../logo.png');
        $this->assertSame('image/png', $item['mime']); $this->assertSame('image', $item['kind']);
        $this->assertSame(1, $item['width']); $this->assertSame(1, $item['height']);
        $this->assertArrayNotHasKey('path', $item); $this->assertArrayNotHasKey('disk', $item);
        $row = app('db')->table('saola_media')->first();
        Storage::disk('local')->assertExists($row->path);
        $this->assertStringStartsWith('saola/admin/media/', $row->path);
        $this->assertStringContainsString($item['id'].'.png', $row->path);
        $this->assertSame('private', Storage::disk('local')->getVisibility($row->path));
        $this->app['auth']->forgetGuards();
        $this->get($item['url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertStreamedContent(base64_decode(self::PNG));
        $this->getJson('/control/_api/media')->assertUnauthorized();
    }

    public function test_library_is_paginated_searchable_literal_and_filtered_with_safe_projections(): void
    {
        $one = $this->upload('100%_logo.png'); $two = $this->upload('other.png');
        $this->getJson('/control/_api/media?q=&kind=all&status=active&page=1')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/control/_api/media?q='.urlencode('%_'))->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $one['id']);
        $this->getJson('/control/_api/media?kind=document')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/control/_api/media?page=2')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.total', 2);
        $this->getJson('/control/_api/media?kind=bad')->assertUnprocessable();
        $this->getJson('/control/_api/media?page=-1')->assertUnprocessable();
        $this->getJson('/control/media?locale=vi')->assertOk()->assertJsonPath('view', 'admin.classic.pages.media')
            ->assertJsonPath('data.currentPage', 'media')->assertJsonPath('data.mediaEditor.initial.total', 2);
    }

    public function test_metadata_cas_trash_restore_keep_identity_bytes_and_existing_links(): void
    {
        $item = $this->upload(); $url = '/control/_api/media/'.$item['id'];
        $this->putJson($url, ['revision' => 1, 'changes' => ['title' => 'Logo mới', 'alt' => '', 'caption' => "Dòng 1\nDòng 2"]])->assertOk()
            ->assertJsonPath('item.revision', 2)->assertJsonPath('item.url', $item['url'])->assertJsonPath('item.alt', '');
        $this->putJson($url, ['revision' => 1, 'changes' => ['title' => 'Stale']])->assertConflict();
        $this->putJson($url, ['revision' => 2, 'changes' => ['path' => '../outside']])->assertUnprocessable();
        $this->postJson($url.'/trash', ['revision' => 2])->assertOk()->assertJsonPath('item.trashed', true)->assertJsonPath('item.revision', 3);
        $this->getJson('/control/_api/media')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/control/_api/media?status=trash')->assertOk()->assertJsonPath('data.items.0.id', $item['id']);
        $this->get($item['url'])->assertOk();
        $this->putJson($url, ['revision' => 3, 'changes' => ['title' => 'bad']])->assertConflict();
        $this->postJson($url.'/restore', ['revision' => 2])->assertConflict();
        $this->postJson($url.'/restore', ['revision' => 3])->assertOk()->assertJsonPath('item.revision', 4)->assertJsonPath('item.trashed', false);
        $this->getJson('/control/_api/media')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_permissions_are_independent_and_revoked_writes_do_not_change_files(): void
    {
        $item = $this->upload(); $url = '/control/_api/media/'.$item['id'];
        $this->actingAs(new GenericUser(['id' => 2]));
        $this->getJson('/control/_api/media')->assertForbidden();
        Gate::define('view-media', fn () => true);
        $this->getJson('/control/_api/media')->assertOk();
        $this->post('/control/_api/media', ['file' => $this->png()], ['Accept' => 'application/json'])->assertForbidden();
        $this->putJson($url, ['revision' => 1, 'changes' => ['title' => 'bad']])->assertForbidden();
        $this->postJson($url.'/trash', ['revision' => 1])->assertForbidden();
        $this->getJson($url)->assertOk()->assertJsonPath('item.revision', 1);
    }

    public function test_scope_is_applied_to_list_read_upload_update_trash_restore_and_picker_metadata(): void
    {
        app()->bind(MediaScope::class, fn () => new class implements MediaScope {
            public function key(Request $request): string { return 'tenant:'.$request->user()->tenant_id; }
        });
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7])); $item = $this->upload();
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 8]));
        $this->getJson('/control/_api/media')->assertOk()->assertJsonPath('data.total', 0);
        $url = '/control/_api/media/'.$item['id'];
        $this->getJson($url)->assertNotFound();
        $this->putJson($url, ['revision' => 1, 'changes' => ['title' => 'bad']])->assertNotFound();
        $this->postJson($url.'/trash', ['revision' => 1])->assertNotFound();
        $this->postJson($url.'/restore', ['revision' => 1])->assertNotFound();
        $this->upload('tenant-eight.png');
        $this->getJson('/control/_api/media')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.filename', 'tenant-eight.png');
    }

    public function test_invalid_files_and_payloads_never_create_database_rows(): void
    {
        foreach (['evil.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'evil.php' => '<?php echo 1;', 'fake.png' => 'not an image'] as $name => $contents) {
            $this->post('/control/_api/media', ['file' => UploadedFile::fake()->createWithContent($name, $contents)], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->post('/control/_api/media', ['file' => $this->png('image.php')], ['Accept' => 'application/json'])->assertUnprocessable();
        config(['saola-admin.media.max_bytes' => 10]);
        $this->post('/control/_api/media', ['file' => $this->png()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, app('db')->table('saola_media')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('saola/admin/media'));
    }

    public function test_settings_image_fields_use_picker_metadata_and_keep_existing_url_contract(): void
    {
        Gate::define('view-site-settings', fn () => true); Gate::define('update-site-settings', fn () => true);
        $item = $this->upload();
        $this->getJson('/control/settings?group=branding')->assertOk()->assertJsonPath('data.editor.fields.0.media', true)
            ->assertJsonPath('data.editor.mediaEditor.permissions.upload', true);
        $this->putJson('/control/settings/branding', ['revision' => 0, 'patch' => ['values' => ['logo_url' => $item['url']]]])->assertOk();
        $this->getJson('/control/settings?group=branding')->assertOk()->assertJsonPath('data.editor.fields.0.value', $item['url']);
        Gate::define('view-media', fn () => false);
        $this->getJson('/control/settings?group=branding')->assertOk()->assertJsonPath('data.editor.mediaEditor', null);
        config(['saola-admin.media.enabled' => false]);
        $this->getJson('/control/_api/media')->assertNotFound();
    }
}

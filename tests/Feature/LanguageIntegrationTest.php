<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Language\TextResolver;

class LanguageIntegrationTest extends TestCase
{
    private string $root;

    protected function getPackageProviders($app): array
    {
        return [AdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.fallback_locale', 'en');
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        $this->root = sys_get_temp_dir().'/saola-admin-lang-'.bin2hex(random_bytes(6));
        foreach (['en', 'vi'] as $locale) {
            mkdir($this->root.'/'.$locale, 0777, true);
        }
        $this->write('en', ['save' => 'Save', 'welcome' => 'Hello :name']);
        $this->write('vi', ['save' => 'Lưu', 'welcome' => 'Chào :name']);
        $this->app->make(LanguageSources::class)->register('test/app', $this->root, ['common']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function write(string $locale, array $tree): void
    {
        file_put_contents($this->root.'/'.$locale.'/common.php', '<?php return '.var_export($tree, true).';');
    }

    private function sync(bool $dryRun = false): array
    {
        return $this->app->make(DatabaseLanguageStore::class)->sync($this->app->make(LanguageSources::class)->catalog(), 'en', $dryRun);
    }

    public function test_sync_project_and_package_then_render_from_db_without_touching_laravel(): void
    {
        $catalog = $this->app->make(LanguageSources::class)->catalog();
        $this->assertSame(['keys' => count($catalog['en']), 'values' => array_sum(array_map('count', $catalog))], $this->sync(true));
        $this->assertSame(0, $this->app['db']->table('saola_translation_keys')->count());
        $this->sync();
        $this->assertSame(['keys' => 0, 'values' => 0], $this->sync());
        $this->app->setLocale('vi');
        $this->assertSame('Lưu / Duy trì đăng nhập', Blade::render("{{ text('save') }} / {{ text('admin.auth.keep_login') }}"));
        $this->assertSame('Chào Lan', text('welcome', ['name' => 'Lan']));
        $this->assertSame('save', __('save'));
        $config = $this->app->make(TextResolver::class)->clientConfig();
        $this->assertSame('Lưu', $config['catalogs']['vi']['messages']->save);
        $this->assertArrayHasKey('admin', (array) $config['catalogs']['vi']['messages']);
        $this->assertSame(['vi', 'en'], array_keys($config['catalogs']));
    }

    public function test_seed_upgrade_preserves_human_values_and_marks_changed_source_for_review(): void
    {
        $this->sync();
        $store = $this->app->make(DatabaseLanguageStore::class);
        $store->publish('vi', ['save' => 'Lưu thay đổi'], 1);
        $this->write('en', ['save' => 'Save changes', 'welcome' => 'Hello :name']);
        $this->write('vi', ['save' => 'Lưu mới từ package', 'welcome' => 'Chào :name']);
        $this->sync();
        $this->assertSame('Lưu thay đổi', $store->snapshot('vi')['messages']['save']);
        $this->assertSame('Save changes', $store->snapshot('en')['messages']['save']);
        $key = $this->app['db']->table('saola_translation_keys')->where('path', 'save')->value('id');
        $this->assertTrue((bool) $this->app['db']->table('saola_translation_values')->where('key_id', $key)->where('locale', 'vi')->value('needs_review'));
    }

    public function test_new_locale_empty_translation_fallback_and_revision_conflict(): void
    {
        $this->sync();
        $store = $this->app->make(DatabaseLanguageStore::class);
        $this->assertSame(1, $store->publish('ja', ['save' => '', 'welcome' => ':nameさん'], 0));
        $this->app->setLocale('ja');
        $this->assertSame('', text('save'));
        $this->assertSame('Lanさん', text('welcome', ['name' => 'Lan']));
        $this->assertSame('Keep me signed in', text('admin.auth.keep_login'));
        $this->expectException(\RuntimeException::class);
        $store->publish('ja', ['save' => 'Stale'], 0);
    }

    public function test_invalid_publish_is_atomic_and_unknown_keys_cannot_register_themselves(): void
    {
        $this->sync();
        $store = $this->app->make(DatabaseLanguageStore::class);
        foreach ([['save' => 'Changed', 'welcome' => 'Lost placeholder'], ['unknown' => 'New key']] as $tree) {
            try {
                $store->publish('vi', $tree, 1);
                $this->fail('Invalid tree must fail');
            } catch (\InvalidArgumentException) {
                $this->assertSame('Lưu', $store->snapshot('vi')['messages']['save']);
                $this->assertSame(1, $store->snapshot('vi')['revision']);
            }
        }
    }

    public function test_commands_export_one_tree_and_sync_dry_run_does_not_persist(): void
    {
        $this->artisan('admin:lang:sync', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, $this->app['db']->table('saola_translation_keys')->count());
        $this->artisan('admin:lang:sync')->assertSuccessful();
        $this->artisan('admin:lang:export', ['locale' => 'ja'])->expectsOutputToContain('"save": "Save"')->assertSuccessful();
    }

    public function test_starter_sources_follow_the_same_registration_contract(): void
    {
        $sources = new LanguageSources();
        $sources->register('starter', __DIR__.'/../Fixtures/host/lang', ['saola']);
        $catalog = $sources->catalog();
        $this->assertSame('Lưu', $catalog['vi']['save']['value']);
        $this->assertSame('Duy trì đăng nhập', $catalog['vi']['auth.keep_login']['value']);
    }
}

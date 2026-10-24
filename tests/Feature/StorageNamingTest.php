<?php

namespace Saola\Admin\Tests\Feature;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\{DatabaseLanguageStore, LanguageExchange};
use Saola\Admin\Settings\{SettingsGroup, SettingsStore};

class StorageNamingTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }
    public function test_doctor_detects_legacy_tables_without_renaming_them(): void
    {
        config(['saola-admin.language.table_prefix' => 'saola_admin_', 'saola-admin.settings.table' => 'saola_admin_settings']);
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        $this->app['db']->table('saola_admin_locales')->insert(['code' => 'vi', 'published_revision' => 7]);
        config(['saola-admin.language.table_prefix' => 'saola_', 'saola-admin.settings.table' => 'saola_settings']);
        $this->withoutMockingConsoleOutput();
        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('admin:doctor'));
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Legacy table saola_admin_locales exists', $output);
        $this->assertStringContainsString('Legacy table saola_admin_settings exists', $output);
        $this->assertSame(7, $this->app['db']->table('saola_admin_locales')->where('code', 'vi')->value('published_revision'));
        $this->assertFalse($this->app['db']->connection()->getSchemaBuilder()->hasTable('saola_locales'));
    }

    public static function prefixes(): array { return [['saola_', ''], ['', ''], ['acme_', ''], ['saola_admin_', ''], ['', 'saola_']]; }

    #[DataProvider('prefixes')]
    public function test_migrations_store_and_exchange_share_the_configured_names(string $prefix, string $connectionPrefix): void
    {
        config(['database.connections.testing.prefix' => $connectionPrefix]);
        config(['saola-admin.language.table_prefix' => $prefix, 'saola-admin.settings.table' => $prefix.'settings']);
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $physical = array_column($this->app['db']->select("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
        $this->assertContains($connectionPrefix.$prefix.'locales', $physical);
        $this->assertContains($connectionPrefix.$prefix.'settings', $physical);
        foreach (['locales', 'translation_keys', 'translation_values', 'language_batches', 'settings'] as $suffix) {
            $this->assertTrue($schema->hasTable($prefix.$suffix));
        }
        if ($prefix !== 'saola_admin_') $this->assertFalse($schema->hasTable('saola_admin_locales'));
        $store = $this->app->make(DatabaseLanguageStore::class);
        $store->sync(['en' => ['save' => ['value' => 'Save', 'owner' => 'test', 'file' => 'common']]], 'en');
        $this->assertSame('Save', $store->snapshot('en')['messages']['save']);
        $exchange = $this->app->make(LanguageExchange::class);
        $export = $exchange->export('vi', 'operator');
        $preview = $exchange->preview('vi', '{"save":"Lưu"}', 'operator', $export['batch']);
        $exchange->apply($preview['batch'], 'operator', true);
        $this->assertSame('Lưu', $store->snapshot('vi')['messages']['save']);
        $group = new SettingsGroup('test', 'settings', 'read', 'write', ['title' => ['labelKey' => 'title', 'type' => 'text', 'default' => '']]);
        $settings = $this->app->make(SettingsStore::class);
        $settings->update($group, 0, ['values' => ['title' => 'Website']]);
        $this->assertSame('Website', $settings->values($group, 'en')['title']);
        $this->assertSame(1, $this->app['db']->table($prefix.'settings')->count());
        foreach (array_reverse(glob(__DIR__.'/../../database/migrations/*.php')) as $file) (require $file)->down();
        $this->assertFalse($schema->hasTable($prefix.'locales'));
        $this->assertFalse($schema->hasTable($prefix.'settings'));
    }
}

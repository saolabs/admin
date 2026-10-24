<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Settings\{DatabaseSettingsStore, SettingsGroup, SettingsRegistry, SettingsStore};
use Saola\Core\Support\SPA;

class SettingsTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('saola-admin.panel.enabled', true);
        $app['config']->set('saola-admin.settings.register_defaults', false);
        $app['config']->set('saola-admin.panel.prefix', 'control');
    }
    protected function setUp(): void
    {
        SPA::resetRoutes(); parent::setUp();
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        Gate::define('access-admin', fn ($user) => in_array($user->getAuthIdentifier(), [1, 2]));
        Gate::define('read-settings', fn ($user) => in_array($user->getAuthIdentifier(), [1, 2]));
        Gate::define('write-settings', fn ($user) => $user->getAuthIdentifier() === 1);
        $this->app->make(SettingsRegistry::class)->register($this->group());
    }
    protected function tearDown(): void { SPA::resetRoutes(); parent::tearDown(); }
    private function group(int $version = 1): SettingsGroup
    {
        return new SettingsGroup('general', 'settings.general', 'read-settings', 'write-settings', [
            'name' => ['labelKey' => 'settings.name', 'type' => 'text', 'default' => 'Website', 'localized' => true, 'public' => true, 'maxLength' => 50],
            'contact' => ['labelKey' => 'settings.contact', 'type' => 'text', 'default' => '', 'public' => false],
            'enabled' => ['labelKey' => 'settings.enabled', 'type' => 'boolean', 'default' => true, 'public' => true],
            'page_size' => ['labelKey' => 'settings.page_size', 'type' => 'integer', 'default' => 20, 'min' => 1, 'max' => 100],
        ], $version);
    }
    public function test_default_general_group_has_separate_permissions_and_website_projection(): void
    {
        config(['saola-admin.settings.register_defaults' => true]);
        $this->app->forgetInstance(SettingsRegistry::class);
        $group = $this->app->make(SettingsRegistry::class)->get('general');
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->getJson('/control/settings/general')->assertForbidden();
        Gate::define('view-site-settings', fn () => true);
        $this->getJson('/control/settings/general')->assertOk()->assertJsonPath('canUpdate', false)
            ->assertJsonPath('schema.fields.website_name.localized', true)->assertJsonPath('data.revision', 0);
        $this->assertSame(['website_name' => config('app.name'), 'description' => ''],
            $this->app->make(SettingsStore::class)->values($group, 'vi', true));
        $this->putJson('/control/settings/general', ['revision' => 0, 'patch' => ['values' => ['contact' => 'private']]])->assertForbidden();
    }
    public function test_defaults_are_read_without_writing_and_public_values_are_allowlisted(): void
    {
        $store = $this->app->make(SettingsStore::class); $group = $this->group();
        $this->assertSame(0, $store->read($group)['revision']);
        $this->assertSame(0, $this->app['db']->table('saola_settings')->count());
        $this->assertSame(['name' => 'Website', 'enabled' => true], $store->values($group, 'vi', true));
        $store->update($group, 0, ['values' => ['contact' => 'private@example.test'], 'localized' => ['en' => ['name' => 'Hello']]]);
        $this->assertSame('Hello', $store->values($group, 'vi')['name']);
        $this->assertArrayNotHasKey('contact', $store->values($group, 'vi', true));
        $this->assertArrayNotHasKey('vi', $store->read($group)['localized']);
    }
    public function test_http_permissions_revision_and_explicit_empty_content_translation(): void
    {
        $url = '/control/settings/general';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(new GenericUser(['id' => 2]));
        $this->getJson($url)->assertOk()->assertJsonPath('canUpdate', false);
        $this->putJson($url, ['revision' => 0, 'patch' => ['values' => ['enabled' => false]]])->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->putJson($url, ['revision' => 0, 'patch' => ['values' => ['enabled' => false], 'localized' => ['en' => ['name' => 'Hello'], 'vi' => ['name' => '']]]])
            ->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.localized.vi.name', '');
        $this->putJson($url, ['revision' => 0, 'patch' => ['values' => ['enabled' => true]]])->assertConflict();
        $this->assertSame('', $this->app->make(SettingsStore::class)->values($this->group(), 'vi')['name']);
        $response = $this->getJson($url)->assertJsonPath('data.values.enabled', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Gate::define('read-settings', fn () => false);
        $this->getJson($url)->assertForbidden();
        $this->putJson($url, ['revision' => 1, 'patch' => ['values' => ['enabled' => true]]])->assertForbidden();
    }
    public function test_unknown_fields_wrong_section_types_and_locales_are_rejected_without_changes(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));
        foreach ([['values' => ['is_admin' => true]], ['values' => ['name' => 'Wrong section']], ['values' => ['enabled' => 'false']],
            ['values' => ['page_size' => 101]], ['localized' => ['ja' => ['name' => 'Unknown locale']]], ['localized' => ['vi' => ['name' => null]]],
            ['values' => ['contact' => str_repeat('x', 2049)]], ['localized' => ['vi' => ['contact' => 'private']]], ['unknown' => []]] as $patch) {
            $this->putJson('/control/settings/general', ['revision' => 0, 'patch' => $patch])->assertUnprocessable();
        }
        $this->assertSame(0, $this->app['db']->table('saola_settings')->count());
        $this->getJson('/control/settings/unknown')->assertNotFound();
    }
    public function test_dormant_extension_fields_survive_a_partial_schema_without_being_exposed(): void
    {
        $store = $this->app->make(SettingsStore::class); $complete = $this->group();
        $store->update($complete, 0, ['values' => ['contact' => 'private extension'], 'localized' => ['vi' => ['name' => 'Giữ lại']]]);
        $partial = new SettingsGroup('general', 'settings.general', 'read-settings', 'write-settings', ['enabled' => $complete->fields['enabled']]);
        $response = $store->update($partial, 1, ['values' => ['enabled' => false]]);
        $this->assertSame(['enabled' => false], $response['values']);
        $this->assertSame([], $response['localized']);
        $this->assertSame('private extension', $store->values($complete, 'vi')['contact']);
        $this->assertSame('Giữ lại', $store->values($complete, 'vi')['name']);
    }

    public function test_disabled_locale_data_survives_later_edits_and_schema_version_requires_migration(): void
    {
        $group = $this->group(); $store = $this->app->make(SettingsStore::class);
        $store->update($group, 0, ['localized' => ['vi' => ['name' => 'Tiếng Việt']]]);
        $english = new DatabaseSettingsStore($this->app['db']->connection(), 'saola_settings', ['en'], 'en');
        $english->update($group, 1, ['values' => ['enabled' => false]]);
        $this->assertSame('Tiếng Việt', $store->values($group, 'vi')['name']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $store->read($this->group(2));
    }
}

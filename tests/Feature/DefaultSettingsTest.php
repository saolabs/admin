<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Admin\Settings\{DatabaseSettingsStore, SettingsRegistry, SettingsStore};
use Saola\Core\Language\LanguageSources;
use Saola\Core\Support\SPA;

class DefaultSettingsTest extends TestCase
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
        Gate::define('view-site-settings', fn ($user) => in_array($user->id, [1, 2], true));
        Gate::define('update-site-settings', fn ($user) => $user->id === 1);
        Gate::define('view-system-settings', fn ($user) => in_array($user->id, [1, 3], true));
        Gate::define('update-system-settings', fn ($user) => $user->id === 1);
        $this->actingAs(new GenericUser(['id' => 1]));
    }
    protected function tearDown(): void { SPA::resetRoutes(); parent::tearDown(); }

    public function test_sidebar_identity_uses_localized_settings_and_full_and_compact_logos(): void
    {
        config(['app.name' => 'Host App']);
        $this->getJson('/control/settings')->assertOk()->assertJsonPath('data.brand', 'Host App')
            ->assertJsonPath('data.branding.logoUrl', '')->assertJsonPath('data.branding.initials', 'H');
        $this->putJson('/control/settings/general', ['revision' => 0, 'patch' => ['localized' => [
            'en' => ['website_name' => 'English Brand'], 'vi' => ['website_name' => 'Thương hiệu Việt'],
        ]]])->assertOk();
        $this->putJson('/control/settings/branding', ['revision' => 0, 'patch' => ['values' => [
            'logo_url' => '/images/logo.png', 'logo_dark_url' => '/images/logo-dark.png', 'favicon_url' => '/images/icon.png',
        ]]])->assertOk();
        $this->getJson('/control/settings?locale=vi')->assertOk()->assertJsonPath('data.brand', 'Thương hiệu Việt')
            ->assertJsonPath('data.branding.logoUrl', '/images/logo-dark.png')
            ->assertJsonPath('data.branding.compactLogoUrl', '/images/icon.png')->assertJsonPath('data.branding.initials', 'T');
        $this->putJson('/control/settings/branding', ['revision' => 1, 'patch' => ['values' => [
            'logo_dark_url' => '', 'favicon_url' => '',
        ]]])->assertOk();
        $this->getJson('/control/settings?locale=en')->assertOk()->assertJsonPath('data.brand', 'English Brand')
            ->assertJsonPath('data.branding.logoUrl', '/images/logo.png')->assertJsonPath('data.branding.compactLogoUrl', '')
            ->assertJsonPath('data.branding.initials', 'E');
        // UI languages and content languages are independent policies.
        $this->app->instance(SettingsStore::class, new DatabaseSettingsStore(app('db')->connection(), 'saola_settings', ['vi'], 'vi'));
        $this->assertSame('Thương hiệu Việt', app(\Saola\Admin\Http\PanelBranding::class)->project('en')['brand']);
    }

    public function test_default_groups_are_nested_and_system_permissions_are_independent(): void
    {
        $this->getJson('/control/settings')->assertOk()->assertJsonCount(2, 'data.editor.categories')
            ->assertJsonCount(5, 'data.editor.categories.0.groups')->assertJsonCount(3, 'data.editor.categories.1.groups')
            ->assertJsonPath('data.currentPage', 'settings.website')->assertJsonPath('data.editor.category', 'website')->assertJsonCount(5, 'data.editor.tabs');
        $this->getJson('/control/settings?group=system')->assertOk()->assertJsonPath('data.currentPage', 'settings.system')
            ->assertJsonCount(3, 'data.editor.tabs')->assertJsonPath('data.navigation.1.children.1.active', true)
            ->assertJsonPath('data.navigation.1.open', true)->assertJsonPath('data.navigation.1.active', true);
        $navigation = $this->getJson('/control/settings?group=seo')->assertOk()->json('data.navigation');
        $this->assertTrue($navigation[1]['active']); $this->assertTrue($navigation[1]['children'][0]['active']);
        $this->assertNotEmpty($navigation[1]['iconPath']);
        $this->actingAs(new GenericUser(['id' => 2]));
        $this->getJson('/control/settings')->assertOk()->assertJsonCount(1, 'data.editor.categories')->assertJsonCount(5, 'data.editor.groups')
            ->assertJsonCount(1, 'data.navigation.1.children')->assertJsonPath('data.navigation.1.children.0.key', 'settings.website');
        $this->getJson('/control/settings/system')->assertForbidden();
        $this->getJson('/control/settings?group=system')->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 3]));
        $this->getJson('/control/settings')->assertOk()->assertJsonPath('data.editor.group', 'system')->assertJsonPath('data.editor.canUpdate', false)
            ->assertJsonCount(1, 'data.navigation.1.children')->assertJsonPath('data.navigation.1.children.0.key', 'settings.system');
        $this->putJson('/control/settings/system', ['revision' => 0, 'patch' => ['values' => ['maintenance_mode' => true]]])->assertForbidden();
    }

    public function test_system_has_one_value_set_and_never_reads_content_locale_policy(): void
    {
        $group = app(SettingsRegistry::class)->get('system');
        $store = new DatabaseSettingsStore(app('db')->connection(), 'saola_settings', ['en'], 'en', static function () { throw new \RuntimeException('Content policy must not be read.'); });
        $this->assertSame(['maintenance_mode' => false, 'maintenance_message' => ''], $store->values($group));
        $store->update($group, 0, ['values' => ['maintenance_mode' => true, 'maintenance_message' => 'A shared maintenance message']]);
        $this->assertSame($store->values($group, 'en'), $store->values($group, 'vi'));
        $this->assertSame([], $store->values($group, publicOnly: true));
        $this->getJson('/control/settings?group=system&content_locale=not-a-locale')->assertOk()
            ->assertJsonPath('data.editor.contentLocale', '')->assertJsonPath('data.editor.localizedGroup', false)
            ->assertJsonPath('data.editor.document.contentLocales', [])->assertJsonPath('data.editor.fields.0.value', true);
        $this->putJson('/control/settings/system', ['revision' => 1, 'patch' => ['localized' => ['vi' => ['maintenance_message' => 'Wrong section']]]])->assertUnprocessable();
        $this->putJson('/control/settings/system', ['revision' => 1, 'patch' => ['values' => ['maintenance_mode' => false]]])->assertOk()->assertJsonPath('data.values.maintenance_mode', false);
        $this->assertSame(1, app('db')->table('saola_settings')->where('group_key', 'system')->count());
        $raw = json_decode(app('db')->table('saola_settings')->where('group_key', 'system')->value('document'), true);
        $this->assertSame([], $raw['localized']);
    }

    public function test_seo_translations_and_shared_image_do_not_overwrite_each_other(): void
    {
        $this->putJson('/control/settings/seo', ['revision' => 0, 'patch' => [
            'values' => ['og_image_url' => '/images/share.png', 'indexable' => false],
            'localized' => ['en' => ['meta_title' => 'English title'], 'vi' => ['meta_title' => '', 'meta_description' => "Dòng một\nDòng hai"]],
        ]])->assertOk();
        $group = app(SettingsRegistry::class)->get('seo'); $store = app(SettingsStore::class);
        $this->assertSame('', $store->values($group, 'vi')['meta_title']);
        $this->assertSame('English title', $store->values($group, 'en')['meta_title']);
        $this->assertSame('/images/share.png', $store->values($group, 'vi')['og_image_url']);
        $this->getJson('/control/settings?group=seo&content_locale=en')->assertOk()->assertJsonPath('data.editor.localizedGroup', true);
        $this->getJson('/control/settings?group=branding&content_locale=unknown')->assertOk()->assertJsonPath('data.editor.localizedGroup', false);
        $this->putJson('/control/settings/seo', ['revision' => 0, 'patch' => ['values' => ['indexable' => true]]])->assertConflict();
    }

    public function test_urls_options_ranges_and_types_are_validated_without_partial_writes(): void
    {
        foreach ([['logo_url' => 'javascript:alert(1)'], ['favicon_url' => '//outside.example/image.png'], ['logo_url' => 'data:image/svg+xml,bad']] as $values) {
            $this->putJson('/control/settings/branding', ['revision' => 0, 'patch' => ['values' => $values]])->assertUnprocessable();
        }
        foreach ([['timezone' => 'Not/AZone'], ['date_format' => 'bad'], ['items_per_page' => 0], ['items_per_page' => '25']] as $values) {
            $this->putJson('/control/settings/system-display', ['revision' => 0, 'patch' => ['values' => $values]])->assertUnprocessable();
        }
        $this->assertSame(0, app('db')->table('saola_settings')->count());
        $this->putJson('/control/settings/system-display', ['revision' => 0, 'patch' => ['values' => ['timezone' => 'Asia/Ho_Chi_Minh', 'items_per_page' => 25]]])->assertOk();
        $this->getJson('/control/settings?group=system-display')->assertOk()->assertJsonPath('data.editor.fields.0.type', 'select')
            ->assertJsonPath('data.editor.fields.0.value', 'Asia/Ho_Chi_Minh');
    }

    public function test_general_upgrade_preserves_legacy_data_but_does_not_show_contact_fields(): void
    {
        $group = app(SettingsRegistry::class)->get('general'); $store = app(SettingsStore::class);
        $store->update($group, 0, ['values' => ['contact' => 'Preserved legacy value'], 'localized' => ['vi' => ['website_name' => 'Tên cũ']]]);
        $this->getJson('/control/settings?group=general&content_locale=vi')->assertOk()->assertJsonCount(9, 'data.editor.fields')
            ->assertJsonPath('data.editor.fields.0.value', 'Tên cũ')->assertJsonPath('data.editor.fields.1.type', 'textarea');
        $store->update($group, 1, ['localized' => ['en' => ['tagline' => 'New tagline']]]);
        $this->assertSame('Preserved legacy value', $store->read($group)['values']['contact']);
        $this->assertArrayNotHasKey('contact', $store->values($group, 'vi', true));
        $this->assertSame(8, count(app(SettingsRegistry::class)->all()));
    }

    public function test_admin_identity_is_independent_private_and_uses_system_permissions(): void
    {
        $this->putJson('/control/settings/branding', ['revision' => 0, 'patch' => ['values' => ['logo_url' => '/website.png']]])->assertOk();
        $patch = ['revision' => 0, 'patch' => ['values' => [
            'admin_logo_url' => '/admin.png', 'admin_logo_dark_url' => '/admin-dark.png',
            'admin_compact_logo_url' => '/admin-icon.png', 'show_brand_text' => false, 'brand_text' => 'Operations <HQ>',
        ]]];
        $this->actingAs(new GenericUser(['id' => 2])); // Website-only editor.
        $this->getJson('/control/settings/admin-appearance')->assertForbidden();
        $this->putJson('/control/settings/admin-appearance', $patch)->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->putJson('/control/settings/admin-appearance', $patch)->assertOk()->assertJsonPath('data.revision', 1);
        $this->getJson('/control/settings?group=admin-appearance')->assertOk()
            ->assertJsonPath('data.brand', 'Operations <HQ>')->assertJsonPath('data.branding.logoUrl', '/admin-dark.png')
            ->assertJsonPath('data.branding.compactLogoUrl', '/admin-icon.png')->assertJsonPath('data.branding.showBrandText', false)
            ->assertJsonPath('data.editor.fields.0.media', true);
        $store = app(SettingsStore::class); $group = app(SettingsRegistry::class)->get('admin-appearance');
        $this->assertSame([], $store->values($group, publicOnly: true));
        $this->assertSame('/website.png', $store->values(app(SettingsRegistry::class)->get('branding'))['logo_url']);
        $this->putJson('/control/settings/admin-appearance', $patch)->assertConflict();
        foreach ([['show_brand_text' => 'false'], ['admin_logo_url' => 'javascript:alert(1)'], ['brand_text' => str_repeat('a', 201)]] as $bad) {
            $this->putJson('/control/settings/admin-appearance', ['revision' => 1, 'patch' => ['values' => $bad]])->assertUnprocessable();
        }
        $this->putJson('/control/settings/admin-appearance', ['revision' => 1, 'patch' => ['values' => [
            'admin_logo_dark_url' => '', 'admin_compact_logo_url' => '', 'brand_text' => '', 'show_brand_text' => true,
        ]]])->assertOk();
        $this->getJson('/control/settings')->assertOk()->assertJsonPath('data.branding.logoUrl', '/admin.png')
            ->assertJsonPath('data.branding.compactLogoUrl', '/admin.png')->assertJsonPath('data.branding.showBrandText', true);
    }

    public function test_company_and_website_details_preserve_localized_names_and_shared_legal_details(): void
    {
        $this->putJson('/control/settings/company', ['revision' => 0, 'patch' => [
            'localized' => ['en' => ['company_name' => 'Saola Labs'], 'vi' => ['company_name' => 'Công ty Saola']],
            'values' => ['company_legal_name' => 'Saola Labs Ltd', 'tax_code' => '0123456789',
                'business_registration_number' => 'REG-001', 'legal_representative' => 'Nguyen A', 'company_url' => 'https://example.test'],
        ]])->assertOk();
        $store = app(SettingsStore::class); $group = app(SettingsRegistry::class)->get('company');
        $this->assertSame('Công ty Saola', $store->values($group, 'vi')['company_name']);
        $this->assertSame('0123456789', $store->values($group, 'en')['tax_code']);
        $this->putJson('/control/settings/general', ['revision' => 0, 'patch' => ['values' => [
            'about_url' => '/about', 'privacy_policy_url' => '/privacy', 'terms_url' => 'https://example.test/terms',
        ], 'localized' => ['en' => ['short_name' => 'Saola']]]])->assertOk();
        $this->putJson('/control/settings/general', ['revision' => 1, 'patch' => ['values' => ['terms_url' => '//evil.test']]])->assertUnprocessable();
    }

    public function test_richtext_settings_are_sanitized_before_they_are_stored(): void
    {
        $group = app(SettingsRegistry::class)->get('general'); $store = app(SettingsStore::class);
        $result = $store->update($group, 0, ['localized' => ['en' => ['copyright' => '<p>© <b>Acme</b><script>alert(1)</script> <a href="javascript:x" onclick="y">go</a></p>']]]);
        $this->assertSame('<p>© <b>Acme</b> <a>go</a></p>', $result['localized']['en']['copyright']);
        $this->assertSame($result['localized']['en']['copyright'], $store->values($group, 'en')['copyright']);
    }
}

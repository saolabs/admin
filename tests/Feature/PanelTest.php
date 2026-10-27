<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Admin\Pages\{Page, PageRegistry, ResourceDocument};
use Saola\Core\Language\LanguageSources;
use Saola\Core\Support\SPA;

class PanelTest extends TestCase
{
    private string $root;
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $this->root = realpath(sys_get_temp_dir()).'/saola-panel-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/resources/views', 0777, true);
        mkdir($this->root.'/bootstrap/cache', 0777, true);
        $app->setBasePath($this->root);
        $app['config']->set('view.paths', [$this->root.'/resources/views']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('app.name', 'Workbench');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('filesystems.disks.local.root', $this->root.'/storage/app/private');
        $app['config']->set('saola-admin.panel.enabled', true);
        $app['config']->set('saola-admin.panel.prefix', 'control');
        $app['config']->set('saola-admin.users.enabled', true);
        $app['config']->set('saola-admin.users.query_scope', \Saola\Admin\Tests\Fixtures\TenantUserScope::class);
        $app['config']->set('auth.providers.users.model', \Saola\Admin\Tests\Fixtures\DirectoryUser::class);
    }

    protected function setUp(): void
    {
        SPA::resetRoutes();
        parent::setUp();
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        $this->app->make(DatabaseLanguageStore::class)->sync($this->app->make(LanguageSources::class)->catalog(), 'en');
        \Saola\Admin\Tests\Fixtures\DirectoryDataset::create();
        Gate::define('access-admin', fn ($user) => $user->getAuthIdentifier() === 1);
        Gate::define('view-categories', fn ($user) => $user->getAuthIdentifier() === 1);
        Gate::define('view-admin-users', fn ($user) => $user->getAuthIdentifier() === 1);
        Gate::define('viewAny', fn ($user, $model) => $user->getAuthIdentifier() === 1 && $model === \Saola\Admin\Tests\Fixtures\DirectoryUser::class);
        $this->app->make(PageRegistry::class)->register(CategoryPage::class);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);
        SPA::resetRoutes();
        parent::tearDown();
    }

    public function test_panel_rejects_guests_and_non_operators_then_exposes_only_readonly_projection(): void
    {
        $this->getJson('/control')->assertUnauthorized();
        $this->actingAs(new GenericUser(['id' => 2])); $this->getJson('/control')->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7]));
        $previousLocale = $this->app->getLocale();
        $response = $this->getJson('/control?locale=vi')->assertOk()->assertJsonPath('data.page.schema', 1)
            ->assertJsonPath('data.page.fields.0.value', 'Workbench')->assertJsonPath('data.page.fields.0.readOnly', true)
            ->assertJsonPath('i18n.catalogs.vi.messages.admin.workspace.title', 'Tổng quan');
        $this->assertSame($previousLocale, $this->app->getLocale());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('admin.themes.classic.pages.resource', $response->json('view'));
        $this->assertCount(2, $response->json('data.page.rows'));
        $this->getJson('/control/categories')->assertOk()->assertJsonMissingPath('data.page.rows.0.secret')
            ->assertJsonPath('data.page.rows.0.name', '<b>Category</b>');
        $this->getJson('/control/unknown')->assertNotFound();
        Gate::define('view-categories', fn () => false);
        $this->getJson('/control/categories')->assertForbidden();
        $this->getJson('/control')->assertOk()->assertJsonCount(2, 'data.navigation');
    }

    public function test_page_registration_is_explicit_and_duplicate_keys_fail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(PageRegistry::class)->register(CategoryPage::class);
    }

    public function test_theme_changes_view_context_and_css_without_changing_the_page_or_permissions(): void
    {
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7]));
        $classic = $this->getJson('/control/categories')->assertOk()->assertJsonPath('view', 'admin.themes.classic.pages.resource')->json('data');
        config(['saola-admin.panel.theme' => 'material']);
        $theme = app(\Saola\Admin\Themes\AdminTheme::class)->apply();
        $material = $this->getJson('/control/categories')->assertOk()->assertJsonPath('view', 'admin.themes.material.pages.resource')->json('data');
        $this->assertSame($classic, $material);
        $this->assertSame('resources/saola/admin/themes/material/assets/css/admin.css', config('sao.assets.css.admin'));
        $this->assertSame('admin.themes.material', $theme['view_context']);
        $shared = app(\Saola\Core\Services\ClientShare::class);
        $this->assertSame('material', $shared->export(Request::create('/control'))['saola.context.admin']['theme']);
        config(['saola-admin.panel.theme' => 'classic']);
        $this->assertSame('classic', $shared->export(Request::create('/control'))['saola.context.admin']['theme']);
        config(['saola-admin.panel.theme' => 'material']);
        Gate::define('view-categories', fn () => false);
        $this->getJson('/control/categories')->assertForbidden();
        config(['saola-admin.panel.theme' => '../classic']);
        try { app(\Saola\Admin\Themes\AdminTheme::class)->apply(); $this->fail('Traversal must fail'); }
        catch (\InvalidArgumentException) { $this->assertSame('admin.themes.material', app(\Saola\Core\Engines\ViewContextManager::class)->getBaseDirectory('admin', 'base')); }
    }

    public function test_host_can_select_a_registered_home_page_without_bypassing_its_permission(): void
    {
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7]));
        config(['saola-admin.panel.home_page' => 'categories']);
        $this->getJson('/control')->assertOk()->assertJsonPath('data.currentPage', 'categories')
            ->assertJsonPath('data.page.rows.0.name', '<b>Category</b>');
        Gate::define('view-categories', fn () => false);
        $this->getJson('/control')->assertForbidden();
        config(['saola-admin.panel.home_page' => 'missing']);
        $this->getJson('/control')->assertNotFound();
    }

    public function test_linked_pages_list_the_host_url_and_redirect_instead_of_rendering_a_document(): void
    {
        app(PageRegistry::class)->register(HostScreenPage::class);
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7]));
        $request = Request::create('/control'); $request->setUserResolver(fn () => new GenericUser(['id' => 1, 'tenant_id' => 7]));
        $items = array_column(app(PageRegistry::class)->navigation($request), null, 'key');
        $this->assertSame('/control/ai/providers', $items['host-screen']['url']);
        $this->get('/control/host-screen')->assertRedirect('/control/ai/providers');
        Gate::define('view-categories', fn () => false);
        $this->get('/control/host-screen')->assertForbidden();
    }

    public function test_host_modules_move_only_the_modules_directory_and_keep_the_theme_as_base(): void
    {
        $views = app(\Saola\Core\Engines\ViewContextManager::class);
        $base = $views->getBaseDirectory('admin', 'base');
        config(['saola-admin.panel.host_modules' => 'host.modules']);
        app(\Saola\Admin\Themes\AdminTheme::class)->apply();
        $this->assertSame($base, $views->getBaseDirectory('admin', 'base'));
        $this->assertSame('host.modules', $views->getBaseDirectory('admin', 'modules'));
        $this->assertSame('host.modules.', $views->getContextVariable('admin', '__module__'));
        $this->assertSame($base.'.pages.', $views->getContextVariable('admin', '__page__'));
        $this->assertSame('host.modules.providers.index', $views->resolvePath('admin', 'providers', 'index', 'modules'));
        $this->assertSame($base.'.pages.resource', $views->resolveRouteComponent('admin', '@BASE:pages.resource'));
    }

    public function test_grouped_pages_project_only_authorized_children_and_hide_empty_parents(): void
    {
        $registry = app(PageRegistry::class);
        $registry->register(GroupedCategoryPage::class); $registry->register(GroupedArticlePage::class);
        $request = Request::create('/control'); $request->setUserResolver(fn () => new GenericUser(['id' => 1, 'tenant_id' => 7]));
        $items = array_column($registry->navigation($request), null, 'key');
        $this->assertCount(1, $items['content']['children']);
        $this->assertSame('grouped-categories', $items['content']['children'][0]['key']);
        Gate::define('view-articles', fn () => true);
        $items = array_column($registry->navigation($request), null, 'key');
        $this->assertCount(2, $items['content']['children']);
        Gate::define('view-categories', fn () => false); Gate::define('view-articles', fn () => false);
        $this->assertArrayNotHasKey('content', array_column($registry->navigation($request), null, 'key'));
    }

    public function test_ssr_compiles_the_published_source_and_uses_the_same_dictionary_and_css_contract(): void
    {
        $this->artisan('admin:doctor')->expectsOutputToContain('Run admin:install')->assertFailed();
        $this->artisan('admin:install', ['--dry-run' => true])->assertSuccessful();
        $this->assertDirectoryDoesNotExist($this->root.'/resources/saola');
        $this->artisan('admin:install')->assertSuccessful();
        foreach (['classic', 'material'] as $theme) {
        $source = $this->root.'/resources/saola/admin/themes/'.$theme.'/views';
        foreach ((new Filesystem())->allFiles($source) as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1, -4);
            $name = 'Admin'.str_replace(' ', '', ucwords(str_replace(['/', '-', '_'], ' ', $relative)));
            $result = (new \Saola\Compiler\SaolaCompiler())->compile(file_get_contents($file), new \Saola\Compiler\CompileOptions(
                viewPath: 'admin.themes.'.$theme.'.'.str_replace('/', '.', $relative), functionName: $name, factoryName: $name, lang: \Saola\Compiler\Lang::Ts,
            ));
            $out = $this->root.'/resources/views/admin/themes/'.$theme.'/'.$relative.'.blade.php';
            (new Filesystem())->ensureDirectoryExists(dirname($out)); file_put_contents($out, $result->blade);
            $js = $this->root.'/resources/js/saola/admin/views/'.$theme.'/'.$relative.'.ts';
            (new Filesystem())->ensureDirectoryExists(dirname($js)); file_put_contents($js, $result->js);
        }
        }
        (new Filesystem())->copyDirectory(__DIR__.'/../Fixtures/host/resources/views/_system', $this->root.'/resources/views/_system');
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7, 'name' => 'Workbench Operator', 'email' => 'operator@workbench.test']));
        $response = $this->get('/control?locale=vi')->assertOk();
        $response->assertSee('Tổng quan')->assertSee('Workbench')->assertSee('window.APP_CONFIGS=', false)
            ->assertSee('admin.themes.classic.pages.resource', false)->assertSee('admin:', false)->assertSee('Workbench Operator')
            ->assertSee('id="admin-language-toggle"', false)->assertSee('id="admin-account-toggle"', false)
            ->assertSee('sa-admin-nav-icon', false)->assertSee('Thu gọn thanh bên');
        config(['saola-admin.panel.theme' => 'material']);
        app(\Saola\Admin\Themes\AdminTheme::class)->apply();
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control?locale=vi')->assertOk()->assertSee('data-admin-theme="material"', false)
            ->assertSee('id="page-topbar"', false)->assertSee('navbar-menu', false)->assertSee('ri-dashboard-2-line', false);
        $this->getJson('/control/_ui')->assertNotFound();
        config(['saola-admin.panel.ui_catalog' => true]);
        $this->getJson('/control/_ui?locale=vi')->assertOk()->assertJsonPath('view', 'admin.themes.material.pages.ui-library')
            ->assertJsonCount(4, 'data.samples.cards')->assertJsonPath('data.currentPage', 'ui-library');
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $catalog = $this->get('/control/_ui?locale=vi')->assertOk()->assertSee('Default Buttons')
            ->assertSee('card-img-top', false)->assertSee('demo-tabs-tab-overview')->assertSee('aria-valuenow="65"', false)
            ->assertSee('demo-dropdown-trigger')->assertSee('demo-dialog');
        (new Filesystem())->ensureDirectoryExists($this->root.'/public');
        file_put_contents($this->root.'/public/ui-library.html', $catalog->getContent());
        config(['saola-admin.panel.ui_catalog' => false]);
        config(['saola-admin.panel.theme' => 'classic']);
        app(\Saola\Admin\Themes\AdminTheme::class)->apply();
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->assertSame('resources/saola/admin/themes/classic/assets/css/admin.css', config('sao.assets.css.admin'));
        $this->assertCount(8, SPA::getRoutes('admin'));
        $routes = app(\Saola\Core\View\Services\ViewHelperService::class)->exportComponentRoutes('admin');
        $this->assertContains('admin.themes.classic.pages.settings', array_column($routes, 'component'));
        $this->assertArrayHasKey('saola.admin.page', $this->app['router']->getRoutes()->compile()['attributes']);
        $this->getJson('/control/settings')->assertForbidden();
        Gate::define('view-site-settings', fn () => true);
        Gate::define('update-site-settings', fn () => true);
        Gate::define('view-system-settings', fn () => true);
        Gate::define('update-system-settings', fn () => true);
        $this->getJson('/control/settings?content_locale=vi')->assertOk()
            ->assertJsonPath('view', 'admin.themes.classic.pages.settings')
            ->assertJsonPath('data.editor.contentLocale', 'vi')->assertJsonPath('data.editor.fields.0.value', 'Workbench')
            ->assertJsonPath('data.editor.canUpdate', true)->assertJsonCount(4, 'data.navigation');
        foreach (['view', 'manage', 'export', 'import', 'publish'] as $operation) Gate::define($operation.'-admin-languages', fn () => true);
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/languages?locale=vi')->assertOk()->assertSee('Ngôn ngữ')->assertSee('language-save', false);
        $this->getJson('/control/languages?locale=vi')->assertOk()->assertJsonPath('view', 'admin.themes.classic.pages.languages')
            ->assertJsonPath('data.languageEditor.document.uiDefault', 'en')->assertJsonPath('data.languageEditor.permissions.publish', true);
        foreach (['view', 'upload', 'update', 'delete'] as $operation) Gate::define($operation.'-media', fn () => true);
        $this->post('/control/_api/media', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('fixture-logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3e0AAAAASUVORK5CYII='))], ['Accept' => 'application/json'])->assertCreated();
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/media?locale=vi')->assertOk()->assertSee('Thư viện media')->assertSee('fixture-logo')->assertSee('media-upload', false);
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/settings?group=branding&locale=vi')->assertOk()->assertSee('Chọn từ thư viện');
        $api = '/control/_api/languages';
        $export = $this->postJson($api.'/vi/export', [])->assertOk()->json('export.batch');
        $this->postJson($api.'/vi/preview', ['exportBatch' => $export, 'json' => '{"admin":{"users":{"title":"Preview label"}}}'])->assertOk();
        $draft = $this->postJson($api.'/vi/preview', ['exportBatch' => $export, 'json' => '{"admin":{"users":{"title":"Draft label"}}}'])->assertOk()->json('preview.batch');
        $this->postJson($api.'/batches/'.$draft, ['publish' => false])->assertOk();
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/languages?locale=vi')->assertOk()->assertSee($export)->assertSee($draft)
            ->assertSee('Đã xuất')->assertSee('Xem trước')->assertSee('Bản nháp');
        $this->getJson('/control/settings?group=missing')->assertNotFound();
        $this->getJson('/control/settings?content_locale=ja')->assertUnprocessable();
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/settings?locale=vi&content_locale=en')->assertOk()
            ->assertSee('Thông tin website')->assertSee('Ngôn ngữ nội dung')->assertSee('settings-save', false)
            ->assertSee('sa-admin-settings-tabs', false)->assertSee('id="admin-submenu-settings"', false)
            ->assertDontSee('id="settings-groups-toggle"', false);
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/settings?locale=vi&group=system&content_locale=ja')->assertOk()
            ->assertSee('Vận hành')->assertSee('Chế độ bảo trì')->assertDontSee('id="settings-content-locale"', false);
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/users?locale=vi&per_page=10')->assertOk()->assertSee('Người dùng')->assertSee('Tìm kiếm')
            ->assertSee('Member 01')->assertDontSee('Member hidden')->assertDontSee('never-send-hash')->assertSee('resource-search', false);
        if ($destination = getenv('SAOLA_ADMIN_PREVIEW_DIR')) {
            if (is_dir($destination) && count(scandir($destination)) > 2) throw new \RuntimeException('Preview export requires an empty directory; choose a fresh temporary root to avoid mixing old source or database files.');
            (new Filesystem())->ensureDirectoryExists($this->root.'/public');
            file_put_contents($this->root.'/public/index-vi.html', $response->getContent());
            foreach (['en', 'vi'] as $locale) {
                app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
                file_put_contents($this->root.'/public/index-'.$locale.'.html', $this->get('/control?locale='.$locale)->assertOk()->getContent());
                file_put_contents($this->root.'/page-'.$locale.'.json', $this->getJson('/control?locale='.$locale)->assertOk()->getContent());
                app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
                file_put_contents($this->root.'/public/categories-'.$locale.'.html', $this->get('/control/categories?locale='.$locale)->assertOk()->getContent());
                file_put_contents($this->root.'/categories-'.$locale.'.json', $this->getJson('/control/categories?locale='.$locale)->assertOk()->getContent());
                app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
                file_put_contents($this->root.'/public/settings-'.$locale.'.html', $this->get('/control/settings?locale='.$locale.'&content_locale=vi')->assertOk()->getContent());
                file_put_contents($this->root.'/settings-page-'.$locale.'.json', $this->getJson('/control/settings?locale='.$locale.'&content_locale=vi')->assertOk()->getContent());
                app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
                file_put_contents($this->root.'/public/users-'.$locale.'.html', $this->get('/control/users?locale='.$locale.'&per_page=10')->assertOk()->getContent());
                file_put_contents($this->root.'/users-page-'.$locale.'.json', $this->getJson('/control/users?locale='.$locale.'&per_page=10')->assertOk()->getContent());
                app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
                file_put_contents($this->root.'/public/languages-'.$locale.'.html', $this->get('/control/languages?locale='.$locale)->assertOk()->getContent());
                file_put_contents($this->root.'/languages-page-'.$locale.'.json', $this->getJson('/control/languages?locale='.$locale)->assertOk()->getContent());
                app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
                file_put_contents($this->root.'/public/system-'.$locale.'.html', $this->get('/control/settings?group=system&locale='.$locale)->assertOk()->getContent());
                file_put_contents($this->root.'/system-page-'.$locale.'.json', $this->getJson('/control/settings?group=system&locale='.$locale)->assertOk()->getContent());
                file_put_contents($this->root.'/system-api.json', $this->getJson('/control/settings/system')->assertOk()->getContent());
                app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
                file_put_contents($this->root.'/public/media-'.$locale.'.html', $this->get('/control/media?locale='.$locale)->assertOk()->getContent());
                file_put_contents($this->root.'/media-page-'.$locale.'.json', $this->getJson('/control/media?locale='.$locale)->assertOk()->getContent());
                app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
                file_put_contents($this->root.'/public/branding-'.$locale.'.html', $this->get('/control/settings?group=branding&locale='.$locale)->assertOk()->getContent());
                file_put_contents($this->root.'/branding-page-'.$locale.'.json', $this->getJson('/control/settings?group=branding&locale='.$locale)->assertOk()->getContent());
                file_put_contents($this->root.'/media-api.json', $this->getJson('/control/_api/media')->assertOk()->getContent());
                file_put_contents($this->root.'/branding-api.json', $this->getJson('/control/settings/branding')->assertOk()->getContent());
                file_put_contents($this->root.'/settings-api.json', $this->getJson('/control/settings/general')->assertOk()->getContent());
                file_put_contents($this->root.'/dictionary-'.$locale.'.json', $this->getJson('/saola/languages/admin/'.$locale)->assertOk()->getContent());
            }
            file_put_contents($this->root.'/users-page2-en.json', $this->getJson('/control/users?locale=en&per_page=10&page=2')->assertOk()->getContent());
            file_put_contents($this->root.'/users-filtered-en.json', $this->getJson('/control/users?locale=en&per_page=10&q=Member%2002')->assertOk()->getContent());
            file_put_contents($this->root.'/composer.json', json_encode(['name' => 'saola/admin-workbench', 'autoload' => ['psr-4' => ['Workbench\\' => 'app/']]], JSON_THROW_ON_ERROR));
            $runtimeConfig = [];
            foreach (['app', 'auth', 'database', 'cache', 'session', 'view', 'filesystems', 'logging', 'sao', 'saola-admin'] as $section) $runtimeConfig[$section] = config($section, []);
            file_put_contents($this->root.'/workbench-config.json', json_encode($runtimeConfig, JSON_THROW_ON_ERROR));
            app('db')->connection()->statement('VACUUM INTO ?', [$this->root.'/workbench.sqlite']);
            copy(__DIR__.'/../Fixtures/host/sao.config.json', $this->root.'/sao.config.json');
            (new Filesystem())->copyDirectory($this->root, $destination);
        }
        // Exercise full-document SSR as well as the separate CSR form tests.
        app(PageRegistry::class)->register(MemberResource::class);
        foreach (['create', 'view', 'update'] as $ability) Gate::define($ability, fn () => true);
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/members/create?locale=vi')->assertOk()->assertSee('resource-field-name', false)
            ->assertSee('resource-field-password', false)->assertSee('method="post"', false)->assertDontSee('never-send-hash');
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/members/1/edit?locale=vi')->assertOk()->assertSee('Member 01')->assertDontSee('never-send-hash');
        app(PageRegistry::class)->register(InputPreviewResource::class);
        Gate::define('viewAny', fn () => true);
        foreach (['classic', 'material'] as $themeName) {
            config(['saola-admin.panel.theme' => $themeName]);
            app(\Saola\Admin\Themes\AdminTheme::class)->apply();
            app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
            $html = $this->get('/control/input-preview/create?locale=vi')->assertOk();
            foreach (array_keys(\Saola\Admin\Resources\Input::TEMPLATES) as $template) $html->assertSee('data-input-template="'.$template.'"', false);
            $html->assertDontSee('<script>unsafe()', false)->assertSee('&lt;script&gt;unsafe()', false);
            if ($destination = getenv('SAOLA_ADMIN_PREVIEW_DIR')) file_put_contents($destination.'/public/inputs-'.$themeName.'.html', $html->getContent());
        }
        config(['saola-admin.panel.theme' => 'classic']);
        app(\Saola\Admin\Themes\AdminTheme::class)->apply();
        copy(__DIR__.'/../Fixtures/host/sao.config.json', $this->root.'/sao.config.json');
        (new Filesystem())->ensureDirectoryExists($this->root.'/public/static/saola/admin/assets');
        file_put_contents($this->root.'/public/static/saola/admin/manifest.json', json_encode([
            'resources/js/saola/app.admin.js' => ['file' => 'assets/admin.js'],
            'resources/saola/admin/themes/classic/assets/css/admin.css' => ['file' => 'assets/admin.css'],
        ]));
        app(\Saola\Core\Services\BundleManifest::class)->reset();
        $this->artisan('admin:doctor')->expectsOutputToContain('build file is missing')->assertFailed();
        file_put_contents($this->root.'/public/static/saola/admin/assets/admin.js', '// doctor existence fixture');
        file_put_contents($this->root.'/public/static/saola/admin/assets/admin.css', '/* doctor existence fixture */');
        $this->artisan('admin:doctor')->assertSuccessful();
        // Source mode uses the built package metadata and compiled renderers;
        // an authored publication/bootstrap is no longer required.
        (new Filesystem())->deleteDirectory($this->root.'/resources/saola/admin');
        $this->app->instance(\Saola\Core\Services\BundleManifest::class, new class extends \Saola\Core\Services\BundleManifest {
            public function contextBuild(string $context): array { return ['package' => 'saola/admin']; }
            public function source(string $context, string $logical): string { return $logical; }
        });
        file_put_contents($this->root.'/sao.config.json', json_encode(['contexts' => ['admin' => new \stdClass()]]));
        $this->artisan('admin:doctor')->assertSuccessful();
    }
}

class CategoryPage implements Page
{
    public function key(): string { return 'categories'; }
    public function titleKey(): string { return 'admin.resource.records'; }
    public function ability(): string { return 'view-categories'; }
    public function document(Request $request): ResourceDocument
    {
        return new ResourceDocument($this->titleKey(), 'admin.workspace.description', [], [
            ['key' => 'name', 'labelKey' => 'admin.workspace.website'],
        ], [['name' => '<b>Category</b>', 'secret' => 'must not serialize']]);
    }
}

class HostScreenPage extends CategoryPage implements \Saola\Admin\Pages\LinkedPage
{
    public function key(): string { return 'host-screen'; }
    public function url(): string { return '/control/ai/providers'; }
}

class GroupedCategoryPage extends CategoryPage implements \Saola\Admin\Pages\GroupedPage
{
    public function key(): string { return 'grouped-categories'; }
    public function navigationGroup(): string { return 'content'; }
    public function navigationGroupTitleKey(): string { return 'admin.resource.records'; }
}

class GroupedArticlePage extends GroupedCategoryPage
{
    public function key(): string { return 'articles'; }
    public function ability(): string { return 'view-articles'; }
}

class MemberResource extends \Saola\Admin\Resources\Resource
{
    public function key(): string { return 'members'; }
    public function titleKey(): string { return 'admin.users.title'; }
    public function ability(): string { return 'access-admin'; }
    public function model(): string { return \Saola\Admin\Tests\Fixtures\DirectoryUser::class; }
    public function query(Request $request): \Illuminate\Database\Eloquent\Builder { return \Saola\Admin\Tests\Fixtures\DirectoryUser::where('tenant_id', $request->user()->tenant_id); }
    public function fields(): array
    {
        return ['name' => ['labelKey' => 'admin.users.name'], 'email' => ['labelKey' => 'admin.users.email', 'type' => 'email'],
            'password' => ['labelKey' => 'admin.resource.password', 'type' => 'password', 'writeOnly' => true]];
    }
}

class InputPreviewRecord extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'directory_users';
    protected $casts = ['field_multiselect' => 'array', 'field_checkboxes' => 'array', 'field_matrix' => 'array', 'field_tags' => 'array', 'field_json' => 'array'];
}
class InputPreviewResource extends \Saola\Admin\Resources\Resource
{
    public function key(): string { return 'input-preview'; }
    public function titleKey(): string { return 'admin.resource.details'; }
    public function ability(): string { return 'access-admin'; }
    public function model(): string { return InputPreviewRecord::class; }
    public function query(Request $request): \Illuminate\Database\Eloquent\Builder { return InputPreviewRecord::query(); }
    public function fields(): array
    {
        $fields = [];
        foreach (\Saola\Admin\Resources\Input::TEMPLATES as $template => $type) {
            $fields['field_'.$template] = \Saola\Admin\Resources\Input::make($template, 'admin.resource.details', [
                'options' => ['one' => 'admin.input.yes', 'two' => 'admin.input.no'], 'writeOnly' => $type === 'password',
                'default' => \Saola\Admin\Resources\Input::multiple($type) ? ['one'] : ($type === 'richtext' ? '<script>unsafe()</script>' : ''),
            ]);
        }
        return $fields;
    }
    public function fieldMatrix(string $key, Request $request, ?\Illuminate\Database\Eloquent\Model $record): array
    {
        return ['columns' => ['view' => 'admin.input.yes', 'edit' => 'admin.input.no'], 'groups' => [['label' => 'Group', 'rows' => [['label' => 'Row', 'cells' => ['view' => 'one', 'edit' => 'two']]]]]];
    }
}

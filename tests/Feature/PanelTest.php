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
        $this->assertSame('admin.pages.resource', $response->json('view'));
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

    public function test_ssr_compiles_the_published_source_and_uses_the_same_dictionary_and_css_contract(): void
    {
        $this->artisan('admin:doctor')->expectsOutputToContain('Run admin:install')->assertFailed();
        $this->artisan('admin:install', ['--dry-run' => true])->assertSuccessful();
        $this->assertDirectoryDoesNotExist($this->root.'/resources/saola');
        $this->artisan('admin:install')->assertSuccessful();
        $source = $this->root.'/resources/saola/admin/views';
        foreach ((new Filesystem())->allFiles($source) as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1, -4);
            $name = 'Admin'.str_replace(' ', '', ucwords(str_replace(['/', '-', '_'], ' ', $relative)));
            $result = (new \Saola\Compiler\SaolaCompiler())->compile(file_get_contents($file), new \Saola\Compiler\CompileOptions(
                viewPath: 'admin.'.str_replace('/', '.', $relative), functionName: $name, factoryName: $name, lang: \Saola\Compiler\Lang::Ts,
            ));
            $out = $this->root.'/resources/views/admin/'.$relative.'.blade.php';
            (new Filesystem())->ensureDirectoryExists(dirname($out)); file_put_contents($out, $result->blade);
            $js = $this->root.'/resources/js/saola/admin/views/'.$relative.'.ts';
            (new Filesystem())->ensureDirectoryExists(dirname($js)); file_put_contents($js, $result->js);
        }
        (new Filesystem())->copyDirectory(__DIR__.'/../Fixtures/host/resources/views/_system', $this->root.'/resources/views/_system');
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7]));
        $response = $this->get('/control?locale=vi')->assertOk();
        $response->assertSee('Tổng quan')->assertSee('Workbench')->assertSee('window.APP_CONFIGS=', false)
            ->assertSee('admin.pages.resource', false)->assertSee('admin:', false);
        $this->assertSame('resources/saola/admin/assets/css/admin.css', config('sao.assets.css.admin'));
        $this->assertCount(3, SPA::getRoutes('admin'));
        $routes = app(\Saola\Core\View\Services\ViewHelperService::class)->exportComponentRoutes('admin');
        $this->assertSame('admin.pages.settings', $routes[0]['component']);
        $this->assertArrayHasKey('saola.admin.page', $this->app['router']->getRoutes()->compile()['attributes']);
        $this->getJson('/control/settings')->assertForbidden();
        Gate::define('view-site-settings', fn () => true);
        Gate::define('update-site-settings', fn () => true);
        $this->getJson('/control/settings?content_locale=vi')->assertOk()
            ->assertJsonPath('view', 'admin.pages.settings')
            ->assertJsonPath('data.editor.contentLocale', 'vi')->assertJsonPath('data.editor.fields.0.value', 'Workbench')
            ->assertJsonPath('data.editor.canUpdate', true)->assertJsonCount(4, 'data.navigation');
        $this->getJson('/control/settings?group=missing')->assertNotFound();
        $this->getJson('/control/settings?content_locale=ja')->assertUnprocessable();
        app(\Saola\Core\View\Services\ViewHelperService::class)->reset();
        $this->get('/control/settings?locale=vi&content_locale=en')->assertOk()
            ->assertSee('Thiết lập website')->assertSee('Ngôn ngữ nội dung')->assertSee('settings-save', false);
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
        copy(__DIR__.'/../Fixtures/host/sao.config.json', $this->root.'/sao.config.json');
        (new Filesystem())->ensureDirectoryExists($this->root.'/public/static/saola/admin/assets');
        file_put_contents($this->root.'/public/static/saola/admin/manifest.json', json_encode([
            'resources/js/saola/app.admin.js' => ['file' => 'assets/admin.js', 'css' => ['assets/admin.css']],
        ]));
        app(\Saola\Core\Services\BundleManifest::class)->reset();
        $this->artisan('admin:doctor')->expectsOutputToContain('build file is missing')->assertFailed();
        file_put_contents($this->root.'/public/static/saola/admin/assets/admin.js', '// doctor existence fixture');
        file_put_contents($this->root.'/public/static/saola/admin/assets/admin.css', '/* doctor existence fixture */');
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

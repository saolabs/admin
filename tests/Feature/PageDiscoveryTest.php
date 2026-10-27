<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Schema};
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Pages\{PageDiscovery, PageRegistry};
use Saola\Core\Support\SPA;

/** Pages under app/Modules/*\/{Resources,Pages} register themselves: no config list, menu data lives on the class. */
class PageDiscoveryTest extends TestCase
{
    private const APP = __DIR__.'/../Fixtures/discovery';

    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        spl_autoload_register(function (string $class) {
            if (str_starts_with($class, 'App\\') && is_file($file = self::APP.'/'.str_replace('\\', '/', substr($class, 4)).'.php')) require_once $file;
        }, true, true);
        $app->useAppPath(self::APP);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('saola-admin.panel.enabled', true);
        $app['config']->set('saola-admin.panel.prefix', 'control');
        $app['config']->set('saola-admin.panel.pages', []);
    }
    protected function setUp(): void
    {
        SPA::resetRoutes(); parent::setUp();
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        Schema::create('shop_items', function (Blueprint $table) { $table->id(); $table->string('name'); $table->timestamps(); });
        Gate::define('access-admin', fn () => true);
        Gate::define('shop.reports.view', fn () => true);
        Gate::define('shop.items.view', fn () => true);
        Gate::define('shop.items.create', fn ($user) => $user->id === 1);
        $this->actingAs(new GenericUser(['id' => 2]));
    }
    protected function tearDown(): void { SPA::resetRoutes(); parent::tearDown(); }

    public function test_discovers_instantiable_pages_and_builds_the_menu_from_the_classes(): void
    {
        $this->assertSame(['App\Modules\Shop\Resources\ItemResource', 'App\Modules\Shop\Pages\HiddenPage', 'App\Modules\Shop\Pages\ReportPage'], PageDiscovery::classes());
        $registry = app(PageRegistry::class);
        $this->assertSame(['items', 'hidden', 'reports'], array_keys($registry->classes()));
        $request = Request::create('/control'); $request->setUserResolver(fn () => auth()->user());
        $menu = $registry->navigation($request);
        // Ordered by navigation()['order'], hidden pages stay out of the menu (but stay registered), icon travels with the item.
        $this->assertSame(['reports', 'items'], array_column($menu, 'key'));
        $this->assertSame('ri-store-line', $menu[1]['icon']);
        $this->assertArrayNotHasKey('order', $menu[0]);
        $this->assertSame('reports', $registry->keyForModule('shop-reports'));
        $this->assertSame('', $registry->keyForModule('unknown'));
    }

    public function test_ability_prefix_replaces_the_model_policy(): void
    {
        $this->getJson('/control/_api/resources/items')->assertOk()->assertJsonPath('resource.createUrl', '');
        $this->postJson('/control/_api/resources/items', ['requestId' => (string) Str::uuid(), 'values' => ['name' => 'Nope']])->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->getJson('/control/_api/resources/items')->assertOk()->assertJsonPath('resource.createUrl', '/control/items/create');
        $this->postJson('/control/_api/resources/items', ['requestId' => (string) Str::uuid(), 'values' => ['name' => 'Lamp']])->assertCreated();
        // update is not granted: no edit right, no write.
        $this->getJson('/control/_api/resources/items')->assertJsonPath('resource.rows.0.actions.0.labelKey', 'admin.resource.view');
        Gate::define('shop.items.view', fn () => false);
        $this->getJson('/control/_api/resources/items')->assertForbidden();
    }
}

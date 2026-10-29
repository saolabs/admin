<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Route, Schema};
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Saola\Admin\Admin;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Http\AdminAccess;
use Saola\Core\Database\Repositories\BaseRepository;
use Saola\Core\Models\Model;
use Saola\Core\Services\ModuleService;
use Saola\Core\Support\SPA;
use Saola\Core\System;

class Gadget extends Model { use HasUuids; protected $table = 'gadgets'; protected $guarded = []; protected $deleteMode = 'soft'; }
class GadgetRepository extends BaseRepository { protected $model = Gadget::class; }
class GadgetService extends ModuleService
{
    protected $repositoryClass = GadgetRepository::class;
    public static array $saved = [];
    public function tidy(array $values, Request $request, $record): array { if (is_string($values['name'] ?? null)) $values['name'] = trim($values['name']); return $values; }
    public function colours(Request $request, $record): array { return ['red' => ['label' => 'Red'], 'blue' => ['label' => 'Blue']]; }
    /** Save hook: sees values that are not columns (persist => false) through the request. */
    public function saveFromAdmin($record, array $values, Request $request)
    {
        static::$saved[] = $request->input('values.note');
        return $record ? $this->update($record, $values) : $this->create($values);
    }
}
/** As a module would write it: extends the project's admin base, which extends the package's. */
class GadgetController extends \Saola\Admin\Http\AdminController {}

/** A module registers arrays in its provider; the package serves list/form through the module's routes and service. */
class RegistryCrudTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('saola-admin.panel.enabled', true);
        $app['config']->set('saola-admin.panel.prefix', 'control');
    }

    protected function setUp(): void
    {
        SPA::resetRoutes(); parent::setUp();
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        Schema::create('gadgets', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('name')->unique(); $t->string('colour')->nullable(); $t->string('serial')->nullable(); $t->timestamps(); $t->softDeletes(); });
        GadgetService::$saved = [];
        Gate::define('access-admin', fn () => true);
        Gate::define('viewAny', fn () => true);
        foreach (['view', 'create', 'update', 'delete', 'restore'] as $op) Gate::define('shop.gadgets.'.$op, fn () => true);
        Gate::define('see-serial', fn () => false);

        // --- what a module's ModuleServiceProvider::boot() would contain ---
        Admin::menu('gadgets', ['title' => 'admin.resource.records', 'route' => 'admin.gadgets.index', 'ability' => 'shop.gadgets.view', 'icon' => 'list']);
        Admin::list('gadgets', ['service' => GadgetService::class, 'columns' => ['name' => ['label' => 'admin.users.name'], 'colour' => ['label' => 'admin.resource.details']], 'search' => ['name'], 'sort' => ['name']]);
        Admin::form('gadgets', [
            'service' => GadgetService::class,
            'save' => [GadgetService::class, 'saveFromAdmin'],
            'prepare' => [GadgetService::class, 'tidy'],
            'messages' => ['required' => 'admin.resource.details'],
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'admin.users.name', 'rules' => ['required', 'max:20', 'unique:gadgets,name,{id}'], 'messages' => ['unique' => 'admin.users.email']],
                'colour' => ['type' => 'select', 'label' => 'admin.resource.details', 'options' => [GadgetService::class, 'colours']],
                'serial' => ['type' => 'text', 'label' => 'admin.resource.details', 'ability' => 'see-serial'],
                'note' => ['type' => 'text', 'label' => 'admin.resource.details', 'persist' => false],
            ],
        ]);
        Route::middleware(['web', AdminAccess::class])->prefix('control/gadgets')->name('admin.gadgets.')->group(fn () => GadgetController::routes());
        Route::getRoutes()->refreshNameLookups(); Route::getRoutes()->refreshActionLookups();
        $this->actingAs(new GenericUser(['id' => 1]));
    }
    protected function tearDown(): void { SPA::resetRoutes(); System::reset(); parent::tearDown(); }

    private function create(array $values) { return $this->postJson('/control/gadgets/_api', ['requestId' => (string) Str::uuid(), 'values' => $values]); }

    public function test_create_goes_through_the_module_service_with_core_events_and_non_column_values(): void
    {
        $events = [];
        GadgetRepository::on('created', function () use (&$events) { $events[] = 'created'; });
        $id = $this->create(['name' => 'Lamp', 'colour' => 'red', 'note' => 'from the form'])->assertCreated()->json('id');
        $this->assertSame(['created'], $events);
        $this->assertSame(['from the form'], GadgetService::$saved);
        $this->assertDatabaseHas('gadgets', ['id' => $id, 'name' => 'Lamp', 'colour' => 'red']);
        $this->getJson('/control/gadgets')->assertOk()->assertJsonPath('data.management.rows.0.values.name', 'Lamp')->assertJsonPath('data.management.createUrl', '/control/gadgets/create');
    }

    public function test_rules_messages_options_and_field_ability(): void
    {
        $this->create(['name' => 'Lamp'])->assertCreated();
        $this->create(['colour' => 'red'])->assertUnprocessable()->assertJsonPath('errors.name.0', text('admin.resource.details'));       // form-level "required"
        $this->create(['name' => ' Lamp '])->assertUnprocessable()->assertJsonPath('errors.name.0', text('admin.users.email'));              // prepare runs before "unique"
        $this->create(['name' => str_repeat('x', 21)])->assertUnprocessable();
        $this->create(['name' => 'Desk', 'colour' => 'green'])->assertUnprocessable();                                                        // not an option of the service
        $this->create(['name' => 'Desk', 'serial' => 'S-1'])->assertUnprocessable();                                                         // field the user may not write
        $id = $this->create(['name' => 'Desk'])->assertCreated()->json('id');

        $editor = $this->getJson('/control/gadgets/'.$id.'/edit')->assertOk()->json('data.management');
        $fields = array_column($editor['fields'], null, 'key');
        $this->assertArrayNotHasKey('serial', $fields);
        $this->assertSame(['red', 'blue'], array_column($fields['colour']['options'], 'value'));
        $this->assertSame(['required', 'max:20'], $fields['name']['rules']);                 // the browser checks these; unique stays on the server
        $this->assertSame(['required' => 'admin.resource.details'], $fields['name']['messages']);  // "unique" is checked (and worded) by the server only
        // unique:…,{id} ignores the record being edited
        $this->putJson('/control/gadgets/_api/'.$id, ['revision' => $editor['revision'], 'values' => ['name' => 'Desk', 'colour' => 'blue']])->assertOk();
        $this->assertDatabaseHas('gadgets', ['id' => $id, 'colour' => 'blue']);
    }

    public function test_module_builder_declares_menu_list_form_next_to_the_routes(): void
    {
        Route::aliasMiddleware('next', \Saola\Core\Http\Middleware\Next::class);
        System::context('admin', ['as' => 'admin', 'slug' => 'admin', 'prefix' => 'control', 'middleware' => ['web', AdminAccess::class]])
            ->module('shop')->menu(['title' => 'admin.resource.records', 'icon' => 'list', 'order' => 5])      // has a sub-menu → group
            ->sub('items')->controller(GadgetController::class)
            ->menu(['title' => 'admin.resource.details', 'ability' => 'shop.gadgets.view'])
            ->list(['service' => GadgetService::class, 'columns' => ['name' => ['label' => 'admin.users.name']], 'sort' => ['name']])
            ->form(['service' => GadgetService::class, 'fields' => ['name' => ['type' => 'text', 'label' => 'admin.users.name', 'rules' => ['required', 'max:20', 'unique:gadgets,name,{id}']]]])
            ->routes(fn ($m) => GadgetController::routes($m));
        System::context('admin')->pushLaravelRoute();
        Route::getRoutes()->refreshNameLookups(); Route::getRoutes()->refreshActionLookups();
        $registry = app(\Saola\Admin\Registry\AdminRegistry::class); $registry->verify();

        $this->assertSame(['shop.items', 'api.item'], $registry->routeKey('admin.shop.items.api.item'));
        $this->assertSame('admin.shop.items.index', $registry->menuOf('shop.items')['route']);
        $shopNode = collect(System::getContext('admin')->toArray()['children'])->firstWhere('slug', 'shop');
        $itemsNode = collect($shopNode['children'])->firstWhere('slug', 'items');
        $operations = array_column($itemsNode['children'], 'permission', 'as');
        $this->assertSame(['read'], $operations['admin.shop.items.index']);
        $this->assertSame(['read', 'create'], $operations['admin.shop.items.create']);
        $this->assertSame(['read', 'create'], $operations['admin.shop.items.api']);
        $this->assertSame(['read', 'update'], $operations['admin.shop.items.api.item']);
        $this->assertSame(['read'], $operations['admin.shop.items.api.state']); // delete/restore depends on operation and record policy
        $shop = collect($registry->navigation(request()->setUserResolver(fn () => new GenericUser(['id' => 1]))))->firstWhere('key', 'shop');
        $this->assertSame(['shop.items'], array_column($shop['children'], 'key'));
        $this->assertSame('/control/shop/items', $shop['url']);

        $this->postJson('/control/shop/items/_api', ['requestId' => (string) Str::uuid(), 'values' => ['name' => 'Desk']])->assertCreated();
        $page = $this->getJson('/control/shop/items')->assertOk()->assertJsonPath('data.management.rows.0.values.name', 'Desk');
        $this->assertSame('shop.items', $page->json('data.currentPage'));
        $this->getJson('/control/shop/items/_api')->assertStatus(405);                                   // reads go through the page URL
        $this->assertSame(['required', 'max:20'], $this->getJson('/control/shop/items/create')->json('data.management.fields.0.rules')); // unique stays on the server
        $this->assertSame(['required', 'in:a,b', 'between:1,5'], \Saola\Admin\Registry\AdminRegistry::clientRules(['required', 'in:a,b', 'between:1,5', 'regex:/^x$/', 'exists:users,id', 'required_if:x,y', new \Illuminate\Validation\Rules\In(['a'])]));

        $this->expectException(\InvalidArgumentException::class);
        System::context('web', ['as' => 'web'])->module('blog')->list(['service' => GadgetService::class, 'columns' => ['name' => ['label' => 'x']]]);
    }

    public function test_pages_render_on_module_routes_and_the_menu_comes_from_the_registry(): void
    {
        Gate::define('access-admin', fn () => true);
        $page = $this->getJson('/control/gadgets')->assertOk()->assertJsonPath('view', 'admin.themes.classic.pages.resource')->assertJsonPath('data.currentPage', 'gadgets');
        $this->assertContains('gadgets', array_column($page->json('data.navigation'), 'key'));
        $this->getJson('/control/gadgets/create')->assertOk()->assertJsonPath('data.management.mode', 'create');
        $this->assertContains('admin.gadgets.index', array_column(SPA::getRoutes('admin'), 'name'));
        Gate::define('shop.gadgets.view', fn () => false);
        $this->getJson('/control/gadgets')->assertForbidden();
        $this->getJson('/control/gadgets')->assertForbidden();
    }

    public function test_install_points_the_project_admin_controller_at_the_package(): void
    {
        $dir = app_path('Http/Controllers');
        @mkdir($dir, 0755, true);
        $file = $dir.'/AdminController.php';
        $backup = is_file($file) ? file_get_contents($file) : null;
        try {
            file_put_contents($file, "<?php\n\nnamespace App\\Http\\Controllers;\n\nabstract class AdminController extends \\Saola\\Core\\Http\\Controllers\\AdminController\n{\n}\n");
            $this->artisan('admin:install', ['--dry-run' => true]);
            $this->assertStringContainsString('extends \\Saola\\Core\\Http\\Controllers\\AdminController', file_get_contents($file));
            $this->artisan('admin:install');
            $this->assertStringContainsString('extends \\Saola\\Admin\\Http\\AdminController', file_get_contents($file));
        } finally {
            $backup === null ? @unlink($file) : file_put_contents($file, $backup);
        }
    }
}

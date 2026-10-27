<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Pages\{ModulePage, NavigablePage};
use Saola\Admin\Resources\Resource;

/** Generators write discoverable files whose schema is valid as generated. */
class MakeCommandsTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $this->app_dir = sys_get_temp_dir().'/saola-make-'.bin2hex(random_bytes(4));
        mkdir($this->app_dir.'/Modules/Shop/Models', 0755, true);
        file_put_contents($this->app_dir.'/Modules/Shop/Models/Gadget.php', "<?php\nnamespace App\\Modules\\Shop\\Models;\nclass Gadget extends \\Illuminate\\Database\\Eloquent\\Model { protected \$guarded = []; }\n");
        $dir = $this->app_dir;
        spl_autoload_register(function (string $class) use ($dir) {
            if (str_starts_with($class, 'App\\') && is_file($file = $dir.'/'.str_replace('\\', '/', substr($class, 4)).'.php')) require_once $file;
        }, true, true);
        $app->useAppPath($dir);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }
    private string $app_dir;
    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->app_dir)); exec('rm -rf '.escapeshellarg(resource_path('saola/admin/views/modules/stock')));
        parent::tearDown();
    }

    public function test_make_resource_reads_the_table_and_produces_a_valid_schema(): void
    {
        Schema::create('gadgets', function (Blueprint $table) {
            $table->id(); $table->string('name', 80); $table->string('email')->nullable(); $table->text('notes')->nullable();
            $table->boolean('active')->default(true); $table->integer('stock')->default(0); $table->decimal('price', 8, 2);
            $table->foreignId('owner_id')->nullable(); $table->timestamps();
        });
        $this->artisan('admin:make:resource', ['name' => 'Shop/Gadget', '--ability' => 'shop.gadgets'])
            ->expectsOutputToContain('Skipped owner_id')->assertSuccessful();
        require_once $this->app_dir.'/Modules/Shop/Resources/GadgetResource.php';
        $resource = app('App\Modules\Shop\Resources\GadgetResource');
        $this->assertInstanceOf(Resource::class, $resource);
        $schema = $resource->schema();
        $this->assertSame(['name', 'email', 'notes', 'active', 'stock', 'price'], array_keys($schema));
        $this->assertSame(['text', 'email', 'textarea', 'boolean', 'integer', 'number'], array_column($schema, 'type'));
        // SQLite reports plain varchar (no length); PostgreSQL/MySQL give varchar(80) and the generator keeps 80.
        $this->assertContains($schema['name']['maxLength'], [80, 255]);
        $this->assertTrue($schema['name']['required']); $this->assertFalse($schema['email']['required'] ?? false);
        $this->assertSame(['name', 'email', 'active'], array_column($resource->columns(), 'key'));
        $this->assertSame(['name', 'email'], $resource->searchable());
        $this->assertSame('shop.gadgets.view', $resource->ability());
        $this->assertSame('gadgets', $resource->key());
        $this->artisan('admin:make:resource', ['name' => 'Shop/Gadget'])->assertFailed();
    }

    public function test_make_screen_writes_page_controller_and_view(): void
    {
        $this->artisan('admin:make:screen', ['name' => 'Shop/stock'])->expectsOutputToContain("->as('stock')")->assertSuccessful();
        require_once $this->app_dir.'/Modules/Shop/Pages/StockPage.php';
        $page = app('App\Modules\Shop\Pages\StockPage');
        $this->assertInstanceOf(ModulePage::class, $page); $this->assertInstanceOf(NavigablePage::class, $page);
        $this->assertSame('stock', $page->module());
        $this->assertStringContainsString('extends HostScreenController', file_get_contents($this->app_dir.'/Modules/Shop/Http/Controllers/Admin/StockController.php'));
        $this->assertStringContainsString("text('shop.stock.title')", file_get_contents(resource_path('saola/admin/views/modules/stock/index.sao')));
    }
}

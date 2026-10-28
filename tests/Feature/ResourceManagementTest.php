<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\{Model, SoftDeletes, Builder};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Resources\Resource;
use Saola\Core\Support\SPA;

class ResourceManagementTest extends TestCase
{
    use \Saola\Admin\Tests\Support\RegistersCrud;

    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('saola-admin.panel.enabled', true);
        $app['config']->set('saola-admin.panel.prefix', 'control');
    }
    protected function setUp(): void
    {
        SPA::resetRoutes(); parent::setUp();
        $this->crud('categories', ManagedCategory::class);
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        Schema::create('managed_records', function (Blueprint $table) {
            $table->id(); $table->integer('tenant_id'); $table->string('name'); $table->string('secret')->default('server-secret'); $table->timestamps(); $table->softDeletes();
        });
        Gate::define('access-admin', fn () => true);
        foreach (['viewAny', 'view', 'viewTrash'] as $ability) Gate::define($ability, fn () => true);
        foreach (['create', 'update', 'delete', 'restore'] as $ability) Gate::define($ability, fn ($user) => $user->id === 1);
        Gate::define('forceDelete', fn () => false);
        ManagedRecord::create(['tenant_id' => 7, 'name' => 'Visible']);
        ManagedRecord::create(['tenant_id' => 8, 'name' => 'Outside']);
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7]));
    }
    protected function tearDown(): void { SPA::resetRoutes(); parent::tearDown(); }
    private function revision(string $id = '1'): string { return $this->getJson('/control/categories/'.$id.'/edit')->assertOk()->json('data.management.revision'); }
    private function createBody(string $name): array { return ['requestId' => (string) Str::uuid(), 'values' => ['name' => $name]]; }

    public function test_scope_projection_list_trash_search_and_permissions_are_applied_server_side(): void
    {
        $this->getJson('/control/categories')->assertOk()->assertJsonPath('data.management.counts.active', 1)
            ->assertJsonPath('data.management.rows.0.values.name', 'Visible')->assertJsonMissing(['secret' => 'server-secret']);
        $this->getJson('/control/categories/2/edit')->assertNotFound();
        $this->getJson('/control/categories/not-an-id/edit')->assertNotFound();
        $this->getJson('/control/categories/9999999999999999999999999/edit')->assertNotFound();
        $this->putJson('/control/categories/_api/2', ['revision' => str_repeat('a', 64), 'values' => ['name' => 'Leak']])->assertNotFound();
        $this->getJson('/control/categories?sort=secret')->assertUnprocessable();
        $this->getJson('/control/categories?q=%25')->assertOk()->assertJsonCount(0, 'data.management.rows');
        $this->actingAs(new GenericUser(['id' => 2, 'tenant_id' => 7]));
        $this->getJson('/control/categories')->assertOk()->assertJsonPath('data.management.createUrl', '');
        $this->postJson('/control/categories/_api', $this->createBody('Denied'))->assertForbidden();
        $this->postJson('/control/categories/_api/1/trash', ['revision' => $this->revision()])->assertForbidden();
        Gate::define('viewTrash', fn () => false);
        $this->getJson('/control/categories?status=trash')->assertForbidden();
    }
    public function test_create_is_validated_idempotent_scoped_and_audited_without_values(): void
    {
        $body = $this->createBody('New');
        $created = $this->postJson('/control/categories/_api', $body)->assertCreated()->json('id');
        $this->assertDatabaseHas('managed_records', ['id' => $created, 'tenant_id' => 7, 'name' => 'New']);
        $this->postJson('/control/categories/_api', $body)->assertCreated()->assertJsonPath('id', $created)->assertJsonPath('replayed', true);
        $this->postJson('/control/categories/_api', [...$body, 'values' => ['name' => 'Changed']])->assertConflict();
        $this->postJson('/control/categories/_api', [...$this->createBody('X'), 'values' => ['tenant_id' => 8, 'name' => 'X']])->assertUnprocessable();
        $this->assertDatabaseCount('saola_resource_events', 1);
        $audit = json_encode(app('db')->table('saola_resource_events')->get());
        $this->assertStringNotContainsString('server-secret', $audit);
        $this->assertStringNotContainsString('New', $audit);
    }
    public function test_update_does_not_depend_on_timestamp_precision_and_stale_writes_conflict(): void
    {
        $revision = $this->revision();
        $this->putJson('/control/categories/_api/1', ['revision' => $revision, 'values' => ['name' => 'Updated']])->assertOk();
        $this->putJson('/control/categories/_api/1', ['revision' => $revision, 'values' => ['name' => 'Stale']])->assertConflict();
        $this->assertDatabaseHas('managed_records', ['id' => 1, 'name' => 'Updated']);
        $this->putJson('/control/categories/_api/1', ['revision' => $this->revision(), 'values' => ['secret' => 'Injected']])->assertUnprocessable();
    }
    public function test_trash_restore_and_stale_transition_keep_identity_and_scope(): void
    {
        $revision = $this->revision();
        $this->postJson('/control/categories/_api/1/trash', ['revision' => $revision])->assertOk()->assertJsonPath('affected', 1);
        $this->getJson('/control/categories')->assertOk()->assertJsonPath('data.management.counts.active', 0)->assertJsonPath('data.management.counts.trash', 1);
        $trash = $this->getJson('/control/categories?status=trash')->assertOk()->json('data.management.rows.0');
        $this->assertSame('1', $trash['id']);
        $this->getJson('/control/categories/1/edit')->assertNotFound();
        $this->postJson('/control/categories/_api/1/restore', ['revision' => $revision])->assertConflict();
        $this->postJson('/control/categories/_api/1/restore', ['revision' => $trash['revision']])->assertOk();
        $this->assertSame('Visible', ManagedRecord::findOrFail(1)->name);
        $this->putJson('/control/categories/_api/1', ['revision' => $revision, 'values' => ['name' => 'Before trash']])->assertConflict();
        $this->postJson('/control/categories/_api/1/purge', ['revision' => $this->revision()])->assertNotFound();
        $this->assertDatabaseCount('saola_resource_events', 2);
    }
    public function test_bulk_is_atomic_if_one_record_is_outside_scope_or_stale(): void
    {
        $three = ManagedRecord::create(['tenant_id' => 7, 'name' => 'Three']);
        $items = [['id' => '1', 'revision' => $this->revision()], ['id' => (string) $three->id, 'revision' => str_repeat('b', 64)]];
        $this->postJson('/control/categories/_api/bulk', ['operation' => 'trash', 'items' => $items])->assertConflict();
        $this->assertSame(2, ManagedRecord::where('tenant_id', 7)->count());
        $this->assertDatabaseCount('saola_resource_events', 0);
        $items[1]['revision'] = $this->revision((string) $three->id);
        $this->postJson('/control/categories/_api/bulk', ['operation' => 'trash', 'items' => $items])->assertOk()->assertJsonPath('affected', 2);
        $this->assertSame(0, ManagedRecord::where('tenant_id', 7)->count());
    }

    public function test_field_permissions_apply_to_list_search_sort_editor_and_writes(): void
    {
        $this->app->bind(ManagedCategory::class, fn () => new class extends ManagedCategory {
            public function fields(): array { return parent::fields() + ['secret' => ['labelKey' => 'admin.users.email', 'readAbility' => 'read-secret', 'writeAbility' => 'write-secret']]; }
            public function searchable(): array { return ['name', 'secret']; }
        });
        Gate::define('read-secret', fn () => false);
        Gate::define('write-secret', fn () => false);
        $this->getJson('/control/categories')->assertOk()->assertJsonMissingPath('data.management.rows.0.values.secret');
        $this->getJson('/control/categories?q=server-secret')->assertOk()->assertJsonCount(0, 'data.management.rows');
        $this->getJson('/control/categories?sort=secret')->assertUnprocessable();
        $this->getJson('/control/categories/1/edit')->assertOk()->assertJsonCount(1, 'data.management.fields');
        $this->putJson('/control/categories/_api/1', ['revision' => $this->revision(), 'values' => ['secret' => 'Injected']])->assertUnprocessable();
        Gate::define('read-secret', fn () => true);
        $this->getJson('/control/categories/1/edit')->assertOk()->assertJsonPath('data.management.fields.1.readOnly', true);
        Gate::define('write-secret', fn () => true);
        $this->putJson('/control/categories/_api/1', ['revision' => $this->revision(), 'values' => ['name' => 'Visible', 'secret' => 'Allowed']])->assertOk();
        $this->assertDatabaseHas('managed_records', ['id' => 1, 'secret' => 'Allowed']);
        $this->assertStringNotContainsString('Allowed', json_encode(app('db')->table('saola_resource_events')->get()));
    }

    public function test_list_context_sort_links_and_search_field_are_preserved_and_allowlisted(): void
    {
        $this->app->bind(ManagedCategory::class, fn () => new class extends ManagedCategory {
            public function fields(): array { return parent::fields() + ['secret' => ['labelKey' => 'admin.users.email', 'readAbility' => 'read-secret']]; }
            public function searchable(): array { return ['name', 'secret']; }
        });
        Gate::define('read-secret', fn () => true);
        $query = '?locale=en&q=Visible&search_by=name&sort=name&direction=desc&per_page=10&page=1';
        $this->withHeader('X-Sao-Response', 'json'); // as the SPA navigates: ?locale here is the list's content locale
        $list = $this->getJson('/control/categories'.$query)->assertOk()->assertJsonCount(1, 'data.management.rows')->json('data.management');
        $this->assertSame(['name', 'secret'], array_column($list['listing']['searchOptions'], 'key'));
        parse_str(parse_url($list['rows'][0]['actions'][0]['url'], PHP_URL_QUERY), $context);
        $this->assertSame(['locale' => 'en', 'q' => 'Visible', 'search_by' => 'name', 'sort' => 'name', 'direction' => 'desc', 'per_page' => '10', 'page' => '1'], $context);
        $this->getJson('/control/categories/1/edit'.$query)->assertOk()->assertJsonPath('data.management.listUrl', $list['listUrl']);
        parse_str(parse_url($list['columns'][0]['sortUrl'], PHP_URL_QUERY), $sort);
        $this->assertSame('asc', $sort['direction']); $this->assertSame('1', $sort['page']); $this->assertSame('name', $sort['search_by']);
        $this->getJson('/control/categories?q=Visible&search_by=secret')->assertOk()->assertJsonCount(0, 'data.management.rows');
        Gate::define('read-secret', fn () => false);
        $this->getJson('/control/categories?search_by=secret')->assertUnprocessable();
        $this->getJson('/control/categories?search_by=tenant_id')->assertUnprocessable();
    }

    public function test_deleting_last_row_of_last_page_clamps_list_and_context_to_the_previous_page(): void
    {
        for ($i = 0; $i < 10; $i++) ManagedRecord::create(['tenant_id' => 7, 'name' => 'Extra '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        $last = $this->getJson('/control/categories?per_page=10&page=2')->assertOk()
            ->assertJsonPath('data.management.listing.from', 11)->assertJsonCount(1, 'data.management.rows')->json('data.management.rows.0');
        $this->postJson('/control/categories/_api/'.$last['id'].'/trash', ['revision' => $last['revision']])->assertOk();
        $list = $this->getJson('/control/categories?per_page=10&page=2')->assertOk()->assertJsonCount(10, 'data.management.rows')
            ->assertJsonPath('data.management.listing.page', 1)->assertJsonPath('data.management.listing.lastPage', 1)->assertJsonPath('data.management.listing.from', 1)
            ->assertJsonPath('data.management.listing.to', 10)->assertJsonPath('data.management.listing.pages.0.current', true)->json('data.management');
        $this->assertSame('/control/categories?per_page=10&page=1', $list['listUrl']);
        $this->assertStringContainsString('page=1', $list['createUrl']);
    }

    public function test_numeric_select_value_is_projected_as_a_string_to_match_option_values(): void
    {
        $this->app->bind(ManagedCategory::class, fn () => new class extends ManagedCategory {
            public function fields(): array { return ['name' => ['type' => 'select', 'labelKey' => 'admin.users.name', 'options' => [1 => 'admin.resource.active', 2 => 'admin.resource.trashed']]]; }
        });
        ManagedRecord::findOrFail(1)->forceFill(['name' => '2'])->save();
        $this->getJson('/control/categories/1/edit')->assertOk()->assertJsonPath('data.management.fields.0.value', '2')
            ->assertJsonPath('data.management.fields.0.options.1.value', '2');
    }

    public function test_domain_hook_failure_rolls_back_prior_rows_and_audit_then_purge_requires_opt_in_and_policy(): void
    {
        $three = ManagedRecord::create(['tenant_id' => 7, 'name' => 'Three']);
        $this->app->bind(ManagedCategory::class, fn () => new class extends ManagedCategory {
            public function beforeMutation(string $operation, Model $record, Request $request): void { abort_if($record->name === 'Three', 409); }
        });
        $items = [['id' => '1', 'revision' => $this->revision()], ['id' => (string) $three->id, 'revision' => $this->revision((string) $three->id)]];
        $this->postJson('/control/categories/_api/bulk', ['operation' => 'trash', 'items' => $items])->assertConflict();
        $this->assertSame(2, ManagedRecord::where('tenant_id', 7)->count());
        $this->assertDatabaseCount('saola_resource_events', 0);
        $this->app->bind(ManagedCategory::class, fn () => new class extends ManagedCategory { public function supportsPurge(): bool { return true; } });
        $this->postJson('/control/categories/_api/1/trash', ['revision' => $this->revision()])->assertOk();
        $trash = $this->getJson('/control/categories?status=trash')->assertOk()->json('data.management.rows.0');
        $this->postJson('/control/categories/_api/1/purge', ['revision' => $trash['revision']])->assertForbidden();
        Gate::define('forceDelete', fn () => true);
        $this->postJson('/control/categories/_api/1/purge', ['revision' => $trash['revision']])->assertOk();
        $this->assertDatabaseMissing('managed_records', ['id' => 1]);
        $this->assertDatabaseHas('saola_resource_events', ['record_key' => '1', 'operation' => 'purge']);
    }
}

class ManagedRecord extends Model
{
    use SoftDeletes;
    protected $table = 'managed_records';
    protected $guarded = [];
}
class ManagedCategory extends Resource
{
    public function key(): string { return 'categories'; }
    public function titleKey(): string { return 'admin.resource.records'; }
    public function ability(): string { return 'access-admin'; }
    public function model(): string { return ManagedRecord::class; }
    public function fields(): array { return ['name' => ['labelKey' => 'admin.users.name', 'required' => true, 'maxLength' => 100]]; }
    public function searchable(): array { return ['name']; }
    public function query(Request $request): Builder { return ManagedRecord::where('tenant_id', $request->user()->tenant_id); }
    public function newRecord(Request $request): Model { return new ManagedRecord(['tenant_id' => $request->user()->tenant_id]); }
    public function rules(Request $request, ?Model $record): array { return ['name' => ['required', 'string', 'max:100', Rule::unique('managed_records')->where('tenant_id', $request->user()->tenant_id)->ignore($record?->id)]]; }
}

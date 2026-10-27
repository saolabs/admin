<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\{DB, Gate, Schema};
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Admin\Pages\{ListingQuery, ResourceDocument};
use Saola\Admin\Tests\Fixtures\{DirectoryUser, StringDirectoryUser, TenantUserScope};
use Saola\Admin\Users\UserDirectory;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Support\SPA;

class UserDirectoryTest extends TestCase
{
    private string $root;
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $this->root = realpath(sys_get_temp_dir()).'/saola-users-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/resources/views', 0777, true);
        mkdir($this->root.'/bootstrap/cache', 0777, true);
        $app->setBasePath($this->root);
        $app['config']->set('view.paths', [$this->root.'/resources/views']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('saola-admin.panel.enabled', true);
        $app['config']->set('saola-admin.panel.prefix', 'control');
        $app['config']->set('saola-admin.users.enabled', true);
        $app['config']->set('saola-admin.users.query_scope', TenantUserScope::class);
        $app['config']->set('auth.providers.users.model', DirectoryUser::class);
    }

    protected function setUp(): void
    {
        SPA::resetRoutes(); parent::setUp();
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        app(DatabaseLanguageStore::class)->sync(app(LanguageSources::class)->catalog(), 'en');
        \Saola\Admin\Tests\Fixtures\DirectoryDataset::create();
        Gate::define('access-admin', fn ($actor) => $actor->id === 1);
        Gate::define('view-admin-users', fn ($actor) => (bool) $actor->directory_read);
        Gate::define('viewAny', fn ($actor, $model) => (bool) $actor->directory_policy && in_array($model, [DirectoryUser::class, StringDirectoryUser::class], true));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root); SPA::resetRoutes(); parent::tearDown();
    }

    private function operator(array $overrides = []): void
    {
        $this->actingAs(new GenericUser(array_replace(['id' => 1, 'tenant_id' => 7, 'directory_read' => true, 'directory_policy' => true], $overrides)));
    }

    public function test_disabled_directory_has_no_route_page_or_navigation_and_does_not_resolve_the_model(): void
    {
        config(['saola-admin.users.enabled' => false, 'saola-admin.users.model' => 'MissingHostIdentity']);
        $this->operator();
        $this->getJson('/control/users')->assertNotFound();
        $this->getJson('/control')->assertOk()->assertJsonCount(1, 'data.navigation');
    }

    public function test_directory_requires_panel_ability_and_host_policy_for_navigation_and_direct_requests(): void
    {
        $this->getJson('/control/users')->assertUnauthorized();
        $this->operator(['directory_read' => false]);
        $this->getJson('/control/users')->assertForbidden();
        $this->getJson('/control')->assertOk()->assertJsonCount(1, 'data.navigation');
        $this->operator(['directory_policy' => false]);
        $this->getJson('/control/users')->assertForbidden();
        $this->getJson('/control')->assertOk()->assertJsonCount(1, 'data.navigation');
        $this->operator();
        $this->getJson('/control')->assertOk()->assertJsonCount(2, 'data.navigation');
        $this->getJson('/control/users')->assertOk()->assertJsonPath('view', 'admin.themes.classic.pages.resource');
        $this->postJson('/control/users', ['password' => 'attempt'])->assertStatus(405);
        $this->putJson('/control/users', ['email' => 'attempt@example.test'])->assertStatus(405);
    }

    public function test_projection_search_count_and_pages_share_the_host_and_global_scopes(): void
    {
        $this->operator(); DB::enableQueryLog();
        $response = $this->getJson('/control/users?locale=vi&per_page=10&sort=name&direction=asc&arbitrary=ignored')->assertOk()
            ->assertJsonCount(10, 'data.page.rows')->assertJsonPath('data.page.listing.total', 27)
            ->assertJsonPath('data.page.rows.0', ['id' => '1', 'name' => 'Member 01', 'email' => 'member1@example.test'])
            ->assertJsonPath('data.page.listing.page', 1)->assertJsonPath('data.page.listing.lastPage', 3);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('password', json_encode($response->json('data.page.rows')));
        $this->assertStringNotContainsString('never-send-hash', $response->getContent());
        $this->assertStringNotContainsString('arbitrary', $response->json('data.page.listing.next'));
        $selects = array_column(DB::getQueryLog(), 'query');
        $this->assertNotEmpty(array_filter($selects, fn ($sql) => str_contains($sql, 'select "directory_users"."id", "directory_users"."name", "directory_users"."email"')));
        $this->getJson($response->json('data.page.listing.next'))->assertOk()->assertJsonPath('data.page.rows.0.id', '11');
        $this->getJson('/control/users?page=3&per_page=10')->assertOk()->assertJsonCount(7, 'data.page.rows');
        $this->getJson('/control/users?q=outside%40example.test')->assertOk()->assertJsonPath('data.page.listing.total', 0);
        $this->getJson('/control/users?q=MEMBER%2002')->assertOk()->assertJsonPath('data.page.rows.0.id', '2')->assertJsonPath('data.page.listing.total', 1);
        $this->getJson('/control/users?q=hidden')->assertOk()->assertJsonPath('data.page.listing.total', 0);
        $this->getJson('/control/users?q=Member&sort=id&direction=desc')->assertOk()->assertJsonPath('data.page.rows.0.id', '27');
        // No request-derived actor or list is retained by the scoped adapter.
        $this->operator(['tenant_id' => 8]);
        $this->getJson('/control/users')->assertOk()->assertJsonPath('data.page.listing.total', 1)->assertJsonPath('data.page.rows.0.id', '28');
    }

    public function test_host_model_policy_is_consulted_on_each_request_even_when_the_read_ability_is_granted(): void
    {
        Gate::policy(DirectoryUser::class, \Saola\Admin\Tests\Fixtures\DirectoryPolicy::class);
        $this->operator(['directory_policy' => false]);
        $this->getJson('/control/users')->assertForbidden();
        $this->getJson('/control')->assertOk()->assertJsonCount(1, 'data.navigation');
        $this->operator();
        $this->getJson('/control/users')->assertOk()->assertJsonPath('data.page.listing.total', 27);
    }

    public function test_host_can_replace_the_adapter_without_an_eloquent_model_or_scope(): void
    {
        config(['saola-admin.users.model' => 'NotAnEloquentModel', 'saola-admin.users.query_scope' => null]);
        $this->app->bind(UserDirectory::class, fn () => new class implements UserDirectory {
            public function allows(\Illuminate\Http\Request $request): bool { return (bool) $request->user()->directory_policy; }
            public function document(\Illuminate\Http\Request $request, ListingQuery $query): ResourceDocument {
                return new ResourceDocument('admin.users.title', 'admin.users.description', [], [['key' => 'name', 'labelKey' => 'admin.users.name']], [['name' => 'External identity provider']]);
            }
            public function diagnostics(): array { return []; }
        });
        $this->operator();
        $this->getJson('/control/users')->assertOk()->assertJsonPath('data.page.rows.0.name', 'External identity provider');
        $this->operator(['directory_policy' => false]);
        $this->getJson('/control/users')->assertForbidden();
        $this->assertSame([], app(UserDirectory::class)->diagnostics());
    }

    public function test_literal_search_does_not_treat_percent_underscore_or_escape_as_wildcards(): void
    {
        $this->operator();
        DB::table('directory_users')->where('id', 1)->update(['name' => '100%_! literal']);
        foreach (['%', '_', '!', '%_!', '100%_!'] as $search) {
            $this->getJson('/control/users?q='.rawurlencode($search))->assertOk()
                ->assertJsonPath('data.page.listing.total', 1)->assertJsonPath('data.page.rows.0.id', '1');
        }
        $this->getJson('/control/users?q='.rawurlencode("' OR 1=1 --"))->assertOk()->assertJsonPath('data.page.listing.total', 0);
    }

    public function test_untrusted_list_parameters_are_validated_and_out_of_range_pages_stay_empty(): void
    {
        $this->operator();
        foreach (['sort=password', 'sort[]=name', 'direction=drop', 'q[]=x', 'q='.str_repeat('x', 101), 'page=0', 'page=100001', 'page=1.5', 'per_page=1000', 'per_page[]=10'] as $query) {
            $this->getJson('/control/users?'.$query)->assertUnprocessable();
        }
        $this->getJson('/control/users?page=100000')->assertOk()->assertJsonCount(0, 'data.page.rows')
            ->assertJsonPath('data.page.listing.next', null)->assertJsonPath('data.page.listing.total', 27);
    }

    public function test_model_resolves_from_selected_guard_and_accepts_string_keys_and_column_mappings(): void
    {
        Schema::create('directory_members', function (Blueprint $table) {
            $table->string('uuid')->primary(); $table->string('display_name'); $table->string('login_email'); $table->integer('tenant_id'); $table->string('password');
        });
        $uuid = '01a16bb0-2c1d-78a3-b3f0-b96603291c8c';
        DB::table('directory_members')->insert(['uuid' => $uuid, 'display_name' => '<script>alert(1)</script>', 'login_email' => 'uuid@example.test', 'tenant_id' => 7, 'password' => 'secret']);
        config(['saola-admin.panel.guard' => 'staff', 'auth.guards.staff' => ['driver' => 'session', 'provider' => 'members'],
            'auth.providers.members' => ['driver' => 'eloquent', 'model' => StringDirectoryUser::class],
            'saola-admin.users.columns' => ['name' => 'display_name', 'email' => 'login_email']]);
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7, 'directory_read' => true, 'directory_policy' => true]), 'staff');
        $this->getJson('/control/users?sort=id')->assertOk()->assertJsonPath('data.page.rows.0.id', $uuid)
            ->assertJsonPath('data.page.rows.0.name', '<script>alert(1)</script>')->assertJsonMissingPath('data.page.rows.0.password');
        $this->assertSame([], app(UserDirectory::class)->diagnostics());
    }

    public function test_doctor_detects_missing_scope_and_columns_without_changing_the_host_schema(): void
    {
        $this->assertSame([], app(UserDirectory::class)->diagnostics());
        config(['saola-admin.users.query_scope' => null]);
        $errors = app(UserDirectory::class)->diagnostics();
        $this->assertStringContainsString('query_scope', $errors[0]);
        $this->withoutMockingConsoleOutput();
        $this->artisan('admin:doctor');
        $this->assertStringContainsString('query_scope', \Illuminate\Support\Facades\Artisan::output());
        config(['saola-admin.users.query_scope' => TenantUserScope::class, 'saola-admin.users.columns.name' => 'full_name']);
        $this->assertSame(['User directory column is missing: full_name'], app(UserDirectory::class)->diagnostics());
        config(['saola-admin.users.columns.name' => 'name; drop table users']);
        $this->assertStringContainsString('plain SQL column names', app(UserDirectory::class)->diagnostics()[0]);
        $this->assertSame(29, DB::table('directory_users')->count());
        $this->assertFalse(Schema::hasColumn('directory_users', 'full_name'));
    }
}

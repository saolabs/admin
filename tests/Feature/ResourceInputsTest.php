<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\{Model, Builder};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Schema};
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Resources\{Input, Resource, RichText, SafeUrlRule};
use Saola\Core\Support\SPA;

class ResourceInputsTest extends TestCase
{
    use \Saola\Admin\Tests\Support\RegistersCrud;

    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', str_repeat('r', 32));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('saola-admin.panel.enabled', true);
        $app['config']->set('saola-admin.panel.prefix', 'control');
    }
    protected function setUp(): void
    {
        SPA::resetRoutes(); parent::setUp();
        $this->crud('inputs', InputResource::class);
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) (require $file)->up();
        Schema::create('input_records', function (Blueprint $table) {
            $table->id(); $table->integer('tenant_id'); $table->string('name');
            foreach (['price', 'day', 'at', 'time', 'color', 'enabled', 'choice', 'relation', 'many', 'tags', 'document', 'body', 'image', 'url'] as $key) $table->text($key)->nullable();
            $table->timestamps();
        });
        foreach (['access-admin', 'viewAny', 'view', 'create', 'update', 'view-media'] as $ability) Gate::define($ability, fn () => true);
        InputRecord::create(['tenant_id' => 7, 'name' => 'Visible', 'many' => ['a'], 'tags' => ['one'], 'document' => ['zero' => 0], 'relation' => '1']);
        InputRecord::create(['tenant_id' => 8, 'name' => 'Other tenant']);
        $this->actingAs(new GenericUser(['id' => 1, 'tenant_id' => 7]));
    }
    protected function tearDown(): void { SPA::resetRoutes(); parent::tearDown(); }
    private function create(array $values) { return $this->postJson('/control/inputs/_api', ['requestId' => (string) Str::uuid(), 'values' => ['name' => 'New'] + $values]); }

    public function test_presets_project_groups_options_and_values_without_exposing_foreign_tenant_choices(): void
    {
        $editor = $this->getJson('/control/inputs/1/edit')->assertOk()->json('data.management');
        $fields = array_column($editor['fields'], null, 'key');
        $this->assertSame('tabs', $editor['formLayout']);
        $this->assertSame('main', $editor['firstSection']);
        $this->assertSame(['main', 'content'], array_column($editor['groups'], 'key'));
        $this->assertSame('switch', $fields['enabled']['template']);
        $this->assertSame('boolean', $fields['enabled']['type']);
        $this->assertSame([['value' => '1', 'labelKey' => '', 'label' => 'Visible', 'selected' => true]], $fields['relation']['options']);
        $this->assertSame(['a'], $fields['many']['value']);
        $this->assertTrue($fields['many']['options'][0]['selected']);
        $this->assertSame('one', $fields['tags']['tagText']);
        $this->assertSame(['zero' => 0], json_decode($fields['document']['value'], true));
        if ($directory = getenv('SAOLA_ADMIN_INPUT_FIXTURES')) {
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/editor.json', json_encode($editor, JSON_THROW_ON_ERROR));
        }
    }
    public function test_typed_inputs_and_arrays_roundtrip_with_casts_and_rich_text_is_sanitized(): void
    {
        $id = $this->create(['price' => '12.50', 'day' => '2026-10-26', 'at' => '2026-10-26T13:45', 'time' => '13:45', 'color' => '#aabbcc',
            'enabled' => false, 'choice' => 'b', 'relation' => '1', 'many' => ['a', 'b'], 'tags' => ['one', 'two'],
            'document' => '{"zero":0,"enabled":false}', 'body' => '<h2 onclick="bad()">Hello</h2><script>alert(1)</script><a href="javascript:bad()">bad</a><strong>Good</strong>',
            'image' => '/media/image.png', 'url' => 'https://example.com'])->assertCreated()->json('id');
        $record = InputRecord::findOrFail($id);
        $this->assertSame(['a', 'b'], $record->many);
        $this->assertSame(['one', 'two'], $record->tags);
        $this->assertSame(['zero' => 0, 'enabled' => false], $record->document);
        $this->assertSame('<h2>Hello</h2><a>bad</a><strong>Good</strong>', $record->body);
        $fields = array_column($this->getJson('/control/inputs/'.$id.'/edit')->assertOk()->json('data.management.fields'), null, 'key');
        $this->assertSame('/media/image.png', $fields['image']['previewUrl']);
        $this->assertSame('2026-10-26T13:45', $fields['at']['value']);
        $this->assertSame('13:45', $fields['time']['value']);
        $revision = $this->getJson('/control/inputs/'.$id.'/edit')->json('data.management.revision');
        $this->putJson('/control/inputs/_api/'.$id, ['revision' => $revision, 'values' => ['many' => [], 'tags' => []]])->assertOk();
        $this->assertSame([], $record->fresh()->many);
    }
    public function test_type_constraints_options_and_scope_cannot_be_bypassed_by_a_client(): void
    {
        foreach ([['price' => -1], ['day' => '2026-02-30'], ['at' => 'tomorrow'], ['time' => '25:61'], ['color' => 'red'],
            ['relation' => '2'], ['choice' => 'outside'], ['many' => ['outside']], ['many' => ['a', 'a']], ['tags' => ['one', ['bad']]],
            ['tags' => ['a', 'b', 'c', 'd']], ['document' => '{invalid'], ['image' => 'javascript:alert(1)'], ['url' => '//evil.test']] as $values) {
            $this->create($values)->assertUnprocessable();
        }
        $this->assertDatabaseCount('input_records', 2);
        $this->assertDatabaseCount('saola_resource_events', 0);
    }
    public function test_numeric_step_is_enforced_and_array_templates_reject_object_casts(): void
    {
        $this->app->bind(InputResource::class, fn () => new class extends InputResource {
            public function fields(): array { $fields = parent::fields(); $fields['price']['step'] = 0.25; return $fields; }
        });
        $this->create(['price' => '1.10'])->assertUnprocessable();
        $this->create(['price' => '1.25'])->assertCreated();
        $record = new InputRecord(); $record->mergeCasts(['many' => 'object']);
        $this->app->bind(InputRecord::class, fn () => $record);
        $this->expectException(\LogicException::class);
        (new InputResource())->schema();
    }

    public function test_field_permissions_and_sections_are_filtered_before_projection_and_write(): void
    {
        Gate::define('read-content', fn () => false);
        $this->app->bind(InputResource::class, fn () => new class extends InputResource {
            public function fields(): array { $fields = parent::fields(); foreach ($fields as &$field) if (($field['section'] ?? '') === 'content') $field['readAbility'] = 'read-content'; return $fields; }
        });
        $this->getJson('/control/inputs/1/edit')->assertOk()->assertJsonCount(1, 'data.management.groups')->assertJsonMissingPath('data.management.groups.1');
        $this->create(['body' => 'hidden'])->assertUnprocessable();
    }
    public function test_sanitized_list_renderers_and_custom_computed_values_do_not_render_html_or_unsafe_urls(): void
    {
        InputRecord::findOrFail(1)->forceFill(['url' => 'javascript:alert(1)', 'image' => '//evil.test/a.png', 'price' => '12.5', 'enabled' => true])->save();
        $list = $this->getJson('/control/inputs')->assertOk()->json('data.management');
        $this->assertSame('', $list['rows'][0]['values']['url']);
        $this->assertSame('', $list['rows'][0]['values']['image']);
        $this->assertSame('1', $list['rows'][0]['values']['enabled']);
        $this->assertSame('Visible computed', $list['rows'][0]['values']['summary']);
    }
    public function test_invalid_input_templates_and_unknown_sections_fail_at_registration(): void
    {
        foreach ([['type' => 'made-up'], ['type' => 'text', 'template' => 'image'], ['type' => 'text', 'section' => 'missing'], ['type' => 'text', 'rows' => 999]] as $attributes) {
            $resource = new class($attributes) extends InputResource {
                public function __construct(private array $attributes) {}
                public function fields(): array { return ['name' => ['labelKey' => 'admin.users.name'] + $this->attributes]; }
            };
            try { $resource->schema(); $this->fail('Invalid schema should fail'); } catch (\LogicException) { $this->assertTrue(true); }
        }
    }
    public function test_html_and_url_vocabulary_rejects_executable_content_and_keeps_unicode_and_safe_links(): void
    {
        $this->assertSame('<p>Tiếng Việt</p><a href="/safe" rel="noopener noreferrer">link</a>', RichText::clean('<p style="bad">Tiếng Việt</p><svg><script>x</script></svg><a href="/safe" onclick="x">link</a>'));
        foreach (["/\\evil.test", '//evil.test', 'data:text/html,bad', "https://user:pass@example.com", "\njavascript:x"] as $url) $this->assertSame('', SafeUrlRule::safe($url));
    }
}
class InputRecord extends Model
{
    protected $table = 'input_records'; protected $guarded = [];
    protected $casts = ['many' => 'array', 'tags' => 'array', 'document' => 'array'];
}
class InputResource extends Resource
{
    public function key(): string { return 'inputs'; }
    public function titleKey(): string { return 'admin.resource.records'; }
    public function ability(): string { return 'access-admin'; }
    public function model(): string { return InputRecord::class; }
    public function query(Request $request): Builder { return InputRecord::where('tenant_id', $request->user()->tenant_id); }
    public function newRecord(Request $request): Model { return new InputRecord(['tenant_id' => $request->user()->tenant_id]); }
    public function sections(): array { return ['main' => ['labelKey' => 'admin.resource.details'], 'content' => ['labelKey' => 'admin.settings.description']]; }
    public function formLayout(): string { return 'tabs'; }
    public function fields(): array
    {
        return ['name' => Input::make('text', 'admin.users.name', ['required' => true]), 'price' => Input::make('number', 'admin.users.name', ['min' => 0, 'max' => 100]),
            'day' => Input::make('date', 'admin.users.name'), 'at' => Input::make('datetime', 'admin.users.name'), 'time' => Input::make('time', 'admin.users.name'),
            'color' => Input::make('color', 'admin.users.name'), 'enabled' => Input::make('switch', 'admin.users.name'),
            'choice' => Input::make('radio', 'admin.users.name', ['options' => ['a' => 'admin.resource.active', 'b' => 'admin.resource.trashed']]),
            'relation' => Input::make('relation', 'admin.users.name'), 'many' => Input::make('checkboxes', 'admin.users.name', ['options' => ['a' => 'admin.resource.active', 'b' => 'admin.resource.trashed']]),
            'tags' => Input::make('tags', 'admin.users.name', ['maxItems' => 3]), 'document' => Input::make('json', 'admin.users.name', ['section' => 'content']),
            'body' => Input::make('richtext', 'admin.users.name', ['section' => 'content']), 'image' => Input::make('image', 'admin.users.name', ['section' => 'content']),
            'url' => Input::make('url', 'admin.users.name'), 'summary' => Input::make('text', 'admin.users.name', ['readOnly' => true, 'persist' => false])];
    }
    public function columns(): array { return array_map(fn ($key, $renderer) => ['key' => $key, 'labelKey' => 'admin.users.name', 'renderer' => $renderer], ['name', 'price', 'enabled', 'image', 'url', 'summary'], ['text', 'number', 'boolean', 'image', 'link', 'text']); }
    public function columnValue(string $key, Model $record, Request $request): mixed { return $key === 'summary' ? $record->name.' computed' : parent::columnValue($key, $record, $request); }
    public function fieldOptions(string $key, Request $request, ?Model $record): array
    {
        return $key === 'relation' ? InputRecord::where('tenant_id', $request->user()->tenant_id)->pluck('name', 'id')->map(fn ($name) => ['label' => $name])->all() : parent::fieldOptions($key, $request, $record);
    }
}

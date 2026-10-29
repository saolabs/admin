<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Saola\Admin\Registry\{AdminRegistry, RegisteredResource};
use Saola\Admin\Resources\{Resource, ResourceStore};
use Saola\Core\Http\Controllers\AdminController as CoreAdminController;
use Saola\Core\Routing\Permission as Op;

/**
 * The admin package's base for the admin context. A project points App\Http\Controllers\AdminController at it
 * (`extends \Saola\Admin\Http\AdminController`); module controllers extend the project's class and declare their
 * own routes onto the CRUD actions below (see routes()), overriding any of them when a module needs to.
 *
 * Every GET page gets the shell data (menu, branding, locales); the list/form come from Admin::list()/Admin::form()
 * registered under the module key: $module when set, else the second segment of the route name (admin.{module}.*).
 */
abstract class AdminController extends CoreAdminController
{
    /** Registered key served by this controller; null = the key the route name continues (admin.{key}.*, key may be products.categories). */
    protected $module = null;

    /**
     * Standard CRUD routes for a module, pointing at the actions below; the module declares them in its own route group.
     *  - Saola route builder: System::context('admin')->module([...])->controller(UserController::class)->as('users')->group(fn ($m) => UserController::routes($m));
     *  - Laravel routes:      Route::prefix('admin/users')->name('admin.users.')->middleware([...])->group(fn () => UserController::routes());
     * The list/form pages render the package's resource screen, as HTML on a full load and as JSON for the view's @await
     * (filters and paging navigate to the same URLs); the _api paths only take writes.
     */
    public static function routes(?object $m = null): void
    {
        $add = function (array|string $verbs, string $uri, string $action, string $name, array $permissions, bool $page = false) use ($m) {
            if ($m) {
                $route = is_array($verbs) ? $m->match($verbs, $uri, $action) : $m->{$verbs}($uri, $action);
                $route = $route->name($name)->permission(...$permissions);
                if ($page) $route->view('@BASE:pages.resource');
                return;
            }
            $route = (is_array($verbs) ? \Illuminate\Support\Facades\Route::match($verbs, $uri, [static::class, $action]) : \Illuminate\Support\Facades\Route::{$verbs}($uri, [static::class, $action]))->name($name);
            // SPA navigation needs to know which view a page route renders (the Saola builder records it through ->view()).
            if ($page) {
                $active = \Saola\Core\Support\SPA::isActive(); \Saola\Core\Support\SPA::active();
                \Saola\Core\Support\SPA::addRoute('admin', (string) $route->getName(), '/'.trim($route->uri(), '/'), ['kind' => 'explicit', 'logical' => '@BASE:pages.resource']);
                if (!$active) \Saola\Core\Support\SPA::inactive();
            }
        };
        $add('get', '/', 'listPage', 'index', [Op::READ], true);
        $add('get', '/create', 'createPage', 'create', [Op::READ, Op::CREATE], true);
        // Editors also serve read-only records. Resource policies decide which fields/actions may change.
        $add('get', '/{record}/edit', 'editPage', 'edit', [Op::READ], true);
        $add('post', '/_api', 'apiRecords', 'api', [Op::READ, Op::CREATE]);
        $add('post', '/_api/bulk', 'apiBulk', 'api.bulk', [Op::READ]);
        $add('put', '/_api/{record}', 'apiRecord', 'api.item', [Op::READ, Op::UPDATE]);
        // Bulk/state operations depend on the submitted operation; ResourceStore enforces delete/restore/purge per record.
        $add('post', '/_api/{record}/{operation}', 'apiTransition', 'api.state', [Op::READ]);
    }

    /** Key of the registered list/form/menu this controller serves. */
    protected function adminKey(): string
    {
        if (is_string($this->module ?? null) && $this->module !== '') return $this->module;
        return app(AdminRegistry::class)->routeKey((string) request()->route()?->getName())[0];
    }

    /** Outcome of a write (POST/PUT/DELETE), delivered to the view's App.Http. */
    protected function result(array $data, int $status = 200)
    {
        return $this->response($data, null, ['forceJson' => true, 'status' => $status]);
    }

    public function response(array $data = [], ?string $bladePath = null, array $options = [])
    {
        $request = request();
        [, $module, $action] = [...explode('.', (string) $request->route()?->getName()), '', '', ''];
        $key = $this->adminKey();
        $this->module ??= $module ?: null; // core resolves '@module:' views from it
        if ($request->isMethod('GET') && !($options['forceJson'] ?? false)) {
            $data = [...app(PanelData::class)->prepare($request, $key), ...$data];
            // Core's own derivation (response() without a view) names the view '@RAW:modules.x', which the theme base then prefixes,
            // and routeToViewPathConfig()'s '@MODULE:module.blade' doubles the module. So derive module/action from the route name here.
            // "@module:" resolves through the "modules" directory (the host namespace), not the theme base.
            $bladePath ??= $module && $action ? "@module:{$action}" : null;
        }

        return parent::response($data, $bladePath, $options);
    }

    // ---- CRUD actions over the registered list/form. Named apart from index/create/edit/store/update/destroy,
    //      which stay free for a module's own actions. ----

    protected function crudDefinition(Request $request): Resource
    {
        $definition = app(AdminRegistry::class)->definition($this->adminKey());
        abort_unless($definition->ability() !== '' && \Illuminate\Support\Facades\Gate::forUser($request->user())->allows($definition->ability()) && $definition->allows($request), 403);
        return $definition;
    }
    protected function crudStore(Resource $resource): ResourceStore { return app($resource->store()); }

    protected function crudPage(Request $request, bool $editor, ?string $record = null)
    {
        $resource = $this->crudDefinition($request);
        return $this->response([
            'page' => $resource->document($request)->toArray(),
            'management' => $editor ? $this->crudStore($resource)->editor($resource, $request, $record) : $this->crudStore($resource)->listing($resource, $request),
            // resources/saola/admin/views/resources/{key}.sao, when the project has one, replaces the shared manager for this list/form.
            'screen' => \Saola\Admin\Themes\HostViews::existing('resources', $resource->key()) ?? '',
        ], '@RAW:pages.resource');
    }

    public function listPage(Request $request) { return $this->crudPage($request, false); }
    public function createPage(Request $request) { return $this->crudPage($request, true); }
    public function editPage(Request $request, string $record) { return $this->crudPage($request, true, $record); }

    public function apiRecords(Request $request)
    {
        $definition = $this->crudDefinition($request);
        return response()->json($this->crudStore($definition)->create($definition, $request), 201);
    }

    public function apiRecord(Request $request, string $record)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $record), 404);
        $definition = $this->crudDefinition($request);
        return response()->json($this->crudStore($definition)->update($definition, $request, $record));
    }

    public function apiTransition(Request $request, string $record, string $operation)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $record) && in_array($operation, ['trash', 'restore', 'purge'], true), 404);
        abort_if(array_diff(array_keys($request->json()->all()), ['revision']), 422);
        $definition = $this->crudDefinition($request);
        return response()->json($this->crudStore($definition)->transition($definition, $request, $operation, [['id' => $record, 'revision' => $request->input('revision')]]));
    }

    public function apiBulk(Request $request)
    {
        abort_if(array_diff(array_keys($request->json()->all()), ['operation', 'items']), 422);
        $data = $request->validate(['operation' => ['required', 'in:trash,restore'], 'items' => ['required', 'array']]);
        $definition = $this->crudDefinition($request);
        return response()->json($this->crudStore($definition)->transition($definition, $request, $data['operation'], $data['items']));
    }
}

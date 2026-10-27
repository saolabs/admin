<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Saola\Admin\Pages\PageRegistry;
use Saola\Admin\Resources\{Resource, ResourceStore};
use Saola\Core\Http\Controllers\BaseWebController;

final class ResourceController extends BaseWebController
{
    protected $context = 'admin';

    private function definition(Request $request, string $key): Resource
    {
        $pages = app(PageRegistry::class); $page = $pages->get($key);
        abort_unless($page instanceof Resource, 404);
        abort_unless($pages->allows($page, $request), 403);
        return $page;
    }
    private function store(Resource $resource): ResourceStore { return app($resource->store()); }
    public function page(Request $request, string $page, ?string $record = null)
    {
        $resource = $this->definition($request, $page);
        $editor = $request->routeIs('saola.admin.resource.create', 'saola.admin.resource.edit');
        return $this->response(array_replace(app(PanelData::class)->prepare($request, $page), [
            'page' => $resource->document($request)->toArray(),
            'management' => $editor ? $this->store($resource)->editor($resource, $request, $record) : $this->store($resource)->listing($resource, $request),
            // resources/saola/admin/views/resources/{key}.sao, when the project has one, replaces the shared manager for this Resource.
            'screen' => \Saola\Admin\Themes\HostViews::existing('resources', $resource->key()) ?? '',
        ]), '@RAW:pages.resource');
    }
    public function index(Request $request, string $resource)
    {
        $definition = $this->definition($request, $resource);
        if ($request->isMethod('POST')) return response()->json($this->store($definition)->create($definition, $request), 201);
        return response()->json(['schema' => 1, 'resource' => $this->store($definition)->listing($definition, $request)]);
    }
    public function item(Request $request, string $resource, string $record)
    {
        $definition = $this->definition($request, $resource); $store = $this->store($definition);
        if ($request->isMethod('PUT')) return response()->json($store->update($definition, $request, $record));
        return response()->json(['schema' => 1, 'resource' => $store->editor($definition, $request, $record)]);
    }
    public function state(Request $request, string $resource, string $record, string $operation)
    {
        abort_if(array_diff(array_keys($request->json()->all()), ['revision']), 422);
        $definition = $this->definition($request, $resource);
        return response()->json($this->store($definition)->transition($definition, $request, $operation, [['id' => $record, 'revision' => $request->input('revision')]]));
    }
    public function bulk(Request $request, string $resource)
    {
        abort_if(array_diff(array_keys($request->json()->all()), ['operation', 'items']), 422);
        $data = $request->validate(['operation' => ['required', 'in:trash,restore'], 'items' => ['required', 'array']]);
        $definition = $this->definition($request, $resource);
        return response()->json($this->store($definition)->transition($definition, $request, $data['operation'], $data['items']));
    }
}

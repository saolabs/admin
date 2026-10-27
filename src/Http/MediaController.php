<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Saola\Admin\Media\{MediaLibrary, MediaScope};
use Saola\Core\Http\Controllers\BaseWebController;

final class MediaController extends BaseWebController
{
    protected $context = 'admin';

    private function allow(Request $request, string $operation): void
    {
        abort_unless(config('saola-admin.media.enabled', true), 404);
        abort_unless(Gate::forUser($request->user())->allows(config('saola-admin.media.abilities.'.$operation)), 403);
    }

    public function editor(Request $request): array
    {
        $permissions = [];
        foreach (['view', 'upload', 'update', 'delete'] as $operation) $permissions[$operation] = Gate::forUser($request->user())->allows(config('saola-admin.media.abilities.'.$operation));
        return ['endpoint' => route('saola.admin.media.api', [], false), 'csrfToken' => csrf_token(), 'permissions' => $permissions,
            'initial' => ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 24],
            'maxBytes' => (int) config('saola-admin.media.max_bytes', 20971520),
            'accept' => implode(',', config('saola-admin.media.allowed_mimes', []))];
    }

    public function page(Request $request)
    {
        $this->allow($request, 'view');
        $editor = $this->editor($request);
        $editor['initial'] = app(MediaLibrary::class)->listing(app(MediaScope::class)->key($request), ['q' => '', 'page' => 1, 'kind' => 'all', 'status' => 'active']);
        return $this->response(array_replace(app(PanelData::class)->prepare($request, 'media'), ['mediaEditor' => $editor]), '@RAW:pages.media');
    }

    public function index(Request $request, MediaLibrary $library, MediaScope $scope)
    {
        $this->allow($request, 'view');
        $data = app('validator')->make($request->query(), ['q' => ['sometimes', 'nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'kind' => ['sometimes', 'in:all,image,video,audio,document'], 'status' => ['sometimes', 'in:active,trash']])->validate();
        $filters = ['q' => trim($data['q'] ?? ''), 'page' => (int) ($data['page'] ?? 1), 'kind' => $data['kind'] ?? 'all', 'status' => $data['status'] ?? 'active'];
        return response()->json(['data' => $library->listing($scope->key($request), $filters)]);
    }

    public function upload(Request $request, MediaLibrary $library, MediaScope $scope)
    {
        $this->allow($request, 'view'); $this->allow($request, 'upload');
        $data = $request->validate(['file' => ['required', 'file', 'max:'.(int) ceil(config('saola-admin.media.max_bytes', 20971520) / 1024)]]);
        return response()->json(['item' => $library->upload($scope->key($request), $data['file'])], 201);
    }

    public function item(Request $request, string $media, MediaLibrary $library, MediaScope $scope)
    {
        $this->allow($request, 'view');
        if ($request->isMethod('GET')) return response()->json(['item' => $library->find($scope->key($request), $media)]);
        $this->allow($request, 'update'); $body = $this->body($request, ['revision', 'changes']);
        abort_unless(is_array($body['changes'] ?? null), 422);
        return response()->json(['item' => $library->update($scope->key($request), $media, $body['revision'], $body['changes'])]);
    }

    public function state(Request $request, string $media, string $operation, MediaLibrary $library, MediaScope $scope)
    {
        $this->allow($request, 'view'); $this->allow($request, 'delete'); $body = $this->body($request, ['revision']);
        return response()->json(['item' => $library->trash($scope->key($request), $media, $body['revision'], $operation === 'restore')]);
    }

    public function content(string $media, MediaLibrary $library)
    {
        return $library->content($media);
    }

    private function body(Request $request, array $keys): array
    {
        abort_unless($request->isJson(), 415); abort_if(strlen($request->getContent()) > 16384, 413);
        try { $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR); } catch (\JsonException) { abort(422); }
        abort_unless(is_array($body) && !array_diff(array_keys($body), $keys) && is_int($body['revision'] ?? null) && $body['revision'] > 0, 422);
        return $body;
    }
}

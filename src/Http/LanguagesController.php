<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Saola\Admin\Language\{LanguageExchange, LocaleManager};
use Saola\Core\Http\Controllers\BaseWebController;

final class LanguagesController extends BaseWebController
{
    protected $context = 'admin';

    private function allow(Request $request, string $operation): void
    {
        abort_unless(Gate::forUser($request->user())->allows(config('saola-admin.language.abilities.'.$operation)), 403);
    }

    private function actor(Request $request): string
    {
        $user = $request->user(); $id = $user?->getAuthIdentifier();
        abort_unless(is_int($id) || is_string($id) && $id !== '', 403);
        return 'web:'.hash('sha256', (string) config('saola-admin.panel.guard')."\0".get_class($user)."\0".(string) $id);
    }

    private function body(Request $request, array $keys): array
    {
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 6 * (int) config('saola-admin.language.exchange.max_bytes', 2097152) + 65536, 413);
        try { $body = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { abort(422, 'Invalid JSON.'); }
        abort_unless(is_array($body) && !array_diff(array_keys($body), $keys), 422);
        return $body;
    }

    public function page(Request $request)
    {
        $this->allow($request, 'view');
        return $this->response(array_replace(app(PanelData::class)->prepare($request, 'languages'), ['languageEditor' => $this->editor($request)]), '@RAW:pages.languages');
    }

    public function editor(Request $request): array
    {
        $permissions = [];
        foreach (['view', 'manage', 'export', 'import', 'publish'] as $operation) $permissions[$operation] = Gate::forUser($request->user())->allows(config('saola-admin.language.abilities.'.$operation));
        return ['document' => app(LocaleManager::class)->state(), 'permissions' => $permissions, 'csrfToken' => csrf_token(),
            'endpoint' => route('saola.admin.languages.api', [], false), 'maxBytes' => (int) config('saola-admin.language.exchange.max_bytes', 2097152),
            'batches' => app(LanguageExchange::class)->recent($this->actor($request))];
    }

    public function index(Request $request)
    {
        $this->allow($request, 'view');
        if ($request->isMethod('PUT')) {
            $this->allow($request, 'manage');
            $body = $this->body($request, ['revision', 'uiDefault', 'uiFallback', 'contentDefault', 'locales']);
            $revision = $body['revision'] ?? null;
            abort_unless(is_int($revision) && $revision >= 0, 422);
            app(LocaleManager::class)->update($revision, $body);
        }
        return response()->json(['editor' => $this->editor($request)]);
    }

    public function exchange(Request $request, string $locale, string $operation)
    {
        $this->allow($request, 'view');
        $this->allow($request, $operation === 'export' ? 'export' : 'import');
        abort_unless(in_array($locale, array_column(app(LocaleManager::class)->state()['locales'], 'code'), true), 404);
        $exchange = app(LanguageExchange::class); $actor = $this->actor($request);
        try {
            if ($operation === 'export') {
                $this->body($request, []);
                return response()->json(['export' => $exchange->export($locale, $actor), 'editor' => $this->editor($request)]);
            }
            $data = app('validator')->make($this->body($request, ['json', 'exportBatch', 'confirmSource']), [
                'json' => ['required', 'string'], 'exportBatch' => ['required', 'uuid'], 'confirmSource' => ['sometimes', 'array', 'max:10000'], 'confirmSource.*' => ['string', 'max:500'],
            ])->validate();
            $preview = $exchange->preview($locale, $data['json'], $actor, $data['exportBatch'], $data['confirmSource'] ?? []);
            return response()->json(['preview' => $preview, 'editor' => $this->editor($request)]);
        } catch (\Saola\Admin\Language\LanguageBatchNotFound $error) { abort(404); } catch (\InvalidArgumentException $error) { throw \Illuminate\Validation\ValidationException::withMessages(['json' => $error->getMessage()]); }
    }

    public function batch(Request $request, string $batch)
    {
        $this->allow($request, 'view'); $this->allow($request, 'import');
        $exchange = app(LanguageExchange::class); $actor = $this->actor($request);
        try { $existing = $exchange->inspect($batch, $actor); }
        catch (\Saola\Admin\Language\LanguageBatchNotFound $error) { abort(404); }
        if ($request->isMethod('GET')) return response()->json(['preview' => $existing, 'editor' => $this->editor($request)]);
        $data = app('validator')->make($this->body($request, ['publish']), ['publish' => ['required', 'boolean']])->validate();
        abort_unless(is_bool($data['publish']), 422);
        if ($data['publish']) $this->allow($request, 'publish');
        try { $preview = $exchange->apply($batch, $actor, $data['publish']); }
        catch (\InvalidArgumentException $error) { throw \Illuminate\Validation\ValidationException::withMessages(['json' => $error->getMessage()]); }
        app(\Saola\Core\Language\TextResolver::class)->forget();
        return response()->json(['preview' => $preview, 'editor' => $this->editor($request)]);
    }
}

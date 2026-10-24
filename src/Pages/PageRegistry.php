<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Only stateless definitions belong in the registry; resolve page instances for each request. */
final class PageRegistry
{
    private array $pages = [];

    public function register(string $class): void
    {
        $page = app()->make($class);
        if (!$page instanceof Page || !preg_match('/^[a-z][a-z0-9_-]*$/D', $page->key()) || $page->ability() === '' || $page->key() === 'settings') throw new InvalidArgumentException('Invalid admin page definition.');
        if (isset($this->pages[$page->key()])) throw new InvalidArgumentException('Duplicate admin page: '.$page->key());
        $this->pages[$page->key()] = $class;
    }

    public function get(string $key): Page
    {
        if (!isset($this->pages[$key])) abort(404);
        return app()->make($this->pages[$key]);
    }

    public function navigation(Request $request): array
    {
        $items = [];
        foreach (array_keys($this->pages) as $key) {
            $page = $this->get($key);
            if ($this->allows($page, $request)) {
                $items[] = ['key' => $key, 'titleKey' => $page->titleKey(), 'url' => route('saola.admin.page', ['page' => $key])];
            }
        }
        return $items;
    }

    public function allows(Page $page, Request $request): bool
    {
        return Gate::forUser($request->user())->allows($page->ability())
            && (!$page instanceof AuthorizesPage || $page->allows($request));
    }
}

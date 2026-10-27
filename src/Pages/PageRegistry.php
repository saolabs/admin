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
    private array $groups = [];

    public function register(string $class): void
    {
        $page = app()->make($class);
        if (!$page instanceof Page || !preg_match('/^[a-z][a-z0-9_-]*$/D', $page->key()) || $page->ability() === '' || in_array($page->key(), ['settings', 'languages', 'media'], true)) throw new InvalidArgumentException('Invalid admin page definition.');
        if (isset($this->pages[$page->key()])) throw new InvalidArgumentException('Duplicate admin page: '.$page->key());
        if (isset($this->groups[$page->key()])) throw new InvalidArgumentException('Page key collides with a navigation group.');
        if ($page instanceof GroupedPage) {
            $group = $page->navigationGroup(); $title = $page->navigationGroupTitleKey();
            if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $group) || in_array($group, ['settings', 'languages', 'media'], true)
                || $title === '' || $group === $page->key() || isset($this->pages[$group])
                || isset($this->groups[$group]) && $this->groups[$group] !== $title) throw new InvalidArgumentException('Invalid admin navigation group.');
            $this->groups[$group] = $title;
        }
        $this->pages[$page->key()] = $class;
    }

    /** @return array<string, class-string<Page>> key => class, in registration order. */
    public function classes(): array { return $this->pages; }

    /** Key of the registered ModulePage serving route module $module, or ''. */
    public function keyForModule(string $module): string
    {
        foreach ($this->pages as $key => $class) {
            if (is_subclass_of($class, ModulePage::class) && $this->get($key)->module() === $module) return $key;
        }
        return '';
    }

    public function get(string $key): Page
    {
        if (!isset($this->pages[$key])) abort(404);
        return app()->make($this->pages[$key]);
    }

    public function navigation(Request $request): array
    {
        $items = [];
        foreach (array_keys($this->pages) as $index => $key) {
            $page = $this->get($key);
            $nav = $page instanceof NavigablePage ? $page->navigation() : [];
            if (($nav['hidden'] ?? false) || !$this->allows($page, $request)) continue;
            $item = ['key' => $key, 'titleKey' => $page->titleKey(), 'url' => $page instanceof LinkedPage ? $page->url() : route('saola.admin.page', ['page' => $key]),
                'order' => [(int) ($nav['order'] ?? 100), $index]];
            if (is_string($nav['icon'] ?? null)) $item['icon'] = $nav['icon'];
            if ($page instanceof GroupedPage) {
                $group = $page->navigationGroup();
                $items[$group] ??= ['key' => $group, 'titleKey' => $page->navigationGroupTitleKey(), 'url' => $item['url'], 'order' => $item['order'], 'children' => []];
                $items[$group]['children'][] = $item;
            } else $items[$key] = $item;
        }
        foreach ($items as &$item) {
            if (!isset($item['children'])) continue;
            usort($item['children'], fn ($a, $b) => $a['order'] <=> $b['order']);
            $item['order'] = $item['children'][0]['order']; $item['url'] = $item['children'][0]['url'];
            $item['icon'] ??= array_values(array_filter(array_column($item['children'], 'icon')))[0] ?? null;
            foreach ($item['children'] as &$child) unset($child['order'], $child['icon']);
            unset($child);
            if ($item['icon'] === null) unset($item['icon']);
        }
        unset($item);
        usort($items, fn ($a, $b) => $a['order'] <=> $b['order']);
        return array_map(function ($item) { unset($item['order']); return $item; }, $items);
    }

    public function allows(Page $page, Request $request): bool
    {
        return Gate::forUser($request->user())->allows($page->ability())
            && (!$page instanceof AuthorizesPage || $page->allows($request));
    }
}

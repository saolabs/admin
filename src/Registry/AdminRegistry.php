<?php

declare(strict_types=1);

namespace Saola\Admin\Registry;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Saola\Admin\Resources\Input;

/**
 * Admin UI configuration registered by modules from their ModuleServiceProvider: menu entries, lists and forms,
 * as plain arrays. Modules hold no UI classes; the package renders from this data and exports it as JSON.
 * Every entry is checked when it is registered (at boot), so a wrong key fails before any request.
 */
final class AdminRegistry
{
    private const KEY = '/^[a-z][a-z0-9_-]{0,63}$/D';
    private const MENU_KEYS = ['title', 'icon', 'order', 'route', 'url', 'ability', 'group', 'hidden'];
    private const GROUP_KEYS = ['title', 'icon', 'order'];
    private const LIST_KEYS = ['service', 'query', 'ability', 'columns', 'search', 'sort', 'title', 'description'];
    private const COLUMN_KEYS = ['label', 'type', 'decimals', 'value', 'ability'];
    private const FORM_KEYS = ['service', 'save', 'prepare', 'fields', 'messages', 'sections', 'layout'];
    private const FIELD_KEYS = ['type', 'label', 'rules', 'messages', 'placeholder', 'help', 'options', 'default', 'section', 'span',
        'readOnly', 'writeOnly', 'persist', 'ability', 'optionalOnUpdate', 'rows', 'maxItems', 'itemMaxLength', 'step', 'translatable'];

    private array $menus = [];
    private array $groups = [];
    private array $lists = [];
    private array $forms = [];

    public function menu(string $key, array $menu): void
    {
        $this->assertKey($key, 'menu');
        $this->assertKeys($menu, self::MENU_KEYS, "menu '{$key}'");
        if (!is_string($menu['title'] ?? null) || $menu['title'] === '') throw new InvalidArgumentException("Admin menu '{$key}' needs a title.");
        if (!is_string($menu['ability'] ?? null) || $menu['ability'] === '') throw new InvalidArgumentException("Admin menu '{$key}' needs an ability.");
        if (isset($menu['route']) === isset($menu['url'])) throw new InvalidArgumentException("Admin menu '{$key}' needs exactly one of route or url.");
        if (isset($menu['group'])) $this->assertKey((string) $menu['group'], 'menu group'); // existence checked by verify()
        if (isset($this->menus[$key]) || isset($this->groups[$key])) throw new InvalidArgumentException("Admin menu '{$key}' is registered twice.");
        $this->menus[$key] = $menu;
    }

    public function group(string $key, array $group): void
    {
        $this->assertKey($key, 'menu group');
        $this->assertKeys($group, self::GROUP_KEYS, "menu group '{$key}'");
        if (!is_string($group['title'] ?? null) || $group['title'] === '') throw new InvalidArgumentException("Admin menu group '{$key}' needs a title.");
        // Several modules may share a group (e.g. AI screens from two modules): the same definition again is fine.
        if (isset($this->groups[$key]) && $this->groups[$key] === $group) return;
        if (isset($this->groups[$key]) || isset($this->menus[$key])) throw new InvalidArgumentException("Admin menu group '{$key}' is registered twice with different settings.");
        $this->groups[$key] = $group;
    }

    public function list(string $key, array $list): void
    {
        $this->assertKey($key, 'list');
        $this->assertKeys($list, self::LIST_KEYS, "list '{$key}'");
        $this->assertService($list['service'] ?? null, "list '{$key}'");
        if (!is_array($list['columns'] ?? null) || $list['columns'] === []) throw new InvalidArgumentException("Admin list '{$key}' needs columns.");
        foreach ($list['columns'] as $column => $spec) {
            if (!is_string($column) || !is_array($spec)) throw new InvalidArgumentException("Admin list '{$key}' has an invalid column.");
            $this->assertKeys($spec, self::COLUMN_KEYS, "list '{$key}' column '{$column}'");
            if (!is_string($spec['label'] ?? null)) throw new InvalidArgumentException("Admin list '{$key}' column '{$column}' needs a label.");
            if (isset($spec['value'])) $this->assertCallable($spec['value'], "list '{$key}' column '{$column}' value");
        }
        foreach (['search', 'sort'] as $part) if (isset($list[$part]) && (!is_array($list[$part]) || array_filter($list[$part], fn ($c) => !is_string($c)))) throw new InvalidArgumentException("Admin list '{$key}' {$part} must list column names.");
        if (isset($list['query'])) $this->assertCallable($list['query'], "list '{$key}' query");
        if (isset($this->lists[$key])) throw new InvalidArgumentException("Admin list '{$key}' is registered twice.");
        $this->lists[$key] = $list;
    }

    public function form(string $key, array $form): void
    {
        $this->assertKey($key, 'form');
        $this->assertKeys($form, self::FORM_KEYS, "form '{$key}'");
        $this->assertService($form['service'] ?? null, "form '{$key}'");
        foreach (['save', 'prepare'] as $hook) if (isset($form[$hook])) $this->assertCallable($form[$hook], "form '{$key}' {$hook}");
        if (!is_array($form['fields'] ?? null) || $form['fields'] === []) throw new InvalidArgumentException("Admin form '{$key}' needs fields.");
        $this->assertMessages($form['messages'] ?? [], "form '{$key}'");
        foreach ($form['fields'] as $name => $field) {
            $where = "form '{$key}' field '{$name}'";
            if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name) || !is_array($field)) throw new InvalidArgumentException("Admin {$where} is invalid.");
            $this->assertKeys($field, self::FIELD_KEYS, $where);
            $type = $field['type'] ?? null;
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $type)) throw new InvalidArgumentException("Admin {$where} needs a type.");
            if (!is_string($field['label'] ?? null) || $field['label'] === '') throw new InvalidArgumentException("Admin {$where} needs a label.");
            if (isset($field['rules']) && (!is_array($field['rules']) || array_filter($field['rules'], fn ($r) => !is_string($r) && !is_object($r)))) throw new InvalidArgumentException("Admin {$where} rules must be a list of Laravel rules.");
            $this->assertMessages($field['messages'] ?? [], $where);
            if (isset($field['options']) && !is_array($field['options'])) throw new InvalidArgumentException("Admin {$where} options must be an array or [Service::class, 'method'].");
            if (isset($field['options']) && array_is_list($field['options']) && count($field['options']) === 2 && is_string($field['options'][0]) && class_exists($field['options'][0])) $this->assertCallable($field['options'], "{$where} options");
            if ($type === 'password' && !($field['writeOnly'] ?? false)) throw new InvalidArgumentException("Admin {$where}: password fields must be writeOnly.");
        }
        if (isset($this->forms[$key])) throw new InvalidArgumentException("Admin form '{$key}' is registered twice.");
        $this->forms[$key] = $form;
    }

    /** Cross-entry checks, run once every module has booted (registration order between modules is not fixed). */
    public function verify(): void
    {
        foreach ($this->menus as $key => $menu) {
            if (isset($menu['group']) && !isset($this->groups[$menu['group']])) throw new InvalidArgumentException("Admin menu '{$key}' uses unknown group '{$menu['group']}'; register it with Admin::group().");
            if (isset($this->groups[$key])) throw new InvalidArgumentException("Admin menu '{$key}' collides with a menu group.");
        }
        foreach ($this->forms as $key => $form) if (!isset($this->lists[$key])) throw new InvalidArgumentException("Admin form '{$key}' has no list with the same key.");
    }

    public function hasList(string $key): bool { return isset($this->lists[$key]) || isset($this->engines[$key]); }
    /** @return list<string> keys with a list (registered or engine), for admin:doctor. */
    public function listKeys(): array { return array_values(array_unique([...array_keys($this->lists), ...array_keys($this->engines)])); }
    public function menuOf(string $key): ?array { return $this->menus[$key] ?? null; }
    public function menus(): array { return $this->menus; }

    /** @internal Package tests drive the CRUD engine with a hand-written definition; projects use list()/form(). */
    private array $engines = [];
    public function engine(string $key, \Saola\Admin\Resources\Resource|string $definition): void { $this->engines[$key] = $definition; }

    /** The list + form registered under $key, as the definition the package's CRUD screens run on. */
    public function definition(string $key): \Saola\Admin\Resources\Resource
    {
        if (isset($this->engines[$key])) return is_string($this->engines[$key]) ? app($this->engines[$key]) : $this->engines[$key];
        if (!isset($this->lists[$key])) abort(404);
        return new RegisteredResource($key, $this->lists[$key], $this->forms[$key] ?? null, $this->menus[$key] ?? null);
    }

    /** Sidebar for this request: menus the user may open, grouped and ordered. */
    public function navigation(Request $request): array
    {
        $gate = Gate::forUser($request->user());
        $items = []; $index = 0;
        foreach ($this->menus as $key => $menu) {
            $index++;
            if (($menu['hidden'] ?? false) || !$gate->allows($menu['ability'])) continue;
            $item = ['key' => $key, 'titleKey' => $menu['title'], 'url' => $this->url($menu), 'order' => [(int) ($menu['order'] ?? 100), $index]];
            if (isset($menu['icon'])) $item['icon'] = $menu['icon'];
            if (isset($menu['group'])) {
                $group = $this->groups[$menu['group']]; $g = $menu['group'];
                $items[$g] ??= ['key' => $g, 'titleKey' => $group['title'], 'url' => $item['url'], 'order' => [(int) ($group['order'] ?? $item['order'][0]), $index], 'children' => []]
                    + (isset($group['icon']) ? ['icon' => $group['icon']] : []);
                unset($item['icon']);
                $items[$g]['children'][] = $item;
            } else $items[$key] = $item;
        }
        foreach ($items as &$item) {
            if (!isset($item['children'])) continue;
            usort($item['children'], fn ($a, $b) => $a['order'] <=> $b['order']);
            $item['url'] = $item['children'][0]['url'];
            foreach ($item['children'] as &$child) unset($child['order']);
            unset($child);
        }
        unset($item);
        usort($items, fn ($a, $b) => $a['order'] <=> $b['order']);
        return array_map(function ($item) { unset($item['order']); return $item; }, $items);
    }

    public function url(array $menu): string
    {
        return isset($menu['route']) ? route($menu['route'], [], false) : (string) $menu['url'];
    }

    /**
     * Rules the browser may check itself. Everything else stays on the server: unique/exists name tables and columns,
     * rule objects and closures are backend code, regex is PCRE (and often an internal format).
     */
    public static function clientRules(array $rules): array
    {
        return array_values(array_filter($rules, fn ($r) => is_string($r)
            && preg_match('/^(required|nullable|email|url|numeric|integer|boolean|date|confirmed|(min|max|between|size|digits|digits_between):[0-9.,]+|(in|not_in):[^,]+(,[^,]+)*)$/D', $r)));
    }

    /** Message keys of those browser rules only: a key named after a server rule ("unique") would still tell what the server checks. */
    public static function clientMessages(array $messages): array
    {
        return array_intersect_key($messages, array_flip(['required', 'email', 'url', 'numeric', 'integer', 'boolean', 'date', 'confirmed',
            'min', 'max', 'between', 'size', 'digits', 'digits_between', 'in', 'not_in']));
    }

    /**
     * What the front end may see of the registration, for this request: entries and fields the user may open, presentation
     * keys only. Never service/callable class names, queries, abilities, route names or server-only rules.
     */
    public function toArray(Request $request): array
    {
        $gate = Gate::forUser($request->user());
        $allowed = fn (?string $ability) => $ability === null || $gate->allows($ability);
        $pick = fn (array $from, array $keys) => array_intersect_key($from, array_flip($keys));
        $lists = $forms = [];
        foreach ($this->lists as $key => $list) {
            if (!$allowed($list['ability'] ?? $this->menus[$key]['ability'] ?? null)) continue;
            $lists[$key] = $pick($list, ['title', 'description', 'search', 'sort']) + ['columns' => array_map(
                fn ($column) => $pick($column, ['label', 'type', 'decimals']),
                array_filter($list['columns'], fn ($column) => $allowed($column['ability'] ?? null)),
            )];
            if (!isset($this->forms[$key])) continue;
            $form = $this->forms[$key];
            $forms[$key] = $pick($form, ['sections', 'layout']) + ['messages' => self::clientMessages($form['messages'] ?? [])] + ['fields' => array_map(function ($field) use ($pick) {
                $out = $pick($field, ['type', 'label', 'placeholder', 'help', 'default', 'section', 'span', 'readOnly', 'writeOnly',
                    'optionalOnUpdate', 'rows', 'maxItems', 'itemMaxLength', 'step', 'translatable']);
                $out['rules'] = self::clientRules($field['rules'] ?? []);
                $out['messages'] = self::clientMessages($field['messages'] ?? []);
                // Options from a service are resolved per record by the editor endpoint; only literal lists travel here.
                if (isset($field['options']) && !(array_is_list($field['options']) && count($field['options']) === 2 && is_string($field['options'][0]) && class_exists($field['options'][0]))) $out['options'] = $field['options'];
                return $out;
            }, array_filter($form['fields'], fn ($field) => $allowed($field['ability'] ?? null)))];
        }
        return ['navigation' => $this->navigation($request), 'lists' => $lists, 'forms' => $forms];
    }

    private function assertKey(string $key, string $what): void
    {
        if (!preg_match(self::KEY, $key)) throw new InvalidArgumentException("Admin {$what} key '{$key}' must be lowercase letters, digits, - or _.");
    }

    private function assertKeys(array $data, array $allowed, string $where): void
    {
        if ($unknown = array_diff(array_keys($data), $allowed)) throw new InvalidArgumentException("Admin {$where}: unknown key(s) ".implode(', ', $unknown).'.');
    }

    private function assertService(mixed $service, string $where): void
    {
        if (!is_string($service) || !class_exists($service) || !is_subclass_of($service, \Saola\Core\Services\ModuleService::class)) {
            throw new InvalidArgumentException("Admin {$where} needs 'service' => a ModuleService class of the module.");
        }
    }

    private function assertCallable(mixed $callable, string $where): void
    {
        if (!is_array($callable) || count($callable) !== 2 || !is_string($callable[0]) || !is_string($callable[1]) || !method_exists($callable[0], $callable[1])) {
            throw new InvalidArgumentException("Admin {$where} must be [Class::class, 'method'] of an existing method.");
        }
    }

    private function assertMessages(mixed $messages, string $where): void
    {
        if (!is_array($messages) || array_filter($messages, fn ($m, $r) => !is_string($r) || !is_string($m), ARRAY_FILTER_USE_BOTH)) throw new InvalidArgumentException("Admin {$where} messages must map a rule to a translation key.");
    }
}

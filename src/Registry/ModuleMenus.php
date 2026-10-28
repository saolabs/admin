<?php

declare(strict_types=1);

namespace Saola\Admin\Registry;

use InvalidArgumentException;
use Saola\Admin\Admin;
use Saola\Core\Routing\Module;

/**
 * menu()/list()/form() on the route builder, so a module's admin screen is declared where its routes are:
 *
 *   System::context('admin')->module('products')->menu(['title' => '…', 'order' => 50])        // has sub-menus → a group
 *       ->sub('categories')->controller(CategoryController::class)
 *       ->menu(['title' => '…', 'ability' => '…'])->list([...])->form([...])
 *       ->routes(fn ($m) => CategoryController::routes($m));                                      // key products.categories
 *
 * The key is the module's route name without the context ("admin.products.categories" → "products.categories"); modules
 * without a name are named after their slug, so key, route names and URL prefix all follow the module tree.
 */
final class ModuleMenus
{
    public static function register(): void
    {
        Module::macro('menu', function (array $menu = []) {
            app(AdminRegistry::class)->moduleMenu(...ModuleMenus::locate($this), menu: $menu);
            return $this;
        });
        Module::macro('list', function (array $list) { Admin::list(ModuleMenus::locate($this)[0], $list); return $this; });
        Module::macro('form', function (array $form) { Admin::form(ModuleMenus::locate($this)[0], $form); return $this; });
    }

    /** @return array{0:string,1:string,2:list<string>} key, route name, ancestor module keys nearest first */
    public static function locate(Module $module): array
    {
        return (function () {
            $chain = [];
            for ($node = $this; $node && !$node->isContext(); $node = $node->parent) {
                $node->data['as'] ??= ($node->data['slug'] ?? null) ?: throw new InvalidArgumentException('Admin menu/list/form need modules with a slug.');
                $chain[] = $node;
            }
            if (!$node || $node->getFullRouteName() !== 'admin') throw new InvalidArgumentException('Admin menu/list/form belong on modules of the admin context.');
            $key = fn ($n) => substr($n->getFullRouteName(), strlen('admin.'));
            return [$key($this), $this->getFullRouteName(), array_map($key, array_slice($chain, 1))];
        })->call($module);
    }
}

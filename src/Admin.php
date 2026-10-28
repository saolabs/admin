<?php

declare(strict_types=1);

namespace Saola\Admin;

use Saola\Admin\Registry\AdminRegistry;

/**
 * Registration point for modules, called from a module's ModuleServiceProvider::boot():
 *
 *   Admin::menu('users', ['title' => 'admin.users.title', 'route' => 'admin.users.index', 'ability' => '…', 'icon' => 'users', 'order' => 30]);
 *   Admin::list('users', ['service' => UserService::class, 'columns' => ['name' => ['label' => '…']], 'search' => ['name']]);
 *   Admin::form('users', ['service' => UserService::class, 'fields' => ['name' => ['type' => 'text', 'label' => '…', 'rules' => ['required', 'max:100']]]]);
 *
 * See docs/ADMIN_REGISTRY_PLAN.md for every key.
 */
final class Admin
{
    public static function menu(string $key, array $menu): void { app(AdminRegistry::class)->menu($key, $menu); }
    public static function group(string $key, array $group): void { app(AdminRegistry::class)->group($key, $group); }
    public static function list(string $key, array $list): void { app(AdminRegistry::class)->list($key, $list); }
    public static function form(string $key, array $form): void { app(AdminRegistry::class)->form($key, $form); }
}

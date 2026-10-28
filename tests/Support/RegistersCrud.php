<?php

namespace Saola\Admin\Tests\Support;

use Illuminate\Support\Facades\Route;
use Saola\Admin\Http\AdminAccess;
use Saola\Admin\Registry\AdminRegistry;
use Saola\Admin\Resources\Resource;

/** A module's controller as a project would write it: it extends the package's admin controller. */
class CrudController extends \Saola\Admin\Http\AdminController {}

/** Engine tests: attach a hand-written definition under $key and declare the module routes a project would. */
trait RegistersCrud
{
    protected function crud(string $key, Resource|string $definition, string $prefix = 'control'): void
    {
        app(AdminRegistry::class)->engine($key, $definition);
        Route::middleware(['web', AdminAccess::class])->prefix($prefix.'/'.$key)->name('admin.'.$key.'.')->group(fn () => CrudController::routes());
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}

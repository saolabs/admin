<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Closure;
use Illuminate\Http\Request;
use Saola\Admin\Language\LocaleManager;

/** Optional host web middleware (saola.locale); never leaks request locale into a worker. */
final class ResolveUiLocale
{
    public function handle(Request $request, Closure $next)
    {
        $previous = app()->getLocale();
        try { app()->setLocale(app(LocaleManager::class)->resolveUi($request)); return $next($request); }
        finally { app()->setLocale($previous); }
    }
}

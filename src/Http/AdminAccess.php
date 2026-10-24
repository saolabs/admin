<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class AdminAccess
{
    public function handle(Request $request, Closure $next)
    {
        $guard = config('saola-admin.panel.guard');
        $user = $guard ? Auth::guard($guard)->user() : $request->user();
        if (!$user) {
            $login = config('saola-admin.panel.login_route');
            if (!$request->expectsJson() && is_string($login) && $login !== '') return redirect()->guest(route($login));
            abort(401);
        }
        $request->setUserResolver(fn () => $user);
        $ability = config('saola-admin.panel.ability', 'access-admin');
        abort_unless(is_string($ability) && $ability !== '' && Gate::forUser($user)->allows($ability), 403);
        $application = app();
        $previousLocale = $application->getLocale();
        try { $response = $next($request); }
        finally {
            // Long-lived workers must not reuse the previous operator's UI locale.
            if ($application->getLocale() !== $previousLocale) $application->setLocale($previousLocale);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary(['Cookie', 'Authorization', 'Accept-Language'], false);
        return $response;
    }
}

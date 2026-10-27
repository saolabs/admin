<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;

/** Explicit projection of the current operator; never serialize the host user model. */
class HeaderAccount
{
    public function project(Request $request): array
    {
        $user = $request->user();
        $read = static function (string $key, int $limit) use ($user): string {
            $attribute = config('saola-admin.account.'.$key);
            $value = is_string($attribute) && $attribute !== '' ? data_get($user, $attribute) : null;
            return is_string($value) ? mb_substr(trim($value), 0, $limit) : '';
        };
        $name = $read('name_attribute', 160); $email = $read('email_attribute', 200);
        $avatar = $read('avatar_attribute', 2048);
        if ($avatar !== '' && (preg_match('/[\x00-\x20\\\\]/', $avatar)
            || !(str_starts_with($avatar, '/') && !str_starts_with($avatar, '//')
                || filter_var($avatar, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($avatar, PHP_URL_SCHEME)), ['https', 'http'], true)))) $avatar = '';
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = $parts ? mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '') : 'U';
        $links = [];
        foreach (['profile', 'settings'] as $key) {
            if ($url = $this->namedRoute($key.'_route', 'GET')) $links[] = ['key' => $key, 'titleKey' => 'admin.shell.'.$key, 'url' => $url];
        }
        $logout = $this->namedRoute('logout_route', 'POST'); $method = 'POST';
        if ($logout === '') { $logout = $this->namedRoute('logout_route', 'DELETE'); $method = 'DELETE'; }
        return ['name' => $name, 'email' => $email, 'subtitle' => $read('subtitle_attribute', 100), 'avatar' => $avatar,
            'initials' => mb_strtoupper($initials), 'links' => $links, 'logoutUrl' => $logout, 'logoutMethod' => $method, 'csrfToken' => csrf_token()];
    }

    private function namedRoute(string $key, string $method): string
    {
        $name = config('saola-admin.account.'.$key);
        if (!is_string($name) || $name === '') return '';
        $route = app('router')->getRoutes()->getByName($name);
        if (!$route || !in_array($method, $route->methods(), true)) return '';
        try { return route($name, [], false); }
        catch (\Illuminate\Routing\Exceptions\UrlGenerationException) { return ''; }
    }
}

<?php

declare(strict_types=1);

namespace Saola\Admin;

use Saola\Admin\Settings\PublicSettings;

/** Builds the web manifest, service worker and <head> tags from the public `pwa` settings (falling back to general/branding). */
final class Pwa
{
    public static function settings(?string $locale = null): array
    {
        // Cùng nguồn đã cache với `__site`: không query lại ở mỗi trang.
        $site = app(PublicSettings::class)->data($locale ?? app()->getLocale());
        $pwa = $site['pwa'] ?? [];
        // pwa.* thắng general.* ở tên trùng, nên đặt tiền tố.
        return ($site['general'] ?? []) + ($site['branding'] ?? [])
            + array_combine(array_map(fn ($k) => 'pwa_'.$k, array_keys($pwa)), $pwa);
    }

    public static function enabled(): bool
    {
        return (bool) (self::settings()['pwa_enabled'] ?? false);
    }

    public static function manifest(?string $locale = null): array
    {
        $v = self::settings($locale);
        $first = static fn (string ...$keys) => collect($keys)->map(fn ($k) => trim((string) ($v[$k] ?? '')))->first(fn ($s) => $s !== '') ?? '';
        $name = $first('pwa_name', 'website_name') ?: (string) config('app.name', 'App');
        $color = static fn (string $key) => preg_match('/^#[0-9a-fA-F]{6}$/D', (string) ($v[$key] ?? '')) ? $v[$key] : '#ffffff';
        $icon192 = self::url($first('pwa_icon_192_url', 'favicon_url'));
        $icon512 = self::url($first('pwa_icon_512_url')) ?: $icon192;
        $icons = [];
        if ($icon192) $icons[] = ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'];
        if ($icon512) $icons[] = ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'];
        if ($mask = self::url($first('pwa_icon_maskable_url'))) $icons[] = ['src' => $mask, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];

        return array_filter([
            'name' => $name,
            'short_name' => mb_substr($first('pwa_short_name', 'short_name') ?: $name, 0, 30),
            'description' => $first('pwa_description', 'description'),
            'start_url' => self::url($first('pwa_start_url')) ?: '/',
            'scope' => '/',
            'display' => $v['pwa_display'] ?? 'standalone',
            'theme_color' => $color('pwa_theme_color'),
            'background_color' => $color('pwa_background_color'),
            'icons' => $icons,
        ], fn ($x) => $x !== '' && $x !== []);
    }

    /** Minimal worker: installable, always network (no stale-cache risk). ponytail: add an offline cache strategy only when a host asks for one. */
    public static function serviceWorker(): string
    {
        return "self.addEventListener('install',()=>self.skipWaiting());\n"
            ."self.addEventListener('activate',e=>e.waitUntil(self.clients.claim()));\n"
            ."self.addEventListener('fetch',()=>{});\n";
    }

    /** Tags for the host layout's <head>; empty when PWA is off. */
    public static function headTags(): string
    {
        if (!self::enabled()) return '';
        $color = e(self::manifest()['theme_color'] ?? '#ffffff');
        return '<link rel="manifest" href="/manifest.webmanifest"><meta name="theme-color" content="'.$color.'">'
            .'<script>"serviceWorker"in navigator&&addEventListener("load",()=>navigator.serviceWorker.register("/sw.js"))</script>';
    }

    private static function url(string $value): string
    {
        return $value !== '' && !preg_match('/[\x00-\x20\\\\]/', $value) && (str_starts_with($value, '/') && !str_starts_with($value, '//') || preg_match('~^https?://~i', $value)) ? $value : '';
    }
}

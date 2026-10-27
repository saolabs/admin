<?php

declare(strict_types=1);

namespace Saola\Admin\Themes;

/** A theme selects presentation only; routes, authorization and stores stay in admin. */
final class AdminTheme
{
    public function definition(): array
    {
        $name = config('saola-admin.panel.theme', 'classic');
        if (!is_string($name) || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $name)) throw new \InvalidArgumentException('Invalid admin theme name.');
        $themes = $this->all();
        $theme = $themes[$name] ?? null;
        if (!is_array($theme)) throw new \InvalidArgumentException('Admin theme is not registered: '.$name);
        $base = $theme['view_context'] ?? 'admin.themes.'.$name;
        $css = $theme['css'] ?? 'resources/saola/admin/themes/'.$name.'/assets/css/admin.css';
        if (!is_string($base) || !preg_match('/^admin\.[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)*$/D', $base)
            || !is_string($css) || !preg_match('~^resources/saola/admin/themes/[a-z][a-z0-9-]*/assets/[A-Za-z0-9_./-]+\.css$~D', $css)
            || in_array('..', explode('/', $css), true)) throw new \InvalidArgumentException('Invalid admin theme view context or CSS entry.');
        return ['name' => $name, 'view_context' => $base, 'css' => $css];
    }

    public function all(): array
    {
        $themes = [];
        foreach ([...(glob(__DIR__.'/../../resources/saola/admin/themes/*/theme.manifest.json') ?: []),
            ...(glob(resource_path('saola/admin/themes/*/theme.manifest.json')) ?: [])] as $file) {
            $manifest = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            $name = basename(dirname($file));
            if (($manifest['contract'] ?? null) !== 1 || ($manifest['name'] ?? null) !== $name) throw new \InvalidArgumentException('Invalid admin theme manifest: '.$name);
            $themes[$name] = ['view_context' => $manifest['view_context'] ?? 'admin.themes.'.$name,
                'css' => 'resources/saola/admin/themes/'.$name.'/'.($manifest['css'] ?? 'assets/css/admin.css')];
        }
        return array_replace($themes, config('saola-admin.themes', []));
    }

    public function apply(): array
    {
        $theme = $this->definition();
        // Resolve again for every request; no selected theme is captured in an Octane worker.
        \Saola\Core\Saola::share('saola.context.admin', static fn () => [
            'theme' => app(self::class)->definition()['name'],
            'bundleKinds' => method_exists(app(\Saola\Core\Services\BundleManifest::class), 'bundleKinds')
                ? app(\Saola\Core\Services\BundleManifest::class)->bundleKinds('admin') : [],
        ]);
        $views = app(\Saola\Core\Engines\ViewContextManager::class);
        $host = config('saola-admin.panel.host_modules');
        if (is_string($host) && $host !== '') {
            // Host screens live in their own namespace while the shell, layouts, components and package pages stay on the theme.
            // Only the "modules" directory moves, so client routes name both groups correctly (@MODULE: host, @BASE: theme).
            $base = $theme['view_context'];
            $views->registerContext('admin', ['base' => $base, 'modules' => $host], [
                '__system__' => '_system.', '__base__' => $base.'.', '__component__' => $base.'.components.', '__template__' => $base.'.templates.',
                '__partial__' => $base.'.partials.', '__layout__' => $base.'.layouts.', '__module__' => $host.'.', '__page__' => $base.'.pages.',
            ]);
        } else {
            $views->registerContext('admin', ['base' => $theme['view_context']]);
        }
        config(['sao.assets.css.admin' => $theme['css']]);
        return $theme;
    }
}

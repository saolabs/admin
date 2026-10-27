<?php

declare(strict_types=1);

namespace Saola\Admin\Themes;

/**
 * Names of the project's own views that the theme includes, beside its screens (panel.host_modules = "admin.modules"):
 *   admin.inputs.{template}   custom form input        resources/saola/admin/views/inputs/{template}.sao
 *   admin.columns.{renderer}  custom list cell         resources/saola/admin/views/columns/{renderer}.sao
 *   admin.resources.{key}     whole Resource screen    resources/saola/admin/views/resources/{key}.sao
 * They sit in the same bundle and Blade tree as the host modules, so SSR and CSR resolve the same name.
 */
final class HostViews
{
    public static function name(string $kind, string $name): string
    {
        $host = config('saola-admin.panel.host_modules');
        if (!is_string($host) || $host === '') throw new \LogicException("Project $kind views need saola-admin.panel.host_modules.");
        $root = str_contains($host, '.') ? substr($host, 0, strrpos($host, '.')) : $host;
        return $root.'.'.$kind.'.'.$name;
    }

    /** The view when the project has compiled it, else null. */
    public static function existing(string $kind, string $name): ?string
    {
        $host = config('saola-admin.panel.host_modules');
        if (!is_string($host) || $host === '') return null;
        $view = self::name($kind, $name);
        return view()->exists($view) ? $view : null;
    }
}

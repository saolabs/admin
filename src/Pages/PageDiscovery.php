<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

/**
 * Finds the host's Page classes by convention: every instantiable Page under app/Modules/{Module}/{Resources,Pages}.
 * Adding a file adds the page; no list to edit. Patterns are relative to app_path() and map onto the app namespace (PSR-4).
 */
final class PageDiscovery
{
    public const DEFAULT_PATTERNS = ['Modules/*/Resources', 'Modules/*/Pages'];

    /** The app's PSR-4 namespace ("App\\"); falls back to App\\ when composer.json does not map app_path(). */
    public static function appNamespace(): string
    {
        try { return app()->getNamespace(); } catch (\RuntimeException) { return 'App\\'; }
    }

    /** @return list<class-string<Page>> */
    public static function classes(array $patterns = self::DEFAULT_PATTERNS): array
    {
        // ponytail: globs on every boot; cache the list (like route:cache) if module count grows into the hundreds.
        $root = rtrim(app_path(), '/').'/'; $namespace = null; $classes = [];
        foreach ($patterns as $pattern) {
            foreach (glob($root.trim($pattern, '/').'/*.php') ?: [] as $file) {
                $namespace ??= self::appNamespace();
                $class = $namespace.str_replace('/', '\\', substr($file, strlen($root), -4));
                if (class_exists($class) && is_subclass_of($class, Page::class) && (new \ReflectionClass($class))->isInstantiable()) $classes[] = $class;
            }
        }
        return $classes;
    }
}

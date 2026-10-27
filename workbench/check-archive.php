<?php

declare(strict_types=1);

/** Release check: consume the archive's source, not the current checkout's publisher. */
require __DIR__.'/../vendor/autoload.php';

$archive = new ZipArchive();
if ($archive->open($argv[1] ?? '') !== true) throw new RuntimeException('Cannot open the Composer ZIP.');
$root = realpath(sys_get_temp_dir()).'/saola-admin-archive-'.bin2hex(random_bytes(8));
mkdir($root, 0777, true);
$package = $root.'/package';
try {
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $name = $archive->getNameIndex($index);
        $parts = explode('/', $name);
        if (str_starts_with($name, '/') || str_contains($name, '\\') || in_array('..', $parts, true)
            || array_intersect(['.git', 'node_modules', '.env', '.github'], $parts)
            || (in_array('vendor', $parts, true) && !preg_match('~^resources/saola/admin/themes/[a-z][a-z0-9-]*/assets/vendor/~', $name))
            || str_ends_with($name, '.sqlite')) throw new RuntimeException('Invalid archive entry: '.$name);
    }
    $archive->extractTo($package); $archive->close();
    foreach (['LICENSE', 'composer.json', 'src/Publishing/SourcePublisher.php', 'src/Users/UserDirectory.php', 'src/Language/LocaleManager.php', 'src/Http/LanguagesController.php',
        'database/migrations/2026_10_24_000004_add_locale_management.php', 'resources/saola/admin/app/language-form.ts',
        'src/Media/MediaLibrary.php', 'src/Http/MediaController.php', 'database/migrations/2026_10_25_000005_create_media.php',
        'resources/saola/admin/app/media-library.ts', 'resources/saola/admin/themes/classic/views/components/media-library.sao', 'resources/saola/admin/themes/classic/views/pages/media.sao',
        'lang/en/admin.php', 'lang/vi/admin.php', 'resources/saola/admin/context.manifest.json',
        'resources/saola/admin/themes/classic/assets/css/admin.css', 'resources/saola/admin/themes/classic/assets/images/mark.svg',
        'src/Themes/AdminTheme.php', 'resources/saola/admin/themes/material/theme.manifest.json', 'resources/saola/admin/themes/material/assets/vendor/material/css/app.min.css', 'resources/saola/admin/build/themes.mjs', 'src/Resources/Resource.php', 'src/Resources/ResourceStore.php', 'src/Resources/EloquentResourceStore.php', 'src/Http/ResourceController.php', 'docs/RESOURCES.md',
        'database/migrations/2026_10_26_000006_create_resource_events.php', 'resources/saola/admin/app/resource-manager.ts',
        'resources/saola/admin/themes/classic/views/components/manager.sao',
        'resources/saola/admin/app/views/binding.ts', 'resources/saola/admin/app/views/page.ts',
        'resources/saola/admin/app/views/manager.ts', 'resources/saola/admin/app/views/resource.ts',
        'resources/saola/admin/app/views/settings-form.ts', 'resources/saola/admin/app/views/languages.ts',
        'resources/saola/admin/app/views/media-library.ts', 'resources/saola/admin/app/views/shell.ts',
        'src/Resources/Input.php', 'src/Resources/RichText.php', 'src/Resources/SafeUrlRule.php', 'examples/CatalogResource.php',
        'resources/saola/admin/app/feedback.ts', 'resources/saola/admin/app/views/input.ts', 'resources/saola/admin/app/url.ts',
        'resources/saola/admin/themes/classic/views/components/input.sao', 'resources/saola/admin/themes/material/views/components/input.sao',
        'resources/saola/admin/themes/material/app/presentation.ts',
        'resources/saola/admin/app/views/ui.ts', 'resources/saola/admin/app/contracts/ui.ts',
        'resources/saola/admin/themes/material/views/components/ui/button.sao',
        'resources/saola/admin/themes/material/views/components/ui/card.sao',
        'resources/saola/admin/themes/material/views/components/ui/dialog.sao',
        'resources/saola/admin/themes/material/views/pages/ui-library.sao',
        'resources/saola/admin/themes/material/assets/ui.sources.json', 'docs/UI_COMPONENTS.md',
        'resources/saola/admin/themes/classic/views/components/resource.sao', 'resources/saola/admin/themes/classic/views/components/settings-form.sao'] as $path) {
        if (!is_file($package.'/'.$path)) throw new RuntimeException('Missing archive source: '.$path);
    }
    if (is_dir($package.'/resources/saola/admin/_shared')) throw new RuntimeException('Archive contains obsolete shared runtime source.');
    require $package.'/src/Publishing/SourcePublisher.php';
    $publisher = new \Saola\Admin\Publishing\SourcePublisher($package.'/resources/saola/admin', $root.'/host/resources/saola/admin');
    $first = $publisher->publish();
    $second = $publisher->publish();
    if ($first['conflicts'] || !isset($first['actions']['themes/classic/theme.manifest.json'], $first['actions']['themes/material/theme.manifest.json']) || $second['conflicts'] || $second['actions']) {
        throw new RuntimeException('Archive source publication failed or was not idempotent.');
    }
    echo "Composer ZIP inventory and extracted source publication passed.\n";
} finally {
    (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($root);
}

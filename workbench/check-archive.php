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
            || array_intersect(['.git', 'vendor', 'node_modules', '.env', '.github'], $parts)
            || str_ends_with($name, '.sqlite')) throw new RuntimeException('Invalid archive entry: '.$name);
    }
    $archive->extractTo($package); $archive->close();
    foreach (['LICENSE', 'composer.json', 'src/Publishing/SourcePublisher.php', 'src/Users/UserDirectory.php',
        'lang/en/admin.php', 'lang/vi/admin.php', 'resources/saola/admin/admin.manifest.json',
        'resources/saola/admin/assets/css/admin.css', 'resources/saola/admin/assets/images/mark.svg',
        'resources/saola/admin/views/components/resource.sao', 'resources/saola/admin/views/components/settings-form.sao'] as $path) {
        if (!is_file($package.'/'.$path)) throw new RuntimeException('Missing archive source: '.$path);
    }
    require $package.'/src/Publishing/SourcePublisher.php';
    $publisher = new \Saola\Admin\Publishing\SourcePublisher($package.'/resources/saola/admin', $root.'/host/resources/saola/admin');
    $first = $publisher->publish();
    $second = $publisher->publish();
    if ($first['conflicts'] || count($first['actions']) !== 12 || $second['conflicts'] || $second['actions']) {
        throw new RuntimeException('Archive source publication failed or was not idempotent.');
    }
    echo "Composer ZIP inventory and extracted source publication passed.\n";
} finally {
    (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($root);
}

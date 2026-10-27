<?php

declare(strict_types=1);

namespace Saola\Admin\Console;

use Illuminate\Console\Command;
use Saola\Admin\Publishing\SourcePublisher;
use Saola\Core\Services\BundleManifest;

final class DoctorAdminCommand extends Command
{
    protected $signature = 'admin:doctor';
    protected $description = 'Check frontend source, bootstrap, host context, compiled view and CSS integration without changing files';

    public function handle(): int
    {
        $errors = [];
        $theme = app(\Saola\Admin\Themes\AdminTheme::class)->definition();
        $namespace = $theme['view_context'];
        $root = resource_path('saola/admin');
        $configFile = base_path('sao.config.json');
        $config = is_file($configFile) ? json_decode(file_get_contents($configFile), true) : [];
        $bundles = app(BundleManifest::class);
        $contextBuild = method_exists($bundles, 'contextBuild') ? $bundles->contextBuild('admin') : [];
        $mode = $config['contexts']['admin']['source'] ?? null;
        $packageSource = ($contextBuild['package'] ?? null) === 'saola/admin' && $mode !== 'published'
            && ($mode === 'package' || !is_file($root.'/.publish-state.json'));
        if (!$packageSource) try {
            if (!is_file($root.'/.publish-state.json')) $errors[] = 'Run admin:install --dry-run and admin:install first.';
            $plan = (new SourcePublisher(__DIR__.'/../../resources/saola/admin', $root))->plan();
            foreach ($plan['conflicts'] as $path => $message) $errors[] = $path.': '.$message;
            if ($plan['actions']) $errors[] = 'Owned frontend source is out of date; run admin:publish --dry-run.';
        } catch (\Throwable $error) { $errors[] = $error->getMessage(); }
        $bootstrap = is_file($root.'/app/bootstrap.ts') ? file_get_contents($root.'/app/bootstrap.ts') : '';
        if (!$packageSource && !str_contains($bootstrap, './bundle')) $errors[] = 'Merge ./bundle into admin/app/bootstrap.ts; keep existing host providers.';
        if (!$packageSource && (($config['paths']['saoView'] ?? null) !== 'resources/saola'
            || ($config['contexts']['admin']['views'][$namespace] ?? null) !== 'admin/themes/'.$theme['name'].'/views'
            || ($config['contexts']['admin']['blade'][$namespace] ?? null) !== 'admin/themes/'.$theme['name']
            || !in_array('admin/app', $config['contexts']['admin']['app'] ?? [], true))) {
            $errors[] = 'Configure sao.config.json with admin/app and the selected theme view/Blade namespace: '.$namespace;
        }
        // Nguồn sự thật của renderer bắt buộc là context.manifest.json của gói, không phải danh sách viết cứng.
        $source = json_decode((string) @file_get_contents(__DIR__.'/../../resources/saola/admin/context.manifest.json'), true) ?: [];
        if (($source['context'] ?? null) !== 'admin') $errors[] = 'Package context.manifest.json is missing or belongs to another context.';
        if (isset($source['themes']) && !in_array($theme['name'], $source['themes'], true)
            && !is_file(resource_path('saola/admin/themes/'.$theme['name'].'/theme.manifest.json'))) {
            $errors[] = 'Selected theme is not listed in context.manifest.json: '.$theme['name'];
        }
        $renderers = array_values((array) ($source['renderers'] ?? []));
        foreach (array_unique([...$renderers, 'pages.resource', 'pages.settings', 'pages.languages', 'pages.media']) as $suffix) {
            $renderer = $namespace.'.'.$suffix;
            if (!view()->exists($renderer)) $errors[] = 'Build the admin context with the matching compiler/client; renderer is missing: '.$renderer;
        }
        foreach (['_system.page.begin', '_system.page.end', $namespace.'.partials.head'] as $view) {
            if (!view()->exists($view)) $errors[] = 'Host document view is missing: '.$view;
        }
        if (!$bundles->isDev()) {
            $manifest = $bundles->manifest('admin');
            $entry = $manifest[$bundles->entrySource('admin')] ?? [];
            $cssSource = method_exists($bundles, 'source') ? $bundles->source('admin', $theme['css']) : $theme['css'];
            $css = $manifest[$cssSource]['file'] ?? null;
            foreach (['JavaScript' => $entry['file'] ?? null, 'CSS' => $css] as $kind => $file) {
                if (!is_string($file) || !preg_match('~^[A-Za-z0-9_/-][A-Za-z0-9_./-]*$~D', $file)
                    || str_starts_with($file, '/') || in_array('..', explode('/', $file), true)
                    || !is_file(public_path('static/saola/admin/'.$file))) {
                    $errors[] = 'Admin '.$kind.' build file is missing or invalid; merge the admin bundle and build the admin context.';
                }
            }
        }
        try {
            $prefix = config('saola-admin.language.table_prefix', 'saola_');
            $schema = app('db')->connection(config('saola-admin.language.connection'))->getSchemaBuilder();
            foreach (['locales', 'translation_keys', 'translation_values', 'language_batches', 'language_settings'] as $suffix) {
                if ($schema->hasTable($prefix.$suffix)) continue;
                $legacy = 'saola_admin_'.$suffix;
                $errors[] = $prefix === 'saola_' && $schema->hasTable($legacy)
                    ? 'Legacy table '.$legacy.' exists. Keep language.table_prefix=saola_admin_ in saola-admin config until an explicit database migration is reviewed; no table was renamed.'
                    : 'Database table is missing: '.$prefix.$suffix.'. Run migrations for the configured connection and prefix.';
            }
            $table = config('saola-admin.settings.table', 'saola_settings');
            $settingsSchema = app('db')->connection(config('saola-admin.settings.connection'))->getSchemaBuilder();
            if (!$settingsSchema->hasTable($table)) {
                $errors[] = $table === 'saola_settings' && $settingsSchema->hasTable('saola_admin_settings')
                    ? 'Legacy table saola_admin_settings exists. Keep settings.table=saola_admin_settings in saola-admin config until an explicit database migration is reviewed; no table was renamed.'
                    : 'Database table is missing: '.$table.'. Run migrations for the configured settings connection.';
            }
            if (config('saola-admin.media.enabled', true)) {
                $table = config('saola-admin.media.table', 'saola_media');
                if (!app('db')->connection(config('saola-admin.media.connection'))->getSchemaBuilder()->hasTable($table)) $errors[] = 'Database table is missing: '.$table.'. Run media migrations.';
                $disk = config('saola-admin.media.disk', 'local');
                if (!is_array(config('filesystems.disks.'.$disk))) $errors[] = 'Media filesystem disk is missing: '.$disk;
            }
            foreach (config('saola-admin.panel.pages', []) as $class) {
                $resource = app($class);
                if (!$resource instanceof \Saola\Admin\Resources\Resource) continue;
                $resource->schema();
                if (!$resource->store() || !app($resource->store()) instanceof \Saola\Admin\Resources\ResourceStore) $errors[] = 'Resource store must implement ResourceStore: '.$class;
                if ($resource->store() !== \Saola\Admin\Resources\EloquentResourceStore::class) continue;
                $model = app($resource->model());
                $schema = $model->getConnection()->getSchemaBuilder();
                if ($model->getConnection()->getName() !== config('database.default')) $errors[] = 'Resource '.$resource->key().' needs a custom store for a non-default connection.';
                if (!$schema->hasTable(config('saola-admin.resources.audit_table', 'saola_resource_events'))) $errors[] = 'Resource audit table is missing; run package migrations.';
                if (!$schema->hasTable($model->getTable())) $errors[] = 'Resource model table is missing: '.$model->getTable();
                elseif ($resource->supportsTrash() && !$schema->hasColumn($model->getTable(), $model->getDeletedAtColumn())) $errors[] = 'Resource trash requires the host deleted_at migration: '.$model->getTable();
            }
        } catch (\Throwable $error) { $errors[] = 'Cannot inspect configured storage: '.$error->getMessage(); }
        if (config('saola-admin.panel.enabled') && config('sao.assets.css.admin') !== $theme['css']) $errors[] = 'The admin CSS source configuration differs from the selected theme CSS entry.';
        if (config('saola-admin.users.enabled', false)) {
            try { $errors = [...$errors, ...app(\Saola\Admin\Users\UserDirectory::class)->diagnostics()]; }
            catch (\Throwable $error) { $errors[] = 'Cannot inspect user directory: '.$error->getMessage(); }
        }
        $this->line(json_encode(['ok' => !$errors, 'contract' => 1, 'errors' => $errors,
            'next' => 'Configure the host Gate/guard, migrate and sync lang explicitly, build, then enable saola-admin.panel.enabled.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}

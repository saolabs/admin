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
        $root = resource_path('saola/admin');
        try {
            if (!is_file($root.'/.publish-state.json')) $errors[] = 'Run admin:install --dry-run and admin:install first.';
            $plan = (new SourcePublisher(__DIR__.'/../../resources/saola/admin', $root))->plan();
            foreach ($plan['conflicts'] as $path => $message) $errors[] = $path.': '.$message;
            if ($plan['actions']) $errors[] = 'Owned frontend source is out of date; run admin:publish --dry-run.';
        } catch (\Throwable $error) { $errors[] = $error->getMessage(); }
        $bootstrap = is_file($root.'/app/bootstrap.ts') ? file_get_contents($root.'/app/bootstrap.ts') : '';
        if (!str_contains($bootstrap, './bundle')) $errors[] = 'Merge ./bundle into admin/app/bootstrap.ts; keep existing host providers.';
        $configFile = base_path('sao.config.json');
        $config = is_file($configFile) ? json_decode(file_get_contents($configFile), true) : [];
        if (($config['paths']['saoView'] ?? null) !== 'resources/saola'
            || ($config['contexts']['admin']['views']['admin'] ?? null) !== 'admin/views'
            || ($config['contexts']['admin']['blade']['admin'] ?? null) !== 'admin'
            || !in_array('admin/app', $config['contexts']['admin']['app'] ?? [], true)) {
            $errors[] = 'Configure sao.config.json with resources/saola, admin/app, admin/views and the admin Blade namespace.';
        }
        foreach (['admin.pages.resource', 'admin.pages.settings', 'admin.components.settings-form'] as $renderer) {
            if (!view()->exists($renderer)) $errors[] = 'Build the admin context with the matching compiler/client; renderer is missing: '.$renderer;
        }
        foreach (['_system.page.begin', '_system.page.end', 'admin.partials.head'] as $view) {
            if (!view()->exists($view)) $errors[] = 'Host document view is missing: '.$view;
        }
        $bundles = app(BundleManifest::class);
        if (!$bundles->isDev()) {
            $manifest = $bundles->manifest('admin');
            $entry = $manifest[$bundles->entrySource('admin')] ?? [];
            $css = $manifest[config('saola-admin.panel.css')]['file'] ?? ($entry['css'][0] ?? null);
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
            foreach (['locales', 'translation_keys', 'translation_values', 'language_batches'] as $suffix) {
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
        } catch (\Throwable $error) { $errors[] = 'Cannot inspect configured storage: '.$error->getMessage(); }
        if (config('saola-admin.panel.enabled') && config('sao.assets.css.admin') !== config('saola-admin.panel.css')) $errors[] = 'The admin CSS source configuration differs from the panel CSS entry.';
        if (config('saola-admin.users.enabled', false)) {
            try { $errors = [...$errors, ...app(\Saola\Admin\Users\UserDirectory::class)->diagnostics()]; }
            catch (\Throwable $error) { $errors[] = 'Cannot inspect user directory: '.$error->getMessage(); }
        }
        $this->line(json_encode(['ok' => !$errors, 'contract' => 1, 'errors' => $errors,
            'next' => 'Configure the host Gate/guard, migrate and sync lang explicitly, build, then enable saola-admin.panel.enabled.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}

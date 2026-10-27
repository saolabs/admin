<?php

declare(strict_types=1);

namespace Saola\Admin\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * admin:make:screen Shop/reports -> Page (sidebar + access), controller and view of a custom (non-CRUD) screen.
 * The Page is discovered automatically; only the route line has to be added to the module's provider (printed).
 * Stubs may be overridden in stubs/admin.screen.{page,controller,view}.stub.
 */
final class MakeScreenCommand extends Command
{
    protected $signature = 'admin:make:screen {name : Module/slug, e.g. Shop/reports}
        {--ability= : Ability prefix (default admin.<slug>)}
        {--lang= : Translation key prefix (default <module>.<slug>)}
        {--controller= : Base controller (default App\Http\Controllers\AdminController, else HostScreenController)}
        {--force : Overwrite existing files}';
    protected $description = 'Create a custom admin screen: Page, controller and .sao view';

    public function handle(): int
    {
        if (!preg_match('#^([A-Z][A-Za-z0-9]*)/([a-z][a-z0-9-]*)$#', (string) $this->argument('name'), $m)) {
            $this->error('Name must be Module/slug, e.g. Shop/reports.'); return self::FAILURE;
        }
        [, $module, $slug] = $m;
        $studly = Str::studly($slug);
        $namespace = rtrim(\Saola\Admin\Pages\PageDiscovery::appNamespace(), '\\').'\\Modules\\'.$module;
        $base = $this->option('controller') ?: (class_exists($host = rtrim(\Saola\Admin\Pages\PageDiscovery::appNamespace(), '\\').'\\Http\\Controllers\\AdminController') ? $host : \Saola\Admin\Http\HostScreenController::class);
        $views = resource_path('saola/admin/views/modules/'.$slug);
        $prefix = config('saola-admin.generator', []);
        $vars = ['{{ module }}' => $slug, '{{ lang }}' => $this->option('lang') ?: (($prefix['lang_prefix'] ?? null) !== null ? $prefix['lang_prefix'].'.' : Str::snake($module).'.').str_replace('-', '_', $slug),
            '{{ ability }}' => $this->option('ability') ?: ($prefix['ability_prefix'] ?? null ?: 'admin').'.'.$slug, '{{ baseController }}' => ltrim($base, '\\'), '{{ baseControllerBase }}' => class_basename($base)];
        $files = [
            'page' => [app_path("Modules/$module/Pages/{$studly}Page.php"), $namespace.'\\Pages', $studly.'Page'],
            'controller' => [app_path("Modules/$module/Http/Controllers/Admin/{$studly}Controller.php"), $namespace.'\\Http\\Controllers\\Admin', $studly.'Controller'],
            'view' => [$views.'/index.sao', '', ''],
        ];
        foreach ($files as [$path]) if (is_file($path) && !$this->option('force')) { $this->error("$path exists (use --force)."); return self::FAILURE; }
        foreach ($files as $stub => [$path, $ns, $class]) {
            $source = is_file(base_path("stubs/admin.screen.$stub.stub")) ? base_path("stubs/admin.screen.$stub.stub") : __DIR__."/../../stubs/admin.screen.$stub.stub";
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, strtr(file_get_contents($source), [...$vars, '{{ namespace }}' => $ns, '{{ class }}' => $class]));
            $this->info('Created '.$path);
        }
        $this->line('Register the route in '.$module.'\'s ModuleServiceProvider (admin context), then add labels and run admin:lang:sync:');
        $this->line("  System::context('admin')->module(['slug' => '$slug', 'prefix' => '$slug'])->controller(\\{$files['controller'][1]}\\{$files['controller'][2]}::class)->as('$slug')");
        $this->line("      ->group(fn (\$m) => \$m->get('/', 'index')->name('index'));");
        return self::SUCCESS;
    }
}

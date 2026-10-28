<?php

namespace Saola\Admin\Console;

final class InstallAdminCommand extends PublishAdminCommand
{
    protected $signature = 'admin:install {--dry-run : Review frontend installation without writing files}';
    protected $description = 'Install admin frontend source and point the project admin controller at the package; leaves authentication, database and toolchain configuration to the host';

    /**
     * Module controllers extend App\Http\Controllers\AdminController; that one class decides which package serves
     * the admin context. Installing the package switches its base from core's admin controller to the package's.
     */
    public function handle(): int
    {
        $status = parent::handle();
        $path = app_path('Http/Controllers/AdminController.php');
        $base = '\\'.\Saola\Admin\Http\AdminController::class;
        if (!is_file($path)) {
            $source = "<?php\n\nnamespace App\\Http\\Controllers;\n\n/** Base of every admin controller of this project; served by the saola/admin package. */\nabstract class AdminController extends {$base}\n{\n}\n";
            $this->line(($this->option('dry-run') ? 'Would create ' : 'Created ').$path);
            if (!$this->option('dry-run')) { @mkdir(dirname($path), 0755, true); file_put_contents($path, $source); }
            return $status;
        }
        $source = file_get_contents($path);
        if (str_contains($source, 'Saola\\Admin\\Http\\AdminController')) return $status;
        $updated = preg_replace('/(abstract\s+class\s+AdminController\s+extends\s+)[\\\\A-Za-z0-9_]+/', '$1'.str_replace('\\', '\\\\', $base), $source, 1, $count);
        if (!$count) {
            $this->warn("{$path}: make AdminController extend {$base} by hand (its class line was not recognised).");
            return self::FAILURE;
        }
        $this->line(($this->option('dry-run') ? 'Would point ' : 'Pointed ')."App\\Http\\Controllers\\AdminController at {$base}");
        if (!$this->option('dry-run')) file_put_contents($path, $updated);
        return $status;
    }
}

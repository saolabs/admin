<?php

/** Local workbench only: fake operator + a disposable SQLite copy. Never load a host .env/DB. */
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Saola\Admin\AdminServiceProvider;

$root = realpath(getenv('SAOLA_ADMIN_PREVIEW_DIR') ?: '/tmp/saola-admin-preview');
if (!$root || !is_file($root.'/workbench-config.json') || !is_file($root.'/workbench.sqlite')) {
    http_response_code(503); exit('Export PanelTest fixtures and build the preview first.');
}
$public = realpath($root.'/public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($public.'/'.rawurldecode($path));
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) return false;
require __DIR__.'/../tests/bootstrap.php';

final class SaolaWorkbenchOperator
{
    public function handle(Request $request, Closure $next)
    {
        $operator = new GenericUser(['id' => 1, 'tenant_id' => 7, 'name' => 'Workbench Operator', 'email' => 'operator@workbench.test']);
        \Illuminate\Support\Facades\Auth::guard()->setUser($operator);
        $request->setUserResolver(static fn () => $operator);
        return $next($request);
    }
}
$app = Application::configure(basePath: $root)
    ->withExceptions()
    ->withProviders([AdminServiceProvider::class])
    ->withMiddleware(fn (Middleware $middleware) => $middleware->append(SaolaWorkbenchOperator::class))
    ->create();
$app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($root) {
    $config = json_decode(file_get_contents($root.'/workbench-config.json'), true, 64, JSON_THROW_ON_ERROR);
    $config['database']['default'] = 'workbench';
    $config['database']['connections'] = ['workbench' => ['driver' => 'sqlite', 'database' => $root.'/workbench.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]];
    $config['cache']['default'] = 'array';
    $config['session']['driver'] = 'file'; $config['session']['files'] = $root.'/storage/framework/sessions';
    $config['session']['cookie'] = 'saola_workbench'; $config['session']['secure'] = false;
    $config['view']['paths'] = [$root.'/resources/views']; $config['view']['compiled'] = $root.'/storage/framework/views';
    $config['app']['debug'] = true;
    $config['filesystems']['disks']['local']['root'] = $root.'/storage/app/private';
    foreach (['sessions', 'views', 'cache'] as $directory) @mkdir($root.'/storage/framework/'.$directory, 0777, true);
    foreach ($config as $key => $values) $app['config']->set($key, $values);
});
$app->booted(function () use ($app) {
    foreach (['access-admin', 'view-media', 'upload-media', 'update-media', 'delete-media', 'view-site-settings', 'update-site-settings', 'view-system-settings', 'update-system-settings', 'view-categories', 'view-admin-users', 'view-admin-languages', 'manage-admin-languages', 'export-admin-languages', 'import-admin-languages', 'publish-admin-languages'] as $ability) {
        Gate::define($ability, fn ($user) => $user->getAuthIdentifier() === 1);
    }
    Gate::define('viewAny', fn ($user, $model) => $user->getAuthIdentifier() === 1 && $model === \Saola\Admin\Tests\Fixtures\DirectoryUser::class);
});
$app->handleRequest(Request::capture());

<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Saola\Admin\AdminServiceProvider;
use Saola\Admin\Media\MediaLibrary;

class MediaDeliveryTest extends TestCase
{
    protected function getPackageProviders($app): array { return [AdminServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('saola-admin.panel.enabled', false);
    }

    public function test_existing_public_media_does_not_depend_on_the_panel_being_enabled(): void
    {
        (require __DIR__.'/../../database/migrations/2026_10_25_000005_create_media.php')->up();
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3e0AAAAASUVORK5CYII=');
        $item = app(MediaLibrary::class)->upload('global', UploadedFile::fake()->createWithContent('logo.png', $png));
        $this->get($item['url'])->assertOk()->assertStreamedContent($png);
        $this->assertFalse(app('router')->has('saola.admin.media.editor'));
        $routes = app('router')->getRoutes()->compile()['attributes'];
        $this->assertArrayHasKey('saola.media.content', $routes);
    }
}

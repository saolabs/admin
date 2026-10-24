<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Saola\Admin\Publishing\SourcePublisher;

class SourcePublishingTest extends TestCase
{
    private string $root;
    private string $source;
    private string $target;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()).'/saola-publish-'.bin2hex(random_bytes(6));
        $this->source = $this->root.'/package/resources/saola/admin';
        $this->target = $this->root.'/host/resources/saola/admin';
        (new Filesystem())->copyDirectory(__DIR__.'/../../resources/saola/admin', $this->source);
    }

    protected function tearDown(): void { (new Filesystem())->deleteDirectory($this->root); }
    private function publisher(): SourcePublisher { return new SourcePublisher($this->source, $this->target); }

    public function test_dry_run_is_read_only_and_install_is_idempotent_with_one_frontend_root(): void
    {
        $plan = $this->publisher()->publish(true);
        $this->assertArrayHasKey('assets/css/admin.css', $plan['actions']);
        $this->assertDirectoryDoesNotExist($this->target);
        $this->publisher()->publish();
        $this->assertSame([], $this->publisher()->publish()['actions']);
        $this->assertFileExists($this->target.'/.publish-state.json');
        $this->assertDirectoryDoesNotExist($this->root.'/host/resources/css');
        $this->assertDirectoryDoesNotExist($this->root.'/host/themes');
    }

    public function test_existing_bootstrap_is_preserved_and_requires_explicit_bundle_integration(): void
    {
        mkdir($this->target.'/app', 0777, true);
        file_put_contents($this->target.'/app/bootstrap.ts', 'host-providers');
        $result = $this->publisher()->publish();
        $this->assertNotEmpty($result['integration']);
        $this->assertSame('host-providers', file_get_contents($this->target.'/app/bootstrap.ts'));
        $state = json_decode(file_get_contents($this->target.'/.publish-state.json'), true);
        $this->assertArrayNotHasKey('app/bootstrap.ts', $state['files']);
    }

    public function test_three_way_update_preserves_custom_files_and_aborts_the_whole_batch_on_conflict(): void
    {
        $this->publisher()->publish();
        $css = 'assets/css/admin.css';
        file_put_contents($this->target.'/'.$css, 'host CSS');
        $this->assertSame([], $this->publisher()->publish()['actions']);
        file_put_contents($this->source.'/'.$css, 'new package CSS');
        file_put_contents($this->source.'/assets/new.txt', 'new');
        $before = file_get_contents($this->target.'/.publish-state.json');
        try { $this->publisher()->publish(); $this->fail('Conflict expected'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('conflicts', $error->getMessage()); }
        $this->assertSame('host CSS', file_get_contents($this->target.'/'.$css));
        $this->assertSame($before, file_get_contents($this->target.'/.publish-state.json'));
        $this->assertFileDoesNotExist($this->target.'/assets/new.txt');
        // Developer resolves the conflict explicitly by merging to the upstream version.
        file_put_contents($this->target.'/'.$css, 'new package CSS');
        $this->publisher()->publish();
        $this->assertFileExists($this->target.'/assets/new.txt');
    }

    public function test_removes_only_unmodified_owned_obsolete_files_and_keeps_host_or_theme_files(): void
    {
        file_put_contents($this->source.'/assets/old.txt', 'old');
        $this->publisher()->publish();
        mkdir($this->target.'/custom'); file_put_contents($this->target.'/custom/host.txt', 'custom');
        mkdir($this->root.'/host/themes', 0777, true); file_put_contents($this->root.'/host/themes/host.txt', 'theme');
        unlink($this->source.'/assets/old.txt');
        $this->publisher()->publish();
        $this->assertFileDoesNotExist($this->target.'/assets/old.txt');
        $this->assertSame('custom', file_get_contents($this->target.'/custom/host.txt'));
        $this->assertSame('theme', file_get_contents($this->root.'/host/themes/host.txt'));
    }

    public function test_unowned_collision_and_local_deletion_are_not_adopted_or_restored(): void
    {
        mkdir($this->target.'/assets/css', 0777, true);
        file_put_contents($this->target.'/assets/css/admin.css', 'host');
        $this->assertArrayHasKey('assets/css/admin.css', $this->publisher()->plan()['conflicts']);
        unlink($this->target.'/assets/css/admin.css');
        $this->publisher()->publish();
        unlink($this->target.'/assets/css/admin.css');
        $this->assertArrayHasKey('assets/css/admin.css', $this->publisher()->plan()['conflicts']);
    }

    private function installLegacySource(): string
    {
        $legacy = $this->root.'/legacy/resources/saola/admin';
        (new Filesystem())->copyDirectory($this->source, $legacy);
        $manifest = json_decode(file_get_contents($legacy.'/admin.manifest.json'), true);
        foreach ($manifest['renames'] as $previous => $next) {
            (new Filesystem())->ensureDirectoryExists(dirname($legacy.'/'.$previous));
            rename($legacy.'/'.$next, $legacy.'/'.$previous);
        }
        unset($manifest['renames']);
        $manifest['version'] = '0.0.0-dev';
        file_put_contents($legacy.'/admin.manifest.json', json_encode($manifest));
        file_put_contents($legacy.'/app/bootstrap.ts', "import admin from './saola_admin/bundle'; export default admin;\n");
        (new SourcePublisher($legacy, $this->target))->publish();
        return $legacy;
    }

    public function test_upgrade_relocates_pristine_owned_source_and_removes_only_empty_legacy_directories(): void
    {
        $this->installLegacySource();
        $result = $this->publisher()->publish();
        $this->assertSame('remove', $result['actions']['views/saola_admin/pages/settings.sao']['operation']);
        $this->assertFileExists($this->target.'/views/pages/settings.sao');
        $this->assertFileExists($this->target.'/app/bundle.ts');
        $this->assertDirectoryDoesNotExist($this->target.'/views/saola_admin');
        $this->assertDirectoryDoesNotExist($this->target.'/app/saola_admin');
        $this->assertSame([], $this->publisher()->plan()['actions']);
    }

    public function test_renamed_custom_view_stops_publication_without_orphaning_or_deleting_its_changes(): void
    {
        $legacy = $this->installLegacySource();
        $path = 'views/saola_admin/components/resource.sao';
        file_put_contents($this->target.'/'.$path, 'host renderer');
        $before = file_get_contents($this->target.'/.publish-state.json');
        $this->assertArrayHasKey($path, $this->publisher()->plan()['conflicts']);
        try { $this->publisher()->publish(); $this->fail('Customized rename must be reviewed'); }
        catch (\RuntimeException) {
            $this->assertSame('host renderer', file_get_contents($this->target.'/'.$path));
            $this->assertSame($before, file_get_contents($this->target.'/.publish-state.json'));
            $this->assertFileDoesNotExist($this->target.'/views/components/resource.sao');
        }
        // Save the host patch externally, restore the known baseline, publish,
        // then apply the customization to the newly owned location.
        copy($legacy.'/'.$path, $this->target.'/'.$path);
        $this->publisher()->publish();
        file_put_contents($this->target.'/views/components/resource.sao', 'host renderer');
        $this->assertSame([], $this->publisher()->plan()['conflicts']);
    }

    public function test_custom_legacy_bootstrap_import_blocks_removal_of_its_bundle(): void
    {
        $this->installLegacySource();
        $file = $this->target.'/app/bootstrap.ts';
        file_put_contents($file, file_get_contents($file)."// host providers\n");
        $this->assertArrayHasKey('app/bootstrap.ts', $this->publisher()->plan()['conflicts']);
        try { $this->publisher()->publish(); $this->fail('Legacy import must be merged first'); }
        catch (\RuntimeException) { $this->assertFileExists($this->target.'/app/saola_admin/bundle.ts'); }
    }

    public function test_symlinks_and_traversal_in_ownership_state_are_rejected(): void
    {
        $this->publisher()->publish();
        $file = $this->target.'/assets/css/admin.css';
        unlink($file); symlink($this->source.'/assets/css/admin.css', $file);
        try { $this->publisher()->plan(); $this->fail('Symlink expected to fail'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('Symlinks', $error->getMessage()); }
        unlink($file); copy($this->source.'/assets/css/admin.css', $file);
        $state = json_decode(file_get_contents($this->target.'/.publish-state.json'), true);
        $state['files']['assets/../../outside.txt'] = str_repeat('a', 64);
        file_put_contents($this->target.'/.publish-state.json', json_encode($state));
        $this->expectException(\RuntimeException::class);
        $this->publisher()->plan();
    }
}

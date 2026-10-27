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
        $this->assertArrayHasKey('themes/classic/assets/css/admin.css', $plan['actions']);
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

    public function test_adding_a_theme_keeps_existing_themes_and_host_customizations(): void
    {
        $this->publisher()->publish();
        $classic = hash_file('sha256', $this->target.'/themes/classic/views/components/manager.sao');
        (new Filesystem())->copyDirectory($this->source.'/themes/classic', $this->source.'/themes/graphite');
        $manifest = json_decode(file_get_contents($this->source.'/themes/graphite/theme.manifest.json'), true);
        $manifest['name'] = 'graphite'; $manifest['view_context'] = 'admin.graphite';
        file_put_contents($this->source.'/themes/graphite/theme.manifest.json', json_encode($manifest));
        mkdir($this->target.'/personal'); file_put_contents($this->target.'/personal/notes.txt', 'host');
        $plan = $this->publisher()->publish();
        $this->assertArrayHasKey('themes/graphite/views/components/manager.sao', $plan['actions']);
        $this->assertSame($classic, hash_file('sha256', $this->target.'/themes/classic/views/components/manager.sao'));
        $this->assertFileExists($this->target.'/themes/material/theme.manifest.json');
        $this->assertSame('host', file_get_contents($this->target.'/personal/notes.txt'));
        $this->assertSame([], $this->publisher()->plan()['actions']);
    }

    public function test_three_way_update_preserves_custom_files_and_aborts_the_whole_batch_on_conflict(): void
    {
        $this->publisher()->publish();
        $css = 'themes/classic/assets/css/admin.css';
        file_put_contents($this->target.'/'.$css, 'host CSS');
        $this->assertSame([], $this->publisher()->publish()['actions']);
        file_put_contents($this->source.'/'.$css, 'new package CSS');
        file_put_contents($this->source.'/themes/classic/assets/new.txt', 'new');
        $before = file_get_contents($this->target.'/.publish-state.json');
        try { $this->publisher()->publish(); $this->fail('Conflict expected'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('conflicts', $error->getMessage()); }
        $this->assertSame('host CSS', file_get_contents($this->target.'/'.$css));
        $this->assertSame($before, file_get_contents($this->target.'/.publish-state.json'));
        $this->assertFileDoesNotExist($this->target.'/themes/classic/assets/new.txt');
        // Developer resolves the conflict explicitly by merging to the upstream version.
        file_put_contents($this->target.'/'.$css, 'new package CSS');
        $this->publisher()->publish();
        $this->assertFileExists($this->target.'/themes/classic/assets/new.txt');
    }

    public function test_removes_only_unmodified_owned_obsolete_files_and_keeps_host_or_theme_files(): void
    {
        file_put_contents($this->source.'/themes/classic/assets/old.txt', 'old');
        $this->publisher()->publish();
        mkdir($this->target.'/custom'); file_put_contents($this->target.'/custom/host.txt', 'custom');
        mkdir($this->root.'/host/themes', 0777, true); file_put_contents($this->root.'/host/themes/host.txt', 'theme');
        unlink($this->source.'/themes/classic/assets/old.txt');
        $this->publisher()->publish();
        $this->assertFileDoesNotExist($this->target.'/themes/classic/assets/old.txt');
        $this->assertSame('custom', file_get_contents($this->target.'/custom/host.txt'));
        $this->assertSame('theme', file_get_contents($this->root.'/host/themes/host.txt'));
    }

    public function test_unowned_collision_and_local_deletion_are_not_adopted_or_restored(): void
    {
        mkdir($this->target.'/themes/classic/assets/css', 0777, true);
        file_put_contents($this->target.'/themes/classic/assets/css/admin.css', 'host');
        $this->assertArrayHasKey('themes/classic/assets/css/admin.css', $this->publisher()->plan()['conflicts']);
        unlink($this->target.'/themes/classic/assets/css/admin.css');
        $this->publisher()->publish();
        unlink($this->target.'/themes/classic/assets/css/admin.css');
        $this->assertArrayHasKey('themes/classic/assets/css/admin.css', $this->publisher()->plan()['conflicts']);
    }

    private function installLegacySource(): string
    {
        $legacy = $this->root.'/legacy/resources/saola/admin';
        (new Filesystem())->copyDirectory($this->source, $legacy);
        $manifest = json_decode(file_get_contents($legacy.'/context.manifest.json'), true);
        unlink($legacy.'/context.manifest.json');
        foreach ($manifest['renames'] as $previous => $next) {
            (new Filesystem())->ensureDirectoryExists(dirname($legacy.'/'.$previous));
            copy($legacy.'/'.$next, $legacy.'/'.$previous);
        }
        foreach (array_unique(array_values($manifest['renames'])) as $next) unlink($legacy.'/'.$next);
        unset($manifest['renames']);
        $manifest['version'] = '0.0.0-dev';
        file_put_contents($legacy.'/admin.manifest.json', json_encode($manifest));
        file_put_contents($legacy.'/app/bootstrap.ts', "import admin from './bundle'; export default admin;\n");
        (new SourcePublisher($legacy, $this->target))->publish();
        return $legacy;
    }

    public function test_upgrade_relocates_pristine_owned_source_and_removes_only_empty_legacy_directories(): void
    {
        $this->installLegacySource();
        $result = $this->publisher()->publish();
        $this->assertSame('remove', $result['actions']['views/pages/settings.sao']['operation']);
        $this->assertFileExists($this->target.'/themes/classic/views/pages/settings.sao');
        $this->assertFileExists($this->target.'/app/bundle.ts');
        $this->assertDirectoryDoesNotExist($this->target.'/views');
        $this->assertFileExists($this->target.'/app/settings-form.ts');
        $this->assertSame([], $this->publisher()->plan()['actions']);
    }

    private function installNamedThemes(): void
    {
        $legacy = $this->root.'/named/resources/saola/admin';
        (new Filesystem())->copyDirectory($this->source, $legacy);
        $manifest = json_decode(file_get_contents($legacy.'/context.manifest.json'), true);
        unlink($legacy.'/context.manifest.json');
        foreach ($manifest['renames'] as $previous => $next) {
            if (!preg_match('~^(classic|material)/~', $previous)) continue;
            (new Filesystem())->ensureDirectoryExists(dirname($legacy.'/'.$previous));
            rename($legacy.'/'.$next, $legacy.'/'.$previous);
        }
        unset($manifest['renames']);
        file_put_contents($legacy.'/admin.manifest.json', json_encode($manifest));
        (new SourcePublisher($legacy, $this->target))->publish();
    }

    public function test_upgrade_from_named_themes_moves_owned_sources_into_the_theme_container(): void
    {
        $this->installNamedThemes();
        $result = $this->publisher()->publish();
        $this->assertSame('remove', $result['actions']['material/views/components/manager.sao']['operation']);
        $this->assertFileExists($this->target.'/themes/material/views/components/manager.sao');
        $this->assertDirectoryDoesNotExist($this->target.'/material');
        $this->assertSame([], $this->publisher()->plan()['actions']);
    }

    public function test_customization_in_old_named_theme_blocks_the_entire_relocation(): void
    {
        $this->installNamedThemes();
        file_put_contents($this->target.'/classic/views/components/manager.sao', 'custom view');
        $this->assertArrayHasKey('classic/views/components/manager.sao', $this->publisher()->plan()['conflicts']);
        try { $this->publisher()->publish(); $this->fail('Customized relocation must stop'); }
        catch (\RuntimeException) {
            $this->assertSame('custom view', file_get_contents($this->target.'/classic/views/components/manager.sao'));
            $this->assertFileDoesNotExist($this->target.'/themes/classic/views/components/manager.sao');
        }
    }

    public function test_renamed_custom_view_stops_publication_without_orphaning_or_deleting_its_changes(): void
    {
        $legacy = $this->installLegacySource();
        $path = 'views/components/resource.sao';
        file_put_contents($this->target.'/'.$path, 'host renderer');
        $before = file_get_contents($this->target.'/.publish-state.json');
        $this->assertArrayHasKey($path, $this->publisher()->plan()['conflicts']);
        try { $this->publisher()->publish(); $this->fail('Customized rename must be reviewed'); }
        catch (\RuntimeException) {
            $this->assertSame('host renderer', file_get_contents($this->target.'/'.$path));
            $this->assertSame($before, file_get_contents($this->target.'/.publish-state.json'));
            $this->assertFileDoesNotExist($this->target.'/themes/classic/views/components/resource.sao');
        }
        // Save the host patch externally, restore the known baseline, publish,
        // then apply the customization to the newly owned location.
        copy($legacy.'/'.$path, $this->target.'/'.$path);
        $this->publisher()->publish();
        file_put_contents($this->target.'/themes/classic/views/components/resource.sao', 'host renderer');
        $this->assertSame([], $this->publisher()->plan()['conflicts']);
    }

    public function test_custom_legacy_bootstrap_import_blocks_removal_of_shared_source(): void
    {
        $this->installSharedLayout();
        $file = $this->target.'/app/bootstrap.ts';
        file_put_contents($file, file_get_contents($file)."import {SettingsForm} from '../_shared/app/settings-form'; // host providers\n");
        $this->assertArrayHasKey('app/bootstrap.ts', $this->publisher()->plan()['conflicts']);
        try { $this->publisher()->publish(); $this->fail('Legacy import must be merged first'); }
        catch (\RuntimeException) { $this->assertFileExists($this->target.'/_shared/app/settings-form.ts'); }
    }

    public function test_symlinks_and_traversal_in_ownership_state_are_rejected(): void
    {
        $this->publisher()->publish();
        $file = $this->target.'/themes/classic/assets/css/admin.css';
        unlink($file); symlink($this->source.'/themes/classic/assets/css/admin.css', $file);
        try { $this->publisher()->plan(); $this->fail('Symlink expected to fail'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('Symlinks', $error->getMessage()); }
        unlink($file); copy($this->source.'/themes/classic/assets/css/admin.css', $file);
        $state = json_decode(file_get_contents($this->target.'/.publish-state.json'), true);
        $state['files']['themes/classic/assets/../../outside.txt'] = str_repeat('a', 64);
        file_put_contents($this->target.'/.publish-state.json', json_encode($state));
        $this->expectException(\RuntimeException::class);
        $this->publisher()->plan();
    }

    /** Bố cục trước P3: nguồn thật ở `_shared/app`, `app/bundle.ts` chỉ là cầu nối, manifest tên cũ. */
    private function installSharedLayout(): void
    {
        $old = $this->root.'/shared/resources/saola/admin';
        $fs = new Filesystem();
        $fs->copyDirectory($this->source, $old);
        $manifest = json_decode(file_get_contents($old.'/context.manifest.json'), true);
        unlink($old.'/context.manifest.json');
        unset($manifest['renames'], $manifest['context'], $manifest['requires'], $manifest['api'], $manifest['defaultTheme'], $manifest['overridable']);
        $manifest['version'] = '0.0.0-dev';
        file_put_contents($old.'/admin.manifest.json', json_encode($manifest));
        foreach (glob($old.'/app/{,views/}*.ts', GLOB_BRACE) as $real) {
            if (basename($real) === 'bootstrap.ts') continue;
            $reexport = $old.'/_shared/app/'.substr($real, strlen($old.'/app/'));
            $fs->ensureDirectoryExists(dirname($reexport));
            copy($real, $reexport);
            if (basename($real) === 'bundle.ts' && dirname($real) === $old.'/app') {
                file_put_contents($real, "export * from '@sao/admin/_shared/app/bundle';\nexport {default} from '@sao/admin/_shared/app/bundle';\n");
            } else {
                unlink($real);
            }
        }
        $fs->deleteDirectory($old.'/app/contracts');
        (new SourcePublisher($old, $this->target))->publish();
    }

    public function test_upgrade_from_shared_layout_moves_source_to_app_and_removes_owned_shared_tree(): void
    {
        $this->installSharedLayout();
        $this->assertFileExists($this->target.'/admin.manifest.json');
        $this->assertFileDoesNotExist($this->target.'/app/settings-form.ts');

        $result = $this->publisher()->publish();

        $this->assertSame([], $result['conflicts']);
        $this->assertFileExists($this->target.'/context.manifest.json');
        $this->assertFileDoesNotExist($this->target.'/admin.manifest.json');
        $this->assertFileExists($this->target.'/app/settings-form.ts');
        $this->assertFileExists($this->target.'/app/contracts/admin.ts');
        $this->assertDirectoryDoesNotExist($this->target.'/_shared');
        $this->assertSame('remove', $result['actions']['_shared/app/settings-form.ts']['operation']);
        $this->assertSame([], $this->publisher()->plan()['actions']);
    }

    public function test_upgrade_from_shared_layout_stops_when_host_customized_a_shared_file(): void
    {
        $this->installSharedLayout();
        file_put_contents($this->target.'/_shared/app/settings-form.ts', "// host\nexport const mine = 1;\n");

        $plan = $this->publisher()->plan();

        $this->assertArrayHasKey('_shared/app/settings-form.ts', $plan['conflicts']);
        $this->expectException(\RuntimeException::class);
        $this->publisher()->publish();
    }
}

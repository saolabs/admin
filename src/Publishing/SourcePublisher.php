<?php

declare(strict_types=1);

namespace Saola\Admin\Publishing;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/** Three-way source publication. Paths are always relative to the single frontend root. */
final class SourcePublisher
{
    private const STATE = '.publish-state.json';

    public function __construct(private readonly string $source, private readonly string $target) {}

    public function plan(): array
    {
        $this->safeRoot($this->source);
        $this->safeRoot($this->target);
        $manifest = $this->manifest($this->manifestFile());
        $upstream = $this->inventory();
        $state = is_file($this->target.'/'.self::STATE) ? $this->manifest($this->target.'/'.self::STATE) : [];
        if ($state && (($state['package'] ?? '') !== 'saola/admin' || ($state['contract'] ?? null) !== 1 || !is_array($state['files'] ?? null))) {
            throw new RuntimeException('Invalid admin ownership state.');
        }
        $old = $state['files'] ?? [];
        $actions = []; $conflicts = []; $integration = []; $files = $old;
        $renames = $manifest['renames'] ?? [];
        if (!is_array($renames)) throw new RuntimeException('Invalid admin source renames.');
        foreach ($renames as $previous => $nextPath) {
            if (!is_string($previous) || !is_string($nextPath)) throw new RuntimeException('Invalid admin source rename.');
            $this->path($previous); $this->path($nextPath);
            if (isset($upstream[$previous]) || !isset($upstream[$nextPath])) throw new RuntimeException('Invalid admin source rename.');
            $legacy = $this->target.'/'.$previous;
            $this->safeRoot($legacy);
            if (!is_file($legacy)) continue;
            $base = $old[$previous] ?? null;
            if ($base === null || hash_file('sha256', $legacy) !== $base) {
                $conflicts[$previous] = 'Renamed source has local changes or no ownership; preserve a patch and merge it into '.$nextPath.' after publishing the original baseline.';
            }
            if (str_starts_with($previous, 'app/') || str_starts_with($previous, '_shared/app/')) {
                $bootstrap = $this->target.'/app/bootstrap.ts';
                $this->safeRoot($bootstrap);
                $references = str_starts_with($previous, 'app/')
                    ? ['./'.substr($previous, 4, -3)]
                    : ['../'.substr($previous, 0, -3), '@sao/admin/'.substr($previous, 0, -3), '@admin/'.substr($previous, 0, -3)];
                if (is_file($bootstrap) && hash_file('sha256', $bootstrap) !== ($old['app/bootstrap.ts'] ?? null)) {
                    foreach ($references as $reference) {
                        if (str_contains(file_get_contents($bootstrap), $reference)) {
                            $conflicts['app/bootstrap.ts'] = 'Custom bootstrap references '.$reference.'; migrate imports to app/ before publishing.';
                            break;
                        }
                    }
                }
            }
        }
        foreach (array_unique([...array_keys($upstream), ...array_keys($old)]) as $path) {
            $this->path($path);
            if (isset($old[$path]) && (!is_string($old[$path]) || !preg_match('/^[a-f0-9]{64}$/D', $old[$path]))) throw new RuntimeException('Invalid ownership hash.');
            $dest = $this->target.'/'.$path;
            $this->safeRoot($dest);
            if (file_exists($dest) && !is_file($dest)) { $conflicts[$path] = 'Destination is not a regular file'; continue; }
            $current = is_file($dest) ? hash_file('sha256', $dest) : null;
            $next = $upstream[$path] ?? null;
            $base = $old[$path] ?? null;
            if ($base === null && $current !== null) {
                if ($path === 'app/bootstrap.ts') {
                    $integration[] = 'Keep host app/bootstrap.ts; merge ./bundle into its exported bundle.';
                    continue;
                }
                $conflicts[$path] = 'Existing file is not owned by saola/admin'; continue;
            }
            if ($base !== null && $current === null) { $conflicts[$path] = 'Host deleted an owned file'; continue; }
            if ($next === null) {
                if ($current === $base) { $actions[$path] = ['operation' => 'remove', 'expected' => $current]; unset($files[$path]); }
                else $integration[] = 'Obsolete customized file retained: '.$path;
                continue;
            }
            if ($base !== null && $current !== $base && $current !== $next && $next !== $base) {
                $conflicts[$path] = 'Host and package both changed; merge before publishing'; continue;
            }
            if ($current === null || ($current === $base && $current !== $next)) {
                $actions[$path] = ['operation' => $current === null ? 'add' : 'update', 'expected' => $current, 'source' => $next];
            }
            $files[$path] = $next;
        }
        ksort($actions); ksort($files);
        return ['package' => 'saola/admin', 'contract' => 1, 'version' => $manifest['version'],
            'actions' => $actions, 'conflicts' => $conflicts, 'integration' => $integration, 'files' => $files];
    }

    public function publish(bool $dryRun = false): array
    {
        if ($dryRun) return $this->plan();
        $this->safeRoot($this->target);
        (new Filesystem())->ensureDirectoryExists($this->target);
        $lockPath = realpath(sys_get_temp_dir()).'/saola-admin-publish-'.hash('sha256', $this->target).'.lock';
        $this->safeRoot($lockPath);
        $lock = fopen($lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock admin source publication.');
        $stage = $this->target.'/.publish-'.bin2hex(random_bytes(8));
        $original = []; $applied = [];
        try {
            $plan = $this->plan();
            if ($plan['conflicts']) throw new RuntimeException('Admin source conflicts: '.json_encode($plan['conflicts'], JSON_UNESCAPED_SLASHES));
            (new Filesystem())->ensureDirectoryExists($stage);
            foreach ($plan['actions'] as $path => $change) {
                $dest = $this->target.'/'.$path;
                $this->safeRoot($dest);
                $actual = is_file($dest) ? hash_file('sha256', $dest) : null;
                if ($actual !== $change['expected']) throw new RuntimeException('Host source changed during publication: '.$path);
                $original[$path] = is_file($dest) ? file_get_contents($dest) : null;
                if ($change['operation'] !== 'remove') {
                    $bytes = file_get_contents($this->source.'/'.$path);
                    if (!hash_equals($change['source'], hash('sha256', $bytes))) throw new RuntimeException('Package source changed during publication.');
                    $this->write($stage.'/'.$path, $bytes);
                }
            }
            $original[self::STATE] = is_file($this->target.'/'.self::STATE) ? file_get_contents($this->target.'/'.self::STATE) : null;
            $this->write($stage.'/'.self::STATE, json_encode(array_intersect_key($plan, array_flip(['package', 'contract', 'version', 'files'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            foreach ($plan['actions'] as $path => $change) {
                $dest = $this->target.'/'.$path;
                $this->safeRoot($dest);
                if ((is_file($dest) ? hash_file('sha256', $dest) : null) !== $change['expected']) throw new RuntimeException('Host source changed during publication.');
                if ($change['operation'] === 'remove') {
                    if (!unlink($dest)) throw new RuntimeException('Cannot remove obsolete admin file.');
                } else {
                    (new Filesystem())->ensureDirectoryExists(dirname($dest));
                    if (!rename($stage.'/'.$path, $dest)) throw new RuntimeException('Cannot install admin source.');
                }
                $applied[] = $path;
            }
            if (!rename($stage.'/'.self::STATE, $this->target.'/'.self::STATE)) throw new RuntimeException('Cannot update admin ownership state.');
            // Remove only empty parent directories of files we owned and removed.
            // Custom files keep their directories; publication state is committed.
            foreach ($plan['actions'] as $path => $change) {
                if ($change['operation'] !== 'remove') continue;
                for ($dir = dirname($this->target.'/'.$path); $dir !== rtrim($this->target, '/'); $dir = dirname($dir)) {
                    if (is_link($dir) || !@rmdir($dir)) break;
                }
            }
            return $plan;
        } catch (\Throwable $error) {
            foreach (array_reverse($applied) as $path) {
                $bytes = $original[$path];
                $dest = $this->target.'/'.$path;
                if ($bytes === null) { if (is_file($dest)) unlink($dest); }
                else $this->write($dest, $bytes);
            }
            throw $error;
        } finally {
            (new Filesystem())->deleteDirectory($stage);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function inventory(): array
    {
        $files = [];
        $walk = function (string $dir) use (&$walk, &$files): void {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $path = $dir.'/'.$name;
                if (is_link($path)) throw new RuntimeException('Admin package cannot contain symlinks.');
                if (is_dir($path)) $walk($path);
                elseif (is_file($path)) {
                    $relative = substr($path, strlen(rtrim($this->source, '/')) + 1);
                    $this->path($relative); $files[$relative] = hash_file('sha256', $path);
                }
            }
        };
        $walk($this->source);
        ksort($files); return $files;
    }

    private function path(string $path): void
    {
        if (!preg_match('~^(?:(?:admin|context)\.manifest\.json|(?:app|views|assets|build)/[A-Za-z0-9_./-]+|_shared/app/[A-Za-z0-9_./-]+|(?:themes/)?[a-z][a-z0-9-]*/(?:theme\.manifest\.json|(?:app|views|assets)/[A-Za-z0-9_./-]+))$~D', $path)
            || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true) || str_contains($path, '//')) {
            throw new RuntimeException('Invalid admin source path: '.$path);
        }
    }

    private function safeRoot(string $path): void
    {
        for ($part = $path; $part !== dirname($part); $part = dirname($part)) {
            if (is_link($part)) throw new RuntimeException('Symlinks are not allowed in admin publication paths.');
            if (file_exists($part) && $part !== $path && !is_dir($part)) throw new RuntimeException('Admin parent path is not a directory.');
        }
    }

    /** `context.manifest.json` là tên chuẩn; `admin.manifest.json` là tên cũ, còn được đọc một bản phát hành. */
    private function manifestFile(): string
    {
        $current = $this->source.'/context.manifest.json';

        return is_file($current) ? $current : $this->source.'/admin.manifest.json';
    }

    private function manifest(string $path): array
    {
        $this->safeRoot($path);
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['package'] ?? '') !== 'saola/admin' || ($data['contract'] ?? null) !== 1 || !is_string($data['version'] ?? null)) {
            throw new RuntimeException('Invalid admin UI manifest.');
        }
        if (isset($data['context']) && $data['context'] !== 'admin') {
            throw new RuntimeException('Admin UI manifest belongs to another context.');
        }
        return $data;
    }

    private function write(string $path, string $bytes): void
    {
        (new Filesystem())->ensureDirectoryExists(dirname($path));
        if (file_put_contents($path, $bytes) === false) throw new RuntimeException('Cannot write admin source: '.$path);
    }
}

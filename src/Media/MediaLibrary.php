<?php

declare(strict_types=1);

namespace Saola\Admin\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Database identity/metadata with Laravel filesystem storage; no client-supplied disk or path. */
class MediaLibrary
{
    private const TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/gif' => ['gif'], 'image/webp' => ['webp'],
        'image/x-icon' => ['ico'], 'image/vnd.microsoft.icon' => ['ico'], 'application/pdf' => ['pdf'],
        'audio/mpeg' => ['mp3'], 'audio/ogg' => ['ogg'], 'audio/wav' => ['wav'], 'audio/x-wav' => ['wav'],
        'video/mp4' => ['mp4'], 'video/webm' => ['webm'],
    ];

    private function table(): \Illuminate\Database\Query\Builder
    {
        $table = config('saola-admin.media.table', 'saola_media');
        if (!is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) throw new \InvalidArgumentException('Invalid media table.');
        return app('db')->connection(config('saola-admin.media.connection'))->table($table);
    }

    private function scoped(string $scope): \Illuminate\Database\Query\Builder
    {
        if ($scope === '' || strlen($scope) > 128) throw new \InvalidArgumentException('Invalid media scope.');
        return $this->table()->where('scope_key', $scope);
    }

    public function listing(string $scope, array $filters): array
    {
        $query = $this->scoped($scope);
        ($filters['status'] === 'trash') ? $query->whereNotNull('deleted_at') : $query->whereNull('deleted_at');
        if ($filters['kind'] !== 'all') $query->where('kind', $filters['kind']);
        if ($filters['q'] !== '') {
            // Escape LIKE wildcards so search always means a literal substring.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(title) LIKE LOWER(?) ESCAPE '!'", [$term])
                ->orWhereRaw("LOWER(original_name) LIKE LOWER(?) ESCAPE '!'", [$term]));
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($filters['page'] - 1) * 24)->limit(24)->get();
        return ['items' => $rows->map(fn ($row) => $this->project($row))->all(), 'total' => $total, 'page' => $filters['page'], 'perPage' => 24];
    }

    public function find(string $scope, string $id): array
    {
        return $this->project($this->row($scope, $id));
    }

    private function row(string $scope, string $id): object
    {
        return $this->scoped($scope)->where('id', $id)->first() ?? abort(404);
    }

    public function upload(string $scope, UploadedFile $file): array
    {
        $this->scoped($scope);
        $mime = $file->getMimeType();
        $types = array_intersect_key(self::TYPES, array_flip(config('saola-admin.media.allowed_mimes', array_keys(self::TYPES))));
        $size = $file->getSize();
        if (!$file->isValid() || !isset($types[$mime]) || !in_array(strtolower($file->getClientOriginalExtension()), $types[$mime], true)
            || !$size || $size > (int) config('saola-admin.media.max_bytes', 20971520)) $this->invalid('file');
        $width = null; $height = null;
        if (str_starts_with($mime, 'image/') && !in_array($mime, ['image/x-icon', 'image/vnd.microsoft.icon'], true)) {
            $info = @getimagesize($file->getRealPath());
            if (!$info || $info[0] * $info[1] > (int) config('saola-admin.media.max_pixels', 40000000)) $this->invalid('file');
            [$width, $height] = $info;
        }
        $kind = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : 'document'));
        $root = config('saola-admin.media.kind_directories.'.$kind, config('saola-admin.media.directory', 'saola/admin/media'));
        if (!is_string($root) || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $root)) throw new \InvalidArgumentException('Invalid media directory.');
        $id = (string) Str::uuid(); $disk = config('saola-admin.media.disk', 'local');
        $path = $root.'/'.date('Y/m').'/'.$id.'.'.$types[$mime][0];
        $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?: 'file', 0, 200);
        $storage = Storage::disk($disk); $stream = fopen($file->getRealPath(), 'rb');
        $public = in_array($disk, config('saola-admin.media.public_disks', []), true);
        try { if (!$storage->put($path, $stream, ['visibility' => $public ? 'public' : 'private'])) throw new \RuntimeException('Cannot store media.'); }
        finally { if (is_resource($stream)) fclose($stream); }
        try {
            $now = now();
            $this->table()->insert(['id' => $id, 'scope_key' => $scope, 'disk' => $disk, 'path' => $path,
                'original_name' => $name, 'title' => pathinfo($name, PATHINFO_FILENAME) ?: $name, 'alt' => '', 'caption' => '',
                'mime' => $mime, 'kind' => $kind, 'size' => $size, 'width' => $width, 'height' => $height,
                'revision' => 1, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null]);
        } catch (\Throwable $error) { $storage->delete($path); throw $error; }
        return $this->find($scope, $id);
    }

    public function update(string $scope, string $id, int $revision, array $changes): array
    {
        $row = $this->row($scope, $id);
        if ($row->deleted_at !== null || (int) $row->revision !== $revision) abort(409);
        if (!$changes || array_diff(array_keys($changes), ['title', 'alt', 'caption'])) $this->invalid('metadata');
        foreach ($changes as $key => $value) if (!is_string($value) || mb_strlen($value) > ($key === 'caption' ? 2000 : 200) || ($key === 'title' && trim($value) === '')) $this->invalid($key);
        return $this->write($scope, $id, $revision, $changes);
    }

    public function trash(string $scope, string $id, int $revision, bool $restore): array
    {
        $row = $this->row($scope, $id);
        if (($row->deleted_at === null) === $restore || (int) $row->revision !== $revision) abort(409);
        return $this->write($scope, $id, $revision, ['deleted_at' => $restore ? null : now()]);
    }

    private function write(string $scope, string $id, int $revision, array $changes): array
    {
        $changed = $this->scoped($scope)->where('id', $id)->where('revision', $revision)
            ->update($changes + ['revision' => $revision + 1, 'updated_at' => now()]);
        abort_unless($changed === 1, 409);
        return $this->find($scope, $id);
    }

    /** Public website assets; trash removes picker visibility, retaining existing links and bytes. */
    public function content(string $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $row = $this->table()->where('id', $id)->first() ?? abort(404);
        $storage = Storage::disk($row->disk);
        abort_unless(isset(self::TYPES[$row->mime]) && $storage->exists($row->path), 404);
        return response()->stream(function () use ($storage, $row) {
            $stream = $storage->readStream($row->path);
            if (is_resource($stream)) { try { fpassthru($stream); } finally { fclose($stream); } }
        }, 200, ['Content-Type' => $row->mime, 'Content-Length' => (string) $row->size, 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($row->kind === 'image' ? 'inline' : 'attachment').'; filename="'.$row->id.'.'.self::TYPES[$row->mime][0].'"',
            'Cache-Control' => 'public, max-age=86400', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function project(object $row): array
    {
        return ['id' => $row->id, 'title' => $row->title, 'alt' => $row->alt, 'caption' => $row->caption,
            'filename' => $row->original_name, 'mime' => $row->mime, 'kind' => $row->kind, 'size' => (int) $row->size, 'sizeLabel' => number_format(ceil($row->size / 1024)).' KB',
            'width' => $row->width === null ? null : (int) $row->width, 'height' => $row->height === null ? null : (int) $row->height,
            'revision' => (int) $row->revision, 'trashed' => $row->deleted_at !== null, 'createdAt' => $row->created_at,
            'url' => in_array($row->disk, config('saola-admin.media.public_disks', []), true)
                ? Storage::disk($row->disk)->url($row->path)
                : route('saola.media.content', ['media' => $row->id], false)];
    }

    private function invalid(string $key): never { throw ValidationException::withMessages([$key => 'Invalid media value.']); }
}

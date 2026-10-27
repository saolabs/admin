<?php

declare(strict_types=1);

namespace Saola\Admin\Language;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Saola\Core\Language\LanguageJson;
use Saola\Core\Language\LanguageTree;

/** Actor-bound export baseline -> validated preview -> draft -> explicit publish. No HTTP authorization here. */
final class LanguageExchange
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly DatabaseLanguageStore $store,
        private readonly string $prefix = 'saola_',
        private readonly string $sourceLocale = 'en',
        private readonly int $maxBytes = 2097152,
        private readonly int $maxKeys = 10000,
        private readonly int $ttl = 604800,
    ) {}

    public function export(string $locale, string $actor): array
    {
        return $this->store->locked($locale, $this->sourceLocale, function ($revision) use ($locale, $actor) {
            $catalog = $this->store->catalogState();
            $values = LanguageTree::flatten($this->store->snapshot($locale)['messages']);
            $template = [];
            foreach ($catalog as $path => $source) $template[$path] = $values[$path] ?? $source['text'];
            $payload = ['source_locale' => $this->sourceLocale, 'catalog_hash' => $this->hash($catalog),
                'values' => $values, 'template' => $template];
            $id = $this->insert('export', $locale, $actor, 'exported', $revision, $payload, (string) Str::uuid());
            return ['batch' => $id, 'revision' => $revision, 'tree' => (object) LanguageTree::nest($template)];
        });
    }

    public function preview(string $locale, string $json, string $actor, ?string $exportId = null, array $confirmSource = []): array
    {
        $flat = LanguageTree::flatten(LanguageJson::decode($json, $this->maxBytes, $this->maxKeys));
        sort($confirmSource);
        return $this->store->locked($locale, $this->sourceLocale, function ($revision) use ($locale, $json, $flat, $actor, $exportId, $confirmSource) {
            if ($exportId === null) $exportId = $this->export($locale, $actor)['batch'];
            $baseline = $this->batch($exportId, $actor, 'export', false);
            if ($baseline->locale !== $locale) throw new InvalidArgumentException('Export locale differs from import locale.');
            $payload = json_decode($baseline->payload, true, 512, JSON_THROW_ON_ERROR);
            $identity = $this->hash([$exportId, hash('sha256', $json), $confirmSource]);
            $existing = $this->db->table($this->table())->where('identity_hash', $identity)->first();
            if ($existing && $existing->status === 'published') return $this->report($existing);
            $this->assertNotExpired($baseline);
            $this->assertCurrent($payload, (int) $baseline->base_revision, $revision);
            if ($existing) return $this->report($existing);
            $catalog = $this->store->catalogState();
            $changes = []; $unchanged = []; $untranslated = [];
            foreach ($confirmSource as $path) {
                if (!is_string($path) || !array_key_exists($path, $flat)) throw new InvalidArgumentException('Source confirmation must name an imported key.');
            }
            foreach ($flat as $path => $value) {
                if (!isset($catalog[$path])) throw new InvalidArgumentException("Unknown language key: {$path}");
                $this->validateParams($catalog[$path]['text'], $value, $path);
                $missing = !array_key_exists($path, $payload['values']);
                if ($missing && $value === $catalog[$path]['text'] && !in_array($path, $confirmSource, true)) {
                    $untranslated[] = $path;
                } elseif (!$missing && $value === $payload['values'][$path]) {
                    $unchanged[] = $path;
                } else {
                    $changes[$path] = $value;
                }
            }
            $draft = ['source_locale' => $this->sourceLocale, 'catalog_hash' => $payload['catalog_hash'],
                'export_id' => $exportId, 'changes' => $changes, 'unchanged' => $unchanged, 'untranslated' => $untranslated];
            $id = $this->insert('import', $locale, $actor, 'preview', $revision, $draft, $identity);
            return $this->report($this->batch($id, $actor, 'import'));
        });
    }

    public function apply(string $id, string $actor, bool $publish = false): array
    {
        $batch = $this->batch($id, $actor, 'import', false);
        return $this->store->locked($batch->locale, $this->sourceLocale, function ($revision) use ($id, $actor, $publish) {
            $batch = $this->batch($id, $actor, 'import', false, true);
            if ($batch->status === 'published') return $this->report($batch);
            $this->assertNotExpired($batch);
            $payload = json_decode($batch->payload, true, 512, JSON_THROW_ON_ERROR);
            $this->assertCurrent($payload, (int) $batch->base_revision, $revision);
            if ($publish) {
                $result = $this->store->publish($batch->locale, LanguageTree::nest($payload['changes']),
                    $revision, $this->sourceLocale, 'import');
                $this->db->table($this->table())->where('id', $id)->update([
                    'status' => 'published', 'result_revision' => $result, 'published_at' => gmdate('Y-m-d H:i:s'),
                ]);
            } else {
                $this->db->table($this->table())->where('id', $id)->update(['status' => 'draft']);
            }
            return $this->report($this->batch($id, $actor, 'import', false));
        });
    }

    public function inspect(string $id, string $actor): array
    {
        $batch = $this->batch($id, $actor, 'import', false);
        if ($batch->status !== 'published') $this->assertNotExpired($batch);
        return $this->report($batch);
    }

    public function recent(string $actor): array
    {
        return $this->db->table($this->table())->where('actor', $actor)->orderByDesc('created_at')->orderByDesc('id')->limit(20)
            ->get(['id', 'kind', 'locale', 'status', 'base_revision', 'result_revision', 'created_at', 'expires_at'])
            ->map(fn ($row) => ['batch' => $row->id, 'kind' => $row->kind, 'locale' => $row->locale, 'status' => $row->status,
                'baseRevision' => (int) $row->base_revision, 'createdAt' => $row->created_at, 'expired' => (int) $row->expires_at <= time()])->all();
    }

    private function assertCurrent(array $payload, int $expectedRevision, int $revision): void
    {
        if ($expectedRevision !== $revision || $payload['source_locale'] !== $this->sourceLocale
            || !hash_equals($payload['catalog_hash'], $this->hash($this->store->catalogState()))) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Language import conflict: catalog or target revision changed. Export/preview again.');
        }
    }

    private function batch(string $id, string $actor, string $kind, bool $expires = true, bool $lock = false): object
    {
        $query = $this->db->table($this->table())->where('id', $id)->where('actor', $actor)->where('kind', $kind);
        $batch = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$batch) throw new LanguageBatchNotFound('Language batch not found for this actor.');
        if ($expires) $this->assertNotExpired($batch);
        return $batch;
    }

    private function assertNotExpired(object $batch): void
    {
        if ((int) $batch->expires_at <= time()) throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException('Language batch expired. Export/preview again.');
    }

    private function insert(string $kind, string $locale, string $actor, string $status, int $revision, array $payload, string $identity): string
    {
        if ($actor === '' || strlen($actor) > 255) throw new InvalidArgumentException('Language batch needs an actor.');
        $id = (string) Str::uuid();
        $this->db->table($this->table())->insert(['id' => $id, 'kind' => $kind, 'locale' => $locale, 'actor' => $actor,
            'status' => $status, 'base_revision' => $revision, 'identity_hash' => $identity,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'expires_at' => time() + $this->ttl, 'created_at' => gmdate('Y-m-d H:i:s')]);
        return $id;
    }

    private function report(object $batch): array
    {
        $payload = json_decode($batch->payload, true, 512, JSON_THROW_ON_ERROR);
        return ['batch' => $batch->id, 'locale' => $batch->locale, 'status' => $batch->status,
            'base_revision' => (int) $batch->base_revision, 'result_revision' => $batch->result_revision === null ? null : (int) $batch->result_revision,
            'changes' => $payload['changes'] ?? [], 'unchanged' => $payload['unchanged'] ?? [],
            'untranslated' => $payload['untranslated'] ?? []];
    }

    private function hash(array $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)); }
    private function table(): string { return $this->prefix.'language_batches'; }

    private function validateParams(string $source, string $target, string $path): void
    {
        $params = static function ($text) {
            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $text, $matches);
            $names = array_unique($matches[1]); sort($names); return array_values($names);
        };
        if ($params($source) !== $params($target)) throw new InvalidArgumentException("Language placeholders differ: {$path}");
    }
}

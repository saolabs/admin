<?php

declare(strict_types=1);

namespace Saola\Admin\Language;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;
use Saola\Core\Language\LanguageStore;
use Saola\Core\Language\LanguageTree;

final class DatabaseLanguageStore implements LanguageStore
{
    public function __construct(private readonly ConnectionInterface $db, private readonly string $prefix = 'saola_') {}

    public function snapshot(string $locale): array
    {
        // One statement reads the revision and values from the same committed state.
        $rows = $this->db->table($this->prefix.'locales as l')
            ->leftJoin($this->prefix.'translation_values as v', 'v.locale', '=', 'l.code')
            ->leftJoin($this->prefix.'translation_keys as k', 'k.id', '=', 'v.key_id')
            ->where('l.code', $locale)->get(['l.published_revision', 'k.path', 'v.published_value']);
        $flat = [];
        foreach ($rows as $row) {
            if ($row->path !== null && $row->published_value !== null) {
                $flat[$row->path] = $row->published_value;
            }
        }
        return ['revision' => (int) ($rows->first()->published_revision ?? 0), 'messages' => LanguageTree::nest($flat)];
    }

    /** Sync the complete registered catalog; seed updates never overwrite human translations. */
    public function sync(array $catalog, string $sourceLocale, bool $dryRun = false): array
    {
        $source = $catalog[$sourceLocale] ?? [];
        foreach ($catalog as $entries) {
            foreach ($entries as $path => $entry) {
                if (!isset($source[$path])) {
                    throw new InvalidArgumentException("Missing source locale {$sourceLocale} for {$path}");
                }
                if ($this->parameters($source[$path]['value']) !== $this->parameters($entry['value'])) {
                    throw new InvalidArgumentException("Language placeholders differ: {$path}");
                }
            }
        }
        $changes = ['keys' => 0, 'values' => 0];
        $this->db->beginTransaction();
        try {
            // All writers lock the source locale first, then target locales in sorted order.
            $this->lockLocale($sourceLocale);
            LanguageTree::nest(array_fill_keys(array_unique(array_merge(
                $this->db->table($this->prefix.'translation_keys')->pluck('path')->all(), array_keys($source),
            )), ''));
            $locales = array_keys($catalog);
            sort($locales);
            foreach ($locales as $locale) {
                $this->lockLocale($locale);
            }
            $keys = [];
            foreach ($source as $path => $entry) {
                $current = $this->db->table($this->prefix.'translation_keys')->where('path', $path)->first();
                if ($current && ($current->owner !== $entry['owner'] || $current->source_file !== $entry['file'])) {
                    throw new InvalidArgumentException("Language key already owned by {$current->owner}: {$path}");
                }
                $hash = hash('sha256', $entry['value']);
                $data = ['owner' => $entry['owner'], 'source_file' => $entry['file'], 'source_locale' => $sourceLocale,
                    'source_text' => $entry['value'], 'source_hash' => $hash];
                if (!$current) {
                    $keys[$path] = $this->db->table($this->prefix.'translation_keys')->insertGetId(['path' => $path] + $data);
                    $changes['keys']++;
                } else {
                    $keys[$path] = $current->id;
                    if ($current->source_hash !== $hash || $current->source_locale !== $sourceLocale) {
                        $this->db->table($this->prefix.'translation_keys')->where('id', $current->id)->update($data);
                        $this->db->table($this->prefix.'translation_values')->where('key_id', $current->id)
                            ->where('origin', '!=', 'seed')->update(['needs_review' => true]);
                        $changes['keys']++;
                    }
                }
            }
            foreach ($catalog as $locale => $entries) {
                $changed = false;
                foreach ($entries as $path => $entry) {
                    $query = $this->db->table($this->prefix.'translation_values')->where('key_id', $keys[$path])->where('locale', $locale);
                    $current = $query->first();
                    $hash = hash('sha256', $entry['value']);
                    if (!$current) {
                        $this->db->table($this->prefix.'translation_values')->insert([
                            'key_id' => $keys[$path], 'locale' => $locale, 'published_value' => $entry['value'],
                            'origin' => 'seed', 'seed_hash' => $hash, 'needs_review' => false,
                        ]);
                    } elseif ($current->origin === 'seed' && $current->seed_hash === hash('sha256', (string) $current->published_value)
                        && $current->seed_hash !== $hash) {
                        $query->update(['published_value' => $entry['value'], 'seed_hash' => $hash, 'needs_review' => false]);
                    } else {
                        continue;
                    }
                    $changed = true;
                    $changes['values']++;
                }
                if ($changed) {
                    $this->db->table($this->prefix.'locales')->where('code', $locale)->increment('published_revision');
                }
            }
            if ($dryRun) {
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $changes;
    }

    /** Backend primitive, not an upload endpoint: caller authorizes and previews before publish. */
    public function publish(string $locale, array $tree, int $expectedRevision, string $sourceLocale = 'en', string $origin = 'human'): int
    {
        if (!in_array($origin, ['human', 'import', 'ai'], true)) throw new InvalidArgumentException('Invalid translation origin.');
        $flat = LanguageTree::flatten($tree);
        return $this->db->transaction(function () use ($locale, $flat, $expectedRevision, $sourceLocale, $origin) {
            $this->lockLocale($sourceLocale);
            $revision = $this->lockLocale($locale);
            if ($revision !== $expectedRevision) {
                throw new RuntimeException('Language revision conflict. Refresh the preview before publishing.');
            }
            $changed = false;
            foreach ($flat as $path => $value) {
                $key = $this->db->table($this->prefix.'translation_keys')->where('path', $path)->first();
                if (!$key) {
                    throw new InvalidArgumentException("Unknown language key: {$path}");
                }
                if ($this->parameters($key->source_text) !== $this->parameters($value)) {
                    throw new InvalidArgumentException("Language placeholders differ: {$path}");
                }
                $query = $this->db->table($this->prefix.'translation_values')->where('key_id', $key->id)->where('locale', $locale);
                $current = $query->first();
                if ($current && $current->published_value === $value && $current->origin === $origin && !$current->needs_review) {
                    continue;
                }
                $this->db->table($this->prefix.'translation_values')->updateOrInsert(
                    ['key_id' => $key->id, 'locale' => $locale],
                    ['published_value' => $value, 'origin' => $origin, 'needs_review' => false, 'seed_hash' => null],
                );
                $changed = true;
            }
            if ($changed) {
                $this->db->table($this->prefix.'locales')->where('code', $locale)->increment('published_revision');
            }
            return $revision + (int) $changed;
        });
    }

    private function lockLocale(string $locale): int
    {
        if (!preg_match('/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]{2,8})*$/D', $locale)) {
            throw new InvalidArgumentException("Invalid locale: {$locale}");
        }
        $this->db->table($this->prefix.'locales')->insertOrIgnore(['code' => $locale, 'published_revision' => 0]);
        return (int) $this->db->table($this->prefix.'locales')->where('code', $locale)->lockForUpdate()->value('published_revision');
    }

    private function parameters(string $value): array
    {
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $value, $matches);
        $names = array_unique($matches[1]);
        sort($names);
        return array_values($names);
    }

    public function locked(string $locale, string $sourceLocale, callable $operation): mixed
    {
        return $this->db->transaction(function () use ($locale, $sourceLocale, $operation) {
            $this->lockLocale($sourceLocale);
            $revision = $this->lockLocale($locale);
            return $operation($revision);
        });
    }

    public function catalogState(): array
    {
        $catalog = [];
        foreach ($this->db->table($this->prefix.'translation_keys')->orderBy('path')->get() as $key) {
            $catalog[$key->path] = ['text' => $key->source_text, 'hash' => $key->source_hash,
                'locale' => $key->source_locale, 'owner' => $key->owner, 'file' => $key->source_file];
        }
        return $catalog;
    }
}

<?php

declare(strict_types=1);

namespace Saola\Admin\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Saola\Admin\Resources\RichText;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** No global cache or actor state. A conditional revision update is the write boundary. */
final class DatabaseSettingsStore implements SettingsStore
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $table,
        private readonly array $locales,
        private readonly string $defaultLocale,
        private readonly ?\Closure $localePolicy = null,
    ) {
        if (!$locales || !in_array($defaultLocale, $locales, true)) throw new \InvalidArgumentException('Invalid content locales.');
        foreach ($locales as $locale) {
            if (!is_string($locale) || strlen($locale) > 40 || !preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/D', $locale)) throw new \InvalidArgumentException('Invalid content locale.');
        }
    }

    public function read(SettingsGroup $group): array
    {
        $row = $this->db->table($this->table)->where('group_key', $group->key)->first();
        if ($row && (int) $row->schema_version !== $group->version) throw new ConflictHttpException('Settings schema changed; migrate this group explicitly.');
        $stored = $row ? json_decode($row->document, true, 64, JSON_THROW_ON_ERROR) : [];
        $values = []; $localized = [];
        // Project only the registered fields, even when a previous schema stored more.
        foreach ($group->fields as $key => $field) {
            if ($field['localized'] ?? false) {
                foreach (array_keys($stored['localized'] ?? []) as $locale) {
                    if (array_key_exists($key, $stored['localized'][$locale] ?? [])) $localized[$locale][$key] = $stored['localized'][$locale][$key];
                }
            } elseif (array_key_exists($key, $stored['values'] ?? [])) {
                // A stored secret is reported as present but empty: the value itself never leaves the server.
                if ($field['type'] === 'secret') { if (($stored['values'][$key] ?? '') !== '') $values[$key] = ''; }
                else $values[$key] = $stored['values'][$key];
            }
        }
        $policy = $group->hasLocalizedFields() ? $this->policy() : ['locales' => [], 'default' => ''];
        return ['group' => $group->key, 'schemaVersion' => $group->version, 'revision' => (int) ($row->revision ?? 0),
            'values' => $values, 'localized' => $localized, 'contentLocales' => $policy['locales'], 'defaultLocale' => $policy['default']];
    }

    public function update(SettingsGroup $group, int $revision, array $patch): array
    {
        $this->validate($group, $patch);
        // Rich text is stored in its sanitized form, so what the response projects is what readers get.
        foreach ($patch['values'] ?? [] as $key => $value) if (($group->fields[$key]['type'] ?? '') === 'richtext') $patch['values'][$key] = RichText::clean($value);
        foreach ($patch['localized'] ?? [] as $locale => $fields) foreach ($fields as $key => $value) if (($group->fields[$key]['type'] ?? '') === 'richtext') $patch['localized'][$locale][$key] = RichText::clean($value);
        return $this->db->transaction(function () use ($group, $revision, $patch) {
            $this->db->table($this->table)->insertOrIgnore(['group_key' => $group->key, 'schema_version' => $group->version,
                'revision' => 0, 'document' => '{}']);
            $current = $this->read($group);
            if ($current['revision'] !== $revision) throw new ConflictHttpException('Settings changed; reload before saving.');
            // Preserve dormant extension fields in storage, while read()/responses
            // continue to project only the active schema. A temporary unregistration
            // must not destroy values when an unrelated field is saved.
            $stored = json_decode($this->db->table($this->table)->where('group_key', $group->key)->value('document'), true, 64, JSON_THROW_ON_ERROR);
            $incoming = $patch['values'] ?? [];
            foreach ($incoming as $key => $value) if (($group->fields[$key]['type'] ?? '') === 'secret') $incoming[$key] = $value === '' ? '' : Crypt::encryptString($value);
            $values = array_replace($stored['values'] ?? [], $incoming);
            $localized = $stored['localized'] ?? [];
            foreach ($patch['localized'] ?? [] as $locale => $fields) $localized[$locale] = array_replace($localized[$locale] ?? [], $fields);
            $changed = $this->db->table($this->table)->where('group_key', $group->key)->where('revision', $revision)
                ->where('schema_version', $group->version)->update(['document' => json_encode(['values' => $values, 'localized' => $localized], JSON_THROW_ON_ERROR), 'revision' => $revision + 1]);
            if ($changed !== 1) throw new ConflictHttpException('Settings changed; reload before saving.');
            // Return this transaction's version, rather than a later concurrent read.
            $masked = $patch['values'] ?? [];
            foreach ($masked as $key => $value) if (($group->fields[$key]['type'] ?? '') === 'secret') { if ($value === '') unset($masked[$key]); else $masked[$key] = ''; }
            $projected = array_replace($current['values'], $masked);
            foreach ($patch['values'] ?? [] as $key => $value) if (($group->fields[$key]['type'] ?? '') === 'secret' && $value === '') unset($projected[$key]);
            $translated = $current['localized'];
            foreach ($patch['localized'] ?? [] as $locale => $fields) $translated[$locale] = array_replace($translated[$locale] ?? [], $fields);
            return array_replace($current, ['revision' => $revision + 1, 'values' => $projected, 'localized' => $translated]);
        });
    }

    public function values(SettingsGroup $group, ?string $locale = null, bool $publicOnly = false): array
    {
        $document = $this->read($group); $values = [];
        $locale ??= $document['defaultLocale'];
        if ($group->hasLocalizedFields() && !in_array($locale, $document['contentLocales'], true)) throw new \InvalidArgumentException('Unsupported content locale.');
        foreach ($group->fields as $key => $field) {
            if ($publicOnly && !($field['public'] ?? false)) continue;
            if ($field['type'] === 'secret') { $values[$key] = $this->secret($group, $key) ?? $field['default']; continue; }
            if ($field['localized'] ?? false) {
                // Empty string is an intentional translation. Fallback never writes data.
                $values[$key] = $document['localized'][$locale][$key] ?? $document['localized'][$document['defaultLocale']][$key] ?? $field['default'];
            } else $values[$key] = $document['values'][$key] ?? $field['default'];
        }
        return $values;
    }

    /** Server-side only (mailer, API clients): the decrypted secret, or null when unset or unreadable (e.g. APP_KEY rotated). */
    private function secret(SettingsGroup $group, string $key): ?string
    {
        $stored = json_decode((string) $this->db->table($this->table)->where('group_key', $group->key)->value('document'), true, 64) ?: [];
        $cipher = $stored['values'][$key] ?? '';
        if ($cipher === '') return null;
        try { return Crypt::decryptString($cipher); } catch (DecryptException) { return null; }
    }

    private function policy(): array { return $this->localePolicy ? ($this->localePolicy)() : ['locales' => $this->locales, 'default' => $this->defaultLocale]; }

    private function validate(SettingsGroup $group, array $patch): void
    {
        $errors = [];
        if (!$patch || array_diff(array_keys($patch), ['values', 'localized'])) $errors['patch'][] = 'Only values and localized are accepted.';
        foreach (['values', 'localized'] as $section) {
            if (array_key_exists($section, $patch) && !is_array($patch[$section])) $errors[$section][] = 'Must be an object.';
        }
        if ($errors) throw ValidationException::withMessages($errors);
        if (!$group->hasLocalizedFields() && !empty($patch['localized'])) throw ValidationException::withMessages(['localized' => 'This settings group has one shared value set.']);
        $policy = $group->hasLocalizedFields() ? $this->policy() : ['locales' => []];
        $sets = ['values' => $patch['values'] ?? []];
        foreach ($patch['localized'] ?? [] as $locale => $fields) {
            if (!in_array($locale, $policy['locales'], true) || !is_array($fields)) { $errors['localized'][] = 'Unsupported locale or invalid fields.'; continue; }
            $sets['localized.'.$locale] = $fields;
        }
        $count = 0;
        foreach ($sets as $path => $fields) foreach ($fields as $key => $value) {
            ++$count; $field = $group->fields[$key] ?? null;
            if (!$field || (bool) ($field['localized'] ?? false) !== str_starts_with($path, 'localized.')) { $errors[$path.'.'.$key][] = 'Unknown field or wrong locale section.'; continue; }
            if (!$group->validValue($field, $value)) { $errors[$path.'.'.$key][] = 'Invalid value or constraint.'; continue; }
            if (is_string($value) && mb_strlen($value) > ($field['maxLength'] ?? 2048)) $errors[$path.'.'.$key][] = 'Text is too long.';
            if (is_int($value) && (isset($field['min']) && $value < $field['min'] || isset($field['max']) && $value > $field['max'])) $errors[$path.'.'.$key][] = 'Number is outside the allowed range.';
        }
        if ($count === 0) $errors['patch'][] = 'At least one field is required.';
        if ($errors) throw ValidationException::withMessages($errors);
    }
}

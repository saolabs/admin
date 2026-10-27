<?php

declare(strict_types=1);

namespace Saola\Admin\Language;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Website-wide locale policy. One revision/row serializes metadata/default changes; reads never seed. */
final class LocaleManager
{
    private ?string $fallback = null;
    public function __construct(private readonly ConnectionInterface $db, private readonly string $prefix, private readonly array $defaults, private readonly bool $detectBrowser = false) {}

    public function state(): array
    {
        $row = $this->db->table($this->prefix.'language_settings')->where('id', 1)->first();
        $document = $row ? json_decode($row->document, true, 32, JSON_THROW_ON_ERROR) : [];
        $policy = array_replace($this->defaults, $document);
        $locales = [];
        foreach ($this->db->table($this->prefix.'locales')->orderBy('code')->get() as $locale) {
            $locales[$locale->code] = ['code' => $locale->code, 'name' => $locale->name ?? $locale->code, 'country' => $locale->country ?? '', 'currency' => $locale->currency ?? '', 'revision' => (int) $locale->published_revision,
                'uiEnabled' => !in_array($locale->code, $policy['uiDisabled'] ?? [], true), 'contentEnabled' => in_array($locale->code, $policy['contentLocales'], true)];
        }
        // A host may already use a content language without a UI dictionary.
        foreach ($policy['contentLocales'] as $code) $locales[$code] ??= ['code' => $code, 'name' => $code, 'country' => '', 'currency' => '', 'revision' => 0, 'uiEnabled' => true, 'contentEnabled' => true];
        ksort($locales);
        return ['revision' => (int) ($row->revision ?? 0), 'uiDefault' => $policy['uiDefault'], 'uiFallback' => $policy['uiFallback'],
            'contentDefault' => $policy['contentDefault'], 'locales' => array_values($locales)];
    }

    public function contentPolicy(): array
    {
        $state = $this->state();
        return ['locales' => array_column(array_filter($state['locales'], fn ($item) => $item['contentEnabled']), 'code'), 'default' => $state['contentDefault']];
    }

    public function uiLocales(): array { return array_values(array_filter($this->state()['locales'], fn ($row) => $row['uiEnabled'] && $row['revision'] > 0)); }
    public function uiFallback(): string { return $this->fallback ??= $this->state()['uiFallback']; }

    public function resolveUi(Request $request): string
    {
        $state = $this->state();
        $available = array_column(array_filter($state['locales'], fn ($row) => $row['uiEnabled'] && $row['revision'] > 0), 'code');
        $locale = null;
        if ($request->header('X-Sao-Response') === 'json' && $request->header('Accept-Language')) {
            $locale = $this->preferred($request, $available);
        } elseif ($request->query->has('locale')) {
            $locale = $request->query('locale');
            abort_unless(is_string($locale) && preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale), 422);
            abort_unless(in_array($locale, $available, true), 404);
        }
        if ($locale !== null && in_array($locale, $available, true)) {
            if ($request->hasSession()) $request->session()->put('saola.ui_locale', $locale);
            return $locale;
        }
        $selected = $request->hasSession() ? $request->session()->get('saola.ui_locale') : null;
        if (in_array($selected, $available, true)) return $selected;
        if ($this->detectBrowser && $request->header('Accept-Language')) return $this->preferred($request, $available) ?? $state['uiDefault'];
        return $state['uiDefault'];
    }

    private function preferred(Request $request, array $available): ?string
    {
        foreach ($request->getLanguages() as $requested) foreach ($available as $code) {
            if (strcasecmp(str_replace('_', '-', $requested), str_replace('_', '-', $code)) === 0) return $code;
        }
        return null;
    }

    public function update(int $revision, array $input): array
    {
        $this->fallback = null;
        app('validator')->make($input, [
            'uiDefault' => ['required', 'string'], 'uiFallback' => ['required', 'string'], 'contentDefault' => ['required', 'string'],
            'locales' => ['required', 'array', 'max:100'], 'locales.*' => ['required', 'array:code,name,country,currency,uiEnabled,contentEnabled'],
            'locales.*.code' => ['required', 'string', 'distinct:strict', 'max:40', 'regex:/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D'],
            'locales.*.name' => ['required', 'string', 'max:100'],
            'locales.*.country' => ['sometimes', 'string', 'regex:/^(?:[A-Z]{2})?$/D'], 'locales.*.currency' => ['sometimes', 'string', 'regex:/^(?:[A-Z]{3})?$/D'], 'locales.*.uiEnabled' => ['required', 'boolean'], 'locales.*.contentEnabled' => ['required', 'boolean'],
        ])->validate();
        foreach ($input['locales'] as $row) if (!is_bool($row['uiEnabled']) || !is_bool($row['contentEnabled'])) throw ValidationException::withMessages(['locales' => 'Boolean flags are required.']);
        return $this->db->transaction(function () use ($revision, $input) {
            $this->db->table($this->prefix.'language_settings')->insertOrIgnore(['id' => 1, 'revision' => 0, 'document' => '{}']);
            $actual = (int) $this->db->table($this->prefix.'language_settings')->where('id', 1)->lockForUpdate()->value('revision');
            if ($revision !== $actual) throw new ConflictHttpException('Locale settings changed; reload before saving.');
            $current = $this->state(); $rows = array_column($current['locales'], null, 'code');
            foreach ($input['locales'] as $row) $rows[$row['code']] = array_replace($rows[$row['code']] ?? ['revision' => 0], $row);
            $identities = [];
            foreach (array_keys($rows) as $code) {
                $identity = strtolower(str_replace('_', '-', $code));
                if (isset($identities[$identity])) throw ValidationException::withMessages(['locales' => 'Duplicate locale identity.']);
                $identities[$identity] = true;
            }
            if (count($rows) > 100) throw ValidationException::withMessages(['locales' => 'At most 100 locales are supported.']);
            foreach (['uiDefault', 'uiFallback'] as $key) {
                $row = $rows[$input[$key]] ?? null;
                if (!$row || !$row['uiEnabled'] || $row['revision'] < 1) throw ValidationException::withMessages([$key => 'Choose an enabled language with a published dictionary.']);
            }
            if (!($rows[$input['contentDefault']]['contentEnabled'] ?? false)) throw ValidationException::withMessages(['contentDefault' => 'The default content language must be enabled.']);
            $disabled = []; $content = [];
            foreach ($rows as $row) {
                $this->db->table($this->prefix.'locales')->insertOrIgnore(['code' => $row['code'], 'published_revision' => 0]);
                $this->db->table($this->prefix.'locales')->where('code', $row['code'])->update(['name' => $row['name'], 'country' => ($row['country'] ?? '') ?: null, 'currency' => ($row['currency'] ?? '') ?: null]);
                if (!$row['uiEnabled']) $disabled[] = $row['code'];
                if ($row['contentEnabled']) $content[] = $row['code'];
            }
            sort($disabled); sort($content);
            $document = ['uiDefault' => $input['uiDefault'], 'uiFallback' => $input['uiFallback'], 'contentDefault' => $input['contentDefault'], 'uiDisabled' => $disabled, 'contentLocales' => $content];
            $changed = $this->db->table($this->prefix.'language_settings')->where('id', 1)->where('revision', $revision)
                ->update(['revision' => $revision + 1, 'document' => json_encode($document, JSON_THROW_ON_ERROR)]);
            if ($changed !== 1) throw new ConflictHttpException('Locale settings changed; reload before saving.');
            return $this->state();
        });
    }
}

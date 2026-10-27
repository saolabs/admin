<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Saola\Admin\Settings\{SettingsRegistry, SettingsStore};

/** Public website identity for the dark sidebar; resolved for each request/locale. */
final class PanelBranding
{
    public function project(string $locale): array
    {
        $values = [];
        foreach (app(SettingsRegistry::class)->all() as $group) {
            if (in_array($group->key, ['general', 'branding'], true)) {
                $store = app(SettingsStore::class);
                $contentLocale = $locale;
                if ($group->hasLocalizedFields()) {
                    $document = $store->read($group);
                    if (!in_array($locale, $document['contentLocales'], true)) $contentLocale = $document['defaultLocale'];
                }
                $values = array_replace($values, $store->values($group, $contentLocale, publicOnly: true));
            }
        }
        $brand = trim((string) ($values['website_name'] ?? ''));
        if ($brand === '') $brand = (string) (config('saola-admin.panel.brand') ?: config('app.name', 'Saola Admin'));
        return ['brand' => $brand, 'branding' => [
            'logoUrl' => $this->image($values['logo_dark_url'] ?? '') ?: $this->image($values['logo_url'] ?? ''),
            'compactLogoUrl' => $this->image($values['favicon_url'] ?? ''),
            'initials' => mb_strtoupper(mb_substr($brand, 0, 1)),
        ]];
    }

    private function image(mixed $value): string
    {
        if (!is_string($value) || $value === '' || preg_match('/[\x00-\x20\\\\]/', $value)) return '';
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return $value;
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true) ? $value : '';
    }
}

<?php

declare(strict_types=1);

namespace Saola\Admin\Settings;

/** Shared defaults; application-specific settings extend the registry. */
final class DefaultSettings
{
    public static function register(SettingsRegistry $registry): void
    {
        $text = static fn (string $key, bool $localized = true, string $type = 'text', int $max = 2048) => [
            'labelKey' => 'admin.settings.fields.'.$key, 'placeholderKey' => 'admin.settings.placeholders.'.$key, 'type' => $type, 'localized' => $localized, 'public' => true, 'default' => '', 'maxLength' => $max,
        ];
        $url = static fn (string $key) => $text($key, false, 'url') + ['relative' => true, 'media' => true];
        $siteRead = config('saola-admin.settings.read_ability', 'view-site-settings');
        $siteWrite = config('saola-admin.settings.write_ability', 'update-site-settings');
        $systemRead = config('saola-admin.settings.system_read_ability', 'view-system-settings');
        $systemWrite = config('saola-admin.settings.system_write_ability', 'update-system-settings');
        $registry->register(new SettingsGroup('general', 'admin.settings.general', $siteRead, $siteWrite, [
            'website_name' => ['labelKey' => 'admin.settings.website_name', 'placeholderKey' => 'admin.settings.placeholders.website_name', 'type' => 'text', 'localized' => true, 'public' => true, 'default' => (string) config('app.name', 'Saola'), 'maxLength' => 200],
            'description' => ['labelKey' => 'admin.settings.description', 'placeholderKey' => 'admin.settings.placeholders.description', 'type' => 'textarea', 'localized' => true, 'public' => true, 'default' => ''],
            'tagline' => $text('tagline', true, 'text', 200),
            'website_url' => $text('website_url', false, 'url'),
            'copyright' => $text('copyright', true, 'richtext', 4000),
            // Preserve the alpha.1 private key/API; no contact field in the default UI.
            'contact' => ['labelKey' => 'admin.settings.contact', 'type' => 'text', 'public' => false, 'hidden' => true, 'default' => ''],
        ]));
        $registry->register(new SettingsGroup('contact', 'admin.settings.groups_contact', $siteRead, $siteWrite, [
            'contact_email' => $text('contact_email', false, 'text', 200),
            'contact_phone' => $text('contact_phone', false, 'text', 50),
            'address' => $text('address', true, 'textarea', 500),
            'facebook_url' => $text('facebook_url', false, 'url'), 'x_url' => $text('x_url', false, 'url'), 'youtube_url' => $text('youtube_url', false, 'url'),
            'linkedin_url' => $text('linkedin_url', false, 'url'), 'tiktok_url' => $text('tiktok_url', false, 'url'),
        ]));
        $registry->register(new SettingsGroup('branding', 'admin.settings.groups_branding', $siteRead, $siteWrite, [
            'logo_url' => $url('logo_url'), 'logo_dark_url' => $url('logo_dark_url'), 'favicon_url' => $url('favicon_url'),
        ]));
        $registry->register(new SettingsGroup('seo', 'admin.settings.groups_seo', $siteRead, $siteWrite, [
            'meta_title' => $text('meta_title', true, 'text', 200),
            'meta_description' => $text('meta_description', true, 'textarea', 500),
            'meta_keywords' => $text('meta_keywords', true, 'text', 500),
            'og_title' => $text('og_title', true, 'text', 200),
            'og_description' => $text('og_description', true, 'textarea', 500),
            'og_image_url' => $url('og_image_url'),
            'indexable' => ['labelKey' => 'admin.settings.fields.indexable', 'type' => 'boolean', 'public' => true, 'default' => true],
        ]));
        $registry->register(new SettingsGroup('system', 'admin.settings.groups_system', $systemRead, $systemWrite, [
            'maintenance_mode' => ['labelKey' => 'admin.settings.fields.maintenance_mode', 'type' => 'boolean', 'default' => false],
            'maintenance_message' => ['labelKey' => 'admin.settings.fields.maintenance_message', 'placeholderKey' => 'admin.settings.placeholders.maintenance_message', 'type' => 'textarea', 'default' => '', 'maxLength' => 1000],
        ], category: 'system', categoryTitleKey: 'admin.settings.categories.system'));
        $zones = \DateTimeZone::listIdentifiers();
        $timezone = (string) config('app.timezone', 'UTC');
        try { new \DateTimeZone($timezone); } catch (\Exception) { $timezone = 'UTC'; }
        if (!in_array($timezone, $zones, true)) $zones[] = $timezone;
        $registry->register(new SettingsGroup('system-display', 'admin.settings.groups_display', $systemRead, $systemWrite, [
            'timezone' => ['labelKey' => 'admin.settings.fields.timezone', 'type' => 'select', 'default' => $timezone, 'options' => array_combine($zones, $zones)],
            'date_format' => ['labelKey' => 'admin.settings.fields.date_format', 'type' => 'select', 'default' => 'Y-m-d', 'options' => ['Y-m-d' => 'YYYY-MM-DD', 'd/m/Y' => 'DD/MM/YYYY', 'm/d/Y' => 'MM/DD/YYYY']],
            'time_format' => ['labelKey' => 'admin.settings.fields.time_format', 'type' => 'select', 'default' => 'H:i', 'options' => ['H:i' => '24h (HH:mm)', 'h:i A' => '12h (hh:mm AM/PM)']],
            'items_per_page' => ['labelKey' => 'admin.settings.fields.items_per_page', 'type' => 'integer', 'default' => 20, 'min' => 1, 'max' => 100],
        ], category: 'system', categoryTitleKey: 'admin.settings.categories.system'));
    }
}

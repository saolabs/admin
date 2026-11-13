<?php

declare(strict_types=1);

namespace Saola\Admin\Settings;

use Illuminate\Contracts\Cache\Repository as Cache;
use Saola\Core\Contracts\SiteProvider;

/**
 * Nguồn `__site` mặc định: mọi nhóm thiết lập có field `public`, chỉ các field
 * đó, giải theo locale nội dung — `['general' => [...], 'branding' => [...], …]`.
 *
 * Singleton sống cả worker Octane. Hai tầng cache theo phiên bản:
 *   1. memo trong worker `[version][locale]` — không I/O;
 *   2. Laravel Cache `saola.site.{version}.{locale}` — dùng chung mọi worker.
 * Mỗi request chỉ đọc khoá phiên bản (một lần đọc cache, không query).
 * Admin lưu một nhóm có field public ⇒ `SettingsUpdated` ⇒ `refresh()` tăng
 * phiên bản, request kế tiếp ở mọi worker dựng lại.
 */
final class PublicSettings implements SiteProvider
{
    public const VERSION_KEY = 'saola.site.version';

    /** @var array<string, array<string, array>> */
    private array $memo = [];

    public function __construct(private readonly Cache $cache) {}

    public function version(): string
    {
        return (string) $this->cache->get(self::VERSION_KEY, '1');
    }

    public function data(string $locale): array
    {
        $version = $this->version();
        if (isset($this->memo[$version][$locale])) {
            return $this->memo[$version][$locale];
        }
        try {
            $data = $this->cache->rememberForever("saola.site.{$version}.{$locale}", fn () => $this->build($locale));
        } catch (\Throwable) {
            // Cài mới, bảng settings chưa có: dùng default của field và KHÔNG cache,
            // để lần sau (khi đã migrate) đọc được giá trị thật.
            return $this->defaults();
        }
        // Chỉ giữ phiên bản hiện tại: phiên bản cũ không bao giờ được đọc lại.
        $this->memo = [$version => [$locale => $data] + ($this->memo[$version] ?? [])];

        return $data;
    }

    /** Gọi khi thiết lập công khai đổi; an toàn khi nhiều worker cùng gọi. */
    public function refresh(): void
    {
        $this->cache->add(self::VERSION_KEY, 1);
        $this->cache->increment(self::VERSION_KEY);
        $this->memo = [];
    }

    public static function isPublic(SettingsGroup $group): bool
    {
        foreach ($group->fields as $field) {
            if ($field['public'] ?? false) {
                return true;
            }
        }

        return false;
    }

    private function build(string $locale): array
    {
        $store = app(SettingsStore::class);
        $site = [];
        foreach ($this->groups() as $group) {
            $resolved = null;
            if ($group->hasLocalizedFields()) {
                $document = $store->read($group);
                $resolved = in_array($locale, $document['contentLocales'], true) ? $locale : $document['defaultLocale'];
            }
            $site[$group->key] = $store->values($group, $resolved ?: null, publicOnly: true);
        }

        return $site;
    }

    private function defaults(): array
    {
        $site = [];
        foreach ($this->groups() as $group) {
            foreach ($group->fields as $key => $field) {
                if ($field['public'] ?? false) {
                    $site[$group->key][$key] = $field['default'];
                }
            }
        }

        return $site;
    }

    /** @return list<SettingsGroup> */
    private function groups(): array
    {
        return array_values(array_filter(app(SettingsRegistry::class)->all(), self::isPublic(...)));
    }
}

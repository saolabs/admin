<?php

declare(strict_types=1);

namespace Saola\Admin\Settings;

interface SettingsStore
{
    public function read(SettingsGroup $group): array;
    public function update(SettingsGroup $group, int $revision, array $patch): array;
    public function values(SettingsGroup $group, string $locale, bool $publicOnly = false): array;
}

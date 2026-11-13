<?php

declare(strict_types=1);

namespace Saola\Admin\Settings;

/** Phát sau khi `SettingsStore::update()` ghi xong một nhóm. Dự án nghe được để làm mới cache riêng. */
final class SettingsUpdated
{
    public function __construct(public readonly SettingsGroup $group) {}
}

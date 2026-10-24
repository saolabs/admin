<?php

declare(strict_types=1);

namespace Saola\Admin\Settings;

use InvalidArgumentException;

final class SettingsRegistry
{
    private array $groups = [];

    public function register(SettingsGroup $group): void
    {
        if (isset($this->groups[$group->key])) throw new InvalidArgumentException('Duplicate settings group: '.$group->key);
        $this->groups[$group->key] = $group;
    }

    public function get(string $key): SettingsGroup
    {
        return $this->groups[$key] ?? throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    /** @return list<SettingsGroup> */
    public function all(): array { return array_values($this->groups); }
}

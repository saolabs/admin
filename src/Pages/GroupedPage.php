<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

/** Optional sidebar section; authorization still belongs to each child Page. */
interface GroupedPage extends Page
{
    public function navigationGroup(): string;
    public function navigationGroupTitleKey(): string;
}

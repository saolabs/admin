<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

/** A host screen served by module routes ("admin.{module}.{action}"); HostScreenController finds its Page through module(). */
interface ModulePage extends Page
{
    public function module(): string;
}

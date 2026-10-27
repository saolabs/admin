<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

/**
 * A screen the host renders at its own URL (inside the same shell via PanelData). The registry only lists it
 * in navigation, filtered by ability; /{prefix}/{key} redirects to url().
 */
interface LinkedPage extends Page
{
    /** Same-site absolute path, e.g. /control/ai/providers. */
    public function url(): string;
}

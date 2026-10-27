<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

/**
 * Sidebar entry declared on the page itself, so adding a page never edits a central list.
 * Keys (all optional): icon (named icon or a Remix class "ri-*"), order (int, default 100; a group sorts by its first child),
 * hidden (true keeps the page reachable by URL but out of the menu).
 */
interface NavigablePage extends Page
{
    public function navigation(): array;
}

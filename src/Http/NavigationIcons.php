<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

/** Small original outline set. Host chooses names, never executable SVG markup. */
final class NavigationIcons
{
    public static function path(string $name): string
    {
        return match ($name) {
            'dashboard' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
            'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M17 4a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.9',
            'media' => 'M3 3h18v18H3z M3 16l5-5 5 5 3-3 5 5 M15 7h.01',
            'language' => 'M3 5h12 M9 3v2 M6 5c0 6 3 9 8 11 M12 5c0 6-3 9-8 11 M14 21l4-10 4 10 M16 17h4',
            'settings' => 'M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8',
            'list' => 'M8 6h13 M8 12h13 M8 18h13 M3 6h.01 M3 12h.01 M3 18h.01',
            default => 'M3 7V5h6l2 2h10v13H3z',
        };
    }
}

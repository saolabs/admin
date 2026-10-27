<?php

declare(strict_types=1);

namespace Saola\Admin\Resources;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SafeUrlRule implements ValidationRule
{
    public static function safe(mixed $value): string
    {
        if (!is_string($value) || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) return '';
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return $value;
        $parts = parse_url($value);
        return filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !isset($parts['user']) && !isset($parts['pass']) ? $value : '';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::safe($value) === '') $fail('The :attribute must be an HTTP(S) URL or a site-relative path.');
    }
}

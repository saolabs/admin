<?php

declare(strict_types=1);

namespace Saola\Admin\Language;

/** Suggestions for the locale form (ISO 3166-1 alpha-2 / ISO 4217); any well-formed code is still accepted. */
final class Reference
{
    public const COUNTRIES = [
        'VN' => 'Vietnam', 'US' => 'United States', 'GB' => 'United Kingdom', 'AU' => 'Australia', 'CA' => 'Canada', 'SG' => 'Singapore', 'FR' => 'France', 'DE' => 'Germany',
        'ES' => 'Spain', 'IT' => 'Italy', 'PT' => 'Portugal', 'BR' => 'Brazil', 'MX' => 'Mexico', 'NL' => 'Netherlands', 'RU' => 'Russia', 'UA' => 'Ukraine',
        'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China', 'TW' => 'Taiwan', 'HK' => 'Hong Kong', 'TH' => 'Thailand', 'ID' => 'Indonesia', 'MY' => 'Malaysia',
        'PH' => 'Philippines', 'IN' => 'India', 'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'TR' => 'Turkey', 'PL' => 'Poland', 'SE' => 'Sweden', 'CH' => 'Switzerland',
    ];

    public const CURRENCIES = [
        'VND' => 'Vietnamese dong', 'USD' => 'US dollar', 'EUR' => 'Euro', 'GBP' => 'Pound sterling', 'JPY' => 'Japanese yen', 'KRW' => 'South Korean won', 'CNY' => 'Chinese yuan',
        'TWD' => 'New Taiwan dollar', 'HKD' => 'Hong Kong dollar', 'SGD' => 'Singapore dollar', 'AUD' => 'Australian dollar', 'CAD' => 'Canadian dollar', 'THB' => 'Thai baht',
        'IDR' => 'Indonesian rupiah', 'MYR' => 'Malaysian ringgit', 'PHP' => 'Philippine peso', 'INR' => 'Indian rupee', 'BRL' => 'Brazilian real', 'MXN' => 'Mexican peso',
        'RUB' => 'Russian ruble', 'TRY' => 'Turkish lira', 'AED' => 'UAE dirham', 'SAR' => 'Saudi riyal', 'CHF' => 'Swiss franc', 'SEK' => 'Swedish krona', 'PLN' => 'Polish zloty',
    ];

    public static function all(): array
    {
        $list = fn (array $map) => array_map(fn ($code, $name) => ['code' => $code, 'name' => $name], array_keys($map), $map);
        return ['countries' => $list(self::COUNTRIES), 'currencies' => $list(self::CURRENCIES)];
    }
}

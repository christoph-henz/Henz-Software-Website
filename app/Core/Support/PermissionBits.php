<?php

declare(strict_types=1);

namespace App\Core\Support;

final class PermissionBits
{
    /** @var array<string, int> */
    private static array $cache = [];

    public static function resolve(string $slug, int $fallback = 0): int
    {
        $slug = trim($slug);
        if ($slug === '') {
            return $fallback;
        }

        if (array_key_exists($slug, self::$cache)) {
            return self::$cache[$slug];
        }

        $row = db('permissions')
            ->where('slug', $slug)
            ->select(['bit_value'])
            ->first();

        $bitValue = (int) ($row['bit_value'] ?? 0);
        if ($bitValue <= 0) {
            $bitValue = $fallback;
        }

        self::$cache[$slug] = $bitValue;

        return $bitValue;
    }
}

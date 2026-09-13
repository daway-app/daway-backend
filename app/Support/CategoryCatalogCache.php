<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CategoryCatalogCache
{
    private const VERSION_KEY = 'category_catalog_version';

    private const VERSION_TTL = 2592000;

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function bump(): void
    {
        Cache::add(self::VERSION_KEY, 1, self::VERSION_TTL);
        Cache::increment(self::VERSION_KEY);
    }
}

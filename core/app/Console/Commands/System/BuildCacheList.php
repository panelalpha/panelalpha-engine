<?php

namespace App\Console\Commands\System;

use App\Lib\Deploy\CacheManager\BuildCaches;
use Illuminate\Console\Command;

/** The build caches the cache registry holds, one `repository:branch` tag per build. GET /system/build-caches. */
class BuildCacheList extends Command
{
    protected $signature = 'system:build-cache:list';

    protected $description = 'List the BuildKit caches the cache registry holds for accounts to import';

    public function handle(): int
    {
        try {
            $caches = BuildCaches::make()->all();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($caches === []) {
            $this->info('The cache registry holds no build cache.');

            return self::SUCCESS;
        }
        foreach ($caches as $cache) {
            $this->line($cache['ref']);
        }

        return self::SUCCESS;
    }
}

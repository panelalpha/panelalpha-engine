<?php

namespace App\Console\Commands\System;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\CacheManager\BuildCaches;
use Illuminate\Console\Command;

/**
 * Remove a repository's build caches from the cache registry, every build of
 * one branch or of all of them. DELETE /system/build-caches.
 */
class BuildCacheRemove extends Command
{
    protected $signature = 'system:build-cache:remove
        {repository : The clone URL the cache was built from}
        {--branch= : Only this branch; every branch when omitted}';

    protected $description = 'Remove a repository\'s BuildKit caches from the cache registry';

    public function handle(): int
    {
        try {
            $removed = BuildCaches::make()->remove((string) $this->argument('repository'), (string) $this->option('branch'));
        } catch (ProblemException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($removed === []) {
            $this->info('No build cache for that repository.');

            return self::SUCCESS;
        }
        foreach ($removed as $ref) {
            $this->line("removed {$ref}");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands\System;

use App\Lib\Deploy\CacheManager\BuildCacheProducer;
use App\Lib\Deploy\CacheManager\BuildCacheRefused;
use Illuminate\Console\Command;

/**
 * Build a public repository on the host and publish its BuildKit caches, in
 * the foreground. POST /system/build-caches queues the same as a task.
 */
class BuildCacheBuild extends Command
{
    protected $signature = 'system:build-cache:build
        {repository : A public https clone URL}
        {--branch= : The branch accounts deploy; the default branch, by its name, when omitted}';

    protected $description = 'Build a public repository on the host and publish its BuildKit caches for accounts to import';

    public function handle(): int
    {
        $branch = trim((string) $this->option('branch'));
        try {
            $published = BuildCacheProducer::make(fn (string $line) => $this->line($line))
                ->build(trim((string) $this->argument('repository')), $branch === '' ? null : $branch);
        } catch (BuildCacheRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($published === []) {
            $this->error('No build of this repository produced a cache.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

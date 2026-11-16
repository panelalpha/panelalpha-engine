<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\ReadsOperatorImageBuild;
use App\Lib\Deploy\CacheManager\OperatorImages;
use Illuminate\Console\Command;

/**
 * The weekly refresh of one operator image, on demand: pull it again,
 * rebuild it from upstream with no build cache, or push the host copy again.
 */
class ImageRefreshCommand extends Command
{
    use ReadsOperatorImageBuild;

    protected $signature = 'system:image:refresh {ref : The operator image}';

    protected $description = 'Re-pull, rebuild or re-push an operator base image now';

    public function handle(): int
    {
        $images = OperatorImages::make();
        $image = OperatorImages::findByRefOrFail((string) $this->argument('ref'));
        $images->refuseWhileBusy($image);

        try {
            $renewed = $images->refresh($image, $this->writeOutput(...));
        } catch (\Throwable $e) {
            $this->error("{$image->ref} could not be refreshed: ".trim($e->getMessage()));
            $this->line('The cache registry keeps the copy it had.');

            return self::FAILURE;
        }

        $this->info($renewed
            ? "{$image->ref} refreshed; the cache registry serves {$image->digest}."
            : "{$image->ref} is not on this host any more; the cache registry keeps the copy it has.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands\System;

use App\Lib\Deploy\CacheManager\OperatorImages;
use Illuminate\Console\Command;

/**
 * Take an operator image out of the cache registry and forget it. Projects
 * that already have it keep their copy; a new build FROM it fails.
 */
class ImageRemoveCommand extends Command
{
    protected $signature = 'system:image:remove {ref : The operator image}';

    protected $description = 'Remove an operator base image from the cache registry';

    public function handle(): int
    {
        $images = OperatorImages::make();
        $image = OperatorImages::findByRefOrFail((string) $this->argument('ref'));
        $images->refuseWhileBusy($image);

        try {
            $outcome = $images->remove($image);
        } catch (\Throwable $e) {
            $this->error("{$image->ref} could not be removed: ".trim($e->getMessage()));

            return self::FAILURE;
        }

        $this->info("{$image->ref} is no longer an operator image ({$outcome}).");

        return self::SUCCESS;
    }
}

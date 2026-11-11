<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\ReadsOperatorImageBuild;
use App\Lib\Deploy\CacheManager\OperatorImages;
use Illuminate\Console\Command;

/**
 * A changed build is rebuilt and published again before the command returns.
 */
class ImageUpdateCommand extends Command
{
    use ReadsOperatorImageBuild;

    private const USAGE = 'pae system:image:update <name:tag> --build < Dockerfile';

    protected $signature = 'system:image:update
        {ref : The operator image}
        {--build : Replace the Dockerfile with the one read from standard input}
        {--build-arg=* : NAME=value, replacing every build argument}
        {--no-build-args : Drop every build argument}
        {--why= : A new note on what the image is for}';

    protected $description = 'Change an operator base image and rebuild it when its build changed';

    public function handle(): int
    {
        $images = OperatorImages::make();
        $image = OperatorImages::findByRefOrFail((string) $this->argument('ref'));
        if (! $images->update($image, $this->changes())) {
            $this->info("{$image->ref} updated; its build did not change, so nothing was rebuilt.");

            return self::SUCCESS;
        }

        try {
            $images->publish($image, false, $this->writeOutput(...));
        } catch (\Throwable $e) {
            $this->error("{$image->ref} could not be rebuilt: ".trim($e->getMessage()));
            $this->line('The cache registry keeps the copy it had.');

            return self::FAILURE;
        }
        $this->info("{$image->ref} rebuilt; the cache registry serves {$image->digest}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function changes(): array
    {
        $changes = [];
        if ($this->option('why') !== null) {
            $changes['why'] = $this->option('why');
        }
        if ($this->option('build')) {
            $changes['dockerfile'] = $this->dockerfileFromStdin(self::USAGE);
        }
        if ($this->option('no-build-args')) {
            $changes['build_args'] = null;
        } elseif ($this->option('build-arg') !== []) {
            $changes['build_args'] = $this->buildArgOptions();
        }

        return $changes;
    }
}

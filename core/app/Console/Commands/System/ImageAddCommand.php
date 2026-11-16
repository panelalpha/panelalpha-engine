<?php

namespace App\Console\Commands\System;

use App\Console\Commands\Concerns\ReadsOperatorImageBuild;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\CacheManager\OperatorImages;
use App\Models\OperatorImage;
use Illuminate\Console\Command;

/**
 * Publishes the image before it returns, printing the pull or build as it runs.
 */
class ImageAddCommand extends Command
{
    use ReadsOperatorImageBuild;

    private const USAGE = 'pae system:image:add <name:tag> --build < Dockerfile';

    protected $signature = 'system:image:add
        {ref : The image name with a tag, e.g. whmcs-dev-base:php8.3}
        {--pull : Pull it from its registry}
        {--build : Build the Dockerfile read from standard input, with no build context}
        {--local : Push an image already on this host}
        {--build-arg=* : NAME=value for the build; shows in the image history}
        {--why= : A note on what the image is for}';

    protected $description = 'Add a base image to the cache registry for every project on this host';

    public function handle(): int
    {
        $source = $this->source();
        $images = OperatorImages::make();
        $image = $images->create([
            'ref' => (string) $this->argument('ref'),
            'source' => $source,
            'dockerfile' => $source === OperatorImage::SOURCE_BUILD ? $this->dockerfileFromStdin(self::USAGE) : null,
            'build_args' => $source === OperatorImage::SOURCE_BUILD ? $this->buildArgOptions() : null,
            'why' => $this->option('why'),
        ]);

        try {
            $images->publish($image, false, $this->writeOutput(...));
        } catch (\Throwable $e) {
            $this->error("{$image->ref} could not be published: ".trim($e->getMessage()));
            $this->line("It is recorded as failed. Fix the cause, then run `pae system:image:refresh {$image->ref}`, or `pae system:image:remove {$image->ref}`.");

            return self::FAILURE;
        }

        $this->info("{$image->ref} is in the cache registry as {$image->digest}. Projects on this host can build FROM {$image->ref}.");

        return self::SUCCESS;
    }

    private function source(): string
    {
        $chosen = array_values(array_filter(
            OperatorImage::SOURCES,
            fn (string $source): bool => (bool) $this->option($source)
        ));
        if (count($chosen) !== 1) {
            throw ProblemException::one('source', OperatorImages::SOURCE_INVALID, 'Pass exactly one of --pull, --build or --local.');
        }

        return $chosen[0];
    }
}

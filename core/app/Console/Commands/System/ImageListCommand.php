<?php

namespace App\Console\Commands\System;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\SystemImageListing;
use Illuminate\Console\Command;

/**
 * The operator's images, then the engine's catalogue, each with whether the
 * cache registry holds it.
 */
class ImageListCommand extends Command
{
    protected $signature = 'system:image:list
        {--operator : Only the operator\'s own images}';

    protected $description = 'List the base images the cache registry keeps for every project on this host';

    public function handle(): int
    {
        $listing = SystemImageListing::make();
        $entries = $this->option('operator') ? $listing->operators() : $listing->all();
        if ($entries === []) {
            $this->line('No operator image. Add one with `pae system:image:add`.');

            return self::SUCCESS;
        }

        $this->table(
            ['Owner', 'Ref', 'Source', 'State', 'In registry', 'Size', 'Refreshed', 'Why'],
            array_map(self::row(...), $entries)
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private static function row(array $entry): array
    {
        return [
            (string) $entry['owner'],
            (string) $entry['ref'],
            (string) $entry['source'],
            self::state($entry),
            match ($entry['present']) {
                true => 'yes',
                false => 'no',
                default => 'unknown',
            },
            is_int($entry['size'] ?? null) ? HostPrewarmPlan::formatBytes($entry['size']) : '-',
            (string) ($entry['refreshed_at'] ?? '-'),
            (string) ($entry['why'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function state(array $entry): string
    {
        if ($entry['owner'] === 'engine') {
            return ($entry['selected'] ?? false) ? 'prewarmed' : 'on demand';
        }

        return (string) $entry['status'];
    }
}

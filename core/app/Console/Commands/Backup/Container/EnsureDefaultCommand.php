<?php

namespace App\Console\Commands\Backup\Container;

use App\Models\BackupContainer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gives a host with no store the local one the engine backs up into, so a
 * panel can back a project up without being configured first.
 */
class EnsureDefaultCommand extends Command
{
    public const NAME = 'local';

    public const LOCATION = '/opt/panelalpha/backups/local';

    protected $signature = 'backup:container:ensure-default';

    protected $description = 'Create the default local backup container when the host has none';

    public function handle(): int
    {
        $existing = BackupContainer::query()->orderBy('id')->first();
        if ($existing !== null) {
            $existing->claimDefaultWhenNone();
            $this->info("A backup container already exists ({$existing->name}); nothing created.");

            return 0;
        }

        /** @var BackupContainer $container */
        $container = BackupContainer::create([
            'name' => self::NAME,
            'driver' => 'local',
            'location' => self::LOCATION,
            'credentials' => null,
        ]);
        $container->makeDefault();
        $this->prepareLocation($container);

        $this->info("Default backup container created (ID: {$container->id}, location: {$container->location}).");

        return 0;
    }

    /** The backup job creates and opens the directory itself when this host's broker is not reachable yet. */
    private function prepareLocation(BackupContainer $container): void
    {
        try {
            $container->storage()->test();
        } catch (Throwable $e) {
            $this->warn("Could not prepare {$container->location}: {$e->getMessage()}");
        }
    }
}

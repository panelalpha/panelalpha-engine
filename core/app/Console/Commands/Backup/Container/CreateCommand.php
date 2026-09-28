<?php

namespace App\Console\Commands\Backup\Container;

use App\Models\BackupContainer;
use Illuminate\Validation\ValidationException;

use App\Http\Requests\Concerns\NormalizesBackupContainerCredentials;

class CreateCommand extends BackupContainerCommand
{
    use NormalizesBackupContainerCredentials;
    protected $signature = 'backup:container:create' . self::OPTIONS;

    protected $description = 'Create a backup container';

    public function handle(): int
    {
        $name = $this->stringOption('name');
        $driver = $this->stringOption('driver');
        $location = $this->stringOption('location');

        if (!$this->input->isInteractive()) {
            if ($name === null) {
                $this->error('The --name option is required in non-interactive mode.');
                return 1;
            }
            if ($driver === null) {
                $this->error('The --driver option is required in non-interactive mode.');
                return 1;
            }
            if ($location === null) {
                $this->error('The --location option is required in non-interactive mode.');
                return 1;
            }
        } else {
            $name ??= $this->ask('Container name');
            $driver ??= $this->choice('Storage driver', self::DRIVERS, 0);
            $location ??= $this->ask('Storage location');
        }

        if (!is_string($name) || $name === '') {
            $this->error('Container name is required.');
            return 1;
        }
        if (!is_string($driver) || !in_array($driver, self::DRIVERS, true)) {
            $this->error('Driver must be one of: local, s3, ftp, ftps, sftp.');
            return 1;
        }
        if (!is_string($location) || $location === '') {
            $this->error('Storage location is required.');
            return 1;
        }
        if (strlen($location) > 1024) {
            $this->error('Storage location must be at most 1024 characters.');
            return 1;
        }
        if ($driver === 'local') {
            try {
                $this->assertLocalLocationIsSafe($location);
            } catch (ValidationException $e) {
                $messages = collect($e->errors())->flatten();
                $this->error($messages->first() ?? $e->getMessage());
                return 1;
            }
        }

        if (BackupContainer::query()->where('name', $name)->exists()) {
            $this->error("A backup container named '{$name}' already exists.");
            return 1;
        }

        try {
            $credentials = $this->buildCredentials($driver, requireIdentity: true);
            $this->assertCredentialsComplete($driver, $credentials);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info('New backup container:');
        $this->line("  Name: {$name}");
        $this->line("  Driver: {$driver}");
        $this->line("  Location: {$location}");
        $this->line('  Has credentials: ' . ($credentials === null ? 'no' : 'yes'));

        if (!$this->option('force') && !$this->confirm('Create this container?')) {
            $this->info('Cancelled.');
            return 0;
        }

        /** @var BackupContainer $container */
        $container = BackupContainer::create([
            'name' => $name,
            'driver' => $driver,
            'location' => $location,
            'credentials' => $credentials,
        ]);

        $this->info("Backup container created (ID: {$container->id}).");
        return 0;
    }
}

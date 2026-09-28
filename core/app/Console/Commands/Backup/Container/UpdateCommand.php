<?php

namespace App\Console\Commands\Backup\Container;

use App\Models\BackupContainer;

class UpdateCommand extends BackupContainerCommand
{
    protected $signature = 'backup:container:update {container : Container ID or name}' . self::OPTIONS;

    protected $description = 'Update a backup container';

    public function handle(): int
    {
        $containerArg = $this->argument('container');
        if (!is_string($containerArg)) {
            $this->error('Invalid container identifier.');
            return 1;
        }

        $container = BackupContainer::findByIdOrName($containerArg);
        if ($container === null) {
            $this->error("Backup container '{$containerArg}' not found.");
            return 1;
        }

        $updates = $this->requestedUpdates($container);
        if ($updates === null) {
            return 1;
        }

        $refusal = $this->refusal($container, $updates);
        if ($refusal !== null) {
            $this->error($refusal);
            return 1;
        }

        $this->showChanges($container, $updates);

        if (!$this->option('force') && !$this->confirm('Apply these changes?')) {
            $this->info('Cancelled.');
            return 0;
        }

        $container->update($updates);
        $this->info('Backup container updated successfully.');

        return 0;
    }

    /**
     * The fields the options change, or null once a refusal has been printed.
     *
     * @return ?array<string, mixed>
     */
    private function requestedUpdates(BackupContainer $container): ?array
    {
        $updates = [];

        $name = $this->stringOption('name');
        if ($name !== null) {
            if (BackupContainer::query()->where('name', $name)->where('id', '!=', $container->id)->exists()) {
                $this->error("A backup container named '{$name}' already exists.");
                return null;
            }
            $updates['name'] = $name;
        }

        $driver = $this->stringOption('driver');
        if ($driver !== null) {
            if (!in_array($driver, self::DRIVERS, true)) {
                $this->error('Driver must be one of: local, s3, ftp, ftps, sftp.');
                return null;
            }
            $updates['driver'] = $driver;
        }

        $location = $this->stringOption('location');
        if ($location !== null) {
            $updates['location'] = $location;
        }

        $effectiveDriver = $updates['driver'] ?? $container->driver;
        if ($this->credentialFlagsProvided($effectiveDriver)) {
            try {
                $merged = array_merge($container->credentials ?? [], $this->buildCredentials($effectiveDriver) ?? []);
                $updates['credentials'] = $merged === [] ? null : $merged;
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return null;
            }
        } elseif (($updates['driver'] ?? null) === 'local') {
            $updates['credentials'] = null;
        }

        return $updates;
    }

    /**
     * Why the container would be left unusable, or null.
     *
     * @param array<string, mixed> $updates
     */
    private function refusal(BackupContainer $container, array $updates): ?string
    {
        if ($updates === []) {
            return 'No updates provided.';
        }

        $resultingDriver = $updates['driver'] ?? $container->driver;
        $resultingCredentials = array_key_exists('credentials', $updates)
            ? $updates['credentials']
            : $container->credentials;

        if ($resultingDriver !== 'local' && ($resultingCredentials === null || $resultingCredentials === [])) {
            return 'Credentials are required for non-local backup storage.';
        }

        try {
            $this->assertCredentialsComplete($resultingDriver, $resultingCredentials);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** @param array<string, mixed> $updates */
    private function showChanges(BackupContainer $container, array $updates): void
    {
        $this->info('Current values:');
        $this->line("  Name: {$container->name}");
        $this->line("  Driver: {$container->driver}");
        $this->line("  Location: {$container->location}");
        $this->line('  Has credentials: ' . ($container->credentials !== null ? 'yes' : 'no'));

        $this->info('New values:');
        foreach ($updates as $key => $value) {
            if ($key === 'credentials') {
                $this->line('  Credentials: ' . ($value === null ? 'none' : 'updated'));
                continue;
            }
            $this->line('  ' . ucfirst($key) . ": {$value}");
        }
    }

    private function credentialFlagsProvided(string $driver): bool
    {
        if ($driver === 's3') {
            return $this->stringOption('s3-access-key-id') !== null
                || $this->stringOption('s3-secret-access-key') !== null
                || $this->stringOption('s3-region') !== null
                || $this->stringOption('s3-endpoint') !== null
                || $this->option('s3-use-path-style')
                || $this->stringOption('s3-prefix') !== null;
        }

        if (in_array($driver, ['ftp', 'ftps'], true)) {
            return $this->stringOption('ftp-host') !== null
                || $this->stringOption('ftp-port') !== null
                || $this->stringOption('ftp-username') !== null
                || $this->stringOption('ftp-password') !== null;
        }

        if ($driver === 'sftp') {
            return $this->stringOption('sftp-host') !== null
                || $this->stringOption('sftp-port') !== null
                || $this->stringOption('sftp-username') !== null
                || $this->stringOption('sftp-password') !== null
                || $this->stringOption('sftp-private-key') !== null
                || $this->stringOption('sftp-passphrase') !== null;
        }

        return false;
    }
}

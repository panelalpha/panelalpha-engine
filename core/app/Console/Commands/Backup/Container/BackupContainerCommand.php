<?php

namespace App\Console\Commands\Backup\Container;

use Illuminate\Console\Command;

/**
 * What backup:container:create and backup:container:update share: the
 * storage options, and turning them into the credentials a driver needs.
 */
abstract class BackupContainerCommand extends Command
{
    protected const DRIVERS = ['local', 's3', 'ftp', 'ftps', 'sftp'];

    /** Every option both commands take, after the command name. */
    protected const OPTIONS = '
        {--name= : Container name}
        {--driver= : Storage driver (local, s3, ftp, ftps, sftp)}
        {--location= : Storage location}
        {--s3-access-key-id= : S3 access key ID}
        {--s3-secret-access-key= : S3 secret access key}
        {--s3-region= : S3 region}
        {--s3-endpoint= : S3 endpoint}
        {--s3-use-path-style : S3 path-style endpoint}
        {--s3-prefix= : S3 key prefix}
        {--ftp-host= : FTP/FTPS host}
        {--ftp-port= : FTP/FTPS port}
        {--ftp-username= : FTP/FTPS username}
        {--ftp-password= : FTP/FTPS password}
        {--sftp-host= : SFTP host}
        {--sftp-port= : SFTP port}
        {--sftp-username= : SFTP username}
        {--sftp-password= : SFTP password}
        {--sftp-private-key= : SFTP private key}
        {--sftp-passphrase= : SFTP private key passphrase}
        {--force : Skip confirmation}';

    /**
     * The credentials the options give for this driver, empty values dropped.
     * With $requireIdentity, a run that cannot ask refuses to go on without the
     * key pair (s3) or host and username (ftp/ftps/sftp).
     *
     * @return ?array<string, mixed>
     */
    protected function buildCredentials(string $driver, bool $requireIdentity = false): ?array
    {
        if ($driver === 'local') {
            return null;
        }

        $mustHave = $requireIdentity && !$this->input->isInteractive();

        if ($driver === 's3') {
            $accessKeyId = $this->stringOption('s3-access-key-id');
            $secretAccessKey = $this->stringOption('s3-secret-access-key');
            if ($mustHave) {
                if ($accessKeyId === null) {
                    throw new \InvalidArgumentException('The --s3-access-key-id option is required in non-interactive mode for s3.');
                }
                if ($secretAccessKey === null) {
                    throw new \InvalidArgumentException('The --s3-secret-access-key option is required in non-interactive mode for s3.');
                }
            }

            return array_filter([
                'access_key_id' => $accessKeyId,
                'secret_access_key' => $secretAccessKey,
                'region' => $this->stringOption('s3-region'),
                'endpoint' => $this->stringOption('s3-endpoint'),
                'use_path_style_endpoint' => $this->option('s3-use-path-style') ? true : null,
                'prefix' => $this->stringOption('s3-prefix'),
            ], static fn ($value) => $value !== null && $value !== '');
        }

        if (in_array($driver, ['ftp', 'ftps'], true)) {
            $host = $this->stringOption('ftp-host');
            $username = $this->stringOption('ftp-username');
            if ($mustHave) {
                if ($host === null) {
                    throw new \InvalidArgumentException('The --ftp-host option is required in non-interactive mode for ftp/ftps.');
                }
                if ($username === null) {
                    throw new \InvalidArgumentException('The --ftp-username option is required in non-interactive mode for ftp/ftps.');
                }
            }

            return array_filter([
                'host' => $host,
                'username' => $username,
                'password' => $this->stringOption('ftp-password'),
                'port' => $this->numericOption('ftp-port'),
            ], static fn ($value) => $value !== null && $value !== '');
        }

        if ($driver === 'sftp') {
            $host = $this->stringOption('sftp-host');
            $username = $this->stringOption('sftp-username');
            if ($mustHave) {
                if ($host === null) {
                    throw new \InvalidArgumentException('The --sftp-host option is required in non-interactive mode for sftp.');
                }
                if ($username === null) {
                    throw new \InvalidArgumentException('The --sftp-username option is required in non-interactive mode for sftp.');
                }
            }

            return array_filter([
                'host' => $host,
                'username' => $username,
                'password' => $this->stringOption('sftp-password'),
                'private_key' => $this->stringOption('sftp-private-key'),
                'passphrase' => $this->stringOption('sftp-passphrase'),
                'port' => $this->numericOption('sftp-port'),
            ], static fn ($value) => $value !== null && $value !== '');
        }

        throw new \InvalidArgumentException('Unsupported driver.');
    }

    /** @param ?array<string, mixed> $credentials */
    protected function assertCredentialsComplete(string $driver, ?array $credentials): void
    {
        if ($driver === 'local') {
            return;
        }

        if ($driver === 's3') {
            $this->requireCredentialValue($credentials, 'access_key_id');
            $this->requireCredentialValue($credentials, 'secret_access_key');
            return;
        }

        if (in_array($driver, ['ftp', 'ftps', 'sftp'], true)) {
            $this->requireCredentialValue($credentials, 'host');
            $this->requireCredentialValue($credentials, 'username');
        }
    }

    /** @param ?array<string, mixed> $credentials */
    private function requireCredentialValue(?array $credentials, string $key): void
    {
        $value = $credentials[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Credential {$key} is required for this driver.");
        }
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    protected function numericOption(string $name): ?int
    {
        $value = $this->option($name);
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}

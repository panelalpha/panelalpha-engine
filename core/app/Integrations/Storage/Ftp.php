<?php

namespace App\Integrations\Storage;

use App\Models\BackupContainer;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

final class Ftp extends AbstractStorage
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {}

    public static function fromContainer(BackupContainer $container): self
    {
        return new self(self::filesystem($container));
    }

    public function put(string $key, $stream): void
    {
        try {
            $this->filesystem->writeStream($this->normalizeKey($key), $stream);
        } catch (UnableToWriteFile $exception) {
            throw new RuntimeException("Could not write backup object: {$key}", 0, $exception);
        }
    }

    public function readStream(string $key)
    {
        try {
            return $this->filesystem->readStream($this->normalizeKey($key));
        } catch (UnableToReadFile $exception) {
            throw new RuntimeException("Backup object not found: {$key}", 0, $exception);
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->filesystem->delete($this->normalizeKey($key));
        } catch (UnableToDeleteFile) {
        }
    }

    public function deletePrefix(string $prefix): void
    {
        $normalized = $this->normalizeRelativePath($prefix);
        if ($normalized === '') {
            return;
        }

        foreach ($this->filesystem->listContents($normalized, true) as $attributes) {
            if ($attributes->isFile()) {
                $this->filesystem->delete($attributes->path());
            }
        }
    }

    public function exists(string $key): bool
    {
        return $this->filesystem->fileExists($this->normalizeKey($key));
    }

    public function size(string $key): int
    {
        try {
            return $this->filesystem->fileSize($this->normalizeKey($key));
        } catch (UnableToRetrieveMetadata $exception) {
            throw new RuntimeException("Backup object not found: {$key}", 0, $exception);
        }
    }

    public function test(): void
    {
        try {
            $this->filesystem->write(self::PROBE_FILE, 'ok');
            $this->filesystem->delete(self::PROBE_FILE);
        } catch (UnableToWriteFile|UnableToDeleteFile $exception) {
            throw new RuntimeException('Backup storage is not writable.', 0, $exception);
        }
    }

    private function normalizeKey(string $key): string
    {
        $normalized = $this->normalizeRelativePath($key);
        if ($normalized === '') {
            throw new InvalidArgumentException('Invalid key.');
        }

        return $normalized;
    }

    private static function filesystem(BackupContainer $container): FilesystemOperator
    {
        $credentials = self::requireCredentials($container, $container->driver);
        $host = self::requireCredential($credentials, 'host', $container->driver);
        $username = self::requireCredential($credentials, 'username', $container->driver);
        $password = $credentials['password'] ?? '';

        if ($container->driver === 'sftp') {
            $provider = new SftpConnectionProvider(
                host: $host,
                username: $username,
                password: $password !== '' ? $password : null,
                privateKey: $credentials['private_key'] ?? null,
                passphrase: $credentials['passphrase'] ?? null,
                port: (int) ($credentials['port'] ?? 22),
            );
            $adapter = new SftpAdapter($provider, $container->location);

            return new Filesystem($adapter);
        }

        $adapter = new FtpAdapter(new FtpConnectionOptions(
            host: $host,
            root: $container->location,
            username: $username,
            password: $password,
            port: (int) ($credentials['port'] ?? 21),
            ssl: $container->driver === 'ftps',
        ));

        return new Filesystem($adapter);
    }

    private static function requireCredentials(BackupContainer $container, string $driver): array
    {
        $credentials = $container->credentials;
        if (! is_array($credentials) || $credentials === []) {
            throw new InvalidArgumentException("Credentials are required for {$driver} backup storage.");
        }

        return $credentials;
    }

    private static function requireCredential(array $credentials, string $key, string $driver): string
    {
        $value = $credentials[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing backup storage credential: {$key} ({$driver}).");
        }

        return $value;
    }
}

<?php

namespace App\Integrations\Storage;

use App\Models\BackupContainer;
use Aws\S3\S3Client;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

final class S3 extends AbstractStorage
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
        $credentials = self::requireCredentials($container, 's3');
        $accessKeyId = self::requireCredential($credentials, 'access_key_id', 's3');
        $secretAccessKey = self::requireCredential($credentials, 'secret_access_key', 's3');

        $config = [
            'version' => 'latest',
            'region' => $credentials['region'] ?? 'us-east-1',
            'credentials' => [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ],
        ];

        if (! empty($credentials['endpoint'])) {
            $config['endpoint'] = $credentials['endpoint'];
            $config['use_path_style_endpoint'] = (bool) ($credentials['use_path_style_endpoint'] ?? false);
        }

        $client = new S3Client($config);
        $adapter = new AwsS3V3Adapter($client, $container->location);

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

<?php

namespace Tests\Integration\Integrations\Storage;

use App\Integrations\Storage\Ftp;
use App\Integrations\Storage\S3;
use App\Models\BackupContainer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A remote backup container whose stored credentials are missing or incomplete is refused
 * before any connection is attempted, naming what is missing.
 */
class RemoteStorageCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    /** @return array<string, array{string, ?array<string, string>, string}> */
    public static function refusals(): array
    {
        return [
            's3 without credentials' => ['s3', null, 'Credentials are required for s3 backup storage.'],
            's3 with empty credentials' => ['s3', [], 'Credentials are required for s3 backup storage.'],
            's3 without a secret' => ['s3', ['access_key_id' => 'AKIDEXAMPLE'], 'Missing backup storage credential: secret_access_key (s3).'],
            's3 with an empty key id' => ['s3', ['access_key_id' => '', 'secret_access_key' => 's'], 'Missing backup storage credential: access_key_id (s3).'],
            'sftp without credentials' => ['sftp', null, 'Credentials are required for sftp backup storage.'],
            'sftp without a username' => ['sftp', ['host' => 'backup.example.test'], 'Missing backup storage credential: username (sftp).'],
        ];
    }

    /** @param ?array<string, string> $credentials */
    #[DataProvider('refusals')]
    public function test_incomplete_credentials_are_refused(string $driver, ?array $credentials, string $message): void
    {
        $container = new BackupContainer;
        $container->driver = $driver;
        $container->location = '/backups';
        $container->credentials = $credentials;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $driver === 's3' ? S3::fromContainer($container) : Ftp::fromContainer($container);
    }
}

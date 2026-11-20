<?php

namespace Tests\Integration\Backup;

use App\Lib\Backup\StorageProbe;
use App\Models\BackupContainer;
use RuntimeException;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A failing store test answers the storage's own reason, without the container's credentials.
 * The endpoints are closed loopback ports, refused at once.
 */
class StorageProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_an_unreachable_endpoint_is_named_with_the_bucket_and_the_drivers_error(): void
    {
        $message = $this->failure($this->s3('http://'.TestNetwork::LOOPBACK.':1'));

        $this->assertStringStartsWith('s3 http://'.TestNetwork::LOOPBACK.':1 my-bucket: Backup storage is not writable: ', $message);
        $this->assertStringContainsString('http://'.TestNetwork::LOOPBACK.':1/my-bucket/', $message);
        $this->assertStringContainsString('Failed to connect to '.TestNetwork::LOOPBACK, $message);
        $this->assertStringNotContainsString("\n", $message);
    }

    public function test_the_answer_masks_the_containers_credentials(): void
    {
        $message = $this->failure($this->s3('http://'.TestNetwork::LOOPBACK.':1/wJalrXUtnFEMI-K7MDENG'));

        $this->assertStringNotContainsString('wJalrXUtnFEMI-K7MDENG', $message);
        $this->assertStringStartsWith('s3 http://'.TestNetwork::LOOPBACK.':1/*** my-bucket: ', $message);
        $this->assertStringContainsString('http://'.TestNetwork::LOOPBACK.':1/***/my-bucket/', $message);
    }

    public function test_the_answer_masks_the_s3_access_key_id(): void
    {
        $message = $this->failure($this->s3('http://'.TestNetwork::LOOPBACK.':1/AKIAIOSFODNN7EXAMPLE'));

        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $message);
        $this->assertStringContainsString('***', $message);
    }

    public function test_a_secret_too_short_to_mask_leaves_the_answer_whole(): void
    {
        $message = $this->failure($this->s3('http://'.TestNetwork::LOOPBACK.':1', '1'));

        $this->assertStringContainsString('http://'.TestNetwork::LOOPBACK.':1/my-bucket/', $message);
        $this->assertStringNotContainsString('***', $message);
    }

    public function test_the_answer_starts_with_the_driver_host_and_location(): void
    {
        $container = new BackupContainer;
        $container->driver = 'sftp';
        $container->location = '/backups';
        $container->credentials = ['host' => TestNetwork::LOOPBACK, 'port' => 1, 'username' => 'backup', 'password' => 'correct-horse-battery'];

        $message = $this->failure($container);

        $this->assertStringStartsWith('sftp '.TestNetwork::LOOPBACK.':1 /backups: Backup storage is not writable: ', $message);
        $this->assertStringNotContainsString('correct-horse-battery', $message);
    }

    public function test_a_store_missing_a_credential_says_which(): void
    {
        $container = new BackupContainer;
        $container->driver = 'sftp';
        $container->location = '/backups';
        $container->credentials = ['username' => 'backup', 'password' => 'hunter2'];

        $this->assertSame('sftp /backups: Missing backup storage credential: host (sftp).', $this->failure($container));
    }

    private function s3(string $endpoint, string $secret = 'wJalrXUtnFEMI-K7MDENG'): BackupContainer
    {
        $container = new BackupContainer;
        $container->driver = 's3';
        $container->location = 'my-bucket';
        $container->credentials = [
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => $secret,
            'region' => 'eu-west-1',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
        ];

        return $container;
    }

    private function failure(BackupContainer $container): string
    {
        try {
            StorageProbe::check($container);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('the store test passed');
    }
}

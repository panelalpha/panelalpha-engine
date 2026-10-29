<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;

/**
 * remote-sync payload: destination_url is the target name with the source's
 * subdirectory and ?token=, and ip pins that name to the engine, whose
 * certificate for it does not exist yet (insecure_tls). force is off unless asked.
 */
class WordpressMigrationPayloadTest extends WordpressMigrationTestCase
{
    public function test_remote_sync_payload_includes_subdir_and_panelalpha_host(): void
    {
        $label = 'examplecom-a1b2.panelalpha.online';
        $sourceUrl = 'https://example.com/blog';
        $token = 'migrator-token-fixture';

        $destinationSiteUrl = SourceUrl::destinationSiteUrl($label, $sourceUrl);
        $this->assertSame('https://examplecom-a1b2.panelalpha.online/blog', $destinationSiteUrl);

        $payload = SourceWordpressClient::remoteSyncPayload($destinationSiteUrl, $token);

        $destinationUrl = $payload['destination_url'];
        $this->assertIsString($destinationUrl);

        $parts = parse_url($destinationUrl);
        $this->assertIsArray($parts);
        $this->assertSame('examplecom-a1b2.panelalpha.online', $parts['host'] ?? null);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.panelalpha\.online$/', (string) ($parts['host'] ?? ''));
        $this->assertSame('/blog', $parts['path'] ?? null);
        $this->assertSame('token=' . $token, $parts['query'] ?? null);

        $this->assertStringContainsString('wp-content/cache', $payload['exclusions']);
        $this->assertTrue($payload['backup_database']);
        $this->assertTrue($payload['backup_files']);
        $this->assertFalse($payload['force']);
    }

    public function test_remote_sync_payload_takes_over_the_sources_lock_only_when_asked(): void
    {
        $payload = SourceWordpressClient::remoteSyncPayload('https://example.com', 'tok', force: true);

        $this->assertTrue($payload['force']);
    }

    public function test_remote_sync_payload_root_install_has_no_path(): void
    {
        $label = 'examplecom-cdef.panelalpha.online';
        $destinationSiteUrl = SourceUrl::destinationSiteUrl($label, 'https://example.com');
        $payload = SourceWordpressClient::remoteSyncPayload($destinationSiteUrl, 'tok');

        $parts = parse_url($payload['destination_url']);
        $this->assertSame('examplecom-cdef.panelalpha.online', $parts['host'] ?? null);
        $path = $parts['path'] ?? null;
        $this->assertTrue($path === null || $path === '' || $path === '/');
    }

    public function test_remote_sync_payload_pins_the_kept_domain_to_the_engine_ip(): void
    {
        $token = str_repeat('ab', 32);
        $destinationSiteUrl = SourceUrl::destinationSiteUrl('example.com', 'https://www.example.com/blog');

        $payload = SourceWordpressClient::remoteSyncPayload($destinationSiteUrl, $token, destinationIp: self::engineIpv4());

        $this->assertSame('https://example.com/blog?token=' . $token, $payload['destination_url']);
        $this->assertSame(self::engineIpv4(), $payload['ip']);
        $this->assertTrue($payload['insecure_tls']);
    }

    public function test_remote_sync_payload_without_an_ip_sends_none(): void
    {
        $payload = SourceWordpressClient::remoteSyncPayload('https://example.com', 'tok');

        $this->assertArrayNotHasKey('ip', $payload);
        $this->assertArrayNotHasKey('insecure_tls', $payload);
    }
}

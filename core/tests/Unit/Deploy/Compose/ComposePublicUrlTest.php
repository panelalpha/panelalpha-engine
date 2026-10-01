<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use PHPUnit\Framework\TestCase;

/**
 * How a compose stack the engine did not write learns the account's address
 * (engine#192): under the engine's own names, by substitution, and in the
 * keys the author left blank for whoever deploys it.
 */
class ComposePublicUrlTest extends TestCase
{
    private const URL = 'https://shop.example.com';

    /**
     * @param array<string, mixed> $services
     * @return array<string, mixed>
     */
    private function fill(array $services, ?string $url = self::URL): array
    {
        return ComposePlaceholders::fill(['services' => $services], 'seed', $url);
    }

    public function test_every_service_but_a_datastore_is_told_the_address(): void
    {
        $result = $this->fill([
            'app' => ['image' => 'ghcr.io/acme/shop', 'environment' => ['MODE' => 'public']],
            'worker' => ['image' => 'ghcr.io/acme/shop', 'environment' => ['- ignored']],
            'db' => ['image' => 'postgres:16'],
        ]);
        $services = $result['compose']['services'];

        $this->assertSame(self::URL, $services['app']['environment']['PA_PUBLIC_URL']);
        $this->assertSame('shop.example.com', $services['app']['environment']['PA_PUBLIC_HOST']);
        $this->assertContains('PA_PUBLIC_URL=' . self::URL, $services['worker']['environment']);
        $this->assertArrayNotHasKey('environment', $services['db']);
    }

    public function test_a_service_that_sets_the_names_itself_keeps_its_values(): void
    {
        $result = $this->fill([
            'app' => ['image' => 'x', 'environment' => ['PA_PUBLIC_HOST=other.example.org']],
        ]);

        $environment = $result['compose']['services']['app']['environment'];
        $this->assertContains('PA_PUBLIC_HOST=other.example.org', $environment);
        $this->assertCount(1, preg_grep('/^PA_PUBLIC_HOST=/', $environment));
    }

    public function test_a_compose_file_can_hand_the_address_to_the_key_its_app_reads(): void
    {
        $result = $this->fill([
            'app' => ['image' => 'x', 'environment' => [
                'ORIGIN' => '${PA_PUBLIC_URL}',
                'LESMA_DOMAINS' => '${PA_PUBLIC_HOST:-localhost}',
                'CALLBACK' => '${PA_PUBLIC_URL}/auth/callback',
            ]],
        ]);

        $environment = $result['compose']['services']['app']['environment'];
        $this->assertSame(self::URL, $environment['ORIGIN']);
        $this->assertSame('shop.example.com', $environment['LESMA_DOMAINS']);
        $this->assertSame(self::URL . '/auth/callback', $environment['CALLBACK']);
        $this->assertContains('ORIGIN', $result['urls']);
    }

    /** cmintey/wishlist's shape: `ORIGIN=` set and empty kills adapter-node. */
    /**
     * Invio runs its backend on :3000 beside the frontend it publishes on
     * :8000; BACKEND_URL is a hop inside the container, not the site.
     */
    public function test_a_localhost_url_on_a_port_nothing_publishes_is_left_alone(): void
    {
        $result = $this->fill([
            'app' => ['image' => 'invio', 'ports' => ['8000:8000'], 'environment' => [
                'BACKEND_URL' => 'http://localhost:3000',
                'ORIGIN' => 'http://localhost:8000',
                'BASE_URL' => 'http://localhost',
            ]],
            'admin' => ['image' => 'x', 'ports' => [['target' => 9000, 'published' => '9090']], 'environment' => [
                'ADMIN_URL' => 'http://localhost:9090',
            ]],
        ]);
        $services = $result['compose']['services'];

        $this->assertSame('http://localhost:3000', $services['app']['environment']['BACKEND_URL']);
        $this->assertSame(self::URL, $services['app']['environment']['ORIGIN']);
        $this->assertSame(self::URL, $services['app']['environment']['BASE_URL']);
        $this->assertSame(self::URL, $services['admin']['environment']['ADMIN_URL']);
        $this->assertSame(['ORIGIN', 'BASE_URL', 'ADMIN_URL'], $result['urls']);
    }

    public function test_a_stack_that_publishes_nothing_is_rewritten_as_before(): void
    {
        $result = $this->fill(['app' => ['image' => 'x', 'environment' => ['APP_URL' => 'http://localhost:3000']]]);

        $this->assertSame(self::URL, $result['compose']['services']['app']['environment']['APP_URL']);
    }

    public function test_an_empty_public_url_key_is_filled(): void
    {
        $result = $this->fill([
            'app' => ['image' => 'x', 'environment' => ['ORIGIN=', 'APP_URL=', 'WEBHOOK_URL=', 'DATABASE_URL=', 'BASE_URL=']],
        ]);

        $environment = $result['compose']['services']['app']['environment'];
        $this->assertContains('ORIGIN=' . self::URL, $environment);
        $this->assertContains('APP_URL=' . self::URL, $environment);
        // A blank BASE_URL is a sub-path prefix meaning "root" in many apps.
        $this->assertContains('BASE_URL=', $environment);
        // Someone else's address, or a sidecar's: not ours to invent.
        $this->assertContains('WEBHOOK_URL=', $environment);
        $this->assertContains('DATABASE_URL=', $environment);
    }

    public function test_without_a_domain_nothing_changes(): void
    {
        $services = ['app' => ['image' => 'x', 'environment' => ['ORIGIN' => '']]];

        $this->assertSame($services, $this->fill($services, null)['compose']['services']);
    }
}

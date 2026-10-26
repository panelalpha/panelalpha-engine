<?php

namespace Tests\Unit\WordpressMigration;

use App\Console\Kernel;
use App\Models\Domain;
use App\Models\Ipv4NatMap;
use App\Models\Tunnel;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\DestinationProbe;
use App\System\Project\PhpHosting\WordPress\Migration\ImportedDomainSwitch;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\PreviewTunnel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Engine requests to an imported site by name go to this engine, and once
 * the kept domain resolves here and answers, the scheduled check requests
 * its certificate and removes the preview tunnel.
 */
class WordpressMigrationDnsSwitchTest extends ImportTargetTestCase
{
    /** @var list<string> */
    private array $certificates = [];

    /** @var list<string> */
    private array $removedTunnels = [];

    public function test_the_probe_pins_the_name_to_the_projects_bind_address(): void
    {
        $this->project('wpcom', 'wp.com');

        $options = (new DestinationProbe())->options('https://wp.com/blog/');

        $this->assertSame(['wp.com:80:' . self::ENGINE_IPV4, 'wp.com:443:' . self::ENGINE_IPV4], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($options['verify']);
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_behind_nat_the_probe_pins_the_local_address_the_webserver_binds(): void
    {
        Ipv4NatMap::upsertMap('10.0.0.5', self::ENGINE_IPV4);
        $this->project('wpcom', 'wp.com');

        $this->assertSame('10.0.0.5', DestinationProbe::engineAddress('wp.com'));
    }

    public function test_a_name_without_a_project_falls_back_to_the_engine_ipv4(): void
    {
        $this->assertSame(self::ENGINE_IPV4, DestinationProbe::engineAddress('unknown.example.com'));
    }

    public function test_only_completed_imports_on_their_own_domain_wait_for_dns(): void
    {
        $waiting = $this->completedImport('wp.com');
        $this->completedImport('wordpress-a1b2.panelalpha.online');
        $this->completedImport('done.example.com')->markDnsSwitched();
        WordpressMigration::start('https://running.example.com', 'admin', 'pw', MigrationTarget::newProject('running.example.com', null));

        $this->assertSame([$waiting->id], WordpressMigration::awaitingDnsSwitch()->pluck('id')->all());
    }

    public function test_a_domain_still_pointing_elsewhere_keeps_waiting(): void
    {
        $migration = $this->previewedImport();
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertFalse($this->switch(['198.51.100.99'])->check($migration));

        $migration->refresh();
        $this->assertNull($migration->dns_switched_at);
        $this->assertSame('wp-com.panelalpha.online', $migration->preview_hostname);
        $this->assertSame([], $this->certificates);
    }

    public function test_a_domain_that_does_not_answer_here_keeps_waiting(): void
    {
        $migration = $this->previewedImport();
        Http::fake(['*' => Http::failedConnection()]);

        $this->assertFalse($this->switch([self::ENGINE_IPV4])->check($migration));
        $this->assertSame([], $this->certificates);
    }

    public function test_a_domain_pointing_here_gets_its_certificate_and_loses_the_preview(): void
    {
        $migration = $this->previewedImport();
        Http::fake(['*' => Http::response('', 301)]);

        $this->assertTrue($this->switch([self::ENGINE_IPV4])->check($migration));

        $migration->refresh();
        $this->assertSame(['wp.com'], $this->certificates);
        $this->assertSame(['wp-com.panelalpha.online'], $this->removedTunnels);
        $this->assertNotNull($migration->dns_switched_at);
        $this->assertNull($migration->toStatusArray()['preview_url']);
        $this->assertSame([], WordpressMigration::awaitingDnsSwitch()->all());
        Http::assertSent(static fn ($request): bool => $request->url() === 'http://wp.com/');
    }

    public function test_a_refused_certificate_keeps_the_preview_and_the_wait(): void
    {
        $migration = $this->previewedImport();
        Http::fake(['*' => Http::response('', 200)]);
        $switch = new ImportedDomainSwitch(
            static fn (): array => [self::ENGINE_IPV4],
            requestCertificate: static fn () => throw new RuntimeException('urn:ietf:params:acme:error:connection'),
            preview: $this->preview(),
        );

        try {
            $switch->check($migration);
            $this->fail('Expected the authority refusal');
        } catch (RuntimeException) {
        }

        $migration->refresh();
        $this->assertNull($migration->dns_switched_at);
        $this->assertSame('wp-com.panelalpha.online', $migration->preview_hostname);
        $this->assertSame([], $this->removedTunnels);
    }

    public function test_the_command_switches_what_points_here_and_reports_the_rest(): void
    {
        $this->previewedImport();
        $this->completedImport('elsewhere.example.com');
        Http::fake(['*' => Http::response('', 200)]);
        $this->app->instance(ImportedDomainSwitch::class, new ImportedDomainSwitch(
            static fn (string $host): array => $host === 'wp.com' ? [self::ENGINE_IPV4] : ['198.51.100.99'],
            requestCertificate: function (Domain $domain): void {
                $this->certificates[] = (string) $domain->domain;
            },
            preview: $this->preview(),
        ));

        $exit = Artisan::call('wordpress:migrate:dns-switch');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 switched, 0 failed', Artisan::output());
        $this->assertSame(['wp.com'], $this->certificates);
    }

    public function test_the_check_is_scheduled_every_fifteen_minutes(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $events = array_filter(
            $kernel->resolveConsoleSchedule()->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'wordpress:migrate:dns-switch'),
        );

        $this->assertCount(1, $events);
        $event = array_values($events)[0];
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function completedImport(string $domain): WordpressMigration
    {
        $migration = WordpressMigration::start('https://' . $domain, 'admin', 'pw', MigrationTarget::newProject($domain, null));
        $migration->status = WordpressMigration::STATUS_COMPLETED;
        $migration->save();

        return $migration;
    }

    private function previewedImport(): WordpressMigration
    {
        $domain = $this->project('wpcom', 'wp.com');
        Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $domain->user_id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'wp-com.panelalpha.online',
            'details' => ['purpose' => Tunnel::PURPOSE_PREVIEW],
        ]);
        $migration = $this->completedImport('wp.com');
        $migration->storePreviewHostname('wp-com.panelalpha.online');

        return $migration;
    }

    /**
     * @param list<string> $resolved
     */
    private function switch(array $resolved): ImportedDomainSwitch
    {
        return new ImportedDomainSwitch(
            static fn (): array => $resolved,
            requestCertificate: function (Domain $domain): void {
                $this->certificates[] = (string) $domain->domain;
            },
            preview: $this->preview(),
        );
    }

    private function preview(): PreviewTunnel
    {
        return new PreviewTunnel(remove: function (User $user, Tunnel $tunnel): void {
            $this->removedTunnels[] = (string) $tunnel->hostname;
            $tunnel->delete();
        });
    }
}

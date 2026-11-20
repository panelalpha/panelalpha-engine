<?php

namespace Tests\Integration\Console;

use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Domains\HttpsPorts;
use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Lib\Project\PublicRouting\PublicPortRules;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * `system:public-routing:allow` and `:list`, and the refusals of `:deny`: what each grants or takes, and what it
 * prints of the ports and the server's name.
 */
class PublicRoutingAllowListCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const HOST = 'host.example.test';

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->storage = sys_get_temp_dir().'/pa-public-routing-'.bin2hex(random_bytes(4));
        mkdir($this->storage.'/logs/deploy', 0777, true);
        $this->app->useStoragePath($this->storage);
        Setting::setRuntimeSettings(['cert_domain' => self::HOST]);
        $this->app->instance(System::class, new RecordingSystem);
        $user = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.test');
        $this->makeMainDomain($user, 'shop.example.test');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        exec('rm -rf '.escapeshellarg($this->storage));
        parent::tearDown();
    }

    /** @return array{int, string} */
    private function run_(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    /** @param list<array{listen: int, to: int, transport: string}> $ports */
    private function declare(string $username, array $ports, string $domain = 'generated'): void
    {
        $user = User::findByUsername($username);
        $user->setDetails([ProjectPublicRouting::DETAIL => ['declared' => ['domain' => $domain, 'ports' => $ports]]]);
        $user->save();
    }

    public function test_allow_refuses_an_unknown_project_and_a_call_naming_nothing(): void
    {
        $this->assertSame([1, "There is no project 'nobody' on this engine.\n"], $this->run_('system:public-routing:allow', ['project' => 'nobody']));
        $this->assertSame([1, "Name what to allow: --ports, --host-domain, or both.\n"], $this->run_('system:public-routing:allow', ['project' => 'shop']));
        $this->assertSame([], (new PublicRoutingGrants)->of('shop'));
    }

    public function test_allowing_ports_opens_what_the_last_deploy_declared_and_says_what_was_refused(): void
    {
        $this->declare('shop', [['listen' => 2222, 'to' => 22, 'transport' => 'tcp'], ['listen' => 80, 'to' => 8080, 'transport' => 'tcp']]);
        $rules = Mockery::mock(PublicPortRules::class);
        $rules->shouldReceive('apply')->once()->andReturn([
            ['listen' => 2222, 'to' => 22, 'transport' => 'tcp', 'state' => PublicPortRules::OPEN],
            ['listen' => 80, 'to' => 8080, 'transport' => 'tcp', 'state' => PublicPortRules::REFUSED, 'reason' => 'port 80 is the webserver\'s'],
            ['listen' => 81, 'to' => 8081, 'transport' => 'udp', 'state' => PublicPortRules::NOT_ALLOWED],
        ]);
        $this->app->instance(PublicPortRules::class, $rules);

        [$code, $out] = $this->run_('system:public-routing:allow', ['project' => 'shop', '--ports' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(
            "Allowed shop: public ports.\n"
            ."tcp 2222 → 22: open\n"
            ."tcp 80 → 8080: not opened, port 80 is the webserver's\n"
            ."udp 81 → 8081: not opened, not_allowed\n",
            $out
        );
        $this->assertSame([PublicRoutingGrants::PORTS], (new PublicRoutingGrants)->of('shop'));
    }

    public function test_allowing_both_with_nothing_declared_says_the_next_deploy_applies_them(): void
    {
        [$code, $out] = $this->run_('system:public-routing:allow', ['project' => 'shop', '--ports' => true, '--host-domain' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(
            "Allowed shop: public ports and the server's own name.\n"
            ."Its last deploy declared no public ports; the next one opens what its panelalpha.yaml lists.\n"
            .'Its next deploy moves it to '.self::HOST." if its panelalpha.yaml says domain: host.\n",
            $out
        );
    }

    public function test_allowing_the_servers_name_on_an_engine_without_one_warns(): void
    {
        Setting::setRuntimeSettings(['cert_domain' => '']);

        [$code, $out] = $this->run_('system:public-routing:allow', ['project' => 'shop', '--host-domain' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(
            "Allowed shop: the server's own name.\n"
            ."This engine has no name of its own (cert_domain is not set), so domain: host has nothing to take.\n",
            $out
        );
    }

    public function test_the_listing_shows_the_projects_allowed_or_asking_and_skips_the_rest(): void
    {
        $this->makeUser('blog', ['template' => 'dind']);
        $this->makeUser('quiet', ['template' => 'dind']);
        (new PublicRoutingGrants)->allow('shop', PublicRoutingGrants::KINDS);
        $shop = User::findByUsername('shop');
        $shop->setDetails([ProjectPublicRouting::DETAIL => [
            'declared' => ['domain' => 'host', 'ports' => [['listen' => 2222, 'to' => 22, 'transport' => 'tcp']]],
            'domain' => ['name' => self::HOST, 'state' => 'host'],
            'ports' => [['listen' => 2222, 'to' => 22, 'transport' => 'tcp', 'state' => 'open']],
        ]]);
        $shop->save();
        $this->declare('blog', [['listen' => 25, 'to' => 25, 'transport' => 'tcp']]);

        [$code, $out] = $this->run_('system:public-routing:list');

        $this->assertSame(0, $code);
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => str_starts_with($line, '|')));
        $this->assertCount(3, $lines);
        $this->assertMatchesRegularExpression('/\| Project\s+\| Allowed\s+\| Domain\s+\| Public ports\s+\|/', $lines[0]);
        $this->assertMatchesRegularExpression('/\| blog\s+\| nothing\s+\| -\s+\| -\s+\|/', $lines[1]);
        $this->assertMatchesRegularExpression(
            '/\| shop\s+\| public ports and the server\'s own name \| '.preg_quote(self::HOST, '/').' \(host\) \| tcp 2222→22 open \|/',
            $lines[2]
        );
        $this->assertStringNotContainsString('quiet', $out);
    }

    public function test_an_empty_listing_says_so(): void
    {
        $this->assertSame([0, "No project is allowed public routing or asks for it.\n"], $this->run_('system:public-routing:list'));
    }

    public function test_deny_refuses_an_unknown_project(): void
    {
        $this->assertSame([1, "There is no project 'nobody' on this engine.\n"], $this->run_('system:public-routing:deny', ['project' => 'nobody']));
    }

    public function test_deny_waits_for_a_deploy_that_holds_the_project(): void
    {
        (new PublicRoutingGrants)->allow('shop', PublicRoutingGrants::KINDS);
        $lock = DeployLock::acquireFor('shop');

        try {
            [$code, $out] = $this->run_('system:public-routing:deny', ['project' => 'shop']);
        } finally {
            $lock->release();
        }

        $this->assertSame(1, $code);
        $this->assertStringEndsWith(" Run this again once it has finished.\n", $out);
        $this->assertSame(PublicRoutingGrants::KINDS, (new PublicRoutingGrants)->of('shop'));
    }

    public function test_denying_ports_puts_https_back_on_443_and_asks_for_a_redeploy(): void
    {
        (new PublicRoutingGrants)->allow('shop', [PublicRoutingGrants::PORTS]);
        $domain = Domain::findByName('shop.example.test');
        $domain->setDetails([HttpsPorts::PORTS_KEY => [443, 8443]]);
        $domain->save();
        $change = Mockery::mock(HttpsPortsChange::class);
        $change->shouldReceive('apply')->once()->andReturnUsing(static function (Domain $domain, array $ports): void {
            $domain->setDetails([HttpsPorts::PORTS_KEY => $ports]);
            $domain->save();
        });
        $this->app->instance(HttpsPortsChange::class, $change);

        [$code, $out] = $this->run_('system:public-routing:deny', ['project' => 'shop', '--ports' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(
            "shop is now allowed nothing.\n"
            ."HTTPS is back on 443 alone for shop.example.test; redeploy the project so the application uses that address.\n",
            $out
        );
        $this->assertSame([443], HttpsPorts::of(Domain::findByName('shop.example.test')));
    }
}

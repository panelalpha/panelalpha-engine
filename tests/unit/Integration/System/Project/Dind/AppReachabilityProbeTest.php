<?php

namespace Tests\Integration\System\Project\Dind;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppReachability;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * check() end to end: the app asked in its container, the edge asked on the host at the
 * address the vhost listens on, and the two fingerprints compared.
 */
class AppReachabilityProbeTest extends TestCase
{
    use InMemoryDatabase;

    private const PAGE = 'a3f1c2d4e5b6a7980123456789abcdef0123456789abcdef0123456789abcdef';

    private const OTHER = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    /** @var list<list<string>> host requests, as the broker gets them */
    private array $edge = [];

    /** @var list<string> scripts run in the account's container */
    private array $inAccount = [];

    private string $edgeAnswers = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
        Setting::set('default_ipv4', TestNetwork::public(7));
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_domain_serving_the_apps_own_page_is_reachable(): void
    {
        $this->edgeAnswers = "200\t" . self::PAGE . "\tno\t\t\thttps";

        $result = $this->reachability('shop.example.com')->check(self::answering(), 4);

        $this->assertSame(AppReachability::OK, $result['verdict']);
        $this->assertSame('shop.example.com', $result['domain']);
        $this->assertSame('https', $result['scheme']);
        $this->assertCount(1, $this->inAccount);
        $this->assertStringContainsString("'http://".TestNetwork::LOOPBACK.":8080/'", $this->inAccount[0]);
        $this->assertStringContainsString('-m 4', $this->inAccount[0]);
        $this->assertSame([['shop.example.com', TestNetwork::public(7), '4', 'https']], $this->edge);
        $this->assertStringStartsWith('Reachable: https://shop.example.com/ answered', AppReachability::describe($result));
    }

    public function test_a_domain_serving_another_page_differs(): void
    {
        $this->edgeAnswers = "200\t" . self::OTHER . "\tno\t\t\thttp";

        $result = $this->reachability('shop.example.com', ssl: false)->check(self::answering());

        $this->assertSame(AppReachability::DIFFERS, $result['verdict']);
        $this->assertSame([['shop.example.com', TestNetwork::public(7), '5', '']], $this->edge);
        $this->assertStringStartsWith('Not reachable: http://shop.example.com/', AppReachability::describe($result));
    }

    public function test_nothing_is_compared_when_no_port_served_a_page_or_there_is_no_domain(): void
    {
        $failing = [['port' => 8080, 'scheme' => 'http', 'status' => AppHealth::STATUS_FAIL, 'http_code' => 502]];

        $unserved = $this->reachability('shop.example.com')->check($failing);
        $nameless = $this->reachability(null)->check(self::answering());

        $this->assertSame(
            ['verdict' => AppReachability::SKIPPED, 'domain' => 'shop.example.com', 'http_code' => null, 'detail' => 'no port served a page, so there is nothing to compare'],
            $unserved
        );
        $this->assertSame('Reachability check skipped: no port served a page, so there is nothing to compare', AppReachability::describe($unserved));
        $this->assertSame(AppReachability::SKIPPED, $nameless['verdict']);
        $this->assertSame('the project has no domain yet', $nameless['detail']);
        $this->assertSame([], $this->inAccount);
        $this->assertSame([], $this->edge);
    }

    public function test_a_check_that_cannot_run_is_skipped_with_its_reason(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getMainDomain')->willThrowException(new \RuntimeException("database gone\nat line 3"));
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);

        $result = (new AppReachability($dind))->check(self::answering());

        $this->assertSame(AppReachability::SKIPPED, $result['verdict']);
        $this->assertSame('the check could not run: database gone', $result['detail']);
    }

    /** @return list<array<string, mixed>> */
    private static function answering(): array
    {
        return [
            ['port' => 3000, 'scheme' => 'http', 'status' => AppHealth::STATUS_FAIL, 'http_code' => 502],
            ['port' => 8080, 'scheme' => 'http', 'status' => AppHealth::STATUS_OK, 'http_code' => 200],
        ];
    }

    private function reachability(?string $domain, bool $ssl = true): AppReachability
    {
        $main = null;
        if ($domain !== null) {
            $main = new Domain();
            $main->domain = $domain;
            $main->details = $ssl ? [] : ['ssl_disabled' => true];
        }
        $user = $this->createStub(User::class);
        $user->method('getMainDomain')->willReturn($main);

        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $argv = (array) $cmd;
            if (LocalRoot::action($argv) === 'host.edgeProbe') {
                $this->edge[] = LocalRoot::values($argv);

                return $this->edgeAnswers;
            }
            $this->inAccount[] = implode(' ', $argv);

            return "200\t" . self::PAGE . "\tno\t" . self::PAGE;
        });

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($user);
        $dind->method('username')->willReturn('acme');
        $dind->method('homeDirPath')->willReturn('/home/acme');
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(static fn (): ShellOperations => new ShellOperations($dind));

        return new AppReachability($dind);
    }
}

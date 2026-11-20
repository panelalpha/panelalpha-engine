<?php

namespace Tests\Unit\Support;

use App\Support\DockerHubLogin;
use App\System;
use App\System\Privileged\Root;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * What the running registry-proxy and the host daemon say about the Docker Hub
 * login, and the answers from Hub that are neither a yes nor a refusal.
 */
class DockerHubLoginProxyStateTest extends TestCase
{
    /** @var list<array<int, string>> */
    private array $asked = [];

    public function test_the_running_proxys_login_is_read_from_its_environment(): void
    {
        $login = $this->onHost("PATH=/usr/bin\r\nREGISTRY_PROXY_USERNAME=me\nREGISTRY_PROXY_PASSWORD=dckr_pat_a=b\nEMPTY");

        $this->assertSame(['username' => 'me', 'token' => 'dckr_pat_a=b'], $login->current());
        $this->assertSame('docker.runningEnvironment', Root::askedAction($this->asked[0]));
        $this->assertSame([DockerHubLogin::CONTAINER], Root::askedValues($this->asked[0]));
    }

    public function test_a_proxy_with_no_login_set_pulls_anonymously(): void
    {
        $this->assertSame(['username' => '', 'token' => ''], $this->onHost("PATH=/usr/bin\n")->current());
    }

    public function test_a_proxy_that_is_not_running_has_no_login(): void
    {
        $this->assertNull($this->onHost("  \n")->current());
        $this->assertNull($this->failingHost()->current());
    }

    public function test_the_host_daemon_mirrors_hub_only_through_the_proxys_port(): void
    {
        $through = $this->onHost('[http://'.TestNetwork::LOOPBACK.':5001/]');

        $this->assertTrue($through->hostMirrors());
        $this->assertSame('docker.registryMirrors', Root::askedAction($this->asked[0]));
        $this->assertFalse($this->onHost('[https://mirror.example.test/]')->hostMirrors());
        $this->assertNull($this->failingHost()->hostMirrors());
    }

    public function test_a_login_that_could_break_the_env_file_is_never_saved(): void
    {
        $login = $this->onHost('');

        try {
            $login->save('Not Valid', 'dckr_pat_abcdefgh');
            $this->fail('a username with spaces must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Docker Hub username', $e->getMessage());
        }
        try {
            $login->save('me', 'short');
            $this->fail('a token under eight characters must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('access token', $e->getMessage());
        }

        $this->assertSame([], $this->asked);
    }

    public function test_an_unexpected_hub_status_is_named(): void
    {
        $check = $this->answering(['GET' => ['status' => 503, 'body' => 'busy']])->check('me', 'dckr_pat_abcdefgh');

        $this->assertSame(
            ['ok' => false, 'error' => 'Docker Hub answered HTTP 503.', 'limit' => null, 'remaining' => null, 'source' => null],
            $check
        );
    }

    public function test_a_200_without_a_bearer_token_is_not_accepted(): void
    {
        $check = $this->answering(['GET' => ['status' => 200, 'body' => '{"nope":1}']])->check('me', 'dckr_pat_abcdefgh');

        $this->assertFalse($check['ok']);
        $this->assertSame('Docker Hub answered HTTP 200.', $check['error']);
    }

    /** @return array<string, array{string, ?string}> */
    public static function windows(): array
    {
        return [
            'six hours' => ['100;w=21600', '100 per 6 hours'],
            'other window' => ['50;w=60', '50 per 60s'],
            'no window' => ['40', '40'],
            'unreadable' => ['unlimited', null],
        ];
    }

    #[DataProvider('windows')]
    public function test_the_limit_is_told_in_its_own_window(string $header, ?string $limit): void
    {
        $check = $this->answering([
            'GET' => ['status' => 200, 'body' => '{"token":"t"}'],
            'HEAD' => ['status' => 200, 'headers' => ['ratelimit-limit' => $header]],
        ])->check('me', 'dckr_pat_abcdefgh');

        $this->assertTrue($check['ok']);
        $this->assertSame($limit, $check['limit']);
        $this->assertNull($check['remaining']);
        $this->assertNull($check['source']);
    }

    public function test_a_repository_list_hub_will_not_give_is_not_knowing(): void
    {
        $login = $this->answering([
            'POST' => ['status' => 200, 'body' => '{"token":"hub"}'],
            'GET' => ['status' => 403, 'body' => '{"detail":"forbidden"}'],
        ]);

        $this->assertNull($login->pullablePrivateRepositories('me', 'dckr_pat_abcdefgh'));
    }

    private function onHost(string $output): DockerHubLogin
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (array $cmd) use ($output): string {
            $this->asked[] = $cmd;

            return $output;
        });

        return new DockerHubLogin($system, static fn (): ?array => null);
    }

    private function failingHost(): DockerHubLogin
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willThrowException(new \Exception('No such container: panelalpha-registry-proxy'));

        return new DockerHubLogin($system, static fn (): ?array => null);
    }

    /** @param array<string, array{status: int, headers?: array<string, string>, body?: string}> $byMethod */
    private function answering(array $byMethod): DockerHubLogin
    {
        return new DockerHubLogin(
            $this->createStub(System::class),
            static fn (string $method): ?array => isset($byMethod[$method])
                ? $byMethod[$method] + ['headers' => [], 'body' => '']
                : null
        );
    }
}

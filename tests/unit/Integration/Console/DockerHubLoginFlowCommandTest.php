<?php

namespace Tests\Integration\Console;

use App\Console\Commands\DockerHub\DockerHubLoginCommand;
use App\Console\Commands\DockerHub\DockerHubLogoutCommand;
use App\Support\DockerHubLogin;
use App\System;
use App\System\Privileged;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** `pae docker-hub:login --token-stdin` and `pae docker-hub:logout` against a stand-in Docker Hub and registry-proxy. */
class DockerHubLoginFlowCommandTest extends TestCase
{
    public const TOKEN = 'dckr_pat_abcdefgh';

    private string $dir;

    /** @var array<string, ?array{status: int, headers: array<string, string>, body: string}> url prefix => answer */
    private array $hub = [];

    /** The username registry-proxy runs with after the save; null when it is not running. */
    private ?string $runningAs = 'me';

    private string $mirrors = '{"registry-mirrors":["http://'.TestNetwork::LOOPBACK.':5001"]}';

    private bool $composeFails = false;

    /** @var list<string> */
    private array $recreated = [];

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-hub-login-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/.env', "APP_ENV=production\n");
        $this->hub = [
            'https://auth.docker.io/token?service=registry.docker.io&scope=repository:ratelimitpreview' => self::answer(200, ['token' => 'bearer']),
            'https://registry-1.docker.io/' => ['status' => 200, 'headers' => ['ratelimit-limit' => '200;w=21600'], 'body' => ''],
            'https://hub.docker.com/v2/users/login' => self::answer(401, []),
        ];
        $this->app->instance(DockerHubLogin::class, new DockerHubLogin($this->system(), $this->http(...)));
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter(glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function answer(int $status, array $json): array
    {
        return ['status' => $status, 'headers' => [], 'body' => (string) json_encode($json)];
    }

    /** @return ?array{status: int, headers: array<string, string>, body: string} */
    private function http(string $method, string $url, array $headers, ?string $body): ?array
    {
        foreach ($this->hub as $prefix => $answer) {
            if (str_starts_with($url, $prefix)) {
                return $answer;
            }
        }

        return null;
    }

    private function system(): System
    {
        $test = $this;

        return new class($test, $this->dir) extends System
        {
            public function __construct(private readonly DockerHubLoginFlowCommandTest $test, private readonly string $dir) {}

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->test->registryProxyAnswers($cmd);
            }
        };
    }

    /** @param string|list<string> $cmd */
    public function registryProxyAnswers(string|array $cmd): string
    {
        if ($cmd === Privileged::docker()->runningEnvironment(DockerHubLogin::CONTAINER)) {
            return $this->runningAs === null ? '' : DockerHubLogin::ENV_USERNAME."={$this->runningAs}\n".DockerHubLogin::ENV_PASSWORD."=x\n";
        }
        if ($cmd === Privileged::docker()->registryMirrors()) {
            return $this->mirrors;
        }
        if ($cmd === Privileged::engineCompose($this->dir)->upWithoutDependencies('registry-proxy')) {
            if ($this->composeFails) {
                throw new RuntimeException("registry-proxy did not start\n");
            }
            $this->recreated[] = (string) file_get_contents($this->dir.'/.env');

            return '';
        }

        throw new RuntimeException('unexpected command');
    }

    /** @param class-string<Command> $class */
    private function command(string $class, array $input = []): int
    {
        $command = $class === DockerHubLoginCommand::class
            ? new class extends DockerHubLoginCommand
            {
                protected function tokenFromStdin(): string
                {
                    return DockerHubLoginFlowCommandTest::TOKEN."\n";
                }
            }
            : new DockerHubLogoutCommand();
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($input), $output);
        $this->printed = $output->fetch();

        return $code;
    }

    private function login(array $input = []): int
    {
        return $this->command(DockerHubLoginCommand::class, ['--username' => 'me', '--token-stdin' => true] + $input);
    }

    private function privateRepositories(): void
    {
        $access = rtrim(strtr(base64_encode((string) json_encode(['access' => [['name' => 'me/secret', 'actions' => ['pull']]]])), '+/', '-_'), '=');
        $this->hub = [
            'https://hub.docker.com/v2/users/login' => self::answer(200, ['token' => 'jwt']),
            'https://hub.docker.com/v2/namespaces/me/repositories' => self::answer(200, ['results' => [
                ['name' => 'secret', 'is_private' => true],
                ['name' => 'open', 'is_private' => false],
            ]]),
            'https://auth.docker.io/token?service=registry.docker.io&scope=repository:me/secret' => self::answer(200, ['token' => "h.{$access}.s"]),
        ] + $this->hub;
    }

    public function test_a_login_docker_hub_cannot_be_reached_for_is_not_saved(): void
    {
        $this->hub = [];

        $this->assertSame(1, $this->login());

        $this->assertStringContainsString('Docker Hub could not be reached from this server.', $this->printed);
        $this->assertSame([], $this->recreated);
    }

    public function test_an_accepted_public_token_is_saved_and_registry_proxy_recreated_with_it(): void
    {
        $this->assertSame(0, $this->login());

        $this->assertStringContainsString('Docker Hub accepts it: me, 200 per 6 hours', $this->printed);
        $this->assertStringContainsString('Could not check whether this token reaches private repositories.', $this->printed);
        $this->assertStringContainsString('registry-proxy now pulls from Docker Hub as me.', $this->printed);
        $this->assertStringNotContainsString('does not mirror through it yet', $this->printed);
        $this->assertSame(["APP_ENV=production\nREGISTRY_PROXY_USERNAME=me\nREGISTRY_PROXY_PASSWORD=".self::TOKEN."\n"], $this->recreated);
    }

    public function test_a_host_daemon_that_does_not_mirror_is_pointed_at_the_script_that_sets_it_up(): void
    {
        $this->mirrors = '{}';

        $this->assertSame(0, $this->login());

        $this->assertStringContainsString('`bash scripts/install-sysbox.sh` sets that up.', $this->printed);
    }

    public function test_a_token_that_reaches_private_repositories_is_not_saved_unless_allowed(): void
    {
        $this->privateRepositories();

        $this->assertSame(1, $this->login());

        $this->assertStringContainsString('This token can pull private repositories', $this->printed);
        $this->assertStringContainsString('  me/secret', $this->printed);
        $this->assertStringNotContainsString('me/open', $this->printed);
        $this->assertStringContainsString('Not saved. Create a token with the Public Repo Read-only scope, or pass --allow-private.', $this->printed);
        $this->assertSame([], $this->recreated);

        $this->assertSame(0, $this->login(['--allow-private' => true]));
        $this->assertCount(1, $this->recreated);
    }

    public function test_a_save_that_fails_says_why(): void
    {
        $this->composeFails = true;

        $this->assertSame(1, $this->login());

        $this->assertStringContainsString('Could not save it: registry-proxy did not start', $this->printed);
    }

    public function test_a_proxy_still_running_with_another_login_is_reported(): void
    {
        $this->runningAs = 'someone-else';

        $this->assertSame(1, $this->login());

        $this->assertStringContainsString('Saved to .env, but registry-proxy is not running with it.', $this->printed);
    }

    public function test_logout_takes_the_proxy_back_to_anonymous_pulls(): void
    {
        $this->runningAs = '';

        $this->assertSame(0, $this->command(DockerHubLogoutCommand::class));

        $this->assertStringContainsString('registry-proxy pulls from Docker Hub anonymously again.', $this->printed);
        $this->assertSame(["APP_ENV=production\nREGISTRY_PROXY_USERNAME=\nREGISTRY_PROXY_PASSWORD=\n"], $this->recreated);
    }

    public function test_logout_with_the_proxy_down_says_it_could_not_be_confirmed(): void
    {
        $this->runningAs = null;

        $this->assertSame(0, $this->command(DockerHubLogoutCommand::class));

        $this->assertStringContainsString('Removed from .env, but registry-proxy is not running to confirm it.', $this->printed);
    }

    public function test_a_failed_logout_says_why(): void
    {
        $this->composeFails = true;

        $this->assertSame(1, $this->command(DockerHubLogoutCommand::class));

        $this->assertStringContainsString('Could not remove it: registry-proxy did not start', $this->printed);
    }
}

<?php

namespace Tests\Integration\Console;

use App\Support\DockerHubLogin;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * `pae docker-hub:status` reads registry-proxy's login and the host daemon's mirrors through a stand-in root broker;
 * `pae docker-hub:login` refuses what it cannot use before it asks anyone.
 */
class DockerHubCommandsTest extends TestCase
{
    private string $dir;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-dockerhub-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $client = $this->dir . '/pa-root';
        file_put_contents($client, self::broker($this->dir));
        chmod($client, 0755);
        LocalRoot::answerWith($client);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** A pa-root that answers `docker.<action>` with `<action>.out` when the test wrote one, and fails otherwise. */
    private static function broker(string $dir): string
    {
        $d = escapeshellarg($dir);

        return "#!/bin/sh\n"
            . "printf '%s\\n' \"\$*\" >> {$d}/asked\n"
            . "for action in runningEnvironment registryMirrors upWithoutDependencies; do\n"
            . "  case \" \$* \" in *\".\$action \"*) [ -f {$d}/\$action.out ] && { cat {$d}/\$action.out; exit 0; } ;; esac\n"
            . "done\n"
            . "echo 'Error response from daemon: No such container' >&2\nexit 1\n";
    }

    private function answer(string $action, string $output): void
    {
        file_put_contents("{$this->dir}/{$action}.out", $output);
    }

    private function setEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    /** Every HTTPS request goes to a loopback port nothing listens on, so Docker Hub is never reached. */
    private function cutOffDockerHub(): void
    {
        $server = stream_socket_server('tcp://' . TestNetwork::LOOPBACK . ':0');
        $address = stream_socket_get_name($server, false);
        fclose($server);
        $this->setEnv('HTTPS_PROXY', 'http://' . $address);
        $this->setEnv('NO_PROXY', '');
        $this->setEnv('no_proxy', '');
    }

    /** @param array<string, mixed> $args */
    private function console(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    public function test_status_without_registry_proxy_running(): void
    {
        [$code, $out] = $this->console('docker-hub:status');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('registry-proxy is not running: account pulls go straight to Docker Hub, anonymously.', $out);
        $this->assertStringContainsString('Host daemon: could not be asked', $out);
        $this->assertStringContainsString('docker.runningEnvironment -- ' . DockerHubLogin::CONTAINER, (string) file_get_contents($this->dir . '/asked'));
    }

    public function test_status_of_an_anonymous_proxy_behind_the_host_mirror(): void
    {
        $this->answer('runningEnvironment', "PATH=/usr/bin\n" . DockerHubLogin::ENV_USERNAME . "=\n" . DockerHubLogin::ENV_PASSWORD . "=\n");
        $this->answer('registryMirrors', '["http://' . TestNetwork::LOOPBACK . ":5001/\"]\n");

        [$code, $out] = $this->console('docker-hub:status');

        $this->assertSame(0, $code);
        $this->assertStringContainsString("registry-proxy pulls anonymously: the host IP's limit, shared with everything behind it.", $out);
        $this->assertStringContainsString('  pae docker-hub:login', $out);
        $this->assertStringContainsString('Host daemon: mirrors Docker Hub through registry-proxy', $out);
    }

    public function test_status_of_a_host_daemon_that_pulls_directly(): void
    {
        $this->answer('runningEnvironment', DockerHubLogin::ENV_USERNAME . "=\n");
        $this->answer('registryMirrors', "[]\n");

        [, $out] = $this->console('docker-hub:status');

        $this->assertStringContainsString('Host daemon: pulls Docker Hub directly (bash scripts/install-sysbox.sh sets up the mirror)', $out);
    }

    public function test_status_of_a_login_docker_hub_cannot_be_asked_about(): void
    {
        $this->cutOffDockerHub();
        $this->answer('runningEnvironment', DockerHubLogin::ENV_USERNAME . "=puller\n" . DockerHubLogin::ENV_PASSWORD . "=dckr_pat_abcdefgh\n");

        [$code, $out] = $this->console('docker-hub:status');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('registry-proxy pulls as puller: Docker Hub could not be reached from this server.', $out);
    }

    public function test_login_without_a_username_is_refused_with_the_scripted_form(): void
    {
        [$code, $out] = $this->console('docker-hub:login', ['--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('A Docker Hub username: lowercase letters, digits, dots, dashes and underscores.', $out);
        $this->assertStringContainsString('echo "$TOKEN" | pae docker-hub:login --username=<name> --token-stdin', $out);
        $this->assertFileDoesNotExist($this->dir . '/asked');
    }

    public function test_login_without_a_token_is_refused(): void
    {
        [$code, $out] = $this->console('docker-hub:login', ['--username' => 'puller', '--no-interaction' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('A Docker Hub access token (dckr_pat_…) or password without spaces or quotes.', $out);
        $this->assertFileDoesNotExist($this->dir . '/asked');
    }
}

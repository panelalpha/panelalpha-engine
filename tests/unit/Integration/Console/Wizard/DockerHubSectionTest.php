<?php

namespace Tests\Integration\Console\Wizard;

use App\Console\Wizard\Sections\DockerHubSection;
use App\Support\DockerHubLogin;
use App\System;
use Laravel\Prompts\Key;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\LocalRoot;
use Tests\Support\NoProcessHost;
use Tests\Support\TestNetwork;
use Tests\Support\WizardScreen;
use Tests\TestCase;

/** `pae configure docker-hub`: the login registry-proxy pulls with, checked against a faked Docker Hub. */
class DockerHubSectionTest extends TestCase
{
    use WizardScreen;

    private const TOKEN = 'dckr_pat_abcdefgh';

    /** @var array{env: ?string, mirrors: ?string, up: bool} */
    private array $proxy = ['env' => null, 'mirrors' => null, 'up' => true];

    /** @var list<string> */
    private array $asked = [];

    /** @var array{auth: int, private: ?list<string>} */
    private array $account = ['auth' => 200, 'private' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startWizardScreen();
    }

    protected function tearDown(): void
    {
        $this->stopWizardScreen();
        parent::tearDown();
    }

    public function test_a_proxy_that_is_not_running_is_said_so(): void
    {
        $this->keys([...self::row(1), Key::CTRL_E, Key::ENTER]);

        $this->assertSame(0, $this->section()->run(false));
        $this->assertScreenShows('registry-proxy: not running — accounts pull straight from Docker Hub');
        $this->assertScreenShows('Host daemon: could not be asked');
        $this->assertScreenDoesNotShow('Remove it — pull anonymously');
        $this->assertSame(['docker.runningEnvironment', 'docker.registryMirrors', 'docker.runningEnvironment', 'docker.registryMirrors'], $this->asked);
    }

    public function test_a_login_is_checked_then_saved_and_the_proxy_recreated(): void
    {
        $this->anonymous();
        file_put_contents($this->wizardDir.'/.env', "# engine\nREGISTRY_PROXY_USERNAME=\n");
        $this->keys([
            Key::ENTER,
            'Not A Name'.Key::ENTER, self::typed('mio', 'Not A Name'),
            'short'.Key::ENTER, self::typed(self::TOKEN, 'short'),
            Key::ENTER,
            Key::CTRL_E, Key::ENTER,
        ]);

        $section = $this->section();
        $section->run(false);

        $this->assertScreenShows('Login: none — the host IP\'s anonymous limit');
        $this->assertScreenShows('Host daemon: pulls through registry-proxy too');
        $this->assertScreenShows('A Docker Hub username: lowercase letters, digits, dots, dashes and underscores.');
        $this->assertScreenShows('A Docker Hub access token (dckr_pat_…) or password without spaces or quotes.');
        $this->assertScreenShows('Docker Hub accepts it: mio, 200 per 6 hours');
        $this->assertSame(['registry-proxy pulls from Docker Hub as mio.'], $section->receipt());
        $this->assertSame("# engine\nREGISTRY_PROXY_USERNAME=mio\nREGISTRY_PROXY_PASSWORD=".self::TOKEN."\n", file_get_contents($this->wizardDir.'/.env'));
        $this->assertContains('compose.engineUpWithoutDependencies', $this->asked);
    }

    public function test_a_login_that_reaches_private_repositories_is_saved_only_when_confirmed(): void
    {
        $this->anonymous();
        $this->account['private'] = ['secret'];
        $this->keys([
            Key::ENTER, 'mio'.Key::ENTER, self::TOKEN.Key::ENTER, Key::ENTER,
            Key::ENTER, 'mio'.Key::ENTER, self::TOKEN.Key::ENTER, 'y', Key::ENTER, Key::ENTER,
            Key::CTRL_E, Key::ENTER,
        ]);

        $section = $this->section();
        $section->run(true);

        $this->assertScreenShows('This token can pull private repositories, which every account could then pull: mio/secret');
        $this->assertScreenShows('Dry run: REGISTRY_PROXY_USERNAME would become mio, and registry-proxy would be recreated.');
        $this->assertSame([], $section->receipt());
        $this->assertNotContains('compose.engineUpWithoutDependencies', $this->asked);
    }

    public function test_a_login_whose_repositories_cannot_be_listed_is_still_saved_with_a_warning(): void
    {
        $this->anonymous();
        $this->account['private'] = null;
        $this->keys([Key::ENTER, 'mio'.Key::ENTER, self::TOKEN.Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = $this->section();
        $section->run(false);

        $this->assertScreenShows('Could not check whether this token reaches private repositories.');
        $this->assertSame(['registry-proxy pulls from Docker Hub as mio.'], $section->receipt());
    }

    public function test_a_login_docker_hub_refuses_is_not_saved(): void
    {
        $this->anonymous();
        $this->account['auth'] = 401;
        $this->keys([Key::ENTER, 'mio'.Key::ENTER, self::TOKEN.Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = $this->section();
        $section->run(false);

        $this->assertScreenShows('Docker Hub refused this username and token.');
        $this->assertSame([], $section->receipt());
        $this->assertNotContains('compose.engineUpWithoutDependencies', $this->asked);
    }

    public function test_a_login_that_cannot_be_applied_says_why(): void
    {
        $this->anonymous();
        $this->proxy['up'] = false;
        $this->keys([Key::ENTER, 'mio'.Key::ENTER, self::TOKEN.Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = $this->section();
        $section->run(false);

        $this->assertScreenShows('Could not save it: registry-proxy did not start');
        $this->assertSame([], $section->receipt());
    }

    public function test_the_current_login_is_shown_with_its_limit_and_can_be_removed(): void
    {
        $this->loggedIn();
        $this->keys([...self::row(1), Key::ENTER, ...self::row(1), 'y', Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);

        $section = $this->section();
        $section->run(false);

        $this->assertScreenShows('Login: mio');
        $this->assertScreenShows('Rate limit: 199 per 6 hours left of 200 per 6 hours');
        $this->assertScreenShows('Host daemon: pulls Docker Hub directly');
        $this->assertScreenShows('Replace the login');
        $this->assertSame(['registry-proxy pulls from Docker Hub anonymously.'], $section->receipt());
        $this->assertStringContainsString("REGISTRY_PROXY_USERNAME=\nREGISTRY_PROXY_PASSWORD=\n", file_get_contents($this->wizardDir.'/.env'));
    }

    public function test_removing_the_login_in_a_dry_run_or_when_it_fails_keeps_it(): void
    {
        $this->loggedIn();
        $this->account['auth'] = 500;
        $this->keys([...self::row(1), 'y', Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);
        $dry = $this->section();
        $dry->run(true);
        $this->assertScreenShows('Rate limit: Docker Hub answered HTTP 500.');
        $this->assertScreenShows('Dry run: the login would be removed and registry-proxy recreated.');

        $this->proxy['up'] = false;
        $this->keys([...self::row(1), 'y', Key::ENTER, Key::ENTER, Key::CTRL_E, Key::ENTER]);
        $failed = $this->section();
        $failed->run(false);

        $this->assertScreenShows('Could not remove it: registry-proxy did not start');
        $this->assertSame([], $dry->receipt());
        $this->assertSame([], $failed->receipt());
    }

    private function anonymous(): void
    {
        $this->proxy['env'] = "REGISTRY_PROXY_USERNAME=\nREGISTRY_PROXY_PASSWORD=";
        $this->proxy['mirrors'] = '["http://'.TestNetwork::LOOPBACK.':5001"]';
    }

    private function loggedIn(): void
    {
        $this->proxy['env'] = "PATH=/usr/bin\nREGISTRY_PROXY_USERNAME=mio\nREGISTRY_PROXY_PASSWORD=".self::TOKEN;
        $this->proxy['mirrors'] = '[]';
    }

    private function section(): DockerHubSection
    {
        $section = new DockerHubSection();
        (new ReflectionProperty($section, 'login'))->setValue($section, new DockerHubLogin($this->host(), $this->dockerHub(...)));

        return $section;
    }

    /** The engine host: registry-proxy's environment, the daemon's mirrors, and a compose up that may fail. */
    private function host(): System
    {
        $test = $this;

        return new class ($test, $this->wizardDir) extends NoProcessHost {
            public function __construct(private DockerHubSectionTest $test, private string $dir) {}

            public function engineDirPath(): string
            {
                return $this->dir;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->test->answer((string) LocalRoot::action((array) $cmd));
            }
        };
    }

    /** What the faked host answers a broker request with. */
    public function answer(string $action): string
    {
        $this->asked[] = $action;

        return match ($action) {
            'docker.runningEnvironment' => $this->proxy['env'] ?? throw new RuntimeException('No such container'),
            'docker.registryMirrors' => $this->proxy['mirrors'] ?? throw new RuntimeException('Cannot connect to the Docker daemon'),
            'compose.engineUpWithoutDependencies' => $this->proxy['up'] ? '' : throw new RuntimeException("registry-proxy did not start\n"),
            default => throw new RuntimeException("unexpected {$action}"),
        };
    }

    /**
     * Docker Hub, as the login sees it.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private function dockerHub(string $method, string $url, array $headers, ?string $body): ?array
    {
        if (str_contains($url, 'ratelimitpreview/test:pull')) {
            return ['status' => $this->account['auth'], 'headers' => [], 'body' => json_encode(['token' => 'bearer'])];
        }

        if (str_contains($url, '/manifests/latest')) {
            return ['status' => 200, 'headers' => ['ratelimit-limit' => '200;w=21600', 'ratelimit-remaining' => '199;w=21600'], 'body' => ''];
        }

        if (str_ends_with($url, '/v2/users/login')) {
            return $this->account['private'] === null
                ? ['status' => 401, 'headers' => [], 'body' => '{}']
                : ['status' => 200, 'headers' => [], 'body' => json_encode(['token' => 'jwt'])];
        }

        if (str_contains($url, '/repositories')) {
            $results = array_map(fn (string $name): array => ['name' => $name, 'is_private' => true], $this->account['private'] ?? []);

            return ['status' => 200, 'headers' => [], 'body' => json_encode(['results' => $results])];
        }

        $grant = ['access' => [['name' => 'mio/secret', 'actions' => ['pull']]]];

        return ['status' => 200, 'headers' => [], 'body' => json_encode(['token' => 'x.'.rtrim(strtr(base64_encode(json_encode($grant)), '+/', '-_'), '=').'.y'])];
    }
}

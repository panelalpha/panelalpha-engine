<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AnsweringPort;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a deploy found serving the site when it guessed between equal ports
 * (Cabernet: 6077 over 5004), and when a rebuild may keep it: only for the same
 * guess, with the same services behind those ports.
 */
class AnsweringPortTest extends TestCase
{
    private const CABERNET = ['primary' => 5004, 'alternatives' => [6077]];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/answering-port-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_the_guess_is_the_lowest_of_one_services_equal_ports(): void
    {
        $this->assertSame(self::CABERNET, AnsweringPort::choiceIn($this->compose(['6077:6077', '5004:5004']), [], null));
        $this->assertSame(
            ['primary' => 5004, 'alternatives' => [6077, 7000]],
            AnsweringPort::choiceIn($this->compose(['5004:5004', '7000:7000', '6077:6077']), [], null)
        );
        // `.env` publishes the port, as compose would.
        $this->assertSame(
            ['primary' => 5004, 'alternatives' => [6078]],
            AnsweringPort::choiceIn($this->compose(['5004:5004', '${UI_PORT:-6077}:6077']), ['UI_PORT' => '6078'], null)
        );
    }

    public function test_there_is_no_guess_when_the_port_has_a_reason(): void
    {
        $this->assertNull(AnsweringPort::choiceIn($this->compose(['5004:5004', '6077:6077']), [], 5004), 'a recipe port');
        $this->assertNull(AnsweringPort::choiceIn($this->compose(['8080:8080', '6077:6077']), [], null), 'a preferred port');
        $this->assertNull(AnsweringPort::choiceIn($this->compose(['6077:6077']), [], null), 'one port');
        $this->assertNull(AnsweringPort::choiceIn($this->dir . '/missing.yml', [], null));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: bool}> a config next to the Cabernet one, whether the same serves behind the ports */
    public static function behindTheGuess(): iterable
    {
        yield 'the same, written in another order' => [self::config(env: ['UI_PORT' => '6077', 'STREAM_PORT' => '5004'], reorder: true), true];
        yield 'another service changed' => [self::config(sidecar: 'redis:8'), true];
        yield 'container ports swapped behind the same published ports' => [self::config(targets: [5004 => 6077, 6077 => 5004]), false];
        yield 'another container port' => [self::config(targets: [5004 => 5004, 6077 => 7000]), false];
        yield 'the environment says which port serves what' => [self::config(env: ['STREAM_PORT' => '6077', 'UI_PORT' => '5004']), false];
        yield 'another image' => [self::config(image: 'cabernet:0.9.15'), false];
        yield 'another command' => [self::config(command: ['python3', 'tvh_main.py', '--ui', '5004']), false];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('behindTheGuess')]
    public function test_what_serves_behind_the_guessed_ports_is_the_services_publishing_them(array $config, bool $same): void
    {
        $was = AnsweringPort::behind(self::config(), self::CABERNET);

        $this->assertNotNull($was);
        $this->assertSame($same, AnsweringPort::behind($config, self::CABERNET) === $was);
    }

    public function test_nothing_is_behind_ports_no_service_publishes(): void
    {
        $this->assertNull(AnsweringPort::behind(self::config(), ['primary' => 8000, 'alternatives' => [8001]]));
    }

    /** @return iterable<string, array{0: mixed, 1: ?array{primary: int, alternatives: list<int>}, 2: array<string, mixed>, 3: ?int}> */
    public static function records(): iterable
    {
        $behind = AnsweringPort::behind(self::config(), self::CABERNET);
        $proven = ['port' => 6077, 'choice' => self::CABERNET + ['behind' => $behind]];
        yield 'the same guess, the same services' => [$proven, self::CABERNET, self::config(), 6077];
        yield 'settled on the lowest port' => [['port' => 5004] + $proven, self::CABERNET, self::config(), 5004];
        yield 'container ports swapped behind them' => [$proven, self::CABERNET, self::config(targets: [5004 => 6077, 6077 => 5004]), null];
        yield 'the environment changed' => [$proven, self::CABERNET, self::config(env: ['STREAM_PORT' => '6077', 'UI_PORT' => '5004']), null];
        yield 'another equal port' => [$proven, ['primary' => 5004, 'alternatives' => [6078]], self::config(), null];
        yield 'another lowest port' => [$proven, ['primary' => 5005, 'alternatives' => [6077]], self::config(), null];
        yield 'no guess now' => [$proven, null, self::config(), null];
        yield 'a record without what served behind it' => [['port' => 6077, 'choice' => self::CABERNET], self::CABERNET, self::config(), null];
        yield 'a port outside the guess' => [['port' => 8080] + $proven, self::CABERNET, self::config(), null];
    }

    /**
     * @param ?array{primary: int, alternatives: list<int>} $choice
     * @param array<string, mixed> $config
     */
    #[DataProvider('records')]
    public function test_a_rebuild_keeps_the_port_only_for_the_same_guess_and_services_and_uses_the_record_up(mixed $record, ?array $choice, array $config, ?int $kept): void
    {
        $user = $this->user(['app_port' => 6077, AnsweringPort::DETAIL => $record]);

        $this->assertSame($kept, AnsweringPort::claim($user, $choice, $config));
        $this->assertNull($user->getDetails()[AnsweringPort::DETAIL], 'a refused rebuild leaves nothing to keep');
    }

    public function test_nothing_recorded_is_nothing_to_claim(): void
    {
        $user = $this->user(['app_port' => 6077], false);

        $this->assertNull(AnsweringPort::claim($user, self::CABERNET, self::config()));
    }

    /** @return iterable<string, array{0: int, 1: list<array<string, mixed>>, 2: bool}> */
    public static function healthChecks(): iterable
    {
        $probed = static fn (?int $on5004, ?int $on6077): array => [
            ['port' => 5004, 'status' => $on5004 !== null && $on5004 < 500 ? 'ok' : 'fail', 'http_code' => $on5004],
            ['port' => 6077, 'status' => $on6077 !== null && $on6077 < 500 ? 'ok' : 'fail', 'http_code' => $on6077],
        ];
        yield 'moved to the port that served a page' => [6077, $probed(null, 200), true];
        yield 'the lowest port served it' => [5004, $probed(200, null), true];
        yield 'a redirect is a page too' => [6077, $probed(null, 302), true];
        yield 'the routed port answered 404 and nothing served' => [5004, $probed(404, null), false];
        yield 'the routed port answered 501' => [5004, $probed(501, 404), false];
        yield 'the routed port did not answer' => [6077, $probed(200, null), false];
    }

    /** @param list<array<string, mixed>> $ports */
    #[DataProvider('healthChecks')]
    public function test_only_a_port_that_served_a_page_is_recorded(int $routed, array $ports, bool $recorded): void
    {
        $user = $this->user(['app_port' => $routed, 'deploy_strategy' => 'compose', AnsweringPort::DETAIL => ['port' => 1, 'choice' => self::CABERNET]]);

        AnsweringPort::remember($this->project($user, $this->compose(['5004:5004', '6077:6077'])), ['healthy' => false, 'ports' => $ports]);

        $expected = $recorded ? ['port' => $routed, 'choice' => self::CABERNET + ['behind' => AnsweringPort::behind(self::config(), self::CABERNET)]] : null;
        $this->assertSame($expected, $user->getDetails()[AnsweringPort::DETAIL]);
    }

    public function test_a_project_without_a_guess_forgets_what_it_kept(): void
    {
        $user = $this->user(['app_port' => 8080, 'deploy_strategy' => 'compose', AnsweringPort::DETAIL => ['port' => 6077, 'choice' => self::CABERNET]]);

        AnsweringPort::remember($this->project($user, $this->compose(['8080:8080', '6077:6077'])), ['ports' => [['port' => 8080, 'status' => 'ok', 'http_code' => 200]]]);

        $this->assertNull($user->getDetails()[AnsweringPort::DETAIL]);
    }

    public function test_the_same_record_is_not_saved_again(): void
    {
        $record = ['port' => 6077, 'choice' => self::CABERNET + ['behind' => AnsweringPort::behind(self::config(), self::CABERNET)]];
        $user = $this->user(['app_port' => 6077, 'deploy_strategy' => 'compose', AnsweringPort::DETAIL => $record], false);

        AnsweringPort::remember($this->project($user, $this->compose(['5004:5004', '6077:6077'])), ['ports' => [['port' => 6077, 'status' => 'ok', 'http_code' => 200]]]);

        $this->assertSame($record, $user->getDetails()[AnsweringPort::DETAIL]);
    }

    public function test_a_record_that_cannot_be_saved_does_not_fail_the_deploy(): void
    {
        $user = $this->getMockBuilder(User::class)->onlyMethods(['save'])->getMock();
        $user->expects($this->once())->method('save')->willThrowException(new \RuntimeException('database is locked'));
        $user->details = ['app_port' => 6077, AnsweringPort::DETAIL => ['port' => 6077, 'choice' => self::CABERNET]];

        $this->assertNull(AnsweringPort::claim($user, self::CABERNET, self::config()));
    }

    public function test_the_rule_is_the_first_deploys(): void
    {
        $results = [['port' => 5004, 'http_code' => null], ['port' => 6077, 'http_code' => 200]];

        $this->assertSame(['port' => 6077, 'reason' => '5004 did not answer, 6077 answered 200'], AnsweringPort::better(self::CABERNET, $results));
        $this->assertNull(AnsweringPort::better(self::CABERNET, [['port' => 5004, 'http_code' => 302], ['port' => 6077, 'http_code' => 200]]));
        $this->assertNull(AnsweringPort::better(self::CABERNET, [['port' => 5004, 'http_code' => 501], ['port' => 6077, 'http_code' => 404]]));
    }

    /**
     * `docker compose config --format json` of a Cabernet-like stack.
     *
     * @param array<int, int> $targets published => container port
     * @param array<string, string> $env
     * @param ?list<string> $command
     * @return array<string, mixed>
     */
    private static function config(
        array $targets = [5004 => 5004, 6077 => 6077],
        array $env = ['STREAM_PORT' => '5004', 'UI_PORT' => '6077'],
        string $image = 'cabernet:0.9.14',
        ?array $command = null,
        string $sidecar = 'redis:7',
        bool $reorder = false,
    ): array {
        $ports = [];
        foreach ($targets as $published => $target) {
            $ports[] = ['mode' => 'ingress', 'target' => $target, 'published' => (string) $published, 'protocol' => 'tcp'];
        }
        $web = ['image' => $image, 'environment' => $env, 'ports' => $ports, 'restart' => 'unless-stopped'];
        if ($command !== null) {
            $web['command'] = $command;
        }
        if ($reorder) {
            $web = array_reverse($web, true);
        }

        return [
            'name' => 'project',
            'services' => ['web' => $web, 'cache' => ['image' => $sidecar, 'restart' => 'unless-stopped']],
        ];
    }

    /** @param list<string> $ports */
    private function compose(array $ports): string
    {
        $path = $this->dir . '/compose-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($path, "services:\n  web:\n    build: .\n    ports:\n" . implode('', array_map(static fn (string $p): string => "      - \"{$p}\"\n", $ports)));

        return $path;
    }

    /** @param array<string, mixed> $details */
    private function user(array $details, bool $saves = true): User
    {
        $user = $this->getMockBuilder(User::class)->onlyMethods(['save'])->getMock();
        $user->expects($saves ? $this->once() : $this->never())->method('save')->willReturn(true);
        $user->username = 'answering-' . bin2hex(random_bytes(4));
        $user->details = $details;

        return $user;
    }

    /** A project whose `docker compose config` is {@see config()}. */
    private function project(User $user, string $composePath): Dind
    {
        $environment = $this->createStub(ProjectEnvironment::class);
        $environment->method('forPortDetection')->willReturn([]);
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(static fn (string|array $cmd): string => str_contains(is_array($cmd) ? implode(' ', $cmd) : $cmd, "'config' '--format' 'json'")
            ? (string) json_encode(self::config()) : '');
        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($user->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($user);
        $project->method('userAppComposeFileForPorts')->willReturn($composePath);
        $project->method('userAppComposeCommand')->willReturnCallback(static fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $project->method('environment')->willReturn($environment);
        $project->method('shell')->willReturn(new ShellOperations($project));

        return $project;
    }
}

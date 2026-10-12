<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountTeardown;
use App\System\Project\Dind\Generation\CancelledGeneration;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A cancelled redeploy of a stopped app left the containers it had started running: only a first deploy
 * was taken down. What the redeploy created goes; what was there before, and a version still serving, stay.
 */
class CancelledGenerationTest extends TestCase
{
    private string $username = '';

    /** @var list<string> each command run, flattened */
    private array $ran = [];

    /** @var list<string> the arguments of the removal: containers, `--`, images */
    private array $removal = [];

    /** What `docker inspect` says of each container the old version ran. */
    private string $previousRunning = "true\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'cancelgen-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_a_cancelled_redeploy_of_a_stopped_app_removes_only_what_it_created(): void
    {
        $deploy = DeployLogger::start($this->username);

        $removed = (new CancelledGeneration($this->dind()))->remove();

        $this->assertSame(['new-app', 'new-db'], $removed);
        $this->assertSame(['new-app', 'new-db', '--', 'sha256:built'], $this->removal, 'the pulled postgres image stays');
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    public function test_a_version_it_was_replacing_that_no_longer_runs_keeps_everything(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::ROUTES, ['containers' => ['old-app']]);
        $this->previousRunning = "gone\n";

        $this->assertSame([], (new CancelledGeneration($this->dind()))->remove());
        $this->assertSame([], $this->removal);
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    public function test_beside_a_version_that_still_runs_only_the_new_containers_go(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::ROUTES, ['containers' => ['old-app']]);

        $this->assertSame(['new-app', 'new-db'], (new CancelledGeneration($this->dind()))->remove());
        $this->assertNotContains('old-app', $this->removal);
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    public function test_without_a_deploy_start_nothing_is_asked_or_removed(): void
    {
        $this->assertSame([], (new CancelledGeneration($this->dind()))->remove());
        $this->assertSame([], $this->ran);
    }

    public function test_only_lines_created_since_the_start_are_kept(): void
    {
        $since = strtotime('2026-10-11T10:00:00Z');
        $inspect = "a 2026-10-11T09:59:59.999999999Z sha256:1\n"
            . "b 2026-10-11T10:00:00.5Z sha256:2\n"
            . "c 2026-10-11T10:05:00Z\n"
            . "garbage\n"
            . "d not-a-time sha256:4\n";

        $this->assertSame(
            [['id' => 'b', 'image' => 'sha256:2'], ['id' => 'c', 'image' => '']],
            CancelledGeneration::createdSince($inspect, $since)
        );
    }

    /** A fully cached redeploy starts the old, stopped container: a cancel stops it again. */
    public function test_a_cancelled_redeploy_of_a_stopped_app_stops_what_it_started(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::STOPPED, ['owner' => GenerationState::owner()]);

        (new AccountTeardown($this->dind()))->cancelRunningDeploy();

        $this->assertNotEmpty(
            array_filter($this->ran, static fn (string $line): bool => str_contains($line, 'docker compose stop')),
            'the container the redeploy started is stopped again'
        );
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    private function dind(): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->answer(implode(' ', (array) $cmd), (array) $cmd));
        $system->method('runProcess')->willReturnCallback(function (string|array $cmd): Process {
            $this->ran[] = implode(' ', (array) $cmd);
            $process = new Process(['true']);
            $process->run();

            return $process;
        });

        $model = new ModelsUser;
        $model->username = $this->username;
        $model->setDetails(['deployment_status' => 'success']);

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('userAppComposeCommand')->willReturnCallback(static fn (array $rest): array => ['docker', 'compose', ...$rest]);

        return $dind;
    }

    /** @param list<string> $argv */
    private function answer(string $flat, array $argv): string
    {
        $this->ran[] = $flat;
        $before = self::at(-3600);
        $after = self::at(5);

        return match (true) {
            str_contains($flat, 'docker version') => $this->previousRunning,
            str_contains($flat, '--all') => "old-app {$before} sha256:old\nnew-app {$after} sha256:built\nnew-db {$after} sha256:pg\n",
            in_array('image', $argv, true) && in_array('inspect', $argv, true) => "sha256:built {$after}\nsha256:pg " . self::at(-86400) . "\n",
            str_contains($flat, 'docker rm -f') => $this->recordRemoval($argv),
            default => '',
        };
    }

    /** @param list<string> $argv */
    private function recordRemoval(array $argv): string
    {
        $this->removal = array_slice($argv, (int) array_search('remove', $argv, true) + 1);

        return '';
    }

    private static function at(int $offset): string
    {
        return gmdate('Y-m-d\TH:i:s', time() + $offset) . '.123456789Z';
    }
}

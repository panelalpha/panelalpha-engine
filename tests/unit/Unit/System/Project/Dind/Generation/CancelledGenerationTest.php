<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AccountTeardown;
use App\System\Project\Dind\Generation\CancelledGeneration;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
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

    /** Whether the container the old version ran is gone. */
    private bool $previousGone = false;

    /** What compose lists besides the containers the daemon has: one gone before it was inspected. */
    private string $alsoListed = '';

    /** Whether the image the redeploy pulled is gone before it was inspected. */
    private bool $pulledGone = false;

    private bool $runFileThere = true;

    /** @var array<string, string> `containers` or `images` => the daemon's error refusing their removal */
    private array $refused = [];

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
        (new GenerationState($this->username))->put(GenerationState::STOPPED, ['owner' => GenerationState::owner()]);

        $removed = (new CancelledGeneration($this->dind()))->remove();

        $this->assertSame(['new-app', 'new-db'], $removed);
        $this->assertSame(['new-app', 'new-db', '--', 'sha256:built'], $this->removal, 'the pulled postgres image stays');
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    public function test_a_version_it_was_replacing_that_no_longer_runs_keeps_everything(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::ROUTES, ['containers' => ['old-app']]);
        $this->previousGone = true;

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

    /** The app may be live when its containers could not be read: what the redeploy started may be what serves. */
    public function test_a_redeploy_that_could_not_read_the_running_containers_removes_and_stops_nothing(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::UNREAD, ['owner' => GenerationState::owner()]);

        (new AccountTeardown($this->dind()))->cancelRunningDeploy();

        $this->assertSame([], $this->removal);
        $this->assertSame([], array_filter($this->ran, static fn (string $line): bool => str_contains($line, '--all') || str_contains($line, 'compose stop')));
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    public function test_a_redeploy_that_noted_nothing_yet_removes_nothing(): void
    {
        $deploy = DeployLogger::start($this->username);

        $this->assertSame([], (new CancelledGeneration($this->dind()))->remove());
        $this->assertSame([], $this->removal);
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    /** Between moving the checkout aside and writing the new run file, compose cannot read the app's project, and nothing was started yet. */
    public function test_a_cancel_while_the_checkout_is_aside_before_the_new_run_file_asks_compose_nothing(): void
    {
        $deploy = DeployLogger::start($this->username);
        $state = new GenerationState($this->username);
        $state->put(GenerationState::ROUTES, ['containers' => ['old-app']]);
        $state->put(GenerationState::CHECKOUT, ['path' => '/home/u/.project-prev', 'containers' => ['old-app'], 'owner' => GenerationState::owner()]);
        $this->runFileThere = false;

        $this->assertSame([], (new CancelledGeneration($this->dind()))->remove());
        $this->assertSame([], array_filter($this->ran, static fn (string $line): bool => str_contains($line, '--all')));
        $this->assertSame([], $this->removal);
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    public function test_once_the_new_run_file_is_written_a_checkout_aside_does_not_stop_the_removal(): void
    {
        $deploy = DeployLogger::start($this->username);
        $state = new GenerationState($this->username);
        $state->put(GenerationState::ROUTES, ['containers' => ['old-app']]);
        $state->put(GenerationState::CHECKOUT, ['path' => '/home/u/.project-prev', 'containers' => ['old-app'], 'owner' => GenerationState::owner()]);

        $this->assertSame(['new-app', 'new-db'], (new CancelledGeneration($this->dind()))->remove());
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    /** A container gone between the listing and the inspect: the project could not be read, and nothing goes. */
    public function test_a_container_gone_before_it_was_inspected_removes_nothing(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::STOPPED, ['owner' => GenerationState::owner()]);
        $this->alsoListed = 'gone-app';

        $this->assertSame([], (new CancelledGeneration($this->dind()))->remove());
        $this->assertSame([], $this->removal);
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    /** An image gone before it was inspected: the new containers still go, and no image with them, not even the one built. */
    public function test_an_image_gone_before_it_was_inspected_leaves_every_image(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::STOPPED, ['owner' => GenerationState::owner()]);
        $this->pulledGone = true;

        $this->assertSame(['new-app', 'new-db'], (new CancelledGeneration($this->dind()))->remove());
        $this->assertSame(['new-app', 'new-db', '--'], $this->removal);
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    /** A container the daemon would not remove: nothing counts as removed, and no image is asked to go. */
    public function test_a_container_the_daemon_would_not_remove_removes_no_image(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::STOPPED, ['owner' => GenerationState::owner()]);
        $this->refused['containers'] = 'Error response from daemon: removal of container new-db is already in progress';

        $this->assertSame([], (new CancelledGeneration($this->dind()))->remove());
        $this->assertSame([], array_filter($this->ran, static fn (string $line): bool => str_contains($line, 'account-docker.removeImages')));
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    /** An image still in use stays, and the containers still count as removed. */
    public function test_an_image_the_daemon_would_not_remove_still_counts_the_containers_removed(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::STOPPED, ['owner' => GenerationState::owner()]);
        $this->refused['images'] = 'Error response from daemon: conflict: unable to delete sha256:built (cannot be forced) - image is being used by running container';

        $this->assertSame(['new-app', 'new-db'], (new CancelledGeneration($this->dind()))->remove());
        $this->assertSame(['new-app', 'new-db', '--', 'sha256:built'], $this->removal);
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    public function test_without_a_deploy_start_nothing_is_asked_or_removed(): void
    {
        $this->assertSame([], (new CancelledGeneration($this->dind()))->remove());
        $this->assertSame([], $this->ran);
    }

    public function test_only_what_was_created_since_the_start_is_kept(): void
    {
        $since = strtotime('2026-10-11T10:00:00Z');
        $inspected = [
            ['Id' => 'a', 'Created' => '2026-10-11T09:59:59.999999999Z', 'Image' => 'sha256:1'],
            ['Id' => 'b', 'Created' => '2026-10-11T10:00:00.5Z', 'Image' => 'sha256:2'],
            ['Id' => 'c', 'Created' => '2026-10-11T10:05:00Z'],
            ['Id' => 'garbage'],
            ['Created' => '2026-10-11T10:05:00Z'],
            ['Id' => 'd', 'Created' => 'not-a-time', 'Image' => 'sha256:4'],
            null,
        ];

        $this->assertSame(
            [['id' => 'b', 'image' => 'sha256:2'], ['id' => 'c', 'image' => '']],
            CancelledGeneration::createdSince($inspected, $since)
        );
        $this->assertSame([], CancelledGeneration::createdSince([], $since));
    }

    /** A fully cached redeploy starts the old, stopped container: a cancel stops it again. */
    public function test_a_cancelled_redeploy_of_a_stopped_app_stops_what_it_started(): void
    {
        $deploy = DeployLogger::start($this->username);
        (new GenerationState($this->username))->put(GenerationState::STOPPED, ['owner' => GenerationState::owner()]);

        (new AccountTeardown($this->dind()))->cancelRunningDeploy();

        $this->assertNotEmpty(
            array_filter($this->ran, static fn (string $line): bool => str_contains($line, 'account-compose.run') && str_ends_with($line, ' compose stop')),
            'the container the redeploy started is stopped again'
        );
        $deploy->finish(DeployLogger::STATUS_CANCELLED);
    }

    private function dind(): Dind
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('fileExists')->willReturnCallback(fn (string $path): bool => $path === '/home/u/project/docker-compose.panelalpha.yml' && $this->runFileThere);
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->answer(implode(' ', (array) $cmd), (array) $cmd));
        $system->method('runProcess')->willReturnCallback(function (string|array $cmd): Process {
            $this->ran[] = implode(' ', (array) $cmd);
            $process = new Process(['true']);
            $process->run();

            return $process;
        });

        $model = new ModelsUser;
        $model->username = $this->username;
        $model->setDetails(['deployment_status' => 'success', 'UID' => 1000, 'GID' => 1000]);

        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('userAppComposeFilePath')->willReturn('/home/u/project/docker-compose.panelalpha.yml');
        $dind->method('userAppComposeCommand')->willReturnCallback(static fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $dind->method('userAppComposeProjectName')->willReturn('project');

        return $dind;
    }

    /** @param list<string> $argv */
    private function answer(string $flat, array $argv): string
    {
        $this->ran[] = $flat;
        $asked = array_slice(LocalRoot::values($argv), 1);

        return match (true) {
            LocalRoot::action($argv) === 'account-docker.containers' => (string) json_encode(array_map($this->container(...), $asked)),
            LocalRoot::action($argv) === 'account-docker.images' => (string) json_encode(array_map($this->image(...), $asked)),
            LocalRoot::action($argv) === 'account-docker.removeContainers' => $this->recordContainers($asked),
            LocalRoot::action($argv) === 'account-docker.removeImages' => $this->recordImages($asked),
            LocalRoot::action($argv) === 'account-docker.listContainers' => self::listed(['old-app', 'new-app', 'new-db', $this->alsoListed]),
            default => '',
        };
    }

    /**
     * The daemon's list of the stack, in the order given.
     *
     * @param  list<string>  $ids
     */
    private static function listed(array $ids): string
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => $id !== ''));

        return (string) json_encode(array_map(static fn (string $id, int $at): array => ['Id' => $id, 'Names' => ["/project-{$at}"]], $ids, array_keys($ids)));
    }

    /** @return ?array<string, mixed> the old version's container, then what the redeploy created */
    private function container(string $id): ?array
    {
        return match ($id) {
            'old-app' => $this->previousGone ? null : ['Id' => 'old-app', 'Created' => self::at(-3600), 'Image' => 'sha256:old', 'State' => ['Running' => true]],
            'new-app' => ['Id' => 'new-app', 'Created' => self::at(5), 'Image' => 'sha256:built', 'State' => ['Running' => true]],
            'new-db' => ['Id' => 'new-db', 'Created' => self::at(5), 'Image' => 'sha256:pg', 'State' => ['Running' => true]],
            default => null,
        };
    }

    /** @return ?array<string, mixed> the image the redeploy built, and a pulled one older than it */
    private function image(string $ref): ?array
    {
        return match ($ref) {
            'sha256:built' => ['Id' => 'sha256:built', 'Created' => self::at(5)],
            'sha256:pg' => $this->pulledGone ? null : ['Id' => 'sha256:pg', 'Created' => self::at(-86400)],
            default => null,
        };
    }

    /** @param list<string> $asked `force`, no `volumes`, then the containers */
    private function recordContainers(array $asked): string
    {
        $this->assertSame(['force', ''], array_slice($asked, 0, 2));
        if (isset($this->refused['containers'])) {
            throw new DockerErrorException($this->refused['containers']);
        }
        $this->removal = [...array_slice($asked, 2), '--'];

        return '';
    }

    /** @param list<string> $asked no `force`, then the images */
    private function recordImages(array $asked): string
    {
        $this->assertSame('', $asked[0]);
        $this->removal = [...$this->removal, ...array_slice($asked, 1)];
        if (isset($this->refused['images'])) {
            throw new DockerErrorException($this->refused['images']);
        }

        return '';
    }

    private static function at(int $offset): string
    {
        return gmdate('Y-m-d\TH:i:s', time() + $offset) . '.123456789Z';
    }
}

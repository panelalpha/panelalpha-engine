<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\ServingImages;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A failed redeploy must not leave `project-app` pointing at the build it
 * rejected: what each running container runs is noted before the build.
 */
class ServingImagesTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'serving-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    /**
     * When the images cannot be held, nothing is noted, so a later rollback
     * cannot start the previous version. The failure has to say so, with its
     * reason, in the deploy log -- otherwise the rollback only reports "its
     * checkout or images are no longer kept" and hides the cause.
     */
    public function test_the_read_failure_is_logged_with_its_reason(): void
    {
        $logger = DeployLogger::start($this->username);
        $dind = $this->dindThatFails(\RuntimeException::class, 'cannot connect to the daemon');

        (new ServingImages($dind))->remember();

        $lines = array_map(static fn (array $e): string => $e['msg'], $logger->entries());
        $lines = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'Could not keep the images')));
        $this->assertCount(1, $lines, 'the failure is logged once, in the deploy log');
        $this->assertStringContainsString('cannot connect to the daemon', $lines[0]);
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::IMAGES));
    }

    /** A container gone between the listing and the inspect: they could not be read, and nothing is noted. */
    public function test_a_container_gone_since_it_was_listed_is_a_read_failure(): void
    {
        $logger = DeployLogger::start($this->username);
        $dind = $this->dind($this->account(static fn (string $action): string => match ($action) {
            'account-docker.containers' => '[' . self::inspect('c0ffee', 'sha256:2e3a4abc', 'project-app') . ',null]',
            default => '',
        }, "c0ffee\nbeef\n"));

        (new ServingImages($dind))->remember();

        $lines = array_map(static fn (array $e): string => $e['msg'], $logger->entries());
        $lines = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'Could not keep the images')));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('its running containers could not be read: No such container: beef', $lines[0]);
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::IMAGES));
    }

    /** The read worked but the account could not be asked to tag: the same report, a different reason. */
    public function test_the_tag_failure_is_logged_with_its_reason(): void
    {
        $logger = DeployLogger::start($this->username);
        $dind = $this->dind($this->account(static fn (string $action): string => match ($action) {
            'account-docker.containers' => '[' . self::inspect('c0ffee', 'sha256:2e3a4abc', 'project-app') . ']',
            'account-docker.tag' => throw new \RuntimeException('tag: no space left on device'),
            default => '',
        }, "c0ffee\n"));

        (new ServingImages($dind))->remember();

        $lines = array_map(static fn (array $e): string => $e['msg'], $logger->entries());
        $lines = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'could not be tagged')));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('no space left on device', $lines[0]);
    }

    /** An account that is not running fails the whole hold, as the daemon refusing one tag does not. */
    public function test_an_account_that_stopped_answering_fails_the_hold(): void
    {
        $logger = DeployLogger::start($this->username);
        $dind = $this->dind($this->account(fn (string $action): string => match ($action) {
            'account-docker.containers' => '[' . self::inspect('c0ffee', 'sha256:2e3a4abc', 'project-app') . ']',
            'account-docker.tag' => throw new DockerErrorException("container {$this->username} is not running"),
            default => '',
        }, "c0ffee\n"));

        (new ServingImages($dind))->remember();

        $lines = array_map(static fn (array $e): string => $e['msg'], $logger->entries());
        $lines = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'Could not keep the images')));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString("their images could not be tagged: container {$this->username} is not running", $lines[0]);
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::IMAGES));
    }

    /** A `docker tag` the daemon refused is skipped, not thrown: the un-held image is named. */
    public function test_an_image_whose_tag_was_not_set_is_logged_with_its_name(): void
    {
        $logger = DeployLogger::start($this->username);
        $dind = $this->dind($this->account(static fn (string $action, array $values): string => match (true) {
            $action === 'account-docker.containers' => '[' . self::inspect('c0ffee', 'sha256:2e3a4abc', 'project-app')
                . ',' . self::inspect('beef', 'sha256:9f00', 'postgres:16') . ']',
            $action === 'account-docker.tag' && $values[0] === 'sha256:9f00' => throw new DockerErrorException('Error response from daemon: No such image: sha256:9f00'),
            default => '',
        }, "c0ffee\nbeef\n"));

        (new ServingImages($dind))->remember();

        $lines = array_map(static fn (array $e): string => $e['msg'], $logger->entries());
        $lines = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'Could not keep the images')));
        $this->assertCount(1, $lines, 'the un-held image is reported once, in the deploy log');
        $this->assertStringContainsString('postgres:16', $lines[0]);
        $this->assertStringNotContainsString('project-app', $lines[0]);
        $this->assertSame(['project-app'], array_column((new ServingImages($dind))->noted(), 'ref'));
    }

    private function dindThatFails(string $exception, string $message): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willThrowException(new $exception($message));

        return $this->dind($system);
    }

    /**
     * An account whose stack is the containers $ps lists, in that order, and whose daemon answers $answer(action,
     * values after the username).
     *
     * @param  \Closure(string, list<string>): string  $answer
     */
    private function account(\Closure $answer, string $ps): System
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(static function (string|array $cmd) use ($answer, $ps): string {
            $action = LocalRoot::action((array) $cmd);
            if ($action === 'account-docker.listContainers') {
                $ids = preg_split('/\s+/', trim($ps), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                return (string) json_encode(array_map(static fn (string $id, int $at): array => ['Id' => $id, 'Names' => ["/project-app-{$at}"]], $ids, array_keys($ids)));
            }

            return $action !== null && str_starts_with($action, 'account-docker.')
                ? $answer($action, array_slice(LocalRoot::values((array) $cmd), 1))
                : '';
        });

        return $system;
    }

    private static function inspect(string $id, string $image, string $ref): string
    {
        return (string) json_encode(['Id' => $id, 'Image' => $image, 'Config' => ['Image' => $ref]]);
    }

    private function dind(System $system): Dind
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $dind->method('username')->willReturn($this->username);
        $dind->method('system')->willReturn($system);
        // The real ShellOperations, so the deploy logger and the exec wrapper
        // behave as they do in a deploy.
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('userAppComposeProjectName')->willReturn('project');

        return $dind;
    }

    public function test_each_running_container_is_noted_with_its_image_and_reference(): void
    {
        $inspected = [
            ['Id' => 'c0ffee', 'Image' => 'sha256:2e3a4abc', 'Config' => ['Image' => 'project-app']],
            ['Id' => 'beef', 'Image' => 'sha256:9f00', 'Config' => ['Image' => 'postgres:16']],
            ['Id' => 'dead', 'Image' => 'sha256:aa11', 'Config' => ['Image' => 'traefik/whoami@sha256:1234']],
            ['Id' => 'f00d', 'Image' => 'sha256:bb22'],
            null,
        ];

        $this->assertSame([
            ['id' => 'c0ffee', 'image' => 'sha256:2e3a4abc', 'ref' => 'project-app'],
            ['id' => 'beef', 'image' => 'sha256:9f00', 'ref' => 'postgres:16'],
        ], ServingImages::parse($inspected));
        $this->assertSame([], ServingImages::parse([]));
    }

    /** The old image is held by a tag of its own, or a build that takes its name lets it go. */
    public function test_the_holding_tag_is_named_after_the_image(): void
    {
        $this->assertSame('panelalpha-serving:2e3a4abc0123', ServingImages::keepTag('sha256:2e3a4abc0123456789'));
    }
}

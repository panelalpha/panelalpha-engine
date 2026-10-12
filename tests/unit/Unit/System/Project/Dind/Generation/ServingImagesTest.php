<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\ServingImages;
use App\System\Project\Dind\ShellOperations;
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

    /** The read worked but the tagging failed: the same report, a different reason. */
    public function test_the_tag_failure_is_logged_with_its_reason(): void
    {
        $logger = DeployLogger::start($this->username);
        $calls = 0;
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function () use (&$calls): string {
            $calls++;
            if ($calls === 1) {
                return "c0ffee sha256:2e3a4abc project-app\n";
            }

            throw new \RuntimeException('tag: no space left on device');
        });
        $dind = $this->dind($system);

        (new ServingImages($dind))->remember();

        $lines = array_map(static fn (array $e): string => $e['msg'], $logger->entries());
        $lines = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'could not be tagged')));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('no space left on device', $lines[0]);
    }

    private function dindThatFails(string $exception, string $message): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willThrowException(new $exception($message));

        return $this->dind($system);
    }

    private function dind(System $system): Dind
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('system')->willReturn($system);
        // The real ShellOperations, so the deploy logger and the exec wrapper
        // behave as they do in a deploy.
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('userAppComposeCommand')->willReturn(['ps', '--quiet']);

        return $dind;
    }

    public function test_each_running_container_is_noted_with_its_image_and_reference(): void
    {
        $inspect = "c0ffee sha256:2e3a4abc project-app\n"
            . "beef sha256:9f00 postgres:16\n"
            . "dead sha256:aa11 traefik/whoami@sha256:1234\n"
            . "garbage\n";

        $this->assertSame([
            ['id' => 'c0ffee', 'image' => 'sha256:2e3a4abc', 'ref' => 'project-app'],
            ['id' => 'beef', 'image' => 'sha256:9f00', 'ref' => 'postgres:16'],
        ], ServingImages::parse($inspect));
        $this->assertSame([], ServingImages::parse(''));
    }

    /** The old image is held by a tag of its own, or a build that takes its name lets it go. */
    public function test_the_holding_tag_is_named_after_the_image(): void
    {
        $this->assertSame('panelalpha-serving:2e3a4abc0123', ServingImages::keepTag('sha256:2e3a4abc0123456789'));
    }
}

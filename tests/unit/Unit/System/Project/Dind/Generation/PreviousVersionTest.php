<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\PreviousVersion;
use App\System\Project\Dind\ShellOperations;
use Tests\TestCase;

/**
 * `PreviousVersion::start()` answers null only once the previous version is
 * known to answer on its port again. When the routing snapshot recorded no
 * app_port there is nothing to probe, and reporting success without checking
 * let rollBack() discard the copy that was still serving the site.
 */
class PreviousVersionTest extends TestCase
{
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'prev-' . bin2hex(random_bytes(6));
        $state = new GenerationState($this->username);
        $state->put(GenerationState::CHECKOUT, [
            'path' => '/home/u/.project-prev',
            'containers' => ['c1'],
            'binds' => true,
            'records' => ['git_branch' => 'main', 'branch' => null],
        ]);
        $state->put(GenerationState::IMAGES, [
            'containers' => [['id' => 'c1', 'image' => 'sha256:abc', 'ref' => 'project-app']],
        ]);
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    /** No app_port in the snapshot: it is not known to answer, so say why it was not checked. */
    public function test_a_missing_recorded_port_is_reported_rather_than_assumed_started(): void
    {
        (new GenerationState($this->username))->put(GenerationState::ROUTES, ['details' => []]);

        $failure = (new PreviousVersion($this->project()))->start();

        $this->assertSame('its port was not recorded, so it was not checked', $failure);
    }

    private function project(): Dind
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('directoryExists')->willReturn(true);

        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('exec')->willReturn('');

        $user = $this->createStub(User::class);
        $user->method('getAppPort')->willReturn(3000);
        $user->method('getDetails')->willReturn([]);

        $project = $this->createStub(Dind::class);
        $project->method('username')->willReturn($this->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($user);
        $project->method('homeDirPath')->willReturn('/home/u');
        $project->method('userAppDirPath')->willReturn('/home/u/project');
        $project->method('userAppComposeCommand')->willReturn(['docker', 'compose', 'up', '-d']);
        $project->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($project));

        return $project;
    }
}

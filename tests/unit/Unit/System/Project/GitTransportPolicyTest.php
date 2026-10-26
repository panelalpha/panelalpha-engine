<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Git\WorkTree;
use PHPUnit\Framework\TestCase;

/**
 * The engine's own git runs speak only the transports the engine clones over.
 */
class GitTransportPolicyTest extends TestCase
{
    public function test_a_network_command_carries_the_transport_policy(): void
    {
        $git = new TransportRecordingWorkTree();

        $git->clone('https://example.test/acme/app.git', null, null);

        $this->assertNotEmpty($git->commands);
        $this->assertContains('GIT_ALLOW_PROTOCOL=http:https', $git->commands[0]);
    }
}

/** @internal Records the argv runWithCredentials() hands to the shell. */
final class TransportRecordingWorkTree extends WorkTree
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct()
    {
        $this->absolutePath = sys_get_temp_dir() . '/pa-git-transport-' . bin2hex(random_bytes(6)) . '/project';
        $this->pathKey = 'project';
    }

    protected function user(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';

        return $model;
    }

    protected function system(): EngineSystem
    {
        return new class extends EngineSystem
        {
            public function __construct() {}
        };
    }

    protected function homeDirPath(): string
    {
        return dirname($this->absolutePath);
    }

    protected function runCommand(array $cmd, int $timeout): string
    {
        $this->commands[] = $cmd;

        return '';
    }

    protected function runQuietCommand(array $cmd, int $timeout): string
    {
        $this->commands[] = $cmd;

        return '';
    }
}

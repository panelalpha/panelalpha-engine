<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTransport;
use PHPUnit\Framework\TestCase;

/**
 * One policy for every git command the engine itself runs.
 */
class GitTransportTest extends TestCase
{
    public function test_the_default_is_the_clone_schemes_the_engine_validates(): void
    {
        $this->assertSame(GitRepoInput::SCHEMES, GitTransport::schemes());
        $this->assertSame(['GIT_ALLOW_PROTOCOL' => 'http:https'], GitTransport::env(GitTransport::schemes()));
        $this->assertSame('GIT_ALLOW_PROTOCOL=http:https', GitTransport::assignment(GitTransport::schemes()));
        $this->assertSame(['GIT_ALLOW_PROTOCOL' => 'https:http:file'], GitTransport::env(['https', 'http', 'file']));
    }

    public function test_ssh_is_offered_only_beside_a_deploy_key(): void
    {
        $this->assertSame(['ssh', 'http', 'https'], GitTransport::schemes(true));
        $this->assertSame(['GIT_ALLOW_PROTOCOL' => 'ssh:http:https'], GitTransport::env(GitTransport::schemes(true)));
        $this->assertSame('GIT_ALLOW_PROTOCOL=ssh:http:https', GitTransport::assignment(GitTransport::schemes(true)));
    }
}

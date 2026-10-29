<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\SshSubmoduleRewrite;
use PHPUnit\Framework\TestCase;

/** The ssh submodule URLs a `.gitmodules` names, read over https instead. */
class SshSubmoduleRewriteTest extends TestCase
{
    public function test_scp_and_ssh_urls_each_get_an_https_rewrite(): void
    {
        $gitmodules = "[submodule \"a\"]\n\turl = git@github.com:org/a.git\n"
            . "[submodule \"b\"]\n\turl = ssh://git@GitLab.example.com:2222/org/b.git\n"
            . "[submodule \"c\"]\n\turl = git@github.com:org/c.git\n";

        $this->assertSame([
            '-c', 'url.https://github.com/.insteadOf=git@github.com:',
            '-c', 'url.https://gitlab.example.com/.insteadOf=ssh://git@GitLab.example.com:2222/',
        ], SshSubmoduleRewrite::configArgs($gitmodules));
    }

    public function test_https_and_relative_urls_are_left_alone(): void
    {
        $gitmodules = "[submodule \"a\"]\n\turl = https://github.com/org/a.git\n"
            . "[submodule \"b\"]\n\turl = ../b.git\n"
            . "[submodule \"c\"]\n\turl = https://user@github.com/org/c.git\n";

        $this->assertSame([], SshSubmoduleRewrite::configArgs($gitmodules));
    }
}

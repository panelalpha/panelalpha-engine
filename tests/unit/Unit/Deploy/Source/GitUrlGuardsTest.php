<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitUrl;
use PHPUnit\Framework\TestCase;

/** The argv builders refuse what they cannot quote safely, and leave alone what they do not recognise. */
class GitUrlGuardsTest extends TestCase
{
    public function test_a_host_that_cannot_be_quoted_into_the_helper_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Git repository host.');

        GitUrl::askPassScript('token', "github.com'; rm -rf ~");
    }

    public function test_an_empty_or_nul_askpass_path_is_refused(): void
    {
        foreach (['', "/tmp/askpass\0.sh"] as $path) {
            try {
                GitUrl::withAskPass(['git', 'ls-remote'], $path);
                $this->fail('Expected a refusal for '.json_encode($path));
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Invalid GIT_ASKPASS path.', $e->getMessage());
            }
        }
    }

    public function test_output_that_mentions_a_redirect_without_naming_one_names_no_target(): void
    {
        $this->assertNull(GitUrl::redirectedTo('fatal: the server refused to redirect this request'));
        $this->assertFalse(GitUrl::refusedRedirect('fatal: the server refused to redirect this request'));
    }

    public function test_the_refusal_names_the_target_only_when_it_is_known(): void
    {
        $this->assertSame(
            'The repository redirects to another address. This engine does not follow Git redirects; use the address '
                .'it redirects to (on GitLab, the one ending in .git).',
            GitUrl::redirectRefusal(null)
        );
        $this->assertSame(
            'The repository redirects to https://gitlab.com/g/p.git. This engine does not follow Git redirects; use https://gitlab.com/g/p.git instead.',
            GitUrl::redirectRefusal('https://gitlab.com/g/p.git')
        );
    }

    public function test_a_command_without_git_is_left_as_it_is(): void
    {
        $command = ['env', 'GIT_TERMINAL_PROMPT=0', 'ssh', 'host'];

        $this->assertSame($command, GitUrl::overHttp11($command));
        $this->assertSame($command, GitUrl::withoutRedirects($command));
    }

    public function test_an_ssh_key_path_that_a_shell_would_split_is_refused(): void
    {
        foreach ([['', '/tmp/known'], ['/tmp/my key', '/tmp/known'], ['/tmp/key', '/tmp/known;id']] as [$key, $known]) {
            try {
                GitUrl::withSshKey(['env', 'git', 'ls-remote'], $key, $known);
                $this->fail('Expected a refusal for '.$key.' / '.$known);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Invalid SSH key path.', $e->getMessage());
            }
        }
    }

    public function test_an_ssh_key_needs_an_env_argv_to_ride_on(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an env argv.');

        GitUrl::withSshKey(['git', 'ls-remote'], '/tmp/key', '/tmp/known_hosts');
    }
}

<?php

namespace Tests\Integration\Deploy\Source;

use App\Lib\Deploy\Source\GitUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GitUrlTest extends TestCase
{
    public function test_askpass_supplies_token_without_putting_it_in_command_arguments(): void
    {
        $token = "s3cret token'\nsecond-line";
        $path = tempnam(sys_get_temp_dir(), 'git-askpass-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, GitUrl::askPassScript($token, 'github.com'));
        chmod($path, 0700);

        try {
            $command = GitUrl::withAskPass(
                ['git', 'clone', 'https://github.com/org/repo.git', '/srv/project'],
                $path
            );
            $this->assertStringNotContainsString($token, implode(' ', $command));
            $this->assertContains('https://github.com/org/repo.git', $command);

            $process = new Process([$path, "Password for 'https://git@github.com':"]);
            $process->mustRun();
            $this->assertSame($token, $process->getOutput());
        } finally {
            @unlink($path);
        }
    }

    /**
     * A repository whose .gitmodules names another host must not receive the
     * token the parent clone was given.
     */
    public function test_the_askpass_helper_answers_only_its_repository_host(): void
    {
        $token = 's3cret';
        $path = tempnam(sys_get_temp_dir(), 'git-askpass-host-');
        $this->assertNotFalse($path);
        file_put_contents($path, GitUrl::askPassScript($token, 'github.com'));
        chmod($path, 0700);

        try {
            $same = new Process([$path, "Password for 'https://git@github.com':"]);
            $same->mustRun();
            $this->assertSame($token, $same->getOutput());

            foreach (["Password for 'https://evil.example':", "Password for 'https://git@github.com.evil.example':"] as $prompt) {
                $other = new Process([$path, $prompt]);
                $other->run();
                $this->assertNotSame(0, $other->getExitCode(), $prompt);
                $this->assertSame('', $other->getOutput(), $prompt);
            }
        } finally {
            @unlink($path);
        }
    }

    /** Git prompts with the URL as written, and a host name is not case-sensitive. */
    public function test_the_askpass_helper_answers_its_host_in_any_case(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'git-askpass-case-');
        $this->assertNotFalse($path);
        file_put_contents($path, GitUrl::askPassScript('token', GitUrl::hostOf('https://GitHub.com/owner/repo')));
        chmod($path, 0700);

        try {
            $process = new Process([$path, "Password for 'HTTPS://git@GitHub.COM':"]);
            $process->mustRun();
            $this->assertSame('token', $process->getOutput());
        } finally {
            @unlink($path);
        }
    }

    /** A helper rendered without a host gives the token to no prompt. */
    public function test_a_hostless_askpass_helper_answers_nothing(): void
    {
        $this->assertHelperRefuses(GitUrl::askPassScript('token'), "Password for 'https://anywhere.example':");
    }

    /** A prompt whose host cannot be read is refused, never answered. */
    public function test_a_prompt_without_a_readable_host_is_refused(): void
    {
        $script = GitUrl::askPassScript('token', 'github.com');

        foreach (['Password for https://git@github.com:', 'Password:', ''] as $prompt) {
            $this->assertHelperRefuses($script, $prompt);
        }
    }

    private function assertHelperRefuses(string $script, string $prompt): void
    {
        $path = tempnam(sys_get_temp_dir(), 'git-askpass-refuse-');
        $this->assertNotFalse($path);
        file_put_contents($path, $script);
        chmod($path, 0700);

        try {
            $process = new Process([$path, $prompt]);
            $process->run();
            $this->assertNotSame(0, $process->getExitCode(), $prompt);
            $this->assertSame('', $process->getOutput(), $prompt);
        } finally {
            @unlink($path);
        }
    }
}

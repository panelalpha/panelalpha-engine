<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GitUrlTest extends TestCase
{
    public function test_askpass_supplies_token_without_putting_it_in_command_arguments(): void
    {
        $token = "s3cret token'\nsecond-line";
        $path = tempnam(sys_get_temp_dir(), 'git-askpass-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, GitUrl::askPassScript($token));
        chmod($path, 0700);

        try {
            $command = GitUrl::withAskPass(
                ['git', 'clone', 'https://github.com/org/repo.git', '/srv/project'],
                $path
            );
            $this->assertStringNotContainsString($token, implode(' ', $command));
            $this->assertContains('https://github.com/org/repo.git', $command);

            $process = new Process([$path, 'Password for https://git@github.com:']);
            $process->mustRun();
            $this->assertSame($token, $process->getOutput());
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_empty_askpass_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GitUrl::askPassScript('  ');
    }

    public function test_sanitize_strips_credentials(): void
    {
        $this->assertSame(
            'https://github.com/org/repo.git',
            GitUrl::sanitize('https://git:s3cret%20token@github.com/org/repo.git')
        );
    }

    public function test_sanitize_leaves_clean_url(): void
    {
        $this->assertSame(
            'https://github.com/org/repo.git',
            GitUrl::sanitize('https://github.com/org/repo.git')
        );
    }

    #[DataProvider('unsafeTokenUrlProvider')]
    public function test_rejects_token_for_unsafe_repository_url(string $url): void
    {
        $this->assertFalse(GitUrl::isHttpsWithoutCredentials($url));

        $this->expectException(\InvalidArgumentException::class);
        GitUrl::assertSafeForToken($url);
    }

    public static function unsafeTokenUrlProvider(): array
    {
        return [
            'plaintext HTTP' => ['http://git.example.com/org/repo.git'],
            'embedded username' => ['https://user@git.example.com/org/repo.git'],
            'embedded password' => ['https://user:password@git.example.com/org/repo.git'],
            'non-HTTP scheme' => ['ftp://git.example.com/org/repo.git'],
        ];
    }

    public function test_accepts_clean_https_repository_for_token(): void
    {
        $this->assertTrue(
            GitUrl::isHttpsWithoutCredentials('https://git.example.com/org/repo.git')
        );
    }

    // ---- git may never stop and ask a human ----------------------------

    /**
     * The guard the tokenless clone was missing.
     *
     * It used to live only inside withAskPass(), so it applied exactly when a
     * credential had already been supplied and was skipped in the one case
     * that prompts: a private repository with no token.
     */
    public function test_a_command_without_a_token_still_cannot_prompt(): void
    {
        $command = GitUrl::withoutPrompts(['git', 'clone', '--depth=1', 'https://h/o/r.git', '/p']);

        $this->assertSame('env', $command[0]);
        $this->assertContains('GIT_TERMINAL_PROMPT=0', $command);
        $this->assertContains('GIT_ASKPASS=/bin/false', $command);
        $this->assertContains('SSH_ASKPASS=/bin/false', $command);
        // The command itself is untouched and still last.
        $this->assertSame(
            ['git', 'clone', '--depth=1', 'https://h/o/r.git', '/p'],
            array_slice($command, -5)
        );
    }

    /**
     * GIT_TERMINAL_PROMPT alone only closes the terminal -- an askpass helper
     * still launches and can sit there waiting, which is the hang this guards.
     */
    public function test_closing_the_terminal_is_not_enough_on_its_own(): void
    {
        $command = GitUrl::withoutPrompts(['git', 'fetch']);

        $this->assertNotContains(
            'GIT_ASKPASS=',
            $command,
            'an unset GIT_ASKPASS lets a configured helper run'
        );
        $this->assertContains('GIT_ASKPASS=/bin/false', $command);
    }

    /** `env` applies assignments left to right, so the real helper must be last. */
    public function test_the_token_askpass_overrides_the_tokenless_placeholder(): void
    {
        $command = GitUrl::withAskPass(['git', 'fetch'], '/home/u/.pa-askpass');

        $this->assertContains('GIT_TERMINAL_PROMPT=0', $command);
        $this->assertContains('GIT_ASKPASS=/home/u/.pa-askpass', $command);

        $askPass = array_values(array_filter(
            $command,
            static fn (string $arg): bool => str_starts_with($arg, 'GIT_ASKPASS=')
        ));
        $this->assertSame(['GIT_ASKPASS=/home/u/.pa-askpass'], $askPass, 'only the real helper');
    }
}

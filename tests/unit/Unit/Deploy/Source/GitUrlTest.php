<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\GitTransport;
use App\Lib\Deploy\Source\GitUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TestNetwork;

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

    public function test_rejects_empty_askpass_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GitUrl::askPassScript('  ');
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

    #[DataProvider('gitHostProvider')]
    public function test_it_reads_the_host_of_a_remote(string $url, ?string $host): void
    {
        $this->assertSame($host, GitUrl::hostOf($url));
    }

    public static function gitHostProvider(): array
    {
        return [
            'https' => ['https://github.com/o/r.git', 'github.com'],
            'https with port' => ['https://git.example.com:8443/o/r.git', 'git.example.com'],
            'scp-style' => ['git@github.com:o/r.git', 'github.com'],
            'ssh scheme' => ['ssh://git@git.example.com:2222/o/r.git', 'git.example.com'],
            'schemeless' => ['github.com/o/r', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('redirectProvider')]
    public function test_it_reads_the_url_a_git_redirect_was_refused(string $output, ?string $expected): void
    {
        $this->assertSame($expected, GitUrl::redirectedTo($output));
    }

    public static function redirectProvider(): array
    {
        return [
            'url base' => [
                "fatal: unable to update url base from redirection:\n"
                . "  asked for: https://example.com/o/r.git/info/refs?service=git-upload-pack\n"
                . "   redirect: https://evil.example/o/r.git/info/refs?service=git-upload-pack",
                'https://evil.example/o/r.git',
            ],
            'redirecting to' => ["fatal: redirecting to 'https://evil.example/o/r.git'", 'https://evil.example/o/r.git'],
            'not a redirect' => ['fatal: repository not found', null],
            'authentication' => ['fatal: Authentication failed for https://example.com/o/r.git/', null],
        ];
    }

    public function test_the_network_command_is_pinned_and_does_not_follow_redirects(): void
    {
        $command = GitUrl::withResolve(
            GitUrl::withoutRedirects(['git', '-C', '/p', 'fetch', 'origin']),
            'files.example.com:443:' . TestNetwork::routable(34)
        );

        $this->assertSame(
            ['git', '-c', 'http.curloptResolve=files.example.com:443:' . TestNetwork::routable(34), '-c', 'http.followRedirects=false',
                '-C', '/p', 'fetch', 'origin'],
            $command
        );
        $this->assertSame(['git', 'fetch'], GitUrl::withResolve(['git', 'fetch'], null));
    }

    public function test_sanitize_strips_credentials(): void
    {
        $this->assertSame(
            'https://github.com/org/repo.git',
            GitUrl::sanitize('https://git:s3cret%20token@github.com/org/repo.git')
        );
    }

    public function test_sanitize_keeps_an_ssh_user_and_drops_only_its_password(): void
    {
        $this->assertSame(
            'ssh://git@' . TestNetwork::private(44) . ':22277/home/git/r7.git',
            GitUrl::sanitize('ssh://git@' . TestNetwork::private(44) . ':22277/home/git/r7.git')
        );
        $this->assertSame(
            'ssh://deploy@git.example.com/org/repo.git',
            GitUrl::sanitize('ssh://deploy:s3cret@git.example.com/org/repo.git')
        );
        $this->assertSame(
            'https://github.com/org/repo.git',
            GitUrl::sanitize('https://ghp_token@github.com/org/repo.git')
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

    /** Every wrapped command is limited to the transports the engine clones over; ssh only with a key. */
    public function test_every_wrapped_command_carries_the_transport_policy(): void
    {
        $clone = GitUrl::withoutPrompts(['git', 'clone', 'https://h/o/r.git'], GitTransport::schemes());
        $this->assertContains('GIT_ALLOW_PROTOCOL=http:https', $clone);

        $askPass = GitUrl::withAskPass(['git', 'fetch'], '/tmp/ap', GitTransport::schemes());
        $this->assertContains('GIT_ALLOW_PROTOCOL=http:https', $askPass);

        $withSsh = GitUrl::withoutPrompts(['git', 'fetch'], GitTransport::schemes(true));
        $this->assertContains('GIT_ALLOW_PROTOCOL=ssh:http:https', $withSsh);
    }

    /** One helper for the create-time probe and the account's clone and fetches. */
    public function test_the_http11_retry_goes_right_after_git_bare_or_wrapped(): void
    {
        $this->assertTrue(GitUrl::refusedOverHttp2("error: RPC failed\nfatal: Expected flush after ref listing"));
        $this->assertFalse(GitUrl::refusedOverHttp2('fatal: repository not found'));

        $this->assertSame(
            ['git', '-c', 'http.version=HTTP/1.1', '-C', '/p', 'fetch', 'origin'],
            GitUrl::overHttp11(['git', '-C', '/p', 'fetch', 'origin'])
        );
        $wrapped = GitUrl::withAskPass(['git', 'ls-remote', '--', 'https://x.test/r.git'], '/tmp/ap');
        $retried = GitUrl::overHttp11($wrapped);
        $git = array_search('git', $retried, true);
        $this->assertSame(['git', '-c', 'http.version=HTTP/1.1', 'ls-remote'], array_slice($retried, (int) $git, 4));
        $this->assertSame(array_slice($wrapped, 0, (int) $git), array_slice($retried, 0, (int) $git), 'the env wrapper is kept');
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

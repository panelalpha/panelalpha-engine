<?php

namespace App\Lib\Deploy\Source;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Ask the forge whether it will hand a repository to these credentials.
 *
 * Runs before a create spends anything. The clone used to be where "private,
 * and you sent no token" surfaced, by which point a panelalpha.online label
 * had been allocated -- and those are never released.
 *
 * Runs from the core container: the account does not exist yet.
 */
class GitRemoteProbe
{
    // Measured from the core container: github.com 0.4-0.6s idle and under
    // 2.9s with 120 concurrent probes; a slow forge (code.castopod.org)
    // 2.4-13s. A timeout is the forge, not the load, so it gets one more try.
    public const TIMEOUT_SECONDS = 30;

    public const ATTEMPTS = 2;

    /** @var array<string, list<string>> stderr needles, by outcome */
    private const SIGNATURES = [
        'unreachable' => [
            'could not resolve host', 'could not resolve proxy', 'failed to connect',
            'connection refused', 'connection timed out', 'network is unreachable',
            'ssl certificate problem', 'gnutls_handshake', 'empty reply from server',
        ],
        'auth' => [
            'could not read username', 'authentication failed', 'invalid username or password',
            'terminal prompts disabled', 'http basic: access denied', '403 forbidden',
            '401 unauthorized',
        ],
        'missing' => [
            'repository not found', 'not found: did you run git update-server-info',
            'the project you were looking for could not be found',
            'does not appear to be a git repository',
        ],
    ];

    public function __construct(
        private readonly int $timeout = self::TIMEOUT_SECONDS,
        private readonly int $attempts = self::ATTEMPTS,
    ) {
    }

    /**
     * Null when the remote handed over its refs.
     *
     * Engine-side failures return null too: a missing git binary is not the
     * caller's to answer for, and refusing every git create over one would be
     * worse than the late clone error this pre-empts.
     *
     * @return ?array<string, mixed>
     */
    public function problem(
        string $repoField,
        string $repoUrl,
        ?string $token,
        string $tokenField = 'git_token'
    ): ?array {
        return $this->check($repoField, $repoUrl, $token, $tokenField)->problem;
    }

    /** The same probe, telling "the remote answered" apart from "nothing was learned". */
    public function check(
        string $repoField,
        string $repoUrl,
        ?string $token,
        string $tokenField = 'git_token'
    ): GitProbeResult {
        $repoUrl = trim($repoUrl);
        if ($repoUrl === '') {
            return GitProbeResult::unchecked();
        }

        $hasToken = $token !== null && trim($token) !== '';
        $workspace = null;
        $askPass = null;

        try {
            $command = ['git', '-c', 'credential.helper=', '-c', 'core.askpass=',
                'ls-remote', '--heads', '--', $repoUrl];

            if ($hasToken) {
                $workspace = $this->makeWorkspace();
                $askPass = $workspace === null ? null : $this->writeAskPass($workspace, (string) $token);
                if ($askPass === null) {
                    return GitProbeResult::unchecked();
                }
                $command = GitUrl::withAskPass($command, $askPass);
            }

            // Only a timeout is retried: any answer, even a refusal, is final.
            $attempt = 0;
            do {
                $result = $this->run($command);
            } while ($result['timedOut'] && ++$attempt < $this->attempts);

            return $this->interpret($result, $repoField, $repoUrl, $hasToken, $tokenField);
        } catch (\Throwable $e) {
            return GitProbeResult::unchecked();
        } finally {
            if ($askPass !== null) {
                @unlink($askPass);
            }
            if ($workspace !== null) {
                @rmdir($workspace);
            }
        }
    }

    /**
     * @param list<string> $command
     * @return array{timedOut: bool, ok: bool, stderr: string}
     */
    private function run(array $command): array
    {
        // ls-remote needs no repository, and a broken one in the cwd would fail it.
        $process = new Process($command, sys_get_temp_dir(), [
            'GIT_TERMINAL_PROMPT' => '0',
            // The terminal is not the only thing that waits: an askpass
            // program still launches unless it is pointed somewhere harmless.
            'GIT_ASKPASS' => '/bin/false',
            'SSH_ASKPASS' => '/bin/false',
            // These sentences get matched on, so not in the server's language.
            'LC_ALL' => 'C',
            'LANG' => 'C',
        ], null, $this->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return ['timedOut' => true, 'ok' => false, 'stderr' => ''];
        }

        $stderr = trim($process->getErrorOutput() ?: $process->getOutput());

        return [
            // A kill can also land as a signal rather than an exception.
            'timedOut' => $stderr === '' && $process->getExitCode() === null,
            'ok' => $process->isSuccessful(),
            'stderr' => $stderr,
        ];
    }

    /**
     * @param array{timedOut: bool, ok: bool, stderr: string} $result
     */
    private function interpret(
        array $result,
        string $repoField,
        string $repoUrl,
        bool $hasToken,
        string $tokenField
    ): GitProbeResult {
        $host = $this->hostOf($repoUrl);

        // Not `unreachable`: nothing refused the connection, and nothing was
        // learned about the repository -- least of all that it is private.
        if ($result['timedOut']) {
            $tries = $this->attempts > 1 ? " ({$this->attempts} attempts)" : '';

            return GitProbeResult::unchecked($this->problemOf($repoField, 'timeout',
                "{$host} did not answer within {$this->timeout}s{$tries}. This says nothing about whether "
                . 'the repository exists or is public: it was not checked, so nothing was created. '
                . 'Retry; if it keeps timing out, the host is down or slow to reach from this engine.',
                true));
        }
        if ($result['ok']) {
            return GitProbeResult::verified();
        }

        $stderr = strtolower(GitUrl::sanitize($result['stderr']));

        if ($this->matches($stderr, 'unreachable')) {
            return GitProbeResult::unchecked($this->problemOf($repoField, 'unreachable',
                "Could not reach {$host}. The engine must be able to open an HTTPS connection to it.",
                true));
        }

        // A forge will not admit a private repository exists to a caller it
        // has not authenticated, so these two answers must read as one.
        if ($this->matches($stderr, 'auth') || (!$hasToken && $this->matches($stderr, 'missing'))) {
            return GitProbeResult::refused($hasToken
                ? $this->problemOf($tokenField, 'rejected',
                    "The token was refused by {$host}. Check it has not expired and that it "
                    . 'grants read access to this repository.')
                : $this->problemOf($repoField, 'requires_token',
                    'This repository is private, or does not exist. Pass a read token as '
                    . "`{$tokenField}`, or check the address."));
        }
        if ($this->matches($stderr, 'missing')) {
            return GitProbeResult::refused($this->problemOf($repoField, 'not_found',
                "No such repository on {$host}, or the token cannot see it."));
        }

        return GitProbeResult::refused($this->problemOf($repoField, 'unreadable',
            'The repository could not be read: ' . $this->firstLine($result['stderr'])));
    }

    private function matches(string $stderr, string $outcome): bool
    {
        foreach (self::SIGNATURES[$outcome] as $needle) {
            if (str_contains($stderr, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function hostOf(string $repoUrl): string
    {
        $host = parse_url(GitRepoInput::normalise($repoUrl), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'the repository host';
    }

    private function firstLine(string $output): string
    {
        $line = trim(strtok(trim(GitUrl::sanitize($output)), "\n") ?: '');

        return $line === '' ? 'no output from git' : mb_substr($line, 0, 300);
    }

    private function makeWorkspace(): ?string
    {
        $dir = sys_get_temp_dir() . '/pa-probe-' . bin2hex(random_bytes(8));

        return @mkdir($dir, 0700) ? $dir : null;
    }

    private function writeAskPass(string $workspace, string $token): ?string
    {
        $path = $workspace . '/askpass.sh';
        $written = file_put_contents($path, GitUrl::askPassScript($token), LOCK_EX);

        return $written === false || !chmod($path, 0700) ? null : $path;
    }

    /** @return array<string, mixed> */
    private function problemOf(
        string $field,
        string $code,
        string $message,
        bool $retryable = false
    ): array {
        return array_filter([
            'field' => $field,
            'code' => $field . '_' . $code,
            'message' => $message,
            // Resend unchanged -- a different instruction from every other
            // problem this endpoint raises.
            'retryable' => $retryable ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}

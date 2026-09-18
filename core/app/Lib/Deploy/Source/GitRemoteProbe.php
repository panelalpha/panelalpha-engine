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
final class GitRemoteProbe
{
    public const TIMEOUT_SECONDS = 10;

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

    public function __construct(private readonly int $timeout = self::TIMEOUT_SECONDS)
    {
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
        $repoUrl = trim($repoUrl);
        if ($repoUrl === '') {
            return null;
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
                    return null;
                }
                $command = GitUrl::withAskPass($command, $askPass);
            }

            return $this->interpret($this->run($command), $repoField, $repoUrl, $hasToken, $tokenField);
        } catch (\Throwable $e) {
            return null;
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
        $process = new Process($command, null, [
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
     * @return ?array<string, mixed>
     */
    private function interpret(
        array $result,
        string $repoField,
        string $repoUrl,
        bool $hasToken,
        string $tokenField
    ): ?array {
        $host = $this->hostOf($repoUrl);

        if ($result['timedOut']) {
            return $this->problemOf($repoField, 'unreachable',
                "{$host} did not answer within {$this->timeout}s. The repository was not checked, "
                . 'so nothing was created -- retry, or check the host is reachable from this engine.',
                true);
        }
        if ($result['ok']) {
            return null;
        }

        $stderr = strtolower(GitUrl::sanitize($result['stderr']));

        if ($this->matches($stderr, 'unreachable')) {
            return $this->problemOf($repoField, 'unreachable',
                "Could not reach {$host}. The engine must be able to open an HTTPS connection to it.",
                true);
        }

        // A forge will not admit a private repository exists to a caller it
        // has not authenticated, so these two answers must read as one.
        if ($this->matches($stderr, 'auth') || (!$hasToken && $this->matches($stderr, 'missing'))) {
            return $hasToken
                ? $this->problemOf($tokenField, 'rejected',
                    "The token was refused by {$host}. Check it has not expired and that it "
                    . 'grants read access to this repository.')
                : $this->problemOf($repoField, 'requires_token',
                    'This repository is private, or does not exist. Pass a read token as '
                    . "`{$tokenField}`, or check the address.");
        }
        if ($this->matches($stderr, 'missing')) {
            return $this->problemOf($repoField, 'not_found',
                "No such repository on {$host}, or the token cannot see it.");
        }

        return $this->problemOf($repoField, 'unreadable',
            'The repository could not be read: ' . $this->firstLine($result['stderr']));
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

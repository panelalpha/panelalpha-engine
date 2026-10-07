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
        string $tokenField = 'git_token',
        ?string $branch = null,
        string $branchField = 'git_branch'
    ): ?array {
        $branch = $branch === null || trim($branch) === '' ? null : trim($branch);

        // With a branch, the same ls-remote also says whether the remote has
        // it, so a typo is a 422 instead of a deploy that fails at clone.
        return $branch === null
            ? $this->check($repoField, $repoUrl, $token, $tokenField)->problem
            : $this->probe($repoField, $repoUrl, $token, $tokenField, $branch, $branchField)->problem;
    }

    /** The same probe, telling "the remote answered" apart from "nothing was learned". */
    public function check(
        string $repoField,
        string $repoUrl,
        ?string $token,
        string $tokenField = 'git_token'
    ): GitProbeResult {
        return $this->probe($repoField, $repoUrl, $token, $tokenField, null, 'git_branch');
    }

    private function probe(
        string $repoField,
        string $repoUrl,
        ?string $token,
        string $tokenField,
        ?string $branch,
        string $branchField
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
            if ($branch !== null) {
                // Tags too, since the clone's --branch takes one; HEAD for the default.
                $command = ['git', '-c', 'credential.helper=', '-c', 'core.askpass=',
                    'ls-remote', '--symref', '--', $repoUrl, 'HEAD', 'refs/heads/*', 'refs/tags/*'];
            }

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
                $run = $this->run($command);
                // As the account's clone and fetches do ({@see GitUrl::overHttp11()}).
                if (!$run['ok'] && GitUrl::refusedOverHttp2($run['stderr'])) {
                    $run = $this->run(GitUrl::overHttp11($command));
                }
            } while ($run['timedOut'] && ++$attempt < $this->attempts);

            $result = $this->interpret($run, $repoField, $repoUrl, $hasToken, $tokenField);
            if ($branch !== null && $result->outcome === GitProbeResult::VERIFIED) {
                $missing = self::branchProblem($branchField, $branch, $this->lastOutput, $this->hostOf($repoUrl));
                if ($missing !== null) {
                    return GitProbeResult::refused($missing);
                }
            }

            return $result;
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

    /** What the last ls-remote printed: its refs, when it succeeded. */
    private string $lastOutput = '';

    /**
     * @param list<string> $command
     * @return array{timedOut: bool, ok: bool, stderr: string}
     */
    protected function run(array $command): array
    {
        $this->lastOutput = '';

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
        $this->lastOutput = $process->getOutput();

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

    /**
     * Null when `ls-remote --symref` output lists the branch or tag.
     *
     * @return ?array<string, mixed>
     */
    public static function branchProblem(string $field, string $branch, string $refs, string $host): ?array
    {
        $heads = [];
        $tags = [];
        $default = null;
        foreach (preg_split('/\R/', $refs) ?: [] as $line) {
            if (preg_match('#^ref: refs/heads/(\S+)\s+HEAD$#', $line, $m) === 1) {
                $default = $m[1];
            } elseif (preg_match('#^[0-9a-f]{40,64}\s+refs/heads/(\S+)$#', $line, $m) === 1) {
                $heads[] = $m[1];
            } elseif (preg_match('#^[0-9a-f]{40,64}\s+refs/tags/(\S+?)(\^\{\})?$#', $line, $m) === 1) {
                $tags[$m[1]] = true;
            }
        }
        $tags = array_keys($tags);

        if (in_array($branch, $heads, true) || in_array($branch, $tags, true)) {
            return null;
        }

        $suggestion = self::closestRef($branch, $heads, $tags);
        $examples = array_values(array_unique(array_filter([$default, ...array_slice($heads, 0, 10)])));

        $message = $heads === [] && $tags === []
            ? "{$host} lists no branches or tags for this repository, so '{$branch}' cannot be checked out."
            : "{$host} has no branch or tag named '{$branch}'."
                . ($suggestion !== null ? " Did you mean '{$suggestion}'?" : '')
                . ($default !== null ? " The default branch is '{$default}'; omit `{$field}` to use it." : '');

        return array_filter([
            'field' => $field,
            'code' => $field . '_not_found',
            'message' => $message,
            'expected' => 'the name of a branch or tag in the repository',
            'suggestion' => $suggestion,
            'examples' => $examples === [] ? null : $examples,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param list<string> $heads
     * @param list<string> $tags
     */
    public static function closestRef(string $branch, array $heads, array $tags): ?string
    {
        $short = (string) preg_replace('#^refs/(heads|tags)/#', '', $branch);
        $best = null;
        $bestDistance = 3;
        foreach (array_slice([...$heads, ...$tags], 0, 2000) as $ref) {
            if ($ref === $short || strcasecmp($ref, $short) === 0) {
                return $ref;
            }
            $distance = levenshtein(strtolower($short), strtolower($ref));
            if ($distance < $bestDistance) {
                $best = $ref;
                $bestDistance = $distance;
            }
        }

        return $best;
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

<?php

namespace App\Lib\Deploy\Source;

use App\Lib\Git\DeployKey;
use App\Lib\Http\OutboundUrl;
use App\Lib\Net\EgressRefusedException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Ask the forge whether it will hand a repository to these credentials, from core (no account exists yet) and before a create
 * spends anything: found at clone time, "private, and no token" came after a panelalpha.online label, never released, was taken.
 */
class GitRemoteProbe
{
    /** A timeout is a slow forge, not the load, so it gets one more try. */
    public const TIMEOUT_SECONDS = 30;

    public const ATTEMPTS = 2;

    private const ASKPASS_SCRIPT = 'askpass.sh';

    /** Asking where a refused redirect leads is one request; the probe already waited for the forge once. */
    private const REDIRECT_LOOKUP_SECONDS = 10;

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
    ) {}

    /**
     * Null when the remote handed over its refs, or on an engine-side failure (a missing git binary): refusing every git create
     * over one would be worse than the late clone error. With a branch, ls-remote says whether it exists: a typo is a 422, not a failed clone.
     *
     * @param  ?array<string, mixed>  $deployKey  the key an SSH remote is read with, as {@see DeployKey::stored()} holds it
     * @return ?array<string, mixed>
     */
    public function problem(
        string $repoField,
        string $repoUrl,
        ?string $token,
        string $tokenField = 'git_token',
        ?string $branch = null,
        string $branchField = 'git_branch',
        ?array $deployKey = null,
    ): ?array {
        $branch = $branch === null || trim($branch) === '' ? null : trim($branch);
        if ($deployKey !== null && GitRepoInput::isSsh($repoUrl)) {
            return $this->sshProblem($repoField, trim($repoUrl), $deployKey, $branch, $branchField);
        }

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

    /**
     * The branches and tags the remote advertises, listed the way a deploy asks.
     *
     * @throws GitRefsUnavailableException when the remote refuses or cannot be reached
     */
    public function refs(string $repoUrl, ?string $token): GitRefs
    {
        $repoUrl = trim($repoUrl);
        $hasToken = $token !== null && trim($token) !== '';
        $workspace = null;

        try {
            $command = self::refsCommand($repoUrl);
            if ($hasToken) {
                $workspace = $this->makeWorkspace();
                $askPass = $workspace === null ? null : $this->writeAskPass($workspace, (string) $token, $repoUrl);
                if ($askPass === null) {
                    throw new GitRefsUnavailableException(
                        GitRefsUnavailableException::UNREACHABLE,
                        'Could not prepare the Git credential helper.'
                    );
                }
                $command = GitUrl::withAskPass($command, $askPass);
            }

            return $this->interpretRefs($this->runWithRetry($command), $repoUrl, $hasToken);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /** By the script's known path: a write that failed partway leaves a file nobody was handed. */
    private function removeWorkspace(?string $workspace): void
    {
        if ($workspace === null) {
            return;
        }
        @unlink($workspace.'/'.self::ASKPASS_SCRIPT);
        @rmdir($workspace);
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

        try {
            return $this->probeRemote($repoField, $repoUrl, $token, $tokenField, $branch, $branchField);
        } catch (\InvalidArgumentException $e) {
            return GitProbeResult::refused(['field' => $repoField, 'code' => EgressRefusedException::CODE, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return GitProbeResult::unchecked();
        }
    }

    /** The credential helper and its workspace are gone before probe() reads any exception this throws. */
    private function probeRemote(
        string $repoField,
        string $repoUrl,
        ?string $token,
        string $tokenField,
        ?string $branch,
        string $branchField
    ): GitProbeResult {
        $hasToken = $token !== null && trim($token) !== '';
        $workspace = null;

        try {
            $command = self::probeCommand($branch, $repoUrl);
            if ($hasToken) {
                $workspace = $this->makeWorkspace();
                $askPass = $workspace === null ? null : $this->writeAskPass($workspace, (string) $token, $repoUrl);
                if ($askPass === null) {
                    return GitProbeResult::unchecked();
                }
                $command = GitUrl::withAskPass($command, $askPass);
            }

            $result = $this->interpret($this->runWithRetry($command), $repoField, $repoUrl, $hasToken, $tokenField);

            return $this->branchChecked($result, $branch, $branchField, $repoUrl);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException when core may no longer contact the host
     */
    private static function probeCommand(?string $branch, string $repoUrl): array
    {
        return self::pinned(GitUrl::withoutRedirects(self::lsRemoteCommand($repoUrl, $branch !== null)), $repoUrl);
    }

    private function branchChecked(GitProbeResult $result, ?string $branch, string $branchField, string $repoUrl): GitProbeResult
    {
        if ($branch !== null && $result->outcome === GitProbeResult::VERIFIED) {
            $missing = self::branchProblem($branchField, $branch, $this->lastOutput, $this->hostOf($repoUrl));
            if ($missing !== null) {
                return GitProbeResult::refused($missing);
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function lsRemoteCommand(string $repoUrl, bool $withRefs): array
    {
        return $withRefs
            ? self::symrefCommand($repoUrl)
            : ['git', '-c', 'credential.helper=', '-c', 'core.askpass=', 'ls-remote', '--heads', '--', $repoUrl];
    }

    /**
     * An SSH remote read with the deploy key and pinned host keys the clone
     * will use, written to files of the engine's own for the one command.
     *
     * @param  array<string, mixed>  $deployKey
     * @return ?array<string, mixed>
     */
    private function sshProblem(string $repoField, string $repoUrl, array $deployKey, ?string $branch, string $branchField): ?array
    {
        $workspace = $this->makeWorkspace();
        if ($workspace === null) {
            return null;
        }
        $keyPath = $workspace.'/key';
        $knownHostsPath = $workspace.'/known_hosts';

        try {
            if (! self::writePrivate($keyPath, (string) ($deployKey['private_key'] ?? ''))
                || ! self::writePrivate($knownHostsPath, DeployKey::knownHostsOf($deployKey))) {
                return null;
            }
            $command = ['env', GitTransport::assignment(GitTransport::schemes(withSsh: true)), ...self::lsRemoteCommand($repoUrl, $branch !== null)];
            $run = $this->runWithRetry(GitUrl::withSshKey($command, $keyPath, $knownHostsPath));
            $problem = $this->interpretSsh($run, $repoField, $repoUrl);
            if ($problem !== null || $branch === null) {
                return $problem;
            }

            return self::branchProblem($branchField, $branch, $this->lastOutput, (string) GitRepoInput::sshHost($repoUrl));
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($keyPath);
            @unlink($knownHostsPath);
            @rmdir($workspace);
        }
    }

    /**
     * A forge answers a deploy key it does not know and one registered on
     * another repository alike, so both read as the key not being accepted.
     *
     * @param  array{timedOut: bool, ok: bool, stderr: string}  $run
     * @return ?array<string, mixed>
     */
    private function interpretSsh(array $run, string $repoField, string $repoUrl): ?array
    {
        $host = (string) GitRepoInput::sshHost($repoUrl);
        if ($run['timedOut']) {
            return $this->problemOf($repoField, 'timeout', "{$host} did not answer over SSH within {$this->timeout}s. Nothing was created; retry.", true);
        }
        if ($run['ok']) {
            return null;
        }

        $stderr = strtolower(GitUrl::sanitize($run['stderr']));

        return match (true) {
            str_contains($stderr, 'host key verification failed'), str_contains($stderr, 'remote host identification has changed') => $this->problemOf($repoField, 'host_key_changed',
                "{$host} presented an SSH host key that does not match the one pinned on the deploy key. "
                .'If the server really changed its key, create a new deploy key with `host` set to it.'),
            $this->matches($stderr, 'unreachable') => $this->problemOf($repoField, 'unreachable', "Could not reach {$host} over SSH.", true),
            str_contains($stderr, 'permission denied'), $this->matches($stderr, 'auth'), $this->matches($stderr, 'missing') => $this->problemOf('git_deploy_key', 'rejected',
                "{$host} did not accept the deploy key for this repository, or the repository does not exist. "
                .'Add the public key to the repository as a deploy key (read access is enough), then try again.'),
            default => $this->problemOf($repoField, 'unreadable', 'The repository could not be read: '.$this->firstLine($run['stderr'])),
        };
    }

    private static function writePrivate(string $path, string $content): bool
    {
        if ($content === '' || file_put_contents($path, $content, LOCK_EX) === false) {
            return false;
        }

        return chmod($path, 0600);
    }

    /** What the last ls-remote printed: its refs, when it succeeded. */
    private string $lastOutput = '';

    /**
     * Only a timeout is retried: any answer, even a refusal, is final. A cut ref listing is retried over HTTP/1.1, as
     * the account's clone and fetches do ({@see GitUrl::overHttp11()}).
     *
     * @param  list<string>  $command
     * @return array{timedOut: bool, ok: bool, stderr: string}
     */
    private function runWithRetry(array $command): array
    {
        $attempt = 0;
        do {
            $run = $this->run($command);
            if (! $run['ok'] && GitUrl::refusedOverHttp2($run['stderr'])) {
                $run = $this->run(GitUrl::overHttp11($command));
            }
        } while ($run['timedOut'] && ++$attempt < $this->attempts);

        return $run;
    }

    /**
     * Runs outside any repository (a broken one in the cwd fails ls-remote), askpass at /bin/false (one would still launch),
     * in the C locale since its sentences get matched on; a kill can land as a signal rather than an exception.
     *
     * @param  list<string>  $command
     * @return array{timedOut: bool, ok: bool, stderr: string}
     */
    protected function run(array $command): array
    {
        $this->lastOutput = '';

        $process = new Process($command, sys_get_temp_dir(), [
            ...GitTransport::env(GitTransport::schemes()),
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => '/bin/false',
            'SSH_ASKPASS' => '/bin/false',
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
            'timedOut' => $stderr === '' && $process->getExitCode() === null,
            'ok' => $process->isSuccessful(),
            'stderr' => $stderr,
        ];
    }

    /**
     * A timeout is unchecked, not `unreachable`: nothing refused the connection, and nothing was learned about the
     * repository, least of all that it is private.
     *
     * @param  array{timedOut: bool, ok: bool, stderr: string}  $result
     */
    private function interpret(
        array $result,
        string $repoField,
        string $repoUrl,
        bool $hasToken,
        string $tokenField
    ): GitProbeResult {
        $host = $this->hostOf($repoUrl);

        if ($result['timedOut']) {
            $tries = $this->attempts > 1 ? " ({$this->attempts} attempts)" : '';

            return GitProbeResult::unchecked($this->problemOf($repoField, 'timeout',
                "{$host} did not answer within {$this->timeout}s{$tries}. This says nothing about whether "
                .'the repository exists or is public: it was not checked, so nothing was created. '
                .'Retry; if it keeps timing out, the host is down or slow to reach from this engine.',
                true));
        }
        if ($result['ok']) {
            return GitProbeResult::verified();
        }

        return $this->refusal($result, $repoField, $repoUrl, $host, $hasToken, $tokenField);
    }

    /**
     * A forge will not admit a private repository exists to a caller it has not authenticated, so without a token
     * "auth" and "missing" must read as one.
     *
     * @param  array{timedOut: bool, ok: bool, stderr: string}  $result
     */
    private function refusal(
        array $result,
        string $repoField,
        string $repoUrl,
        string $host,
        bool $hasToken,
        string $tokenField
    ): GitProbeResult {
        $stderr = strtolower(GitUrl::sanitize($result['stderr']));

        if ($this->matches($stderr, 'unreachable')) {
            return GitProbeResult::unchecked($this->problemOf($repoField, 'unreachable',
                "Could not reach {$host}. The engine must be able to open an HTTPS connection to it.",
                true));
        }

        if ($this->matches($stderr, 'auth') || (! $hasToken && $this->matches($stderr, 'missing'))) {
            return GitProbeResult::refused($hasToken
                ? $this->problemOf($tokenField, 'rejected',
                    "The token was refused by {$host}. Check it has not expired and that it "
                    .'grants read access to this repository.')
                : $this->problemOf($repoField, 'requires_token',
                    'This repository is private, or does not exist. Send a token that can read it, '
                    .'or check the address.'));
        }
        if ($this->matches($stderr, 'missing')) {
            return GitProbeResult::refused($this->problemOf($repoField, 'not_found',
                "No such repository on {$host}, or the token cannot see it."));
        }

        return GitProbeResult::refused($this->redirectProblem($result['stderr'], $repoField, $repoUrl)
            ?? $this->problemOf($repoField, 'unreadable', 'The repository could not be read: '.$this->firstLine($result['stderr'])));
    }

    /**
     * Redirects are not followed, so a moved repository, or a GitLab address without `.git`,
     * is refused for what it is, with the address to use when the remote names it.
     *
     * @return ?array<string, mixed>
     */
    private function redirectProblem(string $stderr, string $repoField, string $repoUrl): ?array
    {
        if (! GitUrl::refusedRedirect($stderr)) {
            return null;
        }
        $target = GitUrl::redirectedTo($stderr) ?? $this->redirectTarget($repoUrl);

        return $this->problemOf($repoField, 'redirect_not_followed', GitUrl::redirectRefusal(
            $target === null ? null : GitUrl::sanitize($target)
        ));
    }

    /**
     * Where the remote's ref listing redirects, asked once without following it; null when it does
     * not say. Pinned like the probe itself, so the lookup cannot be steered to an internal address.
     */
    private function redirectTarget(string $repoUrl): ?string
    {
        $refs = rtrim($repoUrl, '/').'/info/refs?service=git-upload-pack';
        try {
            $response = Http::withOptions(['allow_redirects' => false] + self::pinOption($repoUrl))
                ->timeout(self::REDIRECT_LOOKUP_SECONDS)
                ->get($refs);
        } catch (\Throwable) {
            return null;
        }
        $location = trim((string) $response->header('Location'));
        if (! $response->redirect() || $location === '') {
            return null;
        }

        return GitUrl::repositoryFromRedirect((string) UriResolver::resolve(new Uri($refs), new Uri($location)));
    }

    /**
     * @return array<string, mixed> curl connects to the address the egress check passed, never resolving the host again
     *
     * @throws \InvalidArgumentException when the host resolves to an address core must not contact
     */
    private static function pinOption(string $repoUrl): array
    {
        $pin = OutboundUrl::pin($repoUrl, GitRepoInput::SCHEMES);

        return $pin === null || $pin === '' ? [] : ['curl' => [CURLOPT_RESOLVE => [$pin]]];
    }

    /**
     * @return list<string>
     *
     * @throws GitRefsUnavailableException when core may no longer contact the host
     */
    private static function refsCommand(string $repoUrl): array
    {
        try {
            return self::pinned(GitUrl::withoutRedirects(self::symrefCommand($repoUrl)), $repoUrl);
        } catch (\InvalidArgumentException $e) {
            throw new GitRefsUnavailableException(GitRefsUnavailableException::UNREACHABLE, $e->getMessage());
        }
    }

    /**
     * Pinned to an address the egress check accepts, resolved in the process that runs git, so a DNS
     * answer that changes after the caller's check cannot steer ls-remote to an internal address.
     *
     * @param  list<string>  $command
     * @return list<string>
     *
     * @throws \InvalidArgumentException when the host no longer resolves, or resolves to an address core must not contact
     */
    private static function pinned(array $command, string $repoUrl): array
    {
        $scheme = strtolower((string) parse_url($repoUrl, PHP_URL_SCHEME));

        return in_array($scheme, GitRepoInput::SCHEMES, true)
            ? GitUrl::withResolve($command, OutboundUrl::pin($repoUrl, GitRepoInput::SCHEMES))
            : $command;
    }

    /** @return list<string> */
    private static function symrefCommand(string $repoUrl): array
    {
        return ['git', '-c', 'credential.helper=', '-c', 'core.askpass=', 'ls-remote', '--symref',
            '--', $repoUrl, 'HEAD', 'refs/heads/*', 'refs/tags/*'];
    }

    /**
     * @param  array{timedOut: bool, ok: bool, stderr: string}  $run
     *
     * @throws GitRefsUnavailableException
     */
    private function interpretRefs(array $run, string $repoUrl, bool $hasToken): GitRefs
    {
        $probe = $this->interpret($run, 'repository', $repoUrl, $hasToken, 'git_token');
        if ($probe->outcome === GitProbeResult::VERIFIED) {
            return GitRefs::parse($this->lastOutput);
        }

        $code = (string) ($probe->problem['code'] ?? '');
        $outcome = match (true) {
            str_ends_with($code, '_not_found') => GitRefsUnavailableException::NOT_FOUND,
            str_ends_with($code, '_rejected'),
            str_ends_with($code, '_requires_token') => GitRefsUnavailableException::AUTHENTICATION_FAILED,
            default => GitRefsUnavailableException::UNREACHABLE,
        };

        throw new GitRefsUnavailableException(
            $outcome,
            (string) ($probe->problem['message'] ?? 'The repository could not be reached.')
        );
    }

    /**
     * Null when `ls-remote --symref` output lists the branch or tag.
     *
     * @return ?array<string, mixed>
     */
    public static function branchProblem(string $field, string $branch, string $refs, string $host): ?array
    {
        $parsed = GitRefs::parse($refs);
        if ($parsed->hasBranch($branch)) {
            return null;
        }

        $heads = $parsed->branches;
        $tags = $parsed->tags;
        $default = $parsed->defaultBranch;
        $suggestion = self::closestRef($branch, $heads, $tags);
        $examples = array_values(array_unique(array_filter([$default, ...array_slice($heads, 0, 10)])));

        $message = $heads === [] && $tags === []
            ? "{$host} lists no branches or tags for this repository, so '{$branch}' cannot be checked out."
            : "{$host} has no branch or tag named '{$branch}'."
                .($suggestion !== null ? " Did you mean '{$suggestion}'?" : '')
                .($default !== null ? " The default branch is '{$default}'; omit `{$field}` to use it." : '');

        return array_filter([
            'field' => $field,
            'code' => $field.'_not_found',
            'message' => $message,
            'expected' => 'the name of a branch or tag in the repository',
            'suggestion' => $suggestion,
            'examples' => $examples === [] ? null : $examples,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param  list<string>  $heads
     * @param  list<string>  $tags
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
        $dir = sys_get_temp_dir().'/pa-probe-'.bin2hex(random_bytes(8));

        return @mkdir($dir, 0700) ? $dir : null;
    }

    protected function writeAskPass(string $workspace, string $token, string $repoUrl): ?string
    {
        $path = $workspace.'/'.self::ASKPASS_SCRIPT;
        $written = file_put_contents($path, GitUrl::askPassScript($token, GitUrl::hostOf($repoUrl)), LOCK_EX);

        return $written === false || ! chmod($path, 0700) ? null : $path;
    }

    /**
     * `retryable` says resend unchanged, an instruction no other problem this endpoint raises gives.
     *
     * @return array<string, mixed>
     */
    private function problemOf(
        string $field,
        string $code,
        string $message,
        bool $retryable = false
    ): array {
        return array_filter([
            'field' => $field,
            'code' => $field.'_'.$code,
            'message' => $message,
            'retryable' => $retryable ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}

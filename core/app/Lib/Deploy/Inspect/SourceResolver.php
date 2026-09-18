<?php

namespace App\Lib\Deploy\Inspect;

use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitUrl;
use Symfony\Component\Process\Process;

/**
 * Turns a repository URL, a directory on this server, a hosting project, or an
 * `owner/repo` host string into a directory AppInspector can read. Git sources clone
 * exactly as the deploy pipeline does (`--depth=1`, askpass token, no prompts).
 */
final class SourceResolver
{
    public const TYPE_GIT = 'git';
    public const TYPE_PATH = 'path';
    public const TYPE_PROJECT = 'project';

    /** @var list<string> */
    public const TYPES = [self::TYPE_GIT, self::TYPE_PATH, self::TYPE_PROJECT];

    /**
     * How long a workspace may sit in the temp root before it is assumed to belong
     * to a request that died. Every clone deletes its own.
     */
    private const STALE_WORKSPACE_SECONDS = 6 * 3600;

    public function __construct(
        private readonly string $tempRoot,
        private readonly int $timeout = 180
    ) {
    }

    /**
     * Which of the three kinds of source this string names, or null when it is
     * none of them.
     *
     * The order is what makes it unambiguous: a hosting username is 3-15 lowercase
     * alphanumerics with no dot or slash, so nothing else can look like one.
     */
    public static function classify(string $source): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        if (str_starts_with($source, '/')) {
            return self::TYPE_PATH;
        }

        if (str_starts_with($source, 'git@') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $source) === 1) {
            return self::TYPE_GIT;
        }

        // github.com/owner/repo — a URL somebody pasted without the scheme.
        if (preg_match('#^[a-z0-9.-]+\.[a-z]{2,}/#i', $source) === 1) {
            return self::TYPE_GIT;
        }

        if (preg_match('/^[a-z][a-z0-9]{2,14}$/', $source) === 1) {
            return self::TYPE_PROJECT;
        }

        return null;
    }

    /** The repository URL a caller meant, with the scheme they left out. */
    public static function normaliseGitUrl(string $source): string
    {
        return GitRepoInput::normalise($source);
    }

    /**
     * The same question POST /projects asks of `git_repo`, from the same
     * place. Carried whole rather than flattened to a sentence, so the
     * suggestion survives.
     *
     * @throws InspectException when the URL is not one this may clone
     */
    public static function assertCloneable(string $repoUrl): void
    {
        $problem = GitRepoInput::problem('source', $repoUrl);
        if ($problem !== null) {
            throw InspectException::ofProblem($problem);
        }
    }

    /**
     * Clone $repoUrl shallow into a temp directory.
     *
     * @throws InspectException
     */
    public function fromGit(string $repoUrl, ?string $branch = null, ?string $token = null): ResolvedSource
    {
        $repoUrl = self::normaliseGitUrl($repoUrl);
        self::assertCloneable($repoUrl);
        if ($token !== null && $token !== '') {
            GitUrl::assertSafeForToken($repoUrl);
        }

        $root = $this->makeTempDir();
        $target = $root . '/repo';

        $command = [
            'git',
            '-c', 'safe.directory=*',
            // A locked keyring or desktop agent is a program that waits, and this
            // request is not allowed to wait on one: a token is the only credential.
            '-c', 'credential.helper=',
            '-c', 'core.askpass=',
            'clone', '--depth=1', '--single-branch', '--no-tags',
        ];
        if ($branch !== null && $branch !== '') {
            $command[] = '--branch';
            $command[] = $branch;
        }
        $command[] = '--';
        $command[] = $repoUrl;
        $command[] = $target;

        $askPass = null;
        try {
            if ($token !== null && $token !== '') {
                $askPass = $root . '/askpass.sh';
                if (file_put_contents($askPass, GitUrl::askPassScript($token), LOCK_EX) === false
                    || !chmod($askPass, 0700)
                ) {
                    throw new InspectException('Could not prepare the Git credential helper.');
                }
                $command = GitUrl::withAskPass($command, $askPass);
            }

            $this->run($command, 'Could not clone the repository', $root);
        } catch (\Throwable $e) {
            (new ResolvedSource(self::TYPE_GIT, $repoUrl, $root, [], $root))->release();
            throw $e;
        } finally {
            if ($askPass !== null) {
                @unlink($askPass);
            }
        }

        return new ResolvedSource(
            self::TYPE_GIT,
            GitUrl::sanitize($repoUrl),
            $target,
            [
                'repository' => GitUrl::sanitize($repoUrl),
                'branch' => $branch ?? $this->gitBranch($target),
                'commit' => $this->gitCommit($target),
            ],
            $root
        );
    }

    /**
     * A path already on this server: one the caller named, or a hosting account's
     * project directory the controller looked up.
     *
     * @throws InspectException
     */
    public function fromDirectory(string $type, string $reference, string $dir): ResolvedSource
    {
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            throw new InspectException("No such directory: {$dir}");
        }
        if (!is_readable($real)) {
            throw new InspectException("Directory is not readable: {$dir}");
        }

        return new ResolvedSource($type, $reference, $real, [
            'repository' => $this->gitRemote($real),
            'branch' => $this->gitBranch($real),
            'commit' => $this->gitCommit($real),
        ]);
    }

    /**
     * $base plus a caller-supplied relative path, refusing anything that
     * climbs out of it.
     *
     * @throws InspectException
     */
    public static function descend(string $base, ?string $subdirectory): string
    {
        $subdirectory = trim((string) $subdirectory, "/ \t\n\r\0\x0B");
        if ($subdirectory === '') {
            return $base;
        }
        if (str_contains($subdirectory, "\0")) {
            throw new InspectException('Invalid directory.');
        }

        $parts = [];
        foreach (explode('/', $subdirectory) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new InspectException('The requested directory must stay inside the source.');
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            return $base;
        }

        $candidate = realpath($base . '/' . implode('/', $parts));
        $root = realpath($base);
        if ($candidate === false || $root === false || !is_dir($candidate)) {
            throw new InspectException('The requested directory does not exist in the source.');
        }
        if ($candidate !== $root && !str_starts_with($candidate, rtrim($root, '/') . '/')) {
            throw new InspectException('The requested directory must stay inside the source.');
        }

        return $candidate;
    }

    /**
     * A caller-supplied path expressed relative to $root.
     *
     * An absolute path inside $root is accepted, because that is how these
     * directories are named in the deploy log and an operator should be able to
     * paste one back. Anything outside is an error, never a clamp.
     *
     * @throws InspectException
     */
    public static function underRoot(string $root, string $path): string
    {
        $root = rtrim($root, '/');
        $path = trim($path);
        if (!str_starts_with($path, '/')) {
            return $path;
        }

        $path = rtrim($path, '/');
        if ($path === $root) {
            return '';
        }
        if (!str_starts_with($path, $root . '/')) {
            throw new InspectException("Path must be inside {$root}.");
        }

        return substr($path, strlen($root) + 1);
    }

    /** $path as seen from $root, or null when it is not under it. */
    public static function relativeTo(string $root, string $path): ?string
    {
        $prefix = rtrim($root, '/') . '/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }
        $relative = trim(substr($path, strlen($prefix)), '/');

        return $relative === '' ? null : $relative;
    }

    private function gitCommit(string $dir): ?string
    {
        return $this->readGit($dir, ['rev-parse', 'HEAD']);
    }

    private function gitBranch(string $dir): ?string
    {
        $branch = $this->readGit($dir, ['rev-parse', '--abbrev-ref', 'HEAD']);

        return $branch === 'HEAD' ? null : $branch;
    }

    private function gitRemote(string $dir): ?string
    {
        $remote = $this->readGit($dir, ['config', '--get', 'remote.origin.url']);

        return $remote === null ? null : GitUrl::sanitize($remote);
    }

    /**
     * A read from a repository that may not be one, so failure is an answer and not
     * an error. `safe.directory=*` is needed because most checkouts here belong to
     * another uid, which git otherwise refuses to read.
     *
     * @param list<string> $args
     */
    private function readGit(string $dir, array $args): ?string
    {
        if (!is_dir($dir . '/.git') && !is_file($dir . '/.git')) {
            return null;
        }

        $process = new Process(
            ['git', '-c', 'safe.directory=*', '-C', $dir, ...$args],
            null,
            ['GIT_TERMINAL_PROMPT' => '0'],
            null,
            10
        );
        try {
            $process->run();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$process->isSuccessful()) {
            return null;
        }
        $out = trim($process->getOutput());

        return $out === '' ? null : $out;
    }

    /**
     * @param list<string> $command
     * @param ?string $redact a path that must not appear in the error message
     * @throws InspectException
     */
    private function run(array $command, string $failure, ?string $redact = null): void
    {
        $process = new Process($command, null, [
            'GIT_TERMINAL_PROMPT' => '0',
            // GIT_TERMINAL_PROMPT only closes the terminal; an askpass program still
            // launches, and on a desktop session that is a GUI dialog nobody answers —
            // the clone then hangs until the timeout. The token path overrides GIT_ASKPASS.
            'GIT_ASKPASS' => '/bin/false',
            'SSH_ASKPASS' => '/bin/false',
            // A repository's LFS media is never what decides its stack, and pulling it
            // would download gigabytes to read a package.json.
            'GIT_LFS_SKIP_SMUDGE' => '1',
            // git speaks the server's language otherwise, and this message is going into
            // an API response that a program may match on.
            'LC_ALL' => 'C',
            'LANG' => 'C',
        ], null, $this->timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new InspectException($failure . ': ' . $e->getMessage(), 0, $e);
        }

        if (!$process->isSuccessful()) {
            $message = self::reason($process->getErrorOutput() ?: $process->getOutput(), $redact);
            throw new InspectException($failure . ($message === '' ? '.' : ': ' . $message));
        }
    }

    /**
     * The one line of git's output worth returning: the `fatal:` one, else the last thing
     * said — not the first, which only announces the clone. Authentication is rewritten
     * rather than quoted, because git's "unable to read askpass response" describes this
     * class's plumbing; on GitHub it is also the answer for a missing repository.
     */
    private static function reason(string $output, ?string $redact): string
    {
        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return '';
        }

        foreach ($lines as $line) {
            if (preg_match('/askpass|could not read (Username|Password)|Authentication failed|terminal prompts disabled/i', $line) === 1) {
                return 'the repository is private or does not exist. '
                    . 'Pass git_token to read a private HTTPS repository.';
            }
        }

        $chosen = $lines[count($lines) - 1];
        foreach ($lines as $line) {
            if (preg_match('/^fatal:/i', $line) === 1) {
                $chosen = $line;
                break;
            }
        }

        // The workspace is this class's business, not the caller's, and it has
        // already been deleted by the time they read this.
        if ($redact !== null && $redact !== '') {
            $chosen = str_replace($redact, '<workspace>', $chosen);
        }

        return mb_substr($chosen, 0, 500);
    }

    /**
     * @throws InspectException
     */
    private function makeTempDir(): string
    {
        $root = rtrim($this->tempRoot, '/');
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new InspectException('Could not create the inspection workspace.');
        }

        $this->sweepStaleWorkspaces($root);

        $dir = $root . '/inspect-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700)) {
            throw new InspectException('Could not create the inspection workspace.');
        }

        return $dir;
    }

    /**
     * Clean up after requests that never finished: the normal path deletes its own
     * workspace in a finally block, so this only finds the leftovers of a php-fpm
     * worker killed mid-clone, which would otherwise stay on disk.
     */
    private function sweepStaleWorkspaces(string $root): void
    {
        $cutoff = time() - self::STALE_WORKSPACE_SECONDS;

        foreach (scandir($root) ?: [] as $entry) {
            if (!str_starts_with($entry, 'inspect-')) {
                continue;
            }
            $path = $root . '/' . $entry;
            if (!is_dir($path) || is_link($path)) {
                continue;
            }
            $modified = @filemtime($path);
            if ($modified !== false && $modified < $cutoff) {
                ResolvedSource::removeTree($path);
            }
        }
    }
}

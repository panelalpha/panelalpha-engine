<?php

namespace App\Lib\Deploy\Inspect;

use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Source\RepositoryAddress;
use Symfony\Component\Process\Process;

/**
 * Turns a repository URL, a directory on this server, a hosting project, or an
 * `owner/repo` host string into a directory AppInspector can read. Git sources clone
 * exactly as the deploy pipeline does (`--depth=1`, askpass token, no prompts).
 */
final class SourceResolver
{
    public const TYPE_GIT = 'git';

    /**
     * A directory already on this server. classify() still recognises one, but
     * it is not in {@see TYPES}: it would read anything core can, /etc included.
     */
    public const TYPE_PATH = 'path';

    public const TYPE_PROJECT = 'project';

    /** The kinds the API and MCP accept. */
    public const TYPES = [self::TYPE_GIT, self::TYPE_PROJECT];

    /** How a git source was read: its GitHub file list, or a clone. */
    public const METHOD_TREE = 'tree';

    public const METHOD_CLONE = 'clone';

    /**
     * How long a workspace may sit in the temp root before it is assumed to belong
     * to a request that died. Every clone deletes its own.
     */
    private const STALE_WORKSPACE_SECONDS = 6 * 3600;

    /** How many levels of nested submodules inspect follows. */
    private const SUBMODULE_LEVELS = 3;

    /**
     * No credential helper or askpass, since a locked keyring is a program that waits and a
     * token is the only credential; no redirects, which could lead a checked host to an internal one.
     */
    private const UNATTENDED_GIT_CONFIG = [
        '-c', 'safe.directory=*',
        '-c', 'credential.helper=',
        '-c', 'core.askpass=',
        '-c', 'http.followRedirects=false',
    ];

    /**
     * GIT_TERMINAL_PROMPT only closes the terminal: an askpass program would still open a
     * dialog nobody answers (the token path overrides GIT_ASKPASS). LFS media never decides
     * a stack; the C locale keeps git's message stable for the API response it ends up in.
     */
    private const UNATTENDED_GIT_ENV = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_ASKPASS' => '/bin/false',
        'SSH_ASKPASS' => '/bin/false',
        'GIT_LFS_SKIP_SMUDGE' => '1',
        'LC_ALL' => 'C',
        'LANG' => 'C',
    ];

    /**
     * @param ?GitHubTree $tree reads a public GitHub repository from its file
     *        list before falling back to a clone; null always clones
     */
    public function __construct(
        private readonly string $tempRoot,
        private readonly int $timeout = 180,
        private readonly ?GitHubTree $tree = null
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

        if (self::namesRepository($source)) {
            return self::TYPE_GIT;
        }

        if (preg_match('/^[a-z][a-z0-9]{2,14}$/', $source) === 1) {
            return self::TYPE_PROJECT;
        }

        return null;
    }

    /**
     * A URL with a scheme or scp-style, one pasted without the scheme
     * (github.com/owner/repo), or `owner/repo`, later refused with the URL it probably means.
     */
    private static function namesRepository(string $source): bool
    {
        return str_starts_with($source, 'git@')
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $source) === 1
            || preg_match('#^[a-z0-9.-]+\.[a-z]{2,}/#i', $source) === 1
            || GitRepoInput::isShorthand($source);
    }

    /** The repository URL a caller meant, with the scheme they left out. */
    public static function normaliseGitUrl(string $source): string
    {
        return GitRepoInput::normalise($source);
    }

    /**
     * The same question POST /projects asks of `git_repo`, from the same
     * place, carried whole so the suggestion survives. The address is checked
     * too: the inspection clone runs in core, whose network reaches the metadata service.
     *
     * @throws InspectException when the URL is not one this may clone
     */
    public static function assertCloneable(string $repoUrl): void
    {
        $problem = GitRepoInput::problem('source', $repoUrl);
        if ($problem !== null) {
            throw InspectException::ofProblem($problem);
        }

        $address = RepositoryAddress::problem($repoUrl);
        if ($address !== null) {
            throw InspectException::ofProblem($address);
        }
    }

    /**
     * Read $repoUrl into a temp directory: from its file list when it is a
     * public GitHub repository, else (or when that fails) as a shallow clone.
     * `meta.method` says which.
     *
     * $subdirectory only narrows which files the file list fetches; the
     * caller still descends into it.
     *
     * @throws InspectException
     */
    public function fromGit(
        string $repoUrl,
        ?string $branch = null,
        ?string $token = null,
        ?string $subdirectory = null
    ): ResolvedSource {
        $repoUrl = self::normaliseGitUrl($repoUrl);
        self::assertCloneable($repoUrl);
        $hasToken = $token !== null && $token !== '';
        if ($hasToken) {
            GitUrl::assertSafeForToken($repoUrl);
        } else {
            $resolved = $this->fromTree($repoUrl, $branch, $subdirectory);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->cloneRepository($repoUrl, $branch, $hasToken ? $token : null);
    }

    /**
     * A shallow clone of $repoUrl with its submodules, the workspace released on failure.
     *
     * @throws InspectException
     */
    private function cloneRepository(string $repoUrl, ?string $branch, ?string $token): ResolvedSource
    {
        $root = $this->makeTempDir();
        $target = $root . '/repo';
        $command = self::cloneCommand($repoUrl, $branch, $target);

        $askPass = null;
        try {
            if ($token !== null) {
                $askPass = $root . '/askpass.sh';
                self::writeAskPass($askPass, $token);
                $command = GitUrl::withAskPass($command, $askPass);
            }

            $this->run($command, 'Could not clone the repository', $root);
            $this->initSubmodules($target, $askPass);
        } catch (\Throwable $e) {
            (new ResolvedSource(self::TYPE_GIT, $repoUrl, $root, [], $root))->release();
            throw $e;
        } finally {
            if ($askPass !== null) {
                @unlink($askPass);
            }
        }

        return $this->clonedSource($repoUrl, $branch, $target, $root);
    }

    /**
     * @return list<string>
     */
    private static function cloneCommand(string $repoUrl, ?string $branch, string $target): array
    {
        $command = ['git', ...self::UNATTENDED_GIT_CONFIG, 'clone', '--depth=1', '--single-branch', '--no-tags'];
        if ($branch !== null && $branch !== '') {
            $command[] = '--branch';
            $command[] = $branch;
        }
        $command[] = '--';
        $command[] = $repoUrl;
        $command[] = $target;

        return $command;
    }

    /**
     * @throws InspectException
     */
    private static function writeAskPass(string $askPass, string $token): void
    {
        if (file_put_contents($askPass, GitUrl::askPassScript($token), LOCK_EX) === false
            || !chmod($askPass, 0700)
        ) {
            throw new InspectException('Could not prepare the Git credential helper.');
        }
    }

    private function clonedSource(string $repoUrl, ?string $branch, string $target, string $root): ResolvedSource
    {
        return new ResolvedSource(
            self::TYPE_GIT,
            GitUrl::sanitize($repoUrl),
            $target,
            [
                'repository' => GitUrl::sanitize($repoUrl),
                'branch' => $branch ?? $this->gitBranch($target),
                'commit' => $this->gitCommit($target),
                'method' => self::METHOD_CLONE,
            ],
            $root
        );
    }

    /**
     * The repository from its file list when it is a GitHub one, or null to clone
     * it instead. A token never gets here: a repository needing one is not readable this way.
     *
     * @throws InspectException
     */
    private function fromTree(string $repoUrl, ?string $branch, ?string $subdirectory): ?ResolvedSource
    {
        if ($this->tree === null || GitHubTree::repository($repoUrl) === null) {
            return null;
        }

        try {
            return $this->readTree($this->tree, $repoUrl, $branch, $subdirectory);
        } catch (TreeUnavailable) {
            return null;
        }
    }

    /**
     * The repository from its file list only, never cloned: for a caller
     * that would rather know nothing than wait for a clone.
     *
     * @throws TreeUnavailable when it cannot be read that way
     * @throws InspectException
     */
    public function fromGitHubTree(string $repoUrl, ?string $branch = null): ResolvedSource
    {
        $repoUrl = self::normaliseGitUrl($repoUrl);
        if ($this->tree === null || GitHubTree::repository($repoUrl) === null) {
            throw new TreeUnavailable('Not a github.com repository URL.');
        }

        return $this->readTree($this->tree, $repoUrl, $branch, null);
    }

    /**
     * @throws TreeUnavailable
     * @throws InspectException
     */
    private function readTree(
        GitHubTree $tree,
        string $repoUrl,
        ?string $branch,
        ?string $subdirectory
    ): ResolvedSource {
        $root = $this->makeTempDir();
        try {
            $meta = $tree->writeTo($root . '/repo', $repoUrl, $branch, $subdirectory);
        } catch (TreeUnavailable $e) {
            ResolvedSource::removeTree($root);

            throw $e;
        }

        return new ResolvedSource(
            self::TYPE_GIT,
            $repoUrl,
            $root . '/repo',
            $meta + ['method' => self::METHOD_TREE],
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
     * Where a hosting project keeps the application's files: `~/project` for a
     * container project, else the main domain's document root for the classic
     * PHP templates, which have no `~/project`. `$relative` overrides the guess.
     * Each is held to the home like a caller's subdirectory: a `~/project` that
     * leaves it is refused, a document root that does (or is missing) is skipped,
     * and the read then reports ~/project missing.
     *
     * @throws InspectException
     */
    public static function projectDirectory(string $home, ?string $documentRoot, ?string $relative): string
    {
        $relative = trim((string) $relative);
        if ($relative !== '') {
            return self::descend($home, self::underRoot($home, $relative));
        }

        $appDir = $home . '/project';
        if (file_exists($appDir) || is_link($appDir)) {
            return self::descend($home, 'project');
        }

        if ($documentRoot !== null) {
            $documentRootDir = self::descendOrNull($home, $documentRoot);
            if ($documentRootDir !== null) {
                return $documentRootDir;
            }
        }

        return $appDir;
    }

    private static function descendOrNull(string $base, string $subdirectory): ?string
    {
        try {
            return self::descend($base, $subdirectory);
        } catch (InspectException) {
            return null;
        }
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

        $parts = self::pathParts($subdirectory);
        if ($parts === []) {
            return $base;
        }

        return self::existingDirectoryInside($base, $parts);
    }

    /**
     * @return list<string>
     * @throws InspectException when a part climbs out with `..`
     */
    private static function pathParts(string $subdirectory): array
    {
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

        return $parts;
    }

    /**
     * The resolved directory $parts names under $base. The stat cache is cleared first:
     * it outlives the request, and a link swapped in since would pass for the directory it replaced.
     *
     * @param list<string> $parts
     * @throws InspectException
     */
    private static function existingDirectoryInside(string $base, array $parts): string
    {
        clearstatcache(true);
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
     * Fetch submodules level by level, the way the deploy path does, after checking every
     * URL git resolved. Best effort: a failure leaves the parent checkout as it was.
     */
    private function initSubmodules(string $target, ?string $askPass, int $level = 0): void
    {
        if ($level > self::SUBMODULE_LEVELS || !is_file($target . '/.gitmodules')) {
            return;
        }

        $git = ['git', ...self::UNATTENDED_GIT_CONFIG, '-C', $target];

        try {
            if (!$this->submoduleUrlsAllowed($git, $target)) {
                return;
            }
            $this->fetchSubmodules($git, $target, $askPass);
            $this->initNestedSubmodules($target, $askPass, $level);
        } catch (InspectException) {
            return;
        }
    }

    /**
     * `submodule init` fetches nothing: it writes each URL into .git/config as the fetch
     * will use it, a relative `../` resolved, so those are what gets checked.
     *
     * @param list<string> $git
     * @throws InspectException
     */
    private function submoduleUrlsAllowed(array $git, string $target): bool
    {
        $this->run([...$git, 'submodule', 'init'], 'Could not read submodules', $target);
        foreach ($this->gitConfigValues($target, '^submodule\..*\.url$') as $url) {
            if (RepositoryAddress::refused($url)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shallow, to match the --depth=1 clone; not --recursive, so each nested level is checked first.
     *
     * @param list<string> $git
     * @throws InspectException
     */
    private function fetchSubmodules(array $git, string $target, ?string $askPass): void
    {
        $command = [...$git, 'submodule', 'update', '--depth=1'];
        if ($askPass !== null) {
            $command = GitUrl::withAskPass($command, $askPass);
        }
        $this->run($command, 'Could not fetch submodules', $target);
    }

    private function initNestedSubmodules(string $target, ?string $askPass, int $level): void
    {
        $root = realpath($target);
        foreach ($this->gitConfigValues($target, '^submodule\..*\.path$', '.gitmodules') as $path) {
            $dir = realpath($target . '/' . $path);
            if ($root !== false && $dir !== false && str_starts_with($dir, $root . '/')) {
                $this->initSubmodules($dir, $askPass, $level + 1);
            }
        }
    }

    /**
     * Values of the config keys matching $pattern, read by git itself, from
     * the checkout's own config or from $file.
     *
     * @return list<string>
     * @throws InspectException when git does not answer in time
     */
    private function gitConfigValues(string $target, string $pattern, ?string $file = null): array
    {
        $command = ['git', '-c', 'safe.directory=*', '-C', $target, 'config', '-z'];
        array_push($command, ...($file === null ? ['--local'] : ['--file', $file]));
        array_push($command, '--get-regexp', $pattern);

        $values = [];
        foreach (explode("\0", $this->configOutput($command)) as $entry) {
            $newline = strpos($entry, "\n");
            if ($newline !== false) {
                $values[] = substr($entry, $newline + 1);
            }
        }

        return $values;
    }

    /**
     * @param list<string> $command
     * @throws InspectException
     */
    private function configOutput(array $command): string
    {
        $process = new Process($command, null, ['LC_ALL' => 'C', 'LANG' => 'C'], null, $this->timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new InspectException('Could not read the submodule configuration.', 0, $e);
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $command
     * @param ?string $redact a path that must not appear in the error message
     * @throws InspectException
     */
    private function run(array $command, string $failure, ?string $redact = null): void
    {
        $process = new Process($command, null, self::UNATTENDED_GIT_ENV, null, $this->timeout);

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
     * said — not the first, which only announces the clone. $redact, the workspace that is
     * already deleted by the time the caller reads this, is replaced by `<workspace>`.
     */
    private static function reason(string $output, ?string $redact): string
    {
        $lines = self::nonEmptyLines($output);
        if ($lines === []) {
            return '';
        }

        if (self::mentionsAuthentication($lines)) {
            return 'the repository is private or does not exist. '
                . 'Pass git_token to read a private HTTPS repository.';
        }

        $chosen = self::fatalOrLastLine($lines);
        if ($redact !== null && $redact !== '') {
            $chosen = str_replace($redact, '<workspace>', $chosen);
        }

        return mb_substr($chosen, 0, 500);
    }

    /**
     * @return list<string>
     */
    private static function nonEmptyLines(string $output): array
    {
        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Authentication is rewritten rather than quoted: git's "unable to read askpass response"
     * describes this class's plumbing, and on GitHub it is also the answer for a missing repository.
     *
     * @param list<string> $lines
     */
    private static function mentionsAuthentication(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/askpass|could not read (Username|Password)|Authentication failed|terminal prompts disabled/i', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param non-empty-list<string> $lines
     */
    private static function fatalOrLastLine(array $lines): string
    {
        foreach ($lines as $line) {
            if (preg_match('/^fatal:/i', $line) === 1) {
                return $line;
            }
        }

        return $lines[count($lines) - 1];
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

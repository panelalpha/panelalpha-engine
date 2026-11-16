<?php

namespace App\System\Project\Git;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\Checkout\InstallLockfiles;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTransport;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Source\SshSubmoduleRewrite;
use App\Lib\Git\DeployKey;
use App\Lib\Git\HostGitVersion;
use App\Lib\Http\OutboundUrl;
use App\Lib\Net\EgressRefusedException;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Privileged\Exchange;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\Ref as GitRef;
use Illuminate\Support\Facades\Log;

/**
 * Everything a git work tree does, however the command is run: App\System\Project\Git (panel API, host or DinD shell)
 * and App\System\Project\Dind\Source\GitRepository (deploy ingest) share it and differ only in the hooks below.
 */
abstract class WorkTree
{
    use FastForwardPull;

    public const string STRATEGY_FF = 'ff';

    public const string STRATEGY_FORCE = 'force';

    public const string STRATEGY_PUSH_FIRST = 'push_first';

    /** HEAD before a pull or push, kept for a person to go back to; the restore uses the commit it read itself. */
    protected const string BACKUP_REF = 'refs/panelalpha/backup';

    /** Set in .git/config while connect() fetches, so other calls can say "wait" rather than "not connected". */
    private const string CONNECTING_KEY = 'panelalpha.connecting';

    /** A marker older than this outlived its request (git's own limit is 600 s per command). */
    private const int CONNECTING_STALE_SECONDS = 1300;

    private const array STRATEGIES = [self::STRATEGY_FF, self::STRATEGY_FORCE, self::STRATEGY_PUSH_FIRST];

    /** Git's words for a remote it could not reach, and for one it reached and would not hand over: a private
     * repository read without a token answers as one that is not there. */
    private const string UNREACHABLE_OUTPUT = '/could not resolve host|could not resolve proxy|failed to connect'
        .'|connection (refused|timed out)|network is unreachable|no route to host|operation timed out/i';

    private const string UNREADABLE_OUTPUT = '/could not read username|could not read password|unable to read askpass'
        .'|terminal prompts disabled|authentication failed|invalid username or (password|token)|http basic: access denied'
        .'|repository not found|not found: did you run git update-server-info|does not appear to be a git repository/i';

    private const array REMOTE_SUBCOMMANDS = ['clone', 'fetch', 'pull', 'push', 'ls-remote', 'submodule'];

    protected string $absolutePath;

    protected string $pathKey;

    private bool $quiet = false;

    /** HEAD's commit before the pull or push under way; null while HEAD has none. */
    private ?string $headBackup = null;

    /** HEAD's commit before a merge or a force reset of the step under way rewrote the work tree; null until then. */
    private ?string $workTreeBackup = null;

    /** The index's tree before the pull or push staged over it, so a refused one puts the staging back; null while HEAD has none. */
    private ?string $indexBackup = null;

    /** The account the work tree belongs to. */
    abstract protected function user(): ModelsUser;

    abstract protected function system(): EngineSystem;

    /** Where GIT_ASKPASS is written, and what pathKey() is measured against. */
    abstract protected function homeDirPath(): string;

    /**
     * Run a fully built argv as the project's user.
     *
     * @param  list<string>  $cmd
     */
    abstract protected function runCommand(array $cmd, int $timeout): string;

    /**
     * runCommand() for a read, whose output stays out of a deploy log open meanwhile.
     *
     * @param  list<string>  $cmd
     */
    abstract protected function runQuietCommand(array $cmd, int $timeout): string;

    /**
     * Whether git runs on the host, whose network reaches internal addresses. A DinD
     * account's own network refuses them, so only a host command is pinned.
     */
    abstract protected function runsOnHost(): bool;

    public function absolutePath(): string
    {
        return $this->absolutePath;
    }

    public function pathKey(): string
    {
        return $this->pathKey;
    }

    /**
     * Whether this checkout has a remote recorded, the Deploy-managed one or a Site Git checkout that `connect` set up:
     * the precondition for anything that pulls from it.
     */
    public function isConnected(): bool
    {
        return $this->user()->getSiteGit($this->pathKey) !== null;
    }

    /** Whether this path is inside a git work tree; never throws, a git that could not run reading as no. */
    public function hasRepository(): bool
    {
        try {
            return $this->repositoryExistsLenient();
        } catch (CouldNotRun) {
            return false;
        }
    }

    /**
     * The checkout's local exclude file, wherever git keeps it, a gitfile's git directory included. Null when this path
     * is not the top of a work tree: no repository, or a directory inside someone else's.
     */
    public function localExcludePath(): ?string
    {
        try {
            $out = $this->git(['rev-parse', '--show-prefix', '--git-path', 'info/exclude']);
        } catch (GitException) {
            return null;
        }

        [$prefixFromTop, $path] = array_pad(explode("\n", str_replace("\r", '', $out)), 2, '');
        $path = trim($path);
        if ($prefixFromTop !== '' || $path === '') {
            return null;
        }

        return self::isAbsolutePath($path) ? $path : $this->absolutePath.'/'.$path;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) === 1;
    }

    /**
     * Whether $relativePath (checkout-relative) differs from what HEAD has. Always false for a path the repository does
     * not track: nothing to compare against, so "no change to revert" is the right reading.
     */
    public function fileDiffersFromHead(string $relativePath): bool
    {
        if ($this->trackedAmong([$relativePath]) === []) {
            return false;
        }

        return trim($this->git(['diff', '--name-only', 'HEAD', '--', $relativePath])) !== '';
    }

    /**
     * Restore $relativePath (checkout-relative) to what HEAD has. The caller confirms first that it is worth doing,
     * with {@see fileDiffersFromHead()}.
     */
    public function restoreFromHead(string $relativePath): void
    {
        $this->git(['checkout', 'HEAD', '--', $relativePath]);
    }

    /** $relativePath's content at HEAD, or null when HEAD has no such path. */
    public function readFromHead(string $relativePath): ?string
    {
        try {
            return $this->git(['show', 'HEAD:'.$relativePath]);
        } catch (GitException) {
            return null;
        }
    }

    /**
     * @param  list<string>  $paths  checkout-relative
     * @return list<string> the ones the repository tracks, as git spells them
     */
    public function trackedAmong(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $out = $this->git(['-c', 'core.quotepath=off', '--literal-pathspecs', 'ls-files', '--', ...$paths]);
        $tracked = [];
        foreach (explode("\n", str_replace("\r", '', $out)) as $line) {
            if ($line !== '') {
                $tracked[] = self::unquotedPath($line);
            }
        }

        return $tracked;
    }

    /** A path as git listed it: names with a quote, backslash or control character come back C-quoted. */
    private static function unquotedPath(string $line): string
    {
        return str_starts_with($line, '"') && str_ends_with($line, '"')
            ? stripcslashes(substr($line, 1, -1))
            : $line;
    }

    /**
     * DinD ~/project deployed from git (git_repo, or a git_connect'd remote):
     * pull/change-branch/revert are followed by a rebuild so the app matches the tree.
     */
    public function isDeployManaged(): bool
    {
        if ($this->pathKey !== 'project') {
            return false;
        }
        if ($this->user()->hasGitProject()) {
            return true;
        }

        return $this->user()->getTemplate() === 'dind'
            && $this->user()->getSiteGit('project') !== null;
    }

    /** A ~/project deployed from git_repo, which only the deploy may let go of. */
    private function isGitRepoProject(): bool
    {
        return $this->pathKey === 'project' && $this->user()->hasGitProject();
    }

    /**
     * Shallow clone into this instance's absolute path, or $into (no -C; clone creates the dir).
     */
    public function clone(string $repoUrl, ?string $branch, ?string $token, ?string $into = null): void
    {
        $target = $into ?? $this->absolutePath;

        $this->assertUrlSafeForToken($repoUrl, $token);

        $cmd = ['git', '-c', 'safe.directory='.$target, 'clone', '--depth=1'];
        if ($branch !== null && $branch !== '') {
            GitRef::assertName($branch);
            $cmd[] = '--branch';
            $cmd[] = $branch;
        }
        $cmd[] = $repoUrl;
        $cmd[] = $target;

        $this->executeOverNetwork($cmd, $token, self::cloneTimeout());
    }

    /** DEPLOY_CLONE_TIMEOUT, in seconds; 600 when unset or not positive. */
    private static function cloneTimeout(): int
    {
        $seconds = app()->bound('config') ? (int) config('deploy.clone_timeout', 600) : 600;

        return $seconds > 0 ? $seconds : 600;
    }

    /** A token is only sent to a URL that cannot leak it; refused as a 422. */
    private function assertUrlSafeForToken(string $repoUrl, ?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }

        try {
            GitUrl::assertSafeForToken($repoUrl);
        } catch (\InvalidArgumentException $e) {
            throw new GitException($e->getMessage(), 422);
        }
    }

    /**
     * Deepen the depth-1 clone to the whole history and every tag, for a build
     * that derives its version from them (`git describe --tags`).
     */
    public function fetchFullHistory(?string $token): void
    {
        $this->requireRepository();

        $shallow = $this->isShallowRepository();
        $this->gitOverNetwork($shallow ? ['fetch', '--unshallow', '--tags', 'origin'] : ['fetch', '--tags', 'origin'], $token);
    }

    /**
     * Fetch the declared submodules at their recorded commits, at depth 1 like the clone, after the clone and every
     * checkout change, so a moved submodule pointer deploys the new commit; required where dependencies are vendored so.
     *
     * @param  ?list<string>  $paths  only these submodules (and theirs, recursively); null for every one
     * @param  bool  $checkout  check each one out even at the commit it already has, as a clone an aborted update left is not
     * @return bool whether submodules were declared and asked for (not whether they all came down)
     *
     * @throws GitException when the checkout has no repository at all
     */
    public function initSubmodules(?string $token = null, ?array $paths = null, bool $checkout = false): bool
    {
        $this->requireRepository();

        if (! is_file($this->absolutePath.'/.gitmodules') || $paths === []) {
            return false;
        }

        $this->executeOverNetwork([
            'git',
            '-c', 'safe.directory='.$this->absolutePath,
            ...$this->submoduleUrlRewrites(),
            '-C', $this->absolutePath,
            'submodule', 'update', '--init', '--recursive', '--depth=1',
            ...($checkout ? ['--force'] : []),
            ...($paths === null ? [] : ['--', ...$paths]),
        ], $token);

        return true;
    }

    /**
     * Without a deploy key ssh has no key to offer, so the submodules addressed over it are read over https.
     *
     * @return list<string>
     */
    private function submoduleUrlRewrites(): array
    {
        if (DeployKey::stored($this->user()) !== null) {
            return [];
        }

        return SshSubmoduleRewrite::configArgs((string) @file_get_contents($this->absolutePath.'/.gitmodules'));
    }

    /**
     * Persist site_git for an already-cloned checkout (deploy ingest).
     * Does not init, fetch, or wipe the work tree.
     */
    public function adoptCheckout(string $repoUrl, ?string $branch, ?string $token): void
    {
        $this->requireRepository();

        if ($branch === null || $branch === '') {
            $branch = $this->currentBranchName();
            if ($branch === '') {
                throw new GitException('Could not determine current branch after clone.', 422);
            }
        }
        GitRef::assertName($branch);

        $this->ensureOriginMatches($repoUrl);
        $this->persistSiteGit($repoUrl, $branch, $token);
        $this->setUpstreamTracking($branch);
        $this->mirrorDeployCredentials($branch, $token);
    }

    public function assertHeadReadable(): void
    {
        $this->git(['rev-parse', 'HEAD']);
    }

    public function readHeadCommit(): ?string
    {
        try {
            $commit = trim($this->git(['rev-parse', 'HEAD']));

            return $commit !== '' ? $commit : null;
        } catch (GitException) {
            return null;
        }
    }

    /** The checked-out branch, null when HEAD is detached or git cannot say. */
    public function readHeadBranch(): ?string
    {
        return $this->quietly(function (): ?string {
            try {
                $branch = $this->currentBranchName();

                return $branch !== '' ? $branch : null;
            } catch (GitException) {
                return null;
            }
        });
    }

    public function status(bool $fetch = false): array
    {
        return $this->quietly(fn (): array => $this->readStatus($fetch));
    }

    /**
     * Runs $read with every git command it makes kept out of the deploy log: a
     * read or a probe is no part of a deploy that happens to be open.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    protected function quietly(callable $read): mixed
    {
        $wasQuiet = $this->quiet;
        $this->quiet = true;
        try {
            return $read();
        } finally {
            $this->quiet = $wasQuiet;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatus(bool $fetch): array
    {
        $connected = $this->user()->getSiteGit($this->pathKey) !== null;

        if (! $this->repositoryExistsLenient()) {
            return $this->missingRepositoryStatus($connected);
        }

        if ($fetch) {
            $this->fetchConnectedBranch($connected);
        }

        return $this->withConnectingState($this->buildStatus($connected));
    }

    /**
     * @return array<string, mixed>
     */
    private function missingRepositoryStatus(bool $connected): array
    {
        return $this->statusEnvelope([
            'connected' => $connected,
            'repository_exists' => false,
            'remote_url' => null,
            'branch' => null,
            'dirty' => false,
            'tracking' => null,
            'commits_ahead' => null,
            'commits_behind' => null,
            'connecting' => false,
            'connecting_since' => null,
        ]);
    }

    private function fetchConnectedBranch(bool $connected): void
    {
        if (! $connected) {
            throw new GitException('Git is not connected.', 422);
        }
        $siteGit = $this->user()->getSiteGit($this->pathKey);
        $this->fetchBranch($siteGit['branch'] ?? '', $siteGit['token'] ?? null);
    }

    /**
     * Adds whether a connect is fetching in another request: connected is
     * still false then, but the caller should wait.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function withConnectingState(array $status): array
    {
        $since = $this->connectingSince();

        return [
            ...$status,
            'connecting' => $since !== null,
            'connecting_since' => $since !== null ? date(DATE_ATOM, $since) : null,
        ];
    }

    /**
     * False when git answers that this is no work tree.
     *
     * @throws CouldNotRun when git could not run at all, which says nothing about the checkout
     */
    private function repositoryExistsLenient(): bool
    {
        return $this->quietly(function (): bool {
            try {
                return trim($this->git(['rev-parse', '--is-inside-work-tree'])) === 'true';
            } catch (CouldNotRun $e) {
                throw $e;
            } catch (GitException) {
                return false;
            }
        });
    }

    /**
     * @return list<array{name: string, current: bool, tracking: ?string}>
     */
    public function branches(): array
    {
        return $this->quietly(fn (): array => $this->readBranches());
    }

    /**
     * @return list<array{name: string, current: bool, tracking: ?string}>
     */
    private function readBranches(): array
    {
        $this->requireRepository();

        $byName = [];
        foreach (explode("\n", $this->localAndOriginRefs()) as $line) {
            $parsed = self::branchFromRefLine($line);
            if ($parsed === null) {
                continue;
            }
            [$branch, $isRemote] = $parsed;
            if (! $isRemote || ! isset($byName[$branch['name']])) {
                $byName[$branch['name']] = $branch;
            }
        }

        foreach ($this->remoteHeads() as $name) {
            $byName[$name] ??= ['name' => $name, 'current' => false, 'tracking' => 'origin/'.$name];
        }

        return array_values($byName);
    }

    /** Local and origin refs, one `for-each-ref` line each. */
    private function localAndOriginRefs(): string
    {
        return trim($this->git([
            'for-each-ref',
            '--format=%(refname)%1f%(refname:short)%1f%(upstream:short)%1f%(HEAD)',
            'refs/heads/',
            'refs/remotes/origin/',
        ]));
    }

    /**
     * One `for-each-ref` line as a branch, and whether it is origin's; null for a HEAD symref or an empty line.
     *
     * @return array{0: array{name: string, current: bool, tracking: ?string}, 1: bool}|null
     */
    private static function branchFromRefLine(string $line): ?array
    {
        [$ref, $short, $upstream, $head] = array_pad(explode("\x1f", $line, 4), 4, '');
        if ($ref === '' || str_ends_with($ref, '/HEAD')) {
            return null;
        }

        $isRemote = str_starts_with($ref, 'refs/remotes/origin/');
        $name = self::branchNameOf($ref, $short);
        if ($name === '' || $name === 'HEAD') {
            return null;
        }

        return [[
            'name' => $name,
            'current' => $head === '*',
            'tracking' => $upstream !== ''
                ? $upstream
                : ($isRemote ? 'origin/'.$name : null),
        ], $isRemote];
    }

    private static function branchNameOf(string $ref, string $short): string
    {
        if (str_starts_with($ref, 'refs/remotes/origin/')) {
            return substr($ref, strlen('refs/remotes/origin/'));
        }
        if (str_starts_with($ref, 'refs/heads/')) {
            return substr($ref, strlen('refs/heads/'));
        }

        return $short;
    }

    /**
     * Every branch the connected remote has: connect and pull fetch one branch only, so the remote-tracking refs never
     * show the others. Empty when not connected or the remote cannot be asked; the local refs still answer.
     *
     * @return list<string>
     */
    private function remoteHeads(): array
    {
        $siteGit = $this->user()->getSiteGit($this->pathKey);
        if ($siteGit === null) {
            return [];
        }

        try {
            $out = $this->gitOverNetwork(['ls-remote', '--heads', 'origin'], $siteGit['token'] ?? null);
        } catch (GitException $e) {
            Log::warning('git_branches: could not list the remote branches', [
                'path' => $this->absolutePath,
                'error' => GitUrl::sanitize($e->getMessage()),
            ]);

            return [];
        }

        return self::headNamesFromLsRemote($out);
    }

    /**
     * @return list<string>
     */
    private static function headNamesFromLsRemote(string $out): array
    {
        $names = [];
        foreach (explode("\n", str_replace("\r", '', $out)) as $line) {
            $ref = trim(explode("\t", $line, 2)[1] ?? '');
            if (str_starts_with($ref, 'refs/heads/') && $ref !== 'refs/heads/') {
                $names[] = substr($ref, strlen('refs/heads/'));
            }
        }

        return $names;
    }

    /**
     * @return list<array{hash: string, short_hash: string, subject: string, author: string, date: string}>
     */
    public function commits(?int $limit = 50, ?string $branch = null): array
    {
        return $this->quietly(fn (): array => $this->readCommits($limit, $branch));
    }

    /**
     * @return list<array{hash: string, short_hash: string, subject: string, author: string, date: string}>
     */
    private function readCommits(?int $limit, ?string $branch): array
    {
        $this->requireRepository();

        $output = trim($this->git(self::logArgs($limit ?? 50, $this->revisionToLog($branch))));
        if ($output === '') {
            return [];
        }

        $commits = [];
        foreach (explode("\n", $output) as $line) {
            if ($line !== '') {
                $commits[] = self::commitFromLogLine($line);
            }
        }

        return $commits;
    }

    /**
     * The branch as the checkout has it, or as its remote-tracking ref: the branch list reads the
     * remote, so a branch the checkout never fetched is fetched here, once, to list it.
     */
    private function revisionToLog(?string $branch): ?string
    {
        if ($branch === null || $branch === '') {
            return null;
        }

        GitRef::assertName($branch);
        foreach ([$branch, 'origin/'.$branch] as $revision) {
            if ($this->commitIsHere($revision)) {
                return $revision;
            }
        }

        return $this->fetchedBranchRevision($branch);
    }

    /**
     * The branch's remote-tracking ref, after fetching it: the remote's own sentence when it has no
     * such branch, a 422 without git's words when it could not be fetched.
     */
    private function fetchedBranchRevision(string $branch): string
    {
        $siteGit = $this->user()->getSiteGit($this->pathKey);
        $token = $siteGit['token'] ?? null;

        try {
            $this->fetchBranch($branch, $token);
        } catch (GitException $e) {
            throw self::remoteLacksBranch($e)
                ? $this->branchNotFound($branch, $token)
                : self::branchNotFetched($branch, $e);
        }

        if ($this->commitIsHere('origin/'.$branch)) {
            return 'origin/'.$branch;
        }

        throw $this->branchNotFound($branch, $token);
    }

    private static function branchNotFetched(string $branch, GitException $e): GitException
    {
        Log::warning('git_commits: could not fetch the branch to list it', [
            'branch' => $branch,
            'error' => GitUrl::sanitize($e->getMessage()),
        ]);

        return new GitException("The branch '{$branch}' could not be fetched from the remote, so this project's "
            .'checkout has no commits of it to list. Check that this server can reach the repository and that the '
            .'stored token still reads it, then try again.', 422, 'git_branch_not_fetched');
    }

    /**
     * The branch goes in as a revision range, not a pathspec, so no `--` before it.
     *
     * @return list<string>
     */
    private static function logArgs(int $limit, ?string $branch): array
    {
        $args = ['log', '-n', (string) $limit, '--format=%H%x1f%h%x1f%s%x1f%an%x1f%aI'];
        if ($branch !== null && $branch !== '') {
            GitRef::assertName($branch);
            $args[] = $branch;
        }

        return $args;
    }

    /**
     * @return array{hash: string, short_hash: string, subject: string, author: string, date: string}
     */
    private static function commitFromLogLine(string $line): array
    {
        [$hash, $shortHash, $subject, $author, $date] = array_pad(explode("\x1f", $line, 5), 5, '');

        return [
            'hash' => $hash,
            'short_hash' => $shortHash,
            'subject' => $subject,
            'author' => $author,
            'date' => $date,
        ];
    }

    public function revert(?string $ref = null): array
    {
        $this->requireRepository();
        $ref = $ref ?? 'HEAD';

        if ($ref !== 'HEAD') {
            GitRef::assertName($ref);
            $this->assertRevertTargetHere($ref);
        } else {
            $this->assertHeadToRevertTo();
        }

        $this->resetHardTo($ref);

        return $this->status();
    }

    /**
     * The checkout is a depth-1 clone, so an earlier commit is fetched before it is refused: going
     * back to one is what revert is for, and the clone never brought its parent down.
     */
    private function assertRevertTargetHere(string $ref): void
    {
        if ($this->commitIsHere($ref)) {
            return;
        }

        $this->fetchRevertTarget($ref);

        if (! $this->commitIsHere($ref)) {
            throw new GitException("No commit named '{$ref}' in this checkout.", 422, 'git_ref_not_found');
        }
    }

    private function commitIsHere(string $ref): bool
    {
        try {
            $this->git(['rev-parse', '--verify', '--quiet', '--end-of-options', $ref.'^{commit}']);

            return true;
        } catch (GitException) {
            return false;
        }
    }

    /**
     * The shallow clone is deepened through the remote: the ref alone first, then the whole branch
     * history for a host that refuses a fetch by a commit it does not advertise.
     */
    private function fetchRevertTarget(string $ref): void
    {
        $siteGit = $this->user()->getSiteGit($this->pathKey);
        if ($siteGit === null || ! $this->isShallowRepository()) {
            return;
        }
        $token = $siteGit['token'] ?? null;

        try {
            $this->gitOverNetwork(['fetch', '--depth=1', 'origin', $ref], $token);
            if ($this->commitIsHere($ref)) {
                return;
            }
        } catch (GitException) {
        }

        $this->fetchFullHistory($token);
    }

    private function isShallowRepository(): bool
    {
        return trim($this->git(['rev-parse', '--is-shallow-repository'])) === 'true';
    }

    private function assertHeadToRevertTo(): void
    {
        try {
            $this->git(['rev-parse', '--verify', 'HEAD']);
        } catch (GitException) {
            throw new GitException('Nothing to revert', 422);
        }
    }

    /** reset --hard to $ref, then drop untracked files. */
    private function resetHardTo(string $ref): void
    {
        $this->git(['reset', '--hard', $ref]);
        $this->git(['clean', '-fd']);
    }

    public function connect(string $repoUrl, string $branch, ?string $token, bool $repair = false): array
    {
        if ($this->isDeployManaged() && ! $repair) {
            return $this->connectDeployManaged($repoUrl, $branch, $token);
        }

        $siteGit = $this->user()->getSiteGit($this->pathKey);
        $repoExists = $this->repositoryExistsLenient();

        if ($repair || ($siteGit !== null && ! $repoExists)) {
            return $this->connectRepair($repoUrl, $branch, $token, $siteGit, $repoExists);
        }

        $token = $this->connectToken($repoUrl, $token, $siteGit['token'] ?? null);
        $this->assertConnectable($repoUrl, $branch, $token);

        $syncError = null;
        if ($repoExists) {
            $syncError = $this->connectExistingRepository($repoUrl, $branch, $siteGit !== null, $token);
        } else {
            $syncError = $this->connectNewRepository($repoUrl, $branch, $token);
        }

        $this->persistSiteGit($repoUrl, $branch, $token);
        if (! $repoExists) {
            $this->clearConnecting();
        }

        return $this->withSyncError($this->status(), $branch, $syncError);
    }

    private function assertConnectable(string $repoUrl, string $branch, ?string $token): void
    {
        if ($branch !== '') {
            GitRef::assertName($branch);
        }
        $this->assertRemoteReadable($repoUrl, $branch, $token);
    }

    /**
     * The token a connect reads the remote with, then fetches and stores: the one sent, an empty one clearing it, or
     * $stored for an HTTPS remote, which no deploy key answers.
     */
    private function connectToken(string $repoUrl, ?string $token, ?string $stored): ?string
    {
        if ($token !== null || GitRepoInput::isSsh($repoUrl)) {
            return $token;
        }

        return $stored;
    }

    /**
     * A checkout already provisioned via git_repo: keep origin in sync and persist,
     * or adopt it again, repair-style, when its .git is missing.
     */
    private function connectDeployManaged(string $repoUrl, string $branch, ?string $token): array
    {
        if (! $this->repositoryExistsLenient()) {
            return $this->repairDeployCheckout($repoUrl, $branch, $token);
        }

        if ($branch !== '') {
            GitRef::assertName($branch);
        } else {
            $branch = $this->user()->getGitBranch() ?? '';
        }
        if ($repoUrl === '') {
            $repoUrl = $this->user()->getGitRepo() ?? '';
        }
        if ($repoUrl === '' || $branch === '') {
            throw new GitException('Git is managed by deploy.', 422);
        }
        $this->assertRemoteReadable($repoUrl, $branch, $this->connectToken($repoUrl, $token, $this->user()->getGitToken()));
        $this->ensureOriginMatches($repoUrl);
        $this->persistSiteGit($repoUrl, $branch, $token ?? $this->user()->getGitToken());
        $this->mirrorDeployCredentials($branch, $token ?? $this->user()->getGitToken());

        return $this->status();
    }

    /** A deploy-managed checkout without .git: adopted again from the stored connection. */
    private function repairDeployCheckout(string $repoUrl, string $branch, ?string $token): array
    {
        $siteGit = $this->user()->getSiteGit($this->pathKey);

        return $this->connectRepair(
            $repoUrl !== '' ? $repoUrl : ($siteGit['repo_url'] ?? ''),
            $branch !== '' ? $branch : ($siteGit['branch'] ?? ''),
            $token ?? ($siteGit['token'] ?? null),
            $siteGit,
            false,
        );
    }

    /**
     * Read the remote's heads before anything is written, so a repository the credentials cannot read, a changed
     * host key and a branch the remote does not have are all refused with nothing connected. No -C: the checkout
     * may not exist yet.
     */
    private function assertRemoteReadable(string $repoUrl, string $branch, ?string $token): void
    {
        $this->assertUrlSafeForToken($repoUrl, $token);

        try {
            $heads = self::headNamesFromLsRemote($this->lsRemoteOutsideCheckout($repoUrl, $token));
        } catch (GitException $e) {
            if ($this->hostGitCannotPin($e)) {
                return;
            }

            throw $this->unreadableRemote($e, $repoUrl, $token !== null && $token !== '');
        }

        if ($branch === '' || $heads === [] || in_array($branch, $heads, true)) {
            return;
        }

        throw self::branchNotFoundAmong($branch, $heads);
    }

    /** `ls-remote` outside the checkout, which a first connect has not made yet; its credentials go in the home. */
    private function lsRemoteOutsideCheckout(string $repoUrl, ?string $token): string
    {
        return $this->executeOverNetwork(['git', 'ls-remote', '--heads', $repoUrl], $token);
    }

    /**
     * A host git older than 2.37 cannot pin a name the operator has not allowed, so the remote is left unchecked
     * here: the connect's own fetch refuses it with that reason, as it always has.
     */
    private function hostGitCannotPin(GitException $e): bool
    {
        return $e->problemCode === EgressRefusedException::CODE
            && ! HostGitVersion::of($this->system())->pinsAddresses();
    }

    /**
     * The remote did not hand over its heads: a 422 saying what to change, in place of git's own words.
     */
    private function unreadableRemote(GitException $e, string $repoUrl, bool $withToken): GitException
    {
        if ($e->httpStatus !== 400) {
            return $e;
        }

        $host = GitUrl::hostOf($repoUrl) ?? 'the repository host';

        if (preg_match(self::UNREACHABLE_OUTPUT, $e->getMessage()) === 1) {
            return new GitException("Could not reach {$host}, so the repository cannot be read and nothing was "
                .'connected. Check the address, and that this server can reach it.', 422, 'git_repository_unreachable');
        }

        if (preg_match(self::UNREADABLE_OUTPUT, $e->getMessage()) === 0) {
            return new GitException('The repository could not be read: '.$e->getMessage(), 422, 'git_repository_unreadable');
        }

        return $withToken
            ? new GitException("The repository could not be read: {$host} refused the token, or it cannot see "
                .'this repository. Nothing was connected.', 422, 'git_token_refused')
            : new GitException("The repository could not be read: {$host} has no repository at this address, or it "
                .'is private and needs a read token. Nothing was connected.', 422, 'git_repository_not_found');
    }

    /**
     * A checkout with no commit is what an interrupted connect leaves behind, and a disconnect keeps it: it is synced
     * like a fresh one, so a retry brings the branch. A checkout that has commits keeps its files untouched.
     *
     * @return ?string why the initial sync failed, null when it did not
     */
    private function connectExistingRepository(string $repoUrl, string $branch, bool $alreadyConnected, ?string $token): ?string
    {
        if (! $alreadyConnected) {
            $this->refuseWhileConnecting();
        }
        $pointUnbornHead = $this->keepCurrentBranch($branch);
        $this->ensureOriginMatches($repoUrl);
        if ($pointUnbornHead) {
            $this->git(['symbolic-ref', 'HEAD', 'refs/heads/'.$branch]);
        }

        if ($this->headCommitExists()) {
            $this->trackOriginBranch($branch);

            return null;
        }

        $syncError = $this->fetchAndSyncFreshInit($branch, $token);
        $this->clearConnecting();

        return $syncError;
    }

    /**
     * @return ?string why the initial sync failed, null when it did not
     */
    private function connectNewRepository(string $repoUrl, string $branch, ?string $token): ?string
    {
        $this->ensureWorkTreeDirectory();
        $this->initRepository($branch, $repoUrl);
        $this->git(['config', self::CONNECTING_KEY, (string) time()]);

        return $this->fetchAndSyncFreshInit($branch, $token);
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function withSyncError(array $status, string $branch, ?string $syncError): array
    {
        if ($syncError !== null) {
            $status['sync_error'] = 'Connected, but fetching '.$branch.' failed, so the work tree has none of '
                ."the repository's files yet. Pull to fetch them. git said: ".$syncError;
        }

        return $status;
    }

    /**
     * Sync a fresh `git init` with origin's branch so connect() alone is enough; depth 1, as a full history can outlive
     * the request. reset --hard spares unrelated untracked files. Best-effort: a failure leaves it connected but empty.
     *
     * @return ?string why the sync failed, null when it did not
     */
    protected function fetchAndSyncFreshInit(string $branch, ?string $token): ?string
    {
        try {
            $this->gitOverNetwork(['fetch', '--depth=1', 'origin', $branch], $token);
            $this->git(['reset', '--hard', 'origin/'.$branch]);
            $this->setUpstreamTracking($branch);

            return null;
        } catch (GitException $e) {
            Log::warning('git_connect: initial fetch/sync failed, repository left empty', [
                'path' => $this->absolutePath,
                'branch' => $branch,
                'error' => $e->getMessage(),
            ]);

            return $e->getMessage();
        }
    }

    /**
     * Connecting leaves an existing work tree on its branch: asked for another, say so rather than store one it is not
     * on. An unborn HEAD has nothing to keep, so true: point it at the branch once every other check passed.
     */
    private function keepCurrentBranch(string $branch): bool
    {
        $current = $this->currentBranchName();
        if ($branch === '' || $current === '' || $current === $branch) {
            return false;
        }

        if (! $this->headCommitExists()) {
            return true;
        }

        throw new GitException(
            "The repository here is on branch {$current}, and connecting keeps its files as they are. "
            ."Connect with branch {$current}, then change the branch to {$branch}.",
            422,
        );
    }

    /** The checked-out branch, '' when HEAD is detached. */
    private function currentBranchName(): string
    {
        return trim($this->git(['branch', '--show-current']));
    }

    /**
     * The connected branch only, as origin/<branch>, which a single-branch clone would leave in FETCH_HEAD. No
     * submodules: an unfetchable one fails the change. A ref the remote lacks yet still lets a push create it.
     *
     * @see initSubmodules() which fetches the submodules afterwards, on the deploy's own terms
     */
    private function fetchBranch(string $branch, ?string $token, bool $allowMissing = false): void
    {
        $fetch = ['-c', 'fetch.recurseSubmodules=false', 'fetch', 'origin'];
        if ($branch === '') {
            $this->gitOverNetwork($fetch, $token);

            return;
        }
        try {
            $this->gitOverNetwork([...$fetch, '+refs/heads/'.$branch.':refs/remotes/origin/'.$branch], $token);
        } catch (GitException $e) {
            if (! $allowMissing || ! self::remoteLacksBranch($e)) {
                throw $e;
            }
            $this->gitOverNetwork($fetch, $token);
        }
    }

    /** The remote has no such branch yet, which an empty repository answers before anything is fetched. */
    private static function remoteLacksBranch(GitException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), "couldn't find remote ref");
    }

    private function refuseWhileConnecting(): void
    {
        if ($this->connectInProgress()) {
            throw new GitException('A git connect is still fetching this repository; try again when it has finished.', 409);
        }
    }

    private function connectInProgress(): bool
    {
        return $this->connectingSince() !== null;
    }

    /** When the running connect started, or null when none is running. */
    private function connectingSince(): ?int
    {
        $since = (int) $this->configValue(self::CONNECTING_KEY);

        return $since > 0 && time() - $since < self::CONNECTING_STALE_SECONDS ? $since : null;
    }

    /** Drops the connecting marker; one already gone is fine. */
    private function clearConnecting(): void
    {
        try {
            $this->git(['config', '--unset', self::CONNECTING_KEY]);
        } catch (GitException) {
        }
    }

    /** Forget the remote. Refused only for a git_repo project: a git_connect'd one can let go. */
    public function disconnect(): array
    {
        if ($this->isGitRepoProject()) {
            throw new GitException('Git is managed by deploy.', 422);
        }

        if ($this->user()->getSiteGit($this->pathKey) === null) {
            throw new GitException('Git is not connected.', 422);
        }

        if ($this->repositoryExistsLenient()) {
            $this->removeOrigin();
        }

        $this->user()->forgetSiteGit($this->pathKey);

        return $this->status();
    }

    /** Best-effort: removes origin when one is configured. */
    private function removeOrigin(): void
    {
        if ($this->configValue('remote.origin.url') === '') {
            return;
        }

        try {
            $this->git(['remote', 'remove', 'origin']);
        } catch (GitException) {
        }
    }

    public function updateCredentials(?string $token, bool $tokenProvided = true): array
    {
        $siteGit = $this->user()->getSiteGit($this->pathKey);
        if ($siteGit === null) {
            throw new GitException('Git is not connected.', 422);
        }

        if (! $tokenProvided) {
            return $this->status();
        }

        $this->saveConnection($siteGit['repo_url'], $siteGit['branch'], $this->normalizeToken($token));

        return $this->statusAfterStoringCredentials();
    }

    /**
     * The credentials are the project's records, stored without git: a container git cannot run in leaves them stored,
     * and the answer says so in a `warning` in place of the checkout's status, not in a 503 that reads as nothing changed.
     *
     * @return array<string, mixed>
     */
    private function statusAfterStoringCredentials(): array
    {
        try {
            return $this->status();
        } catch (CouldNotRun $e) {
            return $this->statusEnvelope([
                'connected' => true,
                'warning' => 'The credentials were stored, but the checkout could not be read: '.GitUrl::sanitize($e->getMessage()),
            ]);
        }
    }

    /**
     * Pull the connected branch with $strategy (ff by default). ff never restores the backup: a refused fast-forward
     * leaves the tree untouched.
     *
     * @see FastForwardPull
     */
    public function pull(?string $strategy = null): array
    {
        $siteGit = $this->requireConnected();
        $this->requireRepository();

        $strategy = $strategy ?? self::STRATEGY_FF;
        self::assertKnownStrategy($strategy);
        $branch = self::connectedBranch($siteGit);
        $token = $siteGit['token'] ?? null;

        $this->fetchBranch($branch, $token);
        $this->createBackup();

        if ($strategy === self::STRATEGY_FF) {
            $this->fastForwardOnly($branch);
        } else {
            $this->restoringBackupOnFailure(fn () => $this->overwriteOrMerge($strategy, $branch, $token));
        }
        $this->setUpstreamTracking($branch);

        return $this->status();
    }

    /** The force strategy takes origin's branch as it is; push_first merges it and pushes back. */
    private function overwriteOrMerge(string $strategy, string $branch, ?string $token): void
    {
        if ($strategy === self::STRATEGY_FORCE) {
            $this->backUpWorkTree();
            $this->resetHardTo('origin/'.$branch);
        } else {
            $this->pullPushFirst($branch, $token);
        }
    }

    public function push(): array
    {
        $this->assertNotDeployManaged('Git is managed by deploy.');

        $siteGit = $this->requireConnected();
        $this->requireRepository();

        $branch = self::connectedBranch($siteGit);
        $token = $siteGit['token'] ?? null;

        $this->fetchBranch($branch, $token, allowMissing: true);

        $this->assertNotBehindUpstream();

        $dirty = $this->hasWorkingTreeChanges();
        $ahead = $this->isAheadOfUpstream();

        if (! $dirty && ! $ahead) {
            return $this->status() + ['nothing_to_push' => true];
        }

        $this->createBackup();
        $this->restoringBackupOnFailure(fn () => $this->pushToOrigin($branch, $token, $dirty));

        return $this->status();
    }

    private function assertNotBehindUpstream(): void
    {
        if ($this->isBehindUpstream()) {
            throw new GitException('Pull first.', 422);
        }
    }

    /**
     * Commits every change first when $commitFirst, then pushes HEAD to origin's $branch.
     *
     * @throws PushRefused when the repository turns the push down
     */
    private function pushToOrigin(string $branch, ?string $token, bool $commitFirst): void
    {
        if ($commitFirst) {
            $this->commitAll();
        }
        try {
            $this->git(['push', 'origin', 'HEAD:'.$branch], $token);
        } catch (GitException $e) {
            throw PushRefused::of($e);
        }
    }

    /** Runs $step, putting the backup back and rethrowing when git fails. */
    private function restoringBackupOnFailure(callable $step): mixed
    {
        try {
            return $step();
        } catch (GitException $e) {
            $this->restoreBackup();
            throw $e;
        }
    }

    /**
     * The remote has no such branch: a 422 naming the closest one it has, as the create-time probe does.
     * Without a suggestion when the remote cannot be listed.
     */
    private function branchNotFound(string $branch, ?string $token): GitException
    {
        return self::branchNotFoundAmong($branch, $this->originHeadsOrNone($token));
    }

    /** @param  list<string>  $heads */
    private static function branchNotFoundAmong(string $branch, array $heads): GitException
    {
        $suggestion = GitRemoteProbe::closestRef($branch, $heads, []);
        $message = "The remote has no branch named '{$branch}'.";
        if ($suggestion !== null) {
            $message .= " Did you mean '{$suggestion}'?";
        } elseif ($heads !== []) {
            $message .= ' Its branches: '.implode(', ', array_slice($heads, 0, 10)).'.';
        }

        return new GitException($message, 422, 'git_branch_not_found');
    }

    /**
     * Origin's branch names, or none when it cannot be listed.
     *
     * @return list<string>
     */
    private function originHeadsOrNone(?string $token): array
    {
        $heads = [];
        try {
            foreach (explode("\n", $this->git(['ls-remote', '--heads', 'origin'], $token)) as $line) {
                if (preg_match('#^[0-9a-f]{40,64}\s+refs/heads/(\S+)$#', trim($line), $m) === 1) {
                    $heads[] = $m[1];
                }
            }
        } catch (GitException) {
        }

        return $heads;
    }

    public function changeBranch(string $branch): array
    {
        $siteGit = $this->requireConnected();
        $this->requireRepository();
        GitRef::assertName($branch);
        $this->assertCleanWorkTree();

        $this->fetchBranchOrExplain($branch, $siteGit['token'] ?? null);
        $this->assertOriginHasBranch($branch);

        $this->addToOriginFetch($branch);
        $this->git(['checkout', '-B', $branch, 'origin/'.$branch]);
        $this->setUpstreamTracking($branch);

        $this->saveConnection($siteGit['repo_url'], $branch, $siteGit['token']);

        return $this->status();
    }

    /** fetchBranch(), with a missing remote branch turned into {@see branchNotFound()}. */
    private function fetchBranchOrExplain(string $branch, ?string $token): void
    {
        try {
            $this->fetchBranch($branch, $token);
        } catch (GitException $e) {
            if (stripos($e->getMessage(), "couldn't find remote ref") === false) {
                throw $e;
            }
            throw $this->branchNotFound($branch, $token);
        }
    }

    private function assertOriginHasBranch(string $branch): void
    {
        try {
            $this->git(['rev-parse', '--verify', '--end-of-options', 'origin/'.$branch]);
        } catch (GitException) {
            throw new GitException('Branch does not exist on remote.', 422);
        }
    }

    /**
     * What pull() refuses before it fetches, and the remote answering with the
     * connected branch on it. Reads only: the checkout is not touched.
     */
    public function assertPullable(?string $strategy = null): void
    {
        $siteGit = $this->requireConnected();
        $this->requireRepository();

        self::assertKnownStrategy($strategy ?? self::STRATEGY_FF);
        $branch = self::connectedBranch($siteGit);

        $this->assertRemoteHasBranch($branch, $siteGit['token'] ?? null);
    }

    /** What changeBranch() refuses, the remote's branches included, without touching the checkout. */
    public function assertBranchChangeable(string $branch): void
    {
        $siteGit = $this->requireConnected();
        $this->requireRepository();
        GitRef::assertName($branch);
        $this->assertCleanWorkTree();

        $this->assertRemoteHasBranch($branch, $siteGit['token'] ?? null);
    }

    /** What revert() refuses, and a `$ref` that names no commit here, without resetting the work tree. */
    public function assertRevertable(?string $ref = null): void
    {
        $this->requireRepository();
        $ref = $ref ?? 'HEAD';
        if ($ref !== 'HEAD') {
            GitRef::assertName($ref);
            $this->assertRevertTargetHere($ref);

            return;
        }

        try {
            $this->git(['rev-parse', '--verify', '--quiet', '--end-of-options', $ref.'^{commit}']);
        } catch (GitException) {
            throw new GitException('Nothing to revert', 422);
        }
    }

    private static function assertKnownStrategy(string $strategy): void
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new GitException('Unknown pull strategy.', 422);
        }
    }

    /**
     * @param  array{repo_url: string, branch: string, token: ?string}  $siteGit
     */
    private static function connectedBranch(array $siteGit): string
    {
        $branch = $siteGit['branch'];
        if ($branch === '') {
            throw new GitException('Connected branch is empty.', 422);
        }
        GitRef::assertName($branch);

        return $branch;
    }

    private function assertCleanWorkTree(): void
    {
        if ($this->hasWorkingTreeChanges()) {
            throw new GitException('Working tree is dirty.', 422);
        }
    }

    /** One round trip: the remote answers, and has `$branch`. */
    private function assertRemoteHasBranch(string $branch, ?string $token): void
    {
        $heads = $this->gitOverNetwork(['ls-remote', '--heads', 'origin', 'refs/heads/'.$branch], $token);
        if (preg_match('#\srefs/heads/'.preg_quote($branch, '#').'$#m', $heads) !== 1) {
            throw $this->branchNotFound($branch, $token);
        }
    }

    /**
     * A single-branch clone's origin fetches its own branch only, and git neither sets nor resolves an upstream outside
     * that refspec. Added only once the branch is known to exist: a refspec naming a missing one fails every later fetch.
     */
    private function addToOriginFetch(string $branch): void
    {
        try {
            $specs = preg_split('/\R/', trim($this->git(['config', '--get-all', 'remote.origin.fetch']))) ?: [];
        } catch (GitException) {
            $specs = [];
        }
        foreach ($specs as $spec) {
            if (in_array(ltrim(trim($spec), '+'), ['refs/heads/*:refs/remotes/origin/*', "refs/heads/{$branch}:refs/remotes/origin/{$branch}"], true)) {
                return;
            }
        }

        $this->git(['remote', 'set-branches', '--add', 'origin', $branch]);
    }

    private function assertNotDeployManaged(string $message): void
    {
        if ($this->isDeployManaged()) {
            throw new GitException($message, 422);
        }
    }

    /**
     * @return array{repo_url: string, branch: string, token: ?string}
     */
    private function requireConnected(): array
    {
        $siteGit = $this->user()->getSiteGit($this->pathKey);
        if ($siteGit === null) {
            $this->refuseWhileConnecting();
            throw new GitException('Git is not connected.', 422);
        }

        return $siteGit;
    }

    private function createBackup(): void
    {
        $this->headBackup = $this->headCommit();
        $this->workTreeBackup = null;
        $this->indexBackup = trim($this->git(['write-tree']));
        if ($this->headBackup !== null) {
            $this->git(['update-ref', self::BACKUP_REF, $this->headBackup]);
        }
    }

    /**
     * HEAD's commit just before a merge or a force reset rewrites the work tree, for restoreBackup(). A HEAD that
     * cannot be read stops the step before the rewrite; one that had no commit leaves nothing to put back.
     */
    private function backUpWorkTree(): void
    {
        $this->workTreeBackup = $this->headBackup === null ? null : trim($this->git(['rev-parse', '--verify', 'HEAD']));
    }

    private function headCommitExists(): bool
    {
        return $this->headCommit() !== null;
    }

    private function headCommit(): ?string
    {
        try {
            $commit = trim($this->git(['rev-parse', '--verify', 'HEAD']));

            return $commit !== '' ? $commit : null;
        } catch (GitException) {
            return null;
        }
    }

    /**
     * A disconnect removes origin and the branch's upstream with it, so the checkout reported no tracking, ahead or
     * behind until a branch change. Set as config, since origin/<branch> exists only after the next fetch.
     */
    private function trackOriginBranch(string $branch): void
    {
        if ($branch === '' || $this->currentBranchName() !== $branch) {
            return;
        }
        if ($this->configValue('branch.'.$branch.'.merge') !== '') {
            return;
        }
        $this->git(['config', 'branch.'.$branch.'.remote', 'origin']);
        $this->git(['config', 'branch.'.$branch.'.merge', 'refs/heads/'.$branch]);
    }

    /** Best-effort: origin/<branch> may not exist yet. */
    private function setUpstreamTracking(string $branch): void
    {
        try {
            $this->git(['rev-parse', '--verify', '--end-of-options', 'origin/'.$branch]);
            $this->git(['branch', '--set-upstream-to=origin/'.$branch, $branch]);
        } catch (GitException) {
        }
    }

    /**
     * Best-effort. Puts back the work tree (`reset --keep`, so a local change is never overwritten), then HEAD and the
     * index; a sync commit's changes stay in the files, uncommitted, and a checkout with no commit has none again.
     */
    private function restoreBackup(): void
    {
        try {
            if ($this->workTreeBackup !== null) {
                $this->git(['reset', '--keep', $this->workTreeBackup]);
            }
            if ($this->headBackup !== null) {
                $this->git(['reset', '--quiet', $this->headBackup]);
            } elseif ($this->headCommitExists()) {
                $this->git(['update-ref', '-d', 'HEAD']);
            }
            if ($this->indexBackup !== null) {
                $this->git(['read-tree', $this->indexBackup]);
            }
        } catch (GitException) {
        }
    }

    private function hasWorkingTreeChanges(): bool
    {
        return trim($this->git(['status', '--porcelain'])) !== '';
    }

    private function isBehindUpstream(): bool
    {
        $behind = $this->upstreamCount('HEAD..@{upstream}');

        return $behind !== null && $behind > 0;
    }

    private function isAheadOfUpstream(): bool
    {
        $ahead = $this->upstreamCount('@{upstream}..HEAD');

        return $ahead !== null && $ahead > 0;
    }

    /** Commits in $range against the upstream, null when there is no upstream. */
    private function upstreamCount(string $range): ?int
    {
        try {
            $upstream = trim($this->git(['rev-parse', '--abbrev-ref', '@{upstream}']));
            if ($upstream === '') {
                return null;
            }

            return (int) trim($this->git(['rev-list', '--count', $range]));
        } catch (GitException) {
            return null;
        }
    }

    /**
     * Commit local changes, merge origin's branch, and push whatever origin lacks. A write that lands after the merge
     * is left uncommitted, so a refused push that resets to before the merge keeps it rather than reverting a commit.
     */
    private function pullPushFirst(string $branch, ?string $token): void
    {
        if ($this->hasWorkingTreeChanges()) {
            $this->commitAll();
        }

        if ($this->isBehindUpstream()) {
            $this->backUpWorkTree();
            $this->mergeOrigin($branch);
        }

        if ($this->isAheadOfUpstream()) {
            $this->pushToOrigin($branch, $token, false);
        }
    }

    private function mergeOrigin(string $branch): void
    {
        try {
            $this->git(['merge', 'origin/'.$branch]);
        } catch (GitException $e) {
            throw $this->abortedMerge($e);
        }
    }

    /** Aborts the failed merge and says why it failed: unrelated histories, the conflicting paths, or git's own words. */
    private function abortedMerge(GitException $failure): GitException
    {
        if (str_contains(strtolower($failure->getMessage()), 'unrelated histories')) {
            $this->abortMerge();

            return new GitException('Unrelated git histories.', 422);
        }

        $unmerged = $this->unmergedPaths();
        $this->abortMerge();

        return $unmerged !== ''
            ? new GitException('Merge conflict: '.str_replace("\n", ', ', $unmerged), 422)
            : $failure;
    }

    /** Newline-separated, '' when git cannot list them. */
    private function unmergedPaths(): string
    {
        try {
            return trim($this->git(['diff', '--name-only', '--diff-filter=U']));
        } catch (GitException) {
            return '';
        }
    }

    /** Best-effort `merge --abort`. */
    private function abortMerge(): void
    {
        try {
            $this->git(['merge', '--abort']);
        } catch (GitException) {
        }
    }

    /**
     * Commit local changes, never the lockfile an install generated in a checkout that shipped none: that file is the
     * engine's own output, and a checkout with nothing else staged is left uncommitted.
     */
    private function commitAll(): void
    {
        $untracked = $this->untrackedInstallLockfiles();

        $this->git(['add', '-A']);
        if ($untracked !== []) {
            $this->git(['--literal-pathspecs', 'reset', '--quiet', '--', ...$untracked]);
        }

        if (trim($this->git(['-c', 'core.quotepath=off', 'diff', '--cached', '--name-only'])) === '') {
            return;
        }

        [$name, $email] = $this->commitIdentity();

        $this->git([
            '-c', 'user.name='.$name,
            '-c', 'user.email='.$email,
            'commit', '-m', 'Sync from PanelAlpha',
        ]);
    }

    /**
     * What an install wrote into a checkout that shipped no lockfile, read before staging makes it the client's.
     *
     * @see InstallLockfiles
     *
     * @return list<string> checkout-relative
     */
    private function untrackedInstallLockfiles(): array
    {
        $candidates = InstallLockfiles::candidates($this->absolutePath);

        return array_values(array_diff($candidates, $this->trackedAmong($candidates)));
    }

    /**
     * The repository's configured identity, or one derived from the account.
     *
     * @return array{0: string, 1: string}
     */
    private function commitIdentity(): array
    {
        $name = $this->configValue('user.name');
        $email = $name !== '' ? $this->configValue('user.email') : '';

        if ($name !== '' && $email !== '') {
            return [$name, $email];
        }

        $username = $this->user()->username;
        $domain = is_string($this->user()->domain) && $this->user()->domain !== ''
            ? $this->user()->domain
            : 'localhost';

        return [$username, $username.'@'.$domain];
    }

    /**
     * Re-create a missing .git from the stored connection. The remote is probed before .git is created, so a failed
     * probe cannot leave a half-initialized repository that blocks the next repair.
     *
     * @param  array{repo_url: string, branch: string, token: ?string}|null  $siteGit
     */
    private function connectRepair(
        string $repoUrl,
        string $branch,
        ?string $token,
        ?array $siteGit,
        bool $repoExists,
    ): array {
        $this->assertRepairable($siteGit, $repoExists);

        if ($siteGit !== null) {
            $repoUrl = $repoUrl !== '' ? $repoUrl : $siteGit['repo_url'];
            $branch = $branch !== '' ? $branch : $siteGit['branch'];
            $token = $token ?? $siteGit['token'];
        }
        if ($repoUrl === '' || $branch === '') {
            throw new GitException('Git is not connected.', 422);
        }
        GitRef::assertName($branch);

        $this->assertRemoteReadable($repoUrl, $branch, $token);
        $this->initRepositoryOrRemoveIt($branch, $repoUrl);

        $this->persistSiteGit($repoUrl, $branch, $token);
        $this->mirrorDeployCredentials($branch, $token);

        return $this->status();
    }

    /**
     * @param  array{repo_url: string, branch: string, token: ?string}|null  $siteGit
     */
    private function assertRepairable(?array $siteGit, bool $repoExists): void
    {
        if ($siteGit === null && ! $this->isDeployManaged()) {
            $this->refuseWhileConnecting();
            throw new GitException('Git is not connected.', 422);
        }
        if ($repoExists) {
            throw new GitException('Local git repository already exists.', 422);
        }
    }

    private function initRepositoryOrRemoveIt(string $branch, string $repoUrl): void
    {
        $this->ensureWorkTreeDirectory();
        try {
            $this->initRepository($branch, $repoUrl);
        } catch (GitException $e) {
            $this->removeCreatedGitDir();
            throw $e;
        }
    }

    protected function ensureWorkTreeDirectory(): void
    {
        $system = $this->system();
        $system->exec(Privileged::files()->makeDirectories($this->absolutePath));
        $chown = $this->user()->getChownString();
        if ($chown) {
            $system->exec(Privileged::files()->changeOwner($chown, $this->absolutePath));
        }
    }

    private function initRepository(string $branch, string $repoUrl): void
    {
        $this->git(['init']);
        $this->git(['symbolic-ref', 'HEAD', 'refs/heads/'.$branch]);
        $this->git(['remote', 'add', 'origin', $repoUrl]);
    }

    /**
     * Best-effort removal of a .git we created during a failed repair init.
     * Does not touch the work tree contents.
     */
    protected function removeCreatedGitDir(): void
    {
        $gitDir = rtrim($this->absolutePath, '/').'/.git';
        try {
            $this->system()->exec(Privileged::files()->removeTree($gitDir));
        } catch (\Throwable) {
        }
    }

    private function ensureOriginMatches(string $repoUrl): void
    {
        $originUrl = $this->configValue('remote.origin.url');

        if ($originUrl === '') {
            $this->git(['remote', 'add', 'origin', $repoUrl]);

            return;
        }

        if (! $this->urlsEquivalent($originUrl, $repoUrl)) {
            throw new GitException('Remote URL does not match the existing origin.', 422);
        }

        $this->git(['remote', 'set-url', 'origin', $repoUrl]);
    }

    /** A git config value, '' when it is not set. */
    private function configValue(string $key): string
    {
        try {
            return trim($this->git(['config', '--get', $key]));
        } catch (GitException) {
            return '';
        }
    }

    private function persistSiteGit(string $repoUrl, string $branch, ?string $token): void
    {
        $this->user()->putSiteGit($this->pathKey, [
            'repo_url' => GitUrl::sanitize($repoUrl),
            'branch' => $branch,
            'token' => $this->normalizeToken($token),
        ]);
    }

    /** Store the connection as given, mirrored into the deploy's details when it manages the checkout. */
    private function saveConnection(string $repoUrl, string $branch, ?string $token): void
    {
        $this->user()->putSiteGit($this->pathKey, [
            'repo_url' => $repoUrl,
            'branch' => $branch,
            'token' => $token,
        ]);
        $this->mirrorDeployCredentials($branch, $token);
    }

    /** A cleared token is stored as '' so the deploy's clone stops using a stale one. */
    private function mirrorDeployCredentials(string $branch, ?string $token): void
    {
        if (! $this->isDeployManaged()) {
            return;
        }

        $this->user()->setDetails([
            'git_branch' => $branch,
            'git_token' => $this->normalizeToken($token) ?? '',
        ]);
        if ($this->user()->exists) {
            $this->user()->save();
        }
    }

    private function normalizeToken(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        return $token;
    }

    private function urlsEquivalent(string $a, string $b): bool
    {
        return $this->normalizeRepoUrl($a) === $this->normalizeRepoUrl($b);
    }

    private function normalizeRepoUrl(string $url): string
    {
        $url = GitUrl::sanitize($url);
        $url = rtrim($url, '/');
        if (str_ends_with($url, '.git')) {
            $url = substr($url, 0, -4);
        }

        return $url;
    }

    /**
     * @param  list<string>  $args
     */
    protected function git(array $args, ?string $token = null): string
    {
        return $this->execute(
            ['git', '-c', 'safe.directory='.$this->absolutePath, '-C', $this->absolutePath, ...$args],
            $token,
        );
    }

    /**
     * @param  list<string>  $args
     */
    private function gitOverNetwork(array $args, ?string $token = null): string
    {
        return $this->executeOverNetwork(
            ['git', '-c', 'safe.directory='.$this->absolutePath, '-C', $this->absolutePath, ...$args],
            $token,
        );
    }

    /**
     * execute() for a command that talks to the remote. GitHub's edge at times refuses older git's HTTP/2 fingerprint
     * once the refs are listed, and lets the same request through over HTTP/1.1, so that one failure is retried so.
     *
     * @param  list<string>  $gitCommand  full argv starting with `git`
     */
    private function executeOverNetwork(array $gitCommand, ?string $token, int $timeout = 600): string
    {
        try {
            return $this->execute($gitCommand, $token, $timeout);
        } catch (GitException $e) {
            if (! GitUrl::refusedOverHttp2($e->getMessage())) {
                throw $e;
            }

            return $this->execute(GitUrl::overHttp11($gitCommand), $token, $timeout);
        }
    }

    /**
     * Run a git argv as the project's user, with its credentials installed for
     * the one command, and every failure turned into a GitException.
     *
     * @param  list<string>  $gitCommand  full argv starting with `git`
     */
    protected function execute(array $gitCommand, ?string $token = null, int $timeout = 600): string
    {
        $system = $this->system();
        $key = null;

        try {
            CredentialFiles::sweep($system, $this->credentialDir($gitCommand));
            $key = $this->deployKeyFor($gitCommand);

            return $this->runWithCredentials($system, $gitCommand, $token, $key, $timeout);
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (GitException $e) {
            throw $e;
        } catch (DockerErrorException $e) {
            throw CouldNotRun::inContainer(GitUrl::sanitize($e->getMessage()), $e->isContainerUnavailable());
        } catch (\InvalidArgumentException $e) {
            throw new GitException($e->getMessage(), 422);
        } catch (\Throwable $e) {
            throw self::failure(GitUrl::sanitize($e->getMessage()), $key !== null);
        }
    }

    /**
     * The deploy key goes along on every command that talks to a remote; an HTTPS remote ignores it.
     *
     * @param  list<string>  $gitCommand
     * @return array<string, mixed>|null
     */
    private function deployKeyFor(array $gitCommand): ?array
    {
        return self::talksToRemote($gitCommand) ? DeployKey::stored($this->user()) : null;
    }

    /**
     * Run $gitCommand with GIT_ASKPASS for a token and the deploy key installed, both
     * removed afterwards. Prompts are off either way: the tokenless case is the one that prompts.
     *
     * @param  list<string>  $gitCommand
     * @param  array<string, mixed>|null  $key
     */
    private function runWithCredentials(EngineSystem $system, array $gitCommand, ?string $token, ?array $key, int $timeout): string
    {
        $askPass = null;
        $sshFiles = [];

        try {
            $command = $this->outboundCommand($gitCommand);
            $protocols = GitTransport::schemes($key !== null);
            $cmd = GitUrl::withoutPrompts($command, $protocols);
            if ($token !== null && $token !== '') {
                $askPass = $this->installAskPass($system, $token, $command);
                $cmd = GitUrl::withAskPass($command, $askPass, $protocols);
            }
            if ($key !== null) {
                $sshFiles = $this->installSshKey($system, $key['private_key'], $command);
                $cmd = GitUrl::withSshKey($cmd, $sshFiles[0], $sshFiles[1]);
            }

            return $this->quiet ? $this->runQuietCommand($cmd, $timeout) : $this->runCommand($cmd, $timeout);
        } finally {
            foreach ([$askPass, ...$sshFiles] as $path) {
                if ($path !== null) {
                    CredentialFiles::remove($system, $path);
                }
            }
        }
    }

    /**
     * A remote command with redirects off, or the command unchanged when it does not talk to a remote: a redirecting
     * remote must be refused rather than silently lead the connection to another host.
     *
     * @param  list<string>  $gitCommand
     * @return list<string>
     */
    private function outboundCommand(array $gitCommand): array
    {
        return self::talksToRemote($gitCommand)
            ? $this->pinnedOnHost(GitUrl::withoutRedirects($gitCommand))
            : $gitCommand;
    }

    /**
     * A host command pinned to the addresses the egress guard accepts, resolved here, so a
     * DNS answer that changed since the connect check cannot steer it to an internal address.
     *
     * @param  list<string>  $command
     * @return list<string>
     *
     * @throws GitException 422 when a host no longer resolves, resolves to an address core must not
     *                      contact, or needs a pin the host's git is too old to honour
     */
    private function pinnedOnHost(array $command): array
    {
        if (! $this->runsOnHost()) {
            return $command;
        }

        try {
            foreach ($this->addressedUrls($command) as $url) {
                $command = GitUrl::withResolve($command, $this->hostPin($url));
            }
        } catch (\InvalidArgumentException $e) {
            throw new GitException(GitUrl::sanitize($e->getMessage()), 422, EgressRefusedException::CODE);
        }

        return $command;
    }

    /**
     * A name the operator allows in EGRESS_ALLOW_HOSTS may be fetched unpinned
     * by a host git too old to honour the pin; any other is refused there.
     *
     * @throws \InvalidArgumentException when the host is refused, or the host's git cannot pin it
     */
    private function hostPin(string $url): ?string
    {
        $pin = OutboundUrl::pin($url, GitRepoInput::SCHEMES);
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($pin !== null && ! OutboundUrl::allowsHost($host)) {
            HostGitVersion::of($this->system())->assertPins($host);
        }

        return $pin;
    }

    /**
     * The http(s) URLs a command connects to: the one in its argv, else what git
     * itself resolves origin to, insteadOf rewrites applied.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private function addressedUrls(array $command): array
    {
        $argument = self::urlArgument($command);
        $urls = $argument !== null ? [$argument] : $this->originUrls(self::subcommandOf($command) === 'push');

        return array_values(array_filter($urls, static fn (string $url): bool => in_array(
            strtolower((string) parse_url($url, PHP_URL_SCHEME)), GitRepoInput::SCHEMES, true,
        )));
    }

    /** @return list<string> */
    private function originUrls(bool $push): array
    {
        try {
            $out = $this->git(['remote', 'get-url', '--all', ...($push ? ['--push'] : []), 'origin']);
        } catch (GitException) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $url): bool => $url !== ''));
    }

    /**
     * The repository URL a command talks to: an http(s) URL in its argv, else
     * the checkout's stored remote, for the askpass helper's host.
     *
     * @param  list<string>  $gitCommand
     */
    private function remoteUrl(array $gitCommand): ?string
    {
        $argument = self::urlArgument($gitCommand);
        if ($argument !== null) {
            return $argument;
        }

        try {
            $siteGit = $this->user()->getSiteGit($this->pathKey);
            $stored = $siteGit['repo_url'] ?? $this->user()->getGitRepo();
        } catch (\Throwable) {
            return null;
        }

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /** @param list<string> $gitCommand */
    private static function urlArgument(array $gitCommand): ?string
    {
        foreach ($gitCommand as $arg) {
            if (str_starts_with($arg, 'http://') || str_starts_with($arg, 'https://')) {
                return $arg;
            }
        }

        return null;
    }

    /** @param list<string> $gitCommand */
    private static function talksToRemote(array $gitCommand): bool
    {
        return in_array(self::subcommandOf($gitCommand), self::REMOTE_SUBCOMMANDS, true);
    }

    /** @param list<string> $gitCommand */
    private static function subcommandOf(array $gitCommand): ?string
    {
        $args = array_slice($gitCommand, 1);
        for ($i = 0; $i < count($args); $i++) {
            if (in_array($args[$i], ['-c', '-C'], true)) {
                $i++;

                continue;
            }
            if (str_starts_with($args[$i], '-')) {
                continue;
            }

            return $args[$i];
        }

        return null;
    }

    /**
     * An exec docker refused never ran git. ssh's own words say what failed, not what to do about it. Without a key
     * nothing is pinned either, so that case goes first: its host-key failure is not a changed key.
     */
    private static function failure(string $message, bool $withKey): GitException
    {
        $notRun = CouldNotRun::fromOutput($message);
        if ($notRun !== null) {
            return $notRun;
        }
        $hostKey = stripos($message, 'Host key verification failed') !== false;
        $refused = stripos($message, 'Permission denied (publickey') !== false;

        if (! $withKey && ($hostKey || $refused)) {
            return new GitException($message.' This project has no deploy key, so the SSH remote cannot '
                .'authenticate. Create one, add its public key to the repository as a deploy key and try again, or '
                .'connect the repository\'s HTTPS URL with a token.',
                422, 'git_ssh_needs_deploy_key');
        }
        if ($hostKey) {
            return new GitException($message.' The server\'s SSH host key does not match the one pinned for this '
                .'host, so the connection was refused. If the host really changed its key, delete the deploy key '
                .'and create it again with `host` to pin the new one.', 400);
        }
        if ($refused) {
            return new GitException($message.' The repository does not accept this project\'s deploy key: add its '
                .'public half, which creating the key returns, to the repository as a deploy key.',
                422, 'git_ssh_key_refused');
        }

        return new GitException($message, 400);
    }

    /**
     * The deploy key and the pinned host keys as files the account can read,
     * beside where the askpass helper goes, for one command.
     *
     * @param  list<string>  $gitCommand
     * @return array{0: string, 1: string} key path, known_hosts path
     */
    private function installSshKey(EngineSystem $system, string $privateKey, array $gitCommand): array
    {
        $baseDir = $this->credentialDir($gitCommand);
        $chown = $this->user()->getChownString() ?: $this->user()->username;
        $installed = [];

        try {
            foreach (['key' => $privateKey, 'known-hosts' => DeployKey::knownHosts($this->user())] as $kind => $content) {
                $path = $baseDir.'/'.CredentialFiles::SSH_PREFIX.$kind.'-'.bin2hex(random_bytes(8));
                $this->installCredentialFile($system, self::credentialTempFile($content, 'pa-git-ssh-'), $path, '0600');
                $installed[] = $path;
                $system->exec(Privileged::files()->changeOwner($chown, $path));
            }
        } catch (\Throwable $e) {
            foreach ($installed as $path) {
                CredentialFiles::remove($system, $path);
            }
            throw $e;
        }

        return [$installed[0], $installed[1]];
    }

    /** $content in a 0600 file of the engine's own, removed again when it cannot be written. */
    private static function credentialTempFile(string $content, string $prefix): string
    {
        $temp = Exchange::file($prefix);
        if (file_put_contents($temp, $content, LOCK_EX) === false || ! chmod($temp, 0600)) {
            @unlink($temp);
            throw new \RuntimeException('Could not prepare a temporary Git credential file.');
        }

        return $temp;
    }

    /** Install $temp at $path with $mode, and remove $temp either way. */
    private function installCredentialFile(EngineSystem $system, string $temp, string $path, string $mode): void
    {
        try {
            $system->exec(Privileged::files()->install($mode, $temp, $path));
        } finally {
            @unlink($temp);
        }
    }

    /**
     * The GIT_ASKPASS helper carrying $token, owned by the account; removed again
     * when installing it fails part way.
     *
     * @param  list<string>  $gitCommand
     * @return string the installed helper's path
     */
    private function installAskPass(EngineSystem $system, string $token, array $gitCommand): string
    {
        $path = $this->credentialDir($gitCommand).'/'.CredentialFiles::ASKPASS_PREFIX.bin2hex(random_bytes(8));
        $chown = $this->user()->getChownString() ?: $this->user()->username;

        try {
            $host = GitUrl::hostOf((string) $this->remoteUrl($gitCommand));
            $temp = self::credentialTempFile(GitUrl::askPassScript($token, $host), 'pa-git-askpass-');
            $this->installCredentialFile($system, $temp, $path, '0700');
            $system->exec(Privileged::files()->changeOwner($chown, $path));
        } catch (\Throwable $e) {
            CredentialFiles::remove($system, $path);
            throw $e;
        }

        return $path;
    }

    /**
     * Where a command's credential files go: beside its work tree, or in the home.
     *
     * @param  list<string>  $gitCommand
     */
    private function credentialDir(array $gitCommand): string
    {
        return CredentialFiles::directoryFor($this->homeDirPath(), $this->workingTreeFromGitArgs($gitCommand));
    }

    /**
     * @param  list<string>  $gitArgs
     */
    private function workingTreeFromGitArgs(array $gitArgs): ?string
    {
        $idx = array_search('-C', $gitArgs, true);
        if ($idx === false || ! isset($gitArgs[$idx + 1])) {
            return null;
        }

        return $gitArgs[$idx + 1];
    }

    private function requireRepository(): void
    {
        if (! $this->repositoryExistsLenient()) {
            throw new GitException('No git repository at path', 422);
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function statusEnvelope(array $fields): array
    {
        return [
            'path' => $this->pathKey,
            'path_key' => $this->pathKey,
            'managed_by' => $this->isDeployManaged() ? 'deploy' : 'site_git',
            ...$fields,
        ];
    }

    /**
     * @return array{
     *     path: string,
     *     path_key: string,
     *     managed_by: string,
     *     connected: bool,
     *     repository_exists: true,
     *     remote_url: ?string,
     *     branch: ?string,
     *     dirty: bool,
     *     tracking: ?string,
     *     commits_ahead: ?int,
     *     commits_behind: ?int
     * }
     */
    private function buildStatus(bool $connected): array
    {
        $originUrl = $this->configValue('remote.origin.url');
        $branch = $this->currentBranchName();
        $dirty = $this->hasWorkingTreeChanges();
        [$tracking, $ahead, $behind] = $this->upstreamState();

        return $this->statusEnvelope([
            'connected' => $connected,
            'repository_exists' => true,
            'remote_url' => $originUrl !== '' ? GitUrl::sanitize($originUrl) : null,
            'branch' => $branch !== '' ? $branch : null,
            'dirty' => $dirty,
            'tracking' => $tracking,
            'commits_ahead' => $ahead,
            'commits_behind' => $behind,
        ]);
    }

    /**
     * The upstream with commits ahead and behind it; null for whatever git could not tell.
     *
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function upstreamState(): array
    {
        $tracking = null;
        $ahead = null;
        $behind = null;
        try {
            $upstream = trim($this->git(['rev-parse', '--abbrev-ref', '@{upstream}']));
            if ($upstream !== '') {
                $tracking = $upstream;
                $ahead = (int) trim($this->git(['rev-list', '--count', '@{upstream}..HEAD']));
                $behind = (int) trim($this->git(['rev-list', '--count', 'HEAD..@{upstream}']));
            }
        } catch (GitException) {
        }

        return [$tracking, $ahead, $behind];
    }
}

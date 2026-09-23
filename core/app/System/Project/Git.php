<?php

namespace App\System\Project;

use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\FastForwardPull;
use App\System\Project\Git\Path as GitPath;
use App\System\Project\Git\Ref as GitRef;
use App\Lib\Deploy\Source\GitUrl;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project as UserProject;
use Illuminate\Support\Facades\Log;

class Git
{
    use FastForwardPull;

    public const string STRATEGY_FF = 'ff';
    public const string STRATEGY_FORCE = 'force';
    public const string STRATEGY_PUSH_FIRST = 'push_first';

    private const string BACKUP_REF = 'refs/panelalpha/backup';

    protected UserProject $project;
    protected string $absolutePath;
    protected string $pathKey;

    public function __construct(UserProject $project, ?string $path = null)
    {
        $this->project = $project;
        $home = rtrim($project->homeDirPath(), '/');
        $trimmed = trim((string) $path);

        if ($trimmed === '') {
            $runtime = $project->runtime();
            if ($runtime instanceof Dind) {
                $this->absolutePath = $runtime->userAppDirPath();
            } else {
                $this->absolutePath = $home . '/public_html';
            }
        } else {
            $this->absolutePath = $project->resolvePath($trimmed);
        }

        $this->pathKey = GitPath::key($this->absolutePath, $home);
    }

    public function absolutePath(): string
    {
        return $this->absolutePath;
    }

    public function pathKey(): string
    {
        return $this->pathKey;
    }

    /**
     * DinD checkout at ~/project when the account was provisioned with git_repo.
     * Disconnect stays blocked; pull/change-branch/revert are followed by a
     * checkout rebuild outside this class so the running app matches the tree.
     */
    public function isDeployManaged(): bool
    {
        return $this->pathKey === 'project' && $this->user()->hasGitProject();
    }

    /**
     * Whether this checkout has a remote recorded: the Deploy-managed one, or a
     * Site Git checkout that `connect` set up. The precondition for anything
     * that pulls from it.
     */
    public function isConnected(): bool
    {
        return $this->user()->getSiteGit($this->pathKey) !== null;
    }

    /**
     * Shallow clone into this instance's absolute path (no -C; clone creates the dir).
     */
    public function clone(string $repoUrl, ?string $branch, ?string $token): void
    {
        if ($token !== null && $token !== '') {
            try {
                GitUrl::assertSafeForToken($repoUrl);
            } catch (\InvalidArgumentException $e) {
                throw new GitException($e->getMessage(), 422);
            }
        }

        $cmd = ['git', '-c', 'safe.directory=' . $this->absolutePath, 'clone', '--depth=1'];
        if ($branch !== null && $branch !== '') {
            GitRef::assertName($branch);
            $cmd[] = '--branch';
            $cmd[] = $branch;
        }
        $cmd[] = $repoUrl;
        $cmd[] = $this->absolutePath;

        $this->execute($cmd, $token);
    }

    /**
     * Fetch the submodules a freshly cloned checkout declares, if any.
     *
     * `clone` stays without `--recurse-submodules` on purpose: that flag makes
     * a submodule that is private, moved or simply slow fail the whole clone,
     * and most repositories that declare one still deploy fine without it.
     * This runs afterwards instead, so a failure costs the submodules and not
     * the deploy.
     *
     * It is not optional for every project, though. A repository that vendors
     * its dependencies this way arrives as an empty directory where its code
     * should be: nextcloud/server keeps its entire PHP dependency tree in the
     * `3rdparty` submodule, so Composer reported "Nothing to install" and the
     * autoloader would have fatalled on the first request.
     *
     * @return bool whether submodules were declared (not whether they all came down)
     * @throws GitException when the checkout has no repository at all
     */
    public function initSubmodules(?string $token = null): bool
    {
        $this->requireRepository();

        if (!is_file($this->absolutePath . '/.gitmodules')) {
            return false;
        }

        // Shallow, to match the depth-1 clone above: a submodule's history is
        // no more use to a deploy than the parent's is.
        $this->execute([
            'git',
            '-c', 'safe.directory=' . $this->absolutePath,
            '-C', $this->absolutePath,
            'submodule', 'update', '--init', '--recursive', '--depth=1',
        ], $token);

        return true;
    }

    /**
     * Persist site_git for an already-cloned checkout (deploy ingest).
     * Does not init, fetch, or wipe the work tree.
     */
    public function adoptCheckout(string $repoUrl, ?string $branch, ?string $token): void
    {
        $this->requireRepository();

        if ($branch === null || $branch === '') {
            $branch = trim($this->git(['branch', '--show-current']));
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

    public function configureSafeDirectory(): void
    {
        // Local .git/config safe.directory is ignored by git 2.35+; hooks
        // (husky/lefthook) need the path in the account user's global config.
        $path = $this->absolutePath;
        try {
            $existing = trim($this->git(['config', '--global', '--get-all', 'safe.directory']));
        } catch (GitException) {
            $existing = '';
        }

        foreach (explode("\n", $existing) as $line) {
            if (trim($line) === $path) {
                return;
            }
        }

        $this->git(['config', '--global', '--add', 'safe.directory', $path]);
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

    public function status(bool $fetch = false): array
    {
        $connected = $this->user()->getSiteGit($this->pathKey) !== null;

        if (!$this->repositoryExistsLenient()) {
            return $this->statusEnvelope([
                'connected' => $connected,
                'repository_exists' => false,
                'remote_url' => null,
                'branch' => null,
                'dirty' => false,
                'tracking' => null,
                'commits_ahead' => null,
                'commits_behind' => null,
            ]);
        }

        if ($fetch) {
            if (!$connected) {
                throw new GitException('Git is not connected.', 422);
            }
            $siteGit = $this->user()->getSiteGit($this->pathKey);
            $this->git(['fetch', 'origin'], $siteGit['token'] ?? null);
        }

        return $this->buildStatus($connected);
    }

    private function repositoryExistsLenient(): bool
    {
        try {
            return trim($this->git(['rev-parse', '--is-inside-work-tree'])) === 'true';
        } catch (GitException) {
            return false;
        }
    }

    /**
     * @return list<array{name: string, current: bool, tracking: ?string}>
     */
    public function branches(): array
    {
        $this->requireRepository();

        $output = trim($this->git([
            'for-each-ref',
            '--format=%(refname)%1f%(refname:short)%1f%(upstream:short)%1f%(HEAD)',
            'refs/heads/',
            'refs/remotes/origin/',
        ]));
        if ($output === '') {
            return [];
        }

        $byName = [];
        foreach (explode("\n", $output) as $line) {
            if ($line === '') {
                continue;
            }
            [$ref, $short, $upstream, $head] = array_pad(explode("\x1f", $line, 4), 4, '');
            if ($ref === '' || str_ends_with($ref, '/HEAD')) {
                continue;
            }

            $isRemote = str_starts_with($ref, 'refs/remotes/origin/');
            if ($isRemote) {
                $name = substr($ref, strlen('refs/remotes/origin/'));
            } elseif (str_starts_with($ref, 'refs/heads/')) {
                $name = substr($ref, strlen('refs/heads/'));
            } else {
                $name = $short;
            }

            if ($name === '' || $name === 'HEAD') {
                continue;
            }
            if ($isRemote && isset($byName[$name])) {
                continue;
            }

            $byName[$name] = [
                'name' => $name,
                'current' => $head === '*',
                'tracking' => $upstream !== ''
                    ? $upstream
                    : ($isRemote ? 'origin/' . $name : null),
            ];
        }

        return array_values($byName);
    }

    /**
     * @return list<array{hash: string, short_hash: string, subject: string, author: string, date: string}>
     */
    public function commits(?int $limit = 50, ?string $branch = null): array
    {
        $this->requireRepository();
        $limit = $limit ?? 50;

        $args = ['log', '-n', (string) $limit, '--format=%H%x1f%h%x1f%s%x1f%an%x1f%aI'];
        if ($branch !== null && $branch !== '') {
            GitRef::assertName($branch);
            // Revision-range (not a pathspec): do not insert `--` before the name.
            $args[] = $branch;
        }

        $output = trim($this->git($args));
        if ($output === '') {
            return [];
        }

        $commits = [];
        foreach (explode("\n", $output) as $line) {
            if ($line === '') {
                continue;
            }
            [$hash, $shortHash, $subject, $author, $date] = array_pad(explode("\x1f", $line, 5), 5, '');
            $commits[] = [
                'hash' => $hash,
                'short_hash' => $shortHash,
                'subject' => $subject,
                'author' => $author,
                'date' => $date,
            ];
        }

        return $commits;
    }

    public function revert(?string $ref = null): array
    {
        $this->requireRepository();
        $ref = $ref ?? 'HEAD';

        if ($ref !== 'HEAD') {
            GitRef::assertName($ref);
        }

        if ($ref === 'HEAD') {
            try {
                $this->git(['rev-parse', '--verify', 'HEAD']);
            } catch (GitException) {
                throw new GitException('Nothing to revert', 422);
            }
        }

        $this->git(['reset', '--hard', $ref]);
        $this->git(['clean', '-fd']);

        return $this->status();
    }

    public function connect(string $repoUrl, string $branch, ?string $token, bool $repair = false): array
    {
        if ($this->isDeployManaged() && !$repair) {
            // Already provisioned via git_repo; keep origin in sync and persist.
            $repoExists = $this->repositoryExistsLenient();
            if ($repoExists) {
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
                $this->ensureOriginMatches($repoUrl);
                $this->persistSiteGit($repoUrl, $branch, $token ?? $this->user()->getGitToken());
                $this->mirrorDeployCredentials($branch, $token ?? $this->user()->getGitToken());

                return $this->status();
            }
            // Missing .git on a deploy account — fall through to repair-style adopt.
            $siteGit = $this->user()->getSiteGit($this->pathKey);
            return $this->connectRepair(
                $repoUrl !== '' ? $repoUrl : ($siteGit['repo_url'] ?? ''),
                $branch !== '' ? $branch : ($siteGit['branch'] ?? ''),
                $token ?? ($siteGit['token'] ?? null),
                $siteGit,
                false,
            );
        }

        $siteGit = $this->user()->getSiteGit($this->pathKey);
        $repoExists = $this->repositoryExistsLenient();

        if ($repair || ($siteGit !== null && !$repoExists)) {
            return $this->connectRepair($repoUrl, $branch, $token, $siteGit, $repoExists);
        }

        if ($branch !== '') {
            GitRef::assertName($branch);
        }

        if ($token !== null && $token !== '') {
            try {
                GitUrl::assertSafeForToken($repoUrl);
            } catch (\InvalidArgumentException $e) {
                throw new GitException($e->getMessage(), 422);
            }
            $this->git(['ls-remote', '--heads', $repoUrl], $token);
        }

        if ($repoExists) {
            $this->ensureOriginMatches($repoUrl);
        } else {
            $this->ensureWorkTreeDirectory();
            $this->initRepository($branch, $repoUrl);
            $this->fetchAndSyncFreshInit($branch, $token);
        }

        $this->persistSiteGit($repoUrl, $branch, $token);

        return $this->status();
    }

    /**
     * A fresh `git init` has an empty local branch and no remote history --
     * left as-is, `connected: true` reports 0 branches and 0 commits until a
     * separate pull. Sync it here so connect() alone is enough. reset --hard
     * only touches paths git already tracks (the newly fetched ones), so it
     * cannot delete an unrelated file already sitting in a non-empty work
     * tree the way `git clean -fd` would -- best-effort: a bad repo/branch
     * still leaves the repository connected, just empty, exactly as before.
     */
    private function fetchAndSyncFreshInit(string $branch, ?string $token): void
    {
        try {
            $this->git(['fetch', 'origin'], $token);
            $this->git(['reset', '--hard', 'origin/' . $branch]);
            $this->setUpstreamTracking($branch);
        } catch (GitException $e) {
            Log::warning('git_connect: initial fetch/sync failed, repository left empty', [
                'path' => $this->absolutePath,
                'branch' => $branch,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function disconnect(): array
    {
        $this->assertNotDeployManaged('Git is managed by deploy.');

        if ($this->user()->getSiteGit($this->pathKey) === null) {
            throw new GitException('Git is not connected.', 422);
        }

        if ($this->repositoryExistsLenient()) {
            try {
                $origin = trim($this->git(['config', '--get', 'remote.origin.url']));
                if ($origin !== '') {
                    $this->git(['remote', 'remove', 'origin']);
                }
            } catch (GitException) {
                // Ignore failure when .git is missing or origin cannot be removed.
            }
        }

        $this->user()->forgetSiteGit($this->pathKey);

        return $this->status();
    }

    public function updateCredentials(?string $token, bool $tokenProvided = true): array
    {
        $siteGit = $this->user()->getSiteGit($this->pathKey);
        if ($siteGit === null) {
            throw new GitException('Git is not connected.', 422);
        }

        if (!$tokenProvided) {
            return $this->status();
        }

        $normalized = $this->normalizeToken($token);
        $this->user()->putSiteGit($this->pathKey, [
            'repo_url' => $siteGit['repo_url'],
            'branch' => $siteGit['branch'],
            'token' => $normalized,
        ]);
        $this->mirrorDeployCredentials($siteGit['branch'], $normalized);

        return $this->status();
    }

    public function pull(?string $strategy = null): array
    {
        $siteGit = $this->requireConnected();
        $this->requireRepository();

        $strategy = $strategy ?? self::STRATEGY_FF;
        if (!in_array($strategy, [self::STRATEGY_FF, self::STRATEGY_FORCE, self::STRATEGY_PUSH_FIRST], true)) {
            throw new GitException('Unknown pull strategy.', 422);
        }

        $branch = $siteGit['branch'];
        if ($branch === '') {
            throw new GitException('Connected branch is empty.', 422);
        }
        GitRef::assertName($branch);
        $token = $siteGit['token'] ?? null;

        $this->git(['fetch', 'origin'], $token);
        $this->createBackup();

        // ff is git's call, not ours (see FastForwardPull), and a refusal leaves
        // the tree untouched -- so it must bypass restoreBackup below, which
        // would wipe the untracked files and local edits git just spared.
        if ($strategy === self::STRATEGY_FF) {
            $this->fastForwardOnly($branch);
            $this->setUpstreamTracking($branch);

            return $this->status();
        }

        try {
            if ($strategy === self::STRATEGY_FORCE) {
                $this->git(['reset', '--hard', 'origin/' . $branch]);
                $this->git(['clean', '-fd']);
            } else {
                $this->pullPushFirst($branch, $token);
            }

            $this->setUpstreamTracking($branch);
        } catch (GitException $e) {
            $this->restoreBackup();
            throw $e;
        }

        return $this->status();
    }

    public function push(): array
    {
        $siteGit = $this->requireConnected();
        $this->requireRepository();

        $branch = $siteGit['branch'];
        if ($branch === '') {
            throw new GitException('Connected branch is empty.', 422);
        }
        GitRef::assertName($branch);
        $token = $siteGit['token'] ?? null;

        $this->git(['fetch', 'origin'], $token);

        $behind = $this->commitsBehind();
        if ($behind !== null && $behind > 0) {
            throw new GitException('Pull first.', 422);
        }

        $dirty = $this->hasWorkingTreeChanges();
        $ahead = $this->commitsAhead();

        if (!$dirty && ($ahead === null || $ahead === 0)) {
            return $this->status() + ['nothing_to_push' => true];
        }

        $this->createBackup();

        try {
            if ($dirty) {
                $this->commitAll();
            }
            $this->git(['push', 'origin', 'HEAD:' . $branch], $token);

            return $this->status();
        } catch (GitException $e) {
            $this->restoreBackup();
            throw $e;
        }
    }

    public function changeBranch(string $branch): array
    {
        $siteGit = $this->requireConnected();
        $this->requireRepository();
        GitRef::assertName($branch);

        if ($this->hasWorkingTreeChanges()) {
            throw new GitException('Working tree is dirty.', 422);
        }

        $token = $siteGit['token'] ?? null;
        $this->git(['fetch', 'origin'], $token);

        try {
            $this->git(['rev-parse', '--verify', '--end-of-options', 'origin/' . $branch]);
        } catch (GitException) {
            throw new GitException('Branch does not exist on remote.', 422);
        }

        $this->git(['checkout', '-B', $branch, 'origin/' . $branch]);
        $this->setUpstreamTracking($branch);

        $this->user()->putSiteGit($this->pathKey, [
            'repo_url' => $siteGit['repo_url'],
            'branch' => $branch,
            'token' => $siteGit['token'],
        ]);
        $this->mirrorDeployCredentials($branch, $siteGit['token']);

        return $this->status();
    }

    private function user(): ModelsUser
    {
        return $this->project->model();
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
            throw new GitException('Git is not connected.', 422);
        }

        return $siteGit;
    }

    private function createBackup(): void
    {
        if (!$this->headCommitExists()) {
            return;
        }

        $this->git(['update-ref', self::BACKUP_REF, 'HEAD']);
    }

    private function headCommitExists(): bool
    {
        try {
            return trim($this->git(['rev-parse', '--verify', 'HEAD'])) !== '';
        } catch (GitException) {
            return false;
        }
    }

    private function setUpstreamTracking(string $branch): void
    {
        try {
            $this->git(['rev-parse', '--verify', '--end-of-options', 'origin/' . $branch]);
            $this->git(['branch', '--set-upstream-to=origin/' . $branch, $branch]);
        } catch (GitException) {
            // Remote branch may not exist yet.
        }
    }

    private function restoreBackup(): void
    {
        try {
            $this->git(['reset', '--hard', self::BACKUP_REF]);
            $this->git(['clean', '-fd']);
        } catch (GitException) {
            // Best-effort restore.
        }
    }

    private function hasWorkingTreeChanges(): bool
    {
        return trim($this->git(['status', '--porcelain'])) !== '';
    }

    private function commitsBehind(): ?int
    {
        try {
            $upstream = trim($this->git(['rev-parse', '--abbrev-ref', '@{upstream}']));
            if ($upstream === '') {
                return null;
            }

            return (int) trim($this->git(['rev-list', '--count', 'HEAD..@{upstream}']));
        } catch (GitException) {
            return null;
        }
    }

    private function commitsAhead(): ?int
    {
        try {
            $upstream = trim($this->git(['rev-parse', '--abbrev-ref', '@{upstream}']));
            if ($upstream === '') {
                return null;
            }

            return (int) trim($this->git(['rev-list', '--count', '@{upstream}..HEAD']));
        } catch (GitException) {
            return null;
        }
    }

    private function pullPushFirst(string $branch, ?string $token): void
    {
        if ($this->hasWorkingTreeChanges()) {
            $this->commitAll();
        }

        $behind = $this->commitsBehind();
        if ($behind !== null && $behind > 0) {
            try {
                $this->git(['merge', 'origin/' . $branch]);
            } catch (GitException $e) {
                $message = strtolower($e->getMessage());
                if (str_contains($message, 'unrelated histories')) {
                    try {
                        $this->git(['merge', '--abort']);
                    } catch (GitException) {
                        // Ignore abort failure.
                    }
                    throw new GitException('Unrelated git histories.', 422);
                }

                $unmerged = '';
                try {
                    $unmerged = trim($this->git(['diff', '--name-only', '--diff-filter=U']));
                } catch (GitException) {
                    // No unmerged paths available.
                }

                try {
                    $this->git(['merge', '--abort']);
                } catch (GitException) {
                    // Ignore abort failure.
                }

                if ($unmerged !== '') {
                    throw new GitException('Merge conflict: ' . str_replace("\n", ', ', $unmerged), 422);
                }

                throw $e;
            }
        }

        $ahead = $this->commitsAhead();
        $dirty = $this->hasWorkingTreeChanges();
        if ($dirty || ($ahead !== null && $ahead > 0)) {
            if ($dirty) {
                $this->commitAll();
            }
            $this->git(['push', 'origin', 'HEAD:' . $branch], $token);
        }
    }

    private function commitAll(): void
    {
        [$name, $email] = $this->commitIdentity();

        $this->git(['add', '-A']);
        $this->git([
            '-c', 'user.name=' . $name,
            '-c', 'user.email=' . $email,
            'commit', '-m', 'Sync from PanelAlpha',
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function commitIdentity(): array
    {
        $name = '';
        $email = '';
        try {
            $name = trim($this->git(['config', '--get', 'user.name']));
            $email = trim($this->git(['config', '--get', 'user.email']));
        } catch (GitException) {
            // Fall back to user-derived identity.
        }

        if ($name !== '' && $email !== '') {
            return [$name, $email];
        }

        $username = $this->user()->username;
        $domain = is_string($this->user()->domain) && $this->user()->domain !== ''
            ? $this->user()->domain
            : 'localhost';

        return [$username, $username . '@' . $domain];
    }

    /**
     * @param array{repo_url: string, branch: string, token: ?string}|null $siteGit
     */
    private function connectRepair(
        string $repoUrl,
        string $branch,
        ?string $token,
        ?array $siteGit,
        bool $repoExists,
    ): array {
        if ($siteGit === null && !$this->isDeployManaged()) {
            throw new GitException('Git is not connected.', 422);
        }
        if ($repoExists) {
            throw new GitException('Local git repository already exists.', 422);
        }

        if ($repoUrl === '' && $siteGit !== null) {
            $repoUrl = $siteGit['repo_url'];
        }
        if ($branch === '' && $siteGit !== null) {
            $branch = $siteGit['branch'];
        }
        if ($token === null && $siteGit !== null) {
            $token = $siteGit['token'];
        }

        if ($repoUrl === '' || $branch === '') {
            throw new GitException('Git is not connected.', 422);
        }
        GitRef::assertName($branch);

        if ($token !== null && $token !== '') {
            try {
                GitUrl::assertSafeForToken($repoUrl);
            } catch (\InvalidArgumentException $e) {
                throw new GitException($e->getMessage(), 422);
            }
        }

        // Probe the remote before creating .git so a failed ls-remote does not
        // leave a half-initialized repository that blocks the next repair.
        $this->git(['ls-remote', '--heads', $repoUrl], $token);

        $this->ensureWorkTreeDirectory();
        try {
            $this->initRepository($branch, $repoUrl);
        } catch (GitException $e) {
            $this->removeCreatedGitDir();
            throw $e;
        }

        $this->persistSiteGit($repoUrl, $branch, $token);
        $this->mirrorDeployCredentials($branch, $token);

        return $this->status();
    }

    protected function ensureWorkTreeDirectory(): void
    {
        $system = $this->project->system();
        $system->exec(['sudo', 'mkdir', '-p', $this->absolutePath]);
        $chown = $this->user()->getChownString();
        if ($chown) {
            $system->exec(['sudo', 'chown', $chown, $this->absolutePath]);
        }
    }

    private function initRepository(string $branch, string $repoUrl): void
    {
        $this->git(['init']);
        $this->git(['symbolic-ref', 'HEAD', 'refs/heads/' . $branch]);
        $this->git(['remote', 'add', 'origin', $repoUrl]);
    }

    /**
     * Best-effort removal of a .git we created during a failed repair init.
     * Does not touch the work tree contents.
     */
    protected function removeCreatedGitDir(): void
    {
        $gitDir = rtrim($this->absolutePath, '/') . '/.git';
        try {
            $this->project->system()->exec(['sudo', 'rm', '-rf', $gitDir]);
        } catch (\Throwable) {
            // Best-effort cleanup.
        }
    }

    private function ensureOriginMatches(string $repoUrl): void
    {
        $originUrl = '';
        try {
            $originUrl = trim($this->git(['config', '--get', 'remote.origin.url']));
        } catch (GitException) {
            // No origin configured.
        }

        if ($originUrl === '') {
            $this->git(['remote', 'add', 'origin', $repoUrl]);

            return;
        }

        if (!$this->urlsEquivalent($originUrl, $repoUrl)) {
            throw new GitException('Remote URL does not match the existing origin.', 422);
        }

        $this->git(['remote', 'set-url', 'origin', $repoUrl]);
    }

    private function persistSiteGit(string $repoUrl, string $branch, ?string $token): void
    {
        $this->user()->putSiteGit($this->pathKey, [
            'repo_url' => GitUrl::sanitize($repoUrl),
            'branch' => $branch,
            'token' => $this->normalizeToken($token),
        ]);
    }

    private function mirrorDeployCredentials(string $branch, ?string $token): void
    {
        if (!$this->isDeployManaged()) {
            return;
        }

        $details = ['git_branch' => $branch];
        $normalized = $this->normalizeToken($token);
        if ($normalized !== null) {
            $details['git_token'] = $normalized;
        } else {
            // Clearing token: store empty so deploy clone stops using a stale PAT.
            $details['git_token'] = '';
        }
        $this->user()->setDetails($details);
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
     * @param list<string> $args
     */
    private function git(array $args, ?string $token = null): string
    {
        return $this->execute(
            ['git', '-c', 'safe.directory=' . $this->absolutePath, '-C', $this->absolutePath, ...$args],
            $token,
        );
    }

    /**
     * @param list<string> $gitCommand full argv starting with `git`
     * @return list<string>
     */
    protected function argvWithAskPass(array $gitCommand, ?string $token, string $askPassPath): array
    {
        if ($token === null || $token === '') {
            return $gitCommand;
        }

        return GitUrl::withAskPass($gitCommand, $askPassPath);
    }

    /**
     * @param list<string> $gitCommand full argv starting with `git`
     */
    protected function execute(array $gitCommand, ?string $token = null, int $timeout = 600): string
    {
        $system = $this->project->system();
        $askPassTemp = null;
        $askPassPath = null;

        try {
            // Guarded either way: the tokenless case is the one that prompts.
            $cmd = GitUrl::withoutPrompts($gitCommand);
            if ($token !== null && $token !== '') {
                [$askPassTemp, $askPassPath] = $this->installAskPass($system, $token, $gitCommand);
                $cmd = $this->argvWithAskPass($gitCommand, $token, $askPassPath);
            }

            $runtime = $this->project->runtime();
            if ($runtime instanceof Dind) {
                return $runtime->shell()->execAsUser($cmd, [], $timeout);
            }

            return $system->execOnHost(['sudo', '-u', $this->user()->username, ...$cmd]);
        } catch (GitException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw new GitException($e->getMessage(), 422);
        } catch (\Throwable $e) {
            throw new GitException(GitUrl::sanitize($e->getMessage()), 400);
        } finally {
            $this->cleanupAskPass($system, $askPassTemp, $askPassPath);
        }
    }

    /**
     * @param list<string> $gitCommand
     * @return array{0: string, 1: string}
     */
    private function installAskPass(EngineSystem $system, string $token, array $gitCommand): array
    {
        $workTree = $this->workingTreeFromGitArgs($gitCommand);
        $home = rtrim($this->project->homeDirPath(), '/');
        $baseDir = $workTree !== null ? dirname($workTree) : $home;

        $askPassTemp = tempnam(sys_get_temp_dir(), 'pa-git-askpass-');
        if ($askPassTemp === false) {
            throw new \RuntimeException('Could not create temporary Git credential helper.');
        }
        $askPassPath = $baseDir . '/.panelalpha-git-askpass-' . bin2hex(random_bytes(8));
        $written = file_put_contents($askPassTemp, GitUrl::askPassScript($token), LOCK_EX);
        if ($written === false || !chmod($askPassTemp, 0600)) {
            throw new \RuntimeException('Could not prepare temporary Git credential helper.');
        }
        $chown = $this->user()->getChownString();
        $system->exec(['sudo', 'install', '-m', '0700', $askPassTemp, $askPassPath]);
        $system->exec(['sudo', 'chown', $chown ?: $this->user()->username, $askPassPath]);

        return [$askPassTemp, $askPassPath];
    }

    private function cleanupAskPass(EngineSystem $system, ?string $askPassTemp, ?string $askPassPath): void
    {
        if ($askPassTemp !== null) {
            @unlink($askPassTemp);
        }
        if ($askPassPath !== null) {
            try {
                $system->exec(['sudo', 'rm', '-f', $askPassPath]);
            } catch (\Throwable $e) {
                Log::warning('Could not remove temporary Git credential helper', [
                    'path' => $askPassPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param list<string> $gitArgs
     */
    private function workingTreeFromGitArgs(array $gitArgs): ?string
    {
        $idx = array_search('-C', $gitArgs, true);
        if ($idx === false || !isset($gitArgs[$idx + 1])) {
            return null;
        }

        return $gitArgs[$idx + 1];
    }

    private function requireRepository(): void
    {
        try {
            if (trim($this->git(['rev-parse', '--is-inside-work-tree'])) !== 'true') {
                throw new GitException('No git repository at path', 422);
            }
        } catch (GitException $e) {
            if ($e->httpStatus === 422 && $e->getMessage() === 'No git repository at path') {
                throw $e;
            }
            throw new GitException('No git repository at path', 422);
        }
    }

    /**
     * @param array<string, mixed> $fields
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
        $remoteUrl = null;
        try {
            $url = trim($this->git(['config', '--get', 'remote.origin.url']));
            if ($url !== '') {
                $remoteUrl = GitUrl::sanitize($url);
            }
        } catch (GitException) {
            // No remote configured.
        }

        $branch = trim($this->git(['branch', '--show-current']));
        if ($branch === '') {
            $branch = null;
        }

        $dirty = trim($this->git(['status', '--porcelain'])) !== '';

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
            // No upstream configured.
        }

        return $this->statusEnvelope([
            'connected' => $connected,
            'repository_exists' => true,
            'remote_url' => $remoteUrl,
            'branch' => $branch,
            'dirty' => $dirty,
            'tracking' => $tracking,
            'commits_ahead' => $ahead,
            'commits_behind' => $behind,
        ]);
    }
}

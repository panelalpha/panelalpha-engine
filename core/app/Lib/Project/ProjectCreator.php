<?php

namespace App\Lib\Project;

use App\Exceptions\ProblemException;
use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\RecipeChoiceInput;
use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Jobs\DeployProject;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployRequest;
use App\Lib\Deploy\EnvVarOverrides;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\ProjectName;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Source\RepoUrl;
use App\Lib\Deploy\Source\RepositoryAddress;
use App\Lib\Domains\AllocatedDomain;
use App\Lib\Domains\DomainAllocationException;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Lib\Git\DeployKey;
use App\Lib\Helper;
use App\Lib\Project\Create\CreateFollowUp;
use App\Lib\Project\PublicRouting\HostDomain;
use App\Lib\Task\TaskTrigger;
use App\Lib\Vault\RequestVault;
use App\Lib\Vault\VaultDeployKeys;
use App\Models\Domain;
use App\Models\Task;
use App\Models\Tunnel;
use App\Models\User;
use App\Rules\ProjectName as ProjectNameRule;
use App\System;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating a project: provision the account and its main domain, then deploy into it, in this process or on the queue.
 */
class ProjectCreator
{
    /** The action a create's deploy is recorded under, on its task and in its log. */
    private const DEPLOY_ACTION = 'deploy';

    /** What the last provision's inspection found; null without a git_repo. */
    private ?CreateInspection $inspection = null;

    /**
     * Provision and deploy in this process.
     *
     * @throws ValidationException
     */
    public function create(NewProjectInput $input): User
    {
        $user = $this->provisionForDeploy($input);
        $this->deploy($user, $this->startDeployLog($user));

        return $user;
    }

    /**
     * Arm this process's deploy with its plan and recipe, then provision: armed first, so a malformed one is a 422 with no
     * account behind it. Neither is stored, so the next deploy is back on detection.
     *
     * @throws ValidationException
     */
    public function provisionForDeploy(NewProjectInput $input): User
    {
        app(DeployPlanContext::class)->set(DeployPlanInput::parse($input->stages));
        app(RecipeChoiceContext::class)->set(RecipeChoiceInput::parse($input->recipe));

        return $this->provision($input);
    }

    /** The deploy log for a project that has something to deploy, or null. */
    public function startDeployLog(User $user): ?DeployLogger
    {
        if (! self::writesDeployLog($user)) {
            return null;
        }

        $deployLogger = DeployLogger::startSafely($user->username);
        if ($deployLogger !== null) {
            $gitRepo = $user->getGitRepo();
            $deployLogger->info($gitRepo
                ? 'Deploy started (source: git, repo: '.GitUrl::sanitize($gitRepo).')'
                : 'Deploy started (source: dind template)');
            $this->inspection?->writeTo($deployLogger);
            DeployRequest::record($deployLogger, self::DEPLOY_ACTION);
        }

        return $deployLogger;
    }

    /**
     * The repository inspection the last provision ran, for the create
     * response; null when it had no git_repo.
     */
    public function inspection(): ?CreateInspection
    {
        return $this->inspection;
    }

    /**
     * Throws ValidationException after finishing the deploy log (cancelled/failed) and cleaning up on errors. A plain
     * template writes no deploy log to hold the account's lock, so it is taken here, or a delete would tear the account down.
     */
    public function deploy(User $user, ?DeployLogger $deployLogger): void
    {
        $lock = self::writesDeployLog($user) ? null : DeployLock::acquireFor($user->username);
        try {
            $user->project()->runDeployment($deployLogger, null);
        } finally {
            $lock?->release();
        }
    }

    private static function writesDeployLog(User $user): bool
    {
        return $user->hasGitProject() || $user->getTemplate() === 'dind';
    }

    /**
     * Provision here and leave the deploy to a DeployProject job. The plan and recipe
     * are parsed before provision, so a malformed one leaves no account behind.
     *
     * @throws ValidationException
     */
    /**
     * The projects that deploy this repository: the same host, owner and name, whatever the scheme, credentials or
     * `.git` the address was given with. What `project:create --update-existing` looks for.
     *
     * @return list<User>
     */
    public function projectsDeploying(string $repo): array
    {
        $wanted = self::repoIdentity($repo);
        if ($wanted === null) {
            return [];
        }

        return User::query()->orderBy('username')->get()
            ->filter(static fn (User $user): bool => self::repoIdentity((string) $user->getGitRepo()) === $wanted)
            ->values()
            ->all();
    }

    /**
     * Deploy an existing project again from its repository, in this process: what POST /projects/{username}/rebuild
     * runs with a deploy stream. The project keeps its name, domain, settings, data and the git token it was made with.
     */
    public function redeploy(User $user): void
    {
        $rebuild = app(ProjectRebuild::class);
        $rebuild->whileIdle($user, static function () use ($rebuild, $user): void {
            $rebuild->run($user, ProjectRebuild::REBUILD, $rebuild->openLogWithoutTask($user, ProjectRebuild::REBUILD), null);
        });
    }

    private static function repoIdentity(string $repo): ?string
    {
        $parsed = trim($repo) === '' ? null : RepoUrl::parse(GitRepoInput::normalise($repo));

        return $parsed === null ? null : strtolower($parsed['host'].'/'.$parsed['owner'].'/'.$parsed['repo']);
    }

    public function queue(NewProjectInput $input, ?CreateFollowUp $followUp = null): Task
    {
        $plan = DeployPlanInput::parse($input->stages);
        $stages = $plan?->toArray();
        $recipe = RecipeChoiceInput::parse($input->recipe);

        $user = $this->provision($input, $followUp);

        $task = Task::start(
            jobType: DeployProject::class,
            queue: 'default',
            username: $user->username,
            details: [
                'username' => $user->username,
                'domain' => $user->domain,
                'action' => self::DEPLOY_ACTION,
                'trigger' => TaskTrigger::current(),
                ...$followUp?->taskDetails() ?? [],
            ],
        );
        DeployProject::dispatch($user->username, $stages, $recipe, $this->inspection?->toArray(), (int) $user->getKey(), $followUp?->toArray())
            ->attachTask($task);

        return $task;
    }

    /**
     * Validate, allocate a domain, and persist the user + main domain.
     * Shared by the sync and async create paths; does not start a deploy.
     */
    private function provision(NewProjectInput $input, ?CreateFollowUp $followUp = null): User
    {
        $this->inspection = null;

        $params = self::namedParams($input);
        self::assertRequestChecksPass($params, $input->nameField);
        $followUp?->assertUsable();
        $params = self::resolvedSource($params, $input);

        if (! empty($params['git_repo'])) {
            $this->inspection = self::inspectRepository($params, $input);
        }

        return self::saveUnderAllocatedDomain($params, $input->nameField, $input->envVars, $followUp);
    }

    /**
     * Until its tunnel row is written, nothing else knows a panelalpha.online
     * name was bought, so a create that fails before then gives it back here.
     *
     * @param  array<string, mixed>  $params
     * @param  mixed  $sentEnvVars  the env_vars as sent, before their vault references were read
     *
     * @throws ProblemException
     */
    private static function saveUnderAllocatedDomain(array $params, string $nameField, mixed $sentEnvVars, ?CreateFollowUp $followUp = null): User
    {
        $allocated = self::allocateDomain($params);
        try {
            return self::saveOnAllocatedDomain($params, $allocated, $nameField, $sentEnvVars, $followUp);
        } catch (\Throwable $failure) {
            PanelAlphaConnect::releaseAllocated($allocated);
            throw $failure;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  mixed  $sentEnvVars  the env_vars as sent, before their vault references were read
     */
    private static function saveOnAllocatedDomain(array $params, AllocatedDomain $allocated, string $nameField, mixed $sentEnvVars, ?CreateFollowUp $followUp = null): User
    {
        $params['domain'] = $allocated->domain;
        self::assertAllocatedDomainFree($params['domain']);

        if (! empty($params['git_repo']) && empty($params['template'])) {
            $params['template'] = 'dind';
        }

        $user = self::newUser($params, $allocated, $sentEnvVars, $followUp);
        self::assertNameStillFree($user, $params['domain']);

        $domain = self::saveNewProject($user, self::mainDomainFor($params), $nameField);
        $followUp?->claimFor($user);

        self::assignDedicatedIps($user, $params);
        self::recordOnlineTunnel($user, $domain, $allocated);

        return $user;
    }

    /**
     * The validated params with a project name: a create need not name itself, and is named after its repository, domain
     * or recipe, or a plain "app" when it carries none, which the one-line installer (`--repo`) relies on.
     *
     * @return array{
     *   username: string,
     *   domain?: ?string,
     *   domain_redirect_url?: ?string,
     *   email: string,
     *   disk_space_limit?: int,
     *   memory_limit?: int,
     *   cpu_limit?: float,
     *   device_read_bps?: int,
     *   device_write_bps?: int,
     *   bandwidth_limit?: int,
     *   mysql_databases_limit?: int,
     *   ftp_accounts_limit?: int,
     *   sftp_accounts_limit?: int,
     *   addon_domains_limit?: int,
     *   subdomains_limit?: int,
     *   inodes_limit?: int,
     *   php_fpm_pool_settings?: string,
     *   lsphp_settings?: string,
     *   redis_config?: string,
     *   dedicated_ipv4?: bool,
     *   dedicated_ipv6?: bool,
     *   template?: string,
     *   tunnel?: ?string,
     *   git_repo?: string,
     *   git_branch?: string,
     *   git_token?: string,
     *   env_vars?: array<string, string>,
     * }
     *
     * @throws ProblemException username_required
     */
    private static function namedParams(NewProjectInput $input): array
    {
        $params = $input->params;

        if (
            ! empty($params['domain'])
            && Str::startsWith($params['domain'], 'www.')
        ) {
            $params['domain'] = Str::after($params['domain'], 'www.');
        }

        if (empty($params['username'])) {
            $params['username'] = self::generatedName($params, $input->nameField);
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws ProblemException username_required
     */
    private static function generatedName(array $params, string $nameField): string
    {
        $name = Helper::generateUsernameFrom(ProjectName::base(
            $params['git_repo'] ?? null,
            $params['domain'] ?? null,
            $params['recipe'] ?? null,
        ));
        if (empty($name)) {
            throw ProblemException::one(
                $nameField,
                'username_required',
                'No project name was given and none could be generated; pass `name` ('
                    .ProjectNameRule::EXPECTED.').'
            );
        }

        return $name;
    }

    /**
     * Everything knowable from the request alone, asked at once and answered at once.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ProblemException
     */
    private static function assertRequestChecksPass(array $params, string $nameField): void
    {
        $problems = (new ProvisionChecks(app(ProvisionEnvironment::class)))->problems($params, $nameField);

        if ($problems !== []) {
            throw ProblemException::of($problems);
        }
    }

    /**
     * The source a deploy will clone: its `vault:` references read for this
     * project, and the remote checked before anything is created or changed.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws ProblemException
     */
    public static function resolvedSource(array $params, NewProjectInput $input): array
    {
        $params = self::withVaultResolved($params, $input);
        if (! empty($params['git_repo'])) {
            self::assertRepositoryReachable($params);
        }

        return $params;
    }

    /**
     * Vault references are read once the name is known to be free, so an entry is assigned to this project, and before the
     * allocator spends a label on a create that would fail here; the project stores the secrets, the vault is not read again.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function withVaultResolved(array $params, NewProjectInput $input): array
    {
        $params['git_token'] = RequestVault::resolve('git_token', $input->gitToken, $params['username']);
        if (isset($params['env_vars']) && is_array($params['env_vars'])) {
            $params['env_vars'] = RequestVault::resolve('env_vars', $input->envVars, $params['username']);
        }
        if ($input->deployKey !== null) {
            $params['git_deploy_key'] = VaultDeployKeys::resolve('git_deploy_key', $input->deployKey, $params['username']);
        }

        return $params;
    }

    /**
     * The only check that leaves the machine, so after the local ones and before a panelalpha.online label is spent. It runs in core,
     * which reaches what the project's network refuses, so those go first; it uses the clone's token (or none) and asks for the branch.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ProblemException
     */
    private static function assertRepositoryReachable(array $params): void
    {
        $deployKey = $params['git_deploy_key'] ?? null;
        if (is_array($deployKey)) {
            self::assertSshHostPinned($params['git_repo'], $deployKey);
        }
        $refused = self::refusedRepositoryAddress($params['git_repo']);
        if ($refused !== null) {
            throw ProblemException::one('git_repo', 'host_refused', $refused);
        }
        $probe = app(GitRemoteProbe::class)->problem(
            'git_repo',
            $params['git_repo'],
            $params['git_token'],
            'git_token',
            $params['git_branch'] ?? null,
            'git_branch',
            is_array($deployKey) ? $deployKey : null,
        );
        if ($probe !== null) {
            throw ProblemException::of([$probe]);
        }
    }

    /**
     * The clone checks the server's host key strictly, so a host the key does
     * not pin is refused at create rather than at clone time.
     *
     * @param  array<string, mixed>  $deployKey
     *
     * @throws ProblemException
     */
    private static function assertSshHostPinned(string $repoUrl, array $deployKey): void
    {
        $host = GitRepoInput::sshHost($repoUrl);
        if ($host === null || DeployKey::pinsIn($deployKey, $host)) {
            return;
        }

        throw ProblemException::one('git_repo', 'git_repo_ssh_host_not_pinned',
            "The deploy key does not pin the SSH host key of {$host}, so the clone could not verify the server. "
            ."Create the key in the vault with {$host} as its host.", ['host' => $host]);
    }

    /**
     * Seconds, and never a refusal: what it finds is reported in the response and the
     * deploy log, and the create goes ahead.
     *
     * @param  array<string, mixed>  $params
     */
    private static function inspectRepository(array $params, NewProjectInput $input): CreateInspection
    {
        return app(CreateInspector::class)->inspect(
            $params['git_repo'],
            $params['git_branch'] ?? null,
            $params['git_token'] ?? null,
            RecipeChoiceInput::parse($input->recipe),
            DeployPlanInput::parse($input->stages),
        );
    }

    /**
     * Chosen before anything is created: a panelalpha.online label is bought from the
     * licensing proxy, and a taken one must be found while another can still be picked.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ProblemException
     */
    private static function allocateDomain(array $params): AllocatedDomain
    {
        try {
            return app(DomainAllocator::class)->allocate(
                $params['username'],
                $params['domain'] ?? null,
                $params['tunnel'] ?? null,
            );
        } catch (DomainAllocationException $e) {
            throw ProblemException::one('domain', $e->reason, $e->getMessage());
        }
    }

    /**
     * The allocator only offers a name it has just found free, so this catches a race,
     * which is worth a 422 rather than a duplicate.
     *
     * @throws ProblemException domain_taken
     */
    private static function assertAllocatedDomainFree(string $domain): void
    {
        if (Domain::existsByName($domain)) {
            throw ProblemException::one(
                'domain',
                'domain_taken',
                "{$domain} is already on this engine."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  mixed  $sentEnvVars  the env_vars as sent, before their vault references were read
     */
    private static function newUser(array $params, AllocatedDomain $allocated, mixed $sentEnvVars, ?CreateFollowUp $followUp = null): User
    {
        $envVars = EnvVarOverrides::applyIncoming($params['env_vars'] ?? [], []);
        /** @var User */
        $user = User::make([
            'username' => $params['username'],
            'domain' => $params['domain'],
            'email' => $params['email'] ?? null,
            'status' => 'active',
            'details' => NewProjectDetails::build($params, $allocated->toDetails(), $envVars)
                + array_filter(['source' => $followUp?->sourceDetails()]),
        ]);
        $user->setEnvVars($envVars, $sentEnvVars);

        if (! empty($params['password']) && is_string($params['password'])) {
            self::enableSitePassword($user, $params['password']);
        }

        return $user;
    }

    private static function enableSitePassword(User $user, string $password): void
    {
        $details = $user->getDetails();
        $details['site_password_enabled'] = true;
        $details['site_password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        $details['site_password_version'] = 1;
        $user->details = $details;
    }

    /**
     * Asked again with the username on the model, which ProvisionChecks cannot see: a name left with a home or OS user by an
     * unfinished rollback would otherwise fail inside the deploy instead of as a 422 the caller can act on.
     *
     * @throws ProblemException
     */
    private static function assertNameStillFree(User $user, string $domain): void
    {
        if ($user->project()->hostingExists()) {
            throw ProblemException::one('username', 'name_unavailable', ProjectNameRefusal::heldOnHost($user->username, app(System::class)));
        }

        if (
            Domain::domainOrAliasExists($domain)
        ) {
            throw ProblemException::one('domain', 'domain_taken', 'Domain name not available.');
        }
        $claim = HostDomain::claimRefusal($user->username, $domain);
        if ($claim !== null) {
            throw ProblemException::one('domain', $claim['code'], $claim['message']);
        }
    }

    /**
     * The main domain row, built once the user is saved. A name whose zone answers only for the exact label registered
     * gets no `www.`: it would reach something that has never heard of it and fail TLS on the way.
     *
     * @param  array<string, mixed>  $params
     * @return \Closure(User): Domain
     */
    private static function mainDomainFor(array $params): \Closure
    {
        $redirectEnabled = false;
        $redirectUrl = null;
        if (! empty($params['domain_redirect_url'])) {
            $redirectEnabled = true;
            $redirectUrl = $params['domain_redirect_url'];
        }

        $aliasAvailable = DomainPlan::wwwAliasWouldAnswer($params['domain'])
            && ! Domain::domainOrAliasExists('www.'.$params['domain']);

        return fn (User $user): Domain => Domain::make([
            'user_id' => $user->id,
            'domain' => $params['domain'],
            'type' => 'main',
            'details' => [
                'document_root' => "/{$params['domain']}/public_html",
                'redirect_enabled' => $redirectEnabled,
                'redirect_url' => $redirectUrl,
                'aliases' => $aliasAvailable ? ['www.'.$params['domain']] : [],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function assignDedicatedIps(User $user, array $params): void
    {
        if (! empty($params['dedicated_ipv4']) && ! $user->assignFreeDedicatedIpv4()) {
            self::dropUnassignedDedicatedIp($user, 'dedicated_ipv4');
        }

        if (! empty($params['dedicated_ipv6']) && ! $user->assignFreeDedicatedIpv6()) {
            self::dropUnassignedDedicatedIp($user, 'dedicated_ipv6');
        }
    }

    /**
     * Ties the public name bought before the account existed to the project's own domain, the one arrangement where the
     * Host the proxy forwards is the name a visitor typed.
     */
    private static function recordOnlineTunnel(User $user, Domain $domain, AllocatedDomain $allocated): void
    {
        if ($allocated->tunnelProvider === Tunnel::PROVIDER_PANELALPHA && $allocated->allocation !== null) {
            PanelAlphaConnect::recordPanelAlphaTunnel($user, $domain, $allocated->allocation);
        }
    }

    /**
     * Two creates of one name can both pass provision()'s checks: the unique index picks the winner, and the loser gets a
     * duplicate's 422, not a 500. One transaction, so the loser leaves no user row without its main domain.
     *
     * @param  \Closure(User): Domain  $mainDomain
     * @param  string  $nameField  the field the caller sent the name as
     */
    private static function saveNewProject(User $user, \Closure $mainDomain, string $nameField = 'username'): Domain
    {
        try {
            return DB::transaction(function () use ($user, $mainDomain): Domain {
                $user->save();
                $domain = $mainDomain($user);
                $domain->save();

                return $domain;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (User::existsByUsername($user->username)) {
                throw ProblemException::one($nameField, 'name_taken', ProjectNameRefusal::taken($user->username));
            }
            if (Domain::domainOrAliasExists($user->domain)) {
                throw ProblemException::one('domain', 'domain_taken', "{$user->domain} is already on this engine.");
            }
            throw $e;
        }
    }

    /**
     * Why core must not contact the repository's host, when it is one the tenant
     * network refuses or one PHP cannot resolve to check; null otherwise.
     */
    private static function refusedRepositoryAddress(string $repoUrl): ?string
    {
        $problem = RepositoryAddress::problem($repoUrl, 'git_repo');

        return isset($problem['message']) ? (string) $problem['message'] : null;
    }

    /**
     * ProvisionChecks refused a create with no free address, so only a race
     * lands here: record that the project has none rather than report one.
     */
    private static function dropUnassignedDedicatedIp(User $user, string $key): void
    {
        $details = $user->getDetails();
        $details[$key] = false;
        $user->details = $details;
        $user->save();
        Log::warning("No free address left for {$key} of {$user->username}; created without one");
    }
}

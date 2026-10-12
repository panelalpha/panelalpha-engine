<?php

namespace App\Lib\Project;

use App\Exceptions\ProblemException;
use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\RecipeChoiceInput;
use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Jobs\DeployProject;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\EnvVarOverrides;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\ProjectName;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\Source\RepositoryAddress;
use App\Lib\Domains\AllocatedDomain;
use App\Lib\Domains\DomainAllocationException;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Domains\DomainPlan;
use App\Lib\Helper;
use App\Lib\Vault\RequestVault;
use App\Models\Domain;
use App\Models\Task;
use App\Models\Tunnel;
use App\Models\User;
use App\Rules\ProjectName as ProjectNameRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating a project: provision the account and its main domain, then deploy
 * into it -- in this process (POST /users, `project:create`) or on the queue
 * (POST /projects, `system:example:create`).
 */
class ProjectCreator
{
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
     * Arm this process's deploy with the plan and recipe it was given, then
     * provision. Armed before provision, so a malformed one is a 422 with no
     * account behind it; nothing about either is stored, so the next deploy is
     * back on detection.
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
        if (!self::writesDeployLog($user)) {
            return null;
        }

        $deployLogger = DeployLogger::startSafely($user->username);
        if ($deployLogger !== null) {
            $gitRepo = $user->getGitRepo();
            $deployLogger->info($gitRepo
                ? "Deploy started (source: git, repo: " . GitUrl::sanitize($gitRepo) . ")"
                : 'Deploy started (source: dind template)');
            $this->inspection?->writeTo($deployLogger);
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
     * Throws ValidationException after finishing the deploy log
     * (cancelled/failed) and cleaning up on errors. A deploy log holds the account's
     * lock; a plain template writes none, so the lock is taken here, or a delete
     * would see this create as idle and tear the account down under it.
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
    public function queue(NewProjectInput $input): Task
    {
        $plan = DeployPlanInput::parse($input->stages);
        $stages = $plan?->toArray();
        $recipe = RecipeChoiceInput::parse($input->recipe);

        $user = $this->provision($input);

        $task = Task::start(
            jobType: DeployProject::class,
            queue: 'default',
            username: $user->username,
            details: [
                'username' => $user->username,
                'domain' => $user->domain,
                'action' => 'deploy',
            ],
        );
        DeployProject::dispatch($user->username, $stages, $recipe, $this->inspection?->toArray(), (int) $user->getKey())
            ->attachTask($task);

        return $task;
    }

    /**
     * Validate, allocate a domain, and persist the user + main domain.
     * Shared by the sync and async create paths; does not start a deploy.
     */
    private function provision(NewProjectInput $input): User
    {
        $this->inspection = null;

        $params = self::namedParams($input);
        self::assertRequestChecksPass($params, $input->nameField);
        $params = self::resolvedSource($params, $input);

        if (!empty($params['git_repo'])) {
            $this->inspection = self::inspectRepository($params, $input);
        }

        $allocated = self::allocateDomain($params);
        $params['domain'] = $allocated->domain;
        self::assertAllocatedDomainFree($params['domain']);

        if (!empty($params['git_repo']) && empty($params['template'])) {
            $params['template'] = 'dind';
        }

        $user = self::newUser($params, $allocated);
        self::assertNameStillFree($user, $params['domain']);

        $domain = self::saveNewProject($user, self::mainDomainFor($params), $input->nameField);

        self::assignDedicatedIps($user, $params);
        self::recordOnlineTunnel($user, $domain, $allocated);

        return $user;
    }

    /**
     * The validated params with a project name. A create need not name itself: it is
     * named after the repository, domain or recipe it carries, or a plain "app" when it
     * carries none, which the one-line installer (`--repo`) relies on.
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
     * @throws ProblemException username_required
     */
    private static function namedParams(NewProjectInput $input): array
    {
        $params = $input->params;

        if (
            !empty($params['domain'])
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
     * @param array<string, mixed> $params
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
                    . ProjectNameRule::EXPECTED . ').'
            );
        }

        return $name;
    }

    /**
     * Everything knowable from the request alone, asked at once and answered at once.
     *
     * @param array<string, mixed> $params
     * @throws ProblemException
     */
    private static function assertRequestChecksPass(array $params, string $nameField): void
    {
        $problems = (new ProvisionChecks(new SystemProvisionEnvironment()))->problems($params, $nameField);

        if ($problems !== []) {
            throw ProblemException::of($problems);
        }
    }

    /**
     * The source a deploy will clone: its `vault:` references read for this
     * project, and the remote checked before anything is created or changed.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws ProblemException
     */
    public static function resolvedSource(array $params, NewProjectInput $input): array
    {
        $params = self::withVaultResolved($params, $input);
        if (!empty($params['git_repo'])) {
            self::assertRepositoryReachable($params);
        }

        return $params;
    }

    /**
     * Vault references are read once the name is known to be free, so an entry is assigned to
     * this project, and before the allocator spends a label on a create that would fail here.
     * The project stores the secrets themselves; nothing reads the vault on its behalf later.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function withVaultResolved(array $params, NewProjectInput $input): array
    {
        $params['git_token'] = RequestVault::resolve('git_token', $input->gitToken, $params['username']);
        if (isset($params['env_vars']) && is_array($params['env_vars'])) {
            $params['env_vars'] = RequestVault::resolve('env_vars', $input->envVars, $params['username']);
        }

        return $params;
    }

    /**
     * The only check that leaves the machine, so it runs after the local ones and before the
     * allocator spends a panelalpha.online label, which is never released. The probe runs in
     * core, which reaches addresses the project's own network refuses, so those are refused
     * first. It uses the token the clone will (none: anonymous) and asks for the branch too.
     *
     * @param array<string, mixed> $params
     * @throws ProblemException
     */
    private static function assertRepositoryReachable(array $params): void
    {
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
        );
        if ($probe !== null) {
            throw ProblemException::of([$probe]);
        }
    }

    /**
     * Seconds, and never a refusal: what it finds is reported in the response and the
     * deploy log, and the create goes ahead.
     *
     * @param array<string, mixed> $params
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
     * @param array<string, mixed> $params
     * @throws ProblemException
     */
    private static function allocateDomain(array $params): AllocatedDomain
    {
        try {
            return (new DomainAllocator())->allocate(
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
     * @param array<string, mixed> $params
     */
    private static function newUser(array $params, AllocatedDomain $allocated): User
    {
        /** @var User */
        $user = User::make([
            'username' => $params['username'],
            'domain' => $params['domain'],
            'email' => $params['email'] ?? null,
            'details' => NewProjectDetails::build(
                $params,
                $allocated->toDetails(),
                EnvVarOverrides::applyIncoming($params['env_vars'] ?? [], []),
            ),
        ]);

        if (!empty($params['password']) && is_string($params['password'])) {
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
     * Asked again with the username on the model, which ProvisionChecks cannot see: a name
     * left with a home directory or OS user by a failed deploy whose rollback did not finish
     * would otherwise fail inside the deploy instead of as a 422 the caller can act on.
     *
     * @throws ProblemException
     */
    private static function assertNameStillFree(User $user, string $domain): void
    {
        if ($user->project()->hostingExists()) {
            throw ProblemException::one('username', 'name_unavailable', 'Username not available.');
        }

        if (
            Domain::domainOrAliasExists($domain)
        ) {
            throw ProblemException::one('domain', 'domain_taken', 'Domain name not available.');
        }
    }

    /**
     * The main domain row, built once the user is saved. A name whose zone answers only for
     * the exact label registered gets no `www.`: it would reach something that has never heard
     * of it and fail TLS on the way.
     *
     * @param array<string, mixed> $params
     * @return \Closure(User): Domain
     */
    private static function mainDomainFor(array $params): \Closure
    {
        $redirectEnabled = false;
        $redirectUrl = null;
        if (!empty($params['domain_redirect_url'])) {
            $redirectEnabled = true;
            $redirectUrl = $params['domain_redirect_url'];
        }

        $aliasAvailable = DomainPlan::wwwAliasWouldAnswer($params['domain'])
            && !Domain::domainOrAliasExists('www.' . $params['domain']);

        return fn (User $user): Domain => Domain::make([
            'user_id' => $user->id,
            'domain' => $params['domain'],
            'type' => 'main',
            'details' => [
                'document_root' => "/{$params['domain']}/public_html",
                'redirect_enabled' => $redirectEnabled,
                'redirect_url' => $redirectUrl,
                'aliases' => $aliasAvailable ? ['www.' . $params['domain']] : [],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function assignDedicatedIps(User $user, array $params): void
    {
        if (!empty($params['dedicated_ipv4']) && !$user->assignFreeDedicatedIpv4()) {
            self::dropUnassignedDedicatedIp($user, 'dedicated_ipv4');
        }

        if (!empty($params['dedicated_ipv6']) && !$user->assignFreeDedicatedIpv6()) {
            self::dropUnassignedDedicatedIp($user, 'dedicated_ipv6');
        }
    }

    /**
     * Ties the public name bought before the account existed to the domain it now serves:
     * the project's own domain, the one arrangement where the Host the proxy forwards is
     * the name a visitor typed.
     */
    private static function recordOnlineTunnel(User $user, Domain $domain, AllocatedDomain $allocated): void
    {
        if ($allocated->tunnelProvider === Tunnel::PROVIDER_PANELALPHA && $allocated->allocation !== null) {
            PanelAlphaConnect::recordPanelAlphaTunnel($user, $domain, $allocated->allocation);
        }
    }

    /**
     * Two creates of one name can both pass the checks in provision(); the
     * unique index picks the winner, and the loser gets the 422 a sequential
     * duplicate gets instead of a 500. One transaction, so the
     * loser leaves no user row without its main domain.
     *
     * @param \Closure(User): Domain $mainDomain
     * @param string $nameField the field the caller sent the name as
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
                throw ProblemException::one(
                    $nameField,
                    'name_taken',
                    "A project named '{$user->username}' already exists. Choose another name."
                );
            }
            if (Domain::domainOrAliasExists($user->domain)) {
                throw ProblemException::one('domain', 'domain_taken', "{$user->domain} is already on this engine.");
            }
            throw $e;
        }
    }

    /**
     * Why core must not contact the repository's host, when an address it has
     * is one the tenant network refuses; null otherwise, or when it does not
     * resolve (the probe says that).
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

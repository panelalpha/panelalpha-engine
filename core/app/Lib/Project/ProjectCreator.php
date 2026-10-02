<?php

namespace App\Lib\Project;

use App\Exceptions\ProblemException;
use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\RecipeChoiceInput;
use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Jobs\DeployProject;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\EnvVarOverrides;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\ProjectName;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Deploy\Source\GitUrl;
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
        if (!$user->hasGitProject() && $user->getTemplate() !== 'dind') {
            return null;
        }

        $deployLogger = DeployLogger::startSafely($user->username);
        if ($deployLogger !== null) {
            $gitRepo = $user->getGitRepo();
            $deployLogger->info($gitRepo
                ? "Deploy started (source: git, repo: " . GitUrl::sanitize($gitRepo) . ")"
                : 'Deploy started (source: dind template)');
        }

        return $deployLogger;
    }

    /**
     * Throws ValidationException after finishing the deploy log
     * (cancelled/failed) and cleaning up on errors.
     */
    public function deploy(User $user, ?DeployLogger $deployLogger): void
    {
        $user->project()->runDeployment($deployLogger, null);
    }

    /**
     * Provision here and leave the deploy to a DeployProject job.
     *
     * @throws ValidationException
     */
    public function queue(NewProjectInput $input): Task
    {
        // Parsed before provision: a malformed plan or recipe id must not
        // leave an account behind with no deploy queued for it.
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
        DeployProject::dispatch($user->username, $stages, $recipe)->attachTask($task);

        return $task;
    }

    /**
     * Validate, allocate a domain, and persist the user + main domain.
     * Shared by the sync and async create paths; does not start a deploy.
     */
    private function provision(NewProjectInput $input): User
    {
        /** @var array{
         *   username?: ?string,
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
         * } $params
         */
        $params = $input->params;
        $nameField = $input->nameField;

        if (
            !empty($params['domain'])
            && Str::startsWith($params['domain'], 'www.')
        ) {
            $params['domain'] = Str::after($params['domain'], 'www.');
        }

        // Nothing here is required of the caller: a create with no body at all
        // names itself after whatever the request does carry -- the repository,
        // the domain, the recipe -- and takes a plain "app" when it carries
        // nothing. The one-line installer (`--repo`) relies on it, and so does
        // every agent that has a repository and no opinion about the name.
        if (empty($params['username'])) {
            $params['username'] = Helper::generateUsernameFrom(ProjectName::base(
                $params['git_repo'] ?? null,
                $params['domain'] ?? null,
                $params['recipe'] ?? null,
            ));
        }
        if (empty($params['username'])) {
            throw ProblemException::one(
                $nameField,
                'username_required',
                'No project name was given and none could be generated; pass `name` ('
                    . ProjectNameRule::EXPECTED . ').'
            );
        }

        // Everything knowable from the request alone, asked at once and
        // answered at once. {@see ProvisionChecks}
        $problems = (new ProvisionChecks(new SystemProvisionEnvironment()))->problems($params, $nameField);

        if ($problems !== []) {
            throw ProblemException::of($problems);
        }

        // Vault references are read once the name is known to be free, so a
        // project entry is assigned to this project, and before the allocator
        // spends a label on a create that would fail here. The project stores
        // the secrets themselves; nothing reads the vault on its behalf later.
        $params['git_token'] = RequestVault::resolve('git_token', $input->gitToken, $params['username']);
        if (isset($params['env_vars']) && is_array($params['env_vars'])) {
            $params['env_vars'] = RequestVault::resolve('env_vars', $input->envVars, $params['username']);
        }

        // After the local checks because it is the only one that leaves the
        // machine; before the allocator because everything past it spends a
        // panelalpha.online label, and those are never released.
        if (!empty($params['git_repo'])) {
            $probe = (new GitRemoteProbe())->problem(
                'git_repo',
                $params['git_repo'],
                // The token the clone will use. None sent means an anonymous
                // probe, as the clone will be.
                $params['git_token'],
                'git_token',
                // Asked in the same round trip: a branch the remote lacks
                // used to be accepted here and fail the deploy at clone.
                $params['git_branch'] ?? null,
            );
            if ($probe !== null) {
                throw ProblemException::of([$probe]);
            }
        }

        // The name, and everything about it worth reporting. Chosen before
        // anything is created: a panelalpha.online label is bought from the
        // licensing proxy, and a label that turns out to be taken has to be
        // discovered while another one can still be picked -- not after an
        // account has been built around the first guess.
        try {
            $allocated = (new DomainAllocator())->allocate(
                $params['username'],
                $params['domain'] ?? null,
                $params['tunnel'] ?? null,
            );
        } catch (DomainAllocationException $e) {
            throw ProblemException::one('domain', $e->reason, $e->getMessage());
        }
        $params['domain'] = $allocated->domain;

        // The allocator only offers a name it has just found free, so this
        // catches a race rather than a mistake -- kept because losing one is
        // worth a 422 rather than a duplicate.
        if (Domain::existsByName($params['domain'])) {
            throw ProblemException::one(
                'domain',
                'domain_taken',
                "{$params['domain']} is already on this engine."
            );
        }

        // Auto-set template to 'dind' for Git repo users
        if (!empty($params['git_repo']) && empty($params['template'])) {
            $params['template'] = 'dind';
        }

        $dedicatedIpv4 = !empty($params['dedicated_ipv4']);
        $dedicatedIpv6 = !empty($params['dedicated_ipv6']);

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
            $details = $user->getDetails();
            $details['site_password_enabled'] = true;
            $details['site_password_hash'] = password_hash($params['password'], PASSWORD_BCRYPT);
            $details['site_password_version'] = 1;
            $user->details = $details;
        }

        // Asked again with the username on the model, which the check above
        // cannot see: that one builds a fresh `System` from `$params` while
        // this row is only in memory, so a username that already has a home
        // directory or an OS user passes it and is caught here instead -- as a
        // bare \Exception thrown inside the deploy pipeline's `preparing`
        // stage, surfacing as `code: deploy_failed` rather than as a 422 about
        // the name.
        //
        // What leaves such debris is a deploy that failed *after* createDirs()
        // and whose rollback did not finish: suroi's test found `/home/suroi`
        // with no database row and no container at all. Naming it here means
        // the caller hears "that name is taken" and can choose another, which
        // is the only thing it can do about it either way.
        if ($user->project()->hostingExists()) {
            throw ProblemException::one('username', 'name_unavailable', 'Username not available.');
        }

        if (
            Domain::domainOrAliasExists($params['domain'])
        ) {
            throw ProblemException::one('domain', 'domain_taken', 'Domain name not available.');
        }

        $redirectEnabled = false;
        $redirectUrl = null;
        if (!empty($params['domain_redirect_url'])) {
            $redirectEnabled = true;
            $redirectUrl = $params['domain_redirect_url'];
        }

        // Free to take, and worth taking. A name whose zone answers only for
        // the exact label registered gets no `www.`: it would resolve, reach
        // something that has never heard of it, and fail TLS on the way.
        $aliasAvailable = DomainPlan::wwwAliasWouldAnswer($params['domain'])
            && !Domain::domainOrAliasExists('www.' . $params['domain']);

        $domain = self::saveNewProject($user, fn (User $user): Domain => Domain::make([
            'user_id' => $user->id,
            'domain' => $params['domain'],
            'type' => 'main',
            'details' => [
                'document_root' => "/{$params['domain']}/public_html",
                'redirect_enabled' => $redirectEnabled,
                'redirect_url' => $redirectUrl,
                'aliases' => $aliasAvailable ? ['www.' . $params['domain']] : [],
            ],
        ]), $nameField);

        if ($dedicatedIpv4 && !$user->assignFreeDedicatedIpv4()) {
            self::dropUnassignedDedicatedIp($user, 'dedicated_ipv4');
        }

        if ($dedicatedIpv6 && !$user->assignFreeDedicatedIpv6()) {
            self::dropUnassignedDedicatedIp($user, 'dedicated_ipv6');
        }

        // The public name was bought before the account existed; this is the
        // row that ties it to the domain it now serves. Attached to the
        // project's own domain by construction, which is the one arrangement
        // where the Host the proxy forwards is the name a visitor typed.
        if ($allocated->tunnelProvider === Tunnel::PROVIDER_PANELALPHA && $allocated->allocation !== null) {
            PanelAlphaConnect::recordPanelAlphaTunnel($user, $domain, $allocated->allocation);
        }

        return $user;
    }

    /**
     * Two creates of one name can both pass the checks in provision(); the
     * unique index picks the winner, and the loser gets the 422 a sequential
     * duplicate gets instead of a 500 (engine#8). One transaction, so the
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

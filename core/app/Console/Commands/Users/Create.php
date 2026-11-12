<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\FollowsDeployLog;
use App\Console\Commands\Concerns\ParsesEnvVarOptions;
use App\Console\Commands\Concerns\PrintsFindings;
use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DockerErrorException;
use App\Http\Resources\UserResource;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Hints\FindingPresenter;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System\Project\Settings;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * The CLI twin of POST /users, and what the one-line installer
 * (`installer.sh --repo …`) calls once the engine is up. Every detail is
 * optional: the account name, the domain and the template are generated from
 * the repository, so `pae project:create --repo owner/repo` is a whole install.
 *
 * The synchronous create on purpose -- the deploy runs in this process, so the
 * command exits when the application is actually live and its exit code says
 * whether it is. A queued create would hand back a task id and leave the
 * caller polling.
 */
class Create extends Command
{
    use FollowsDeployLog;
    use ParsesEnvVarOptions;
    use PrintsFindings;

    /** The plural spellings the other project commands answer to. */
    protected $aliases = ['projects:create', 'users:create'];

    protected $signature = 'project:create
                            {--repo= : Git repository to deploy: a clone URL, host/owner/repo, or owner/repo on the default forge}
                            {--branch= : Branch, tag or commit to deploy; the repository default when omitted}
                            {--git-token= : HTTPS access token for a private repository}
                            {--project= : Account username; generated from the repository when omitted}
                            {--domain= : Main domain; one is allocated when omitted}
                            {--email= : Contact email for the project}
                            {--template= : Project template; dind when a repository is given}
                            {--recipe= : Deploy with this recipe instead of the detected one}
                            {--env-var=* : KEY=VALUE environment override, repeatable}
                            {--full-privileges : Trust the application with this engine: an Engine API token for every project, the server\'s own name and public ports, and protection from API tokens once it is live}
                            {--update-existing : When a project already deploys this repository, deploy it again from it instead of creating another (the one-line installer run a second time)}
                            {--quiet-deploy : Do not print the deploy log while it runs}
                            {--json : Print the created project as JSON instead of a summary}';

    protected $description = 'Create a project and deploy a repository into it; details not given are generated';

    public function handle(ProjectCreator $creator): int
    {
        $params = [];
        foreach ([
            'username' => 'project',
            'domain' => 'domain',
            'email' => 'email',
            'template' => 'template',
            'recipe' => 'recipe',
            'git_branch' => 'branch',
            'git_token' => 'git-token',
        ] as $field => $option) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                $params[$field] = trim($value);
            }
        }

        $repo = $this->option('repo');
        if (is_string($repo) && trim($repo) !== '') {
            $params['git_repo'] = GitRepoInput::expandShorthand($repo, self::shorthandHost());
        }

        $fullPrivileges = (bool) $this->option('full-privileges');
        if ($fullPrivileges && ! isset($params['git_repo']) && ($params['template'] ?? null) !== 'dind') {
            $this->error('--full-privileges needs a container project: give --repo, or --template=dind.');

            return 1;
        }

        if ($this->option('update-existing') && isset($params['git_repo'])) {
            $existing = $creator->projectsDeploying($params['git_repo']);
            if (count($existing) > 1) {
                $names = implode(', ', array_map(static fn (User $user): string => $user->username, $existing));
                $this->error("More than one project deploys {$params['git_repo']} ({$names}): update the one you mean with pae project:rebuild --project=<name>.");

                return 1;
            }
            if ($existing !== []) {
                return $this->updateExisting($existing[0], $params['git_repo'], $fullPrivileges, $creator);
            }
        }

        $envVars = $this->envVarOptions();
        if ($envVars === null) {
            return 1;
        }
        if ($envVars !== []) {
            $params['env_vars'] = $envVars;
        }

        // Validated and provisioned first, as POST /users does: a refused
        // create prints only its reason, not a progress line and a log hint.
        $user = $creator->provisionForDeploy(NewProjectInput::fromArray($params));

        if (! $this->option('json')) {
            $this->info(isset($params['git_repo'])
                ? "Creating a project for {$params['git_repo']} — this takes a few minutes."
                : 'Creating a project — this takes a few minutes.');
        }
        if ($fullPrivileges) {
            $this->grantFullPrivileges($user);
        }

        // The deploy runs inside this process, and DeployLogStream is
        // process-local, so the command can subscribe to the same lines the
        // NDJSON API stream carries and print them as they happen -- no
        // polling, no second process, and nothing to miss between reads.
        $this->followDeployLog();

        try {
            $creator->deploy($user, $creator->startDeployLog($user));
        } catch (ValidationException|DeployAlreadyRunningException|DockerErrorException $e) {
            $this->warnAboutRepository($creator, $user->username);
            // The hint first: the console renderer prints the reason after it.
            $this->pointAtDeployLog($params);

            throw $e;
        } finally {
            $this->stopFollowingDeployLog();
        }

        if ($fullPrivileges) {
            $this->protect($user);
        }

        return $this->report($this->created($user, $creator), $creator);
    }

    /**
     * `--update-existing` found the project: deploy it again from its repository. Its name, domain, settings, data,
     * environment and git token stay as they are, so the --env-var, --email, --branch and --git-token of this run are
     * not applied; the JSON says `updated: true` so a caller does not report the values it generated for a new one.
     */
    private function updateExisting(User $user, string $repo, bool $fullPrivileges, ProjectCreator $creator): int
    {
        if (! $this->option('json')) {
            $this->info("Updating project {$user->username}, which already deploys {$repo} — this takes a few minutes.");
        }
        // Granted again: an earlier run without --full-privileges, or one revoked since, is what this run asks for.
        if ($fullPrivileges) {
            $this->grantFullPrivileges($user);
        }

        $this->followDeployLog();
        try {
            $creator->redeploy($user);
        } finally {
            $this->stopFollowingDeployLog();
        }

        if ($fullPrivileges && ! $user->fresh()->isProtected()) {
            $this->protect($user);
        }

        $user->refresh();
        if ($this->option('json')) {
            /** @var array<string, mixed> $payload */
            $payload = (new UserResource($user))->response()->getData(true);
            $payload['data']['updated'] = true;
            $this->output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->info("Project {$user->username} updated.");
        if ($user->domain) {
            $this->info('Live at '.$this->url($user->domain));
        }

        return 0;
    }

    /**
     * `system:api-access:allow --scope=all` and `system:public-routing:allow --host-domain --ports`, before the first
     * deploy so it already carries both. No apply is queued, which would race the deploy for its lock.
     */
    private function grantFullPrivileges(User $user): void
    {
        app(ApiAccess::class)->allow($user, ApiAccess::SCOPE_ALL, false, deliver: false);
        app(ProjectPublicRouting::class)->allow($user, [PublicRoutingGrants::PORTS, PublicRoutingGrants::HOST_DOMAIN]);
        $this->deployOutput()->writeln(
            "Allowed {$user->username} to use the Engine API for every project, and to take the server's own name and public ports."
        );
    }

    /** `project:settings:set protected on`, once the application is live: no API or MCP token may then delete, stop or redeploy it. */
    private function protect(User $user): void
    {
        $user->refresh();
        $result = $user->project()->settings()->set(Settings::KEY_PROTECTED, User::PROTECTED_ON);
        $this->deployOutput()->writeln((string) ($result['message'] ?? "Project {$user->username} is protected."));
    }

    /** The created project as POST /users answers it, `inspection` included. */
    private function created(User $user, ProjectCreator $creator): string
    {
        $response = (new UserResource($user))->response();
        $payload = $response->getData(true);
        if (is_array($payload) && is_array($payload['data'] ?? null)) {
            $payload['data']['inspection'] = FindingPresenter::inspection($creator->inspection()?->toResponse(), $user->username);
            $response->setData($payload);
        }

        return (string) $response->getContent();
    }

    /** What the inspection before the clone found, when it may not deploy. */
    private function warnAboutRepository(ProjectCreator $creator, string $username): void
    {
        $warning = $creator->inspection()?->warning();
        if ($warning === null) {
            return;
        }
        // stderr under --json, which already carries it as `inspection`.
        if ($this->option('json')) {
            $this->deployOutput()->writeln($warning);
        } else {
            $this->warn($warning);
            $this->printCliHints(FindingPresenter::present([$creator->inspection()->finding()], $username)[0]['hints'] ?? []);
        }
    }

    /**
     * A failed deploy is rolled back but its log is kept.
     *
     * @param  array<string, mixed>  $params
     */
    private function pointAtDeployLog(array $params): void
    {
        if (isset($params['git_repo'])) {
            $this->line('Deploy log: pae project:deploy:list');
        }
    }

    /**
     * The forge a bare `owner/repo` is taken to mean. GitHub unless an
     * operator says otherwise: `pae settings:set default_git_host gitlab.com`.
     * A full URL never consults this.
     */
    private static function shorthandHost(): ?string
    {
        $host = Setting::get('default_git_host');

        return is_string($host) && trim($host) !== '' ? $host : null;
    }

    /** The created project, as JSON or as the two lines an installer prints. */
    private function report(string $body, ProjectCreator $creator): int
    {
        if ($this->option('json')) {
            $this->output->writeln($body);

            return 0;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $username = is_string($data['username'] ?? null) ? $data['username'] : '';
        $domain = is_string($data['domain'] ?? null) ? $data['domain'] : '';

        if ($username === '') {
            $this->error('The project was created but the response carried no username.');

            return 1;
        }

        $this->info("Project {$username} created.");
        if ($domain !== '') {
            $this->info('Live at '.$this->url($domain));
        }
        $this->warnAboutRepository($creator, $username);

        $this->warnAboutPublicUrl($username, $domain);

        return 0;
    }

    /**
     * Said here rather than left to be discovered in a browser: a host with no license or no public address lands the
     * project on a name that resolves nowhere, and the deploy itself succeeded all the same.
     */
    private function warnAboutPublicUrl(string $username, string $domain): void
    {
        $user = User::findByUsername($username);
        if ($user === null || $domain === '') {
            return;
        }

        $details = $user->getDetails();
        $this->printFindings(PublicUrl::warnings($domain, $details), $username);
        foreach (PublicUrl::notices($domain, $details) as $notice) {
            $this->warn($notice);
        }
    }

    /** The scheme the project's own domain is served on. */
    private function url(string $domain): string
    {
        $model = Domain::findByName($domain);
        $details = $model !== null && is_array($model->details) ? $model->details : [];

        return (empty($details['ssl_disabled']) ? 'https://' : 'http://').$domain;
    }
}

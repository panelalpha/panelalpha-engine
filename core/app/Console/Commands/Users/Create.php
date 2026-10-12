<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\FollowsDeployLog;
use App\Console\Commands\Concerns\ParsesEnvVarOptions;
use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DockerErrorException;
use App\Http\Resources\UserResource;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Domains\PublicUrl;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
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

        if (!$this->option('json')) {
            $this->info(isset($params['git_repo'])
                ? "Creating a project for {$params['git_repo']} — this takes a few minutes."
                : 'Creating a project — this takes a few minutes.');
        }

        // The deploy runs inside this process, and DeployLogStream is
        // process-local, so the command can subscribe to the same lines the
        // NDJSON API stream carries and print them as they happen -- no
        // polling, no second process, and nothing to miss between reads.
        $this->followDeployLog();

        try {
            $creator->deploy($user, $creator->startDeployLog($user));
        } catch (ValidationException | DeployAlreadyRunningException | DockerErrorException $e) {
            $this->warnAboutRepository($creator);
            // The hint first: the console renderer prints the reason after it.
            $this->pointAtDeployLog($params);

            throw $e;
        } finally {
            $this->stopFollowingDeployLog();
        }

        return $this->report($this->created($user, $creator), $creator);
    }

    /** The created project as POST /users answers it, `inspection` included. */
    private function created(User $user, ProjectCreator $creator): string
    {
        $response = (new UserResource($user))->response();
        $payload = $response->getData(true);
        if (is_array($payload) && is_array($payload['data'] ?? null)) {
            $payload['data']['inspection'] = $creator->inspection()?->toResponse();
            $response->setData($payload);
        }

        return (string) $response->getContent();
    }

    /** What the inspection before the clone found, when it may not deploy. */
    private function warnAboutRepository(ProjectCreator $creator): void
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
        }
    }

    /**
     * A failed deploy is rolled back but its log is kept.
     *
     * @param array<string, mixed> $params
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
            $this->info('Live at ' . $this->url($domain));
        }
        $this->warnAboutRepository($creator);

        // Said here rather than left to be discovered in a browser: a host with
        // no license or no public address lands the project on a name that
        // resolves nowhere, and the deploy itself succeeded all the same.
        $user = User::findByUsername($username);
        if ($user !== null && $domain !== '') {
            $details = $user->getDetails();
            foreach ([...PublicUrl::warnings($domain, $details), ...PublicUrl::notices($domain, $details)] as $warning) {
                $this->warn($warning);
            }
        }

        return 0;
    }

    /** The scheme the project's own domain is served on. */
    private function url(string $domain): string
    {
        $model = Domain::findByName($domain);
        $details = $model !== null && is_array($model->details) ? $model->details : [];

        return (empty($details['ssl_disabled']) ? 'https://' : 'http://') . $domain;
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\ProblemException;
use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\RecipeChoiceInput;
use App\Http\Requests\UserCloneRequest;
use App\Lib\Host\ProjectMemory;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Requests\UserVerifyNewUsernameRequest;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Jobs\DeployProject;
use App\System;
use App\Lib\Helper;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\EnvVarOverrides;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Deploy\ProjectName;
use App\Integrations\Tunnels\PanelAlphaConnect;
use App\Lib\Domains\DomainAllocationException;
use App\Lib\Domains\DomainAllocator;
use App\Lib\Project\NewProjectDetails;
use App\Lib\Project\ProvisionChecks;
use App\Lib\Project\SystemProvisionEnvironment;
use App\Rules\ProjectName as ProjectNameRule;
use App\Lib\Domains\DomainPlan;
use App\Lib\Domains\MainDomainRename;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Vault\RequestVault;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\Task;
use App\Models\Tunnel;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project as ProjectAggregate;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    #[OA\Get(
        path: '/projects',
        summary: 'List projects (paginated)',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of users', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/User')),
                    new OA\Property(property: 'meta', type: 'object'),
                    new OA\Property(property: 'links', type: 'object'),
                ],
            )),
        ],
    )]
    /**
     * @param Request $request
     * @return UserCollection
     */
    public function index(Request $request)
    {
        $params = $request->validate([
            'per_page' => 'integer',
        ]);

        /** @var LengthAwarePaginator */
        $users = User::query()
            ->with(['liveUser', 'stagingUser'])
            ->paginate($params['per_page'] ?? 15);

        return new UserCollection($users);
    }

    #[OA\Get(
        path: '/projects/all',
        summary: 'List all projects (no pagination)',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'with_domain_names', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Full list of users', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/User'))],
            )),
        ],
    )]
    public function listAll(Request $request): UserCollection
    {
        $query = User::query()->with(['liveUser', 'stagingUser']);

        if (!empty($request->get('with_domain_names'))) {
            $query->with('domains');
        }

        $users = $query->get();
        return new UserCollection($users);
    }

    #[OA\Post(
        path: '/projects',
        description: "Creates the account synchronously, then runs the deploy in a queue job and "
            . "returns 202 with a task. Poll GET /tasks/{id} until the task is terminal. Deploy "
            . "log lines are teed into task_logs — poll GET /tasks/{id}/logs?since=… or stream "
            . "GET /tasks/{id}/logs/stream. The file deploy log on "
            . "GET /projects/{username}/deploy-log stays available for timings and archive. "
            . "Unless the caller has a domain of its own, the name to give a project is a free "
            . "label under panelalpha.online: the zone is a wildcard in front of the PanelAlpha Online "
            . "proxy, so any label resolves worldwide, with a trusted certificate, and no DNS to "
            . "configure. Pass it as `domain` here, then attach it with POST "
            . "/projects/{username}/domains/{domain}/tunnels using the same hostname and provider "
            . "panelalpha. The two must match: the proxy forwards with the Host of the domain the "
            . "tunnel is attached to, so a tunnel on any other local domain makes the application "
            . "answer under a name nobody typed. Labels are first come, first served and are not "
            . "released when a tunnel is deleted -- add a short random suffix, and on 422 pick "
            . "another. Where there is no license key or no public IPv4, fall back to "
            . "<name>.<cert_domain> from GET /system/info, which resolves to this host but is served "
            . "a self-signed certificate. "
            . "X-Deploy-Stream is not supported here — use POST /users for a synchronous create "
            . "with optional NDJSON streaming.",
        summary: 'Create a new hosting project (async)',
        x: ['mcp-description' => 'Creates the account now and deploys it in the background: answers 202 with a task `id`. '
            . 'Poll task_get until it is completed, failed or cancelled. Leave `domain` out: the engine picks '
            . 'the best public name it can, a free panelalpha.online one when available, and project_get '
            . 'says which (details.domain). Resource limits are set afterwards with project_update.'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            // Nothing is required: validation has never demanded an email, and
            // a create whose every detail is generated has nothing to demand.
            properties: [
                new OA\Property(
                    property: 'username',
                    type: 'string',
                    example: 'johndoe',
                    nullable: true,
                    description: 'The project account name. Generated when omitted: from the repository '
                        . 'name, else the domain, else the recipe, else "app" -- with a random numeric '
                        . 'suffix when that name is taken. 3-15 lowercase letters and digits, starting '
                        . 'with a letter.',
                    x: ['mcp-description' => '3-15 lowercase letters and digits, starting with a letter. Generated from the repository or domain when omitted.']
                ),
                new OA\Property(
                    property: 'domain',
                    type: 'string',
                    example: 'shop-4f2a.panelalpha.online',
                    nullable: true,
                    description: 'The main domain. Omitted, it becomes <username>.<sites_base_domain>, '
                        . 'which resolves nowhere while that setting is unset. Prefer a label under '
                        . 'panelalpha.online and a matching tunnel -- see the description above.',
                    x: ['mcp-description' => 'Only for a domain of the user\'s own, with tunnel: none. Omitted, the engine picks the best public name it can.']
                ),
                new OA\Property(property: 'domain_redirect_url', type: 'string', nullable: true, x: ['mcp-hide' => true]),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                new OA\Property(property: 'disk_space_limit', type: 'integer', example: 10240, description: 'MB, -1 for unlimited', x: ['mcp-hide' => true]),
                new OA\Property(property: 'memory_limit', type: 'integer', example: 2048, description: 'MB. Omitted: memory_budget.default_project_mb, the RAM of the server less what is kept for the engine. Refused when larger than memory_budget.max_project_mb in GET /metrics/current', x: ['mcp-description' => 'MB. Default: the server\'s RAM less the engine\'s share, which is also the most allowed.']),
                new OA\Property(property: 'cpu_limit', type: 'number', format: 'float', example: 1.0, nullable: true, x: ['mcp-hide' => true]),
                new OA\Property(property: 'bandwidth_limit', type: 'integer', nullable: true, x: ['mcp-hide' => true]),
                new OA\Property(property: 'mysql_databases_limit', type: 'integer', nullable: true, x: ['mcp-hide' => true]),
                new OA\Property(property: 'ftp_accounts_limit', type: 'integer', nullable: true, x: ['mcp-hide' => true]),
                new OA\Property(property: 'sftp_accounts_limit', type: 'integer', nullable: true, x: ['mcp-hide' => true]),
                new OA\Property(property: 'addon_domains_limit', type: 'integer', nullable: true, x: ['mcp-hide' => true]),
                new OA\Property(property: 'subdomains_limit', type: 'integer', nullable: true, x: ['mcp-hide' => true]),
                new OA\Property(property: 'inodes_limit', type: 'integer', nullable: true, x: ['mcp-hide' => true]),
                new OA\Property(property: 'dedicated_ipv4', type: 'boolean', example: false, x: ['mcp-hide' => true]),
                new OA\Property(property: 'dedicated_ipv6', type: 'boolean', example: false, x: ['mcp-hide' => true]),
                new OA\Property(
                    property: 'template',
                    type: 'string',
                    nullable: true,
                    description: 'dind runs an application in containers of its own, and is what a git_repo '
                        . 'deploys into. Omitted over the REST API, the project is classic shared hosting '
                        . '(default: Apache/PHP-FPM, for WordPress and plain PHP sites).',
                    x: ['mcp-default' => 'dind', 'mcp-description' => 'dind runs the app in containers of its own; the other templates are classic shared hosting.']
                ),
                new OA\Property(
                    property: 'tunnel',
                    type: 'string',
                    enum: ['panelalpha', 'none'],
                    nullable: true,
                    description: 'How the domain reaches this host. panelalpha, the default, allocates a '
                        . 'free label under panelalpha.online, makes it the project domain and attaches '
                        . 'the tunnel in this one call -- so `domain`, if given at all, must be that same '
                        . 'name. none means the domain already resolves here, which is true of '
                        . '<name>.<cert_domain> and of a domain the caller pointed at this host. A '
                        . 'Cloudflare tunnel is not available here: it needs the project\'s API token, '
                        . 'which can only be set once the project exists -- create it, PUT '
                        . '/projects/{username}/settings/cloudflare-api-token, then POST the tunnel.',
                    x: ['mcp-description' => 'panelalpha (default): a free panelalpha.online name with a trusted certificate, tunnel attached in this call. none: `domain` already points at this host.']
                ),
                new OA\Property(
                    property: 'git_repo',
                    type: 'string',
                    nullable: true,
                    example: 'https://github.com/owner/repo.git',
                    description: 'HTTPS clone URL. SSH remotes (git@host:owner/repo.git, ssh://...) are '
                        . 'not supported: the engine clones anonymously or with `git_token` and holds no '
                        . 'SSH keys -- a 422 names the HTTPS spelling to use instead. A schemeless '
                        . 'github.com/owner/repo is accepted and has the scheme filled in.',
                    x: ['mcp-description' => 'HTTPS clone URL; SSH remotes are refused. github.com/owner/repo also works.']
                ),
                new OA\Property(property: 'git_branch', type: 'string', nullable: true),
                new OA\Property(
                    property: 'git_token',
                    type: 'string',
                    nullable: true,
                    description: 'Optional HTTPS token injected at clone time. Never logged or returned in GET /users. '
                        . 'A `vault:<id>` from vault_secret_create is accepted here in place of the literal token, '
                        . 'so the token itself never passes through the calling agent. A `project` entry becomes '
                        . 'this project\'s own and is refused to any other; a `global` one may be used by any '
                        . 'project. Omitted, the repository is cloned anonymously.',
                    x: ['mcp-description' => 'Token for a private repository. Prefer a `vault:<id>` from vault_secret_create over the token itself.']
                ),
                new OA\Property(
                    property: 'env_vars',
                    type: 'object',
                    nullable: true,
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    description: 'Optional KEY=value overrides. Stored on the project and applied to its '
                        . '.env and its container environment on every deploy, outranking what the platform '
                        . 'generates. An empty value is not an override and is not stored.',
                    x: ['mcp-description' => 'KEY=value applied to the app\'s .env and container on every deploy.']
                ),
                new OA\Property(
                    property: 'recipe',
                    type: 'string',
                    nullable: true,
                    description: 'Deploy with this recipe instead of the one detection picks. Takes an id '
                        . 'from `application.candidates` on POST /source/inspect, and inspecting with the '
                        . 'same id previews exactly what this deploys. An id this engine does not ship '
                        . 'fails the deploy rather than falling back to detection. Applies to this deploy '
                        . 'only - nothing is stored, so the next deploy without it detects again.',
                    example: 'php',
                    x: ['mcp-description' => 'Recipe id to use instead of the detected one, from source_inspect\'s application.candidates. This deploy only.']
                ),
                new OA\Property(
                    property: 'stages',
                    type: 'object',
                    nullable: true,
                    description: 'Commands this deploy runs, per stage (precheck, prepare, build, install, '
                        . 'upgrade, start). A stage named here replaces that stage entirely; a stage left '
                        . 'out keeps the platform defaults; a stage given as [] runs nothing. Each command '
                        . 'is {id, run, optional, serve, timeout, workdir, role}. Applies to this deploy '
                        . 'only - nothing is stored, so the next deploy without it is back on defaults.',
                    x: ['mcp-description' => 'Replace a stage\'s commands for this deploy only: {stage: [{id, run, ...}]} for precheck, prepare, build, install, upgrade, start; [] skips a stage.']
                ),
            ],
        )),
        tags: ['Projects'],
        responses: [
            new OA\Response(response: 202, description: 'Account created; deploy queued', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 400, description: 'X-Deploy-Stream is not supported on the async path'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function storeAsync(UserStoreRequest $request): JsonResponse
    {
        // Reject before provision so a stream-oriented client does not leave
        // an account with no matching HTTP response shape.
        if ($request->headers->has('X-Deploy-Stream')) {
            abort(400, 'X-Deploy-Stream is not supported on POST /projects; use POST /users for a synchronous create.');
        }

        // Parsed before provision: a malformed plan or recipe id must not
        // leave an account behind with no deploy queued for it.
        $plan = DeployPlanInput::parse($request->input(DeployPlanInput::FIELD));
        $stages = $plan?->toArray();
        $recipe = RecipeChoiceInput::parse($request->input(RecipeChoiceInput::FIELD));

        $user = $this->provision($request);

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

        return TaskResource::make($task)->response()->setStatusCode(202);
    }

    #[OA\Post(
        path: '/users',
        description: "Legacy synchronous create. Same body as POST /projects, but runs the deploy "
            . "inside the request and returns the project resource. Supports X-Deploy-Stream: ndjson. "
            . "Unless the caller has a domain of its own, the name to give a project is a free "
            . "label under panelalpha.online: the zone is a wildcard in front of the PanelAlpha Online "
            . "proxy, so any label resolves worldwide, with a trusted certificate, and no DNS to "
            . "configure. Pass it as `domain` here, then attach it with POST "
            . "/projects/{username}/domains/{domain}/tunnels using the same hostname and provider "
            . "panelalpha. The two must match: the proxy forwards with the Host of the domain the "
            . "tunnel is attached to, so a tunnel on any other local domain makes the application "
            . "answer under a name nobody typed. Labels are first come, first served and are not "
            . "released when a tunnel is deleted -- add a short random suffix, and on 422 pick "
            . "another. Where there is no license key or no public IPv4, fall back to "
            . "<name>.<cert_domain> from GET /system/info, which resolves to this host but is served "
            . "a self-signed certificate.",
        summary: 'Create a new hosting project (synchronous)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            // Nothing is required: validation has never demanded an email, and
            // a create whose every detail is generated has nothing to demand.
            properties: [
                new OA\Property(
                    property: 'username',
                    type: 'string',
                    example: 'johndoe',
                    nullable: true,
                    description: 'The project account name. Generated when omitted: from the repository '
                        . 'name, else the domain, else the recipe, else "app" -- with a random numeric '
                        . 'suffix when that name is taken. 3-15 lowercase letters and digits, starting '
                        . 'with a letter.',
                    x: ['mcp-description' => '3-15 lowercase letters and digits, starting with a letter. Generated from the repository or domain when omitted.']
                ),
                new OA\Property(
                    property: 'domain',
                    type: 'string',
                    example: 'shop-4f2a.panelalpha.online',
                    nullable: true,
                    description: 'The main domain. Omitted, it becomes <username>.<sites_base_domain>, '
                        . 'which resolves nowhere while that setting is unset. Prefer a label under '
                        . 'panelalpha.online and a matching tunnel -- see the description above.',
                    x: ['mcp-description' => 'Only for a domain of the user\'s own, with tunnel: none. Omitted, the engine picks the best public name it can.']
                ),
                new OA\Property(property: 'domain_redirect_url', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                new OA\Property(property: 'disk_space_limit', type: 'integer', example: 10240, description: 'MB, -1 for unlimited'),
                new OA\Property(property: 'memory_limit', type: 'integer', example: 2048, description: 'MB. Omitted: memory_budget.default_project_mb, the RAM of the server less what is kept for the engine. Refused when larger than memory_budget.max_project_mb in GET /metrics/current', x: ['mcp-description' => 'MB. Default: the server\'s RAM less the engine\'s share, which is also the most allowed.']),
                new OA\Property(property: 'cpu_limit', type: 'number', format: 'float', example: 1.0, nullable: true),
                new OA\Property(property: 'bandwidth_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'mysql_databases_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'ftp_accounts_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'sftp_accounts_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'addon_domains_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'subdomains_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'inodes_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'dedicated_ipv4', type: 'boolean', example: false),
                new OA\Property(property: 'dedicated_ipv6', type: 'boolean', example: false),
                new OA\Property(
                    property: 'template',
                    type: 'string',
                    nullable: true,
                    description: 'dind runs an application in containers of its own, and is what a git_repo '
                        . 'deploys into. Omitted over the REST API, the project is classic shared hosting '
                        . '(default: Apache/PHP-FPM, for WordPress and plain PHP sites).',
                    x: ['mcp-default' => 'dind', 'mcp-description' => 'dind runs the app in containers of its own; the other templates are classic shared hosting.']
                ),
                new OA\Property(
                    property: 'tunnel',
                    type: 'string',
                    enum: ['panelalpha', 'none'],
                    nullable: true,
                    description: 'How the domain reaches this host. panelalpha, the default, allocates a '
                        . 'free label under panelalpha.online, makes it the project domain and attaches '
                        . 'the tunnel in this one call -- so `domain`, if given at all, must be that same '
                        . 'name. none means the domain already resolves here, which is true of '
                        . '<name>.<cert_domain> and of a domain the caller pointed at this host. A '
                        . 'Cloudflare tunnel is not available here: it needs the project\'s API token, '
                        . 'which can only be set once the project exists -- create it, PUT '
                        . '/projects/{username}/settings/cloudflare-api-token, then POST the tunnel.',
                    x: ['mcp-description' => 'panelalpha (default): a free panelalpha.online name with a trusted certificate, tunnel attached in this call. none: `domain` already points at this host.']
                ),
                new OA\Property(
                    property: 'git_repo',
                    type: 'string',
                    nullable: true,
                    example: 'https://github.com/owner/repo.git',
                    description: 'HTTPS clone URL. SSH remotes (git@host:owner/repo.git, ssh://...) are '
                        . 'not supported: the engine clones anonymously or with `git_token` and holds no '
                        . 'SSH keys -- a 422 names the HTTPS spelling to use instead. A schemeless '
                        . 'github.com/owner/repo is accepted and has the scheme filled in.',
                    x: ['mcp-description' => 'HTTPS clone URL; SSH remotes are refused. github.com/owner/repo also works.']
                ),
                new OA\Property(property: 'git_branch', type: 'string', nullable: true),
                new OA\Property(
                    property: 'git_token',
                    type: 'string',
                    nullable: true,
                    description: 'Optional HTTPS token injected at clone time. Never logged or returned in GET /users. '
                        . 'A `vault:<id>` from vault_secret_create is accepted here in place of the literal token, '
                        . 'so the token itself never passes through the calling agent. A `project` entry becomes '
                        . 'this project\'s own and is refused to any other; a `global` one may be used by any '
                        . 'project. Omitted, the repository is cloned anonymously.',
                    x: ['mcp-description' => 'Token for a private repository. Prefer a `vault:<id>` from vault_secret_create over the token itself.']
                ),
                new OA\Property(
                    property: 'env_vars',
                    type: 'object',
                    nullable: true,
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    description: 'Optional KEY=value overrides. Stored on the project and applied to its '
                        . '.env and its container environment on every deploy, outranking what the platform '
                        . 'generates. An empty value is not an override and is not stored.',
                    x: ['mcp-description' => 'KEY=value applied to the app\'s .env and container on every deploy.']
                ),
                new OA\Property(
                    property: 'recipe',
                    type: 'string',
                    nullable: true,
                    description: 'Deploy with this recipe instead of the one detection picks. Takes an id '
                        . 'from `application.candidates` on POST /source/inspect, and inspecting with the '
                        . 'same id previews exactly what this deploys. An id this engine does not ship '
                        . 'fails the deploy rather than falling back to detection. Applies to this deploy '
                        . 'only - nothing is stored, so the next deploy without it detects again.',
                    example: 'php',
                    x: ['mcp-description' => 'Recipe id to use instead of the detected one, from source_inspect\'s application.candidates. This deploy only.']
                ),
                new OA\Property(
                    property: 'stages',
                    type: 'object',
                    nullable: true,
                    description: 'Commands this deploy runs, per stage (precheck, prepare, build, install, '
                        . 'upgrade, start). A stage named here replaces that stage entirely; a stage left '
                        . 'out keeps the platform defaults; a stage given as [] runs nothing. Each command '
                        . 'is {id, run, optional, serve, timeout, workdir, role}. Applies to this deploy '
                        . 'only - nothing is stored, so the next deploy without it is back on defaults.',
                    x: ['mcp-description' => 'Replace a stage\'s commands for this deploy only: {stage: [{id, run, ...}]} for precheck, prepare, build, install, upgrade, start; [] skips a stage.']
                ),
            ],
        )),
        tags: ['Projects'],
        responses: [
            new OA\Response(response: 201, description: 'User created', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(UserStoreRequest $request): UserResource|StreamedResponse
    {
        // Validate the streaming opt-in up front so an unknown value never
        // leaves a half-created account behind.
        $streamDeploy = $this->wantsDeployStream($request);

        // What this deploy was told to run, and which recipe to run it from,
        // if anything. Armed before provision, so a malformed one is a 422
        // with no account behind it, and gone with the response: nothing
        // about either is stored, so the next deploy is back on detection.
        DeployPlanInput::arm($request);
        RecipeChoiceInput::arm($request);

        $user = $this->provision($request);

        $deployLogger = null;
        if ($user->hasGitProject() || $user->getTemplate() === 'dind') {
            $deployLogger = DeployLogger::startSafely($user->username);
            if ($deployLogger !== null) {
                $gitRepo = $user->getGitRepo();
                $deployLogger->info($gitRepo
                    ? "Deploy started (source: git, repo: " . GitUrl::sanitize($gitRepo) . ")"
                    : 'Deploy started (source: dind template)');
            }
        }

        if ($deployLogger !== null && $streamDeploy) {
            return $this->respondWithDeployStream(
                fn () => $this->runDeployPipeline($user, $deployLogger),
                $deployLogger,
                static fn () => ['username' => $user->username, 'domain' => $user->domain]
            );
        }

        $this->runDeployPipeline($user, $deployLogger);

        return new UserResource($user);
    }

    /**
     * Validate, allocate a domain, and persist the user + main domain.
     * Shared by the sync and async create paths; does not start a deploy.
     */
    private function provision(UserStoreRequest $request): User
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
        $params = $request->validated();

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
                $request->nameField(),
                'username_required',
                'No project name was given and none could be generated; pass `name` ('
                    . ProjectNameRule::EXPECTED . ').'
            );
        }

        // Everything knowable from the request alone, asked at once and
        // answered at once. {@see ProvisionChecks}
        $nameField = $request->nameField();
        $problems = (new ProvisionChecks(new SystemProvisionEnvironment()))->problems($params, $nameField);

        if ($problems !== []) {
            throw ProblemException::of($problems);
        }

        // Vault references are read once the name is known to be free, so a
        // project entry is assigned to this project, and before the allocator
        // spends a label on a create that would fail here. The project stores
        // the secrets themselves; nothing reads the vault on its behalf later.
        $params['git_token'] = RequestVault::get('git_token', $params['username']);
        if (isset($params['env_vars']) && is_array($params['env_vars'])) {
            $params['env_vars'] = RequestVault::get('env_vars', $params['username']);
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
                $this->mergedEnvVars($params['env_vars'] ?? [], []),
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

        if ($dedicatedIpv4) {
            $user->assignFreeDedicatedIpv4();
        }

        if ($dedicatedIpv6) {
            $user->assignFreeDedicatedIpv6();
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
     * A failed deploy, said in a way a program can act on.
     *
     * These used to be `withMessages([$message])`, which keys on 0 -- so the
     * response carried `errors: {"0": ["..."]}`: no field to attach it to, no
     * code to branch on, and no clue where in the deploy it happened. A
     * client had to read English to tell "pin a PHP image" from "the
     * repository needs a token".
     */
    /**
     * An archive cannot replace a project that deploys from git (engine#269).
     *
     * The deploy treated the uploaded tree as the repository's checkout -- its
     * recipe, its app config, its HEAD -- found no .git, and failed only after
     * the archive had already replaced ~/project. A deploy-managed repository
     * cannot be disconnected either, so say what can be done instead, before
     * anything is touched.
     */
    private static function refuseArchiveOnGitProject(User $user): void
    {
        if (!$user->hasGitProject()) {
            return;
        }

        throw ProblemException::one(
            'zip_path',
            'archive_on_git_project',
            "Project '{$user->username}' deploys from its git repository, so an archive cannot replace it. "
            . 'Push the change to the repository and rebuild, or deploy the archive into a project created without a repository.',
            ['git_repo' => GitUrl::sanitize((string) $user->getGitRepo())]
        );
    }

    private static function deployProblem(string $code, string $message, ?string $stage): ProblemException
    {
        return ProblemException::one('deploy', $code, $message, array_filter([
            'stage' => $stage,
            // Where to start reading the log for the rest of the story. The
            // account is gone by now, but its deploy log is kept.
            'deploy_log_offset' => 0,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * The synchronous deploy pipeline shared by the classic JSON response,
     * the streamed NDJSON variant, and the async DeployProject job. Throws
     * ValidationException after finishing the deploy log (cancelled/failed)
     * and cleaning up on errors.
     */
    public function runDeployPipeline(User $user, ?DeployLogger $deployLogger, ?callable $beforeRollback = null): void
    {
        $user->project()->runDeployment($deployLogger, $beforeRollback);
    }

    /**
     * Opt-in NDJSON streaming of the deploy log, negotiated via the
     * X-Deploy-Stream request header. Unknown values are rejected.
     */
    private function wantsDeployStream(Request $request): bool
    {
        $value = $request->headers->get('X-Deploy-Stream');
        if ($value === null || $value === '') {
            return false;
        }
        if ($value === 'ndjson') {
            return true;
        }
        abort(400, "Unsupported X-Deploy-Stream value: {$value}");
    }

    /**
     * The overrides to store for a deploy that sent `env_vars`.
     *
     * Merged onto what the project already carries, so a caller correcting one
     * variable does not have to resend the rest — and does not silently drop a
     * secret an earlier deploy set. An empty value removes the key; an
     * explicit `null` clears every override. {@see EnvVarOverrides}
     *
     * @param array<string, string> $stored
     * @return array<string, string>
     */
    private function mergedEnvVars(mixed $incoming, array $stored): array
    {
        if ($incoming === null) {
            return [];
        }

        return EnvVarOverrides::merge($stored, $this->normalizeEnvVars($incoming));
    }

    /**
     * @param mixed $envVars
     * @return array<string, string>
     */
    private function normalizeEnvVars(mixed $envVars): array
    {
        if (!is_array($envVars)) {
            return [];
        }
        $result = [];
        foreach ($envVars as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            // ConvertEmptyStringsToNull turns the documented `""` (remove this key) into null.
            if ($value === null) {
                $result[$key] = '';

                continue;
            }
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            $result[$key] = (string) $value;
        }

        return $result;
    }

    /**
     * Run the deploy pipeline inside a chunked NDJSON response. Every log
     * line and stage change is flushed immediately (tee in DeployLogger);
     * the final 'finish' frame carries the terminal status and, on success,
     * the user data the classic UserResource response would have returned.
     *
     * Client disconnects never abort the deploy itself (ignore_user_abort) —
     * the log files stay the source of truth and clients resume via the
     * deploy-log polling endpoint. The caller's HTTP response must not have
     * started yet (validation errors still go out as regular JSON).
     */
    private function respondWithDeployStream(\Closure $pipeline, ?DeployLogger $deployLogger, ?callable $userFrame = null): StreamedResponse
    {
        ignore_user_abort(true);
        set_time_limit(0);

        return response()->stream(function () use ($pipeline, $deployLogger, $userFrame) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $emit = static function (array $frame): void {
                echo json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                flush();
            };

            // Lines written before the stream opened (e.g. the initial
            // "Deploy started" entry) ride along in the start frame so the
            // consumer's offsets still line up with the log file.
            $backlog = $deployLogger !== null ? $deployLogger->read(0) : ['lines' => []];
            $latest = $deployLogger?->readLatest();

            DeployLogger::streamTo($emit);
            try {
                $emit([
                    'type' => 'start',
                    'id' => $latest['id'] ?? null,
                    'started_at' => $latest['started_at'] ?? null,
                    'lines' => $backlog['lines'],
                ]);
                $pipeline();
            } catch (\Throwable $e) {
                // Expected: ValidationException after the pipeline finished the
                // log (failed/cancelled). Unexpected: close the log here.
                $latest = $deployLogger?->readLatest();
                if (($latest['status'] ?? null) === DeployLogger::STATUS_RUNNING) {
                    $deployLogger?->recordFailureOutput($e->getMessage());
                    $deployLogger?->finish(
                        DeployLogger::STATUS_FAILED,
                        DeployFailureExplainer::explain($e->getMessage()) ?? $e->getMessage()
                    );
                }
                if (!($e instanceof ValidationException)) {
                    Log::error("Streamed deploy failed: {$e->getMessage()}");
                }
            } finally {
                DeployLogger::stopStreaming();
            }

            $latest = $deployLogger?->readLatest();
            $status = $latest['status'] ?? null;
            // Zip accounts leave the logger running after preparing; do not
            // emit finish or the wizard treats the placeholder as deployed.
            if ($status === DeployLogger::STATUS_RUNNING) {
                $emit([
                    'type' => 'continue',
                    'status' => $status,
                    'stage' => $latest['stage'] ?? null,
                    'stages' => $latest['stages'] ?? [],
                ]);

                return;
            }
            $frame = [
                'type' => 'finish',
                'status' => $status,
                'stage' => $latest['stage'] ?? null,
                'stages' => $latest['stages'] ?? [],
                'error' => $latest['error'] ?? null,
                'finished_at' => $latest['finished_at'] ?? null,
                'problem' => $latest['problem'] ?? null,
            ];
            if (
                $userFrame !== null
                && in_array($frame['status'], [DeployLogger::STATUS_SUCCESS, DeployLogger::STATUS_PARTIAL], true)
            ) {
                $frame['user'] = $userFrame();
            }
            $emit($frame);
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
            'X-Deploy-Stream-Accepted' => 'ndjson',
        ]);
    }

    #[OA\Post(
        path: '/projects/{username}/rebuild',
        summary: 'Redeploy a project',
        description: 'Detects, builds and starts the application again from ~/project, after importing '
            . '`zip_path` into it when given (refused on a project deployed from git). The request stays '
            . 'open until the deploy ends; a client that times out has not stopped it, so follow '
            . 'GET /projects/{username}/deploy-log rather than calling again.',
        x: ['mcp-description' => 'Detects, builds and starts the app again from ~/project, importing '
            . '`zip_path` first when given (refused on a git project). Answers when the deploy ends; if the '
            . 'call times out the deploy carries on, so follow deploy_log_get instead of calling again.'],
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'env_vars',
                    type: 'object',
                    nullable: true,
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    description: 'KEY=value overrides, merged onto the ones the project already carries — '
                        . 'send only what changes. An empty value removes that key; null clears them all.',
                    x: ['mcp-description' => 'KEY=value changes merged onto the project\'s; an empty value removes a key, null clears them all.']
                ),
                new OA\Property(
                    property: 'zip_path',
                    type: 'string',
                    nullable: true,
                    description: 'Optional archive under the project home to import into ~/project before detect/apply'
                ),
                new OA\Property(
                    property: 'recipe',
                    type: 'string',
                    nullable: true,
                    description: 'Deploy with this recipe instead of the one detection picks. Takes an id '
                        . 'from `application.candidates` on POST /source/inspect, and inspecting with the '
                        . 'same id previews exactly what this deploys. An id this engine does not ship '
                        . 'fails the deploy rather than falling back to detection. Applies to this deploy '
                        . 'only - nothing is stored, so the next deploy without it detects again.',
                    example: 'php',
                    x: ['mcp-description' => 'Recipe id to use instead of the detected one, from source_inspect\'s application.candidates. This deploy only.']
                ),
                new OA\Property(
                    property: 'stages',
                    type: 'object',
                    nullable: true,
                    description: 'Commands this deploy runs, per stage. A stage named here replaces that '
                        . 'stage entirely; a stage left out keeps the platform defaults; a stage given as '
                        . '[] runs nothing. Applies to this deploy only - nothing is stored.',
                    x: ['mcp-description' => 'Replace a stage\'s commands for this deploy only: {stage: [{id, run, ...}]} for precheck, prepare, build, install, upgrade, start; [] skips a stage.']
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'User rebuilt', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Rebuild failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @return UserResource
     */
    public function rebuild(string $username, Request $request)
    {
        $user = $this->projectOr404($username);

        /** @var array{env_vars?: array<string, string>, zip_path?: string} $params */
        $params = $request->validate([
            'env_vars' => 'array|nullable|max:200',
            'env_vars.*' => 'string|nullable|max:8192',
            'zip_path' => 'string|nullable|max:4096',
            'stages' => 'array|nullable',
            'recipe' => 'string|nullable|max:64',
        ]);
        if (($params['zip_path'] ?? '') !== '') {
            self::refuseArchiveOnGitProject($user);
        }
        DeployPlanInput::arm($request);
        RecipeChoiceInput::arm($request);
        if (array_key_exists('env_vars', $params)) {
            $user->setDetails([
                'env_vars' => $this->mergedEnvVars(
                    is_array($params['env_vars'] ?? null) ? RequestVault::get('env_vars', $user->username) : null,
                    $user->getEnvVars()
                ),
            ]);
            $user->save();
        }
        $zipPath = $params['zip_path'] ?? null;

        $deployLogger = null;
        $stream = false;
        if ($user->getTemplate() === 'dind') {
            // Created here rather than inside the workflow, which would open
            // the same one: a failure then knows its stage (the plain response
            // used to be the only deploy answer without one), and the stream
            // can attach its backlog/start frames before the pipeline runs.
            $deployLogger = DeployLogger::resumeRunningOrStartSafely($user->username);
            $stream = $this->wantsDeployStream($request);
        }

        // One closure for both shapes, so the streamed and the plain response
        // cannot drift -- which is how this endpoint came to be the only
        // deploy entry point with no handler at all. A rebuild whose compose
        // dependency failed answered `500 {"message":"Server Error"}`: no
        // problem code, no stage, no offset, and nothing to tell a client
        // "your compose file is wrong" from "the engine is broken". The deploy
        // log for the same run already had the real error in it.
        $rebuild = function () use ($user, $deployLogger, $zipPath): void {
            try {
                $this->runProjectRebuild($user->project(), $deployLogger, $zipPath);
                $user->project()->system()->webserver()->rebuildDomains();
                $this->recordRebuildSucceeded($user);
            } catch (DeployCancelledException $e) {
                $stage = $deployLogger?->currentStage();
                self::finishRebuildLog($deployLogger, DeployLogger::STATUS_CANCELLED, $e->getMessage());
                throw self::deployProblem('deploy_cancelled', $e->getMessage(), $stage);
            } catch (ValidationException $e) {
                // ProblemException is one of these, so anything already in the
                // documented shape passes through rather than being re-wrapped.
                throw $e;
            } catch (\Exception $e) {
                throw $this->rebuildFailure($e, $deployLogger);
            }
        };

        if ($stream && $deployLogger !== null) {
            return $this->respondWithDeployStream(
                $rebuild,
                $deployLogger,
                static fn () => ['username' => $user->username, 'domain' => $user->domain]
            );
        }

        $rebuild();

        return new UserResource($user);
    }

    /**
     * A failed rebuild, in the shape every other deploy endpoint answers in.
     *
     * Its own method so it can be exercised without a request: the defect was
     * that this translation did not exist here at all, and a test that has to
     * stand up a controller to see it would not have caught that either.
     */
    private function rebuildFailure(\Exception $e, ?DeployLogger $deployLogger): ProblemException
    {
        $deployLogger?->recordFailureOutput($e->getMessage());
        // The same slug deploy telemetry reports, so a client and a dashboard
        // name one failure the same way.
        $match = DeployFailureExplainer::match($e->getMessage());
        $message = $match['message'] ?? FailureOutput::withoutNoise($e->getMessage());
        $stage = $deployLogger?->currentStage();
        self::finishRebuildLog($deployLogger, DeployLogger::STATUS_FAILED, $message);

        return self::deployProblem($match['rule'] ?? 'rebuild_failed', $message, $stage);
    }

    /**
     * The workflow finishes the log itself when a rebuild fails or is cancelled.
     * Finishing it again wrote a second "Deploy failed" line and filed a second
     * telemetry report for the same rebuild. A cancel request alone sets the
     * status without finishing, so `finished_at` is what says it was done.
     */
    private static function finishRebuildLog(?DeployLogger $deployLogger, string $status, string $error): void
    {
        if ($deployLogger === null) {
            return;
        }
        $latest = $deployLogger->readLatest() ?? [];
        $settled = in_array($latest['status'] ?? null, [DeployLogger::STATUS_FAILED, DeployLogger::STATUS_CANCELLED], true);
        if ($settled && ($latest['finished_at'] ?? null) !== null) {
            return;
        }
        $deployLogger->finish($status, $error);
    }

    /**
     * DinD wipe-rebuild goes through DeploymentWorkflow; PhpHosting only recreates outer hosting.
     */
    private function runProjectRebuild(ProjectAggregate $project, ?DeployLogger $deployLogger, ?string $zipPath): void
    {
        $project->rebuildFromSource($deployLogger, $zipPath);
    }

    /**
     * A rebuild that worked is a deploy that worked, and the next one needs to
     * know that.
     *
     * The deployment status is what the entrypoint's install/upgrade phase is
     * derived from ({@see PlatformStage::phaseFor()}), and rebuild used to
     * leave it untouched. An account that had only ever been rebuilt was
     * therefore stuck reporting a first install for the rest of its life:
     * every rebuild re-ran `php artisan key:generate --force`, throwing away
     * the APP_KEY that every session cookie and encrypted column depends on,
     * and re-ran the seeders behind it. The whole point of splitting install
     * from upgrade is that install runs once.
     */
    private function recordRebuildSucceeded(User $user): void
    {
        // A rebuild does not run the deploy pipeline, so the verdict the
        // health checks reached has to be applied here as well -- and it is
        // applied first, because "the site is serving our placeholder" is
        // true whether this account had ever deployed successfully before or
        // not. Without this the whole mechanism was invisible on the one path
        // most redeploys take.
        //
        // The account record only. The deploy log -- and telemetry with it --
        // is finished by the pipeline itself, which reaches the same verdict
        // through the same helper in the deploy pipeline ({@see \App\System\Project\Deployment\DeploymentWorkflow::rebuildFromSource()}). Doing
        // it here as well would file a second terminal status for one rebuild
        // and report it twice.
        $warnings = AppHealth::servingWarnings($user->getDetails());
        if ($warnings !== []) {
            $user->setDetails([
                'deployment_status' => 'partial',
                'deployment_warnings' => $warnings,
                'error' => null,
            ]);
            $user->save();

            return;
        }

        // A clean rebuild clears a previous run's warnings: leaving them would
        // report a fault that has since been fixed.
        if ($user->getDeploymentStatus() === 'success' && ($user->getDetails()['deployment_warnings'] ?? []) === []) {
            return;
        }
        $user->markDeploySucceeded();
        $user->save();
    }

    #[OA\Post(
        path: '/projects/{username}/deploy-archive',
        summary: 'Deploy an uploaded zip/tar into ~/project',
        description: 'Engine-only path: unwrap a single top-level directory, detect project type, apply strategy, docker compose up. Upload the archive first: POST /projects/{username}/files/upload (file_upload over MCP, with file_contents or file_url), or an FTP/SFTP account; zip_path is relative to the account home, e.g. /project/app.zip.',
        x: ['mcp-description' => 'Deploys an archive already in the account (put there with file_upload or FTP), '
            . 'e.g. zip_path /project/app.zip. A single top-level directory is unwrapped.'],
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['zip_path'],
            properties: [
                new OA\Property(property: 'zip_path', type: 'string', example: '/app.zip'),
                new OA\Property(
                    property: 'env_vars',
                    type: 'object',
                    nullable: true,
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    description: 'KEY=value overrides, merged onto the ones the project already carries — '
                        . 'send only what changes. An empty value removes that key; null clears them all.',
                    x: ['mcp-description' => 'KEY=value changes merged onto the project\'s; an empty value removes a key, null clears them all.']
                ),
                new OA\Property(
                    property: 'recipe',
                    type: 'string',
                    nullable: true,
                    description: 'Deploy with this recipe instead of the one detection picks. Takes an id '
                        . 'from `application.candidates` on POST /source/inspect, and inspecting with the '
                        . 'same id previews exactly what this deploys. An id this engine does not ship '
                        . 'fails the deploy rather than falling back to detection. Applies to this deploy '
                        . 'only - nothing is stored, so the next deploy without it detects again.',
                    example: 'php',
                    x: ['mcp-description' => 'Recipe id to use instead of the detected one, from source_inspect\'s application.candidates. This deploy only.']
                ),
                new OA\Property(
                    property: 'stages',
                    type: 'object',
                    nullable: true,
                    description: 'Commands this deploy runs, per stage. A stage named here replaces that '
                        . 'stage entirely; a stage left out keeps the platform defaults; a stage given as '
                        . '[] runs nothing. Applies to this deploy only - nothing is stored.',
                    x: ['mcp-description' => 'Replace a stage\'s commands for this deploy only: {stage: [{id, run, ...}]} for precheck, prepare, build, install, upgrade, start; [] skips a stage.']
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Archive deployed', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function deployArchive(string $username, Request $request): UserResource|StreamedResponse
    {
        $user = $this->projectOr404($username);
        if ($user->getTemplate() !== 'dind') {
            throw ValidationException::withMessages([
                'zip_path' => 'Archive deploy is only supported for dind users.',
            ]);
        }

        /** @var array{zip_path: string, env_vars?: array<string, string>} $params */
        $params = $request->validate([
            'zip_path' => 'string|required|max:4096',
            'env_vars' => 'array|nullable|max:200',
            'env_vars.*' => 'string|nullable|max:8192',
            'stages' => 'array|nullable',
            'recipe' => 'string|nullable|max:64',
        ]);
        self::refuseArchiveOnGitProject($user);
        DeployPlanInput::arm($request);
        RecipeChoiceInput::arm($request);
        if (array_key_exists('env_vars', $params)) {
            $user->setDetails([
                'env_vars' => $this->mergedEnvVars($params['env_vars'] ?? null, $user->getEnvVars()),
            ]);
            $user->save();
        }
        $zipPath = $params['zip_path'];

        $deployLogger = DeployLogger::resumeRunningOrStartSafely($user->username);
        $deployLogger?->info('Deploy started (source: archive)');

        $run = function () use ($user, $zipPath, $deployLogger): void {
            try {
                $user->project()->deployment()->deployFromArchive($deployLogger, $zipPath);
                // The vhosts were rendered when the account was created, against
                // the welcome app's port. Detection has just re-pointed app_port
                // at what the archive really serves on (8000 for PHP, 3000 for
                // Express, ...), so the proxy must be re-rendered the same way
                // rebuild() does it — or every non-8080 app answers 502 behind
                // a green deploy.
                $user->project()->system()->webserver()->rebuildDomains();
                $this->recordRebuildSucceeded($user);
            } catch (DeployCancelledException $e) {
                $stage = $deployLogger?->currentStage();
                $deployLogger?->finish(DeployLogger::STATUS_CANCELLED, $e->getMessage());
                throw self::deployProblem('deploy_cancelled', $e->getMessage(), $stage);
            } catch (\InvalidArgumentException $e) {
                $deployLogger?->finish(DeployLogger::STATUS_FAILED, $e->getMessage());
                throw ValidationException::withMessages([
                    'zip_path' => $e->getMessage(),
                ]);
            } catch (ProblemException $e) {
                // A start failure already carries its rule; finish() is a no-op once finished.
                $deployLogger?->finish(DeployLogger::STATUS_FAILED, $e->getMessage());
                throw $e;
            } catch (\Exception $e) {
                $deployLogger?->recordFailureOutput($e->getMessage());
                $match = DeployFailureExplainer::match($e->getMessage());
                $message = $match['message'] ?? FailureOutput::withoutNoise($e->getMessage());
                $stage = $deployLogger?->currentStage();
                $deployLogger?->finish(DeployLogger::STATUS_FAILED, $message);
                throw self::deployProblem($match['rule'] ?? 'deploy_failed', $message, $stage);
            }
        };

        if ($deployLogger !== null && $this->wantsDeployStream($request)) {
            return $this->respondWithDeployStream(
                $run,
                $deployLogger,
                static fn () => ['username' => $user->username, 'domain' => $user->domain]
            );
        }

        $run();

        return new UserResource($user);
    }

    #[OA\Post(
        path: '/projects/{username}/clone',
        summary: 'Clone a project',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'new_username', type: 'string', nullable: true),
                new OA\Property(property: 'domain', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Cloned user', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Clone a project.
     *
     * Creates a new project with the same resource limits and settings as the
     * source, derives a staging domain when none is provided, and copies the
     * source's home directory (including named Docker volumes) and project config
     * files to the new project after provisioning completes.
     *
     * Subdomains, FTP/SFTP accounts, MySQL databases/users and dedicated IP
     * addresses are NOT cloned.
     *
     * @param string $username  Source project's name.
     */
    public function clone(string $username, UserCloneRequest $request): UserResource
    {
        $srcUser = $this->projectOr404($username);

        /** @var array{new_username?: ?string, domain?: ?string} */
        $params = $request->validated();

        $system = new System();

        // ── Resolve new username ─────────────────────────────────────────────
        $newUsername = !empty($params['new_username']) ? $params['new_username'] : null;
        if ($newUsername === null) {
            $newUsername = Helper::generateCloneUsername($srcUser->username);
            if ($newUsername === null) {
                throw ValidationException::withMessages([
                    'new_username' => 'Could not generate an available username for the clone.',
                ]);
            }
        }

        if (User::existsByUsername($newUsername)) {
            throw ValidationException::withMessages([
                'new_username' => 'User already exists.',
            ]);
        }
        if (!$system->isUsernameAvailable($newUsername)) {
            throw ValidationException::withMessages([
                'new_username' => 'Username not available.',
            ]);
        }

        // ── Resolve clone domain ─────────────────────────────────────────────
        $cloneDomain = !empty($params['domain']) ? $params['domain'] : null;
        if ($cloneDomain === null) {
            $srcDomain = $srcUser->domain;
            $cloneDomain = Helper::generateCloneDomain($srcDomain);
            if ($cloneDomain === null) {
                throw ValidationException::withMessages([
                    'domain' => 'Could not generate an available staging domain for the clone.',
                ]);
            }
        } elseif (Str::startsWith($cloneDomain, 'www.')) {
            $cloneDomain = Str::after($cloneDomain, 'www.');
        }

        if (Domain::domainOrAliasExists($cloneDomain)) {
            throw ValidationException::withMessages([
                'domain' => 'Domain already exists.',
            ]);
        }

        // A clone keeps the source's memory limit, which must still fit this host.
        ProjectMemory::assertFits(ProjectMemory::resolve($srcUser->getMemoryLimit()));

        // ── Copy resource limits, settings and frozen deploy snapshot ────────
        $newDetails = $srcUser->detailsForCopiedProject($newUsername);

        // ── Build domain details ─────────────────────────────────────────────
        $wwwAliasAvailable = DomainPlan::wwwAliasWouldAnswer($cloneDomain)
            && !Domain::domainOrAliasExists('www.' . $cloneDomain);
        $domainAliases = $wwwAliasAvailable ? ['www.' . $cloneDomain] : [];

        // ── Create model instances (not yet persisted) ───────────────────────
        /** @var User */
        $cloneUser = User::make([
            'username' => $newUsername,
            'domain'   => $cloneDomain,
            'email'    => $srcUser->email,
            'details'  => $newDetails,
        ]);

        if ($cloneUser->project()->hostingExists()) {
            throw ValidationException::withMessages([
                'new_username' => 'Username not available.',
            ]);
        }

        /** @var Domain */
        $cloneDomainModel = Domain::make([
            'user_id' => null, // filled after user save
            'domain'  => $cloneDomain,
            'type'    => 'main',
            'details' => [
                'document_root'     => "/{$cloneDomain}/public_html",
                'redirect_enabled'  => false,
                'redirect_url'      => null,
                'aliases'           => $domainAliases,
            ],
        ]);

        // ── Persist + provision ──────────────────────────────────────────────
        $cloneUser->save();
        $cloneDomainModel->user_id = $cloneUser->id;
        $cloneDomainModel->save();

        try {
            $system->projects()->clone($srcUser->username, $cloneUser->username);
            $cloneUser = $cloneUser->refresh();
        } catch (\RuntimeException $e) {
            try {
                $cloneUser->refresh()->project()->destroy();
            } catch (\Throwable $ignored) {
            }
            $match = DeployFailureExplainer::match($e->getMessage());
            throw self::deployProblem(
                $match['rule'] ?? 'clone_failed',
                $match['message'] ?? $e->getMessage(),
                null
            );
        } catch (\Exception $e) {
            try {
                $cloneUser->refresh()->project()->destroy();
            } catch (\Throwable $ignored) {
            }
            $match = DeployFailureExplainer::match($e->getMessage());
            throw self::deployProblem(
                $match['rule'] ?? 'clone_failed',
                $match['message'] ?? $e->getMessage(),
                null
            );
        }

        return new UserResource($cloneUser);
    }

    #[OA\Post(
        path: '/projects/verify-new-username',
        summary: 'Check that a project name is available',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['username'],
            properties: [new OA\Property(property: 'username', type: 'string', example: 'johndoe')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Username is valid', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'valid', type: 'boolean', example: true)],
            )),
            new OA\Response(response: 422, description: 'Username taken or invalid', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param UserVerifyNewUsernameRequest $request
     * @return JsonResponse
     */
    public function verifyNewUsername(UserVerifyNewUsernameRequest $request)
    {
        /** @var array{
         *   username: string,
         * }
         */
        $params = $request->validated();

        if (User::existsByUsername($params['username'])) {
            throw ValidationException::withMessages([
                'username' => 'User already exists.'
            ]);
        }

        $system = new System();

        if (!$system->isUsernameAvailable($params['username'])) {
            throw ValidationException::withMessages([
                'username' => 'Username not available.'
            ]);
        }

        return new JsonResponse(['valid' => true]);
    }

    #[OA\Get(
        path: '/projects/{username}',
        summary: 'Get a project',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'User details', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @return UserResource
     */
    public function show($username)
    {
        $user = $this->projectOr404($username);
        $user->loadMissing(['liveUser', 'stagingUser']);

        return new UserResource($user);
    }

    #[OA\Put(
        path: '/projects/{username}',
        summary: 'Update a project',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'domain', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'disk_space_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'memory_limit', type: 'integer', description: 'MB. Can be changed, not removed. Refused when larger than memory_budget.max_project_mb in GET /metrics/current'),
                new OA\Property(property: 'cpu_limit', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'bandwidth_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'mysql_databases_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'ftp_accounts_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'sftp_accounts_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'addon_domains_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'subdomains_limit', type: 'integer', nullable: true),
                new OA\Property(property: 'inodes_limit', type: 'integer', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated user', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @param UserUpdateRequest $request
     * @return UserResource
     */
    public function update($username, UserUpdateRequest $request)
    {
        $user = $this->projectOr404($username);

        /** @var array{
         *   domain?: string,
         *   email?: string,
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
         *   redis_config?: string
         * }
         */
        $params = $request->validated();

        if (array_key_exists('email', $params)) {
            $user->email = $params['email'];
        }
        foreach ([...ResourceLimit::keys(), 'php_fpm_pool_settings', 'lsphp_settings', 'redis_config'] as $key) {
            if (array_key_exists($key, $params)) {
                $user->setDetails([$key => $params[$key]]);
            }
        }

        if (array_key_exists('domain', $params)) {
            MainDomainRename::apply($user, (string) $params['domain']);
        }

        $user->save();

        // Applied now, not at the next rebuild: the account keeps running.
        $runtime = $user->project()->runtime();
        if (array_key_exists('memory_limit', $params) && $runtime instanceof Dind) {
            $runtime->applyMemoryLimit();
        }

        return new UserResource($user);
    }

    #[OA\Put(
        path: '/projects/{username}/suspend',
        summary: 'Suspend a project',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'User suspended', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function suspend(string $username): UserResource
    {
        $user = $this->projectOr404($username);

        $user->status = "suspended";
        $user->save();

        $system = new System();
        $reloaded = $system->rebuildDomains();

        return (new UserResource($user))->additional(['reload_pending' => !$reloaded]);
    }

    #[OA\Put(
        path: '/projects/{username}/unsuspend',
        summary: 'Unsuspend a project',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'User unsuspended', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function unsuspend(string $username): UserResource
    {
        $user = $this->projectOr404($username);

        $user->status = "active";
        $user->save();

        $system = new System();
        $reloaded = $system->rebuildDomains();

        return (new UserResource($user))->additional(['reload_pending' => !$reloaded]);
    }

    #[OA\Delete(
        path: '/projects/{username}',
        summary: 'Delete a project and everything in it',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'User deleted', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param string $username
     * @return UserResource
     */
    public function destroy($username)
    {
        $user = $this->projectOr404($username);

        try {
            $user->project()->destroy();
        } catch (\Exception $e) {
            Log::warning(
                "Could not delete user '{$user->username}': " . $e->getMessage(),
                ['exception' => $e],
            );
            throw $e;
        }

        return new UserResource($user);
    }

}

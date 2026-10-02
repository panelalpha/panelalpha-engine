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
use App\System;
use App\Lib\Helper;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\EnvVarOverrides;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Lib\Domains\DomainPlan;
use App\Lib\Domains\MainDomainRename;
use App\Lib\Domains\PublicUrl;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Vault\RequestVault;
use App\Models\Domain;
use App\Models\Setting;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\System\Project as ProjectAggregate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
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
    public function storeAsync(UserStoreRequest $request, ProjectCreator $creator): JsonResponse
    {
        // Reject before provision so a stream-oriented client does not leave
        // an account with no matching HTTP response shape.
        if ($request->headers->has('X-Deploy-Stream')) {
            abort(400, 'X-Deploy-Stream is not supported on POST /projects; use POST /users for a synchronous create.');
        }

        $task = $creator->queue(self::newProjectInput($request));

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
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(UserStoreRequest $request, ProjectCreator $creator): UserResource|StreamedResponse
    {
        // Validate the streaming opt-in up front so an unknown value never
        // leaves a half-created account behind.
        $streamDeploy = $this->wantsDeployStream($request);

        $user = $creator->provisionForDeploy(self::newProjectInput($request));
        $deployLogger = $creator->startDeployLog($user);

        if ($deployLogger !== null && $streamDeploy) {
            return $this->respondWithDeployStream(
                fn () => $creator->deploy($user, $deployLogger),
                $deployLogger,
                static fn () => ['username' => $user->username, 'domain' => $user->domain]
            );
        }

        $creator->deploy($user, $deployLogger);

        return new UserResource($user);
    }

    /** The create as ProjectCreator takes it: validated fields, plus the raw ones it reads itself. */
    private static function newProjectInput(UserStoreRequest $request): NewProjectInput
    {
        return new NewProjectInput(
            $request->validated(),
            $request->nameField(),
            $request->input('git_token'),
            $request->input('env_vars'),
            $request->input(DeployPlanInput::FIELD),
            $request->input(RecipeChoiceInput::FIELD),
        );
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
            new OA\Response(response: 200, description: 'Project rebuilt', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
                'env_vars' => EnvVarOverrides::applyIncoming(
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
        //
        // Both lists the pipeline finishes the log with: with the health one
        // alone, a rebuild logged `partial` for its public URL and recorded `success`.
        $warnings = array_merge(
            AppHealth::servingWarnings($user->getDetails()),
            PublicUrl::warnings((string) $user->domain, $user->getDetails()),
        );
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
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
                'env_vars' => EnvVarOverrides::applyIncoming($params['env_vars'] ?? null, $user->getEnvVars()),
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
            new OA\Response(response: 200, description: 'Cloned project', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
            new OA\Response(response: 200, description: 'Project details', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
            new OA\Response(response: 200, description: 'Updated project', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
            new OA\Response(response: 200, description: 'Project suspended', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
            new OA\Response(response: 200, description: 'Project unsuspended', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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
            new OA\Response(response: 200, description: 'Project deleted', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
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

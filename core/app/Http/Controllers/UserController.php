<?php

namespace App\Http\Controllers;

use App\Auth\SystemProjects;
use App\Auth\TokenAbilities;
use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DeployBusyException;
use App\Exceptions\ProblemException;
use App\Exceptions\ProjectBusyException;
use App\Http\Controllers\Concerns\StreamsNdjson;
use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\ProjectCreateRequest;
use App\Http\Requests\ProjectSourceAttachRequest;
use App\Http\Requests\RecipeChoiceInput;
use App\Http\Requests\UserCloneRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Requests\UserVerifyNewUsernameRequest;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Http\Responses\AcceptedTask;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployStatus;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Domains\MainDomainRename;
use App\Lib\Findings\Hints\FindingPresenter;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Project\Create\ProjectCreation;
use App\Lib\Project\Create\ProjectSourceSetter;
use App\Lib\Project\CreateInspection;
use App\Lib\Project\ProjectCopier;
use App\Lib\Project\ProjectCreator;
use App\Lib\Project\ProjectDeleter;
use App\Lib\Project\ProjectNameCheck;
use App\Lib\Project\ProjectRebuild;
use App\Models\User;
use App\OpenApi\ApiDefinitions;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\CpuLimitDeferred;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    use StreamsNdjson;

    /** How a create answers with what it found in the repository before cloning it. */
    private const INSPECTION_DOC = 'A public github.com repository with no git_token is inspected from its '
        .'file list before anything is created, by the detection POST /source/inspect runs, in '
        .'seconds. It never refuses the create: `data.inspection` carries `verdict` and `strategy`, '
        .'and when the repository may not deploy as it stands (`verdict` not_deployable, '
        .'placeholder or no_start_command) also `reason` and `suggestion`, which open the deploy '
        .'log as warnings. It is null when nothing was inspected: no git_repo, another host, a '
        .'token, or a file list that could not be read within 20 seconds.';

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
     * @return UserCollection
     */
    public function index(Request $request)
    {
        $params = $request->validate([
            'per_page' => 'integer',
        ]);

        $query = $this->listedProjects();

        /** @var LengthAwarePaginator */
        $users = $query->paginate($params['per_page'] ?? 15);

        return new UserCollection($users);
    }

    /**
     * The projects the caller's token reaches, without the system ones.
     *
     * @return Builder<User>
     */
    private function listedProjects(): Builder
    {
        $query = User::query()->with(['liveUser', 'stagingUser']);

        $projects = TokenAbilities::forCurrentRequest()->projects();
        if ($projects !== null) {
            $query->whereIn('username', $projects);
        }

        return SystemProjects::forRequest()->unlist($query);
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
        $query = $this->listedProjects();

        if ($request->boolean('with_domain_names')) {
            $query->with('domains');
        }

        $users = $query->get();

        return new UserCollection($users);
    }

    #[OA\Post(
        path: '/projects',
        description: 'Creates a project and deploys it in the background: answers 202 with a task. Poll GET /tasks/{id} '
            .'until it is completed, failed or cancelled; the deploy log is teed into its task logs (GET '
            .'/tasks/{id}/logs, or the stream), and GET /projects/{username}/deploy-log keeps timings and history. '
            .'**The body is typed.** `source` says where the code comes from, and its `type` decides what else it takes '
            .'(schema ProjectSource): `none` an empty project (a container one waits for files), `git` a repository '
            .'(HTTPS, with an optional token; or an SSH remote with a deploy key from POST /vault/deploy-keys), '
            .'`archive` a .zip/.tar.gz from POST /uploads or a URL, `project` a copy of another project, `wordpress` a '
            .'php_hosting project with WordPress installed. `runtime` is `container` or `php_hosting`; a source implies '
            .'its own (git and archive: container, wordpress: php_hosting, project: the original\'s), omitted it is '
            .'container, and a contradicting one is refused. Limits, hosting settings, dedicated addresses and build '
            .'options sit in objects of their own. A key the body does not have is refused (`unknown_field`), and a '
            .'field of the flat body (`git_repo`, `template`, `memory_limit`, ...) with `legacy_field` and its '
            .'`replacement`; every refusal names the field as sent (`source.url`, `limits.memory`, `domain.name`), '
            .'with the same codes the flat body answers. Nothing is created when the body is refused, including a '
            .'repository the engine cannot read or an upload that is gone. The task carries `details.source_type`; '
            .'a copy is a CopyProject task (`details.action: copy`). **The flat body is still accepted** as before (a '
            .'body with a key only it has, such as `username`, `template`, `git_repo` or a `domain` string, and none '
            .'of runtime, source, limits, settings, network, build, site_password), until a later major version '
            .'removes it; a body of nothing but `name` and `email` is the typed one, an empty container project. GET '
            .'/system/info lists `project_source` in `features` where this body is understood. Unless the caller has a '
            .'domain of its own, leave `domain` out: the engine picks the best public name it can (a configured sites '
            .'base domain, else a free panelalpha.online label, else <name>.<cert_domain>), and project_get says '
            .'which. X-Deploy-Stream is not supported here. '
            .self::INSPECTION_DOC,
        summary: 'Create a new hosting project (async)',
        x: ['mcp-description' => 'Creates a project and deploys it in the background: answers a task `id`; poll task_get until '
            .'completed, failed or cancelled. `source` says what to deploy: {"type": "git", "url": "https://github.com/owner/repo", '
            .'"branch": "main"} (private: "auth": {"type": "token", "token": "vault:<key>"}; SSH remote: "auth": {"type": '
            .'"deploy_key", "key": "vault:<key>"} from vault_deploy_key_create), {"type": "archive", "url": "https://..."} or '
            .'{"type": "archive", "upload_id": "..."} from upload_create, {"type": "project", "from": "<name>"} to copy a '
            .'project, {"type": "wordpress", "title": "...", "admin": {"user", "email", "password"}}, or omit it for an '
            .'empty container project. Leave `domain` out unless the user has a domain of their own: then {"name": "...", '
            .'"tunnel": "none"}. For a public github.com repository `data.inspection` says before the clone whether it may deploy. '
            .'When the user wants one answer with the finished project and can wait, project_create_sync deploys inside the call and returns the project.'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'name',
                    type: 'string',
                    example: 'shop',
                    nullable: true,
                    description: 'The project name: 3-15 lowercase letters and digits, starting with a letter. Generated when '
                        .'omitted, from the repository, the domain or the copied project, with a numeric suffix when taken.',
                ),
                new OA\Property(property: 'runtime', type: 'string', enum: ['container', 'php_hosting'], nullable: true,
                    description: 'container: the application runs in containers of its own. php_hosting: classic Apache/PHP-FPM '
                        .'hosting. Omitted: what the source implies, else container.'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'owner@example.com', nullable: true),
                new OA\Property(property: 'site_password', type: 'string', nullable: true, description: 'Protect the whole site with this password (1-255 characters).', x: ['mcp-hide' => true]),
                new OA\Property(property: 'domain', ref: '#/components/schemas/ProjectDomain'),
                new OA\Property(property: 'limits', ref: '#/components/schemas/ProjectLimits'),
                new OA\Property(property: 'settings', ref: '#/components/schemas/ProjectCreateSettings'),
                new OA\Property(property: 'network', ref: '#/components/schemas/ProjectNetwork'),
                new OA\Property(property: 'source', ref: '#/components/schemas/ProjectSource'),
                new OA\Property(property: 'build', ref: '#/components/schemas/ProjectBuild'),
            ],
        )),
        tags: ['Projects'],
        responses: [
            new OA\Response(response: 202, description: 'Account created; deploy queued. `data.inspection` as described above.', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 400, description: 'X-Deploy-Stream is not supported on the async path'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function storeAsync(ProjectCreateRequest $request, ProjectCreation $creation): JsonResponse
    {
        $this->refuseDeployStreamOnQueuedCreate($request);

        return AcceptedTask::of($creation->queue($request->spec()));
    }

    #[OA\Post(
        path: '/users',
        description: 'Synchronous create: runs the deploy inside the request and returns the finished project, '
            .'for when the caller wants one answer with the result rather than a task to poll. Takes the flat '
            .'body POST /projects still accepts, so a body of only a name is classic hosting here unless '
            .'`template` is dind. Supports X-Deploy-Stream: ndjson. '
            .'Unless the caller has a domain of its own, the name to give a project is a free '
            .'label under panelalpha.online: the zone is a wildcard in front of the PanelAlpha Online '
            .'proxy, so any label resolves worldwide, with a trusted certificate, and no DNS to '
            .'configure. Pass it as `domain` here, then attach it with POST '
            .'/projects/{username}/domains/{domain}/tunnels using the same hostname and provider '
            .'panelalpha. The two must match: the proxy forwards with the Host of the domain the '
            .'tunnel is attached to, so a tunnel on any other local domain makes the application '
            .'answer under a name nobody typed. Labels are first come, first served and are not '
            .'released when a tunnel is deleted -- add a short random suffix, and on 422 pick '
            .'another. Where there is no license key or no public IPv4, fall back to '
            .'<name>.<cert_domain> from GET /system/info, which resolves to this host but is served '
            .'a self-signed certificate. '.self::INSPECTION_DOC,
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
                        .'name, else the domain, else the recipe, else "app" -- with a random numeric '
                        .'suffix when that name is taken. 3-15 lowercase letters and digits, starting '
                        .'with a letter.',
                    x: ['mcp-description' => '3-15 lowercase letters and digits, starting with a letter. Generated from the repository or domain when omitted.']
                ),
                new OA\Property(
                    property: 'domain',
                    type: 'string',
                    example: 'shop-4f2a.panelalpha.online',
                    nullable: true,
                    description: 'The main domain. Omitted, it becomes <username>.<sites_base_domain>, '
                        .'which resolves nowhere while that setting is unset. Prefer a label under '
                        .'panelalpha.online and a matching tunnel -- see the description above.',
                    x: ['mcp-description' => 'Only for a domain of the user\'s own, with tunnel: none. Omitted, the engine picks the best public name it can.']
                ),
                new OA\Property(property: 'domain_redirect_url', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                new OA\Property(property: 'disk_space_limit', type: 'integer', example: 10240, description: 'MB, -1 for unlimited'),
                new OA\Property(property: 'memory_limit', type: 'integer', example: 2048, description: 'MB, at least 6, or 256 for a container project (template dind or a git_repo). Omitted: memory_budget.default_project_mb, the RAM of the server less what is kept for the engine. Refused when larger than memory_budget.max_project_mb in GET /metrics/current', x: ['mcp-description' => 'MB, at least 6, or 256 for a container project (template dind or a git_repo). Default: the server\'s RAM less the engine\'s share, which is also the most allowed.']),
                new OA\Property(property: 'cpu_limit', type: 'number', format: 'float', example: 1.0, nullable: true, description: 'CPU cores, -1 for unlimited'),
                new OA\Property(property: 'bandwidth_limit', type: 'integer', nullable: true, description: 'MB a month, -1 for unlimited. Reported against the month\'s transfer, never enforced'),
                new OA\Property(property: 'mysql_databases_limit', type: 'integer', nullable: true, description: '-1 for unlimited'),
                new OA\Property(property: 'ftp_accounts_limit', type: 'integer', nullable: true, description: '-1 for unlimited'),
                new OA\Property(property: 'sftp_accounts_limit', type: 'integer', nullable: true, description: '-1 for unlimited'),
                new OA\Property(property: 'addon_domains_limit', type: 'integer', nullable: true, description: '-1 for unlimited'),
                new OA\Property(property: 'subdomains_limit', type: 'integer', nullable: true, description: '-1 for unlimited'),
                new OA\Property(property: 'inodes_limit', type: 'integer', nullable: true, description: '-1 for unlimited'),
                new OA\Property(property: 'dedicated_ipv4', type: 'boolean', example: false),
                new OA\Property(property: 'dedicated_ipv6', type: 'boolean', example: false),
                new OA\Property(
                    property: 'template',
                    type: 'string',
                    nullable: true,
                    description: 'dind runs an application in containers of its own, and is what a git_repo '
                        .'deploys into. Omitted over the REST API, the project is classic shared hosting '
                        .'(default: Apache/PHP-FPM, for WordPress and plain PHP sites).',
                    x: ['mcp-default' => 'dind', 'mcp-description' => 'dind runs the app in containers of its own; the other templates are classic shared hosting.']
                ),
                new OA\Property(
                    property: 'tunnel',
                    type: 'string',
                    enum: ['panelalpha', 'none'],
                    nullable: true,
                    description: 'How the domain reaches this host. Omitted, the Engine setting '
                        .'`default_tunnel` decides: `none` keeps the name the domain already resolves on '
                        .'(`<name>.<cert_domain>` or the configured sites_base_domain) and registers '
                        .'nothing at PanelAlpha Online; `panelalpha` and an unset setting both give the '
                        .'default ladder, where a configured sites_base_domain comes first and otherwise '
                        .'a free label under panelalpha.online is allocated, made the project domain and '
                        .'attached in this one call, so `domain`, if given at all, must be that same '
                        .'name. GET /system/info reports the setting. A Cloudflare tunnel is not '
                        .'available here: it needs the project\'s API token, which can only be set once '
                        .'the project exists -- create it, PUT '
                        .'/projects/{username}/settings/cloudflare-api-token, then POST the tunnel.',
                    x: ['mcp-description' => 'Omitted, the Engine setting `default_tunnel` decides: none uses `<name>.<cert_domain>` and registers nothing; panelalpha and an unset setting both use the default ladder (a configured base domain first, a free panelalpha.online name otherwise). An explicit value here wins.']
                ),
                new OA\Property(
                    property: 'git_repo',
                    type: 'string',
                    nullable: true,
                    example: 'https://github.com/owner/repo.git',
                    description: 'HTTPS clone URL. An SSH remote (git@host:owner/repo.git, ssh://...) is '
                        .'refused here, because it clones only with the project\'s deploy key and a project '
                        .'has none until it exists: create the project without git_repo, POST '
                        .'/projects/{username}/git/deploy-key, add the returned public key to the repository, '
                        .'then POST /projects/{username}/git/connect. A schemeless github.com/owner/repo is '
                        .'accepted and has the scheme filled in.',
                    x: ['mcp-description' => 'HTTPS clone URL; github.com/owner/repo also works. For an SSH remote, create the project without it, then git_deploy_key_create and git_connect.']
                ),
                new OA\Property(property: 'git_branch', type: 'string', nullable: true),
                new OA\Property(
                    property: 'git_token',
                    type: 'string',
                    nullable: true,
                    description: 'Optional HTTPS token injected at clone time. Never logged or returned in GET /users. '
                        .'A `vault:<key>` from vault_secret_create is accepted here in place of the literal token, '
                        .'so the token itself never passes through the calling agent. A `project` entry becomes '
                        .'this project\'s own and is refused to any other; a `global` one may be used by any '
                        .'project. Omitted, the repository is cloned anonymously.',
                    x: ['mcp-description' => 'Token for a private repository. Prefer a `vault:<key>` from vault_secret_create over the token itself.']
                ),
                new OA\Property(
                    property: 'env_vars',
                    type: 'object',
                    nullable: true,
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    description: 'Optional KEY=value overrides. Stored on the project and applied to its '
                        .'.env and its container environment on every deploy, outranking what the platform '
                        .'generates. An empty value is not an override and is not stored.',
                    x: ['mcp-description' => 'KEY=value applied to the app\'s .env and container on every deploy.']
                ),
                new OA\Property(
                    property: 'recipe',
                    type: 'string',
                    nullable: true,
                    description: 'Deploy with this recipe instead of the one detection picks. Takes an id '
                        .'from `application.candidates` on POST /source/inspect, and inspecting with the '
                        .'same id previews exactly what this deploys. An id this engine does not ship '
                        .'fails the deploy rather than falling back to detection. Applies to this deploy '
                        .'only - nothing is stored, so the next deploy without it detects again.',
                    example: 'php',
                    x: ['mcp-description' => 'Recipe id to use instead of the detected one, from source_inspect\'s application.candidates. This deploy only.']
                ),
                new OA\Property(
                    property: 'stages',
                    type: 'object',
                    nullable: true,
                    description: 'Commands this deploy runs, per stage (precheck, prepare, build, install, '
                        .'upgrade, start). A stage named here replaces that stage entirely; a stage left '
                        .'out keeps the platform defaults; a stage given as [] runs nothing. Each command '
                        .'is {id, run, optional, serve, timeout, workdir, role}. Applies to this deploy '
                        .'only - nothing is stored, so the next deploy without it is back on defaults.',
                    x: ['mcp-description' => 'Replace a stage\'s commands for this deploy only: {stage: [{id, run, ...}]} for precheck, prepare, build, install, upgrade, start; [] skips a stage.']
                ),
            ],
        )),
        tags: ['Projects'],
        responses: [
            new OA\Response(response: 201, description: 'Project created; the User resource plus `inspection`, as described above.', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(UserStoreRequest $request, ProjectCreator $creator): JsonResponse|StreamedResponse
    {
        $streamDeploy = $this->wantsDeployStream($request);

        $user = $creator->provisionForDeploy($request->newProjectInput($request->nameField()));
        $deployLogger = $creator->startDeployLog($user);

        if ($deployLogger !== null && $streamDeploy) {
            return $this->respondWithDeployStream(
                fn () => $creator->deploy($user, $deployLogger),
                $deployLogger,
                static fn () => ['username' => $user->username, 'domain' => $user->domain]
            );
        }

        $creator->deploy($user, $deployLogger);

        return self::withInspection((new UserResource($user))->response(), $creator->inspection());
    }

    /**
     * Refused before provisioning, so a stream-oriented client does not leave an account behind
     * with no matching HTTP response shape.
     */
    private function refuseDeployStreamOnQueuedCreate(Request $request): void
    {
        if ($request->headers->has('X-Deploy-Stream')) {
            abort(400, 'X-Deploy-Stream is not supported on POST /projects; use POST /users for a synchronous create.');
        }
    }

    /**
     * `data.inspection`: what the inspection before the clone found, or null
     * when it did not run. A create and a source attach carry it.
     */
    private static function withInspection(JsonResponse $response, ?CreateInspection $inspection): JsonResponse
    {
        $payload = $response->getData(true);
        if (is_array($payload) && is_array($payload['data'] ?? null)) {
            $payload['data']['inspection'] = FindingPresenter::inspection($inspection, $payload['data']['username'] ?? null);
            $response->setData($payload);
        }

        return $response;
    }

    /**
     * Opt-in NDJSON streaming of the deploy log, negotiated via the
     * X-Deploy-Stream request header. Unknown values are rejected, before
     * anything is created.
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
        return self::ndjsonResponse(function () use ($pipeline, $deployLogger, $userFrame) {
            self::dropOutputBuffers();
            $this->runStreamedPipeline($pipeline, $deployLogger);
            self::emitFrame(self::closingFrame($deployLogger?->readLatest(), $deployLogger?->getUsername(), $userFrame));
        }, ['X-Deploy-Stream-Accepted' => 'ndjson']);
    }

    /** @param array<string, mixed> $frame */
    private static function emitFrame(array $frame): void
    {
        echo json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        flush();
    }

    /**
     * Emits the start frame and runs the pipeline with its log teed into the stream. Lines written
     * before the stream opened (the "Deploy started" entry) ride along in the start frame, so the
     * consumer's offsets still line up with the log file.
     */
    private function runStreamedPipeline(\Closure $pipeline, ?DeployLogger $deployLogger): void
    {
        $backlog = $deployLogger !== null ? $deployLogger->read(0) : ['lines' => []];
        $latest = $deployLogger?->readLatest();

        DeployLogger::streamTo(self::emitFrame(...));
        try {
            self::emitFrame([
                'type' => 'start',
                'id' => $latest['id'] ?? null,
                'started_at' => $latest['started_at'] ?? null,
                'lines' => $backlog['lines'],
            ]);
            $pipeline();
        } catch (\Throwable $e) {
            self::closeLogAfterFailure($deployLogger, $e);
        } finally {
            DeployLogger::stopStreaming();
        }
    }

    /**
     * A ValidationException is expected once the pipeline has finished the log as failed or
     * cancelled; a log still running is closed as failed here, and anything else is logged.
     */
    private static function closeLogAfterFailure(?DeployLogger $deployLogger, \Throwable $e): void
    {
        $latest = $deployLogger?->readLatest();
        if (($latest['status'] ?? null) === DeployLogger::STATUS_RUNNING) {
            $deployLogger?->recordFailureOutput($e->getMessage());
            $deployLogger?->finish(
                DeployLogger::STATUS_FAILED,
                DeployFailureExplainer::explain($e->getMessage()) ?? $e->getMessage()
            );
        }
        if (! ($e instanceof ValidationException)) {
            Log::error("Streamed deploy failed: {$e->getMessage()}");
        }
    }

    /**
     * The last frame: `continue` while the log still runs (an archive account leaves it running after
     * preparing, and a finish would make the wizard treat the placeholder as deployed), otherwise
     * `finish` with the terminal status and, on success, the user data.
     *
     * @param  array<string, mixed>|null  $latest
     * @return array<string, mixed>
     */
    private static function closingFrame(?array $latest, ?string $username, ?callable $userFrame): array
    {
        $status = $latest['status'] ?? null;
        if ($status === DeployLogger::STATUS_RUNNING) {
            return [
                'type' => 'continue',
                'status' => $status,
                'stage' => $latest['stage'] ?? null,
                'stages' => $latest['stages'] ?? [],
            ];
        }
        $frame = [
            'type' => 'finish',
            'status' => $status,
            'stage' => $latest['stage'] ?? null,
            'stages' => $latest['stages'] ?? [],
            'error' => DeployStatus::errorOf($latest ?? []),
            'warnings' => FindingPresenter::present(DeployStatus::warningsOf($latest ?? []), $username),
            'failure' => FindingPresenter::presentOne(DeployStatus::failureOf($latest ?? []), $username),
            'finished_at' => $latest['finished_at'] ?? null,
            'problem' => $latest['problem'] ?? null,
        ];
        if (
            $userFrame !== null
            && in_array($frame['status'], [DeployLogger::STATUS_SUCCESS, DeployLogger::STATUS_PARTIAL], true)
        ) {
            $frame['user'] = $userFrame();
        }

        return $frame;
    }

    #[OA\Post(
        path: '/projects/{username}/rebuild',
        summary: 'Redeploy a project',
        description: 'Detects, builds and starts the application again. A project deployed from git '
            .'is cloned again from the tip of its branch first: the deploy picks up new commits without a '
            .'pull, a revert to an older commit is undone, and files written into ~/project since the last '
            .'deploy are discarded. Keep such changes in the repository, and data the application writes in '
            .'its volumes or a database, which a rebuild keeps. Any other project is built from the files '
            .'already in ~/project, after importing `zip_path` into it when given (refused on a project '
            .'deployed from git). The request is '
            .'checked and `env_vars` applied at once; the deploy itself runs in a queue job, and the answer '
            .'is 202 with a task (`details.action: rebuild`). Poll GET /tasks/{id} until it is completed, '
            .'failed or cancelled: a failure is on the task as `details.error`, and `details.problems` '
            .'carries its code, stage and deploy_log_offset. While a deploy of this project is queued or '
            .'running the answer is 409 with that deploy\'s `task_id`: follow it rather than calling again, '
            .'or cancel it with POST /tasks/{id}/cancel if it is stuck. `task_id` is null when the running '
            .'deploy was not started as a task (the CLI, a push); follow GET /projects/{username}/deploy-log '
            .'then. With X-Deploy-Stream: ndjson the deploy runs in the request instead and its log is '
            .'streamed as NDJSON.',
        x: ['mcp-description' => 'Detects, builds and starts the app again. A git project is cloned again '
            .'from its branch tip first, so files written into ~/project since the last deploy are lost: '
            .'commit them to the repository instead. Any other project builds from the files in ~/project, '
            .'importing `zip_path` first when given (refused on a git project). Answers at once with a task `id`: '
            .'follow it with task_get until completed, failed or cancelled; a failure is in details.error '
            .'and details.problems. A 409 means a deploy is already running: follow the task_id it names '
            .'with task_get (task_cancel if it is stuck), or deploy_log_get when task_id is null, instead '
            .'of calling again.'],
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
                        .'send only what changes. An empty value removes that key; null clears them all.',
                    x: [
                        'mcp-description' => 'KEY=value changes merged onto the project\'s; an empty value removes a key, null clears them all.',
                        'mcp-nullable' => true,
                    ]
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
                        .'from `application.candidates` on POST /source/inspect, and inspecting with the '
                        .'same id previews exactly what this deploys. An id this engine does not ship '
                        .'fails the deploy rather than falling back to detection. Applies to this deploy '
                        .'only - nothing is stored, so the next deploy without it detects again.',
                    example: 'php',
                    x: ['mcp-description' => 'Recipe id to use instead of the detected one, from source_inspect\'s application.candidates. This deploy only.']
                ),
                new OA\Property(
                    property: 'stages',
                    type: 'object',
                    nullable: true,
                    description: 'Commands this deploy runs, per stage. A stage named here replaces that '
                        .'stage entirely; a stage left out keeps the platform defaults; a stage given as '
                        .'[] runs nothing. Applies to this deploy only - nothing is stored.',
                    x: ['mcp-description' => 'Replace a stage\'s commands for this deploy only: {stage: [{id, run, ...}]} for precheck, prepare, build, install, upgrade, start; [] skips a stage.']
                ),
            ],
        )),
        responses: [
            new OA\Response(
                response: 200,
                description: 'With X-Deploy-Stream: ndjson: the deploy runs in the request and its log streams as '
                    .'NDJSON, a `start` frame, `line` and `stage` frames, then a `finish` frame with the status',
                content: new OA\MediaType(mediaType: 'application/x-ndjson'),
            ),
            new OA\Response(response: 202, description: 'Deploy queued; `data` is the task to follow', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 400, description: 'Unsupported X-Deploy-Stream value'),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'A deploy of this project is already queued or running. '.ApiDefinitions::PROTECTED_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: 'The project could not be locked to start the deploy, or, with X-Deploy-Stream: ndjson, its deploy log could not be opened to stream from'),
        ],
    )]
    public function rebuild(string $username, Request $request, ProjectRebuild $rebuild): JsonResponse|StreamedResponse
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
        $zipPath = ($params['zip_path'] ?? '') !== '' ? $params['zip_path'] : null;
        if ($zipPath !== null) {
            ProjectRebuild::refuseArchiveOnGitProject($user);
        }
        $plan = DeployPlanInput::parse($request->input(DeployPlanInput::FIELD));
        $recipe = RecipeChoiceInput::parse($request->input(RecipeChoiceInput::FIELD));
        if ($zipPath !== null && $user->getTemplate() === 'dind') {
            $rebuild->assertArchive($user, $zipPath);
        }
        $stream = $user->getTemplate() === 'dind' && $this->wantsDeployStream($request);
        $envVars = array_key_exists('env_vars', $params)
            ? static fn (): array => ProjectRebuild::sentEnvVars($user, $request->input('env_vars'))
            : null;

        return $rebuild->whileIdle($user, fn () => $this->startRedeploy(
            $rebuild, $user, ProjectRebuild::REBUILD, $zipPath, $plan, $recipe, $stream, $envVars, $request->input('env_vars')
        ));
    }

    /**
     * Queue the redeploy and answer 202 with its task; or, when the client
     * asked for the stream, run it here and stream its log. Runs while no
     * other deploy of the project can be queued or started. The env_vars are
     * resolved before anything starts, so a vault reference they cannot use is still a 422.
     *
     * @param  ?\Closure(): array<string, string>  $envVars  the env_vars to store, null to leave them
     * @param  mixed  $sentEnvVars  the env_vars as sent, before their vault references were read
     */
    private function startRedeploy(
        ProjectRebuild $rebuild,
        User $user,
        string $action,
        ?string $zipPath,
        ?DeployPlan $plan,
        ?string $recipe,
        bool $stream,
        ?\Closure $envVars,
        mixed $sentEnvVars,
    ): JsonResponse|StreamedResponse {
        $env = $envVars !== null ? $envVars() : null;
        $deployLogger = $stream ? $this->openStreamLog($rebuild, $user, $action) : null;

        $rebuild->storeEnvVars($user, $env, $sentEnvVars);

        if ($deployLogger !== null) {
            app(DeployPlanContext::class)->set($plan);
            app(RecipeChoiceContext::class)->set($recipe);

            return $this->respondWithDeployStream(
                fn () => $rebuild->run($user, $action, $deployLogger, $zipPath),
                $deployLogger,
                static fn () => ['username' => $user->username, 'domain' => $user->domain]
            );
        }

        $task = $rebuild->queue($user, $action, $zipPath, $plan, $recipe);

        return TaskResource::make($task)->response()->setStatusCode(202);
    }

    /**
     * The log a streamed redeploy writes to. A deploy with no task that took the lock since the
     * idle check is answered as the check would have; a log that cannot be opened is a 503.
     */
    private function openStreamLog(ProjectRebuild $rebuild, User $user, string $action): DeployLogger
    {
        try {
            $deployLogger = $rebuild->openLogWithoutTask($user, $action);
        } catch (DeployAlreadyRunningException) {
            throw new DeployBusyException($user->username, null);
        }
        if ($deployLogger === null) {
            abort(new JsonResponse([
                'message' => 'The deploy log could not be opened, so there is nothing to stream. '
                    .'Try again, or leave out X-Deploy-Stream to queue the deploy.',
            ], 503));
        }

        return $deployLogger;
    }

    #[OA\Post(
        path: '/projects/{username}/deploy-archive',
        summary: 'Deploy an uploaded zip/tar into ~/project',
        description: 'Engine-only path: unwrap a single top-level directory, detect project type, apply strategy, '
            .'docker compose up. Upload the archive first: POST /projects/{username}/files/upload (file_upload '
            .'over MCP, with file_contents or file_url), or an FTP/SFTP account; zip_path is relative to the '
            .'account home, e.g. /project/app.zip. The archive is checked (it exists in the home and is a .zip '
            .'or .tar.gz) and `env_vars` applied at once; the deploy runs in a queue job, and the answer is '
            .'202 with a task (`details.action: deploy_archive`). Poll GET /tasks/{id} until it is completed, '
            .'failed or cancelled: a failure is on the task as `details.error`, and `details.problems` carries '
            .'its code, stage and deploy_log_offset. While a deploy of this project is queued or running the '
            .'answer is 409 with that deploy\'s `task_id`, as POST /projects/{username}/rebuild describes. '
            .'With X-Deploy-Stream: ndjson the deploy runs in the request instead and its log is streamed '
            .'as NDJSON.',
        x: ['mcp-description' => 'Deploys an archive already in the account (put there with file_upload or FTP), '
            .'e.g. zip_path /project/app.zip. A single top-level directory is unwrapped. Extraction overwrites '
            .'files of the same name in ~/project and that cannot be undone: ask the operator to confirm first. '
            .'Answers at once with a task `id`: follow it with task_get until completed, failed or cancelled; a '
            .'failure is in details.error and details.problems. A 409 means a deploy is already running: follow '
            .'the task_id it names with task_get (task_cancel if it is stuck), or deploy_log_get when task_id is '
            .'null, instead of calling again.'],
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['zip_path'],
            properties: [
                new OA\Property(property: 'zip_path', type: 'string', example: '/project/app.zip'),
                new OA\Property(
                    property: 'env_vars',
                    type: 'object',
                    nullable: true,
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    description: 'KEY=value overrides, merged onto the ones the project already carries — '
                        .'send only what changes. An empty value removes that key; null clears them all.',
                    x: [
                        'mcp-description' => 'KEY=value changes merged onto the project\'s; an empty value removes a key, null clears them all.',
                        'mcp-nullable' => true,
                    ]
                ),
                new OA\Property(
                    property: 'recipe',
                    type: 'string',
                    nullable: true,
                    description: 'Deploy with this recipe instead of the one detection picks. Takes an id '
                        .'from `application.candidates` on POST /source/inspect, and inspecting with the '
                        .'same id previews exactly what this deploys. An id this engine does not ship '
                        .'fails the deploy rather than falling back to detection. Applies to this deploy '
                        .'only - nothing is stored, so the next deploy without it detects again.',
                    example: 'php',
                    x: ['mcp-description' => 'Recipe id to use instead of the detected one, from source_inspect\'s application.candidates. This deploy only.']
                ),
                new OA\Property(
                    property: 'stages',
                    type: 'object',
                    nullable: true,
                    description: 'Commands this deploy runs, per stage. A stage named here replaces that '
                        .'stage entirely; a stage left out keeps the platform defaults; a stage given as '
                        .'[] runs nothing. Applies to this deploy only - nothing is stored.',
                    x: ['mcp-description' => 'Replace a stage\'s commands for this deploy only: {stage: [{id, run, ...}]} for precheck, prepare, build, install, upgrade, start; [] skips a stage.']
                ),
            ],
        )),
        responses: [
            new OA\Response(
                response: 200,
                description: 'With X-Deploy-Stream: ndjson: the deploy runs in the request and its log streams as '
                    .'NDJSON, a `start` frame, `line` and `stage` frames, then a `finish` frame with the status',
                content: new OA\MediaType(mediaType: 'application/x-ndjson'),
            ),
            new OA\Response(response: 202, description: 'Deploy queued; `data` is the task to follow', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 400, description: 'Unsupported X-Deploy-Stream value'),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'A deploy of this project is already queued or running. '.ApiDefinitions::PROTECTED_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: 'The project could not be locked to start the deploy, or, with X-Deploy-Stream: ndjson, its deploy log could not be opened to stream from'),
        ],
    )]
    public function deployArchive(string $username, Request $request, ProjectRebuild $rebuild): JsonResponse|StreamedResponse
    {
        $user = $this->projectOr404($username);
        ProjectRebuild::refuseArchiveOnClassicProject($user);

        /** @var array{zip_path: string, env_vars?: array<string, string>} $params */
        $params = $request->validate([
            'zip_path' => 'string|required|max:4096',
            'env_vars' => 'array|nullable|max:200',
            'env_vars.*' => 'string|nullable|max:8192',
            'stages' => 'array|nullable',
            'recipe' => 'string|nullable|max:64',
        ]);
        ProjectRebuild::refuseArchiveOnGitProject($user);
        $plan = DeployPlanInput::parse($request->input(DeployPlanInput::FIELD));
        $recipe = RecipeChoiceInput::parse($request->input(RecipeChoiceInput::FIELD));
        $rebuild->assertArchive($user, $params['zip_path']);
        $stream = $this->wantsDeployStream($request);
        $envVars = array_key_exists('env_vars', $params)
            ? static fn (): array => ProjectRebuild::sentEnvVars($user, $request->input('env_vars'))
            : null;

        return $rebuild->whileIdle($user, fn () => $this->startRedeploy(
            $rebuild, $user, ProjectRebuild::DEPLOY_ARCHIVE, $params['zip_path'], $plan, $recipe, $stream, $envVars, $request->input('env_vars')
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/source',
        summary: 'Attach a git source to a project that has none',
        description: 'Gives a project created before its source was known the repository it deploys from, and '
            .'deploys it. Only a dind project (`template: dind`) that carries no git_repo and has not produced '
            .'an application yet is accepted: the account a source-less create leaves waiting for files. A '
            .'classic shared-hosting project, and a dind project that already runs an application, are refused '
            .'with 409 problems[{field: git_repo, code: project_not_eligible}] -- converting a classic account '
            .'to dind is not supported, and an existing application is not replaced. A project that already has '
            .'a git_repo is refused with 409 problems[{field: git_repo, code: source_already_attached}]. The '
            .'repository is read as POST /projects reads it: vault references resolved, private, loopback and '
            .'reserved addresses refused, and the remote probed for the branch. The source is stored and the '
            .'deploy runs in a queue job; the answer is 202 with a task (`details.action: attach_source`, '
            .'`details.git_repo`). Follow it as a create. While a deploy of this project is queued or running '
            .'the answer is 409 with that deploy\'s `task_id`. A clone or build failure leaves the project dind '
            .'with the source attached and a failed deploy, which POST /projects/{username}/rebuild retries.',
        x: ['mcp-description' => 'Gives a dind project created without a source the git_repo it deploys from, and '
            .'deploys it. Answers at once with a task `id`: follow it with task_get until completed, failed or '
            .'cancelled; a failure is in details.error and details.problems. Refuses a project that already has a '
            .'git_repo, a classic project, and a dind project already running an application.'],
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['git_repo'],
            properties: [
                new OA\Property(
                    property: 'git_repo',
                    type: 'string',
                    example: 'https://github.com/owner/repo.git',
                    description: 'HTTPS clone URL, the same rule POST /projects applies. A schemeless '
                        .'github.com/owner/repo is accepted and has the scheme filled in.',
                ),
                new OA\Property(property: 'git_branch', type: 'string', nullable: true, description: 'Branch or tag to deploy; omitted, the remote default branch.'),
                new OA\Property(
                    property: 'git_token',
                    type: 'string',
                    nullable: true,
                    description: 'Optional HTTPS token injected at clone time. A `vault:<key>` from vault_secret_create '
                        .'is accepted here in place of the literal token. Never logged or returned.',
                ),
                new OA\Property(
                    property: 'env_vars',
                    type: 'object',
                    nullable: true,
                    additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    description: 'Optional KEY=value overrides, merged onto the ones the project already carries. '
                        .'An empty value removes that key; null clears them all.',
                ),
                new OA\Property(
                    property: 'recipe',
                    type: 'string',
                    nullable: true,
                    description: 'Deploy with this recipe instead of the one detection picks. This deploy only.',
                    example: 'php',
                ),
                new OA\Property(
                    property: 'stages',
                    type: 'object',
                    nullable: true,
                    description: 'Commands this deploy runs, per stage. This deploy only.',
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 202, description: 'Source stored; deploy queued. `data` is the task to follow, with `data.inspection` as a create returns it.', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'A deploy of this project is already queued or running (message and task_id), or the project cannot take a source (problems: source_already_attached, project_not_eligible). '.ApiDefinitions::PROTECTED_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: 'The project could not be locked to start the attach'),
        ],
    )]
    public function attachSource(string $username, ProjectSourceAttachRequest $request, ProjectSourceSetter $setter): JsonResponse
    {
        return AcceptedTask::of($setter->attachGit($this->projectOr404($username), $request->newProjectInput('username')));
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
                new OA\Property(
                    property: 'copy_databases',
                    description: 'Copy the source project\'s MySQL databases under the clone prefix. Defaults to true. '
                        .'A classic WordPress site cannot be copied without them: false is refused with 422 '
                        .'`wordpress_copy_needs_databases` before anything is copied.',
                    type: 'boolean',
                    nullable: true,
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Cloned project', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 403, description: ApiDefinitions::OUT_OF_SCOPE_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * Clone a project.
     *
     * Creates a new project with the same resource limits and settings as the
     * source, and copies the source's home directory (including named Docker
     * volumes) and project config files to the new project after provisioning
     * completes. A clone given no domain is named `clone.` in front of its
     * source's, never the `staging.` address its source's staging copy takes.
     * A source on a free panelalpha.online name gets a clone with a new name of
     * its own there, since a name below it would not be served over HTTPS. An
     * anonymous volume (an image's own VOLUME the compose file does not name) is
     * not copied: the response's `warnings` names each one that held data.
     *
     * Subdomains, FTP/SFTP accounts and dedicated IP addresses are NOT copied.
     * MySQL databases are, under the clone's prefix, together with the users
     * that reach them, unless `copy_databases` is false.
     *
     * @param  string  $username  Source project's name.
     */
    public function clone(string $username, UserCloneRequest $request, ProjectCopier $copier): UserResource
    {
        $srcUser = $this->projectOr404($username);

        /** @var array{new_username?: ?string, domain?: ?string, copy_databases?: ?bool} */
        $params = $request->validated();

        [$copy, $warnings] = $copier->cloneNow($srcUser, $params['new_username'] ?? null, $params['domain'] ?? null, $params['copy_databases'] ?? true);
        $resource = new UserResource($copy);

        return $warnings === [] ? $resource : $resource->additional(['warnings' => $warnings]);
    }

    #[OA\Post(
        path: '/projects/verify-new-username',
        summary: 'Check whether a project name is free',
        description: 'Answers 200 for every well-formed name: `valid` is true when the name is free. When it is not, '
            .'`valid` is false and `reason` says why: `taken` (another project uses it) or `unavailable` '
            .'(reserved on this host). Only a missing name, or one that breaks the naming rules, is refused with 422.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['username'],
            properties: [new OA\Property(property: 'username', type: 'string', example: 'johndoe')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The name was checked', content: new OA\JsonContent(
                required: ['valid'],
                properties: [
                    new OA\Property(property: 'valid', type: 'boolean', example: false),
                    new OA\Property(property: 'reason', type: 'string', enum: ['taken', 'unavailable'], example: 'taken', description: 'Only when `valid` is false'),
                    new OA\Property(property: 'message', type: 'string', example: 'Project name already in use.', description: 'Only when `valid` is false'),
                ],
            )),
            new OA\Response(response: 422, description: 'The name is missing or breaks the naming rules', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @return JsonResponse
     */
    public function verifyNewUsername(UserVerifyNewUsernameRequest $request)
    {
        /** @var array{
         *   username: string,
         * }
         */
        $params = $request->validated();

        return new JsonResponse(ProjectNameCheck::answer($params['username']));
    }

    #[OA\Get(
        path: '/projects/{username}',
        summary: 'Get a project',
        x: ['mcp-description' => 'The project\'s record: status, domain, template, limits and settings. '
            .'For resource use - disk, bandwidth and the space its container logs take - call project_usage.'],
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Project details', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
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
        description: 'A token limited to some projects may change `domain` and `email` only. The resource '
            .'limits and the PHP-FPM, LSPHP and Redis settings are the operator\'s: sending any of them '
            .'with such a token is refused with 403, naming the fields.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'domain', type: 'string', nullable: true, description: 'Renames the main domain. A name given as `www.example.org` becomes `example.org` with `www.example.org` as an alias, as adding a domain makes it. '
                    .'Not to a name under panelalpha.online (add it as a tunnel instead). '
                    .'A PanelAlpha Online tunnel that is the old name itself is deleted with it; any other tunnel of the main domain has to be deleted first.'),
                new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'disk_space_limit', type: 'integer', nullable: true, description: 'MB, -1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'memory_limit', type: 'integer', description: 'MB, at least 6, or 256 for a container project. Can be changed, not removed. Refused when larger than memory_budget.max_project_mb in GET /metrics/current'),
                new OA\Property(property: 'cpu_limit', type: 'number', format: 'float', nullable: true, description: 'CPU cores, -1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'device_read_bps', type: 'integer', nullable: true, description: '-1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'device_write_bps', type: 'integer', nullable: true, description: '-1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'bandwidth_limit', type: 'integer', nullable: true, description: 'MB a month, -1 for unlimited; null clears the limit. Reported against the month\'s transfer, never enforced', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'mysql_databases_limit', type: 'integer', nullable: true, description: '-1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'ftp_accounts_limit', type: 'integer', nullable: true, description: '-1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'sftp_accounts_limit', type: 'integer', nullable: true, description: '-1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'addon_domains_limit', type: 'integer', nullable: true, description: '-1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'subdomains_limit', type: 'integer', nullable: true, description: '-1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
                new OA\Property(property: 'inodes_limit', type: 'integer', nullable: true, description: '-1 for unlimited; null clears the limit', x: ['mcp-nullable' => true]),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated project. A container project takes a new memory or CPU limit at once; '
                .'`warnings` is present when its running container could not take the CPU limit, which then applies when '
                .'the container is next recreated', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 403, description: ApiDefinitions::OPERATOR_ONLY_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: ApiDefinitions::PROTECTED_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
            new OA\Response(response: 422, description: 'Validation error; on domain: domain_taken when the domain is already on this engine, domain_proxy_zone for a name under panelalpha.online, domain_has_tunnels while the main domain has tunnels other than its own PanelAlpha Online name, domain_rename_failed when the rename itself fails', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
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
        $this->applyResourceLimits($user, $params);
        $this->applyServiceSettings($user, $params);

        if (array_key_exists('domain', $params)) {
            MainDomainRename::apply($user, (string) $params['domain']);
        }

        $user->save();
        $warnings = $this->applyLimitsNow($user, $params);
        $resource = new UserResource($user);

        return $warnings === [] ? $resource : $resource->additional(['warnings' => $warnings]);
    }

    /**
     * Stored as the console stores them: -1 is "no limit", and an explicit null clears the limit.
     *
     * @param  array<string, mixed>  $params
     */
    private function applyResourceLimits(User $user, array $params): void
    {
        foreach (ResourceLimit::all() as $limit) {
            if (array_key_exists($limit->key, $params)) {
                $raw = $params[$limit->key];
                $user->setDetails([$limit->key => $raw === null ? null : $limit->normalize($raw)]);
            }
        }
    }

    /** @param array<string, mixed> $params */
    private function applyServiceSettings(User $user, array $params): void
    {
        foreach (['php_fpm_pool_settings', 'lsphp_settings', 'redis_config'] as $key) {
            if (array_key_exists($key, $params)) {
                $user->setDetails([$key => $params[$key]]);
            }
        }
    }

    /**
     * A new memory or CPU limit is applied to the running account at once, not at the next rebuild.
     *
     * @param  array<string, mixed>  $params
     * @return list<string> why a limit waits for the account's container to be recreated
     */
    private function applyLimitsNow(User $user, array $params): array
    {
        $runtime = $user->project(app(System::class))->runtime();
        if (! $runtime instanceof Dind) {
            return [];
        }

        if (array_key_exists('memory_limit', $params)) {
            $runtime->applyMemoryLimit();
        }
        if (array_key_exists('cpu_limit', $params)) {
            try {
                $runtime->applyCpuLimit();
            } catch (CpuLimitDeferred $deferred) {
                return [$deferred->getMessage()];
            }
        }

        return [];
    }

    #[OA\Put(
        path: '/projects/{username}/suspend',
        summary: 'Suspend a project',
        description: 'Only an unscoped token may call this: a token limited to some projects is refused with 403, even on its own project.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Project suspended', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 403, description: ApiDefinitions::OPERATOR_ONLY_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: ApiDefinitions::PROTECTED_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
        ],
    )]
    public function suspend(string $username): UserResource
    {
        $user = $this->projectOr404($username);

        $user->status = 'suspended';
        $user->save();

        $system = app(System::class);
        $this->rotatePhpMyAdminSsoPassword($system, $user);
        $reloaded = $system->rebuildDomains();

        return (new UserResource($user))->additional(['reload_pending' => ! $reloaded]);
    }

    /**
     * An open phpMyAdmin session works through any domain's /phpmyadmin, so a new SSO password cuts
     * it off; the next token sets a fresh one. Best-effort: a failure is only logged.
     */
    private function rotatePhpMyAdminSsoPassword(System $system, User $user): void
    {
        try {
            $sso = 'sso_'.$user->username;
            if ($system->mysql()->users()->userExists($sso)) {
                $system->mysql()->users()->changeUserPassword($sso, Str::random(27));
            }
        } catch (\Throwable $e) {
            Log::warning("Could not rotate the phpMyAdmin SSO password of {$user->username}: ".$e->getMessage());
        }
    }

    #[OA\Put(
        path: '/projects/{username}/unsuspend',
        summary: 'Unsuspend a project',
        description: 'Only an unscoped token may call this: a token limited to some projects is refused with 403, even on its own project.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Project unsuspended', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 403, description: ApiDefinitions::OPERATOR_ONLY_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function unsuspend(string $username): UserResource
    {
        $user = $this->projectOr404($username);

        $user->status = 'active';
        $user->save();

        $system = new System;
        $reloaded = $system->rebuildDomains();

        return (new UserResource($user))->additional(['reload_pending' => ! $reloaded]);
    }

    #[OA\Delete(
        path: '/projects/{username}',
        summary: 'Delete a project and everything in it',
        x: ['mcp-description' => 'Deletes the project and everything in it: its container, home files, MySQL '
            .'databases and users, domains, FTP/SFTP accounts and cron jobs. This cannot be undone. Ask the '
            .'operator to confirm first and say what will be lost; a backup is the only way back.'],
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Project deleted', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'The delete is refused: a task, deploy or push is working on the project, or its staging copy still exists. '.ApiDefinitions::PROTECTED_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
        ],
    )]
    /**
     * @param  string  $username
     * @return UserResource
     */
    public function destroy($username)
    {
        $user = $this->projectOr404($username);
        $this->deleteProject($user);

        return new UserResource($user);
    }

    protected function projectDeleter(): ProjectDeleter
    {
        return new ProjectDeleter;
    }

    /** A refused delete is a refusal, not a failure: answered as it stands and not logged. Any other failure is logged and rethrown. */
    private function deleteProject(User $user): void
    {
        try {
            $this->projectDeleter()->delete($user);
        } catch (ProjectBusyException|ProblemException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning(
                "Could not delete user '{$user->username}': ".$e->getMessage(),
                ['exception' => $e],
            );
            throw $e;
        }
    }
}

<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Git\GitChangeBranchRequest;
use App\Http\Requests\Git\GitCommitsRequest;
use App\Http\Requests\Git\GitConnectRequest;
use App\Http\Requests\Git\GitDeployKeyRequest;
use App\Http\Requests\Git\GitPathRequest;
use App\Http\Requests\Git\GitPullRequest;
use App\Http\Requests\Git\GitRevertRequest;
use App\Http\Requests\Git\GitStatusRequest;
use App\Http\Requests\Git\GitUpdateCredentialsRequest;
use App\Http\Resources\TaskResource;
use App\Lib\Git\DeployKey;
use App\Lib\Git\GitActions;
use App\Lib\Project\ProjectRebuild;
use App\Models\User;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class GitController extends Controller
{
    private const QUEUED_ON_DEPLOY = 'On a Deploy-managed checkout (`managed_by` is `deploy`) the request is checked at once '
        . '(the remote is read, 400 with git\'s error when it cannot be; the branch or ref must exist, 422 otherwise), then the change and the rebuild after it '
        . 'run in a queue job: the answer is 202 with a task. Poll GET /tasks/{id} until it is completed, failed or '
        . 'cancelled: a failure is on the task as `details.error` and `details.problems`, and `details.commit` is the '
        . 'commit a completed one deployed. A new version that fails while the previous one still runs leaves it '
        . 'serving, with its checkout put back';

    private const BUSY = 'While a deploy of a Deploy-managed checkout\'s project is queued or running the answer is 409 with '
        . 'that deploy\'s `task_id`: follow it rather than calling again, or cancel it with POST /tasks/{id}/cancel if '
        . 'it is stuck; `task_id` is null when the running deploy was not started as a task (the CLI, a push), and '
        . 'GET /projects/{username}/deploy-log follows it then.';

    private const QUEUED_RESPONSE = 'Deploy-managed checkout: the change and the rebuild are queued; `data` is the task to follow';

    private const BUSY_RESPONSE = 'A deploy of this project is already queued or running';

    private const GIT_ERROR_RESPONSE = 'Git failed, e.g. the remote could not be read';

    private const LOCK_RESPONSE = 'Deploy-managed checkout: the project could not be locked to queue the change; try again';

    private const MCP_QUEUED = 'On a managed_by deploy checkout the change and the rebuild after it run in the background: '
        . 'the answer is a task `id` at once; follow it with task_get until completed, failed or cancelled (a failure '
        . 'is in details.error and details.problems, the deployed commit in details.commit). A 409 means a deploy is '
        . 'already running: follow the task_id it names with task_get (task_cancel if it is stuck), or deploy_log_get '
        . 'when task_id is null, instead of calling again.';

    #[OA\Get(
        path: '/projects/{username}/git/status',
        description: 'Call this first. Optional `path` defaults to `project` on DinD and the document root '
            . 'of the main domain on FPM/LiteSpeed. Query `fetch` updates remote-tracking refs before reporting. The '
            . 'payload includes `managed_by`: `deploy` (account provisioned with git_repo; a pull, branch change '
            . 'or revert rebuilds the app after it, as a task, and `project_rebuild` redeploys it unchanged) or '
            . '`site_git` (the Git tools change the checkout and nothing is rebuilt). `connecting: true` '
            . 'means a connect is still fetching the repository: wait and poll; do not call connect again.',
        summary: 'Git repository status',
        security: [['bearerAuth' => []]],
        tags: ['Git'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'fetch', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Git status', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'path', type: 'string'),
                        new OA\Property(property: 'path_key', type: 'string'),
                        new OA\Property(property: 'managed_by', type: 'string', enum: ['deploy', 'site_git']),
                        new OA\Property(property: 'connected', type: 'boolean'),
                        new OA\Property(property: 'connecting', type: 'boolean'),
                        new OA\Property(property: 'connecting_since', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'repository_exists', type: 'boolean'),
                    ]),
                ],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function status(string $username, GitStatusRequest $request, GitActions $git): JsonResponse
    {
        $params = $request->validated();
        $user = $this->projectOr404($username);

        return $this->respond(fn () => $git->status($user, $params, $request->boolean('fetch')));
    }

    #[OA\Get(
        path: '/projects/{username}/git/branches',
        description: 'List git branches. Optional `path` defaults to `project` on DinD and the document root '
            . 'of the main domain on FPM/LiteSpeed.',
        summary: 'List git branches',
        security: [['bearerAuth' => []]],
        tags: ['Git'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Branch list', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function branches(string $username, GitPathRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(fn () => $git->branches($user, $request->validated()));
    }

    #[OA\Get(
        path: '/projects/{username}/git/commits',
        description: 'List git commits. Optional `path` defaults to `project` on DinD and the document root '
            . 'of the main domain on FPM/LiteSpeed. Query `branch` filters the log; `limit` caps how many commits are returned.',
        summary: 'List git commits',
        security: [['bearerAuth' => []]],
        tags: ['Git'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Commit list', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function commits(string $username, GitCommitsRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(fn () => $git->commits($user, $request->validated()));
    }

    #[OA\Post(
        path: '/projects/{username}/git/connect',
        description: 'Connect a directory to a git remote. Optional `path` defaults to `project` on DinD '
            . 'and the document root of the main domain on FPM/LiteSpeed. Body `repo_url` and `branch` are required. Optional '
            . '`token` is a PAT (never logged). Set `repair` to re-adopt a missing .git. On a `deploy` '
            . 'account without repair this only keeps origin in sync and persists metadata — it does '
            . 'not clone from scratch. An SSH `repo_url` (`git@host:owner/repo.git`) needs the project\'s '
            . 'deploy key (POST /projects/{username}/git/deploy-key) added to the repository first.',
        summary: 'Connect a directory to a git remote',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['repo_url', 'branch'],
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'repo_url', description: 'HTTPS URL, or an SSH remote when the project has a deploy key.', type: 'string'),
                new OA\Property(property: 'branch', type: 'string'),
                new OA\Property(property: 'token', type: 'string', nullable: true),
                new OA\Property(property: 'auth_type', type: 'string', enum: ['pat']),
                new OA\Property(property: 'repair', type: 'boolean'),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Connected', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function connect(string $username, GitConnectRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(fn () => $git->connectRemote($user, $request->validated()));
    }

    #[OA\Post(
        path: '/projects/{username}/git/disconnect',
        description: 'Disconnect git from a directory. Optional `path` defaults to `project` on DinD and '
            . 'the document root of the main domain on FPM/LiteSpeed. Removes `origin` and site-git metadata; does not delete '
            . 'working-tree files. Also removes the checkout\'s Deploy Hook, if it has one -- a '
            . 'disconnected checkout has nothing for a push to deploy. Returns 422 when managed_by is `deploy`.',
        summary: 'Disconnect git from a directory',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', type: 'string')],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Disconnected', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function disconnect(string $username, GitPathRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(fn () => $git->disconnect($user, $request->validated()));
    }

    #[OA\Put(
        path: '/projects/{username}/git/change-branch',
        description: 'Change the tracked git branch. Optional `path` defaults to `project` on DinD and '
            . 'the document root of the main domain on FPM/LiteSpeed. Body `branch` is required. Returns 422 if the working '
            . 'tree is dirty or the remote branch does not exist. On a `site_git` checkout the change runs in the request '
            . 'and the answer is 200 with the checkout. ' . self::QUEUED_ON_DEPLOY . ' (`details.action: change_branch`). '
            . self::BUSY,
        summary: 'Change the tracked git branch',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['branch'],
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'branch', type: 'string'),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Branch changed (a `site_git` checkout)', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 202, description: self::QUEUED_RESPONSE, content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 400, description: self::GIT_ERROR_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: self::BUSY_RESPONSE, content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'task_id', type: 'integer', nullable: true),
                ],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: self::LOCK_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
        x: ['mcp-description' => 'Switches the checkout to another branch. Optional `path` defaults to `project` on DinD and '
            . 'the document root of the main domain on FPM/LiteSpeed. Refused (422) when the working tree is dirty or the '
            . 'remote has no such branch. ' . self::MCP_QUEUED . ' Use project_rebuild to redeploy without changing the branch.'],
    )]
    public function changeBranch(string $username, GitChangeBranchRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);
        $params = $request->validated();

        return $this->queuedOrDone($git, $user, ProjectRebuild::CHANGE_BRANCH, $params, fn () => $git->changeBranch($user, $params));
    }

    #[OA\Put(
        path: '/projects/{username}/git/update-credentials',
        description: 'Update git credentials for a directory. Optional `path` defaults to `project` on '
            . 'DinD and the document root of the main domain on FPM/LiteSpeed. Sending `token` (including empty) updates stored '
            . 'credentials. Omitting `token` is a no-op that returns status.',
        summary: 'Update git credentials for a directory',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'token', type: 'string', nullable: true),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Credentials updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateCredentials(string $username, GitUpdateCredentialsRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(fn () => $git->updateCredentials($user, $request->validated()));
    }

    #[OA\Post(
        path: '/projects/{username}/git/pull',
        description: 'Pull from the git remote. Optional `path` defaults to `project` on DinD and '
            . 'the document root of the main domain on FPM/LiteSpeed. Body `strategy` is `ff` (default), `force` '
            . '(`reset --hard` origin/<branch> plus clean -fd), or `push_first`. `ff` fetches and fast-forwards, '
            . 'and git alone decides whether the checkout allows it: untracked files (uploads, caches) and edits to '
            . 'files the incoming commits leave alone do not block it. It is refused, naming the paths, when a local '
            . 'edit or untracked file sits where an incoming commit writes, or when history has diverged '
            . '(for example after a force-push); the checkout is left as it was. On a `site_git` checkout the pull '
            . 'runs in the request: 200 with the checkout, or 422 for those refusals. ' . self::QUEUED_ON_DEPLOY
            . ' (`details.action: git_pull`); a refused pull fails the task the same way. ' . self::BUSY
            . ' Confirm with the operator before `force`.',
        summary: 'Pull from the git remote',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'strategy', type: 'string', enum: ['ff', 'force', 'push_first']),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Pulled (a `site_git` checkout)', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 202, description: self::QUEUED_RESPONSE, content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 400, description: self::GIT_ERROR_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: self::BUSY_RESPONSE, content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'task_id', type: 'integer', nullable: true),
                ],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: self::LOCK_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
        x: ['mcp-description' => 'Pulls the connected branch. Optional `path` defaults to `project` on DinD and the document '
            . 'root of the main domain on FPM/LiteSpeed. `strategy`: `ff` (default) fast-forwards and is refused when history '
            . 'has diverged or a local edit or untracked file sits where an incoming commit writes; `force` is `reset --hard` '
            . 'to origin plus clean -fd; `push_first` pushes local commits first. Confirm with the operator before `force`. '
            . self::MCP_QUEUED],
    )]
    public function pull(string $username, GitPullRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);
        $params = $request->validated();

        return $this->queuedOrDone($git, $user, ProjectRebuild::GIT_PULL, $params, fn () => $git->pull($user, $params));
    }

    #[OA\Post(
        path: '/projects/{username}/git/push',
        description: 'Push local git changes. Optional `path` defaults to `project` on DinD and '
            . 'the document root of the main domain on FPM/LiteSpeed. If the working tree is dirty this tool will commit all '
            . 'changes itself, then push. Returns 422 `Pull first.` when behind the remote, or when '
            . 'managed_by is `deploy`.',
        summary: 'Push local git changes',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', type: 'string')],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Pushed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function push(string $username, GitPathRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);

        return $this->respond(fn () => $git->push($user, $request->validated()));
    }

    #[OA\Post(
        path: '/projects/{username}/git/revert',
        description: 'Revert local git changes. Optional `path` defaults to `project` on DinD and '
            . 'the document root of the main domain on FPM/LiteSpeed. Runs `reset --hard` and `clean -fd` to `ref` (default '
            . 'HEAD). Discards local changes. Confirm with the operator. 422 when `ref` names no commit in the checkout. '
            . 'On a `site_git` checkout it runs in the request and the answer is 200 with the checkout. '
            . self::QUEUED_ON_DEPLOY . ' (`details.action: revert`). ' . self::BUSY,
        summary: 'Revert local git changes',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'path', description: 'Optional. Defaults to `project` (DinD) or the document root of the main domain (FPM/LiteSpeed).', type: 'string'),
                new OA\Property(property: 'ref', type: 'string'),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Reverted (a `site_git` checkout)', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 202, description: self::QUEUED_RESPONSE, content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 400, description: self::GIT_ERROR_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: self::BUSY_RESPONSE, content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'task_id', type: 'integer', nullable: true),
                ],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: self::LOCK_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
        x: ['mcp-description' => 'Discards local changes: `reset --hard` and `clean -fd` to `ref` (default HEAD). Optional '
            . '`path` defaults to `project` on DinD and the document root of the main domain on FPM/LiteSpeed. Confirm with '
            . 'the operator. ' . self::MCP_QUEUED],
    )]
    public function revert(string $username, GitRevertRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);
        $params = $request->validated();

        return $this->queuedOrDone($git, $user, ProjectRebuild::REVERT, $params, fn () => $git->revert($user, $params));
    }

    /** The action's data, or the git layer's refusal with the status it carries. */
    #[OA\Post(
        path: '/projects/{username}/git/deploy-key',
        description: 'Create the project\'s SSH deploy key, or return the one it has: `public_key` is the line '
            . 'to add to the repository as a read-only deploy key, then connect an SSH remote with git_connect. '
            . 'The private key is stored encrypted and never returned. Host keys are pinned: github.com, '
            . 'gitlab.com and bitbucket.org from their published keys; for another host pass `host` '
            . '(`git.example.com` or `git.example.com:2222`) and its keys are read once and pinned, with their '
            . '`fingerprints` returned to check. 201 when the key was created, 200 when it existed; never '
            . 'rotates (delete and create again for a new key).',
        summary: 'Create a git deploy key',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'host', description: 'Optional. A git host other than github.com, gitlab.com or bitbucket.org to pin, with an optional :port.', type: 'string'),
            ],
        )),
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'created', type: 'boolean'),
                    new OA\Property(property: 'public_key', type: 'string'),
                    new OA\Property(property: 'fingerprint', type: 'string'),
                    new OA\Property(property: 'hosts', type: 'array', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'host', type: 'string'),
                        new OA\Property(property: 'pinned', type: 'string', enum: ['published', 'scanned']),
                        new OA\Property(property: 'fingerprints', type: 'array', items: new OA\Items(type: 'string')),
                    ])),
                ])],
            )),
            new OA\Response(response: 200, description: 'The key already existed'),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function deployKey(string $username, GitDeployKeyRequest $request, GitActions $git): JsonResponse
    {
        $user = $this->projectOr404($username);
        $data = $git->deployKey($user, $request->validated());

        return new JsonResponse(['data' => $data], $data['created'] ? 201 : 200);
    }

    #[OA\Delete(
        path: '/projects/{username}/git/deploy-key',
        description: 'Delete the project\'s SSH deploy key and the host keys pinned with it. SSH remotes stop '
            . 'authenticating; remove the key from the repository too. 404 when the project has no deploy key.',
        summary: 'Delete a git deploy key',
        security: [['bearerAuth' => []]],
        tags: ['Git'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Project or deploy key not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function deleteDeployKey(string $username): JsonResponse
    {
        $user = $this->projectOr404($username);
        if (!DeployKey::delete($user)) {
            return new JsonResponse(['message' => "Project '{$username}' has no deploy key."], 404);
        }

        return new JsonResponse(null, 204);
    }

    /**
     * 202 with the task when the change was queued with a rebuild; otherwise
     * the change, made here.
     *
     * @param array<string, mixed> $params
     */
    private function queuedOrDone(GitActions $git, User $user, string $action, array $params, callable $run): JsonResponse
    {
        try {
            $task = $git->queueRedeploy($user, $action, $params);
        } catch (GitException $e) {
            return self::refusal($e);
        }
        if ($task !== null) {
            return TaskResource::make($task)->response()->setStatusCode(202);
        }

        return $this->respond($run);
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return new JsonResponse(['data' => $action()]);
        } catch (GitException $e) {
            return self::refusal($e);
        }
    }

    /** A 409 names the deploy it waits for, as a busy rebuild's does: null when that is no task. */
    private static function refusal(GitException $e): JsonResponse
    {
        $body = ['message' => $e->getMessage()];
        if ($e->httpStatus === 409) {
            $body['task_id'] = $e->taskId;
        }

        return new JsonResponse($body, $e->httpStatus);
    }
}
